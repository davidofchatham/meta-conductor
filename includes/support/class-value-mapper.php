<?php
/**
 * Value Mapper Class
 *
 * Core value mapping logic for mapping field option values to different values.
 * This is a plugin-agnostic library with zero dependencies.
 *
 * @package BWS_Meta_Manager
 * @subpackage Libraries
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Value Mapper implementation
 *
 * Provides core functionality for:
 * - Custom value mappings (e.g., "red" → "Blue Category")
 * - Unmapped value handling strategies
 * - Mapping validation and statistics
 * - Pure data transformation (no database operations)
 */
class ValueMapper implements ValueMapperInterface {

    /**
     * Mapping storage
     *
     * @var array
     */
    private $mappings = [];

    /**
     * Unmapped value handler strategy
     *
     * @var string
     */
    private $unmapped_handler = 'skip';

    /**
     * Default value for 'use_default' strategy
     *
     * @var mixed
     */
    private $default_value = null;

    /**
     * Usage tracking
     *
     * @var array
     */
    private $usage_stats = [];

    /**
     * Valid unmapped handler strategies
     *
     * @var array
     */
    private $valid_handlers = [
        'skip',          // Skip unmapped values (don't include in result)
        'use_original',  // Keep original value
        'use_default',   // Use default value
        'create',        // Signal to create new term/option with this value
    ];

    /**
     * Set a mapping from source value to target value
     *
     * @param string $source_value Source value to map from
     * @param string $target_value Target value to map to
     * @return bool True if mapping was set successfully
     */
    public function set_mapping( string $source_value, string $target_value ): bool {
        if ( empty( $source_value ) ) {
            return false;
        }

        $this->mappings[ $source_value ] = $target_value;

        // Initialize usage tracking
        if ( ! isset( $this->usage_stats[ $source_value ] ) ) {
            $this->usage_stats[ $source_value ] = 0;
        }

        return true;
    }

    /**
     * Set multiple mappings at once
     *
     * @param array $mappings Associative array of source => target mappings
     * @return int Number of mappings successfully set
     */
    public function set_mappings( array $mappings ): int {
        $count = 0;

        foreach ( $mappings as $source => $target ) {
            if ( $this->set_mapping( (string) $source, (string) $target ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Map a source value to its target value
     *
     * @param string $source_value Source value to map
     * @return string|null Target value if mapping exists, null otherwise
     */
    public function map_value( string $source_value ): ?string {
        if ( ! isset( $this->mappings[ $source_value ] ) ) {
            return null;
        }

        // Track usage
        if ( isset( $this->usage_stats[ $source_value ] ) ) {
            $this->usage_stats[ $source_value ]++;
        }

        return $this->mappings[ $source_value ];
    }

    /**
     * Map multiple source values
     *
     * @param array $source_values Array of source values
     * @return array Mapping result
     */
    public function map_values( array $source_values ): array {
        $result = [
            'mapped'   => [],
            'unmapped' => [],
        ];

        foreach ( $source_values as $source_value ) {
            $source_value = (string) $source_value;
            $target_value = $this->map_value( $source_value );

            if ( $target_value !== null ) {
                $result['mapped'][ $source_value ] = $target_value;
            } else {
                // Process unmapped value based on handler
                $processed = $this->process_unmapped( $source_value );
                if ( $processed !== null ) {
                    $result['mapped'][ $source_value ] = $processed;
                } else {
                    $result['unmapped'][] = $source_value;
                }
            }
        }

        return $result;
    }

    /**
     * Get all unmapped values from a set of source values
     *
     * @param array $source_values Array of source values to check
     * @return array Array of values that have no mapping
     */
    public function get_unmapped_values( array $source_values ): array {
        $unmapped = [];

        foreach ( $source_values as $value ) {
            $value = (string) $value;
            if ( ! $this->has_mapping( $value ) ) {
                $unmapped[] = $value;
            }
        }

        return $unmapped;
    }

    /**
     * Set handler for unmapped values
     *
     * @param string $handler Handler strategy
     * @param mixed  $default Optional default value for 'use_default' strategy
     * @return bool True if handler was set successfully
     */
    public function set_unmapped_handler( string $handler, $default = null ): bool {
        if ( ! in_array( $handler, $this->valid_handlers, true ) ) {
            return false;
        }

        $this->unmapped_handler = $handler;

        if ( $handler === 'use_default' ) {
            $this->default_value = $default;
        }

        return true;
    }

    /**
     * Process unmapped value based on handler strategy
     *
     * @param string $unmapped_value Unmapped value to process
     * @return mixed Processed value or null if should be skipped
     */
    public function process_unmapped( string $unmapped_value ) {
        switch ( $this->unmapped_handler ) {
            case 'skip':
                return null;

            case 'use_original':
                return $unmapped_value;

            case 'use_default':
                return $this->default_value;

            case 'create':
                // Signal that this value should be created
                // Calling code is responsible for actual creation
                return $unmapped_value;

            default:
                return null;
        }
    }

    /**
     * Check if a mapping exists for a value
     *
     * @param string $source_value Source value to check
     * @return bool True if mapping exists
     */
    public function has_mapping( string $source_value ): bool {
        return isset( $this->mappings[ $source_value ] );
    }

    /**
     * Get a specific mapping
     *
     * @param string $source_value Source value
     * @return string|null Target value if mapping exists, null otherwise
     */
    public function get_mapping( string $source_value ): ?string {
        return $this->mappings[ $source_value ] ?? null;
    }

    /**
     * Get all mappings
     *
     * @return array Associative array of all source => target mappings
     */
    public function get_all_mappings(): array {
        return $this->mappings;
    }

    /**
     * Remove a specific mapping
     *
     * @param string $source_value Source value to remove mapping for
     * @return bool True if mapping was removed
     */
    public function remove_mapping( string $source_value ): bool {
        if ( ! isset( $this->mappings[ $source_value ] ) ) {
            return false;
        }

        unset( $this->mappings[ $source_value ] );
        unset( $this->usage_stats[ $source_value ] );

        return true;
    }

    /**
     * Clear all mappings
     *
     * @return bool True if mappings were cleared
     */
    public function clear_mappings(): bool {
        $this->mappings     = [];
        $this->usage_stats  = [];

        return true;
    }

    /**
     * Get mapping statistics
     *
     * @return array Mapping statistics
     */
    public function get_statistics(): array {
        $total   = count( $this->mappings );
        $used    = 0;
        $unused  = 0;

        foreach ( $this->usage_stats as $count ) {
            if ( $count > 0 ) {
                $used++;
            } else {
                $unused++;
            }
        }

        return [
            'total_mappings'  => $total,
            'used_mappings'   => $used,
            'unused_mappings' => $unused,
        ];
    }

    /**
     * Validate mapping configuration
     *
     * @return array Validation result
     */
    public function validate_mappings(): array {
        $errors   = [];
        $warnings = [];

        // Check if any mappings exist
        if ( empty( $this->mappings ) ) {
            $warnings[] = __( 'No mappings defined.', 'bws-meta-manager' );
        }

        // Check for empty target values
        foreach ( $this->mappings as $source => $target ) {
            if ( empty( $target ) && $target !== '0' ) {
                $warnings[] = sprintf(
                    __( 'Mapping for "%s" has empty target value.', 'bws-meta-manager' ),
                    $source
                );
            }
        }

        // Check for duplicate target values
        $target_values = array_values( $this->mappings );
        $duplicates    = array_unique( array_diff_assoc( $target_values, array_unique( $target_values ) ) );

        if ( ! empty( $duplicates ) ) {
            $warnings[] = sprintf(
                __( '%d source values map to the same target value.', 'bws-meta-manager' ),
                count( $duplicates )
            );
        }

        // Validate unmapped handler
        if ( ! in_array( $this->unmapped_handler, $this->valid_handlers, true ) ) {
            $errors[] = sprintf(
                __( 'Invalid unmapped handler: "%s"', 'bws-meta-manager' ),
                $this->unmapped_handler
            );
        }

        // Check if default value is set when using 'use_default' handler
        if ( $this->unmapped_handler === 'use_default' && $this->default_value === null ) {
            $warnings[] = __( 'Unmapped handler is set to "use_default" but no default value is specified.', 'bws-meta-manager' );
        }

        return [
            'valid'    => empty( $errors ),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get current unmapped handler
     *
     * @return string Current handler strategy
     */
    public function get_unmapped_handler(): string {
        return $this->unmapped_handler;
    }

    /**
     * Get default value (if using 'use_default' handler)
     *
     * @return mixed Default value or null
     */
    public function get_default_value() {
        return $this->default_value;
    }

    /**
     * Get usage statistics for a specific mapping
     *
     * @param string $source_value Source value
     * @return int Number of times this mapping has been used
     */
    public function get_usage_count( string $source_value ): int {
        return $this->usage_stats[ $source_value ] ?? 0;
    }

    /**
     * Reset usage statistics
     *
     * @return bool True if statistics were reset
     */
    public function reset_statistics(): bool {
        foreach ( $this->usage_stats as $key => $value ) {
            $this->usage_stats[ $key ] = 0;
        }

        return true;
    }

    /**
     * Export mappings to array format
     *
     * @return array Exportable mapping configuration
     */
    public function export(): array {
        return [
            'mappings'          => $this->mappings,
            'unmapped_handler'  => $this->unmapped_handler,
            'default_value'     => $this->default_value,
            'usage_stats'       => $this->usage_stats,
        ];
    }

    /**
     * Import mappings from array format
     *
     * @param array $config Mapping configuration
     * @return bool True if import was successful
     */
    public function import( array $config ): bool {
        if ( ! isset( $config['mappings'] ) || ! is_array( $config['mappings'] ) ) {
            return false;
        }

        $this->mappings = $config['mappings'];

        if ( isset( $config['unmapped_handler'] ) ) {
            $this->set_unmapped_handler(
                $config['unmapped_handler'],
                $config['default_value'] ?? null
            );
        }

        if ( isset( $config['usage_stats'] ) && is_array( $config['usage_stats'] ) ) {
            $this->usage_stats = $config['usage_stats'];
        } else {
            // Initialize usage tracking for imported mappings
            foreach ( $this->mappings as $source => $target ) {
                if ( ! isset( $this->usage_stats[ $source ] ) ) {
                    $this->usage_stats[ $source ] = 0;
                }
            }
        }

        return true;
    }
}
