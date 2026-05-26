<?php
/**
 * Hierarchical Handler (Unified Framework v2.0)
 *
 * Handles hierarchical term relationships:
 * - Applying parent/grandparent terms when children are selected
 * - Applying child terms when parent is selected (with smart expansion)
 * - Term meta inheritance between hierarchical terms
 *
 * @package BWS_Meta_Manager
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Hierarchical_Handler extends BWS_Unified_Handler_Base {

    /**
     * Initialize WordPress hooks
     */
    protected function init_hooks() {
        // Hook into post term changes
        add_action('set_object_terms', array($this, 'on_terms_set'), 10, 6);

        // Hook into ACF field updates
        add_action('acf/save_post', array($this, 'on_acf_save'), 20);

        // Hook into term updates (for term-to-term hierarchy)
        add_action('edited_term', array($this, 'on_term_edited'), 10, 3);
        add_action('created_term', array($this, 'on_term_created'), 10, 3);
    }

    /**
     * Get handler type identifier
     *
     * @return string Handler type
     */
    public function get_handler_type() {
        return 'hierarchical';
    }

    /**
     * Get rule type key for settings
     *
     * @return string Rule type key
     */
    protected function get_rule_type() {
        return 'hierarchical_rules';
    }

    /**
     * Handle terms being set on a post
     *
     * @param int $object_id Object ID
     * @param array $terms Terms being set
     * @param array $tt_ids Term taxonomy IDs
     * @param string $taxonomy Taxonomy name
     * @param bool $append Whether appending
     * @param array $old_tt_ids Old term taxonomy IDs
     */
    public function on_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        // Skip if no terms were actually set
        if (empty($tt_ids)) {
            return;
        }

        // Get rules that apply to this taxonomy
        $rules = $this->get_rules_for_taxonomy($taxonomy);

        foreach ($rules as $rule) {
            // Check if rule applies to this post
            if (!$this->should_process_post($object_id, $rule)) {
                continue;
            }

            // Process using unified engine
            $entity = new BWS_Entity('post', $object_id);
            $this->process_entity($entity, $rule);
        }
    }

    /**
     * Handle ACF field saves
     *
     * @param int|string $post_id Post ID
     */
    public function on_acf_save($post_id) {
        // Skip if not a real post ID
        if (!is_numeric($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $rules = $this->get_enabled_rules();

        foreach ($rules as $rule) {
            if (!$this->should_process_post($post_id, $rule)) {
                continue;
            }

            // Process using unified engine
            $entity = new BWS_Entity('post', $post_id);
            $this->process_entity($entity, $rule);
        }
    }

    /**
     * Handle term edits (for term-to-term hierarchy)
     *
     * @param int $term_id Term ID
     * @param int $tt_id Term taxonomy ID
     * @param string $taxonomy Taxonomy name
     */
    public function on_term_edited($term_id, $tt_id, $taxonomy) {
        $this->process_term_hierarchy($term_id, $taxonomy);
    }

    /**
     * Handle term creation (for term-to-term hierarchy)
     *
     * @param int $term_id Term ID
     * @param int $tt_id Term taxonomy ID
     * @param string $taxonomy Taxonomy name
     */
    public function on_term_created($term_id, $tt_id, $taxonomy) {
        $this->process_term_hierarchy($term_id, $taxonomy);
    }

    /**
     * Process term-to-term hierarchy
     *
     * @param int $term_id Term ID
     * @param string $taxonomy Taxonomy name
     */
    protected function process_term_hierarchy($term_id, $taxonomy) {
        $rules = $this->get_rules_for_taxonomy($taxonomy);

        foreach ($rules as $rule) {
            // Only process if rule supports term sources
            if (($rule['source_type'] ?? 'post') !== 'term') {
                continue;
            }

            if (!$this->should_process_term($term_id, $taxonomy, $rule)) {
                continue;
            }

            // Process using unified engine
            $entity = new BWS_Entity('term', $term_id);
            $this->process_entity($entity, $rule);
        }
    }

    /**
     * Get rules that apply to a specific taxonomy
     *
     * @param string $taxonomy Taxonomy name
     * @return array Matching rules
     */
    protected function get_rules_for_taxonomy($taxonomy) {
        $all_rules = $this->get_enabled_rules();
        $matching_rules = [];

        foreach ($all_rules as $rule_id => $rule) {
            $rule_taxonomy = $rule['taxonomy'] ?? '';
            if ($rule_taxonomy === $taxonomy) {
                $rule['id'] = $rule_id;
                $matching_rules[] = $rule;
            }
        }

        return $matching_rules;
    }

    /**
     * Validate rule configuration (internal method)
     *
     * @param array $rule Rule configuration
     * @return bool Valid
     */
    protected function validate_rule_internal($rule) {
        // Call parent validation first
        if (!parent::validate_rule_internal($rule)) {
            return false;
        }

        // Validate taxonomy exists
        if (empty($rule['taxonomy'])) {
            return false;
        }

        if (!taxonomy_exists($rule['taxonomy'])) {
            return false;
        }

        // Validate taxonomy is hierarchical
        $taxonomy = get_taxonomy($rule['taxonomy']);
        if (!$taxonomy->hierarchical) {
            return false;
        }

        // Validate hierarchy direction
        $valid_directions = ['child_to_parent', 'parent_to_child', 'both'];
        if (isset($rule['hierarchy_direction'])) {
            if (!in_array($rule['hierarchy_direction'], $valid_directions)) {
                return false;
            }
        }

        // Validate expansion behavior (for parent_to_child)
        $valid_behaviors = ['smart', 'always', 'merge', 'conditional', 'never'];
        if (isset($rule['expansion_behavior'])) {
            if (!in_array($rule['expansion_behavior'], $valid_behaviors)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get handler configuration defaults
     *
     * @return array Default configuration
     */
    public function get_defaults() {
        $defaults = parent::get_defaults();

        return array_merge($defaults, [
            'taxonomy' => '',
            'hierarchy_direction' => 'child_to_parent', // 'child_to_parent', 'parent_to_child', 'both'
            'inheritance_depth' => 'all', // 'immediate' or 'all'
            'expansion_behavior' => 'smart', // For parent_to_child: 'smart', 'always', 'merge', 'conditional', 'never'
            'expansion_threshold' => 0.5, // For conditional mode
            'expansion_filters' => [], // Term filters for conditional mode
            'inherit_term_meta' => false, // Whether to inherit term meta
            'term_meta_keys' => [], // Specific meta keys to inherit
        ]);
    }

    /**
     * Convert legacy v1.0 rule to unified format
     *
     * @param array $legacy_rule Legacy rule configuration
     * @return array Unified rule configuration
     */
    public function convert_legacy_rule($legacy_rule) {
        $unified_rule = $this->get_defaults();

        // Copy basic fields
        $unified_rule['enabled'] = $legacy_rule['enabled'] ?? true;
        $unified_rule['name'] = $legacy_rule['name'] ?? '';
        $unified_rule['taxonomy'] = $legacy_rule['taxonomy'] ?? '';
        $unified_rule['inheritance_depth'] = $legacy_rule['inheritance_depth'] ?? 'all';

        // Set source type based on post_types
        $unified_rule['source_type'] = 'post';
        $unified_rule['source_filters'] = [
            'post_type' => $legacy_rule['post_types'] ?? [],
            'post_status' => $legacy_rule['post_status'] ?? 'any',
        ];

        // Legacy rules only did child_to_parent
        $unified_rule['hierarchy_direction'] = 'child_to_parent';

        // Build unified action
        $unified_rule['action'] = [
            'type' => 'apply_term',
            'taxonomy' => $legacy_rule['taxonomy'] ?? '',
            'terms' => 'parent_terms', // Apply ancestor terms
            'append' => true,
        ];

        // Target is self (the post)
        $unified_rule['target_type'] = 'self';

        return $unified_rule;
    }

    /**
     * Build unified rule from hierarchical configuration
     *
     * Helper method for creating properly formatted rules
     *
     * @param array $config Configuration array
     * @return array Unified rule
     */
    public function build_rule($config) {
        $rule = $this->get_defaults();

        // Merge configuration
        $rule = array_merge($rule, $config);

        // Build action based on hierarchy direction
        $hierarchy_direction = $config['hierarchy_direction'] ?? 'child_to_parent';
        $taxonomy = $config['taxonomy'] ?? '';

        if ($hierarchy_direction === 'child_to_parent' || $hierarchy_direction === 'both') {
            // Apply ancestor terms
            $rule['action'] = [
                'type' => 'apply_term',
                'taxonomy' => $taxonomy,
                'terms' => 'parent_terms',
                'append' => true,
                'depth' => $config['inheritance_depth'] ?? 'all',
            ];
        } elseif ($hierarchy_direction === 'parent_to_child') {
            // Apply child terms
            $rule['action'] = [
                'type' => 'apply_term',
                'taxonomy' => $taxonomy,
                'terms' => 'child_terms',
                'append' => true,
                'expansion_behavior' => $config['expansion_behavior'] ?? 'smart',
                'expansion_threshold' => $config['expansion_threshold'] ?? 0.5,
                'expansion_filters' => $config['expansion_filters'] ?? [],
            ];
        }

        // Add term meta inheritance if enabled
        if ($config['inherit_term_meta'] ?? false) {
            // Convert to multiple actions
            $apply_action = $rule['action'];
            $meta_action = [
                'type' => 'inherit_parent_meta',
                'meta_keys' => $config['term_meta_keys'] ?? [],
                'source' => 'parent_term',
                'overwrite' => false,
            ];

            $rule['action'] = [
                'type' => 'multiple',
                'actions' => [$apply_action, $meta_action],
            ];
        }

        return $rule;
    }

    /**
     * Process all hierarchical rules for a specific post
     *
     * Legacy compatibility method
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param array $rule Rule configuration
     */
    public function process_hierarchical_terms($post_id, $taxonomy, $rule) {
        // Convert legacy call to unified processing
        $entity = new BWS_Entity('post', $post_id);

        // Ensure rule is in unified format
        if (!isset($rule['action'])) {
            $rule = $this->convert_legacy_rule($rule);
        }

        $this->process_entity($entity, $rule);
    }

    /**
     * Get processing statistics specific to hierarchical operations
     *
     * @return array Statistics with hierarchical details
     */
    public function get_statistics() {
        $stats = parent::get_statistics();

        global $wpdb;
        $table = $wpdb->prefix . 'bws_meta_manager_log';

        // Get counts by hierarchy direction
        $direction_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT
                action_data,
                COUNT(*) as count
            FROM {$table}
            WHERE handler_type = %s
            AND source_entity_id > 0
            GROUP BY action_data",
            $this->handler_type
        ), ARRAY_A);

        $stats['child_to_parent_count'] = 0;
        $stats['parent_to_child_count'] = 0;

        foreach ($direction_stats as $row) {
            $action_data = json_decode($row['action_data'], true);
            if (isset($action_data['direction'])) {
                if ($action_data['direction'] === 'child_to_parent') {
                    $stats['child_to_parent_count'] += (int)$row['count'];
                } elseif ($action_data['direction'] === 'parent_to_child') {
                    $stats['parent_to_child_count'] += (int)$row['count'];
                }
            }
        }

        return $stats;
    }

    /**
     * Bulk process hierarchical rules
     *
     * @param string $rule_id Rule ID
     * @param array $options Processing options
     * @return array Results
     */
    public function bulk_process_hierarchical($rule_id, $options = []) {
        $rule = $this->get_rule($rule_id);

        if (!$rule) {
            return [
                'processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['Rule not found'],
            ];
        }

        // Add batch processing options
        $batch_size = $options['batch_size'] ?? 50;
        $offset = $options['offset'] ?? 0;

        // Modify rule to include paging
        if (!isset($rule['source_filters'])) {
            $rule['source_filters'] = [];
        }

        $rule['source_filters']['limit'] = $batch_size;
        $rule['source_filters']['offset'] = $offset;

        return $this->process_rule($rule);
    }

    /**
     * Preview hierarchical changes
     *
     * Shows what terms would be added/removed without making changes
     *
     * @param int $entity_id Entity ID
     * @param string $entity_type Entity type (post, term)
     * @param string $rule_id Rule ID
     * @return array Preview data
     */
    public function preview_changes($entity_id, $entity_type, $rule_id) {
        $rule = $this->get_rule($rule_id);

        if (!$rule) {
            return [
                'error' => 'Rule not found',
            ];
        }

        $entity = new BWS_Entity($entity_type, $entity_id);
        $taxonomy = $rule['taxonomy'] ?? '';

        if (!$taxonomy) {
            return [
                'error' => 'No taxonomy specified in rule',
            ];
        }

        // Get current terms
        $current_terms = $entity->get_terms($taxonomy);
        $current_term_ids = wp_list_pluck($current_terms, 'term_id');

        // Simulate what would be added
        $preview = [
            'current_terms' => $current_terms,
            'terms_to_add' => [],
            'final_terms' => [],
            'hierarchy_direction' => $rule['hierarchy_direction'] ?? 'child_to_parent',
        ];

        $hierarchy_direction = $rule['hierarchy_direction'] ?? 'child_to_parent';

        if ($hierarchy_direction === 'child_to_parent' || $hierarchy_direction === 'both') {
            // Preview ancestor addition
            $ancestors = [];
            foreach ($current_term_ids as $term_id) {
                if ($rule['inheritance_depth'] === 'immediate') {
                    $term = get_term($term_id);
                    if ($term && !is_wp_error($term) && $term->parent) {
                        $ancestors[] = $term->parent;
                    }
                } else {
                    $term_ancestors = get_ancestors($term_id, $taxonomy, 'taxonomy');
                    $ancestors = array_merge($ancestors, $term_ancestors);
                }
            }

            $ancestors = array_unique($ancestors);
            $ancestors = array_diff($ancestors, $current_term_ids);

            $preview['terms_to_add'] = array_map(function($term_id) {
                return get_term($term_id);
            }, $ancestors);
        } elseif ($hierarchy_direction === 'parent_to_child') {
            // Preview child expansion
            $children = [];
            foreach ($current_term_ids as $term_id) {
                $term_children = get_term_children($term_id, $taxonomy);
                if (!is_wp_error($term_children)) {
                    // Filter to direct children only
                    foreach ($term_children as $child_id) {
                        $child = get_term($child_id);
                        if ($child && !is_wp_error($child) && $child->parent == $term_id) {
                            $children[] = $child_id;
                        }
                    }
                }
            }

            $children = array_unique($children);

            // Apply expansion behavior
            $expansion_behavior = $rule['expansion_behavior'] ?? 'smart';

            if ($expansion_behavior === 'smart') {
                // Only add if no children currently selected
                $has_any_children = !empty(array_intersect($children, $current_term_ids));
                if (!$has_any_children) {
                    $children_to_add = array_diff($children, $current_term_ids);
                } else {
                    $children_to_add = [];
                }
            } elseif ($expansion_behavior === 'always') {
                $children_to_add = array_diff($children, $current_term_ids);
            } elseif ($expansion_behavior === 'merge') {
                $children_to_add = array_diff($children, $current_term_ids);
            } elseif ($expansion_behavior === 'never') {
                $children_to_add = [];
            } else {
                $children_to_add = [];
            }

            $preview['terms_to_add'] = array_map(function($term_id) {
                return get_term($term_id);
            }, $children_to_add);

            $preview['expansion_behavior'] = $expansion_behavior;
            $preview['all_possible_children'] = array_map(function($term_id) {
                return get_term($term_id);
            }, $children);
        }

        $final_term_ids = array_merge($current_term_ids, wp_list_pluck($preview['terms_to_add'], 'term_id'));
        $preview['final_terms'] = array_map(function($term_id) {
            return get_term($term_id);
        }, $final_term_ids);

        return $preview;
    }
}
