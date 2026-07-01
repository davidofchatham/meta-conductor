<?php
/**
 * BWS Taxonomy Manager Propagation Handler
 * Handles applying parent post terms to child posts
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropagationHandler extends UnifiedHandlerBase {

    /**
     * Reentrancy guard. An upward inherit or downward propagate writes terms,
     * which fires set_object_terms again — without this the handler re-enters
     * within one request. (V9/V12)
     */
    private $processing = false;

    public function get_handler_type(): string {
        return 'propagation';
    }

    protected function get_rule_type(): string {
        return 'propagation_rules';
    }

    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        add_action('save_post', array($this, 'on_parent_post_save'), 15, 3);
        add_action('set_object_terms', array($this, 'on_parent_terms_set'), 10, 6);

        // Hook into ACF field updates
        add_action('acf/save_post', array($this, 'on_acf_save_post'), 25);
    }

    // Intentional no-op (not a forgotten implementation). Propagation fires via
    // its own save_post / set_object_terms / acf/save_post hooks (above), with
    // new-child inherit folded into on_parent_post_save (B3); the base
    // process_post routes through RuleEngine, which propagation does not use.
    // (The redundant TaxonomyManager on_post_save loop that used to call this was
    // removed in the Phase-3 teardown; process_existing_posts is the only
    // remaining caller — see B1/#31.)
    public function process_post($post_id, $post, $update) {}

    /**
     * Handle a post save: propagate DOWN to children, and (if the post itself
     * has a parent) inherit UP from its parent. Both directions honor the rule's
     * conflict_handling. Upward inherit lives here — on the child's own save —
     * NOT on wp_insert_post, which fires at auto-draft creation and misses the
     * real save (B3/V12).
     */
    public function on_parent_post_save($post_id, $post, $update) {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if ($this->processing) {
            return;
        }

        $enabled_rules = $this->get_enabled_rules();

        $this->processing = true;
        try {
            foreach ($enabled_rules as $rule) {
                if (!$this->should_process_post($post_id, $rule)) {
                    continue;
                }

                // Inherit UP from parent first (this post is a child), so a
                // freshly created child gets its parent's terms on its own save.
                if ($post->post_parent > 0) {
                    $this->inherit_terms_from_parent($post_id, $post, $rule);
                }

                // Propagate DOWN to children (this post is a parent).
                $this->propagate_terms_to_children($post_id, $rule);
            }
        } finally {
            $this->processing = false;
        }
    }
    
    /**
     * Handle when terms are set on a parent post
     */
    public function on_parent_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        // No wp_is_post_autosave/revision guard here (unlike on_parent_post_save,
        // which needs one because save_post genuinely fires for autosaves/revisions).
        // set_object_terms does NOT fire for revision/autosave objects in normal WP
        // operation — revisions don't get taxonomy relationships — and the
        // should_process_post post-type gate below rejects post_type 'revision' for
        // any normally-configured rule. So the guard would be dead weight. (0.6.0 review)
        //
        // Suppress re-entry while our own propagate/inherit write is firing
        // set_object_terms (V9/V12). A user-initiated term set runs normally
        // ($processing === false).
        if ($this->processing) {
            return;
        }

        $enabled_rules = $this->get_enabled_rules();

        $this->processing = true;
        try {
            foreach ($enabled_rules as $rule) {
                if ($rule['taxonomy'] !== $taxonomy) {
                    continue;
                }

                $post = get_post($object_id);
                if (!$post || !$this->should_process_post($object_id, $rule)) {
                    continue;
                }

                // Calculate which terms were removed from parent
                $removed_tt_ids = array_diff($old_tt_ids ?? array(), $tt_ids ?? array());

                // Propagate term removals to children FIRST
                if (!empty($removed_tt_ids)) {
                    $this->propagate_term_removals_to_children($object_id, $rule, $removed_tt_ids);
                }

                // Then propagate new/current terms to children
                $this->propagate_terms_to_children($object_id, $rule);
            }
        } finally {
            $this->processing = false;
        }
    }
    
    /**
     * AC v7 reapply seam (SPEC §V2/§V6). Delegates to the gated on_acf_save_post
     * — the apply path AC v7's update_field() bypasses.
     */
    public function reapply_for_post(int $post_id): void {
        $this->on_acf_save_post($post_id);
    }

    /**
     * Handle ACF field saves for propagation
     */
    public function on_acf_save_post($post_id) {
        // Skip if not a real post ID
        if (!is_numeric($post_id)) {
            return;
        }

        if ($this->processing) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $enabled_rules = $this->get_enabled_rules();

        $this->processing = true;
        try {
            foreach ($enabled_rules as $rule) {
                if (!$this->should_process_post($post_id, $rule)) {
                    continue;
                }

                // Check for ACF taxonomy fields and propagate if necessary
                $this->process_acf_propagation($post_id, $rule);
            }
        } finally {
            $this->processing = false;
        }
    }
    
    /**
     * Process ACF field propagation
     */
    private function process_acf_propagation($post_id, $rule) {
        // Only propagate on an ACF save if this post actually carries an ACF
        // taxonomy field for the rule's taxonomy. Value-independent discovery
        // (not get_field_objects, which is FALSE for a post with no saved ACF
        // values). (0.6.0 ACF B-sweep.)
        if (empty($this->get_acf_taxonomy_fields($post_id, $rule['taxonomy']))) {
            return;
        }

        $this->propagate_terms_to_children($post_id, $rule);
    }
    
    // Removed: process_new_child_post() + the wp_insert_post path (B3). New-child
    // inheritance now runs on the child's OWN save_post (on_parent_post_save,
    // post_parent > 0 branch) — reliable across editors and conflict-aware,
    // unlike the auto-draft-timed wp_insert_post hook it replaces.

    /**
     * Propagate terms from parent to all children
     */
    private function propagate_terms_to_children($parent_id, $rule) {
        $children = $this->get_all_child_posts($parent_id, $this->resolve_child_post_types($rule));

        if (empty($children)) {
            return;
        }

        $taxonomy = $rule['taxonomy'];
        $conflict_handling = $rule['conflict_handling'] ?? 'merge';

        // Get parent terms (both native and ACF)
        $parent_terms = $this->get_post_terms($parent_id, $taxonomy);

        if (empty($parent_terms)) {
            return;
        }

        foreach ($children as $child_id) {
            // ACF taxonomy mirror fields are a SEPARATE store from native terms
            // and can drift out of sync independently (edited out-of-band, a
            // save_terms-off field, a prior partial write). update_acf_fields_for_post
            // self-guards (writes only when the ACF value actually differs), so
            // run it unconditionally — the native-term short-circuit below must
            // NOT gate it, or a native-correct/ACF-stale child never re-syncs.
            $this->update_acf_fields_for_post($child_id, $taxonomy, $parent_terms, $conflict_handling);

            // No-change short-circuit (symmetric with the upward inherit path):
            // a no-op parent save would otherwise re-write NATIVE terms + log for
            // every descendant even when they already hold the terms. Skip the
            // native write for children already in the target state.
            if (!$this->write_would_change_terms($child_id, $taxonomy, $parent_terms, $conflict_handling)) {
                continue;
            }

            $this->apply_terms_to_post($child_id, $taxonomy, $parent_terms, $conflict_handling);

            $this->debug_log(
                sprintf('Propagated terms from parent %d to child %d', $parent_id, $child_id),
                array('taxonomy' => $taxonomy, 'terms' => $parent_terms)
            );
        }
    }

    /**
     * Propagate term removals from parent to all children
     *
     * When terms are removed from the parent post, remove those same terms from child posts
     * (but only if the child has them - don't remove terms the child has independently)
     *
     * @param int $parent_id Parent post ID
     * @param array $rule Propagation rule configuration
     * @param array $removed_tt_ids Term taxonomy IDs that were removed from parent
     */
    private function propagate_term_removals_to_children($parent_id, $rule, $removed_tt_ids) {
        $children = $this->get_all_child_posts($parent_id, $this->resolve_child_post_types($rule));

        if (empty($children)) {
            return;
        }

        $taxonomy = $rule['taxonomy'];

        // Convert term_taxonomy IDs to term IDs
        $removed_term_ids = $this->convert_tt_ids_to_term_ids($removed_tt_ids, $taxonomy);

        if (empty($removed_term_ids)) {
            return;
        }

        foreach ($children as $child_id) {
            // Get current child terms
            $child_terms = wp_get_object_terms($child_id, $taxonomy, array('fields' => 'ids'));

            if (is_wp_error($child_terms) || empty($child_terms)) {
                continue;
            }

            // Remove only the terms that were removed from parent
            $updated_child_terms = array_diff($child_terms, $removed_term_ids);

            // Only update if terms actually changed
            if ($updated_child_terms !== $child_terms) {
                wp_set_object_terms($child_id, $updated_child_terms, $taxonomy);

                // Also update ACF fields if they exist
                $this->remove_terms_from_acf_fields($child_id, $taxonomy, $removed_term_ids);

                $this->debug_log(
                    sprintf('Removed terms from child %d (removed from parent %d)', $child_id, $parent_id),
                    array(
                        'taxonomy' => $taxonomy,
                        'removed_terms' => $removed_term_ids,
                        'child_terms_before' => $child_terms,
                        'child_terms_after' => $updated_child_terms
                    )
                );
            }
        }
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
     * Remove specific terms from ACF taxonomy fields
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param array $terms_to_remove Term IDs to remove
     */
    private function remove_terms_from_acf_fields($post_id, $taxonomy, $terms_to_remove) {
        // Value-independent discovery (not get_field_objects — FALSE on empty).
        // (0.6.0 ACF B-sweep.) Removing from an already-empty field is a no-op.
        foreach ($this->get_acf_taxonomy_fields($post_id, $taxonomy) as $field) {
            $current_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);

            // Remove the specified terms
            $updated_terms = array_diff($current_terms, $terms_to_remove);

            if ($updated_terms !== $current_terms) {
                $this->set_acf_taxonomy_value($post_id, $field['key'], $updated_terms);
            }
        }
    }
    
    /**
     * Inherit terms from parent to child
     */
    private function inherit_terms_from_parent($child_id, $child_post, $rule) {
        $parent_id = $child_post->post_parent;
        $parent_post = get_post($parent_id);

        // Gate on the PARENT here; on_parent_post_save already gated the child.
        // In stock WP a post_parent is always the same post type as the child
        // (the editor's parent dropdown is type-scoped), so the two gates always
        // agree and this double-gate is redundant-but-harmless. It only diverges
        // under a cross-type post_parent (programmatic / non-stock) — if that
        // ever becomes real, decide whether propagation scopes the source
        // (parent), the target (child), or both. (0.6.0 review)
        if (!$parent_post || !$this->should_process_post($parent_id, $rule)) {
            return;
        }

        $taxonomy = $rule['taxonomy'];
        $conflict_handling = $rule['conflict_handling'] ?? 'merge';

        // Get parent terms
        $parent_terms = $this->get_post_terms($parent_id, $taxonomy);

        if (empty($parent_terms)) {
            return;
        }

        // ACF taxonomy mirror fields are a SEPARATE store from native terms and
        // can drift independently, so re-sync unconditionally — it self-guards
        // against redundant writes. The native-term short-circuit below must NOT
        // gate it (symmetric with propagate_terms_to_children), else a
        // native-correct/ACF-stale child never gets its ACF field repaired.
        $this->update_acf_fields_for_post($child_id, $taxonomy, $parent_terms, $conflict_handling);

        // No-change short-circuit. WP fires save_post more than once per editor
        // save (and a create is often two requests), so without this the same
        // inherit re-runs — redundant NATIVE write + duplicate debug log. Compute
        // the would-be end state for this conflict mode and skip the native write
        // if the child is already there. Cause-agnostic (covers both intra-request
        // double-fire and cross-request re-save). (V12)
        if (!$this->write_would_change_terms($child_id, $taxonomy, $parent_terms, $conflict_handling)) {
            return;
        }

        $this->apply_terms_to_post($child_id, $taxonomy, $parent_terms, $conflict_handling);

        $this->debug_log(
            sprintf('Child %d inherited terms from parent %d', $child_id, $parent_id),
            array('taxonomy' => $taxonomy, 'terms' => $parent_terms)
        );
    }

    /**
     * Whether applying $terms to $post_id under $conflict_handling would actually
     * change the post's current terms. False ⇒ already in the target state, skip
     * the write (and the log). Mirrors apply_terms_to_post's per-mode end state.
     * Shared by BOTH directions — upward inherit and downward propagate — so the
     * no-change short-circuit and the conflict-mode semantics live in one place.
     * (V12)
     *
     * @param int      $post_id
     * @param string   $taxonomy
     * @param int[]    $terms             Term IDs to apply.
     * @param string   $conflict_handling merge|replace|skip.
     * @return bool
     */
    private function write_would_change_terms($post_id, $taxonomy, $terms, $conflict_handling): bool {
        $current = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (is_wp_error($current)) {
            return true; // can't tell — let the write proceed
        }

        $current = array_map('absint', $current);
        $incoming = array_map('absint', $terms);
        sort($current);

        switch ($conflict_handling) {
            case 'replace':
                // Skip only if the post already equals the incoming set exactly.
                $target = $incoming;
                sort($target);
                return $current !== $target;

            case 'skip':
                // skip mode writes only when the post has no terms yet.
                return empty($current);

            case 'merge':
            default:
                // Skip if the incoming set is already a subset of the current.
                return !empty(array_diff($incoming, $current));
        }
    }

    /**
     * Resolve the rule's `post_types` checkboxes to a concrete slug list for
     * the child-post query.
     *
     * The post-type GATE (should_process_post) treats empty as "all" and never
     * needs concrete slugs; the child WALK does — get_posts needs real post
     * types to query by post_parent. So empty `post_types` ⇒ every HIERARCHICAL
     * public post type (propagation only acts on parent/child trees; V5). A
     * non-empty checkbox map/list is flattened via the canonical extractor.
     *
     * @param array $rule Rule config.
     * @return string[] Post-type slugs to walk for children.
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
     * Get all child posts recursively
     *
     * @param int      $parent_id  Parent post ID.
     * @param string[] $post_types Post-type slugs to query (get_posts accepts an array).
     * @return int[] Child post IDs (recursive).
     */
    private function get_all_child_posts($parent_id, array $post_types) {
        if (empty($post_types)) {
            return array();
        }

        $children = array();

        $child_posts = get_posts(array(
            'post_type' => $post_types,
            'post_parent' => $parent_id,
            'post_status' => array('publish', 'draft', 'private'),
            'numberposts' => -1,
            'fields' => 'ids'
        ));

        foreach ($child_posts as $child_id) {
            $children[] = $child_id;

            // Recursively get children of children
            $grandchildren = $this->get_all_child_posts($child_id, $post_types);
            $children = array_merge($children, $grandchildren);
        }

        return $children;
    }
    
    /**
     * Get post terms as the UNION of the native taxonomy and any ACF taxonomy
     * field(s) for $taxonomy.
     *
     * Propagation treats "the source's terms" as one merged set and mirrors it
     * into BOTH stores on the targets (native via apply_terms_to_post, ACF via
     * update_acf_fields_for_post). With the ACF field's Load/Save Terms ON (the
     * normal case) native == ACF, so the union is a no-op and the two stores stay
     * in lockstep. With Load/Save Terms OFF the two stores are intentionally
     * independent — and this union collapses that separation on the targets: a
     * parent's native-only term lands in the child's ACF field and vice-versa.
     * That is accepted behavior (0.6.0); propagation is not channel-preserving.
     * If a "keep native and ACF separate" model is ever needed, this method and
     * the two writers must track native→native / ACF→ACF as distinct channels.
     */
    private function get_post_terms($post_id, $taxonomy) {
        $terms = array();
        
        // Get native taxonomy terms
        $native_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (!is_wp_error($native_terms)) {
            $terms = array_merge($terms, $native_terms);
        }
        
        // Get ACF taxonomy field terms. Value-independent discovery (not
        // get_field_objects, which returns FALSE for a post with no saved ACF
        // values) so an ACF-only source whose field is attached-but-empty still
        // reads correctly. (0.6.0 ACF B-sweep.)
        foreach ($this->get_acf_taxonomy_fields($post_id, $taxonomy) as $field) {
            $acf_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);
            $terms = array_merge($terms, $acf_terms);
        }

        return array_unique(array_filter($terms));
    }
    
    /**
     * Update ACF fields for a post with new terms
     */
    private function update_acf_fields_for_post($post_id, $taxonomy, $terms, $conflict_handling) {
        // Value-independent field discovery: get_field_objects() returns FALSE for
        // a post with no saved ACF values, so a never-populated child could never
        // get its first ACF write. get_acf_taxonomy_fields resolves by location
        // rules instead. (0.6.0 ACF B-sweep.)
        foreach ($this->get_acf_taxonomy_fields($post_id, $taxonomy) as $field) {
            // Read current value by name (get_field), write by KEY (first-write
            // reference-row registration — see set_acf_taxonomy_value).
            $current_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);
            $new_terms = $this->merge_terms_based_on_conflict_handling($current_terms, $terms, $conflict_handling);

            if ($new_terms !== $current_terms) {
                $this->set_acf_taxonomy_value($post_id, $field['key'], $new_terms);
            }
        }
    }
    
    /**
     * Merge terms based on conflict handling
     */
    private function merge_terms_based_on_conflict_handling($current_terms, $new_terms, $conflict_handling) {
        switch ($conflict_handling) {
            case 'replace':
                return $new_terms;
                
            case 'merge':
                return array_unique(array_merge($current_terms, $new_terms));
                
            case 'skip':
                return empty($current_terms) ? $new_terms : $current_terms;
                
            default:
                return $current_terms;
        }
    }
    
    /**
     * Validate rule data
     */
    public function validate_rule($rule_data) {
        $errors = array();

        // Validate post types (optional — empty ⇒ all hierarchical types).
        $post_types = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($rule_data['post_types'] ?? []);
        foreach ($post_types as $post_type) {
            if (!post_type_exists($post_type)) {
                $errors[] = sprintf(__('Post type "%s" does not exist.', 'bws-meta-manager'), $post_type);
                continue;
            }
            $post_type_obj = get_post_type_object($post_type);
            if (!$post_type_obj->hierarchical) {
                $errors[] = sprintf(__('Post type "%s" must be hierarchical for propagation rules.', 'bws-meta-manager'), $post_type);
            }
        }

        // Validate taxonomy
        if (empty($rule_data['taxonomy'])) {
            $errors[] = __('Taxonomy is required.', 'bws-meta-manager');
        } elseif (!taxonomy_exists($rule_data['taxonomy'])) {
            $errors[] = __('Selected taxonomy does not exist.', 'bws-meta-manager');
        }

        // Validate conflict handling
        $valid_handling = array('replace', 'merge', 'skip');
        if (!empty($rule_data['conflict_handling']) &&
            !in_array($rule_data['conflict_handling'], $valid_handling)) {
            $errors[] = __('Invalid conflict handling method selected.', 'bws-meta-manager');
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized_data' => $this->sanitize_rule_data($rule_data)
        );
    }

    /**
     * Sanitize rule data
     */
    private function sanitize_rule_data($rule_data) {
        return array(
            'post_types' => \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($rule_data['post_types'] ?? []),
            'taxonomy' => sanitize_text_field($rule_data['taxonomy'] ?? ''),
            'conflict_handling' => sanitize_text_field($rule_data['conflict_handling'] ?? 'merge'),
            'enabled' => !empty($rule_data['enabled'])
        );
    }

}
