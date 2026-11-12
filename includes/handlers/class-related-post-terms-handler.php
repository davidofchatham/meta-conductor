<?php
/**
 * Related Post Terms (ACF Reference) handler.
 *
 * Copies taxonomy terms between a post and the posts it relates to via an ACF
 * relationship / post-object field, using a DECLARATIVE source-authoritative
 * model (no per-application tracking meta): a dependent's terms in a taxonomy
 * are recomputed from the sources one rule resolves for it.
 *
 * Direction is holder-relative (SPEC §V1): the ACF field pins the holder post
 * type; `holder_role` says which end is authoritative. source = push the
 * holder's terms out to related posts; target = pull related posts' terms onto
 * the holder.
 *
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

use BWS\MetaConductor\Relationships\AcfRelationship;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The last conversion (#63): pull applier + declared fan-out + capture layer.
 *
 * WHAT CHANGED AND WHY. This handler owned six hooks, ran every rule at once
 * from each of them, and wrote both ends of a relationship out of band. Three
 * consequences, all of them the defects Phase 4 exists to remove:
 *
 *   - It occupied ONE slot in the authored order however many rules it held,
 *     so two of its rows straddling another type's rule was inexpressible —
 *     the failure mode architecture.md invariant 17 rejects type-level ordering for.
 *   - Its writes landed on OTHER posts from inside the writing post's own
 *     execution, so the written post never got an ordered pass: one rule
 *     reached it, and the rest of the list then ran against it from whatever
 *     hooks happened to fire (#35's shape).
 *   - Its taxonomy-scoped `in_sync` cascade guard was a second re-entrancy
 *     mechanism competing with the pass lock (architecture.md invariant 17, rejected option).
 *
 * So it splits three ways, along the seams #62 already cut:
 *
 *   - `apply_to_post(P, rule)` recomputes P AND ONLY P under exactly ONE rule
 *     row, by READING the sources that row resolves for it. Pull, not push.
 *   - `fan_out(P, rule)` DECLARES the dependents P feeds under that row. The
 *     dispatcher marks them dirty; each gets its own full ordered pass.
 *   - The CAPTURE layer records what the write is about to destroy — a
 *     relationship that no longer exists cannot be read back — and applies
 *     nothing. `drain_captures()` hands the affected entities to the
 *     dispatcher at drain start.
 *
 * TWO BEHAVIOUR CHANGES ON A LIVE RULE TYPE, both consequences of one rule row
 * becoming the unit of execution. Recorded in the changelog as such.
 *
 *   1. TWO ROWS IN ONE TAXONOMY NO LONGER UNION; THEY COMPOSE BY ORDER. The old
 *      `recompute_dependent()` unioned every rule's sources for a taxonomy and
 *      wrote once. A per-rule applier cannot see its siblings, and must not:
 *      order is the composition mechanism (architecture.md invariants 17 and 19). So a
 *      keep-in-sync row is `owning` over the taxonomy — it replaces — and the
 *      LAST such row in the list wins on any dependent both rows manage. An
 *      author who wants the union puts the second row on add-only, or orders
 *      the owning row first.
 *   2. AN ORPHANED DEPENDENT IS EMPTIED BY THE OWNING ROW EVEN IF A SIBLING
 *      ADD-ONLY ROW STILL HAS SOURCES. The old force path guarded that case
 *      (`$force_sync && $source_count === 0`), because one write served every
 *      rule. Per-rule, the add-only row runs in its own turn and re-adds its
 *      contribution if it is ordered after; if it is ordered before, the owning
 *      row's replace is what the author asked for. Note the limit of this: a
 *      sever is recorded against the RULE whose link was cut, so a row that
 *      resolves no source and had none cut still declines to write at all. Two
 *      rows contending over a taxonomy is a claim question; whether a row
 *      manages the post at all is not, and stays gated.
 *
 * WHAT LIVE STATE STILL CANNOT ANSWER. "This post resolves no sources" means
 * two different things: never related (leave it alone — invariant #2, the
 * destructive-write gate), and JUST severed (withdraw the gone source's
 * terms). Nothing on either post records which, and the relationship that would
 * have said so is precisely what was destroyed. `related`'s #61 answer —
 * restate the condition in live state — is unavailable here for that reason, so
 * the sever is CAPTURED as it happens instead, by the three hooks on
 * `RelatedPostTermsRules::capture_hooks()`. Capture records
 * and applies nothing; the applier consumes the record during a pass.
 */
class RelatedPostTermsHandler extends UnifiedHandlerBase {

    /**
     * The ACF relationship graph and sever capture (FW-61 ticket 02). Everything
     * about WHO feeds whom, and what a write destroyed, lives there; this handler
     * keeps the hook registration, the claim, the zero-source gate and the term
     * write.
     */
    private AcfRelationship $relationship;

    public function __construct() {
        $this->relationship = new AcfRelationship();
        parent::__construct();
    }

    /**
     * Per-REQUEST memo of enabled rules, for the CAPTURE path only — the
     * applier is handed its rule by the pass and never reads the list.
     *
     * The `acf/update_value` capture fires once per relationship field saved
     * site-wide, and each call re-ran get_enabled_rules → storage normalize over
     * every rule. The rule set is immutable within a request that saves posts
     * (rules are only mutated by the admin REST save, a separate request), so a
     * request-lifetime memo is safe. (PR#24 round 8 #4)
     *
     * @var array<int,array>|null
     */
    private ?array $enabled_rules_memo = null;

    /** Memoized get_enabled_rules for the request. (round 8 #4) */
    private function enabled_rules(): array {
        if ($this->enabled_rules_memo === null) {
            $this->enabled_rules_memo = $this->get_enabled_rules();
        }
        return $this->enabled_rules_memo;
    }

    /**
     * CAPTURE hooks only — no apply hooks (#63).
     *
     * `acf/save_post` p30, `save_post` p25 and `set_object_terms` p15 lived here
     * until 0.8.0. `TermDispatcher` owns the trigger union now, so registering
     * any of them here would run every rule of this type twice, once out of
     * authored order.
     *
     * The three that stay all read state the write destroys, and all of them
     * only RECORD:
     *
     *   `acf/update_value/type=relationship` + `.../type=post_object` fire
     *   BEFORE ACF writes the new value, which is the last moment the OLD
     *   relationship is readable. A pass, running at drain, sees only the new
     *   graph — in which the severed link simply is not there, indistinguishable
     *   from one that never was.
     *
     *   `before_delete_post` is the same problem for a vanishing post: no
     *   `acf/update_value` fires on delete, and once the post is gone its
     *   relationship field cannot be read to find out whom it fed. The strip
     *   itself no longer needs a second `deleted_post` hook to sequence it —
     *   that pairing existed so the recompute ran AFTER the source stopped
     *   resolving, and the drain is already long after.
     *
     * The descriptor's `capture_hooks()` is the allow-list that permits these
     * three, and H13 fails on any registration here that is not on it, or on
     * any of these callbacks calling a write primitive.
     */
    protected function init_hooks() {
        add_filter('acf/update_value/type=relationship', [$this, 'capture_removed_dependents'], 5, 3);
        add_filter('acf/update_value/type=post_object', [$this, 'capture_removed_dependents'], 5, 3);
        add_action('before_delete_post', [$this, 'capture_deleted_post'], 10, 1);
    }

    // ---------------------------------------------------------------------
    // Applier (#63)
    // ---------------------------------------------------------------------

    /**
     * Apply ONE rule row to ONE post: recompute this post's terms in the rule's
     * taxonomy from the sources THAT ROW resolves for it. Writes this post and
     * nothing else.
     *
     * WHY THIS DOES NOT CALL `should_process_post()`. On every other rule type
     * `post_status` gates the post being written. Here it is the SOURCE-status
     * gate (§V5) — "only copy from published sources" — and applying it to the
     * dependent would silently stop a draft dependent being kept in sync by a
     * published source, which is neither what the field says nor what the rule
     * did before. The post-type gate is this type's own two-sided eligibility
     * test instead: `post_types` is not even a subfield of this rule type (#58),
     * because the ACF field pins the holder type and the field's target types
     * pin the other end.
     *
     * THE ZERO-SOURCE SKIP IS THE DESTRUCTIVE-WRITE GATE (invariant #2, V13). A
     * push rule's dependent type is `''` = any, so type-match alone would make
     * every post in the field's target types a managed dependent and empty it.
     * Positive evidence that the row manages this post is a resolved source —
     * or a captured sever, which is the same evidence one moment later.
     *
     * @param int   $post_id Post to recompute.
     * @param array $rule    One enabled rule row (canonical shape).
     * @return bool Whether this post's terms actually changed.
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        $taxonomy = (string) ($rule['taxonomy'] ?? '');
        if ($post_id <= 0 || $taxonomy === '') {
            return false;
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return false;
        }

        // Both halves of the dependent-side gate the pre-#63 pipeline applied:
        // the eligibility pre-filter (sync_for_post) and the exact type match
        // (recompute_dependent). For a pull rule they agree; for a push rule the
        // second is vacuous and the first narrows to the ACF field's targets.
        if (!$this->relationship->is_eligible_dependent($post, $rule)) {
            return false;
        }

        $keep_in_sync = !empty($rule['keep_in_sync']);
        $sources      = $this->relationship->sources_of($post_id, $rule);

        // An add-only row never removes, so a sever cannot make it act; only a
        // keep-in-sync row withdraws a gone source's terms (PR#24 Bug 3). And
        // only THIS row's own sever counts (`AcfRelationship::is_severed()`).
        $orphaned = $keep_in_sync && $this->relationship->is_severed($post_id, $rule);

        if (empty($sources) && !$orphaned) {
            return false;
        }

        // Source-scoped status gate (§V5). A gated-out source still COUNTS as a
        // resolved source above — its terms just don't contribute — so a
        // published dependent of a draft source is sync-emptied, not
        // skipped-as-unmanaged.
        $passing = [];
        foreach ($sources as $source_id) {
            if ($this->source_status_passes((int) $source_id, $rule)) {
                $passing[] = (int) $source_id;
            }
        }

        return $this->write_terms($post_id, $taxonomy, $this->terms_of($passing, $taxonomy), $keep_in_sync);
    }

    /**
     * The dependents THIS post feeds under this rule row — its declared reach
     * (#62's seam, this type's second user).
     *
     * push (holder = source): the related posts listed in the holder's field.
     * pull (holder = target): the holders that reference this related post.
     *
     * Neighbours, not closures: each dependent gets its own full ordered pass,
     * and a dependent is never itself a source under the same row, so there is
     * nothing further to walk.
     *
     * A SEVERED dependent is deliberately NOT here. It is unreachable by
     * construction — the relationship that would name it is the thing that was
     * just destroyed — which is why the capture layer exists and why
     * `drain_captures()` is a separate channel into the queue.
     *
     * @param int   $post_id Post just passed over.
     * @param array $rule    The rule row.
     * @return int[] Dependent post IDs.
     */
    public function fan_out(int $post_id, array $rule): array {
        if ((string) ($rule['taxonomy'] ?? '') === '') {
            return [];
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return [];
        }

        // Gate on source ELIGIBILITY, not just the ''-is-any wildcard: a pull
        // rule's source type is '' , which would otherwise run resolve_reverse —
        // including the tier-3 meta_query scan — for every entity passed over
        // site-wide. (§V17/B7)
        if (!$this->relationship->is_eligible_source($post, $rule)) {
            return [];
        }

        return $this->relationship->dependents_of($post_id, $rule);
    }

    /**
     * Entities whose CAPTURED state needs a pass. Consumed — see
     * `AcfRelationship::drain_marks()`.
     *
     * The dispatcher calls this at drain start, so a severed dependent reaches
     * a full ordered pass by the same route a saved post does. It has to be a
     * separate channel from `fan_out()` because the fan-out is asked while
     * passing over a post, and the entity a sever affects may be reachable from
     * no post that gets one: the holder was deleted, or the link that named it
     * is gone.
     *
     * @return int[] Entity IDs to mark dirty.
     */
    public function drain_captures(): array {
        return $this->relationship->drain_marks();
    }

    // ---------------------------------------------------------------------
    // Capture layer (#63) — hook callbacks only; the logic is the module's
    // ---------------------------------------------------------------------

    /**
     * CAPTURE hook (not an apply): `acf/update_value` at priority 5, before the
     * value is written. Records which dependents the removal orphans; the diff
     * and the direction logic are `AcfRelationship::capture_removed_dependents()`.
     *
     * @param mixed $value    New field value (returned unchanged).
     * @param int   $post_id  Post being saved (a source or a holder).
     * @param array $field    ACF field array.
     * @return mixed
     */
    public function capture_removed_dependents($value, $post_id, $field) {
        return $this->relationship->capture_removed_dependents($this->enabled_rules(), $value, $post_id, $field);
    }

    /**
     * CAPTURE hook (not an apply): a post is about to be permanently deleted;
     * record the dependents it feeds while its relationships are still readable.
     *
     * @param int $post_id Post being deleted.
     */
    public function capture_deleted_post($post_id): void {
        $this->relationship->capture_deleted_post($this->enabled_rules(), $post_id);
    }


    // ---------------------------------------------------------------------
    // Write
    // ---------------------------------------------------------------------

    /**
     * The union of the given posts' terms in one taxonomy.
     *
     * Batched: `wp_get_object_terms` accepts an int[] of objects, so all passing
     * sources for a rule resolve in ONE query rather than N (PR#24 round 5 #3).
     *
     * @param int[]  $post_ids
     * @param string $taxonomy
     * @return int[]
     */
    private function terms_of(array $post_ids, string $taxonomy): array {
        if (empty($post_ids)) {
            return [];
        }
        $terms = \wp_get_object_terms($post_ids, $taxonomy, ['fields' => 'ids']);

        return \is_wp_error($terms) ? [] : $this->normalize_term_ids((array) $terms);
    }

    /**
     * Write one rule's computed set, through the shared claim semantics.
     *
     * `keep_in_sync` IS a claim in the CONTEXT.md → Claim sense — owning over the whole
     * taxonomy when on, contributing when off — so it maps onto
     * `compute_end_state()` rather than re-deriving the merge/replace switch
     * here. That mapping is the reason an empty authoritative set can still
     * empty the taxonomy under keep-in-sync (`replace` of nothing) while leaving
     * it untouched under add-only (`merge` of nothing), which is exactly the
     * pair of behaviours the old four-branch write decision spelled out.
     *
     * `apply_terms_to_post()` is still not the write path: it early-returns on
     * an empty term list, so it cannot express the keep-in-sync empty-replace.
     * The claim encoding is shared; the guard is not. (#38 cluster 2, don't 6d.)
     *
     * @param int    $post_id      Dependent post.
     * @param string $taxonomy     Taxonomy.
     * @param int[]  $terms        Authoritative term IDs for this rule.
     * @param bool   $keep_in_sync true ⇒ owning (replace); false ⇒ contributing.
     * @return bool Whether the post's terms actually changed.
     */
    private function write_terms(int $post_id, string $taxonomy, array $terms, bool $keep_in_sync): bool {
        $current = \wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (\is_wp_error($current)) {
            $current = [];
        }
        $current = $this->normalize_term_ids((array) $current);
        $final   = $this->compute_end_state($current, $terms, $keep_in_sync ? 'replace' : 'merge');

        // No-change short-circuit. A pass runs every rule whether or not this
        // one's trigger fired, so without this the same recompute re-writes and
        // re-logs on every provocation. Both sides come out of
        // normalize_term_ids/compute_end_state sorted, so === is a set test.
        if ($final === $current) {
            return false;
        }

        $result = \wp_set_object_terms($post_id, $final, $taxonomy);

        // wp_set_object_terms returns WP_Error when the taxonomy doesn't exist
        // (e.g. deleted after the rule was saved). Don't log a success message
        // for a write that silently no-op'd. (PR#24 round 7 #2)
        if (\is_wp_error($result)) {
            $this->debug_log(
                sprintf('ACF-ref sync FAILED post %d tax %s: %s', $post_id, $taxonomy, $result->get_error_message()),
                ['terms' => $final]
            );
            return false;
        }

        $this->debug_log(
            sprintf('ACF-ref sync (%s) post %d tax %s', $keep_in_sync ? 'replace' : 'add', $post_id, $taxonomy),
            ['before' => $current, 'after' => $final]
        );

        return true;
    }


    /** Source-status gate (SPEC §V5). Empty gate ⇒ any status passes. */
    private function source_status_passes(int $source_id, array $rule): bool {
        $statuses = $this->status_gate($rule);
        if (empty($statuses)) {
            return true;
        }
        return in_array(\get_post_status($source_id), $statuses, true);
    }

    /**
     * The post_status gate as a slug list. Empty ⇒ no filter.
     *
     * @return string[]
     */
    private function status_gate(array $rule): array {
        return $rule['post_status'] ?? [];
    }
}
