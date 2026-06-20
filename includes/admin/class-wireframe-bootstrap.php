<?php
/**
 * WP Wireframe Bootstrap
 *
 * Registers custom field types, builds the settings config, and boots
 * Wireframe\App for the Meta Conductor admin page.
 *
 * @package BWS_Meta_Manager
 * @since 0.3.0
 */

namespace BWS\MetaConductor\Admin;

use BWS\MetaConductor\TaxonomyManager;
use BWS\MetaConductor\Conversion\ConversionUi;

if (!defined('ABSPATH')) {
    exit;
}

class WireframeBootstrap {

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_action('init', [self::class, 'boot'], 10);
        add_action('admin_menu', [self::class, 'register_subpages'], 11);

        // Snapshot resolved term/taxonomy names into each related rule at save
        // time so the repeater row title can read them (V11). Wireframe's
        // title_template does raw value substitution only — it cannot resolve
        // a stored term ID to a name — so the names must be persisted.
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_related_labels'], 10, 1);
    }

    /**
     * Inject trigger_label / target_label into each related_rules row.
     *
     * Hooked on `wp-wireframe/save/payload`, which fires AFTER Wireframe's
     * Sanitizer (so injected keys survive even though they are not declared
     * editable) and right before the merge into saved state. Labels are a
     * save-time snapshot: renaming a term later shows the stale name until
     * the rule is re-saved (accepted, pre-1.0).
     *
     * @param array $clean_values Sanitized top-level field map.
     * @return array
     */
    public static function snapshot_related_labels(array $clean_values): array {
        if (empty($clean_values['related_rules']) || !is_array($clean_values['related_rules'])) {
            return $clean_values;
        }

        foreach ($clean_values['related_rules'] as &$rule) {
            if (!is_array($rule)) {
                continue;
            }

            $trigger_type = $rule['trigger_type'] ?? 'term';

            if ($trigger_type === 'taxonomy') {
                $rule['trigger_label'] = \esc_html(self::taxonomy_label($rule['trigger_taxonomy'] ?? ''));
            } else {
                $rule['trigger_label'] = \esc_html(self::trigger_terms_label($rule['trigger_term_id'] ?? null));
            }

            $rule['target_label'] = \esc_html(self::term_label($rule['target_term_id'] ?? null));
            $rule['scope_label']  = \esc_html(self::scope_label($rule['post_types'] ?? []));
        }
        unset($rule);

        return $clean_values;
    }

    /**
     * Resolve a single stored term ID to "<taxonomy label>: <term name>".
     *
     * Accepts a bare scalar or a single-element array. Returns '' when
     * unresolvable. Used for target_term_id (single) and as a primitive
     * for trigger_terms_label (multi).
     *
     * @param mixed $stored Term ID or single-element array.
     * @return string Unescaped label.
     */
    private static function term_label($stored): string {
        $id = is_array($stored) ? ($stored[0] ?? 0) : $stored;
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        $term = \get_term($id);
        if (!$term || \is_wp_error($term)) {
            return '';
        }

        $tax_label = self::taxonomy_label($term->taxonomy);

        return $tax_label !== '' ? $tax_label . ': ' . $term->name : $term->name;
    }

    /**
     * Build the trigger_label for a term-type rule (V7).
     *
     * Maps the int[] trigger_term_id array to individual term labels and joins
     * with ", ". Scalar and single-element-array stored values are also
     * accepted (legacy shape; normalizer converts on read but the save-payload
     * hook runs before normalize). Returns UNESCAPED text — the caller escapes
     * once at injection, matching term_label/taxonomy_label/scope_label.
     *
     * @param mixed $stored int[], scalar, or null.
     * @return string Unescaped, comma-joined label; '' if nothing resolves.
     */
    private static function trigger_terms_label($stored): string {
        $ids = is_array($stored) ? $stored : [$stored];
        $labels = [];
        foreach ($ids as $id) {
            $label = self::term_label($id);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return implode(', ', $labels);
    }

    /**
     * Build the post-type scope suffix " (Label, Label)" for the row title.
     *
     * Returns '' when the rule applies to all post types (empty post_types),
     * so the dumb-concat title_template renders no trailing space. The " ("
     * and ")" decoration lives here, not in the template. Accepts the
     * Wireframe checkbox `{slug: bool}` map and a plain list of slugs.
     *
     * @param mixed $post_types Checkbox map or list of post-type slugs.
     * @return string
     */
    private static function scope_label($post_types): string {
        if (empty($post_types) || !is_array($post_types)) {
            return '';
        }

        // Checkboxes store {slug: bool}; extract truthy keys. A plain list
        // (array_is_list) is used as-is.
        $slugs = array_is_list($post_types)
            ? $post_types
            : array_keys(array_filter($post_types));

        $labels = [];
        foreach ($slugs as $slug) {
            $obj = \get_post_type_object((string) $slug);
            if ($obj) {
                $labels[] = $obj->label;
            }
            // A slug that no longer resolves (post type unregistered after
            // save) is silently dropped — acceptable pre-1.0.
        }

        if (empty($labels)) {
            return '';
        }

        return ' (' . implode(', ', $labels) . ')';
    }

    /**
     * Resolve a taxonomy slug to its label.
     *
     * Uses the (plural) `label` to match the "Tax: Term" shape produced by
     * ConfigHelpers::all_term_options() and the user-facing examples
     * (e.g. "Shakers").
     *
     * @param string $slug
     * @return string
     */
    private static function taxonomy_label(string $slug): string {
        if ($slug === '') {
            return '';
        }

        $tax = \get_taxonomy($slug);
        if (!$tax) {
            return '';
        }

        return $tax->label ?: $slug;
    }

    /**
     * Register subpages hanging off the meta-conductor top-level menu.
     *
     * Wireframe registers the parent via add_menu_page() at admin_menu
     * priority 10; subpages hook at 11 so the parent exists.
     */
    public static function register_subpages(): void {
        if (!function_exists('acf_get_field_groups')) {
            // Conversion needs ACF; skip submenu when unavailable.
            return;
        }

        add_submenu_page(
            'meta-conductor',
            __('Data Conversion', 'bws-meta-manager'),
            __('Data Conversion', 'bws-meta-manager'),
            'manage_options',
            'meta-conductor-conversion',
            [self::class, 'render_conversion_page']
        );
    }

    /**
     * Render callback for the Data Conversion subpage.
     */
    public static function render_conversion_page(): void {
        if (!class_exists(ConversionUi::class) || !class_exists(TaxonomyManager::class)) {
            wp_die(esc_html__('Conversion components unavailable.', 'bws-meta-manager'));
        }

        $plugin             = TaxonomyManager::get_instance();
        $conversion_manager = method_exists($plugin, 'get_conversion_manager') ? $plugin->get_conversion_manager() : null;

        if (!$conversion_manager) {
            echo '<div class="wrap"><h1>' . esc_html__('Data Conversion', 'bws-meta-manager') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Conversion manager not initialized.', 'bws-meta-manager') . '</p></div>';
            echo '</div>';
            return;
        }

        $conversion_ui = new ConversionUi(
            $conversion_manager->get_field_mapper(),
            $conversion_manager->get_data_processor(),
            $conversion_manager->get_preview_system()
        );

        $conversion_ui->render_page();
    }

    /**
     * Boot Wireframe\App with the assembled config.
     *
     * Gated to admin + REST contexts. Front-end requests skip boot entirely —
     * BWS_Config_Helpers::all_term_options() does a full get_terms() scan
     * across every public taxonomy, which is wasted work outside the
     * settings UI and its save endpoint.
     */
    public static function boot(): void {
        if (!class_exists(\Wireframe\App::class)) {
            return;
        }

        // `REST_REQUEST` constant isn't defined until `parse_request` (well
        // after our `init` priority 10 boot), so detect REST via the URL
        // prefix instead. Both forms of permalinks supported.
        $rest_prefix = trailingslashit(rest_get_url_prefix());
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $is_rest     = str_contains($request_uri, '/' . $rest_prefix) || str_contains($request_uri, '?rest_route=');

        if (!is_admin() && !$is_rest) {
            return;
        }

        // WireframeConfig autoloads via PSR-4 (autoload.php) — no manual require (Phase 2a).

        // Multi-page mode with one page. The single-page menu_slug bug
        // (wp-wireframe#5) is fixed as of 1.0.6, but the `pages[]` form stays
        // — it's the natural shape for adding more Wireframe pages later.
        \Wireframe\App::boot([
            'prefix'     => 'bws-meta-conductor',
            'capability' => 'manage_options',
            'version'    => defined('BWS_META_MANAGER_VERSION') ? BWS_META_MANAGER_VERSION : '0.3.0',
            'pages'      => [
                [
                    'id'            => 'settings',
                    'option_key'    => 'bws_meta_conductor_settings',
                    'page_title'    => __('Meta Conductor', 'bws-meta-manager'),
                    'menu_title'    => __('Meta Conductor', 'bws-meta-manager'),
                    'menu_slug'     => 'meta-conductor',
                    'menu_icon'     => 'dashicons-category',
                    'menu_position' => 80,
                    'config'        => \BWS\MetaConductor\Admin\Config\WireframeConfig::build(),
                ],
            ],
        ]);
    }
}
