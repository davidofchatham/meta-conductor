<?php
/**
 * BWS Taxonomy Manager Related Terms Handler
 * Handles linking terms so applying one automatically applies another
 * 
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Related_Handler extends BWS_Handler_Base {
    
    /**
     * Handler type
     */
    protected $handler_type = 'related';
    
    /**
     * Track if we're currently processing to prevent infinite loops
     */
    private $processing = false;
    
    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        add_action('set_object_terms', array($this, 'on_terms_set'), 10, 6);
        add_action('acf/save_post', array($this, 'on_acf_save_post'), 20);
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
            
            $this->process_related_terms($post_id, $rule);
        }
    }
    
    /**
     * Handle terms being set
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
            if (!$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            // Check if this taxonomy change should trigger related terms
            if ($this->should_trigger_related_terms($rule, $taxonomy, $tt_ids, $old_tt_ids)) {
                $this->processing = true;
                $this->apply_related_terms($object_id, $rule, $tt_ids, $old_tt_ids);
                $this->processing = false;
            }
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
        
        $enabled_rules = $this->get_enabled_rules();
        
        foreach ($enabled_rules as $rule) {
            if (!$this->rule_applies_to_post($rule, $post)) {
                continue;
            }
            
            $this->processing = true;
            $this->process_acf_related_terms($post_id, $rule);
            $this->processing = false;
        }
    }
    
    /**
     * Process related terms for a post.
     *
     * Only ADDS the target term when the trigger is present.
     * Removal is handled exclusively by apply_related_terms() which has
     * the old/new term-taxonomy-ID diff needed to know the trigger was
     * actually removed — not just absent.
     */
    private function process_related_terms($post_id, $rule) {
        $trigger_terms = $this->get_trigger_terms($post_id, $rule);

        if (!empty($trigger_terms)) {
            $target_term = get_term($rule['target_term_id']);
            if ($target_term && !is_wp_error($target_term)) {
                $this->apply_terms_to_post($post_id, $target_term->taxonomy, array($target_term->term_id), 'merge');

                $this->debug_log(
                    sprintf('Applied related term %d to post %d', $rule['target_term_id'], $post_id),
                    array('trigger_terms' => $trigger_terms)
                );
            }
        }
    }
    
    /**
     * Get trigger terms for a post based on rule
     */
    private function get_trigger_terms($post_id, $rule) {
        $trigger_terms = array();
        
        if ($rule['trigger_type'] === 'term') {
            // Check if specific term is applied
            $term = get_term($rule['trigger_term_id']);
            if ($term && !is_wp_error($term)) {
                if ($this->post_has_terms($post_id, $term->taxonomy, array($term->term_id))) {
                    $trigger_terms[] = $term->term_id;
                }
            }
        } elseif ($rule['trigger_type'] === 'taxonomy') {
            // Check if any term from taxonomy is applied
            $post_terms = wp_get_object_terms($post_id, $rule['trigger_taxonomy'], array('fields' => 'ids'));
            if (!is_wp_error($post_terms) && !empty($post_terms)) {
                $trigger_terms = $post_terms;
            }
        }
        
        return $trigger_terms;
    }
    
    /**
     * Check if terms change should trigger related terms
     */
    private function should_trigger_related_terms($rule, $taxonomy, $new_tt_ids, $old_tt_ids) {
        if ($rule['trigger_type'] === 'term') {
            $term = get_term($rule['trigger_term_id']);
            if (!$term || is_wp_error($term) || $term->taxonomy !== $taxonomy) {
                return false;
            }
            
            // Check if the specific trigger term was added or removed
            $added = array_diff($new_tt_ids, $old_tt_ids ?? array());
            $removed = array_diff($old_tt_ids ?? array(), $new_tt_ids);
            
            return in_array($term->term_taxonomy_id, $added) || in_array($term->term_taxonomy_id, $removed);
            
        } elseif ($rule['trigger_type'] === 'taxonomy') {
            return $rule['trigger_taxonomy'] === $taxonomy;
        }
        
        return false;
    }
    
    /**
     * Apply related terms based on rule
     */
    private function apply_related_terms($post_id, $rule, $new_tt_ids, $old_tt_ids) {
        $target_term = get_term($rule['target_term_id']);
        if (!$target_term || is_wp_error($target_term)) {
            return;
        }
        
        $should_apply = false;
        $should_remove = false;
        
        if ($rule['trigger_type'] === 'term') {
            $trigger_term = get_term($rule['trigger_term_id']);
            if ($trigger_term && !is_wp_error($trigger_term)) {
                $added = array_diff($new_tt_ids, $old_tt_ids ?? array());
                $removed = array_diff($old_tt_ids ?? array(), $new_tt_ids);
                
                if (in_array($trigger_term->term_taxonomy_id, $added)) {
                    $should_apply = true;
                } elseif (in_array($trigger_term->term_taxonomy_id, $removed)) {
                    $should_remove = true;
                }
            }
        } elseif ($rule['trigger_type'] === 'taxonomy') {
            // If any terms in trigger taxonomy, apply target
            if (!empty($new_tt_ids)) {
                $should_apply = true;
            } elseif (empty($new_tt_ids) && !empty($old_tt_ids)) {
                $should_remove = true;
            }
        }
        
        if ($should_apply) {
            $this->apply_terms_to_post($post_id, $target_term->taxonomy, array($target_term->term_id), 'merge');
        } elseif ($should_remove && !empty($rule['bidirectional'])) {
            $this->remove_terms_from_post($post_id, $target_term->taxonomy, array($target_term->term_id));
        }
    }
    
    /**
     * Process ACF related terms
     */
    private function process_acf_related_terms($post_id, $rule) {
        if (!function_exists('get_field_objects')) {
            return;
        }
        
        $field_objects = get_field_objects($post_id);
        if (!$field_objects) {
            return;
        }
        
        $trigger_taxonomy = $rule['trigger_type'] === 'taxonomy' ? $rule['trigger_taxonomy'] : null;
        if ($rule['trigger_type'] === 'term') {
            $trigger_term = get_term($rule['trigger_term_id']);
            $trigger_taxonomy = $trigger_term ? $trigger_term->taxonomy : null;
        }
        
        if (!$trigger_taxonomy) {
            return;
        }
        
        // Check if any ACF fields were updated for the trigger taxonomy
        foreach ($field_objects as $field) {
            if ($field['type'] === 'taxonomy' && 
                isset($field['taxonomy']) && 
                $field['taxonomy'] === $trigger_taxonomy) {
                
                $this->process_related_terms($post_id, $rule);
                break;
            }
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
        }
        
        // Validate trigger
        if (empty($rule_data['trigger_type'])) {
            $errors[] = __('Trigger type is required.', 'bws-taxonomy-manager');
        } elseif (!in_array($rule_data['trigger_type'], array('term', 'taxonomy'))) {
            $errors[] = __('Invalid trigger type.', 'bws-taxonomy-manager');
        } else {
            if ($rule_data['trigger_type'] === 'term') {
                if (empty($rule_data['trigger_term_id'])) {
                    $errors[] = __('Trigger term is required.', 'bws-taxonomy-manager');
                } elseif (!get_term($rule_data['trigger_term_id'])) {
                    $errors[] = __('Selected trigger term does not exist.', 'bws-taxonomy-manager');
                }
            } elseif ($rule_data['trigger_type'] === 'taxonomy') {
                if (empty($rule_data['trigger_taxonomy'])) {
                    $errors[] = __('Trigger taxonomy is required.', 'bws-taxonomy-manager');
                } elseif (!taxonomy_exists($rule_data['trigger_taxonomy'])) {
                    $errors[] = __('Selected trigger taxonomy does not exist.', 'bws-taxonomy-manager');
                }
            }
        }
        
        // Validate target term
        if (empty($rule_data['target_term_id'])) {
            $errors[] = __('Target term is required.', 'bws-taxonomy-manager');
        } elseif (!get_term($rule_data['target_term_id'])) {
            $errors[] = __('Selected target term does not exist.', 'bws-taxonomy-manager');
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
            'trigger_type' => sanitize_text_field($rule_data['trigger_type'] ?? ''),
            'trigger_term_id' => $rule_data['trigger_type'] === 'term' ? absint($rule_data['trigger_term_id'] ?? 0) : null,
            'trigger_taxonomy' => $rule_data['trigger_type'] === 'taxonomy' ? sanitize_text_field($rule_data['trigger_taxonomy'] ?? '') : null,
            'target_term_id' => absint($rule_data['target_term_id'] ?? 0),
            'bidirectional' => !empty($rule_data['bidirectional']),
            'enabled' => !empty($rule_data['enabled'])
        );
    }
}
