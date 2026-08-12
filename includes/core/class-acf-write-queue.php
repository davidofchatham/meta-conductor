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
 * sever capture has to reason about (§V14).
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
 * this fixes. (SPEC §V3/§V6/§V7)
 *
 * SIDE EFFECT WORTH KNOWING. RelatedPostTermsHandler::reapply_for_post routes
 * through its normal acf/save_post entry point, which also drains its pending
 * sever bookkeeping. The flush therefore closes that handler's separately
 * documented gap where a bare `update_field()` captured a sever that nothing
 * ever processed (PR#24 round 8 #2).
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
     * @param array<string,\BWS\MetaConductor\Handlers\UnifiedHandlerBase> $handlers
     */
    public function __construct(array $handlers) {
        $this->handlers = $handlers;
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
        // Imports stand down by default; filter can force either way.
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            if (!apply_filters('meta_conductor_acf_reapply_enabled', false, $post_id)) {
                return $value;
            }
        } elseif (!apply_filters('meta_conductor_acf_reapply_enabled', true, $post_id)) {
            return $value;
        }

        // Gate on the TARGET, not the field. A positive integer post ID
        // naturally excludes ACF's 'options' / 'user_N' / 'term_N' targets.
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
     * in the same interaction. Drops the post from the pending set first, so a
     * later flush can't apply it twice.
     */
    public function flush_post(int $post_id): void {
        if ($post_id <= 0) {
            return;
        }
        unset($this->pending[$post_id]);
        $this->guarded(function () use ($post_id) {
            $this->apply($post_id);
        });
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

    /** Hand one post to every handler; each self-gates. (§V3/§V6/§V7) */
    private function apply(int $post_id): void {
        foreach ($this->handlers as $handler) {
            $handler->reapply_for_post($post_id);
        }
    }
}
