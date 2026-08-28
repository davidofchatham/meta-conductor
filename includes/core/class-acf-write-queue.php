<?php
/**
 * ACF write queue — AC-agnostic reapply trigger.
 *
 * @since 0.7.0
 */

namespace BWS\MetaConductor\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Learns about EVERY ACF field write and reapplies the handlers afterwards.
 *
 * PROBLEM (#42, generalising #37/B3). Every handler gates its apply on the
 * save_post family. A large class of ACF writes never fires those hooks at all
 * — they fire `acf/update_value` and nothing else:
 *
 *   - Admin Columns Pro v7 inline/bulk edits of an ACF column
 *   - any bare programmatic `update_field()` (custom code, WP-CLI, cron)
 *   - REST writes to an ACF field
 *
 * In each case the field changes, the rule never runs, and the post keeps stale
 * terms with no warning. #37 patched exactly one of those doors by hooking AC's
 * own save signal; this class watches ACF's write path instead, so every door —
 * including ones future ACF-based tooling opens — is covered by one mechanism.
 *
 * MECHANISM. `acf/update_value` fires BEFORE the value is written, so this
 * class never reads the post there — it only RECORDS the ID. Every apply
 * happens later, once the write has landed. That separation is what keeps the
 * mechanism clear of the pre-write hazard that RelatedPostTermsHandler's own
 * sever capture has to reason about (arch.md handler-invariant #12).
 *
 * WHY EDITOR SAVES DON'T DOUBLE-APPLY. A normal editor/REST post save fires
 * save_post + acf/save_post, and the handlers already run on those. So this
 * class CLAIMS the post on both hooks at a priority above every handler
 * (CLAIM_PRIORITY), removing it from the pending set — the post is fully
 * handled by the ordinary path and never reaches a flush. AC edits and bare
 * `update_field()` fire neither hook, so they survive to be flushed.
 *
 * FLUSH TRIGGERS. `shutdown` is the primary one: it is the only hook guaranteed
 * to fire for a field write involving no post save at all. A bounded mid-request
 * flush runs when the pending set exceeds FLUSH_CAP, so a long single-process
 * run writes progressively instead of deferring everything to the very end. That
 * bounded flush MUST skip the post currently being recorded — that post is
 * mid-write — while every other pending post in a sequential loop is already
 * fully persisted and safe to read. FLUSH_CAP is a plain constant: no site has
 * yet needed to tune it, and a filter is trivial to add (and non-breaking) the
 * first time one does, whereas removing a published one is not.
 *
 * IMPORTS ARE SUPPRESSED. A bulk import of thousands of posts must not silently
 * trigger thousands of recomputes, so the listener stands down entirely while
 * WP_IMPORTING is set (WP core importers and WP All Import both set it).
 * Reconcile afterwards with the "Apply to Existing Posts" bulk action, which is
 * the tool built for that job. The `meta_conductor_acf_reapply_enabled` filter
 * overrides this either way.
 *
 * APPLY CONTRACT. Unchanged from #37: hand the post ID to EVERY handler's
 * reapply_for_post and let each self-gate on post type, rule match and
 * reentrancy. Field type is deliberately NOT filtered in the listener — the
 * handlers key off relationship, post-object, taxonomy and plain-text fields
 * respectively, so any narrowing would reintroduce a smaller version of the bug
 * this fixes. (arch.md handler-invariant #14)
 *
 * SECOND APPLY CONTRACT, FOR CONVERTED TYPES (#60). A handler the dispatcher
 * owns registers no hooks and overrides no reapply seam, so the loop above
 * reaches nothing for it. Those types are covered by marking the post dirty on
 * the dispatcher instead: the shutdown drain (priority 20, after this flush's
 * 10) runs one full ordered pass per post. Both contracts are live while the
 * conversion is partial; the first one goes away with the last unconverted
 * handler (#66).
 *
 * SIDE EFFECT WORTH KNOWING. The bare-`update_field()` sever gap (PR#24 round 8
 * #2) is closed through the SECOND contract now, not the first:
 * RelatedPostTermsHandler was converted in #63 and has no `reapply_for_post`
 * left, so this flush reaches it only by marking the post dirty. That is
 * enough — the drain asks every converted handler for its captured entities
 * before it starts (TermDispatcher::enqueue_captures), so the severed
 * dependent gets a full ordered pass whether or not the flush named it.
 */
class AcfWriteQueue {

    /**
     * Claim priority on save_post / acf/save_post. Must sit ABOVE every
     * handler's own registration or the claim would remove the post before the
     * handlers ran — the latest is TitleSlugHandler at acf/save_post 99.
     * Guarded by tests/verify-acf-write-queue.php.
     */
    private const CLAIM_PRIORITY = 9999;

    /** Default pending-set size that triggers the bounded mid-request flush. */
    private const FLUSH_CAP = 100;

    /**
     * Handlers to reapply, as built by TaxonomyManager.
     *
     * @var array<string,\BWS\MetaConductor\Handlers\UnifiedHandlerBase>
     */
    private array $handlers;

    /**
     * Post IDs touched by an ACF write and not yet applied, as a hash SET
     * (`[post_id => true]`) so a post touched by several fields in one request
     * is applied once.
     *
     * @var array<int,true>
     */
    private array $pending = [];

    /** Reentrancy guard: a handler's own writes must not re-enter a flush. */
    private bool $flushing = false;

    /**
     * Term dispatcher, if one is running (#60).
     *
     * A CONVERTED handler has no `reapply_for_post` to call — it has no hooks
     * at all — so the loop below cannot reach it. This is how the ACF-only
     * write path still provokes a pass for those rule types: the flush marks
     * the post dirty and the dispatcher's own shutdown drain (priority 20, this
     * flush is 10) runs the pass.
     *
     * Nullable so the queue can still be constructed standalone.
     *
     * @var TermDispatcher|null
     */
    private ?TermDispatcher $dispatcher;

    /**
     * @param array<string,\BWS\MetaConductor\Handlers\UnifiedHandlerBase> $handlers
     * @param TermDispatcher|null                                          $dispatcher
     */
    public function __construct(array $handlers, ?TermDispatcher $dispatcher = null) {
        $this->handlers   = $handlers;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Register the listener, the claim and the shutdown flush.
     *
     * The listener is on the UNTYPED `acf/update_value` at max priority so it
     * sees every field write regardless of type or of what other filters do.
     */
    public function register(): void {
        add_filter('acf/update_value', [$this, 'record'], PHP_INT_MAX, 3);

        // Claim above every handler: an ordinary save is already fully handled.
        add_action('save_post', [$this, 'claim'], self::CLAIM_PRIORITY, 1);
        add_action('acf/save_post', [$this, 'claim'], self::CLAIM_PRIORITY, 1);

        add_action('shutdown', [$this, 'flush'], 10, 0);
    }

    /**
     * Record the post behind an ACF write. Pass-through filter — the value is
     * returned untouched and is never inspected.
     *
     * @param mixed $value   Value being written (returned unchanged).
     * @param mixed $post_id ACF target: a post ID, or a pseudo-target such as
     *                       'options' / 'user_5' / 'term_3'.
     * @param array $field   ACF field array (unused — type is not filtered).
     * @return mixed
     */
    public function record($value, $post_id = 0, $field = []) {
        // Gate on the TARGET, not the field. A positive integer post ID
        // naturally excludes ACF's 'options' / 'user_N' / 'term_N' targets.
        // This runs BEFORE reapply_enabled so the filter is only ever handed a
        // real post ID, as its contract promises — never a pseudo-target.
        if (!is_numeric($post_id)) {
            return $value;
        }
        $id = (int) $post_id;
        if ($id <= 0) {
            return $value;
        }
        if (\wp_is_post_autosave($id) || \wp_is_post_revision($id)) {
            return $value;
        }

        // Early-out so a disabled site doesn't accumulate a pending set it will
        // never apply. apply() re-checks — that is the authoritative gate.
        if (!$this->reapply_enabled($id)) {
            return $value;
        }

        $this->pending[$id] = true;

        // Bounded mid-request flush so a long single-process run writes
        // progressively. Skips $id — that post is mid-write. Reentrancy is
        // handled by guarded(), so no flushing check is needed here.
        if (count($this->pending) > self::FLUSH_CAP) {
            $this->flush_pending_except($id);
        }

        return $value;
    }

    /**
     * An ordinary post save already ran every handler — drop the post from the
     * pending set so the deferred flush doesn't repeat the work.
     *
     * @param mixed $post_id Post ID (acf/save_post may pass a pseudo-target).
     */
    public function claim($post_id): void {
        if (!is_numeric($post_id)) {
            return;
        }
        unset($this->pending[(int) $post_id]);
    }

    /**
     * Run a flush body under the reentrancy guard. Sole owner of the guard:
     * a handler's own ACF writes re-enter record(), which can call back here,
     * and a nested flush would read half-written state and double-apply.
     *
     * Every mutation a flush performs must happen INSIDE $fn. In particular
     * flush_pending_except's reassignment of $this->pending belongs in the
     * closure — clearing the pending set outside it would drop posts on a
     * re-entrant call that then returns without applying them.
     */
    private function guarded(callable $fn): void {
        if ($this->flushing) {
            return;
        }
        $this->flushing = true;
        try {
            $fn();
        } finally {
            $this->flushing = false;
        }
    }

    /** Apply every pending post. Primary trigger: shutdown. */
    public function flush(): void {
        $this->guarded(function () {
            // A handler's own writes can enqueue more posts; drain until empty.
            // $seen bounds the loop — a post is applied at most once per flush.
            $seen = [];
            while (!empty($this->pending)) {
                $ids           = array_keys($this->pending);
                $this->pending = [];
                foreach ($ids as $id) {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $this->apply($id);
                }
            }
        });
    }

    /**
     * Apply ONE post immediately. Used by the Admin Columns v7 hook so an
     * inline-edit response — built before shutdown — shows the corrected terms
     * in the same interaction. Drops the post from the pending set so a later
     * flush can't apply it twice.
     *
     * The unset lives INSIDE the guarded closure (arch.md #14, last
     * bullet). Outside it, a call
     * arriving while a flush is already running would clear the post and then
     * return without applying it — neither applied nor pending, so the post
     * silently keeps stale terms. Inside, a re-entrant call is a no-op and the
     * post stays queued for the flush already in progress.
     */
    public function flush_post(int $post_id): void {
        if ($post_id <= 0) {
            return;
        }
        $this->guarded(function () use ($post_id) {
            unset($this->pending[$post_id]);
            $this->apply($post_id);
        });

        // apply() only MARKED the post for the dispatcher; the whole point of
        // this entry point is that AC builds its inline-edit response before
        // shutdown, so the pass has to happen now too or the column renders
        // pre-pass terms. Outside guarded() deliberately — this is a different
        // mechanism's queue with its own re-entrancy guard, and nesting it
        // inside this one would make a mid-flush call a silent no-op. (#60)
        $this->dispatcher?->drain_post($post_id);
    }

    /**
     * Bounded flush: apply every pending post EXCEPT the one currently being
     * recorded, which is mid-write and must not be read yet. It stays pending
     * for the shutdown flush (or for its own claim, if a save follows).
     */
    private function flush_pending_except(int $in_flight_post_id): void {
        $this->guarded(function () use ($in_flight_post_id) {
            $ids = array_keys($this->pending);
            // Keep only the in-flight post pending.
            $this->pending = isset($this->pending[$in_flight_post_id])
                ? [$in_flight_post_id => true]
                : [];

            foreach ($ids as $id) {
                if ($id === $in_flight_post_id) {
                    continue;
                }
                $this->apply($id);
            }
        });
    }

    /**
     * Hand one post to every handler; each self-gates. (arch.md #14)
     *
     * AUTHORITATIVE gate. Every flush path — shutdown, bounded, and the Admin
     * Columns one-post flush — funnels through here, so this is the single
     * place that can honour "turn the whole behaviour off". Gating only the
     * listener would leave flush_post() applying regardless, which is exactly
     * what an admin reaches for while diagnosing an unrelated problem.
     */
    private function apply(int $post_id): void {
        if (!$this->reapply_enabled($post_id)) {
            return;
        }
        foreach ($this->handlers as $handler) {
            $handler->reapply_for_post($post_id);
        }

        // Converted rule types have no reapply seam to call — the dispatcher
        // runs them. Marking rather than passing here keeps the coalescing
        // property: a flush of N posts leaves N dirty entities and the drain
        // runs one pass each, instead of a pass firing inside this loop and
        // re-entering it through its own writes. (#60)
        $this->dispatcher?->mark_dirty($post_id);
    }

    /**
     * Whether rule reapply should run for this post.
     *
     * Imports stand down by default — a bulk import of thousands of posts must
     * not silently trigger thousands of recomputes; reconcile afterwards with
     * "Apply to Existing Posts". The `meta_conductor_acf_reapply_enabled`
     * filter overrides that either way.
     *
     * The `int` parameter type IS the filter's contract: callers guarantee a
     * real post ID before this runs, so a filter never has to defend against
     * ACF's 'options' / 'user_N' / 'term_N' pseudo-targets.
     */
    private function reapply_enabled(int $post_id): bool {
        $default = !(defined('WP_IMPORTING') && WP_IMPORTING);

        return (bool) apply_filters('meta_conductor_acf_reapply_enabled', $default, $post_id);
    }
}
