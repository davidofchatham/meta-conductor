<?php
/**
 * Field Converter Class
 *
 * Core field type conversion and term extraction logic.
 * This is a plugin-agnostic library with zero dependencies on BWS Meta Manager,
 * ACF, or any specific field system.
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
 * Field Converter implementation
 *
 * Provides core functionality for:
 * - Converting between field types (text → textarea, select → checkbox, etc.)
 * - Extracting term names/IDs from field values
 * - Converting terms to field value format
 * - Validating field type compatibility
 */
class FieldConverter implements FieldConverterInterface {

    /**
     * Supported field types
     *
     * @var array
     */
    private $supported_types = [
        'text',
        'textarea',
        'number',
        'email',
        'url',
        'select',
        'checkbox',
        'radio',
        'button_group',
        'true_false',
        'wysiwyg',
        'oembed',
        'date_picker',
        'date_time_picker',
        'time_picker',
        'color_picker',
    ];

    /**
     * Multi-value field types
     *
     * @var array
     */
    private $multi_value_types = [
        'checkbox',
        'gallery',
        'relationship',
        'post_object', // when multiple = true
        'taxonomy',    // when multiple = true
        'user',        // when multiple = true
    ];

    /**
     * Default conversion options
     *
     * @var array
     */
    private $default_options = [
        'delimiter'     => ',',
        'preserve_html' => false,
        'date_format'   => 'Y-m-d',
        'strip_tags'    => true,
    ];

    /**
     * Convert a field value from one type to another
     *
     * @param mixed  $value       Source field value
     * @param string $source_type Source field type
     * @param string $target_type Target field type
     * @param array  $options     Conversion options
     * @return mixed Converted field value
     */
    public function convert_field_value( $value, string $source_type, string $target_type, array $options = [] ) {
        $options = wp_parse_args( $options, $this->default_options );

        // If types are the same, return value as-is
        if ( $source_type === $target_type ) {
            return $value;
        }

        // Normalize value to array for processing
        $normalized = $this->normalize_to_array( $value, $source_type );

        // Convert based on target type
        switch ( $target_type ) {
            case 'text':
            case 'email':
            case 'url':
                // Single line text - join array values
                $result = implode( $options['delimiter'] . ' ', $normalized );
                if ( $options['strip_tags'] ) {
                    $result = wp_strip_all_tags( $result );
                }
                return $result;

            case 'textarea':
            case 'wysiwyg':
                // Multi-line text - join with newlines
                $result = implode( "\n", $normalized );
                if ( $target_type === 'textarea' && $options['strip_tags'] ) {
                    $result = wp_strip_all_tags( $result );
                }
                return $result;

            case 'number':
                // Extract first numeric value
                foreach ( $normalized as $val ) {
                    if ( is_numeric( $val ) ) {
                        return floatval( $val );
                    }
                }
                return 0;

            case 'select':
            case 'radio':
            case 'button_group':
                // Single value - return first item
                return $normalized[0] ?? '';

            case 'checkbox':
                // Multiple values - return array
                return $normalized;

            case 'true_false':
                // Boolean conversion
                $first = $normalized[0] ?? '';
                return in_array( strtolower( $first ), [ '1', 'true', 'yes', 'on' ], true );

            case 'date_picker':
            case 'date_time_picker':
            case 'time_picker':
                // Date/time conversion
                return $this->convert_to_date( $normalized[0] ?? '', $target_type, $options['date_format'] );

            default:
                // Unknown type - return first value or original
                return count( $normalized ) === 1 ? $normalized[0] : $value;
        }
    }

    /**
     * Extract term names/IDs from a field value
     *
     * @param mixed $field_value Field value to extract from
     * @param array $options     Extraction options
     * @return array Array of term names or IDs
     */
    public function extract_terms_from_field( $field_value, array $options = [] ): array {
        $defaults = [
            'field_type' => 'text',
            'delimiter'  => ',',
            'return_ids' => false,
            'taxonomy'   => '',
        ];

        $options    = wp_parse_args( $options, $defaults );
        $field_type = $options['field_type'];
        $term_names = [];

        switch ( $field_type ) {
            case 'text':
            case 'textarea':
            case 'wysiwyg':
                // Text fields - split by delimiter
                if ( is_string( $field_value ) ) {
                    // Strip HTML if present
                    $field_value = wp_strip_all_tags( $field_value );

                    // Split by common delimiters
                    $delimiters = [ $options['delimiter'], ';', '|', "\n", "\r" ];
                    $pattern    = '/[' . preg_quote( implode( '', $delimiters ), '/' ) . ']+/';
                    $terms      = preg_split( $pattern, $field_value );

                    foreach ( $terms as $term ) {
                        $term = trim( $term );
                        if ( ! empty( $term ) ) {
                            $term_names[] = $term;
                        }
                    }
                }
                break;

            case 'select':
            case 'radio':
            case 'button_group':
                // Single value fields
                if ( ! empty( $field_value ) ) {
                    $term_names[] = $field_value;
                }
                break;

            case 'checkbox':
                // Multiple value fields
                if ( is_array( $field_value ) ) {
                    $term_names = array_filter( array_map( 'trim', $field_value ) );
                } elseif ( ! empty( $field_value ) ) {
                    $term_names[] = $field_value;
                }
                break;

            case 'taxonomy':
            case 'post_object':
            case 'relationship':
                // These return IDs - convert to names if needed
                if ( is_array( $field_value ) ) {
                    $term_names = $field_value;
                } elseif ( ! empty( $field_value ) ) {
                    $term_names[] = $field_value;
                }

                // Convert IDs to names if taxonomy is provided and return_ids is false
                if ( ! $options['return_ids'] && ! empty( $options['taxonomy'] ) ) {
                    $term_names = $this->convert_ids_to_names( $term_names, $options['taxonomy'] );
                }
                break;

            default:
                // Unknown field type - try to extract as string
                if ( is_array( $field_value ) ) {
                    $term_names = array_filter( $field_value );
                } elseif ( ! empty( $field_value ) ) {
                    $term_names[] = $field_value;
                }
        }

        return array_values( array_unique( $term_names ) );
    }

    /**
     * Convert term IDs/names to a field value format
     *
     * @param array  $terms      Array of term IDs or names
     * @param string $field_type Target field type
     * @param array  $options    Formatting options
     * @return mixed Formatted field value
     */
    public function convert_terms_to_field( array $terms, string $field_type, array $options = [] ) {
        $defaults = [
            'delimiter' => ',',
            'use_ids'   => false,
            'taxonomy'  => '',
        ];

        $options = wp_parse_args( $options, $defaults );

        // If using IDs, convert names to IDs
        if ( ! $options['use_ids'] && ! empty( $options['taxonomy'] ) ) {
            $terms = $this->convert_names_to_ids( $terms, $options['taxonomy'] );
        }

        // Format based on field type
        switch ( $field_type ) {
            case 'text':
            case 'email':
            case 'url':
                return implode( $options['delimiter'] . ' ', $terms );

            case 'textarea':
            case 'wysiwyg':
                return implode( "\n", $terms );

            case 'select':
            case 'radio':
            case 'button_group':
                return $terms[0] ?? '';

            case 'checkbox':
            case 'taxonomy':
            case 'post_object':
            case 'relationship':
                return $terms;

            default:
                return count( $terms ) === 1 ? $terms[0] : $terms;
        }
    }

    /**
     * Validate if conversion between two field types is supported
     *
     * @param string $source_type Source field type
     * @param string $target_type Target field type
     * @return array Validation result
     */
    public function validate_conversion( string $source_type, string $target_type ): array {
        // Check if both types are supported
        if ( ! in_array( $source_type, $this->supported_types, true ) ) {
            return [
                'valid'   => false,
                'message' => sprintf( __( 'Source field type "%s" is not supported.', 'bws-meta-manager' ), $source_type ),
                'warning' => '',
            ];
        }

        if ( ! in_array( $target_type, $this->supported_types, true ) ) {
            return [
                'valid'   => false,
                'message' => sprintf( __( 'Target field type "%s" is not supported.', 'bws-meta-manager' ), $target_type ),
                'warning' => '',
            ];
        }

        // Check for potential data loss scenarios
        $warning = '';

        // Multi-value to single-value conversion
        if ( $this->is_multi_value_type( $source_type ) && ! $this->is_multi_value_type( $target_type ) ) {
            $warning = __( 'Converting from multi-value to single-value field may result in data loss. Only the first value will be retained.', 'bws-meta-manager' );
        }

        // Structured to unstructured conversion
        if ( in_array( $source_type, [ 'wysiwyg', 'oembed' ], true ) && in_array( $target_type, [ 'text', 'email', 'url' ], true ) ) {
            $warning = __( 'Converting from rich content field to plain text will strip formatting.', 'bws-meta-manager' );
        }

        // Numeric conversions
        if ( $source_type === 'number' && ! in_array( $target_type, [ 'number', 'text', 'textarea' ], true ) ) {
            $warning = __( 'Converting from number field may produce unexpected results.', 'bws-meta-manager' );
        }

        return [
            'valid'   => true,
            'message' => '',
            'warning' => $warning,
        ];
    }

    /**
     * Get list of supported field types
     *
     * @return array Array of supported field type strings
     */
    public function get_supported_types(): array {
        return $this->supported_types;
    }

    /**
     * Check if a field type supports multiple values
     *
     * @param string $field_type Field type to check
     * @return bool True if field type supports multiple values
     */
    public function is_multi_value_type( string $field_type ): bool {
        return in_array( $field_type, $this->multi_value_types, true );
    }

    /**
     * Normalize field value to array format
     *
     * @param mixed  $value      Field value
     * @param string $field_type Field type
     * @return array Normalized array of values
     */
    public function normalize_to_array( $value, string $field_type ): array {
        // Handle null/empty
        if ( is_null( $value ) || $value === '' ) {
            return [];
        }

        // Already an array
        if ( is_array( $value ) ) {
            return array_filter( $value, function( $v ) {
                return ! is_null( $v ) && $v !== '';
            } );
        }

        // Serialized data
        if ( is_string( $value ) && $this->is_serialized( $value ) ) {
            $unserialized = maybe_unserialize( $value );
            if ( is_array( $unserialized ) ) {
                return $unserialized;
            }
        }

        // Multi-value types - split string values
        if ( $this->is_multi_value_type( $field_type ) && is_string( $value ) ) {
            return array_filter( array_map( 'trim', explode( ',', $value ) ) );
        }

        // Single value - wrap in array
        return [ $value ];
    }

    /**
     * Format field value for display
     *
     * @param mixed  $value      Field value
     * @param string $field_type Field type
     * @param array  $options    Optional formatting options
     * @return string Formatted display string
     */
    public function format_for_display( $value, string $field_type, array $options = [] ): string {
        $defaults = [
            'delimiter' => ', ',
            'max_items' => 5,
        ];

        $options = wp_parse_args( $options, $defaults );

        // Normalize to array
        $normalized = $this->normalize_to_array( $value, $field_type );

        if ( empty( $normalized ) ) {
            return __( '(empty)', 'bws-meta-manager' );
        }

        // Limit items if specified
        if ( $options['max_items'] > 0 && count( $normalized ) > $options['max_items'] ) {
            $display_items = array_slice( $normalized, 0, $options['max_items'] );
            $remaining     = count( $normalized ) - $options['max_items'];
            $display       = implode( $options['delimiter'], $display_items );
            return sprintf( '%s %s', $display, sprintf( __( '(+%d more)', 'bws-meta-manager' ), $remaining ) );
        }

        return implode( $options['delimiter'], $normalized );
    }

    /**
     * Convert date/time value to specified format
     *
     * @param string $value       Date/time value
     * @param string $field_type  Target field type
     * @param string $date_format Date format
     * @return string Formatted date/time string
     */
    private function convert_to_date( string $value, string $field_type, string $date_format ): string {
        if ( empty( $value ) ) {
            return '';
        }

        $timestamp = strtotime( $value );
        if ( ! $timestamp ) {
            return $value; // Return original if can't parse
        }

        switch ( $field_type ) {
            case 'date_picker':
                return date( $date_format, $timestamp );

            case 'date_time_picker':
                return date( $date_format . ' H:i:s', $timestamp );

            case 'time_picker':
                return date( 'H:i:s', $timestamp );

            default:
                return $value;
        }
    }

    /**
     * Convert term IDs to names
     *
     * @param array  $term_ids Term IDs
     * @param string $taxonomy Taxonomy slug
     * @return array Term names
     */
    private function convert_ids_to_names( array $term_ids, string $taxonomy ): array {
        $names = [];

        foreach ( $term_ids as $term_id ) {
            if ( ! is_numeric( $term_id ) ) {
                continue;
            }

            $term = get_term( $term_id, $taxonomy );
            if ( ! is_wp_error( $term ) && $term ) {
                $names[] = $term->name;
            }
        }

        return $names;
    }

    /**
     * Convert term names to IDs
     *
     * @param array  $term_names Term names
     * @param string $taxonomy   Taxonomy slug
     * @return array Term IDs
     */
    private function convert_names_to_ids( array $term_names, string $taxonomy ): array {
        $ids = [];

        foreach ( $term_names as $term_name ) {
            $term = get_term_by( 'name', $term_name, $taxonomy );
            if ( $term ) {
                $ids[] = $term->term_id;
            }
        }

        return $ids;
    }

    /**
     * Check if a value is serialized
     *
     * @param string $value Value to check
     * @return bool True if serialized
     */
    private function is_serialized( string $value ): bool {
        return @unserialize( $value ) !== false || $value === 'b:0;';
    }
}
