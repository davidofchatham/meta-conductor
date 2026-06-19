<?php
/**
 * BWS Taxonomy Manager Time-Based Handler
 * Handles applying terms based on date ranges
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// TODO(Phase 3): still on the legacy HandlerBase. The unified base
// (UnifiedHandlerBase) has diverged — e.g. should_process_post()
// normalizes Wireframe checkbox format only there. Fixes to one base may not
// reach this one until migration. See ROADMAP Phase 3.
class TimeBasedHandler extends HandlerBase {
    
    /**
     * Handler type
     */
    protected $handler_type = 'time_based';
    
    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        // Hook into post save to check date-based rules
        add_action('save_post', array($this, 'on_post_save'), 20, 3);
        
        // Hook into publish_post to handle newly published posts
        add_action('publish_post', array($this, 'on_post_publish'), 10, 2);
        
        // Daily cleanup hook
        add_action('bws_taxonomy_manager_cleanup', array($this, 'cleanup_expired_rules'));
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
            
            $this->apply_time_based_rule($post_id, $post, $rule);
        }
    }
    
    /**
     * Handle post save events
     */
    public function on_post_save($post_id, $post, $update) {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        $this->process_post($post_id, $post, $update);
    }
    
    /**
     * Handle post publish events
     */
    public function on_post_publish($post_id, $post) {
        $this->process_post($post_id, $post, false);
    }
    
    /**
     * Apply time-based rule to a post
     */
    private function apply_time_based_rule($post_id, $post, $rule) {
        $current_date = current_time('Y-m-d');
        $start_date = $rule['start_date'];
        $end_date = $rule['end_date'];
        
        // Check if current date is within rule date range
        $in_date_range = ($current_date >= $start_date && $current_date <= $end_date);
        
        // Check if post matches filter criteria
        $matches_filter = $this->post_matches_filter($post_id, $rule);
        
        $target_term = get_term($rule['target_term_id']);
        if (!$target_term || is_wp_error($target_term)) {
            return;
        }
        
        $has_target_term = $this->post_has_terms($post_id, $target_term->taxonomy, array($target_term->term_id));
        
        if ($in_date_range && $matches_filter && !$has_target_term) {
            // Apply the term
            $this->apply_terms_to_post($post_id, $target_term->taxonomy, array($target_term->term_id), 'merge');
            
            $this->debug_log(
                sprintf('Applied time-based term %d to post %d', $rule['target_term_id'], $post_id),
                array('rule' => $rule, 'current_date' => $current_date)
            );
            
        } elseif (!$in_date_range && $has_target_term) {
            // Remove the term if outside date range
            $this->remove_terms_from_post($post_id, $target_term->taxonomy, array($target_term->term_id));
            
            $this->debug_log(
                sprintf('Removed expired time-based term %d from post %d', $rule['target_term_id'], $post_id),
                array('rule' => $rule, 'current_date' => $current_date)
            );
        }
    }
    
    /**
     * Check if post matches the filter criteria for a rule
     */
    private function post_matches_filter($post_id, $rule) {
        // If no filter taxonomies specified, match all posts
        if (empty($rule['filter_taxonomies']) && empty($rule['filter_terms'])) {
            return true;
        }
        
        // Check filter terms (if specified)
        if (!empty($rule['filter_terms'])) {
            foreach ($rule['filter_terms'] as $filter_term_id) {
                $filter_term = get_term($filter_term_id);
                if ($filter_term && !is_wp_error($filter_term)) {
                    if ($this->post_has_terms($post_id, $filter_term->taxonomy, array($filter_term->term_id))) {
                        return true;
                    }
                }
            }
            return false;
        }
        
        // Check filter taxonomies (if specified)
        if (!empty($rule['filter_taxonomies'])) {
            foreach ($rule['filter_taxonomies'] as $taxonomy) {
                if ($this->post_has_terms($post_id, $taxonomy)) {
                    return true;
                }
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * Cleanup expired rules and terms
     */
    public function cleanup_expired_rules() {
        $enabled_rules = $this->get_enabled_rules();
        $current_date = current_time('Y-m-d');
        
        foreach ($enabled_rules as $rule) {
            // Skip rules that haven't expired yet
            if ($current_date <= $rule['end_date']) {
                continue;
            }
            
            $this->cleanup_expired_rule($rule, $current_date);
        }
    }
    
    /**
     * Cleanup a specific expired rule
     */
    private function cleanup_expired_rule($rule, $current_date) {
        $target_term = get_term($rule['target_term_id']);
        if (!$target_term || is_wp_error($target_term)) {
            return;
        }
        
        // Find all posts that have this term
        $posts_with_term = get_posts(array(
            'post_type' => $rule['post_type'],
            'post_status' => array('publish', 'draft', 'private'),
            'numberposts' => -1,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => $target_term->taxonomy,
                    'field' => 'term_id',
                    'terms' => $target_term->term_id
                )
            )
        ));
        
        $removed_count = 0;
        
        foreach ($posts_with_term as $post_id) {
            // Only remove if this was likely added by our time-based rule
            // (We could add metadata to track this, but for now we'll remove from all matching posts)
            $this->remove_terms_from_post($post_id, $target_term->taxonomy, array($target_term->term_id));
            $removed_count++;
        }
        
        if ($removed_count > 0) {
            $this->debug_log(
                sprintf('Cleaned up expired time-based rule: removed term %d from %d posts', $rule['target_term_id'], $removed_count),
                array('rule' => $rule, 'cleanup_date' => $current_date)
            );
        }
    }
    
    /**
     * Get active rules for current date
     */
    public function get_active_rules($date = null) {
        if ($date === null) {
            $date = current_time('Y-m-d');
        }
        
        $enabled_rules = $this->get_enabled_rules();
        $active_rules = array();
        
        foreach ($enabled_rules as $rule) {
            if ($date >= $rule['start_date'] && $date <= $rule['end_date']) {
                $active_rules[] = $rule;
            }
        }
        
        return $active_rules;
    }
    
    /**
     * Get upcoming rules (starting within next 7 days)
     */
    public function get_upcoming_rules($days_ahead = 7) {
        $current_date = current_time('Y-m-d');
        $future_date = date('Y-m-d', strtotime($current_date . " +{$days_ahead} days"));
        
        $enabled_rules = $this->get_enabled_rules();
        $upcoming_rules = array();
        
        foreach ($enabled_rules as $rule) {
            if ($rule['start_date'] > $current_date && $rule['start_date'] <= $future_date) {
                $upcoming_rules[] = $rule;
            }
        }
        
        return $upcoming_rules;
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
        
        // Validate target term
        if (empty($rule_data['target_term_id'])) {
            $errors[] = __('Target term is required.', 'bws-taxonomy-manager');
        } elseif (!get_term($rule_data['target_term_id'])) {
            $errors[] = __('Selected target term does not exist.', 'bws-taxonomy-manager');
        }
        
        // Validate dates
        if (empty($rule_data['start_date'])) {
            $errors[] = __('Start date is required.', 'bws-taxonomy-manager');
        } elseif (!$this->is_valid_date($rule_data['start_date'])) {
            $errors[] = __('Start date must be in YYYY-MM-DD format.', 'bws-taxonomy-manager');
        }
        
        if (empty($rule_data['end_date'])) {
            $errors[] = __('End date is required.', 'bws-taxonomy-manager');
        } elseif (!$this->is_valid_date($rule_data['end_date'])) {
            $errors[] = __('End date must be in YYYY-MM-DD format.', 'bws-taxonomy-manager');
        }
        
        // Validate date range
        if (!empty($rule_data['start_date']) && !empty($rule_data['end_date']) &&
            $this->is_valid_date($rule_data['start_date']) && $this->is_valid_date($rule_data['end_date'])) {
            if ($rule_data['start_date'] > $rule_data['end_date']) {
                $errors[] = __('Start date must be before end date.', 'bws-taxonomy-manager');
            }
        }
        
        // Validate filter taxonomies
        if (!empty($rule_data['filter_taxonomies'])) {
            foreach ($rule_data['filter_taxonomies'] as $taxonomy) {
                if (!taxonomy_exists($taxonomy)) {
                    $errors[] = sprintf(__('Filter taxonomy "%s" does not exist.', 'bws-taxonomy-manager'), $taxonomy);
                }
            }
        }
        
        // Validate filter terms
        if (!empty($rule_data['filter_terms'])) {
            foreach ($rule_data['filter_terms'] as $term_id) {
                if (!get_term($term_id)) {
                    $errors[] = sprintf(__('Filter term ID "%s" does not exist.', 'bws-taxonomy-manager'), $term_id);
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
            'post_type' => sanitize_text_field($rule_data['post_type'] ?? ''),
            'target_term_id' => absint($rule_data['target_term_id'] ?? 0),
            'start_date' => sanitize_text_field($rule_data['start_date'] ?? ''),
            'end_date' => sanitize_text_field($rule_data['end_date'] ?? ''),
            'filter_taxonomies' => array_map('sanitize_text_field', $rule_data['filter_taxonomies'] ?? array()),
            'filter_terms' => array_map('absint', $rule_data['filter_terms'] ?? array()),
            'enabled' => !empty($rule_data['enabled'])
        );
    }
    
    /**
     * Validate date format
     */
    private function is_valid_date($date) {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Process existing posts for testing/manual application
     */
    public function process_existing_posts($batch_size = 50, $offset = 0) {
        $result = parent::process_existing_posts($batch_size, $offset);
        
        // Add specific message for time-based processing
        if ($result['processed'] > 0) {
            $current_date = current_time('Y-m-d');
            $active_rules_count = count($this->get_active_rules($current_date));
            
            $result['message'] = sprintf(
                __('Processed %d posts for time-based rules (date: %s, %d active rules). %d of %d total posts complete.', 'bws-taxonomy-manager'),
                $result['processed'],
                $current_date,
                $active_rules_count,
                min($offset + $batch_size, $result['total']),
                $result['total']
            );
        }
        
        return $result;
    }
    
    /**
     * Get rules summary for admin display
     */
    public function get_rules_summary() {
        $enabled_rules = $this->get_enabled_rules();
        $current_date = current_time('Y-m-d');
        
        $summary = array(
            'total_rules' => count($enabled_rules),
            'active_rules' => count($this->get_active_rules($current_date)),
            'upcoming_rules' => count($this->get_upcoming_rules(7)),
            'expired_rules' => 0
        );
        
        foreach ($enabled_rules as $rule) {
            if ($rule['end_date'] < $current_date) {
                $summary['expired_rules']++;
            }
        }
        
        return $summary;
    }
}
