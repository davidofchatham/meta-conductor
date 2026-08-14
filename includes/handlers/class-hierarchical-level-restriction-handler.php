<?php
/**
 * BWS Taxonomy Manager Hierarchical Level Restriction Handler
 * Restricts taxonomy terms to one per hierarchical level, removing siblings when new terms are applied
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class HierarchicalLevelRestrictionHandler extends UnifiedHandlerBase {

    /**
     * Track processing to prevent infinite loops
     */
    private $processing = false;

    /**
     * Cache for term level calculations
     */
    private $term_level_cache = array();

    public function get_handler_type(): string {
        return 'hierarchical_level_restriction';
    }

    protected function get_rule_type(): string {
        return 'hierarchical_level_restriction_rules';
    }

    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        // Hook with high priority to run before hierarchical handler
        add_action('set_object_terms', array($this, 'on_terms_set'), 5, 6);

        // Hook into ACF field updates
        add_action('acf/save_post', array($this, 'on_acf_save_post'), 15);
    }

    // Intentional no-op (not a forgotten implementation). Level restrictions
    // fire via on_terms_set / on_acf_save_post; the base process_post routes
    // through RuleEngine, which this handler does not use. (The redundant
    // TaxonomyManager on_post_save loop that used to call this was removed in the
    // Phase-3 teardown; process_existing_posts is the only remaining caller.)
    public function process_post($post_id, $post, $update) {}

    /**
     * Bulk-apply primitive (#31). Delegates to apply_level_restrictions for one
     * rule + post, gated by the post-type check. Guards $processing so the
     * wp_set_object_terms it fires doesn't re-enter on_terms_set. Returns whether
     * the post's terms actually changed (a post already within the restriction is
     * a no-op → false), so the bulk count reflects posts pruned (#31).
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        if (!$this->should_process_post($post_id, $rule)) {
            return false;
        }
        $taxonomy = $rule['taxonomy'] ?? '';
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return false;
        }
        $before = $this->terms_fingerprint($post_id, $taxonomy);
        $this->processing = true;
        try {
            $this->apply_level_restrictions($post_id, $taxonomy, $rule);
        } finally {
            $this->processing = false;
        }
        return $this->terms_fingerprint($post_id, $taxonomy) !== $before;
    }

    /**
     * Handle terms being set on an object
     */
    public function on_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if ($this->processing) {
            return;
        }
        
        $post = get_post($object_id);
        if (!$post) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if ($rule['taxonomy'] !== $taxonomy) {
                continue;
            }

            if (!$this->should_process_post($object_id, $rule)) {
                continue;
            }

            // Reset even if a downstream hook/filter throws, so later rules in
            // this request aren't silently skipped by a stuck guard (mirrors the
            // propagation handler's try/finally).
            $this->processing = true;
            try {
                $this->process_term_level_restrictions($object_id, $taxonomy, $tt_ids, $old_tt_ids, $rule);
            } finally {
                $this->processing = false;
            }
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
     * Handle ACF field saves
     */
    public function on_acf_save_post($post_id) {
        if ($this->processing || !is_numeric($post_id)) {
            return;
        }
        
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();

        foreach ($enabled_rules as $rule) {
            if (!$this->should_process_post($post_id, $rule)) {
                continue;
            }

            // Set the reentrancy guard (mirrors on_terms_set): process_acf_level_restrictions
            // calls wp_set_object_terms, which re-fires set_object_terms -> on_terms_set.
            // Without this the handler re-enters and runs a second (idempotent but
            // wasteful) restriction pass. try/finally so a downstream throw can't
            // leave the guard stuck for later rules in this request.
            $this->processing = true;
            try {
                // Check if this taxonomy has ACF fields that might have been updated
                $this->process_acf_level_restrictions($post_id, $rule['taxonomy'], $rule);
            } finally {
                $this->processing = false;
            }
        }
    }
    
    /**
     * Process term level restrictions when terms are set
     */
    private function process_term_level_restrictions($post_id, $taxonomy, $new_tt_ids, $old_tt_ids, $rule) {
        // Get term IDs from term_taxonomy IDs
        $new_term_ids = $this->convert_tt_ids_to_term_ids($new_tt_ids, $taxonomy);
        $old_term_ids = $this->convert_tt_ids_to_term_ids($old_tt_ids ?? array(), $taxonomy);
        
        // Find newly added terms
        $added_terms = array_diff($new_term_ids, $old_term_ids);
        
        if (empty($added_terms)) {
            return;
        }
        
        // Process level restrictions for newly added terms
        $final_terms = $this->calculate_restricted_terms($new_term_ids, $taxonomy, $rule);
        
        // Apply the restricted terms if they're different
        if ($final_terms !== $new_term_ids) {
            wp_set_object_terms($post_id, $final_terms, $taxonomy);
            
            $this->debug_log(
                sprintf('Applied level restrictions to post %d for taxonomy %s', $post_id, $taxonomy),
                array(
                    'original_terms' => $new_term_ids,
                    'restricted_terms' => $final_terms,
                    'removed_terms' => array_diff($new_term_ids, $final_terms)
                )
            );
        }
    }
    
    /**
     * Process ACF level restrictions
     */
    private function process_acf_level_restrictions($post_id, $taxonomy, $rule) {
        // Value-independent discovery (0.6.x ACF B-sweep, #41): resolve fields
        // from field-group LOCATION rules, not stored meta, so an attached-but-
        // empty ACF taxonomy field is still found. Read by name, write by key.
        $fields = $this->get_acf_taxonomy_fields($post_id, $taxonomy);

        foreach ($fields as $field) {
            $current_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);

            if (empty($current_terms)) {
                continue;
            }

            // Apply level restrictions
            $restricted_terms = $this->calculate_restricted_terms($current_terms, $taxonomy, $rule);

            if ($restricted_terms !== $current_terms) {
                // Update ACF field — write by KEY so a first-write registers the
                // ACF reference row (see set_acf_taxonomy_value docblock).
                $this->set_acf_taxonomy_value($post_id, $field['key'], $restricted_terms);

                // Update native taxonomy terms
                wp_set_object_terms($post_id, $restricted_terms, $taxonomy);

                $this->debug_log(
                    sprintf('Applied ACF level restrictions to post %d for taxonomy %s', $post_id, $taxonomy),
                    array('field' => $field['name'], 'restricted_terms' => $restricted_terms)
                );
            }
        }
    }
    
    /**
     * Calculate restricted terms based on hierarchical levels.
     *
     * Two steps, in this order: the mode decides which terms survive, then
     * `include_ancestors` — if set — adds back the parent chain of whatever
     * survived.
     *
     * ### `include_ancestors` has ONE meaning as of 0.8.0 (#32)
     *
     * It used to branch on the mode and mean two different things:
     * additive in `deepest_only`, and in `one_per_level` a suppression of the
     * `remove_conflicting_ancestors()` pass. That second meaning was a no-op
     * in every case — `one_per_level` has already reduced the set to at most
     * one term per level by the time the pass runs, and the pass only dropped
     * a term whose ancestor sat on a level holding MORE than one term, which
     * by then can never be true. So the flag did nothing at all in that mode
     * while the UI claimed otherwise.
     *
     * Redefined to the additive meaning uniformly, which is the one that was
     * real, and applied in all three modes. `shallowest_only` therefore gains
     * behaviour it did not have: asking to keep ancestors of terms selected
     * for being shallowest does pull in still-shallower terms, and that is
     * what the author asked for.
     *
     * The change was free rather than breaking: level restriction runs on the
     * test site only (CLAUDE.md live-rule-type rule), so no stored rule
     * needed migrating. Config side: TermRulesConfig::level_restriction_subfields().
     *
     * @param int[]  $term_ids Term IDs currently on the post.
     * @param string $taxonomy Taxonomy slug.
     * @param array  $rule     Rule configuration.
     * @return int[] Terms that survive the restriction.
     */
    private function calculate_restricted_terms($term_ids, $taxonomy, $rule) {
        if (empty($term_ids)) {
            return $term_ids;
        }

        $restriction_mode = $rule['restriction_mode'] ?? 'one_per_level';
        $include_ancestors = !empty($rule['include_ancestors']);

        // Group terms by their hierarchical level
        $terms_by_level = $this->group_terms_by_level($term_ids, $taxonomy);

        $final_terms = array();

        if ($restriction_mode === 'one_per_level') {
            // Keep only one term per level (prefer the last one added/most specific)
            foreach ($terms_by_level as $level => $level_terms) {
                // Sort by term order or keep the last one
                $final_terms[] = end($level_terms);
            }
        } elseif ($restriction_mode === 'deepest_only') {
            // Keep only terms from the deepest level
            $max_level = max(array_keys($terms_by_level));
            $final_terms = $terms_by_level[$max_level];
        } elseif ($restriction_mode === 'shallowest_only') {
            // Keep only terms from the shallowest level
            $min_level = min(array_keys($terms_by_level));
            $final_terms = $terms_by_level[$min_level];
        }

        // One meaning, every mode: keep the lineage of whatever survived.
        // Iterate a snapshot — the ancestors being added are not themselves
        // walked again (get_ancestors already returns the full chain).
        if ($include_ancestors) {
            foreach (array_values($final_terms) as $term_id) {
                $final_terms = array_merge($final_terms, get_ancestors($term_id, $taxonomy));
            }
        }

        return array_values(array_unique($final_terms));
    }
    
    /**
     * Group terms by their hierarchical level
     */
    private function group_terms_by_level($term_ids, $taxonomy) {
        $terms_by_level = array();
        
        foreach ($term_ids as $term_id) {
            $level = $this->get_term_level($term_id, $taxonomy);
            
            if (!isset($terms_by_level[$level])) {
                $terms_by_level[$level] = array();
            }
            
            $terms_by_level[$level][] = $term_id;
        }
        
        return $terms_by_level;
    }
    
    /**
     * Get the hierarchical level of a term (0 = root level)
     */
    private function get_term_level($term_id, $taxonomy) {
        $cache_key = $taxonomy . '_' . $term_id;
        
        if (isset($this->term_level_cache[$cache_key])) {
            return $this->term_level_cache[$cache_key];
        }
        
        $level = 0;
        $current_term = get_term($term_id, $taxonomy);
        
        while ($current_term && !is_wp_error($current_term) && $current_term->parent > 0) {
            $level++;
            $current_term = get_term($current_term->parent, $taxonomy);
            
            // Prevent infinite loops
            if ($level > 20) {
                break;
            }
        }
        
        $this->term_level_cache[$cache_key] = $level;
        
        return $level;
    }
    
    // remove_conflicting_ancestors() lived here until 0.8.0. It ran only on
    // the `one_per_level` + !include_ancestors path and could not remove
    // anything on it: the mode had already reduced the set to one term per
    // level, and the method only dropped a term whose ancestor sat on a level
    // holding more than one. Deleted with the flag's second meaning (#32) —
    // see calculate_restricted_terms().

    /**
     * Convert term_taxonomy IDs to term IDs
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
     * Apply level restrictions to one existing post.
     *
     * Per-post bulk-apply primitive. NOT currently wired: post-migration the
     * base process_existing_posts() drives bulk via process_post(), which is a
     * no-op here (V4) — so the "process existing posts" tool is inert for this
     * handler, same as every hook-driven unified handler since 0.4.0. The
     * systemic fix (base routes bulk through an apply_to_post() primitive each
     * handler implements) will call this. Kept ready, not dead. See issue.
     */
    private function apply_level_restrictions($post_id, $taxonomy, $rule) {
        $current_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        
        if (is_wp_error($current_terms) || empty($current_terms)) {
            return;
        }
        
        $restricted_terms = $this->calculate_restricted_terms($current_terms, $taxonomy, $rule);
        
        if ($restricted_terms !== $current_terms) {
            wp_set_object_terms($post_id, $restricted_terms, $taxonomy);
            
            $this->debug_log(
                sprintf('Applied level restrictions to existing post %d', $post_id),
                array(
                    'original_terms' => $current_terms,
                    'restricted_terms' => $restricted_terms
                )
            );
        }
    }
    
    /**
     * Get rules summary for admin display
     */
    public function get_rules_summary() {
        $enabled_rules = $this->get_enabled_rules();
        
        $summary = array(
            'total_rules' => count($enabled_rules),
            'taxonomies' => array(),
            'restriction_modes' => array()
        );
        
        foreach ($enabled_rules as $rule) {
            $summary['taxonomies'][] = $rule['taxonomy'];
            $summary['restriction_modes'][] = $rule['restriction_mode'] ?? 'one_per_level';
        }
        
        $summary['taxonomies'] = array_unique($summary['taxonomies']);
        $summary['restriction_modes'] = array_unique($summary['restriction_modes']);
        
        return $summary;
    }
}
