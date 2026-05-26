<?php
/**
 * Custom Wireframe field type: bws_wp_select
 *
 * Dynamic WordPress-data-populated select. Client uses ComboboxControl /
 * FormTokenField populating from existing AJAX endpoints
 * (bws_get_taxonomy_terms, bws_get_post_type_taxonomies, bws_get_acf_fields).
 *
 * `args`:
 *   - source: 'taxonomies' | 'post_types' | 'terms' | 'acf_fields'
 *   - multiple: bool
 *   - depends_on: sibling subfield id (for cascading)
 *   - filter: 'hierarchical_only' for taxonomies
 *
 * @package BWS_Meta_Manager
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

use Wireframe\Framework\Fields\BaseField;

class BWS_WP_Select_Field extends BaseField {

    public static function type(): string {
        return 'bws_wp_select';
    }

    public static function defaultRules(array $args): string {
        return '';
    }

    public static function sanitize(mixed $value, array $args): mixed {
        $multiple = !empty($args['multiple']);

        if ($multiple) {
            if (!is_array($value)) {
                return [];
            }
            // ACF field names and taxonomy/post-type slugs are strings; term IDs are ints.
            // Source-aware sanitization keeps the same logic as the legacy UI.
            $source = $args['source'] ?? 'taxonomies';
            if ($source === 'terms') {
                return array_values(array_filter(array_map('absint', $value)));
            }
            return array_values(array_filter(array_map('sanitize_text_field', $value)));
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $source = $args['source'] ?? 'taxonomies';
        if ($source === 'terms') {
            return (int) $value;
        }

        return sanitize_text_field((string) $value);
    }

    public static function validate(mixed $value, array $args): ?string {
        return null;
    }

    public static function isStateless(): bool {
        return false;
    }
}
