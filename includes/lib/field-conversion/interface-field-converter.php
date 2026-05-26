<?php
/**
 * Field Converter Interface
 *
 * Defines the contract for field type conversion and term extraction operations.
 * This is a plugin-agnostic library for converting between field types and
 * extracting/formatting term data from field values.
 *
 * @package BWS_Meta_Manager
 * @subpackage Libraries
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for field conversion operations
 *
 * Implementations of this interface should provide field value conversion
 * without depending on any specific plugin architecture or field system.
 */
interface BWS_Field_Converter_Interface {

    /**
     * Convert a field value from one type to another
     *
     * @param mixed  $value       Source field value
     * @param string $source_type Source field type (text, textarea, select, etc.)
     * @param string $target_type Target field type
     * @param array  $options {
     *     Optional. Conversion options.
     *
     *     @type string $delimiter      Delimiter for splitting/joining values. Default ','.
     *     @type bool   $preserve_html  Whether to preserve HTML in conversions. Default false.
     *     @type string $date_format    Date format for date field conversions. Default 'Y-m-d'.
     *     @type bool   $strip_tags     Whether to strip HTML tags. Default true.
     * }
     * @return mixed Converted field value
     */
    public function convert_field_value( $value, string $source_type, string $target_type, array $options = [] );

    /**
     * Extract term names/IDs from a field value
     *
     * Handles various field types: text (comma-separated), select, checkbox, relationship, taxonomy, etc.
     *
     * @param mixed  $field_value Field value to extract from
     * @param array  $options {
     *     Extraction options.
     *
     *     @type string $field_type    Field type (text, select, checkbox, etc.)
     *     @type string $delimiter     Delimiter for text fields. Default ','.
     *     @type bool   $return_ids    Whether to return IDs instead of names. Default false.
     *     @type string $taxonomy      Taxonomy slug (for taxonomy fields). Default ''.
     * }
     * @return array Array of term names or IDs
     */
    public function extract_terms_from_field( $field_value, array $options = [] ): array;

    /**
     * Convert term IDs/names to a field value format
     *
     * @param array  $terms      Array of term IDs or names
     * @param string $field_type Target field type (text, select, checkbox, etc.)
     * @param array  $options {
     *     Optional. Formatting options.
     *
     *     @type string $delimiter  Delimiter for text fields. Default ','.
     *     @type bool   $use_ids    Whether terms are IDs (vs names). Default false.
     *     @type string $taxonomy   Taxonomy slug (for looking up term names). Default ''.
     * }
     * @return mixed Formatted field value
     */
    public function convert_terms_to_field( array $terms, string $field_type, array $options = [] );

    /**
     * Validate if conversion between two field types is supported
     *
     * @param string $source_type Source field type
     * @param string $target_type Target field type
     * @return array {
     *     Validation result.
     *
     *     @type bool   $valid   Whether conversion is supported
     *     @type string $message Validation message (empty if valid)
     *     @type string $warning Optional warning message
     * }
     */
    public function validate_conversion( string $source_type, string $target_type ): array;

    /**
     * Get list of supported field types
     *
     * @return array Array of supported field type strings
     */
    public function get_supported_types(): array;

    /**
     * Check if a field type supports multiple values
     *
     * @param string $field_type Field type to check
     * @return bool True if field type supports multiple values
     */
    public function is_multi_value_type( string $field_type ): bool;

    /**
     * Normalize field value to array format
     *
     * Converts single values to arrays, handles serialized data, etc.
     *
     * @param mixed  $value      Field value
     * @param string $field_type Field type
     * @return array Normalized array of values
     */
    public function normalize_to_array( $value, string $field_type ): array;

    /**
     * Format field value for display
     *
     * @param mixed  $value      Field value
     * @param string $field_type Field type
     * @param array  $options    Optional formatting options
     * @return string Formatted display string
     */
    public function format_for_display( $value, string $field_type, array $options = [] ): string;
}
