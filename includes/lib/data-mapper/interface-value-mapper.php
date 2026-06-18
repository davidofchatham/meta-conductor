<?php
/**
 * Value Mapper Interface
 *
 * Defines the contract for custom value mapping operations.
 * This is a plugin-agnostic library for mapping field option values
 * to different values (e.g., "red" → "Blue Category", "option1" → "term_name").
 *
 * @package BWS_Meta_Manager
 * @subpackage Libraries
 * @since 0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for value mapping operations
 *
 * Implementations of this interface should provide value mapping
 * without depending on any specific plugin architecture.
 */
interface BWS_Value_Mapper_Interface {

    /**
     * Set a mapping from source value to target value
     *
     * @param string $source_value Source value to map from
     * @param string $target_value Target value to map to
     * @return bool True if mapping was set successfully
     */
    public function set_mapping( string $source_value, string $target_value ): bool;

    /**
     * Set multiple mappings at once
     *
     * @param array $mappings Associative array of source => target mappings
     * @return int Number of mappings successfully set
     */
    public function set_mappings( array $mappings ): int;

    /**
     * Map a source value to its target value
     *
     * @param string $source_value Source value to map
     * @return string|null Target value if mapping exists, null otherwise
     */
    public function map_value( string $source_value ): ?string;

    /**
     * Map multiple source values
     *
     * @param array $source_values Array of source values
     * @return array {
     *     Mapping result.
     *
     *     @type array $mapped   Array of successfully mapped values (source => target)
     *     @type array $unmapped Array of unmapped source values
     * }
     */
    public function map_values( array $source_values ): array;

    /**
     * Get all unmapped values from a set of source values
     *
     * @param array $source_values Array of source values to check
     * @return array Array of values that have no mapping
     */
    public function get_unmapped_values( array $source_values ): array;

    /**
     * Set handler for unmapped values
     *
     * @param string $handler Handler strategy: 'skip', 'use_original', 'use_default', 'create'
     * @param mixed  $default Optional default value for 'use_default' strategy
     * @return bool True if handler was set successfully
     */
    public function set_unmapped_handler( string $handler, $default = null ): bool;

    /**
     * Process unmapped value based on handler strategy
     *
     * @param string $unmapped_value Unmapped value to process
     * @return mixed Processed value or null if should be skipped
     */
    public function process_unmapped( string $unmapped_value );

    /**
     * Check if a mapping exists for a value
     *
     * @param string $source_value Source value to check
     * @return bool True if mapping exists
     */
    public function has_mapping( string $source_value ): bool;

    /**
     * Get a specific mapping
     *
     * @param string $source_value Source value
     * @return string|null Target value if mapping exists, null otherwise
     */
    public function get_mapping( string $source_value ): ?string;

    /**
     * Get all mappings
     *
     * @return array Associative array of all source => target mappings
     */
    public function get_all_mappings(): array;

    /**
     * Remove a specific mapping
     *
     * @param string $source_value Source value to remove mapping for
     * @return bool True if mapping was removed
     */
    public function remove_mapping( string $source_value ): bool;

    /**
     * Clear all mappings
     *
     * @return bool True if mappings were cleared
     */
    public function clear_mappings(): bool;

    /**
     * Get mapping statistics
     *
     * @return array {
     *     Mapping statistics.
     *
     *     @type int $total_mappings   Total number of mappings
     *     @type int $used_mappings    Number of mappings that have been used
     *     @type int $unused_mappings  Number of unused mappings
     * }
     */
    public function get_statistics(): array;

    /**
     * Validate mapping configuration
     *
     * @return array {
     *     Validation result.
     *
     *     @type bool  $valid   Whether mappings are valid
     *     @type array $errors  Array of validation error messages
     *     @type array $warnings Array of validation warnings
     * }
     */
    public function validate_mappings(): array;
}
