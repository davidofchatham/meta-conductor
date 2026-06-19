<?php
/**
 * WordPress Options Rule Storage Implementation
 *
 * Stores rules in a single serialized array in wp_options.
 * This is the current storage method used by BWS Meta Manager.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Options-based Rule Storage
 *
 * Implements BWS_Rule_Storage interface using WordPress options table.
 * All rules are stored in a single option as a nested array structure.
 */
class BWS_Option_Rule_Storage implements BWS_Rule_Storage {

    /**
     * Options key in wp_options table
     *
     * @var string
     */
    const OPTION_NAME = 'bws_meta_conductor_settings';

    /**
     * Cached settings array
     *
     * @var array|null
     */
    private $cached_settings = null;

    /**
     * Valid rule types
     *
     * @var array
     */
    private $valid_types = [
        'hierarchical_rules',
        'propagation_rules',
        'related_rules',
        'time_based_rules',
        'related_post_terms_rules',
        'hierarchical_level_restriction_rules',
        'title_slug_rules',
    ];

    /**
     * Get all settings from options
     *
     * @return array Complete settings array
     */
    private function get_all_settings(): array {
        if ($this->cached_settings === null) {
            $this->cached_settings = get_option(self::OPTION_NAME, []);

            // Ensure all rule types exist
            foreach ($this->valid_types as $type) {
                if (!isset($this->cached_settings[$type])) {
                    $this->cached_settings[$type] = [];
                }
            }
        }

        return $this->cached_settings;
    }

    /**
     * Read the raw, cached settings option — including non-rule global keys
     * (e.g. conflict_handling_overrides, manual_processing_enabled) that the
     * rule-typed accessors don't expose. Served from the same request cache as
     * get_rules(), so callers needn't issue a second get_option().
     *
     * @return array Complete settings option.
     */
    public function get_raw_settings(): array {
        return $this->get_all_settings();
    }

    /**
     * Save settings to options
     *
     * @param array $settings Complete settings array
     * @return bool Success
     */
    private function save_all_settings(array $settings): bool {
        $success = update_option(self::OPTION_NAME, $settings);

        if ($success) {
            $this->cached_settings = $settings;
        }

        return $success;
    }

    /**
     * Apply filters to rules array
     *
     * @param array $rules Rules array
     * @param array $filters Filters to apply
     * @return array Filtered rules
     */
    private function apply_filters(array $rules, array $filters): array {
        if (empty($filters)) {
            return $rules;
        }

        $filtered = [];

        foreach ($rules as $index => $rule) {
            $matches = true;

            // Filter by enabled status
            if (isset($filters['enabled'])) {
                $rule_enabled = $rule['enabled'] ?? true;
                if ($rule_enabled !== $filters['enabled']) {
                    $matches = false;
                }
            }

            // Filter by taxonomy
            if (isset($filters['taxonomy']) && $matches) {
                $rule_taxonomy = $rule['taxonomy'] ?? '';
                if ($rule_taxonomy !== $filters['taxonomy']) {
                    $matches = false;
                }
            }

            // Filter by post type
            if (isset($filters['post_type']) && $matches) {
                $post_types = $rule['post_types'] ?? [];
                if (!in_array($filters['post_type'], (array)$post_types, true)) {
                    $matches = false;
                }
            }

            if ($matches) {
                // Ensure rule has an ID
                if (!isset($rule['id'])) {
                    $rule['id'] = $index;
                }
                $filtered[] = $rule;
            }
        }

        // Apply limit and offset
        if (isset($filters['limit']) || isset($filters['offset'])) {
            $offset = $filters['offset'] ?? 0;
            $limit = $filters['limit'] ?? -1;

            if ($limit === -1) {
                $filtered = array_slice($filtered, $offset);
            } else {
                $filtered = array_slice($filtered, $offset, $limit);
            }
        }

        return $filtered;
    }

    /**
     * {@inheritDoc}
     */
    public function get_rules(string $type, array $filters = []): array {
        if (!in_array($type, $this->valid_types, true)) {
            return [];
        }

        $all_settings = $this->get_all_settings();
        $rules = $all_settings[$type] ?? [];

        // Add IDs if not present + normalize Wireframe shape changes to legacy shape
        foreach ($rules as $index => &$rule) {
            if (!isset($rule['id'])) {
                $rule['id'] = $index;
            }
            $rule = self::normalize_rule_shape($type, $rule);
        }
        unset($rule);

        return $this->apply_filters($rules, $filters);
    }

    /**
     * Coerce stored rule data into the canonical shape handlers consume.
     *
     * Storage is the adapter boundary between writers (current UI: WP
     * Wireframe REST; future writers: CLI, import) and handlers. Each
     * writer may serialize differently; handlers should see a single
     * canonical shape and not care which writer produced the row.
     *
     * Current coercions:
     *   - Single-value term IDs: [N] array (from FormTokenField max=1) → int N
     *   - ACF relationship field: "post_type:field_name" prefix → split into
     *     scalar post_type + bare acf_field_name
     */
    private static function normalize_rule_shape(string $type, array $rule): array {
        $single_term_fields = [
            'related_rules'    => ['trigger_term_id', 'target_term_id'],
            'time_based_rules' => ['target_term_id'],
        ];

        if (isset($single_term_fields[$type])) {
            foreach ($single_term_fields[$type] as $field) {
                if (!isset($rule[$field])) {
                    continue;
                }
                if (is_array($rule[$field])) {
                    $first        = reset($rule[$field]);
                    $rule[$field] = $first === false ? 0 : (int) $first;
                } else {
                    $rule[$field] = (int) $rule[$field];
                }
            }
        }

        if ($type === 'related_post_terms_rules' && !empty($rule['acf_field_name'])) {
            $raw = (string) $rule['acf_field_name'];
            if (str_contains($raw, ':')) {
                [$pt, $bare]            = explode(':', $raw, 2);
                $rule['post_type']      = $pt;
                $rule['acf_field_name'] = $bare;
            } elseif (!isset($rule['post_type'])) {
                $rule['post_type'] = '';
            }
        }

        return $rule;
    }

    /**
     * {@inheritDoc}
     */
    public function get_rule(string $type, int $rule_id): ?array {
        if (!in_array($type, $this->valid_types, true)) {
            return null;
        }

        $all_settings = $this->get_all_settings();
        $rules = $all_settings[$type] ?? [];

        if (!isset($rules[$rule_id])) {
            return null;
        }

        $rule = $rules[$rule_id];
        $rule['id'] = $rule_id;

        return self::normalize_rule_shape($type, $rule);
    }

    /**
     * {@inheritDoc}
     */
    public function save_rule(string $type, int $rule_id, array $data): int {
        // Returns the saved rule's zero-based index on success, or -1 on
        // failure. Index 0 is a valid first rule — callers must guard with
        // `>= 0`, not `> 0`.
        if (!in_array($type, $this->valid_types, true)) {
            return -1;
        }

        // Guard: -1 is the only valid "create new" sentinel. Any other negative
        // id means a caller round-tripped a failure return (-1 collides with
        // the create sentinel only by value, not intent) or passed garbage.
        // Fail loud rather than silently create or corrupt an index.
        if ($rule_id < -1) {
            return -1;
        }

        $all_settings = $this->get_all_settings();

        if (!isset($all_settings[$type])) {
            $all_settings[$type] = [];
        }

        // Remove ID from data (it's the array key)
        unset($data['id']);

        // Create new rule (append)
        if ($rule_id === -1) {
            $all_settings[$type][] = $data;
            $new_id = count($all_settings[$type]) - 1;
        } else {
            // Update existing rule
            if (!isset($all_settings[$type][$rule_id])) {
                return -1;
            }
            $all_settings[$type][$rule_id] = $data;
            $new_id = $rule_id;
        }

        $success = $this->save_all_settings($all_settings);

        return $success ? $new_id : -1;
    }

    /**
     * {@inheritDoc}
     */
    public function delete_rule(string $type, int $rule_id): bool {
        if (!in_array($type, $this->valid_types, true)) {
            return false;
        }

        $all_settings = $this->get_all_settings();

        if (!isset($all_settings[$type][$rule_id])) {
            return false;
        }

        // Remove rule and re-index array
        unset($all_settings[$type][$rule_id]);
        $all_settings[$type] = array_values($all_settings[$type]);

        return $this->save_all_settings($all_settings);
    }

    /**
     * {@inheritDoc}
     */
    public function search_rules(string $query, array $filters = []): array {
        $query_lower = strtolower($query);
        $results = [];

        foreach ($this->valid_types as $type) {
            $rules = $this->get_rules($type, $filters);

            foreach ($rules as $rule) {
                $searchable = [
                    $rule['name'] ?? '',
                    $rule['taxonomy'] ?? '',
                    implode(' ', $rule['post_types'] ?? []),
                ];

                $searchable_text = strtolower(implode(' ', $searchable));

                if (strpos($searchable_text, $query_lower) !== false) {
                    $rule['type'] = $type;
                    $results[] = $rule;
                }
            }
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function get_rule_types(): array {
        return $this->valid_types;
    }

    /**
     * {@inheritDoc}
     */
    public function count_rules(string $type, array $filters = []): int {
        return count($this->get_rules($type, $filters));
    }

    /**
     * {@inheritDoc}
     */
    public function rule_exists(string $type, int $rule_id): bool {
        return $this->get_rule($type, $rule_id) !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function duplicate_rule(string $type, int $rule_id, array $overrides = []): int {
        $original = $this->get_rule($type, $rule_id);

        if (!$original) {
            return 0;
        }

        // Remove ID
        unset($original['id']);

        // Apply overrides
        $duplicate = array_merge($original, $overrides);

        // Add " (Copy)" to name if not overridden
        if (!isset($overrides['name'])) {
            $duplicate['name'] = ($original['name'] ?? 'Rule') . ' (Copy)';
        }

        return $this->save_rule($type, -1, $duplicate);
    }

    /**
     * {@inheritDoc}
     */
    public function bulk_toggle_rules(string $type, array $rule_ids, bool $enabled): int {
        if (!in_array($type, $this->valid_types, true)) {
            return 0;
        }

        $all_settings = $this->get_all_settings();
        $updated_count = 0;

        foreach ($rule_ids as $rule_id) {
            if (isset($all_settings[$type][$rule_id])) {
                $all_settings[$type][$rule_id]['enabled'] = $enabled;
                $updated_count++;
            }
        }

        if ($updated_count > 0) {
            $this->save_all_settings($all_settings);
        }

        return $updated_count;
    }

    /**
     * {@inheritDoc}
     */
    public function export_rules(array $filters = []): array {
        $export = [
            'version' => defined('BWS_META_MANAGER_VERSION') ? BWS_META_MANAGER_VERSION : '0.3.0',
            'storage_type' => 'options',
            'exported_at' => current_time('mysql'),
            'rules' => [],
        ];

        // Export specific types or all types
        $types_to_export = $filters['types'] ?? $this->valid_types;

        foreach ($types_to_export as $type) {
            if (!in_array($type, $this->valid_types, true)) {
                continue;
            }

            $rules = $this->get_rules($type, $filters);

            if (!empty($rules)) {
                $export['rules'][$type] = $rules;
            }
        }

        return $export;
    }

    /**
     * {@inheritDoc}
     */
    public function import_rules(array $rules_data, array $options = []): array {
        $results = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $overwrite = $options['overwrite'] ?? false;
        $skip_duplicates = $options['skip_duplicates'] ?? true;
        $prefix_names = $options['prefix_names'] ?? '';

        foreach ($rules_data as $type => $rules) {
            if (!in_array($type, $this->valid_types, true)) {
                $results['errors'][] = "Invalid rule type: {$type}";
                continue;
            }

            foreach ($rules as $rule) {
                // Add prefix if specified
                if ($prefix_names && isset($rule['name'])) {
                    $rule['name'] = $prefix_names . $rule['name'];
                }

                // Check for duplicates by exact name (search_rules uses a
                // fuzzy substring match — "Foo" would falsely match "Food").
                if ($skip_duplicates) {
                    $import_name = $rule['name'] ?? '';
                    $is_duplicate = false;
                    foreach ($this->get_rules($type) as $existing_rule) {
                        if (($existing_rule['name'] ?? '') === $import_name) {
                            $is_duplicate = true;
                            break;
                        }
                    }
                    if ($is_duplicate) {
                        $results['skipped']++;
                        continue;
                    }
                }

                // Import the rule
                $new_id = $this->save_rule($type, -1, $rule);

                if ($new_id >= 0) {
                    $results['imported']++;
                } else {
                    $results['errors'][] = "Failed to import rule: " . ($rule['name'] ?? 'Unnamed');
                }
            }
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function clear_cache(?string $type = null): void {
        $this->cached_settings = null;

        // Clear WordPress object cache
        wp_cache_delete(self::OPTION_NAME, 'options');
    }

    /**
     * {@inheritDoc}
     */
    public function get_storage_type(): string {
        return 'options';
    }

    /**
     * {@inheritDoc}
     */
    public function validate_rule(string $type, array $data): array {
        $errors = [];

        // Basic validation - check required fields by type
        switch ($type) {
            case 'hierarchical_rules':
                if (empty($data['taxonomy'])) {
                    $errors[] = 'Taxonomy is required for hierarchical rules';
                }
                if (!empty($data['taxonomy']) && !taxonomy_exists($data['taxonomy'])) {
                    $errors[] = 'Invalid taxonomy: ' . $data['taxonomy'];
                }
                break;

            case 'propagation_rules':
                if (empty($data['taxonomy'])) {
                    $errors[] = 'Taxonomy is required for propagation rules';
                }
                if (empty($data['post_type'])) {
                    $errors[] = 'Post type is required for propagation rules';
                }
                break;

            case 'related_rules':
                if (empty($data['source_taxonomy'])) {
                    $errors[] = 'Source taxonomy is required for related rules';
                }
                if (empty($data['target_taxonomy'])) {
                    $errors[] = 'Target taxonomy is required for related rules';
                }
                break;

            case 'time_based_rules':
                if (empty($data['taxonomy'])) {
                    $errors[] = 'Taxonomy is required for time-based rules';
                }
                if (empty($data['schedule_type'])) {
                    $errors[] = 'Schedule type is required for time-based rules';
                }
                break;

            case 'related_post_terms_rules':
                if (empty($data['acf_field_name'])) {
                    $errors[] = 'ACF field name is required';
                }
                if (empty($data['source_taxonomy'])) {
                    $errors[] = 'Source taxonomy is required';
                }
                break;

            case 'hierarchical_level_restriction_rules':
                if (empty($data['taxonomy'])) {
                    $errors[] = 'Taxonomy is required';
                }
                if (empty($data['restriction_mode'])) {
                    $errors[] = 'Restriction mode is required';
                }
                break;

            case 'title_slug_rules':
                if (empty($data['post_type'])) {
                    $errors[] = 'Post type is required for title/slug rules';
                } elseif (!post_type_exists($data['post_type'])) {
                    $errors[] = 'Invalid post type: ' . $data['post_type'];
                }
                if (empty($data['title_pattern']) && empty($data['slug_pattern'])) {
                    $errors[] = 'At least one of title pattern or slug pattern is required';
                }
                if (!empty($data['slug_pattern']) && !empty($data['slug_mode'])
                    && !in_array($data['slug_mode'], ['replace', 'prefix', 'suffix'], true)) {
                    $errors[] = 'Invalid slug mode';
                }
                break;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
