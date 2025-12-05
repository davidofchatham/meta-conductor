<?php
/**
 * Rule Processing Engine
 *
 * Main orchestrator for processing unified rules
 *
 * @package BWS_Meta_Manager
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Rule_Engine {

    /**
     * Condition evaluator instance
     *
     * @var BWS_Condition_Evaluator
     */
    protected $condition_evaluator;

    /**
     * Action executor instance
     *
     * @var BWS_Action_Executor
     */
    protected $action_executor;

    /**
     * Constructor
     */
    public function __construct() {
        $this->condition_evaluator = new BWS_Condition_Evaluator();
        $this->action_executor = new BWS_Action_Executor();
    }

    /**
     * Process a unified rule
     *
     * @param array $rule Rule configuration
     * @return array Processing results
     */
    public function process_rule($rule) {
        $results = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        // Pre-process hook
        do_action('bws_meta_manager_before_process_rule', $rule);

        try {
            // 1. Get source entities
            $source_entities = $this->get_source_entities($rule);

            if (empty($source_entities)) {
                $results['errors'][] = 'No source entities found';
                return $results;
            }

            // 2. Process each source entity
            foreach ($source_entities as $source_entity) {
                $results['processed']++;

                try {
                    // 3. Evaluate condition
                    $condition = $rule['condition'] ?? [];
                    if (!$this->condition_evaluator->evaluate($source_entity, $condition)) {
                        $results['skipped']++;
                        continue;
                    }

                    // 4. Get target entities
                    $target_entities = $this->get_target_entities($source_entity, $rule);

                    // 5. Execute action on each target
                    foreach ($target_entities as $target_entity) {
                        $success = $this->action_executor->execute(
                            $target_entity,
                            $rule['action'],
                            $source_entity
                        );

                        if ($success) {
                            $results['updated']++;
                        }
                    }

                } catch (Exception $e) {
                    $results['errors'][] = sprintf(
                        'Error processing %s %d: %s',
                        $source_entity->get_type(),
                        $source_entity->get_id(),
                        $e->getMessage()
                    );
                }
            }

        } catch (Exception $e) {
            $results['errors'][] = 'Rule processing error: ' . $e->getMessage();
        }

        // Post-process hook
        do_action('bws_meta_manager_after_process_rule', $rule, $results);

        return $results;
    }

    /**
     * Get source entities based on rule filters
     *
     * @param array $rule Rule configuration
     * @return array BWS_Entity instances
     */
    protected function get_source_entities($rule) {
        $source_type = $rule['source_type'] ?? 'post';
        $source_filters = $rule['source_filters'] ?? [];

        $entities = [];

        switch ($source_type) {
            case 'post':
                $entities = $this->get_post_entities($source_filters);
                break;

            case 'term':
                $entities = $this->get_term_entities($source_filters);
                break;

            case 'both':
                $entities = array_merge(
                    $this->get_post_entities($source_filters),
                    $this->get_term_entities($source_filters)
                );
                break;

            case 'user':
                $entities = $this->get_user_entities($source_filters);
                break;
        }

        return apply_filters('bws_meta_manager_source_entities', $entities, $rule);
    }

    /**
     * Get post entities
     *
     * @param array $filters Filter configuration
     * @return array BWS_Entity instances
     */
    protected function get_post_entities($filters) {
        // Handle direct ID specification
        if (isset($filters['ids'])) {
            return array_map(function($id) {
                return new BWS_Entity('post', $id);
            }, (array)$filters['ids']);
        }

        $args = [
            'post_type' => $filters['post_type'] ?? 'any',
            'post_status' => $filters['post_status'] ?? 'any',
            'posts_per_page' => $filters['limit'] ?? -1,
            'fields' => 'ids',
        ];

        // Add offset/paging
        if (isset($filters['offset'])) {
            $args['offset'] = $filters['offset'];
        }
        if (isset($filters['paged'])) {
            $args['paged'] = $filters['paged'];
        }

        // Add taxonomy filters
        if (isset($filters['taxonomy']) && isset($filters['terms'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => $filters['taxonomy'],
                    'field' => is_numeric($filters['terms'][0] ?? '') ? 'term_id' : 'slug',
                    'terms' => $filters['terms'],
                    'operator' => $filters['tax_operator'] ?? 'IN',
                ],
            ];
        }

        // Add meta query
        if (isset($filters['meta_query'])) {
            $args['meta_query'] = $filters['meta_query'];
        }

        // Add date query
        if (isset($filters['date_query'])) {
            $args['date_query'] = $filters['date_query'];
        }

        // Add author filter
        if (isset($filters['author'])) {
            $args['author'] = $filters['author'];
        }

        $post_ids = get_posts($args);

        return array_map(function($post_id) {
            return new BWS_Entity('post', $post_id);
        }, $post_ids);
    }

    /**
     * Get term entities
     *
     * @param array $filters Filter configuration
     * @return array BWS_Entity instances
     */
    protected function get_term_entities($filters) {
        // Handle direct ID specification
        if (isset($filters['ids'])) {
            return array_map(function($id) {
                return new BWS_Entity('term', $id);
            }, (array)$filters['ids']);
        }

        $args = [
            'taxonomy' => $filters['taxonomy'] ?? 'category',
            'hide_empty' => $filters['hide_empty'] ?? false,
            'fields' => 'ids',
        ];

        // Add parent filter
        if (isset($filters['parent'])) {
            $args['parent'] = $filters['parent'];
        }

        // Add child_of filter
        if (isset($filters['child_of'])) {
            $args['child_of'] = $filters['child_of'];
        }

        // Add meta query
        if (isset($filters['meta_query'])) {
            $args['meta_query'] = $filters['meta_query'];
        }

        // Add search
        if (isset($filters['search'])) {
            $args['search'] = $filters['search'];
        }

        // Add slug filter
        if (isset($filters['slug'])) {
            $args['slug'] = $filters['slug'];
        }

        // Add number/count limit
        if (isset($filters['number'])) {
            $args['number'] = $filters['number'];
        }

        $term_ids = get_terms($args);

        if (is_wp_error($term_ids)) {
            return [];
        }

        return array_map(function($term_id) {
            return new BWS_Entity('term', $term_id);
        }, $term_ids);
    }

    /**
     * Get user entities
     *
     * @param array $filters Filter configuration
     * @return array BWS_Entity instances
     */
    protected function get_user_entities($filters) {
        // Handle direct ID specification
        if (isset($filters['ids'])) {
            return array_map(function($id) {
                return new BWS_Entity('user', $id);
            }, (array)$filters['ids']);
        }

        $args = [
            'fields' => 'ID',
            'number' => $filters['limit'] ?? -1,
        ];

        // Add role filter
        if (isset($filters['role'])) {
            $args['role'] = $filters['role'];
        }

        // Add meta query
        if (isset($filters['meta_query'])) {
            $args['meta_query'] = $filters['meta_query'];
        }

        $user_ids = get_users($args);

        return array_map(function($user_id) {
            return new BWS_Entity('user', $user_id);
        }, $user_ids);
    }

    /**
     * Get target entities for a source entity
     *
     * @param BWS_Entity $source_entity Source entity
     * @param array $rule Rule configuration
     * @return array BWS_Entity instances
     */
    protected function get_target_entities($source_entity, $rule) {
        $target_type = $rule['target_type'] ?? 'self';

        if ($target_type === 'self') {
            return [$source_entity];
        }

        $target_filters = $rule['target_filters'] ?? [];

        // Determine relationship
        $relationship = $target_filters['relationship'] ?? null;

        switch ($relationship) {
            case 'children':
                return $this->get_children_entities($source_entity);

            case 'parent':
                $parent = $this->get_parent_entity($source_entity);
                return $parent ? [$parent] : [];

            case 'ancestors':
                return $this->get_ancestor_entities($source_entity);

            case 'descendants':
                return $this->get_descendant_entities($source_entity);

            case 'has_term':
                // Get posts that have this term
                if ($source_entity->get_type() === 'term') {
                    return $this->get_posts_with_term($source_entity, $target_filters);
                }
                break;

            case 'has_meta':
                // Get entities with specific meta value
                return $this->get_entities_with_meta($source_entity, $target_filters);

            case 'acf_relationship':
                return $this->get_acf_related_entities($source_entity, $target_filters);

            default:
                // Get entities based on target type and filters
                if ($target_type === 'post') {
                    return $this->get_post_entities($target_filters);
                } elseif ($target_type === 'term') {
                    return $this->get_term_entities($target_filters);
                } elseif ($target_type === 'user') {
                    return $this->get_user_entities($target_filters);
                }
        }

        return [];
    }

    /**
     * Get child entities (posts or terms)
     *
     * @param BWS_Entity $source_entity Source entity
     * @return array Child entities
     */
    protected function get_children_entities($source_entity) {
        $children = [];

        if ($source_entity->get_type() === 'post') {
            $args = [
                'post_parent' => $source_entity->get_id(),
                'post_type' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
            ];
            $child_ids = get_posts($args);

            foreach ($child_ids as $child_id) {
                $children[] = new BWS_Entity('post', $child_id);
            }

        } elseif ($source_entity->get_type() === 'term') {
            $term_obj = $source_entity->get_object();
            $child_ids = get_term_children($source_entity->get_id(), $term_obj->taxonomy);

            if (!is_wp_error($child_ids)) {
                // Filter to direct children only
                foreach ($child_ids as $child_id) {
                    $child = get_term($child_id);
                    if ($child && !is_wp_error($child) && $child->parent == $source_entity->get_id()) {
                        $children[] = new BWS_Entity('term', $child_id);
                    }
                }
            }
        }

        return $children;
    }

    /**
     * Get parent entity
     *
     * @param BWS_Entity $source_entity Source entity
     * @return BWS_Entity|null Parent entity
     */
    protected function get_parent_entity($source_entity) {
        if ($source_entity->get_type() === 'post') {
            $post = $source_entity->get_object();
            if ($post->post_parent) {
                return new BWS_Entity('post', $post->post_parent);
            }
        } elseif ($source_entity->get_type() === 'term') {
            $term = $source_entity->get_object();
            if ($term->parent) {
                return new BWS_Entity('term', $term->parent);
            }
        }

        return null;
    }

    /**
     * Get ancestor entities
     *
     * @param BWS_Entity $source_entity Source entity
     * @return array Ancestor entities
     */
    protected function get_ancestor_entities($source_entity) {
        $ancestors = [];

        if ($source_entity->get_type() === 'post') {
            $post = $source_entity->get_object();
            $ancestor_ids = get_post_ancestors($source_entity->get_id());

            foreach ($ancestor_ids as $ancestor_id) {
                $ancestors[] = new BWS_Entity('post', $ancestor_id);
            }

        } elseif ($source_entity->get_type() === 'term') {
            $term = $source_entity->get_object();
            $ancestor_ids = get_ancestors($source_entity->get_id(), $term->taxonomy, 'taxonomy');

            foreach ($ancestor_ids as $ancestor_id) {
                $ancestors[] = new BWS_Entity('term', $ancestor_id);
            }
        }

        return $ancestors;
    }

    /**
     * Get descendant entities (all children recursively)
     *
     * @param BWS_Entity $source_entity Source entity
     * @return array Descendant entities
     */
    protected function get_descendant_entities($source_entity) {
        $descendants = [];

        if ($source_entity->get_type() === 'term') {
            $term = $source_entity->get_object();
            $descendant_ids = get_term_children($source_entity->get_id(), $term->taxonomy);

            if (!is_wp_error($descendant_ids)) {
                foreach ($descendant_ids as $descendant_id) {
                    $descendants[] = new BWS_Entity('term', $descendant_id);
                }
            }
        }

        // For posts, would need to recursively get children
        // Skipping for now as it's less common

        return $descendants;
    }

    /**
     * Get posts that have a specific term
     *
     * @param BWS_Entity $term_entity Term entity
     * @param array $filters Additional filters
     * @return array Post entities
     */
    protected function get_posts_with_term($term_entity, $filters) {
        $term_obj = $term_entity->get_object();

        $args = [
            'post_type' => $filters['post_type'] ?? 'any',
            'post_status' => $filters['post_status'] ?? 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => $term_obj->taxonomy,
                    'field' => 'term_id',
                    'terms' => $term_entity->get_id(),
                ],
            ],
        ];

        $post_ids = get_posts($args);

        return array_map(function($post_id) {
            return new BWS_Entity('post', $post_id);
        }, $post_ids);
    }

    /**
     * Get entities with specific meta value
     *
     * @param BWS_Entity $source_entity Source entity
     * @param array $filters Filter configuration
     * @return array Entities
     */
    protected function get_entities_with_meta($source_entity, $filters) {
        $entity_type = $filters['entity_type'] ?? 'post';
        $meta_key = $filters['meta_key'];
        $meta_value = $filters['meta_value'] ?? null;

        $meta_query = [
            'key' => $meta_key,
        ];

        if ($meta_value !== null) {
            $meta_query['value'] = $meta_value;
            $meta_query['compare'] = $filters['compare'] ?? '=';
        }

        $filters['meta_query'] = [$meta_query];

        switch ($entity_type) {
            case 'post':
                return $this->get_post_entities($filters);

            case 'term':
                return $this->get_term_entities($filters);

            case 'user':
                return $this->get_user_entities($filters);
        }

        return [];
    }

    /**
     * Get entities related via ACF field
     *
     * @param BWS_Entity $source_entity Source entity
     * @param array $filters Filter configuration
     * @return array Related entities
     */
    protected function get_acf_related_entities($source_entity, $filters) {
        $field_name = $filters['acf_relationship'] ?? $filters['field'] ?? '';

        if (!$field_name) {
            return [];
        }

        $related = $source_entity->get_acf_field($field_name);

        if (!$related) {
            return [];
        }

        $entities = [];

        // Handle different return formats
        if (!is_array($related)) {
            $related = [$related];
        }

        foreach ($related as $item) {
            if (is_object($item)) {
                if (isset($item->ID)) {
                    $entities[] = new BWS_Entity('post', $item->ID);
                } elseif (isset($item->term_id)) {
                    $entities[] = new BWS_Entity('term', $item->term_id);
                } elseif (isset($item->ID)) { // User object
                    $entities[] = new BWS_Entity('user', $item->ID);
                }
            } elseif (is_numeric($item)) {
                // Assume post ID by default
                $entity_type = $filters['related_type'] ?? 'post';
                $entities[] = new BWS_Entity($entity_type, $item);
            }
        }

        return $entities;
    }
}
