<?php
/**
 * Action Executor
 *
 * Executes actions on entities
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Action_Executor {

    /**
     * Execute an action on an entity
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action configuration
     * @param BWS_Entity|null $source_entity Source entity (for dynamic values)
     * @return bool Success
     */
    public function execute($entity, $action, $source_entity = null) {
        if (!$entity->exists()) {
            return false;
        }

        $type = $action['type'] ?? '';

        switch ($type) {
            case 'apply_term':
                return $this->execute_apply_term($entity, $action, $source_entity);

            case 'remove_term':
                return $this->execute_remove_term($entity, $action, $source_entity);

            case 'set_meta':
                return $this->execute_set_meta($entity, $action, $source_entity);

            case 'set_acf':
            case 'set_acf_field':
                return $this->execute_set_acf($entity, $action, $source_entity);

            case 'inherit_parent_meta':
                return $this->execute_inherit_parent_meta($entity, $action);

            case 'set_relationship':
                return $this->execute_set_relationship($entity, $action, $source_entity);

            case 'sync_taxonomy':
                return $this->execute_sync_taxonomy($entity, $action, $source_entity);

            case 'convert_field':
                return $this->execute_convert_field($entity, $action, $source_entity);

            case 'multiple':
                return $this->execute_multiple($entity, $action, $source_entity);

            default:
                return apply_filters('bws_meta_manager_execute_custom_action', false, $entity, $action, $source_entity);
        }
    }

    /**
     * Apply taxonomy term(s) to entity
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @param BWS_Entity|null $source_entity Source entity
     * @return bool Success
     */
    protected function execute_apply_term($entity, $action, $source_entity) {
        if ($entity->get_type() !== 'post') {
            return false; // Only posts can have terms (for now)
        }

        $taxonomy = $action['taxonomy'];
        $terms = $action['terms'];
        $append = $action['append'] ?? true;

        // Handle dynamic term sources
        if ($terms === 'source_terms' && $source_entity) {
            $terms = $source_entity->get_terms($taxonomy);
            $terms = is_wp_error($terms) ? [] : wp_list_pluck($terms, 'term_id');
        } elseif ($terms === 'parent_terms') {
            $terms = $this->get_parent_terms($entity, $taxonomy, $action);
        } elseif ($terms === 'child_terms') {
            $terms = $this->get_child_terms($entity, $taxonomy, $action);
        } elseif ($terms === 'source_term' && $source_entity && $source_entity->get_type() === 'term') {
            $terms = [$source_entity->get_id()];
        }

        // Handle value mapping (for term meta sync)
        if (isset($action['mapping']) && $source_entity) {
            $source_meta_key = $action['source_meta_key'] ?? 'active';
            $source_value = $source_entity->get_meta($source_meta_key);
            $source_value_str = $source_value === true ? 'true' :
                               ($source_value === false ? 'false' : (string)$source_value);

            $mapped_term = $action['mapping'][$source_value_str] ?? null;

            if ($mapped_term === null) {
                // Remove all mapped terms
                $all_mapped = array_values(array_filter($action['mapping']));
                if (!empty($all_mapped)) {
                    $entity->remove_terms($all_mapped, $taxonomy);
                }
                return true;
            }

            $terms = [$mapped_term];
        }

        // Apply terms
        $result = $entity->apply_terms($terms, $taxonomy, $append);

        return !is_wp_error($result);
    }

    /**
     * Get parent terms for an entity
     *
     * @param BWS_Entity $entity Entity
     * @param string $taxonomy Taxonomy name
     * @param array $action Action config
     * @return array Parent term IDs
     */
    protected function get_parent_terms($entity, $taxonomy, $action) {
        $parent_ids = [];
        $depth = $action['depth'] ?? 'all';

        if ($entity->get_type() === 'post') {
            $entity_terms = $entity->get_terms($taxonomy);

            if (is_wp_error($entity_terms)) {
                return [];
            }

            foreach ($entity_terms as $term) {
                $ancestors = get_ancestors($term->term_id, $taxonomy, 'taxonomy');

                if ($depth === 'direct' || $depth === 1) {
                    // Only immediate parent
                    if ($term->parent != 0) {
                        $parent_ids[] = $term->parent;
                    }
                } elseif (is_numeric($depth)) {
                    // Specific depth
                    $parent_ids = array_merge($parent_ids, array_slice($ancestors, 0, (int)$depth));
                } else {
                    // All ancestors
                    $parent_ids = array_merge($parent_ids, $ancestors);
                }
            }
        } elseif ($entity->get_type() === 'term') {
            // Get parent terms for a term
            $term_obj = $entity->get_object();
            if ($term_obj->parent != 0) {
                $ancestors = get_ancestors($entity->get_id(), $taxonomy, 'taxonomy');
                $parent_ids = $ancestors;
            }
        }

        return array_unique($parent_ids);
    }

    /**
     * Get child terms for an entity (with smart expansion logic)
     *
     * @param BWS_Entity $entity Entity
     * @param string $taxonomy Taxonomy name
     * @param array $action Action config
     * @return array Child term IDs
     */
    protected function get_child_terms($entity, $taxonomy, $action) {
        if ($entity->get_type() !== 'post') {
            return [];
        }

        $entity_terms = $entity->get_terms($taxonomy);

        if (is_wp_error($entity_terms)) {
            return [];
        }

        $entity_term_ids = wp_list_pluck($entity_terms, 'term_id');
        $child_ids = [];
        $expansion_behavior = $action['expansion_behavior'] ?? 'smart';
        $depth = $action['depth'] ?? 'direct_children';

        foreach ($entity_terms as $term) {
            $children = get_term_children($term->term_id, $taxonomy);

            if (empty($children) || is_wp_error($children)) {
                continue;
            }

            // Filter to direct children only if specified
            if ($depth === 'direct_children' || $depth === 'direct') {
                $direct_children = [];
                foreach ($children as $child_id) {
                    $child = get_term($child_id);
                    if ($child && !is_wp_error($child) && $child->parent == $term->term_id) {
                        $direct_children[] = $child_id;
                    }
                }
                $children = $direct_children;
            }

            // Apply expansion behavior
            switch ($expansion_behavior) {
                case 'smart':
                    // Only expand if NO children are already selected
                    $has_any_children = !empty(array_intersect($children, $entity_term_ids));
                    if (!$has_any_children) {
                        $child_ids = array_merge($child_ids, $children);
                    }
                    break;

                case 'always':
                    // Always add all children
                    $child_ids = array_merge($child_ids, $children);
                    break;

                case 'merge':
                    // Only add missing children
                    $missing = array_diff($children, $entity_term_ids);
                    $child_ids = array_merge($child_ids, $missing);
                    break;

                case 'conditional':
                    $conditions = $action['conditions'] ?? [];
                    $min_children = $conditions['min_children_to_skip'] ?? 1;
                    $selected_children = array_intersect($children, $entity_term_ids);

                    if (count($selected_children) < $min_children) {
                        $max_to_add = $conditions['max_children_to_add'] ?? PHP_INT_MAX;

                        // Filter excluded terms
                        if (!empty($conditions['exclude_terms'])) {
                            $excluded_slugs = (array)$conditions['exclude_terms'];
                            $children = array_filter($children, function($child_id) use ($excluded_slugs) {
                                $child = get_term($child_id);
                                return $child && !in_array($child->slug, $excluded_slugs);
                            });
                        }

                        $children = array_slice($children, 0, $max_to_add);
                        $child_ids = array_merge($child_ids, $children);
                    }
                    break;

                case 'never':
                    // Don't expand
                    break;
            }
        }

        return array_unique($child_ids);
    }

    /**
     * Remove terms from entity
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @param BWS_Entity|null $source_entity Source entity
     * @return bool Success
     */
    protected function execute_remove_term($entity, $action, $source_entity) {
        if ($entity->get_type() !== 'post') {
            return false;
        }

        $taxonomy = $action['taxonomy'];
        $terms = $action['terms'] ?? [];

        if (empty($terms)) {
            return false;
        }

        $result = $entity->remove_terms($terms, $taxonomy);

        return !is_wp_error($result);
    }

    /**
     * Set meta value
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @param BWS_Entity|null $source_entity Source entity
     * @return bool Success
     */
    protected function execute_set_meta($entity, $action, $source_entity) {
        $meta_key = $action['meta_key'];
        $value = $action['value'];

        // Handle dynamic values
        if ($value === 'source_value' && $source_entity) {
            $source_meta_key = $action['source_meta_key'] ?? $meta_key;
            $value = $source_entity->get_meta($source_meta_key);
        } elseif (isset($action['mapping']) && $source_entity) {
            $source_meta_key = $action['source_meta_key'] ?? 'active';
            $source_value = $source_entity->get_meta($source_meta_key);
            $source_value_str = $source_value === true ? 'true' :
                               ($source_value === false ? 'false' : (string)$source_value);
            $value = $action['mapping'][$source_value_str] ?? $value;
        }

        return $entity->set_meta($meta_key, $value);
    }

    /**
     * Set ACF field
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @param BWS_Entity|null $source_entity Source entity
     * @return bool Success
     */
    protected function execute_set_acf($entity, $action, $source_entity) {
        $field_name = $action['field'];
        $value = $action['value'];

        // Handle dynamic values
        if ($value === 'source_value' && $source_entity) {
            $source_field = $action['source_field'] ?? $field_name;
            $value = $source_entity->get_acf_field($source_field);
        } elseif (isset($action['mapping']) && $source_entity) {
            $source_meta_key = $action['source_meta_key'] ?? 'active';
            $source_value = $source_entity->get_meta($source_meta_key);
            $source_value_str = $source_value === true ? 'true' :
                               ($source_value === false ? 'false' : (string)$source_value);
            $value = $action['mapping'][$source_value_str] ?? $value;
        }

        return $entity->set_acf_field($field_name, $value);
    }

    /**
     * Inherit parent meta (for terms)
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @return bool Success
     */
    protected function execute_inherit_parent_meta($entity, $action) {
        if ($entity->get_type() !== 'term') {
            return false;
        }

        $term_obj = $entity->get_object();
        if (!$term_obj->parent) {
            return false; // No parent
        }

        $parent_entity = new BWS_Entity('term', $term_obj->parent);
        $meta_keys = $action['meta_keys'] ?? [];
        $success = true;

        foreach ($meta_keys as $meta_key) {
            $parent_value = $parent_entity->get_meta($meta_key);
            if ($parent_value !== null && $parent_value !== '') {
                if (!$entity->set_meta($meta_key, $parent_value)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Set relationship (post parent/child)
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @param BWS_Entity|null $source_entity Source entity
     * @return bool Success
     */
    protected function execute_set_relationship($entity, $action, $source_entity) {
        if ($entity->get_type() === 'post') {
            $parent_id = $action['parent_id'] ?? 0;

            // Dynamic parent from source
            if ($source_entity && $source_entity->get_type() === 'post') {
                $parent_id = $source_entity->get_id();
            }

            $result = wp_update_post([
                'ID' => $entity->get_id(),
                'post_parent' => $parent_id
            ]);

            return !is_wp_error($result) && $result !== 0;

        } elseif ($entity->get_type() === 'term') {
            $parent_id = $action['parent_id'] ?? 0;
            $term_obj = $entity->get_object();

            $result = wp_update_term(
                $entity->get_id(),
                $term_obj->taxonomy,
                ['parent' => $parent_id]
            );

            return !is_wp_error($result);
        }

        return false;
    }

    /**
     * Sync taxonomy terms to another taxonomy
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @param BWS_Entity|null $source_entity Source entity
     * @return bool Success
     */
    protected function execute_sync_taxonomy($entity, $action, $source_entity) {
        if ($entity->get_type() !== 'post') {
            return false;
        }

        $source_taxonomy = $action['source_taxonomy'];
        $target_taxonomy = $action['target_taxonomy'];
        $mapping_type = $action['mapping'] ?? 'direct';

        // Get source terms
        $source_terms = $entity->get_terms($source_taxonomy);
        if (is_wp_error($source_terms) || empty($source_terms)) {
            return false;
        }

        $target_terms = [];

        if ($mapping_type === 'direct') {
            // Direct mapping - use same term slugs
            foreach ($source_terms as $source_term) {
                $target_term = get_term_by('slug', $source_term->slug, $target_taxonomy);
                if ($target_term && !is_wp_error($target_term)) {
                    $target_terms[] = $target_term->term_id;
                }
            }
        } elseif (is_array($mapping_type)) {
            // Custom mapping
            foreach ($source_terms as $source_term) {
                if (isset($mapping_type[$source_term->slug])) {
                    $target_slug = $mapping_type[$source_term->slug];
                    $target_term = get_term_by('slug', $target_slug, $target_taxonomy);
                    if ($target_term && !is_wp_error($target_term)) {
                        $target_terms[] = $target_term->term_id;
                    }
                }
            }
        }

        if (empty($target_terms)) {
            return false;
        }

        $result = $entity->apply_terms($target_terms, $target_taxonomy, false);
        return !is_wp_error($result);
    }

    /**
     * Convert field value
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @param BWS_Entity|null $source_entity Source entity
     * @return bool Success
     */
    protected function execute_convert_field($entity, $action, $source_entity) {
        $source_field = $action['source_field'];
        $target_field = $action['target_field'];
        $conversion_type = $action['conversion_type'] ?? 'field_to_field';

        // Get source value
        $source_value = $entity->get_acf_field($source_field);

        if ($conversion_type === 'field_to_field') {
            // Simple field copy/conversion
            return $entity->set_acf_field($target_field, $source_value);

        } elseif ($conversion_type === 'field_to_taxonomy') {
            // Convert field value to taxonomy terms
            $taxonomy = $action['target_taxonomy'] ?? $target_field;

            // Parse value into terms
            $terms = $this->parse_value_to_terms($source_value);

            if (empty($terms)) {
                return false;
            }

            $result = $entity->apply_terms($terms, $taxonomy, $action['append'] ?? false);
            return !is_wp_error($result);
        }

        return false;
    }

    /**
     * Parse field value into term array
     *
     * @param mixed $value Field value
     * @return array Term slugs/IDs
     */
    protected function parse_value_to_terms($value) {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            // Split by common delimiters
            return array_filter(preg_split('/[,;\n\r]+/', $value));
        }

        if (is_numeric($value) || is_bool($value)) {
            return [(string)$value];
        }

        return [];
    }

    /**
     * Execute multiple actions
     *
     * @param BWS_Entity $entity Target entity
     * @param array $action Action config
     * @param BWS_Entity|null $source_entity Source entity
     * @return bool Overall success
     */
    protected function execute_multiple($entity, $action, $source_entity) {
        $targets = $action['targets'] ?? [];
        $success = true;

        foreach ($targets as $target_action) {
            if (!$this->execute($entity, $target_action, $source_entity)) {
                $success = false;
                // Continue processing other actions even if one fails
            }
        }

        return $success;
    }
}
