<?php
/**
 * BWS Taxonomy Manager Propagation Handler
 * Handles applying parent post terms to child posts
 * 
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Propagation_Handler extends BWS_Handler_Base {
    
    /**
     * Handler type
     */
    protected $handler_type = 'propagation';
    
    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        add_action('save_post', array($this, 'on_parent_post_save'), 15, 3);
        add_action('set_object_terms', array($this, 'on_parent_terms_set'), 10, 6);
        
        // Hook into ACF field updates
        add_action('acf/save_post', array($this, 'on_acf_save_post'), 25);
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
            
            // If this post has children, propagate terms to them
            $this->propagate_terms_to_children($post_id, $rule);
            
            // If this post has a parent, get terms from parent
            if ($post->post_parent > 0) {
                $this->inherit_terms_from_parent($post_id, $post, $rule);
            }
        }
    }
    
    /**
     * Handle when a parent post is saved
     */
    public function on_parent_post_save($post_id, $post, $update) {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if (!$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            // Propagate terms to children
            $this->propagate_terms_to_children($post_id, $rule);
        }
    }
    
    /**
     * Handle when terms are set on a parent post
     */
    public function on_parent_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if ($rule['taxonomy'] !== $taxonomy) {
                continue;
            }
            
            $post = get_post($object_id);
            if (!$post || !$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            // Propagate terms to children
            $this->propagate_terms_to_children($object_id, $rule);
        }
    }
    
    /**
     * Handle ACF field saves for propagation
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
            
            // Check for ACF taxonomy fields and propagate if necessary
            $this->process_acf_propagation($post_id, $rule);
        }
    }
    
    /**
     * Process ACF field propagation
     */
    private function process_acf_propagation($post_id, $rule) {
        if (!function_exists('get_field_objects')) {
            return;
        }
        
        $field_objects = get_field_objects($post_id);
        if (!$field_objects) {
            return;
        }
        
        $taxonomy = $rule['taxonomy'];
        $has_taxonomy_field = false;
        
        // Check if any ACF fields were updated for this taxonomy
        foreach ($field_objects as $field) {
            if ($field['type'] === 'taxonomy' && 
                isset($field['taxonomy']) && 
                $field['taxonomy'] === $taxonomy) {
                $has_taxonomy_field = true;
                break;
            }
        }
        
        if ($has_taxonomy_field) {
            // Propagate to children
            $this->propagate_terms_to_children($post_id, $rule);
        }
    }
    
    /**
     * Process a new child post
     */
    public function process_new_child_post($post_id, $post) {
        if ($post->post_parent <= 0) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if (!$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            $this->inherit_terms_from_parent($post_id, $post, $rule);
        }
    }
    
    /**
     * Propagate terms from parent to all children
     */
    private function propagate_terms_to_children($parent_id, $rule) {
        $children = $this->get_all_child_posts($parent_id, $rule['post_type']);
        
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
            $this->apply_terms_to_post($child_id, $taxonomy, $parent_terms, $conflict_handling);
            
            // Also update ACF fields if they exist
            $this->update_acf_fields_for_post($child_id, $taxonomy, $parent_terms, $conflict_handling);
            
            $this->debug_log(
                sprintf('Propagated terms from parent %d to child %d', $parent_id, $child_id),
                array('taxonomy' => $taxonomy, 'terms' => $parent_terms)
            );
        }
    }
    
    /**
     * Inherit terms from parent to child
     */
    private function inherit_terms_from_parent($child_id, $child_post, $rule) {
        $parent_id = $child_post->post_parent;
        $parent_post = get_post($parent_id);
        
        if (!$parent_post || !$this->rule_applies_to_post($rule, $parent_post)) {
            return;
        }
        
        $taxonomy = $rule['taxonomy'];
        $conflict_handling = $rule['conflict_handling'] ?? 'merge';
        
        // Get parent terms
        $parent_terms = $this->get_post_terms($parent_id, $taxonomy);
        
        if (!empty($parent_terms)) {
            $this->apply_terms_to_post($child_id, $taxonomy, $parent_terms, $conflict_handling);
            
            // Also update ACF fields if they exist
            $this->update_acf_fields_for_post($child_id, $taxonomy, $parent_terms, $conflict_handling);
            
            $this->debug_log(
                sprintf('Child %d inherited terms from parent %d', $child_id, $parent_id),
                array('taxonomy' => $taxonomy, 'terms' => $parent_terms)
            );
        }
    }
    
    /**
     * Get all child posts recursively
     */
    private function get_all_child_posts($parent_id, $post_type) {
        $children = array();
        
        $child_posts = get_posts(array(
            'post_type' => $post_type,
            'post_parent' => $parent_id,
            'post_status' => array('publish', 'draft', 'private'),
            'numberposts' => -1,
            'fields' => 'ids'
        ));
        
        foreach ($child_posts as $child_id) {
            $children[] = $child_id;
            
            // Recursively get children of children
            $grandchildren = $this->get_all_child_posts($child_id, $post_type);
            $children = array_merge($children, $grandchildren);
        }
        
        return $children;
    }
    
    /**
     * Get post terms from both native taxonomy and ACF fields
     */
    private function get_post_terms($post_id, $taxonomy) {
        $terms = array();
        
        // Get native taxonomy terms
        $native_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (!is_wp_error($native_terms)) {
            $terms = array_merge($terms, $native_terms);
        }
        
        // Get ACF taxonomy field terms
        if (function_exists('get_field_objects')) {
            $field_objects = get_field_objects($post_id);
            if ($field_objects) {
                foreach ($field_objects as $field) {
                    if ($field['type'] === 'taxonomy' && 
                        isset($field['taxonomy']) && 
                        $field['taxonomy'] === $taxonomy) {
                        
                        $acf_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);
                        $terms = array_merge($terms, $acf_terms);
                    }
                }
            }
        }
        
        return array_unique(array_filter($terms));
    }
    
    /**
     * Update ACF fields for a post with new terms
     */
    private function update_acf_fields_for_post($post_id, $taxonomy, $terms, $conflict_handling) {
        if (!function_exists('get_field_objects')) {
            return;
        }
        
        $field_objects = get_field_objects($post_id);
        if (!$field_objects) {
            return;
        }
        
        foreach ($field_objects as $field) {
            if ($field['type'] === 'taxonomy' && 
                isset($field['taxonomy']) && 
                $field['taxonomy'] === $taxonomy) {
                
                $current_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);
                $new_terms = $this->merge_terms_based_on_conflict_handling($current_terms, $terms, $conflict_handling);
                
                if ($new_terms !== $current_terms) {
                    $this->set_acf_taxonomy_value($post_id, $field['name'], $new_terms);
                }
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
        
        // Validate post type
        if (empty($rule_data['post_type'])) {
            $errors[] = __('Post type is required.', 'bws-taxonomy-manager');
        } elseif (!post_type_exists($rule_data['post_type'])) {
            $errors[] = __('Selected post type does not exist.', 'bws-taxonomy-manager');
        } else {
            $post_type_obj = get_post_type_object($rule_data['post_type']);
            if (!$post_type_obj->hierarchical) {
                $errors[] = __('Selected post type must be hierarchical for propagation rules.', 'bws-taxonomy-manager');
            }
        }
        
        // Validate taxonomy
        if (empty($rule_data['taxonomy'])) {
            $errors[] = __('Taxonomy is required.', 'bws-taxonomy-manager');
        } elseif (!taxonomy_exists($rule_data['taxonomy'])) {
            $errors[] = __('Selected taxonomy does not exist.', 'bws-taxonomy-manager');
        }
        
        // Validate conflict handling
        $valid_handling = array('replace', 'merge', 'skip');
        if (!empty($rule_data['conflict_handling']) && 
            !in_array($rule_data['conflict_handling'], $valid_handling)) {
            $errors[] = __('Invalid conflict handling method selected.', 'bws-taxonomy-manager');
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
            'post_type' => sanitize_text_field($rule_data['post_type'] ?? ''),
            'taxonomy' => sanitize_text_field($rule_data['taxonomy'] ?? ''),
            'conflict_handling' => sanitize_text_field($rule_data['conflict_handling'] ?? 'merge'),
            'enabled' => !empty($rule_data['enabled'])
        );
    }
    
    /**
     * Get applicable post types - only hierarchical post types
     */
    protected function get_applicable_post_types() {
        $rules = $this->get_enabled_rules();
        $post_types = array();
        
        foreach ($rules as $rule) {
            if (!empty($rule['post_type'])) {
                $post_types[] = $rule['post_type'];
            }
        }
        
        // If no post types specified, get all hierarchical post types
        if (empty($post_types)) {
            $post_types = get_post_types(array('public' => true, 'hierarchical' => true));
        }
        
        return array_unique($post_types);
    }
    
    /**
     * Process existing posts for testing/manual application
     */
    public function process_existing_posts($batch_size = 50, $offset = 0) {
        $result = parent::process_existing_posts($batch_size, $offset);
        
        // Add specific message for propagation processing
        if ($result['processed'] > 0) {
            $result['message'] = sprintf(
                __('Processed %d posts for parent-child term propagation. %d of %d total posts complete.', 'bws-taxonomy-manager'),
                $result['processed'],
                min($offset + $batch_size, $result['total']),
                $result['total']
            );
        }
        
        return $result;
    }
}
