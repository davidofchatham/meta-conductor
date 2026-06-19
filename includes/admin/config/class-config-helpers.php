<?php
/**
 * Shared option builders for Wireframe config classes.
 *
 * Centralizes the get_taxonomies() / get_post_types() / get_terms()
 * lookups that multiple rule type configs need at boot time.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class ConfigHelpers {

    /**
     * All public taxonomies, with placeholder.
     */
    public static function taxonomy_options(string $placeholder = ''): array {
        $options    = ['' => $placeholder ?: __('— Select taxonomy —', 'bws-meta-manager')];
        $taxonomies = get_taxonomies(['public' => true], 'objects');

        foreach ($taxonomies as $taxonomy) {
            $options[$taxonomy->name] = $taxonomy->label;
        }

        return $options;
    }

    /**
     * Hierarchical public taxonomies, with placeholder.
     */
    public static function hierarchical_taxonomy_options(string $placeholder = ''): array {
        $options    = ['' => $placeholder ?: __('— Select taxonomy —', 'bws-meta-manager')];
        $taxonomies = get_taxonomies(['public' => true, 'hierarchical' => true], 'objects');

        foreach ($taxonomies as $taxonomy) {
            $options[$taxonomy->name] = $taxonomy->label;
        }

        return $options;
    }

    /**
     * All public post types, with placeholder.
     */
    public static function post_type_options(string $placeholder = ''): array {
        $options    = ['' => $placeholder ?: __('— Select post type —', 'bws-meta-manager')];
        $post_types = get_post_types(['public' => true], 'objects');

        foreach ($post_types as $post_type) {
            $options[$post_type->name] = $post_type->label;
        }

        return $options;
    }

    /**
     * Hierarchical public post types (for parent → child propagation).
     */
    public static function hierarchical_post_type_options(string $placeholder = ''): array {
        $options    = ['' => $placeholder ?: __('— Select post type —', 'bws-meta-manager')];
        $post_types = get_post_types(['public' => true, 'hierarchical' => true], 'objects');

        foreach ($post_types as $post_type) {
            $options[$post_type->name] = $post_type->label;
        }

        return $options;
    }

    /**
     * Public post types as slug => label, with NO empty placeholder.
     *
     * Checkbox fields render one row per option and need no placeholder
     * row (unlike a select). Use for the shared post_types_field().
     */
    public static function post_types_checkbox_options(): array {
        $options    = [];
        $post_types = get_post_types(['public' => true], 'objects');

        foreach ($post_types as $post_type) {
            $options[$post_type->name] = $post_type->label;
        }

        return $options;
    }

    /**
     * Canonical "Limit to post types" checkboxes subfield, shared by every
     * rule type that scopes by post type. Single source of truth: id,
     * label, and empty-means-all semantics live here.
     *
     * Empty/all-unchecked ⇒ rule applies to every post type using the
     * taxonomy. Handlers read the resulting `post_types` value via
     * UnifiedHandlerBase::should_process_post (Wireframe {slug:bool} map).
     *
     * @param array $overrides Per-call field-definition overrides (e.g. columns).
     */
    public static function post_types_field(array $overrides = []): array {
        // `id` is intentionally NOT overridable: UnifiedHandlerBase::should_process_post
        // reads the `post_types` key by name, so renaming it would silently
        // make the rule apply to all post types. Merge overrides first, then
        // force the canonical id.
        return array_merge([
            'type'        => 'checkboxes',
            'label'       => __('Limit to post types', 'bws-meta-manager'),
            'description' => __('Leave all unchecked to apply to every post type using this taxonomy.', 'bws-meta-manager'),
            'columns'     => 12,
            'args'        => [
                'options' => self::post_types_checkbox_options(),
            ],
        ], $overrides, ['id' => 'post_types']);
    }

    /**
     * All terms across all public taxonomies, labelled "Taxonomy: Term".
     *
     * Designed for small sites (under ~500 terms). Result is cached for
     * the request lifetime via a static property.
     */
    public static function all_term_options(): array {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $options    = [];
        $taxonomies = get_taxonomies(['public' => true], 'objects');

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy->name,
                'hide_empty' => false,
            ]);

            if (is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $options[(string) $term->term_id] = $taxonomy->label . ': ' . $term->name;
            }
        }

        $cache = $options;
        return $options;
    }

    /**
     * ACF relationship/post-object fields across all field groups,
     * labelled "Post Type: field_name".
     *
     * A field attached to multiple post types appears once per attachment.
     * Stored value is the bare ACF field name; the sibling post_type
     * subfield disambiguates at runtime.
     *
     * Returns empty array when ACF is unavailable.
     */
    public static function acf_relationship_field_options(string $placeholder = ''): array {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $options = ['' => $placeholder ?: __('— Select ACF field —', 'bws-meta-manager')];

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            $cache = $options;
            return $options;
        }

        $post_types  = get_post_types(['public' => true], 'objects');
        $field_types = ['relationship', 'post_object'];

        foreach ($post_types as $post_type) {
            $groups = acf_get_field_groups(['post_type' => $post_type->name]);
            if (empty($groups)) {
                continue;
            }

            foreach ($groups as $group) {
                $fields = acf_get_fields($group['key']);
                if (empty($fields)) {
                    continue;
                }

                foreach ($fields as $field) {
                    if (!in_array($field['type'] ?? '', $field_types, true)) {
                        continue;
                    }

                    $key             = $post_type->name . ':' . $field['name'];
                    $field_label     = $field['label'] ?? $field['name'];
                    $label           = sprintf('%s: %s (%s)', $post_type->label, $field_label, $field['name']);
                    $options[$key]   = $label;
                }
            }
        }

        $cache = $options;
        return $options;
    }
}
