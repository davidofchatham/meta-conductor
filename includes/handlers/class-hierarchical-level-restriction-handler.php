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
        if (!function_exists('get_field_objects')) {
            return;
        }
        
        $field_objects = get_field_objects($post_id);
        if (!$field_objects) {
            return;
        }
        
        foreach ($field_objects as $field) {
            // Check if this is a taxonomy field for our taxonomy
            if ($field['type'] === 'taxonomy' && 
                isset($field['taxonomy']) && 
                $field['taxonomy'] === $taxonomy) {
                
                $current_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);
                
                if (empty($current_terms)) {
                    continue;
                }
                
                // Apply level restrictions
                $restricted_terms = $this->calculate_restricted_terms($current_terms, $taxonomy, $rule);
                
                if ($restricted_terms !== $current_terms) {
                    // Update ACF field
                    $this->set_acf_taxonomy_value($post_id, $field['name'], $restricted_terms);
                    
                    // Update native taxonomy terms
                    wp_set_object_terms($post_id, $restricted_terms, $taxonomy);
                    
                    $this->debug_log(
                        sprintf('Applied ACF level restrictions to post %d for taxonomy %s', $post_id, $taxonomy),
                        array('field' => $field['name'], 'restricted_terms' => $restricted_terms)
                    );
                }
            }
        }
    }
    
    /**
     * Calculate restricted terms based on hierarchical levels
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
            
            // If including ancestors, add ancestors of the deepest terms
            if ($include_ancestors) {
                foreach ($final_terms as $term_id) {
                    $ancestors = get_ancestors($term_id, $taxonomy);
                    $final_terms = array_merge($final_terms, $ancestors);
                }
            }
        } elseif ($restriction_mode === 'shallowest_only') {
            // Keep only terms from the shallowest level
            $min_level = min(array_keys($terms_by_level));
            $final_terms = $terms_by_level[$min_level];
        }
        
        // Remove ancestors that conflict with the restriction rules
        if (!$include_ancestors && $restriction_mode === 'one_per_level') {
            $final_terms = $this->remove_conflicting_ancestors($final_terms, $taxonomy);
        }
        
        return array_unique($final_terms);
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
    
    /**
     * Remove ancestors that conflict with level restrictions
     */
    private function remove_conflicting_ancestors($term_ids, $taxonomy) {
        $terms_to_keep = array();
        $terms_by_level = $this->group_terms_by_level($term_ids, $taxonomy);
        
        foreach ($terms_by_level as $level => $level_terms) {
            foreach ($level_terms as $term_id) {
                $ancestors = get_ancestors($term_id, $taxonomy);
                
                // Check if any ancestors are in the same level restriction
                $has_conflicting_ancestor = false;
                foreach ($ancestors as $ancestor_id) {
                    $ancestor_level = $this->get_term_level($ancestor_id, $taxonomy);
                    if (isset($terms_by_level[$ancestor_level]) && 
                        count($terms_by_level[$ancestor_level]) > 1) {
                        $has_conflicting_ancestor = true;
                        break;
                    }
                }
                
                if (!$has_conflicting_ancestor) {
                    $terms_to_keep[] = $term_id;
                }
            }
        }
        
        return $terms_to_keep;
    }
    
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
     * Validate rule data
     */
    public function validate_rule($rule_data) {
        $errors = array();
        
        // Validate taxonomy
        if (empty($rule_data['taxonomy'])) {
            $errors[] = __('Taxonomy is required.', 'bws-taxonomy-manager');
        } elseif (!taxonomy_exists($rule_data['taxonomy'])) {
            $errors[] = __('Selected taxonomy does not exist.', 'bws-taxonomy-manager');
        } else {
            $taxonomy = get_taxonomy($rule_data['taxonomy']);
            if (!$taxonomy->hierarchical) {
                $errors[] = __('Selected taxonomy must be hierarchical.', 'bws-taxonomy-manager');
            }
        }
        
        // Validate restriction mode
        $valid_modes = array('one_per_level', 'deepest_only', 'shallowest_only');
        if (!empty($rule_data['restriction_mode']) && 
            !in_array($rule_data['restriction_mode'], $valid_modes)) {
            $errors[] = __('Invalid restriction mode selected.', 'bws-taxonomy-manager');
        }
        
        // Validate post types (if specified)
        if (!empty($rule_data['post_types'])) {
            foreach ($rule_data['post_types'] as $post_type) {
                if (!post_type_exists($post_type)) {
                    $errors[] = sprintf(__('Post type "%s" does not exist.', 'bws-taxonomy-manager'), $post_type);
                }
            }
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
            'taxonomy' => sanitize_text_field($rule_data['taxonomy'] ?? ''),
            'restriction_mode' => sanitize_text_field($rule_data['restriction_mode'] ?? 'one_per_level'),
            'include_ancestors' => !empty($rule_data['include_ancestors']),
            'post_types' => array_map('sanitize_text_field', $rule_data['post_types'] ?? array()),
            'enabled' => !empty($rule_data['enabled'])
        );
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
