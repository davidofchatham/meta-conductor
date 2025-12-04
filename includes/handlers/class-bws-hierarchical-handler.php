<?php
/**
 * BWS Taxonomy Manager Hierarchical Handler
 * Handles applying parent/grandparent terms when child terms are selected
 * 
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Hierarchical_Handler extends BWS_Handler_Base {
    
    /**
     * Handler type
     */
    protected $handler_type = 'hierarchical';
    
    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        add_action('set_object_terms', array($this, 'on_terms_set'), 10, 6);
        
        // Hook into ACF field updates
        add_action('acf/save_post', array($this, 'on_acf_save_post'), 20);
    }
    
    /**
     * Process a post
     */
    public function process_post($post_id, $post, $update) {
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if (!$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            $this->process_hierarchical_terms($post_id, $rule['taxonomy'], $rule);
        }
    }
    
    /**
     * Handle terms being set on an object
     */
    public function on_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        // Skip if no terms were actually set
        if (empty($tt_ids)) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if ($rule['taxonomy'] !== $taxonomy) {
                continue;
            }
            
            $post = get_post($object_id);
            if (!$post || !$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            // Process hierarchical terms for this taxonomy
            $this->process_hierarchical_terms($object_id, $taxonomy, $rule);
        }
    }
    
    /**
     * Handle ACF field saves
     */
    public function on_acf_save_post($post_id) {
        // Skip if not a real post ID
        if (!is_numeric($post_id)) {
            return;
        }
        
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if (!$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            // Check if this taxonomy has ACF fields that might have been updated
            $this->process_acf_hierarchical_terms($post_id, $rule['taxonomy'], $rule);
        }
    }
    
    /**
     * Process hierarchical terms for a post and taxonomy
     */
    private function process_hierarchical_terms($post_id, $taxonomy, $rule) {
        // Get current terms for this post and taxonomy
        $current_terms = wp_get_object_terms($post_id, $taxonomy, array(
            'fields' => 'ids'
        ));
        
        if (is_wp_error($current_terms) || empty($current_terms)) {
            return;
        }
        
        $terms_to_add = array();
        
        // For each current term, get its ancestors
        foreach ($current_terms as $term_id) {
            $ancestors = $this->get_ancestors_for_term($term_id, $taxonomy, $rule);
            $terms_to_add = array_merge($terms_to_add, $ancestors);
        }
        
        // Remove duplicates and current terms
        $terms_to_add = array_unique($terms_to_add);
        $terms_to_add = array_diff($terms_to_add, $current_terms);
        
        if (!empty($terms_to_add)) {
            // Add ancestor terms
            $all_terms = array_merge($current_terms, $terms_to_add);
            wp_set_object_terms($post_id, $all_terms, $taxonomy);
            
            $this->debug_log(
                sprintf('Added ancestor terms to post %d for taxonomy %s', $post_id, $taxonomy),
                array('added_terms' => $terms_to_add, 'existing_terms' => $current_terms)
            );
        }
    }
    
    /**
     * Process ACF hierarchical terms
     */
    private function process_acf_hierarchical_terms($post_id, $taxonomy, $rule) {
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
                
                $terms_to_add = array();
                
                // For each current term, get its ancestors
                foreach ($current_terms as $term_id) {
                    $ancestors = $this->get_ancestors_for_term($term_id, $taxonomy, $rule);
                    $terms_to_add = array_merge($terms_to_add, $ancestors);
                }
                
                // Remove duplicates and current terms
                $terms_to_add = array_unique($terms_to_add);
                $terms_to_add = array_diff($terms_to_add, $current_terms);
                
                if (!empty($terms_to_add)) {
                    // Update ACF field with ancestor terms included
                    $all_terms = array_merge($current_terms, $terms_to_add);
                    $this->set_acf_taxonomy_value($post_id, $field['name'], $all_terms);
                    
                    // Also update the native taxonomy terms
                    wp_set_object_terms($post_id, $all_terms, $taxonomy);
                    
                    $this->debug_log(
                        sprintf('Added ancestor terms via ACF to post %d for taxonomy %s', $post_id, $taxonomy),
                        array('field' => $field['name'], 'added_terms' => $terms_to_add)
                    );
                }
            }
        }
    }
    
    /**
     * Get ancestors for a term based on rule configuration
     */
    private function get_ancestors_for_term($term_id, $taxonomy, $rule) {
        $inheritance_depth = $rule['inheritance_depth'] ?? 'all';
        
        if ($inheritance_depth === 'immediate') {
            // Get only immediate parent
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term) && $term->parent > 0) {
                return array($term->parent);
            }
            return array();
        } else {
            // Get all ancestors
            $ancestor_ids = get_ancestors($term_id, $taxonomy);
            return $ancestor_ids;
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
        
        // Validate inheritance depth
        $valid_depths = array('immediate', 'all');
        if (!empty($rule_data['inheritance_depth']) && 
            !in_array($rule_data['inheritance_depth'], $valid_depths)) {
            $errors[] = __('Invalid inheritance depth selected.', 'bws-taxonomy-manager');
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
            'inheritance_depth' => sanitize_text_field($rule_data['inheritance_depth'] ?? 'all'),
            'post_types' => array_map('sanitize_text_field', $rule_data['post_types'] ?? array()),
            'enabled' => !empty($rule_data['enabled'])
        );
    }
    
    /**
     * Get applicable post types for this handler
     */
    protected function get_applicable_post_types() {
        $rules = $this->get_enabled_rules();
        $post_types = array();
        
        foreach ($rules as $rule) {
            if (!empty($rule['post_types'])) {
                $post_types = array_merge($post_types, $rule['post_types']);
            } else {
                // If no specific post types, get all post types that use this taxonomy
                $taxonomy = $rule['taxonomy'];
                if (taxonomy_exists($taxonomy)) {
                    $taxonomy_obj = get_taxonomy($taxonomy);
                    $post_types = array_merge($post_types, $taxonomy_obj->object_type);
                }
            }
        }
        
        // If still no post types, get all public post types
        if (empty($post_types)) {
            $post_types = get_post_types(array('public' => true));
        }
        
        return array_unique($post_types);
    }
    
    /**
     * Process existing posts for testing/manual application
     */
    public function process_existing_posts($batch_size = 50, $offset = 0) {
        $result = parent::process_existing_posts($batch_size, $offset);
        
        // Add specific message for hierarchical processing
        if ($result['processed'] > 0) {
            $result['message'] = sprintf(
                __('Processed %d posts for hierarchical term inheritance. %d of %d total posts complete.', 'bws-taxonomy-manager'),
                $result['processed'],
                min($offset + $batch_size, $result['total']),
                $result['total']
            );
        }
        
        return $result;
    }
}
