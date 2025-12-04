<?php
/**
 * BWS Taxonomy Manager Base Handler Abstract Class
 * 
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

abstract class BWS_Handler_Base {
    
    /**
     * Settings instance
     */
    protected $settings;
    
    /**
     * Handler type
     */
    protected $handler_type;
    
    /**
     * Constructor
     */
    public function __construct($settings) {
        $this->settings = $settings;
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks - can be overridden by child classes
     */
    protected function init_hooks() {
        // Default implementation - child classes can override
    }
    
    /**
     * Process a post - must be implemented by child classes
     */
    abstract public function process_post($post_id, $post, $update);
    
    /**
     * Process existing posts in batches
     */
    public function process_existing_posts($batch_size = 50, $offset = 0) {
        $rules = $this->get_rules();
        
        if (empty($rules)) {
            return array(
                'processed' => 0,
                'total' => 0,
                'complete' => true,
                'message' => __('No rules configured for this handler.', 'bws-taxonomy-manager')
            );
        }
        
        $post_types = $this->get_applicable_post_types();
        
        if (empty($post_types)) {
            return array(
                'processed' => 0,
                'total' => 0,
                'complete' => true,
                'message' => __('No applicable post types found.', 'bws-taxonomy-manager')
            );
        }
        
        // Get total count
        $total_query = new WP_Query(array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => false
        ));
        
        $total = $total_query->found_posts;
        
        // Get batch of posts
        $query = new WP_Query(array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'fields' => 'ids'
        ));
        
        $processed = 0;
        
        foreach ($query->posts as $post_id) {
            $post = get_post($post_id);
            if ($post) {
                $this->process_post($post_id, $post, true);
                $processed++;
            }
        }
        
        $complete = ($offset + $batch_size) >= $total;
        
        return array(
            'processed' => $processed,
            'total' => $total,
            'offset' => $offset + $batch_size,
            'complete' => $complete,
            'message' => sprintf(
                __('Processed %d of %d posts.', 'bws-taxonomy-manager'),
                min($offset + $batch_size, $total),
                $total
            )
        );
    }
    
    /**
     * Validate rule data - must be implemented by child classes
     */
    abstract public function validate_rule($rule_data);
    
    /**
     * Get rules for this handler
     */
    protected function get_rules() {
        $settings = $this->settings->get_settings();
        $rules_key = $this->handler_type . '_rules';
        return $settings[$rules_key] ?? array();
    }
    
    /**
     * Get enabled rules for this handler
     */
    protected function get_enabled_rules() {
        $rules = $this->get_rules();
        return array_filter($rules, function($rule) {
            return !empty($rule['enabled']);
        });
    }
    
    /**
     * Get applicable post types for this handler
     */
    protected function get_applicable_post_types() {
        $rules = $this->get_enabled_rules();
        $post_types = array();
        
        foreach ($rules as $rule) {
            if (!empty($rule['post_type'])) {
                $post_types[] = $rule['post_type'];
            } elseif (!empty($rule['post_types'])) {
                $post_types = array_merge($post_types, $rule['post_types']);
            }
        }
        
        // If no specific post types, get all public post types
        if (empty($post_types)) {
            $post_types = get_post_types(array('public' => true));
        }
        
        return array_unique($post_types);
    }
    
    /**
     * Get conflict handling for a taxonomy
     */
    protected function get_conflict_handling($taxonomy, $default = 'merge') {
        $settings = $this->settings->get_settings();
        $conflict_handling = $settings['conflict_handling'] ?? array();
        return $conflict_handling[$taxonomy] ?? $default;
    }
    
    /**
     * Apply terms to post based on conflict handling
     */
    protected function apply_terms_to_post($post_id, $taxonomy, $terms, $conflict_handling = 'merge') {
        if (empty($terms)) {
            return false;
        }
        
        // Ensure terms are term IDs
        $term_ids = array();
        foreach ($terms as $term) {
            if (is_object($term)) {
                $term_ids[] = $term->term_id;
            } elseif (is_array($term)) {
                $term_ids[] = $term['term_id'];
            } else {
                $term_ids[] = absint($term);
            }
        }
        
        $term_ids = array_unique(array_filter($term_ids));
        
        if (empty($term_ids)) {
            return false;
        }
        
        switch ($conflict_handling) {
            case 'replace':
                return wp_set_object_terms($post_id, $term_ids, $taxonomy);
                
            case 'merge':
                $existing_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
                if (is_wp_error($existing_terms)) {
                    $existing_terms = array();
                }
                $merged_terms = array_unique(array_merge($existing_terms, $term_ids));
                return wp_set_object_terms($post_id, $merged_terms, $taxonomy);
                
            case 'skip':
                $existing_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
                if (is_wp_error($existing_terms)) {
                    $existing_terms = array();
                }
                
                // Only apply if no existing terms
                if (empty($existing_terms)) {
                    return wp_set_object_terms($post_id, $term_ids, $taxonomy);
                }
                return false;
                
            default:
                return false;
        }
    }
    
    /**
     * Remove terms from post
     */
    protected function remove_terms_from_post($post_id, $taxonomy, $terms) {
        if (empty($terms)) {
            return false;
        }
        
        $existing_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (is_wp_error($existing_terms)) {
            return false;
        }
        
        // Ensure terms are term IDs
        $term_ids_to_remove = array();
        foreach ($terms as $term) {
            if (is_object($term)) {
                $term_ids_to_remove[] = $term->term_id;
            } elseif (is_array($term)) {
                $term_ids_to_remove[] = $term['term_id'];
            } else {
                $term_ids_to_remove[] = absint($term);
            }
        }
        
        $remaining_terms = array_diff($existing_terms, $term_ids_to_remove);
        
        return wp_set_object_terms($post_id, $remaining_terms, $taxonomy);
    }
    
    /**
     * Get term ancestors
     */
    protected function get_term_ancestors($term_id, $taxonomy, $include_self = false) {
        $ancestors = array();
        
        if ($include_self) {
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $ancestors[] = $term;
            }
        }
        
        $ancestor_ids = get_ancestors($term_id, $taxonomy);
        
        foreach ($ancestor_ids as $ancestor_id) {
            $ancestor = get_term($ancestor_id, $taxonomy);
            if ($ancestor && !is_wp_error($ancestor)) {
                $ancestors[] = $ancestor;
            }
        }
        
        return $ancestors;
    }
    
    /**
     * Get term descendants
     */
    protected function get_term_descendants($term_id, $taxonomy) {
        $descendants = array();
        
        $children = get_term_children($term_id, $taxonomy);
        
        if (is_wp_error($children)) {
            return $descendants;
        }
        
        foreach ($children as $child_id) {
            $child = get_term($child_id, $taxonomy);
            if ($child && !is_wp_error($child)) {
                $descendants[] = $child;
                // Recursively get descendants of children
                $descendants = array_merge($descendants, $this->get_term_descendants($child_id, $taxonomy));
            }
        }
        
        return $descendants;
    }
    
    /**
     * Check if rule applies to post
     */
    protected function rule_applies_to_post($rule, $post) {
        // Check post type
        if (!empty($rule['post_type']) && $rule['post_type'] !== $post->post_type) {
            return false;
        }
        
        if (!empty($rule['post_types']) && !in_array($post->post_type, $rule['post_types'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Log debug message if WP_DEBUG is enabled
     */
    protected function debug_log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[BWS Taxonomy Manager] ' . $message;
            if ($data !== null) {
                $log_message .= ' - Data: ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }
    
    /**
     * Check if post has specific terms in taxonomy
     */
    protected function post_has_terms($post_id, $taxonomy, $term_ids = null) {
        $post_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        
        if (is_wp_error($post_terms)) {
            return false;
        }
        
        if ($term_ids === null) {
            return !empty($post_terms);
        }
        
        if (!is_array($term_ids)) {
            $term_ids = array($term_ids);
        }
        
        return !empty(array_intersect($post_terms, $term_ids));
    }
    
    /**
     * Get ACF taxonomy field value
     */
    protected function get_acf_taxonomy_value($post_id, $field_name, $taxonomy) {
        if (!function_exists('get_field')) {
            return array();
        }
        
        $value = get_field($field_name, $post_id);
        
        if (empty($value)) {
            return array();
        }
        
        // Handle different ACF taxonomy field return formats
        if (is_array($value)) {
            $term_ids = array();
            foreach ($value as $item) {
                if (is_object($item) && isset($item->term_id)) {
                    $term_ids[] = $item->term_id;
                } elseif (is_numeric($item)) {
                    $term_ids[] = absint($item);
                }
            }
            return $term_ids;
        } elseif (is_object($value) && isset($value->term_id)) {
            return array($value->term_id);
        } elseif (is_numeric($value)) {
            return array(absint($value));
        }
        
        return array();
    }
    
    /**
     * Set ACF taxonomy field value
     */
    protected function set_acf_taxonomy_value($post_id, $field_name, $term_ids) {
        if (!function_exists('update_field')) {
            return false;
        }
        
        if (!is_array($term_ids)) {
            $term_ids = array($term_ids);
        }
        
        return update_field($field_name, $term_ids, $post_id);
    }
}
