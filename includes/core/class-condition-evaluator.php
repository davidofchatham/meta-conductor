<?php
/**
 * Condition Evaluator
 *
 * Evaluates rule conditions against entities
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ConditionEvaluator {

    /**
     * Evaluate a condition against an entity
     *
     * @param Entity $entity Entity to evaluate
     * @param array $condition Condition configuration
     * @return bool Whether condition passes
     */
    public function evaluate($entity, $condition) {
        if (empty($condition)) {
            return true; // No condition = always true
        }

        $type = $condition['type'] ?? '';

        switch ($type) {
            case 'always':
                return true;

            case 'never':
                return false;

            case 'has_term':
                return $this->evaluate_has_term($entity, $condition);

            case 'has_child_term':
                return $this->evaluate_has_child_term($entity, $condition);

            case 'has_parent_term':
                return $this->evaluate_has_parent_term($entity, $condition);

            case 'meta_value':
            case 'has_meta':
                return $this->evaluate_meta_value($entity, $condition);

            case 'meta_changed':
                return $this->evaluate_meta_changed($entity, $condition);

            case 'date_comparison':
                return $this->evaluate_date_comparison($entity, $condition);

            case 'date_range_active':
                return $this->evaluate_date_range_active($entity, $condition);

            case 'has_parent':
                return $this->evaluate_has_parent($entity, $condition);

            case 'has_children':
                return $this->evaluate_has_children($entity, $condition);

            case 'is_parent':
                return $this->evaluate_is_parent($entity, $condition);

            case 'relationship':
                return $this->evaluate_relationship($entity, $condition);

            case 'multiple':
                return $this->evaluate_multiple($entity, $condition);

            default:
                return apply_filters('bws_meta_conductor_evaluate_custom_condition', false, $entity, $condition);
        }
    }

    /**
     * Check if entity has specific term(s)
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Has term(s)
     */
    protected function evaluate_has_term($entity, $condition) {
        if ($entity->get_type() !== 'post') {
            return false;
        }

        $taxonomy = $condition['taxonomy'];
        $required_terms = $condition['terms'] ?? [];
        $match_type = $condition['match'] ?? 'any'; // 'any' or 'all'

        $entity_terms = $entity->get_terms($taxonomy);

        if (is_wp_error($entity_terms)) {
            return false;
        }

        $entity_term_ids = wp_list_pluck($entity_terms, 'term_id');

        // Check for "any term"
        if ($required_terms === 'any' || empty($required_terms)) {
            return !empty($entity_term_ids);
        }

        // Convert term slugs/names to IDs if needed
        $required_term_ids = $this->normalize_term_ids($required_terms, $taxonomy);

        if ($match_type === 'all') {
            // Must have ALL required terms
            return count(array_intersect($required_term_ids, $entity_term_ids)) === count($required_term_ids);
        } else {
            // Must have AT LEAST ONE required term
            return count(array_intersect($required_term_ids, $entity_term_ids)) > 0;
        }
    }

    /**
     * Check if entity has child term(s) selected
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Has child terms
     */
    protected function evaluate_has_child_term($entity, $condition) {
        if ($entity->get_type() !== 'post') {
            return false;
        }

        $taxonomy = $condition['taxonomy'];
        $entity_terms = $entity->get_terms($taxonomy);

        if (is_wp_error($entity_terms)) {
            return false;
        }

        foreach ($entity_terms as $term) {
            if ($term->parent != 0) { // Has a parent = is a child
                return true;
            }
        }

        return false;
    }

    /**
     * Check if entity has parent term(s) selected
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Has parent terms
     */
    protected function evaluate_has_parent_term($entity, $condition) {
        if ($entity->get_type() !== 'post') {
            return false;
        }

        $taxonomy = $condition['taxonomy'];
        $entity_terms = $entity->get_terms($taxonomy);

        if (is_wp_error($entity_terms)) {
            return false;
        }

        foreach ($entity_terms as $term) {
            // Check if this term has children
            $children = get_term_children($term->term_id, $taxonomy);
            if (!empty($children) && !is_wp_error($children)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check meta field value
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Meta condition passes
     */
    protected function evaluate_meta_value($entity, $condition) {
        $meta_key = $condition['meta_key'];
        $expected_value = $condition['value'] ?? null;
        $comparison = $condition['comparison'] ?? 'equals';

        $actual_value = $entity->get_meta($meta_key);

        switch ($comparison) {
            case 'equals':
            case '=':
            case '==':
                return $actual_value == $expected_value;

            case 'not_equals':
            case '!=':
                return $actual_value != $expected_value;

            case 'greater_than':
            case '>':
                return $actual_value > $expected_value;

            case 'less_than':
            case '<':
                return $actual_value < $expected_value;

            case 'greater_or_equal':
            case '>=':
                return $actual_value >= $expected_value;

            case 'less_or_equal':
            case '<=':
                return $actual_value <= $expected_value;

            case 'contains':
                return is_string($actual_value) && strpos($actual_value, $expected_value) !== false;

            case 'not_contains':
                return !is_string($actual_value) || strpos($actual_value, $expected_value) === false;

            case 'exists':
                return !empty($actual_value) || $actual_value === '0' || $actual_value === 0;

            case 'not_exists':
                return empty($actual_value) && $actual_value !== '0' && $actual_value !== 0;

            case 'in':
                return is_array($expected_value) && in_array($actual_value, $expected_value);

            case 'not_in':
                return !is_array($expected_value) || !in_array($actual_value, $expected_value);
        }

        return false;
    }

    /**
     * Check if meta changed (requires tracking)
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Meta changed
     */
    protected function evaluate_meta_changed($entity, $condition) {
        $meta_key = $condition['meta_key'];

        // Use transient to track previous values
        $cache_key = sprintf(
            'bws_meta_changed_%s_%d_%s',
            $entity->get_type(),
            $entity->get_id(),
            $meta_key
        );

        $previous_value = get_transient($cache_key);
        $current_value = $entity->get_meta($meta_key);

        // Store current value for next check
        set_transient($cache_key, $current_value, HOUR_IN_SECONDS);

        // First time checking, no change detected
        if ($previous_value === false) {
            return false;
        }

        return $previous_value !== $current_value;
    }

    /**
     * Evaluate date comparison
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Date condition passes
     */
    protected function evaluate_date_comparison($entity, $condition) {
        $date_source = $condition['date_source'] ?? 'acf_field';
        $comparison = $condition['comparison'] ?? 'past';

        // Get date value
        if ($date_source === 'acf_field') {
            $date_value = $entity->get_acf_field($condition['date_field']);
        } elseif ($date_source === 'post_date' && $entity->get_type() === 'post') {
            $date_value = $entity->get_object()->post_date;
        } elseif ($date_source === 'term_meta' || $date_source === 'meta') {
            $date_value = $entity->get_meta($condition['date_field']);
        } else {
            return false;
        }

        if (!$date_value) {
            return false;
        }

        // Parse date (will create date parser utility later)
        $timestamp = $this->parse_date_value($date_value);
        if (!$timestamp) {
            return false;
        }

        $current_time = current_time('timestamp');

        // Evaluate based on comparison type
        switch ($comparison) {
            case 'past':
                return $timestamp < $current_time;

            case 'past_or_today':
                return $timestamp <= $current_time;

            case 'future':
                return $timestamp > $current_time;

            case 'today':
                return date('Y-m-d', $timestamp) === current_time('Y-m-d');

            case 'within_days':
                $days = $condition['days'] ?? 7;
                $future_timestamp = $current_time + ($days * DAY_IN_SECONDS);
                return $timestamp >= $current_time && $timestamp <= $future_timestamp;

            case 'older_than':
                $days = $condition['days'] ?? 365;
                $past_timestamp = $current_time - ($days * DAY_IN_SECONDS);
                return $timestamp < $past_timestamp;
        }

        return false;
    }

    /**
     * Check if date range is currently active
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Date range is active
     */
    protected function evaluate_date_range_active($entity, $condition) {
        $start_field = $condition['start_date_field'];
        $end_field = $condition['end_date_field'];
        $date_source = $condition['date_source'] ?? 'meta';

        // Get dates
        if ($date_source === 'acf_field') {
            $start_date = $entity->get_acf_field($start_field);
            $end_date = $entity->get_acf_field($end_field);
        } else {
            $start_date = $entity->get_meta($start_field);
            $end_date = $entity->get_meta($end_field);
        }

        if (!$start_date || !$end_date) {
            return false;
        }

        $start_timestamp = $this->parse_date_value($start_date);
        $end_timestamp = $this->parse_date_value($end_date);
        $current_time = current_time('timestamp');

        return $start_timestamp <= $current_time && $current_time <= $end_timestamp;
    }

    /**
     * Check if entity has a parent
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Has parent
     */
    protected function evaluate_has_parent($entity, $condition) {
        if ($entity->get_type() === 'post') {
            return $entity->get_object()->post_parent != 0;
        } elseif ($entity->get_type() === 'term') {
            return $entity->get_object()->parent != 0;
        }

        return false;
    }

    /**
     * Check if entity has children
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Has children
     */
    protected function evaluate_has_children($entity, $condition) {
        if ($entity->get_type() === 'post') {
            $args = [
                'post_parent' => $entity->get_id(),
                'post_type' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
            ];
            $children = get_posts($args);
            return !empty($children);
        } elseif ($entity->get_type() === 'term') {
            $children = get_term_children($entity->get_id(), $entity->get_object()->taxonomy);
            return !empty($children) && !is_wp_error($children);
        }

        return false;
    }

    /**
     * Check if entity is a parent (same as has_children)
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Is parent
     */
    protected function evaluate_is_parent($entity, $condition) {
        return $this->evaluate_has_children($entity, $condition);
    }

    /**
     * Evaluate relationship condition
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Relationship condition passes
     */
    protected function evaluate_relationship($entity, $condition) {
        // Check ACF relationship field
        $field_name = $condition['field'] ?? '';
        if (!$field_name) {
            return false;
        }

        $related = $entity->get_acf_field($field_name);

        // Check if has any related items
        if (isset($condition['has_related'])) {
            return !empty($related);
        }

        // Check for specific related item
        if (isset($condition['related_id'])) {
            if (is_array($related)) {
                $related_ids = array_map(function($item) {
                    return is_object($item) ? ($item->ID ?? $item->term_id ?? 0) : $item;
                }, $related);
                return in_array($condition['related_id'], $related_ids);
            } else {
                $related_id = is_object($related) ? ($related->ID ?? $related->term_id ?? 0) : $related;
                return $related_id == $condition['related_id'];
            }
        }

        return false;
    }

    /**
     * Evaluate multiple conditions with AND/OR logic
     *
     * @param Entity $entity Entity to check
     * @param array $condition Condition config
     * @return bool Multiple conditions pass
     */
    protected function evaluate_multiple($entity, $condition) {
        $logic = $condition['logic'] ?? 'all'; // 'all' = AND, 'any' = OR
        $conditions = $condition['conditions'] ?? [];

        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $sub_condition) {
            $result = $this->evaluate($entity, $sub_condition);

            if ($logic === 'any' && $result) {
                return true; // OR logic: return true on first match
            } elseif ($logic === 'all' && !$result) {
                return false; // AND logic: return false on first failure
            }
        }

        // If we get here:
        // - For AND (all): all conditions passed
        // - For OR (any): no conditions passed
        return $logic === 'all';
    }

    /**
     * Parse date value to timestamp
     *
     * Simple version - will be replaced by full date parser utility
     *
     * @param mixed $date_value Date value
     * @return int|false Timestamp or false
     */
    protected function parse_date_value($date_value) {
        if (is_numeric($date_value)) {
            return (int) $date_value;
        }

        if ($date_value instanceof \DateTime) {
            return $date_value->getTimestamp();
        }

        if (is_string($date_value)) {
            $timestamp = strtotime($date_value);
            return $timestamp !== false ? $timestamp : false;
        }

        return false;
    }

    /**
     * Normalize term identifiers to IDs
     *
     * @param array $terms Array of term IDs, slugs, or names
     * @param string $taxonomy Taxonomy name
     * @return array Term IDs
     */
    protected function normalize_term_ids($terms, $taxonomy) {
        $term_ids = [];

        foreach ((array)$terms as $term) {
            if (is_numeric($term)) {
                $term_ids[] = (int)$term;
            } else {
                // Try by slug first
                $term_obj = get_term_by('slug', $term, $taxonomy);
                if (!$term_obj) {
                    // Try by name
                    $term_obj = get_term_by('name', $term, $taxonomy);
                }
                if ($term_obj && !is_wp_error($term_obj)) {
                    $term_ids[] = $term_obj->term_id;
                }
            }
        }

        return $term_ids;
    }
}
