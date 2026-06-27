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

namespace BWS\MetaConductor\Storage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Options-based Rule Storage
 *
 * Implements RuleStorage interface using WordPress options table.
 * All rules are stored in a single option as a nested array structure.
 */
class OptionRuleStorage implements RuleStorage {

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

        // Refresh the request cache to match what's ACTUALLY stored. update_option
        // returns false for TWO reasons: (a) the new value equals the stored value
        // (no write needed) — cache should reflect $settings; (b) a genuine DB
        // failure — cache must NOT adopt $settings, or a later save in the same
        // request (e.g. import_rules looping save_rule) would read the poisoned
        // cache and persist the failed data as the baseline. Distinguish by
        // re-reading: only adopt $settings when it actually round-trips.
        // (PR#24 round 5 #5 + round 6 #4)
        if ($success || $this->cached_settings === $settings) {
            // Success ⇒ adopt. Or false BUT the cache already equals $settings ⇒
            // the false can only mean update_option no-op'd on equality (the
            // stored value also equals $settings), so adopting is correct and no
            // re-read is needed. (PR#24 round 5 #5, round 7 #3, round 8 #5)
            $this->cached_settings = $settings;
        } else {
            // false AND cache differs: distinguish a no-op (stored already ==
            // $settings) from a genuine DB failure by re-reading. Adopt only if
            // it round-trips; otherwise drop the cache so a later save in the
            // same request (e.g. import_rules looping save_rule) doesn't persist
            // failed data as the baseline. (PR#24 round 6 #4)
            $stored = get_option(self::OPTION_NAME, []);
            $this->cached_settings = (is_array($stored) && $stored === $settings)
                ? $settings
                : null;
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

        // Add IDs if not present + normalize Wireframe shape changes to legacy shape.
        //
        // WARNING: `id` is the ARRAY INDEX, re-derived on every read and never
        // persisted (save reindexes via array_values; the id key is stripped
        // before write). Wireframe stores rules POSITIONALLY, so reordering or
        // deleting a rule renumbers the rest. NEVER key persistent per-rule state
        // (tracking meta, caches) on this id — use a stable identity (e.g. post
        // id + field name). The ACF-reference handler is declarative and stores
        // nothing keyed on rules, so it is unaffected.
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
            'related_rules'    => ['target_term_id'],
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

        // related_rules trigger_term_id: canonical shape is int[] (V1).
        // Stored value may be a FormTokenField array [a,b,...] or a legacy scalar.
        // Dedupe, cast, and drop zeros.
        if ($type === 'related_rules' && isset($rule['trigger_term_id'])) {
            $raw = $rule['trigger_term_id'];
            $ids = is_array($raw) ? $raw : [$raw];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $rule['trigger_term_id'] = $ids;
        }

        if ($type === 'related_post_terms_rules') {
            if (!empty($rule['acf_field_name'])) {
                $raw = (string) $rule['acf_field_name'];
                if (str_contains($raw, ':')) {
                    [$pt, $bare]            = explode(':', $raw, 2);
                    $rule['post_type']      = $pt;
                    $rule['acf_field_name'] = $bare;
                } elseif (!isset($rule['post_type'])) {
                    $rule['post_type'] = '';
                }
            }

            // Reverse field is stored in the same "post_type:field_name" option
            // format; the handler wants the bare field name. (SPEC §V6)
            if (!empty($rule['reverse_acf_field_name'])) {
                $rraw = (string) $rule['reverse_acf_field_name'];
                if (str_contains($rraw, ':')) {
                    [, $rbare]                      = explode(':', $rraw, 2);
                    $rule['reverse_acf_field_name'] = $rbare;
                }
            }

            $rule = self::migrate_related_post_terms_shape($rule);
        }

        return $rule;
    }

    /**
     * Live-data-safe old→new shape migration for related_post_terms rules.
     *
     * Read-time only (no stored rewrite) — applies on every get_rules/get_rule
     * so legacy rows behave identically until re-saved in the new UI. (SPEC §V8)
     *
     *   source_taxonomy(+target fallback) → single `taxonomy` (prefer source;
     *     cross-tax never worked — term-ID copy rejects foreign-tax IDs)
     *   bidirectional (bool)              → keep_in_sync (bool)
     *   conflict_handling                 → DROPPED (merge→off, replace→on,
     *                                       skip→off + _migration_flag)
     *   holder_role absent                → 'target' (= legacy pull-to-holder)
     *   post_status absent                → untouched (= any)
     *
     * @param array $rule Normalized-so-far rule (post_type/acf split already done).
     * @return array
     */
    private static function migrate_related_post_terms_shape(array $rule): array {
        // Taxonomy collapse.
        if (!isset($rule['taxonomy']) || $rule['taxonomy'] === '') {
            $rule['taxonomy'] = $rule['source_taxonomy'] ?? $rule['target_taxonomy'] ?? '';
        }
        unset($rule['source_taxonomy'], $rule['target_taxonomy']);

        // bidirectional → keep_in_sync.
        if (!isset($rule['keep_in_sync']) && isset($rule['bidirectional'])) {
            $rule['keep_in_sync'] = !empty($rule['bidirectional']);
        }
        unset($rule['bidirectional']);

        // conflict_handling → keep_in_sync axis, then drop.
        if (isset($rule['conflict_handling'])) {
            if (!isset($rule['keep_in_sync'])) {
                $rule['keep_in_sync'] = ($rule['conflict_handling'] === 'replace');
            }
            if ($rule['conflict_handling'] === 'skip') {
                // No clean equivalent; flag for manual review (rare).
                $rule['_migration_flag'] = 'conflict_handling=skip dropped';
            }
            unset($rule['conflict_handling']);
        }

        // Defaults for absent keys.
        if (!isset($rule['keep_in_sync'])) {
            $rule['keep_in_sync'] = false;
        }
        if (!isset($rule['holder_role']) || $rule['holder_role'] === '') {
            // Legacy behavior was pull-to-holder.
            $rule['holder_role'] = 'target';
        }

        return $rule;
    }

    /**
     * Schema-version flag for the one-time related_post_terms_rules rewrite.
     *
     * Bumped whenever a future key-RENAMING migration is added that the admin
     * (raw get_option) can't see at read time. Re-running the rewrite is
     * idempotent, so the gate is purely to avoid a write on every admin load.
     */
    const ACFREF_SCHEMA_VERSION = 1;
    const ACFREF_SCHEMA_FLAG    = 'bws_mc_acfref_schema';

    /**
     * One-time, flag-gated persistence of the related_post_terms_rules
     * read-time migration. (SPEC §V16, B6, T18)
     *
     * The Wireframe admin reads the settings option RAW (get_option, no filter
     * seam), bypassing normalize_rule_shape — so the read-time key-RENAME
     * migration (source_taxonomy→taxonomy, bidirectional→keep_in_sync,
     * conflict_handling drop, holder_role default) never reaches the form. A
     * legacy row then renders with config DEFAULTS (push, empty taxonomy) and a
     * resave PERSISTS that corruption. This rewrites the legacy rows in storage
     * once so the admin reads already-migrated data.
     *
     * Applies ONLY the key-rename migration (migrate_related_post_terms_shape).
     * Deliberately does NOT split acf_field_name "post:field" → that split is a
     * SAFE directional adapter: the admin stores+round-trips the COMBINED value,
     * the handler splits at read time. Persisting the split would break the
     * admin select (its option keys are "post:field"). (SPEC §V16)
     *
     * Idempotent: a row already in the new shape is unchanged. Runs once per
     * schema version via the option flag.
     *
     * @return bool True if a rewrite was performed.
     */
    public function maybe_migrate_acf_ref_storage(): bool {
        if ((int) get_option(self::ACFREF_SCHEMA_FLAG, 0) >= self::ACFREF_SCHEMA_VERSION) {
            return false;
        }

        $settings = get_option(self::OPTION_NAME, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $rows = $settings['related_post_terms_rules'] ?? [];
        $changed = false;

        if (is_array($rows)) {
            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $migrated = self::migrate_related_post_terms_shape($row);
                if ($migrated !== $row) {
                    $rows[$i] = $migrated;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $settings['related_post_terms_rules'] = $rows;
            $this->save_all_settings($settings);

            // Flag iff the migrated rows are ACTUALLY in storage now. This
            // distinguishes the two reasons save_all_settings/update_option can
            // report false: (a) a genuine DB write failure — rows NOT persisted
            // → don't flag, so we retry next load instead of permanently
            // skipping and later corrupting on a raw resave (PR#24 round 2 #3);
            // (b) the new bytes equalled the stored bytes (already migrated by a
            // concurrent writer) — rows ARE persisted → flag, so we don't loop
            // re-entering the migration every admin load (PR#24 round 4 #3).
            // Re-read fresh (bypass the request cache, which we just primed).
            // Return true ONLY when the rewrite actually persisted (flag set) —
            // matches the docblock ("true if a rewrite was performed"). On a
            // write failure the flag stays unset (retry next load) and we report
            // false so a caller doesn't log "migration done". (PR#24 round 8 #3)
            $persisted = get_option(self::OPTION_NAME, []);
            if (is_array($persisted)
                && ($persisted['related_post_terms_rules'] ?? null) === $rows) {
                update_option(self::ACFREF_SCHEMA_FLAG, self::ACFREF_SCHEMA_VERSION);
                return true;
            }
            return false;
        }

        // Nothing to migrate (fresh install / already-new data): no write was
        // needed, so flag unconditionally to skip future scans.
        update_option(self::ACFREF_SCHEMA_FLAG, self::ACFREF_SCHEMA_VERSION);

        return false;
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
            // Only count + mutate rules whose state ACTUALLY changes. Counting
            // no-ops would (a) overstate the toggle count, and (b) when EVERY
            // target is already in the requested state, leave $settings ===
            // stored ⇒ update_option no-ops ⇒ save_all_settings returns false ⇒
            // the failure guard below would wrongly report 0. (PR#24 round 8 #1)
            if (isset($all_settings[$type][$rule_id])
                && (bool) ($all_settings[$type][$rule_id]['enabled'] ?? false) !== $enabled) {
                $all_settings[$type][$rule_id]['enabled'] = $enabled;
                $updated_count++;
            }
        }

        // Report 0 if a real write was needed but didn't persist, so callers
        // don't show success on a genuine DB failure (save_rule/delete_rule
        // already propagate the save result; bulk_toggle must too). With the
        // change-detection above, $updated_count>0 implies $settings differs
        // from stored, so a false here is a true failure, not a no-op.
        // (PR#24 round 6 #3, round 8 #1)
        if ($updated_count > 0 && !$this->save_all_settings($all_settings)) {
            return 0;
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
                // New schema: single `taxonomy`. Legacy rows: `source_taxonomy`.
                // Accept either so validation is live-data safe. (SPEC §V8)
                if (empty($data['taxonomy']) && empty($data['source_taxonomy'])) {
                    $errors[] = 'Taxonomy is required';
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
