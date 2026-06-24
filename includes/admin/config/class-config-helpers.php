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
     * Normalize a Wireframe checkboxes value to a flat list of selected slugs.
     *
     * Checkboxes store a `{slug: bool}` map; a plain list of slugs (or an empty
     * value) is also accepted. Single source of truth for the extraction shared
     * by handlers (gate enforcement) and the label snapshot (row titles) — keep
     * the three former copies (handler status_gate, bootstrap status_gate_label,
     * should_process_post) from drifting.
     *
     * @param mixed $value Checkbox map, list of slugs, or empty.
     * @return string[] Selected slugs (truthy keys), or [] when nothing selected.
     */
    public static function selected_checkbox_slugs($value): array {
        if (empty($value) || !is_array($value)) {
            return [];
        }
        return array_is_list($value)
            ? array_values($value)
            : array_keys(array_filter($value));
    }

    /**
     * Registered post statuses as slug => label, with NO empty placeholder.
     *
     * Limited to the statuses meaningful as a rule gate: the built-in
     * publish/draft/pending/private/future, plus any custom public status.
     * Internal statuses (auto-draft, inherit, trash) are excluded — they are
     * never a meaningful filter target.
     */
    public static function post_status_checkbox_options(): array {
        $options = [];

        // Built-in gateable statuses, in a sensible order.
        $builtin = ['publish', 'future', 'draft', 'pending', 'private'];
        foreach ($builtin as $slug) {
            $obj = \get_post_status_object($slug);
            if ($obj) {
                $options[$slug] = $obj->label;
            }
        }

        // Custom public statuses registered by other plugins/themes.
        $custom = \get_post_stati(['public' => true, '_builtin' => false], 'objects');
        foreach ($custom as $obj) {
            if (!isset($options[$obj->name])) {
                $options[$obj->name] = $obj->label;
            }
        }

        return $options;
    }

    /**
     * Canonical "Limit to post statuses" checkboxes subfield, shared by every
     * rule type that gates on publication status. Single source of truth for
     * the id (`post_status`), label, and empty-means-all semantics.
     *
     * Empty/all-unchecked ⇒ no status filter (all statuses considered).
     * Enforcement is per-rule-type: most handlers gate the trigger post via
     * UnifiedHandlerBase::should_process_post (reads `post_status`); the
     * ACF-reference rule gates the SOURCE post during term-collection instead
     * (SPEC §V5). The field shape is shared; the enforcement is not.
     *
     * @param array $overrides Per-call field-definition overrides (e.g. label, columns).
     */
    public static function post_status_field(array $overrides = []): array {
        // `id` is intentionally NOT overridable: handlers read the `post_status`
        // key by name. Merge overrides first, then force the canonical id.
        return array_merge([
            'type'        => 'checkboxes',
            'label'       => __('Limit to post statuses', 'bws-meta-manager'),
            'description' => __('Leave all unchecked to apply to every status.', 'bws-meta-manager'),
            'columns'     => 12,
            'args'        => [
                'options' => self::post_status_checkbox_options(),
            ],
        ], $overrides, ['id' => 'post_status']);
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

        // Sort taxonomies alphabetically by label (V8).
        usort($taxonomies, fn($a, $b) => strcmp($a->label, $b->label));

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy->name,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
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
        // Cache the field map WITHOUT the placeholder row, so callers that pass
        // different placeholders (e.g. the reverse-field "— None / auto —") each
        // get their own first option instead of the first caller's cached one.
        static $fields_cache = null;

        $placeholder_row = ['' => $placeholder ?: __('— Select ACF field —', 'bws-meta-manager')];

        if ($fields_cache !== null) {
            return $placeholder_row + $fields_cache;
        }

        $fields_cache = [];

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $placeholder_row + $fields_cache;
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

                    $key                  = $post_type->name . ':' . $field['name'];
                    $field_label          = $field['label'] ?? $field['name'];
                    $label                = sprintf('%s: %s (%s)', $post_type->label, $field_label, $field['name']);
                    $fields_cache[$key]   = $label;
                }
            }
        }

        return $placeholder_row + $fields_cache;
    }
}
