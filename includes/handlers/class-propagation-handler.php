<?php
/**
 * BWS Taxonomy Manager Propagation Handler
 * Reconciles a post's terms against its PARENT's, and declares the descendants
 * a change reaches.
 *
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Propagation, inverted to PULL (#62).
 *
 * WHAT CHANGED AND WHY. Propagation is the first rule type whose effect is
 * genuinely about a second entity, and until 0.8.0 it acted on that directly:
 * a parent's save walked every descendant and wrote each one. The written child
 * therefore never got an ordered pass — one rule reached it, out of band, and
 * the rest of the list then ran against it from whatever hooks happened to
 * fire. That is #35: the propagated terms landed on the child, the child's
 * `set_object_terms` woke the hierarchical handler on its own hook, and the
 * child ended up with a level of expansion nobody had asked any rule for. It is
 * also where the propagation-before-hierarchical instantiation-order dependency
 * lived — the two handlers ran in whatever sequence `TaxonomyManager` happened
 * to construct them in, which is not an order an author can express.
 *
 * So the rule splits in two, along the seam ADR 0003 draws:
 *
 *   - `apply_to_post(P, rule)` reconciles P AND ONLY P, by READING P's parent.
 *     Pull, not push. Every post that needs reconciling gets there as the
 *     subject of its own full ordered pass, so propagation and hierarchical
 *     compose in the author's order on the child exactly as they do on the
 *     parent.
 *   - `fan_out(P, rule)` DECLARES the entities P's own change reaches — its
 *     immediate children. The dispatcher marks them dirty; each gets its own
 *     pass, whose fan-out carries the effect the next step down. The recursion
 *     lives in the queue, where `TermDispatcher::drain()`'s one-pass-per-entity
 *     bound terminates it, rather than in a recursive descendant walk here.
 *
 * THE ONE THING PULL CANNOT SEE, AND THE CAPTURE HOOK THAT FIXES IT. Live state
 * answers "what should this child hold, given its parent" for every claim
 * except on removal. A term the parent HELD and then lost is, on the child,
 * indistinguishable from a term the child holds independently: no provenance is
 * recorded anywhere (ADR 0002 rejected per-rule provenance), and under `merge`
 * the two must be treated differently — the first is removed from the child,
 * the second is not. The old delta model got this from `set_object_terms`'s
 * `$old_tt_ids`. A pass hands over rules, not deltas, and deliberately so.
 *
 * So the removal is CAPTURED as it happens. `deleted_term_relationships` is the
 * only hook that sees it — `wp_remove_object_terms()` fires nothing else, and
 * on the plain `wp_set_object_terms()` path WP drops terms through an internal
 * `wp_remove_object_terms()` too — and it writes to a request-scoped map,
 * applying nothing. Each child's applier subtracts what ITS parent lost. The
 * child's own resulting write fires the same hook for the child, so the
 * subtraction reaches the next level down through the same mechanism as
 * everything else. This is the first entry on `TermDispatcher::CAPTURE_HOOKS`;
 * capture is not execution, so the dispatcher stays the sole caller of
 * `apply_to_post`.
 */
class PropagationHandler extends UnifiedHandlerBase {

    /**
     * Post statuses a fan-out considers a child at all.
     *
     * The reach the recursive descendant walk had before #62, kept verbatim so
     * the conversion does not quietly widen or narrow which posts propagation
     * touches. It is NOT the rule's own `post_status` gate: that is applied by
     * `should_process_post()` inside the child's own apply, where a status the
     * rule excludes stops the write rather than the enqueue. Enqueuing one post
     * too many costs a no-op pass; missing one loses the propagation.
     *
     * @var string[]
     */
    private const CHILD_STATUSES = ['publish', 'draft', 'private'];

    /**
     * Terms removed from an object THIS REQUEST, keyed `"object_id:taxonomy"`.
     *
     * Written by `capture_removed_terms()` (the capture hook), read by the
     * appliers of that object's CHILDREN. Accumulates rather than being
     * consumed: several children read the same parent's removal, so consuming
     * on read would deliver it to whichever child happened to pass first.
     *
     * Request-scoped by construction — it is instance state on a handler built
     * per request, and a removal is only ever interesting to the pass that
     * follows it.
     *
     * @var array<string,int[]>
     */
    private array $captured_removals = array();

    /**
     * Immediate children by `"post_id|post_types"`, for the request.
     *
     * The fan-out is asked once per RULE per pass, so two propagation rules
     * over the same post types would otherwise run the same `post_parent` query
     * twice per entity, on every entity in a chain.
     *
     * @var array<string,int[]>
     */
    private array $child_cache = array();

    public function get_handler_type(): string {
        return 'propagation';
    }

    protected function get_rule_type(): string {
        return 'propagation_rules';
    }

    /**
     * Capture only — no apply hooks (#62).
     *
     * `save_post` p15, `set_object_terms` p10 and `acf/save_post` p25 lived
     * here until 0.8.0. `TermDispatcher` owns the trigger union now, including
     * `deleted_term_relationships`, so registering any of them here would run
     * propagation twice — once out of authored order, once in it.
     *
     * `deleted_term_relationships` stays, and ONLY because what it reads stops
     * existing after the write: it records the removal and applies nothing.
     * `TermDispatcher::CAPTURE_HOOKS` is the allow-list that says so, and H13
     * fails on any registration here that is not on it.
     */
    protected function init_hooks() {
        add_action('deleted_term_relationships', array($this, 'capture_removed_terms'), 10, 3);
    }

    // Intentional no-op (not a forgotten implementation). apply_to_post() is
    // the applier; the base process_post routes through RuleEngine, which
    // propagation does not use.
    public function process_post($post_id, $post, $update) {}

    /**
     * Apply ONE propagation rule to ONE post: reconcile this post against its
     * parent's terms. Writes this post and nothing else.
     *
     * Reads the parent through `get_post_terms()` (native ∪ ACF), subtracts
     * what the parent LOST this request, computes the end state for the rule's
     * claim through the shared `compute_end_state()`, and writes only when that
     * differs from what the post already holds.
     *
     * THE REMOVAL SUBTRACTION IS CLAIM-INDEPENDENT, and that is not an
     * oversight: the pre-#62 `propagate_term_removals_to_children()` ignored
     * `conflict_handling` too, so a `skip` rule removed from its children even
     * though it would never have added to them. Preserved deliberately — the
     * conversion is not the place to change what a claim means (that is #54).
     *
     * @param int   $post_id Post to reconcile.
     * @param array $rule    One enabled propagation rule (canonical shape).
     * @return bool Whether this post's terms actually changed.
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        if (!$this->should_process_post($post_id, $rule)) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post || (int) $post->post_parent <= 0) {
            return false;
        }
        $parent_id = (int) $post->post_parent;

        // Gate on the PARENT as well as the child. In stock WP a post_parent is
        // always the same post type as the child (the editor's parent dropdown
        // is type-scoped), so the two gates agree and this is
        // redundant-but-harmless. It only diverges under a cross-type
        // post_parent (programmatic / non-stock) — if that ever becomes real,
        // decide whether propagation scopes the source (parent), the target
        // (child), or both. (0.6.0 review, carried through #62.)
        if (!$this->should_process_post($parent_id, $rule)) {
            return false;
        }

        $taxonomy = (string) ($rule['taxonomy'] ?? '');
        if ($taxonomy === '') {
            return false;
        }
        $conflict_handling = $rule['conflict_handling'] ?? 'merge';

        $removed = $this->parent_removals($parent_id, $taxonomy);
        $source  = array_diff($this->get_post_terms($parent_id, $taxonomy), $removed);

        $before = $this->terms_fingerprint($post_id, $taxonomy);

        // ACF taxonomy mirror fields are a SEPARATE store from native terms and
        // can drift out of sync independently (edited out-of-band, a
        // save_terms-off field, a prior partial write). update_acf_fields_for_post
        // self-guards (writes only when the ACF value actually differs), so run
        // it unconditionally — the native short-circuit below must NOT gate it,
        // or a native-correct/ACF-stale post never re-syncs.
        $this->update_acf_fields_for_post($post_id, $taxonomy, $source, $conflict_handling, $removed);

        $current = $this->current_term_ids($post_id, $taxonomy);
        $target  = $this->target_term_ids($current, $source, $conflict_handling, $removed);

        // No-change short-circuit. A pass runs every rule whether or not this
        // one's trigger fired, and WP fires save_post more than once per editor
        // save, so without this the same reconcile re-writes native terms and
        // re-logs on every provocation.
        //
        // It still has to answer the CHANGED question honestly. The ACF write
        // above lands before $current is read, and with the field's Load/Save
        // Terms on it syncs the native store — so "the native write is
        // unnecessary" and "this apply changed nothing" are different
        // statements, and returning false for the first would under-report a
        // real change to run_pass(), drain_post() and the bulk tool's count.
        // $current is already the sorted id list terms_fingerprint() builds.
        if ($target === $current) {
            return implode(',', $current) !== $before;
        }

        wp_set_object_terms($post_id, $target, $taxonomy);

        $this->debug_log(
            sprintf('Post %d reconciled against parent %d', $post_id, $parent_id),
            array(
                'taxonomy' => $taxonomy,
                'parent_terms' => array_values($source),
                'removed_from_parent' => array_values($removed),
                'before' => $current,
                'after' => $target,
            )
        );

        return $this->terms_fingerprint($post_id, $taxonomy) !== $before;
    }

    /**
     * The entities this post's change reaches: its IMMEDIATE children (#62).
     *
     * Not the whole subtree. Each child's own pass fans out to ITS children, so
     * a chain of any depth is walked one level per pass, by the queue, with the
     * drain's one-pass-per-entity bound as the termination argument. Returning
     * the full descendant set would work too and would be strictly more
     * expensive — the same posts, enqueued repeatedly by every ancestor.
     *
     * Gated on the rule applying to THIS post, because that is what makes it a
     * source: a rule that would not read this post's terms has no effect here
     * to carry downward. The child's own eligibility is decided in its own
     * apply.
     *
     * @param int   $post_id Post just passed over.
     * @param array $rule    The propagation rule.
     * @return int[] Immediate child post IDs.
     */
    public function fan_out(int $post_id, array $rule): array {
        if ((string) ($rule['taxonomy'] ?? '') === '') {
            return array();
        }
        if (!$this->should_process_post($post_id, $rule)) {
            return array();
        }

        $post_types = $this->resolve_child_post_types($rule);
        if (empty($post_types)) {
            return array();
        }

        // Memoized per (post, post-type set) for the request: a pass asks once
        // per RULE, and two propagation rules scoped to the same post types
        // declare the same children. The children of one post do not change
        // within a request — nothing here writes `post_parent`.
        $key = $post_id . '|' . implode(',', $post_types);
        if (isset($this->child_cache[$key])) {
            return $this->child_cache[$key];
        }

        $children = get_posts(array(
            'post_type'      => $post_types,
            'post_parent'    => $post_id,
            'post_status'    => self::CHILD_STATUSES,
            'numberposts'    => -1,
            'fields'         => 'ids',
            'suppress_filters' => false,
        ));

        return $this->child_cache[$key] = array_map('intval', (array) $children);
    }

    /**
     * CAPTURE hook (not an apply): record which terms just left an object.
     *
     * Fires for `wp_remove_object_terms()` — which does NOT fire
     * `set_object_terms` — and, on the plain `wp_set_object_terms()` path, for
     * the terms WP drops through its own internal `wp_remove_object_terms()`
     * (taxonomy.php:2924). Either way this is the last moment the information
     * exists: afterwards the child's applier can see only that the parent does
     * not have the term, which is also true of a term the parent never had.
     *
     * Records nothing when no enabled rule works in this taxonomy, so a site
     * without propagation rules pays no tt_id lookup on every term removal.
     *
     * @param int    $object_id Object the terms were removed from.
     * @param array  $tt_ids    Removed term_taxonomy IDs.
     * @param string $taxonomy  Taxonomy slug.
     */
    public function capture_removed_terms($object_id, $tt_ids, $taxonomy): void {
        if (empty($tt_ids) || !is_numeric($object_id)) {
            return;
        }

        $taxonomy = (string) $taxonomy;
        if (!$this->taxonomy_in_use($taxonomy)) {
            return;
        }

        $term_ids = $this->convert_tt_ids_to_term_ids((array) $tt_ids, $taxonomy);
        if (empty($term_ids)) {
            return;
        }

        $key = $this->removal_key((int) $object_id, $taxonomy);

        $this->captured_removals[$key] = array_values(array_unique(array_merge(
            $this->captured_removals[$key] ?? array(),
            $term_ids
        )));
    }

    /**
     * What a parent LOST this request and has not since regained.
     *
     * The intersection with "not currently on the parent" is what keeps the
     * capture from over-subtracting, and it settles two cases at once:
     *
     *  - The #45 case. `get_post_terms()` reads native ∪ ACF, and within one
     *    request the parent's ACF mirror lags its native store (the mirror's
     *    own write has not fired yet), so the union re-reads a just-removed
     *    term as still present. It is absent NATIVELY, so it stays in this set
     *    and is subtracted from both the source and the child — the term cannot
     *    bounce back down.
     *  - Remove-then-re-add in one request. The term IS present natively again,
     *    so it drops out of this set and the child keeps it. The pre-#62 delta
     *    model could not distinguish this; it excluded every same-pass removal
     *    from the add source unconditionally.
     *
     * @param int    $parent_id
     * @param string $taxonomy
     * @return int[] Term IDs to subtract.
     */
    private function parent_removals(int $parent_id, string $taxonomy): array {
        $captured = $this->captured_removals[$this->removal_key($parent_id, $taxonomy)] ?? array();
        if (empty($captured)) {
            return array();
        }

        return array_values(array_diff(
            $captured,
            $this->current_term_ids($parent_id, $taxonomy)
        ));
    }

    /**
     * Whether any enabled rule of this handler works in a taxonomy — the
     * capture hook's cheap gate.
     *
     * @param string $taxonomy
     * @return bool
     */
    private function taxonomy_in_use(string $taxonomy): bool {
        if ($taxonomy === '') {
            return false;
        }
        foreach ($this->get_enabled_rules() as $rule) {
            if (($rule['taxonomy'] ?? '') === $taxonomy) {
                return true;
            }
        }

        return false;
    }

    /**
     * The post's current NATIVE term IDs, sorted — one side of every
     * comparison in this handler.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return int[]
     */
    private function current_term_ids(int $post_id, string $taxonomy): array {
        $ids = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (is_wp_error($ids)) {
            return array();
        }

        return $this->normalize_term_ids((array) $ids);
    }

    /**
     * The term set this post should end up holding: the claim's end state,
     * minus what the parent lost.
     *
     * The claim half is `compute_end_state()` on the base — the SAME function
     * `apply_terms_to_post()` writes through, which is what stops the
     * merge/replace/skip semantics being encoded twice and drifting (#38
     * cluster 2). The subtraction is propagation's own, and applies in every
     * claim (see apply_to_post()).
     *
     * @param int[]  $current           Current term IDs (sorted).
     * @param int[]  $source            Parent's terms, removals already excluded.
     * @param string $conflict_handling merge|replace|skip.
     * @param int[]  $removed           Terms the parent lost this request.
     * @return int[] Sorted target term IDs.
     */
    private function target_term_ids(array $current, array $source, string $conflict_handling, array $removed): array {
        // A SOURCE WITH NOTHING IN IT IS NOT AN INSTRUCTION TO EMPTY THE TARGET.
        // Both pre-#62 push paths returned early on `empty($parent_terms)`, and
        // that early return was load-bearing under the `replace` claim: a parent
        // holding no terms in this taxonomy would otherwise compute an end state
        // of [] and strip the child's own terms. It is not a real reconcile
        // either — a rule whose source has nothing to say has nothing to say.
        // The parent's REMOVALS still apply, because those are a statement about
        // specific terms rather than about the source set, and that is the case
        // the old code reached through its separate removal walk.
        if (empty($source)) {
            return empty($removed)
                ? $this->normalize_term_ids($current)
                : array_values(array_diff($this->normalize_term_ids($current), $this->normalize_term_ids($removed)));
        }

        $target = $this->compute_end_state($current, $source, $conflict_handling);

        if (!empty($removed)) {
            $target = array_values(array_diff($target, $this->normalize_term_ids($removed)));
            sort($target);
        }

        return $target;
    }

    /**
     * Composite key into $captured_removals. One place so the shape cannot
     * drift between writer and reader.
     *
     * @param int    $object_id
     * @param string $taxonomy
     * @return string
     */
    private function removal_key(int $object_id, string $taxonomy): string {
        return $object_id . ':' . $taxonomy;
    }

    /**
     * Convert term_taxonomy IDs to term IDs
     *
     * @param array $tt_ids Term taxonomy IDs
     * @param string $taxonomy Taxonomy name
     * @return array Term IDs
     */
    private function convert_tt_ids_to_term_ids($tt_ids, $taxonomy) {
        if (empty($tt_ids)) {
            return array();
        }

        global $wpdb;

        $tt_ids_sql = implode(',', array_map('absint', $tt_ids));

        $term_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy}
             WHERE term_taxonomy_id IN ($tt_ids_sql)
             AND taxonomy = %s",
            $taxonomy
        ));

        return array_map('absint', $term_ids);
    }

    /**
     * Resolve the rule's `post_types` checkboxes to a concrete slug list for
     * the child query.
     *
     * The post-type GATE (should_process_post) treats empty as "all" and never
     * needs concrete slugs; the fan-out does — get_posts needs real post types
     * to query by post_parent. So empty `post_types` ⇒ every HIERARCHICAL
     * public post type (propagation only acts on parent/child trees; V5). A
     * non-empty checkbox map/list is flattened via the canonical extractor.
     *
     * @param array $rule Rule config.
     * @return string[] Post-type slugs to query for children.
     */
    private function resolve_child_post_types($rule): array {
        $slugs = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs(
            $rule['post_types'] ?? []
        );

        if (empty($slugs)) {
            $slugs = array_values(get_post_types(['public' => true, 'hierarchical' => true]));
        }

        return $slugs;
    }

    /**
     * Get post terms as the UNION of the native taxonomy and any ACF taxonomy
     * field(s) for $taxonomy.
     *
     * Propagation treats "the source's terms" as one merged set and mirrors it
     * into BOTH stores on the target (native via wp_set_object_terms, ACF via
     * update_acf_fields_for_post). With the ACF field's Load/Save Terms ON (the
     * normal case) native == ACF, so the union is a no-op and the two stores stay
     * in lockstep. With Load/Save Terms OFF the two stores are intentionally
     * independent — and this union collapses that separation on the target: a
     * parent's native-only term lands in the child's ACF field and vice-versa.
     * That is accepted behavior (0.6.0); propagation is not channel-preserving.
     * If a "keep native and ACF separate" model is ever needed, this method and
     * the two writers must track native→native / ACF→ACF as distinct channels.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return int[]
     */
    private function get_post_terms($post_id, $taxonomy) {
        $terms = array();

        // Get native taxonomy terms
        $native_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (!is_wp_error($native_terms)) {
            $terms = array_merge($terms, (array) $native_terms);
        }

        // Get ACF taxonomy field terms. Value-independent discovery (not
        // get_field_objects, which returns FALSE for a post with no saved ACF
        // values) so an ACF-only source whose field is attached-but-empty still
        // reads correctly. (0.6.0 ACF B-sweep.)
        foreach ($this->get_acf_taxonomy_fields($post_id, $taxonomy) as $field) {
            $acf_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);
            $terms = array_merge($terms, $acf_terms);
        }

        return $this->normalize_term_ids($terms);
    }

    /**
     * Mirror the reconciled set into this post's ACF taxonomy field(s).
     *
     * Same end state as the native write — the shared `compute_end_state()`
     * plus the parent's removals — computed against the FIELD's current value
     * rather than the native one, because the two stores can legitimately
     * differ (see get_post_terms()).
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @param int[]  $source            Parent's terms, removals already excluded.
     * @param string $conflict_handling merge|replace|skip.
     * @param int[]  $removed           Terms the parent lost this request.
     */
    private function update_acf_fields_for_post($post_id, $taxonomy, $source, $conflict_handling, $removed = array()) {
        // Value-independent field discovery: get_field_objects() returns FALSE for
        // a post with no saved ACF values, so a never-populated child could never
        // get its first ACF write. get_acf_taxonomy_fields resolves by location
        // rules instead. (0.6.0 ACF B-sweep.)
        foreach ($this->get_acf_taxonomy_fields($post_id, $taxonomy) as $field) {
            // Read current value by name (get_field), write by KEY (first-write
            // reference-row registration — see set_acf_taxonomy_value).
            $current_terms = $this->normalize_term_ids(
                $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy)
            );
            $new_terms = $this->target_term_ids($current_terms, $source, $conflict_handling, $removed);

            if ($new_terms !== $current_terms) {
                $this->set_acf_taxonomy_value($post_id, $field['key'], $new_terms);
            }
        }
    }
}
