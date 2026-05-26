<?php
/**
 * Rule Storage Interface
 *
 * Defines the contract for rule storage implementations.
 * This abstraction allows switching between storage backends
 * (wp_options, CPT, external DB, etc.) without changing handler code.
 *
 * @package BWS_Meta_Manager
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rule Storage Interface
 *
 * Provides a unified API for storing and retrieving rules regardless
 * of the underlying storage mechanism.
 */
interface BWS_Rule_Storage {

    /**
     * Get all rules of a specific type
     *
     * @since 2.0.0
     * @param string $type Rule type (hierarchical_rules, propagation_rules, etc.)
     * @param array  $filters Optional filters to apply:
     *                        - 'enabled' (bool): Filter by enabled status
     *                        - 'taxonomy' (string): Filter by taxonomy
     *                        - 'post_type' (string): Filter by post type
     *                        - 'limit' (int): Limit number of results
     *                        - 'offset' (int): Offset for pagination
     * @return array Array of rules, each rule is an associative array
     *
     * @example
     * $storage = BWS_Storage_Factory::get_instance();
     * $rules = $storage->get_rules('hierarchical_rules', [
     *     'enabled' => true,
     *     'taxonomy' => 'category'
     * ]);
     */
    public function get_rules(string $type, array $filters = []): array;

    /**
     * Get a single rule by type and ID
     *
     * @since 2.0.0
     * @param string $type Rule type
     * @param int    $rule_id Rule ID (array index for options, post ID for CPT)
     * @return array|null Rule data array or null if not found
     *
     * @example
     * $rule = $storage->get_rule('hierarchical_rules', 0);
     */
    public function get_rule(string $type, int $rule_id): ?array;

    /**
     * Save a rule (create or update)
     *
     * @since 2.0.0
     * @param string $type Rule type
     * @param int    $rule_id Rule ID (use -1 for new rule)
     * @param array  $data Rule data to save
     * @return int Rule ID (array index or post ID) on success, 0 on failure
     *
     * @example
     * // Create new rule
     * $new_id = $storage->save_rule('hierarchical_rules', -1, [
     *     'name' => 'Auto Category',
     *     'taxonomy' => 'category',
     *     'enabled' => true
     * ]);
     *
     * // Update existing rule
     * $storage->save_rule('hierarchical_rules', 0, $updated_data);
     */
    public function save_rule(string $type, int $rule_id, array $data): int;

    /**
     * Delete a rule
     *
     * @since 2.0.0
     * @param string $type Rule type
     * @param int    $rule_id Rule ID
     * @return bool True on success, false on failure
     */
    public function delete_rule(string $type, int $rule_id): bool;

    /**
     * Search rules across all types
     *
     * @since 2.0.0
     * @param string $query Search query (searches in rule names, taxonomies, etc.)
     * @param array  $filters Optional additional filters
     * @return array Array of matching rules with 'type' and 'id' keys
     *
     * @example
     * $results = $storage->search_rules('category', ['enabled' => true]);
     * // Returns: [
     * //   ['type' => 'hierarchical_rules', 'id' => 0, 'name' => 'Category Auto', ...],
     * //   ['type' => 'related_rules', 'id' => 2, 'name' => 'Related Categories', ...]
     * // ]
     */
    public function search_rules(string $query, array $filters = []): array;

    /**
     * Get all supported rule types
     *
     * @since 2.0.0
     * @return array Array of rule type identifiers
     *
     * @example
     * $types = $storage->get_rule_types();
     * // Returns: ['hierarchical_rules', 'propagation_rules', 'related_rules', ...]
     */
    public function get_rule_types(): array;

    /**
     * Count rules of a specific type
     *
     * @since 2.0.0
     * @param string $type Rule type
     * @param array  $filters Optional filters (same as get_rules)
     * @return int Number of matching rules
     *
     * @example
     * $count = $storage->count_rules('hierarchical_rules', ['enabled' => true]);
     */
    public function count_rules(string $type, array $filters = []): int;

    /**
     * Check if a rule exists
     *
     * @since 2.0.0
     * @param string $type Rule type
     * @param int    $rule_id Rule ID
     * @return bool True if rule exists, false otherwise
     */
    public function rule_exists(string $type, int $rule_id): bool;

    /**
     * Duplicate a rule
     *
     * @since 2.0.0
     * @param string $type Rule type
     * @param int    $rule_id Rule ID to duplicate
     * @param array  $overrides Optional data to override in the duplicate
     * @return int New rule ID on success, 0 on failure
     *
     * @example
     * // Duplicate rule and disable it
     * $new_id = $storage->duplicate_rule('hierarchical_rules', 0, [
     *     'name' => 'Copy of ' . $original_rule['name'],
     *     'enabled' => false
     * ]);
     */
    public function duplicate_rule(string $type, int $rule_id, array $overrides = []): int;

    /**
     * Bulk enable/disable rules
     *
     * @since 2.0.0
     * @param string $type Rule type
     * @param array  $rule_ids Array of rule IDs
     * @param bool   $enabled True to enable, false to disable
     * @return int Number of rules updated
     */
    public function bulk_toggle_rules(string $type, array $rule_ids, bool $enabled): int;

    /**
     * Export rules to array format
     *
     * Prepares rules for export (JSON, XML, etc.)
     *
     * @since 2.0.0
     * @param array $filters Optional filters (type, ids, etc.)
     * @return array Exportable array of rules
     */
    public function export_rules(array $filters = []): array;

    /**
     * Import rules from array format
     *
     * @since 2.0.0
     * @param array $rules_data Array of rules to import
     * @param array $options Import options:
     *                       - 'overwrite' (bool): Overwrite existing rules
     *                       - 'skip_duplicates' (bool): Skip if rule exists
     *                       - 'prefix_names' (string): Prefix to add to rule names
     * @return array Results with 'imported', 'skipped', 'errors' counts
     */
    public function import_rules(array $rules_data, array $options = []): array;

    /**
     * Clear cache for rules
     *
     * Storage implementations that use caching should clear their cache
     * when this method is called.
     *
     * @since 2.0.0
     * @param string|null $type Optional rule type to clear, null for all
     * @return void
     */
    public function clear_cache(?string $type = null): void;

    /**
     * Get storage backend identifier
     *
     * @since 2.0.0
     * @return string Storage backend name ('options', 'cpt', 'external', etc.)
     */
    public function get_storage_type(): string;

    /**
     * Validate rule data before saving
     *
     * @since 2.0.0
     * @param string $type Rule type
     * @param array  $data Rule data to validate
     * @return array Array with 'valid' (bool) and 'errors' (array) keys
     */
    public function validate_rule(string $type, array $data): array;
}
