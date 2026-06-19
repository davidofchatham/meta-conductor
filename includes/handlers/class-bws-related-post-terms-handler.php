<?php
/**
 * BWS Taxonomy Manager Related Post Terms Handler
 * Syncs taxonomy terms from related posts via ACF relationship/post object fields
 * 
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// TODO(Phase 3): still on the legacy BWS_Handler_Base. The unified base
// (BWS_Unified_Handler_Base) has diverged — e.g. should_process_post()
// normalizes Wireframe checkbox format only there. Fixes to one base may not
// reach this one until migration. See ROADMAP Phase 3.
class BWS_Related_Post_Terms_Handler extends BWS_Handler_Base {
    
    /**
     * Handler type
     */
    protected $handler_type = 'related_post_terms';
    
    /**
     * Track processing to prevent infinite loops
     */
    private $processing = false;
    
    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        // Hook into ACF field updates
        add_action('acf/save_post', array($this, 'on_acf_save_post'), 30);
        
        // Hook into post updates for relationship fields
        add_action('save_post', array($this, 'on_post_save'), 25, 3);
        
        // Hook into when posts are updated that might be related to others
        add_action('set_object_terms', array($this, 'on_related_post_terms_updated'), 15, 6);
    }
    
    /**
     * Process a post
     */
    public function process_post($post_id, $post, $update) {
        if ($this->processing) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if (!$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            $this->process_related_post_terms($post_id, $post, $rule);
        }
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
        
        $this->processing = true;
        $this->process_post($post_id, $post, true);
        $this->processing = false;
    }
    
    /**
     * Handle regular post saves
     */
    public function on_post_save($post_id, $post, $update) {
        if ($this->processing || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        $this->processing = true;
        $this->process_post($post_id, $post, $update);
        $this->processing = false;
    }
    
    /**
     * Handle when terms are updated on related posts
     */
    public function on_related_post_terms_updated($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if ($this->processing) {
            return;
        }
        
        // Find posts that have this post as a related post
        $this->processing = true;
        $this->update_posts_related_to($object_id, $taxonomy);
        $this->processing = false;
    }
    
    /**
     * Process related post terms for a specific post and rule
     */
    private function process_related_post_terms($post_id, $post, $rule) {
        $acf_field_name = $rule['acf_field_name'];
        $source_taxonomy = $rule['source_taxonomy'];
        $target_taxonomy = $rule['target_taxonomy'];
        $conflict_handling = $rule['conflict_handling'] ?? 'merge';
        
        // Get related posts from ACF field
        $related_posts = $this->get_acf_related_posts($post_id, $acf_field_name);
        
        if (empty($related_posts)) {
            // If no related posts and bidirectional, clear target taxonomy terms
            if (!empty($rule['bidirectional'])) {
                wp_set_object_terms($post_id, array(), $target_taxonomy);
            }
            return;
        }
        
        // Collect terms from all related posts
        $terms_to_apply = array();
        
        foreach ($related_posts as $related_post_id) {
            $related_terms = wp_get_object_terms($related_post_id, $source_taxonomy, array('fields' => 'ids'));
            
            if (!is_wp_error($related_terms) && !empty($related_terms)) {
                $terms_to_apply = array_merge($terms_to_apply, $related_terms);
            }
        }
        
        $terms_to_apply = array_unique($terms_to_apply);
        
        if (!empty($terms_to_apply)) {
            // Apply terms to target taxonomy
            $this->apply_terms_to_post($post_id, $target_taxonomy, $terms_to_apply, $conflict_handling);
            
            $this->debug_log(
                sprintf('Applied related post terms to post %d', $post_id),
                array(
                    'rule' => $rule,
                    'related_posts' => $related_posts,
                    'terms_applied' => $terms_to_apply
                )
            );
        } elseif (!empty($rule['bidirectional'])) {
            // No terms found and bidirectional - clear target taxonomy
            wp_set_object_terms($post_id, array(), $target_taxonomy);
        }
    }
    
    /**
     * Get related posts from ACF field
     */
    private function get_acf_related_posts($post_id, $field_name) {
        if (!function_exists('get_field')) {
            return array();
        }
        
        $field_value = get_field($field_name, $post_id);
        
        if (empty($field_value)) {
            return array();
        }
        
        $related_post_ids = array();
        
        // Handle different ACF field return formats
        if (is_array($field_value)) {
            foreach ($field_value as $item) {
                if (is_object($item) && isset($item->ID)) {
                    $related_post_ids[] = $item->ID;
                } elseif (is_numeric($item)) {
                    $related_post_ids[] = absint($item);
                }
            }
        } elseif (is_object($field_value) && isset($field_value->ID)) {
            $related_post_ids[] = $field_value->ID;
        } elseif (is_numeric($field_value)) {
            $related_post_ids[] = absint($field_value);
        }
        
        return array_unique(array_filter($related_post_ids));
    }
    
    /**
     * Update posts that are related to the given post
     */
    private function update_posts_related_to($related_post_id, $updated_taxonomy) {
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            // Skip if this taxonomy change doesn't affect this rule
            if ($rule['source_taxonomy'] !== $updated_taxonomy) {
                continue;
            }
            
            // Find posts that have this post as a related post
            $posts_to_update = $this->find_posts_with_related_post($related_post_id, $rule);
            
            foreach ($posts_to_update as $post_id) {
                $post = get_post($post_id);
                if ($post && $this->rule_applies_to_post($rule, $post)) {
                    $this->process_related_post_terms($post_id, $post, $rule);
                }
            }
        }
    }
    
    /**
     * Find posts that have the given post as a related post
     */
    private function find_posts_with_related_post($related_post_id, $rule) {
        if (!function_exists('get_field_objects')) {
            return array();
        }
        
        $acf_field_name = $rule['acf_field_name'];
        $post_type = $rule['post_type'];
        
        // Query posts that might have this related post
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => array('publish', 'draft', 'private'),
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => $acf_field_name,
                    'value' => '"' . $related_post_id . '"',
                    'compare' => 'LIKE'
                )
            )
        ));
        
        $matching_posts = array();
        
        // Verify the relationship exists (meta_query with LIKE can have false positives)
        foreach ($posts as $post_id) {
            $related_posts = $this->get_acf_related_posts($post_id, $acf_field_name);
            if (in_array($related_post_id, $related_posts)) {
                $matching_posts[] = $post_id;
            }
        }
        
        return $matching_posts;
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
        }
        
        // Validate ACF field name
        if (empty($rule_data['acf_field_name'])) {
            $errors[] = __('ACF field name is required.', 'bws-taxonomy-manager');
        }
        
        // Validate source taxonomy
        if (empty($rule_data['source_taxonomy'])) {
            $errors[] = __('Source taxonomy is required.', 'bws-taxonomy-manager');
        } elseif (!taxonomy_exists($rule_data['source_taxonomy'])) {
            $errors[] = __('Selected source taxonomy does not exist.', 'bws-taxonomy-manager');
        }
        
        // Validate target taxonomy
        if (empty($rule_data['target_taxonomy'])) {
            $errors[] = __('Target taxonomy is required.', 'bws-taxonomy-manager');
        } elseif (!taxonomy_exists($rule_data['target_taxonomy'])) {
            $errors[] = __('Selected target taxonomy does not exist.', 'bws-taxonomy-manager');
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
            'acf_field_name' => sanitize_text_field($rule_data['acf_field_name'] ?? ''),
            'source_taxonomy' => sanitize_text_field($rule_data['source_taxonomy'] ?? ''),
            'target_taxonomy' => sanitize_text_field($rule_data['target_taxonomy'] ?? ''),
            'conflict_handling' => sanitize_text_field($rule_data['conflict_handling'] ?? 'merge'),
            'bidirectional' => !empty($rule_data['bidirectional']),
            'enabled' => !empty($rule_data['enabled'])
        );
    }
    
    /**
     * Process existing posts for testing/manual application
     */
    public function process_existing_posts($batch_size = 50, $offset = 0) {
        $result = parent::process_existing_posts($batch_size, $offset);
        
        // Add specific message for related post terms processing
        if ($result['processed'] > 0) {
            $result['message'] = sprintf(
                __('Processed %d posts for related post term syncing. %d of %d total posts complete.', 'bws-taxonomy-manager'),
                $result['processed'],
                min($offset + $batch_size, $result['total']),
                $result['total']
            );
        }
        
        return $result;
    }
    
    /**
     * Get summary of rules for admin display
     */
    public function get_rules_summary() {
        $enabled_rules = $this->get_enabled_rules();
        
        $summary = array(
            'total_rules' => count($enabled_rules),
            'post_types' => array(),
            'taxonomies' => array()
        );
        
        foreach ($enabled_rules as $rule) {
            $summary['post_types'][] = $rule['post_type'];
            $summary['taxonomies'][] = $rule['source_taxonomy'];
            $summary['taxonomies'][] = $rule['target_taxonomy'];
        }
        
        $summary['post_types'] = array_unique($summary['post_types']);
        $summary['taxonomies'] = array_unique($summary['taxonomies']);
        
        return $summary;
    }
}
