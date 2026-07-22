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

        // Snapshot the ACF-reference row title (SPEC §V10). Separate callback
        // from the related path — generalize later if shapes converge.
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_acf_reference_labels'], 10, 1);

        // Snapshot the propagation row title (SPEC §V11). post_type → plural
        // post_types (Phase 3) broke the old {post_type} token; resolve human
        // labels at save like the others.
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_propagation_labels'], 10, 1);

        // Snapshot the time-based row title (SPEC §V11). Term + scope + window.
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_time_based_labels'], 10, 1);
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

            // Flag a disabled rule in the collapsed row title. title_template is
            // raw token substitution with no client-side conditional, and the
            // repeater header has no extension slot for a live control, so the
            // marker is baked into the leading label token at save. It refreshes
            // on the save that flips `enabled`, so it's accurate for persisted
            // state. (Live header toggle would need a Wireframe JS fork; tracked
            // separately.)
            $rule['trigger_label'] = self::disabled_prefix($rule) . $rule['trigger_label'];
        }
        unset($rule);

        return $clean_values;
    }

    /**
     * Leading marker for a disabled rule's collapsed row title, '' when enabled.
     * Prepended to the first title_template token by each snapshot. Shared by
     * every rule type whose repeater carries an `enabled` toggle.
     *
     * @param array $rule Clean rule values (the `enabled` subfield).
     * @return string Unescaped marker (already-safe literal).
     */
    private static function disabled_prefix(array $rule): string {
        // `enabled` defaults true; a rule missing the key (legacy) is treated
        // as enabled, matching the config default and the handler gate.
        $enabled = !array_key_exists('enabled', $rule) || !empty($rule['enabled']);
        return $enabled ? '' : \esc_html__('[Disabled] ', 'meta-conductor');
    }

    /**
     * Assemble each ACF-reference rule's row title (SPEC §V10).
     *
     * Hooked on `wp-wireframe/save/payload`, PRE-storage — so `acf_field_name`
     * is still the raw "post_type:field_name" option value (before the storage
     * adapter splits it). No A→B arrow: same term, same taxonomy, moved across
     * a relationship.
     *
     * Schema: {Copy|Sync} {Taxonomy} terms {to|from} {field_label}{ on {statuses}}
     *   Copy|Sync ← keep_in_sync (off|on)
     *   to|from   ← holder_role (source=to/push | target=from/pull)
     *   field_label ← acf_get_field()['label'] (clean human label), fallback name
     *   on {statuses} ← post_status gate, only when set
     *
     * @param array $clean_values
     * @return array
     */
    public static function snapshot_acf_reference_labels(array $clean_values): array {
        if (empty($clean_values['related_post_terms_rules']) || !is_array($clean_values['related_post_terms_rules'])) {
            return $clean_values;
        }

        foreach ($clean_values['related_post_terms_rules'] as &$rule) {
            if (!is_array($rule)) {
                continue;
            }

            $verb = !empty($rule['keep_in_sync'])
                ? __('Sync', 'meta-conductor')
                : __('Copy', 'meta-conductor');

            // Default an ABSENT holder_role to 'target', matching the handler
            // (holder_is_source) and the storage migration — NOT 'source'. The
            // key is absent only for a legacy raw rule re-saved before the
            // migration flag is set; defaulting to 'source' here would write a
            // row title that lies about the rule's runtime direction. A new rule
            // always carries an explicit holder_role. (PR#24 round 4 #2)
            $prep = (($rule['holder_role'] ?? 'target') === 'source')
                ? __('to', 'meta-conductor')
                : __('from', 'meta-conductor');

            $tax_label   = self::taxonomy_label($rule['taxonomy'] ?? '');
            $field_label = self::acf_field_label($rule['acf_field_name'] ?? '');
            $gate        = self::status_gate_label($rule['post_status'] ?? []);

            // Assemble; tolerate empty parts gracefully.
            $title = trim(sprintf(
                /* translators: 1: Copy/Sync 2: taxonomy 3: to/from 4: field label */
                __('%1$s %2$s terms %3$s %4$s', 'meta-conductor'),
                $verb,
                $tax_label,
                $prep,
                $field_label
            ));

            if ($gate !== '') {
                $title .= ' ' . sprintf(__('on %s', 'meta-conductor'), $gate);
            }

            // Flag disabled rules in the collapsed title (see disabled_prefix).
            $rule['row_title'] = self::disabled_prefix($rule) . \esc_html($title);
        }
        unset($rule);

        return $clean_values;
    }

    /**
     * Assemble each propagation rule's row title (SPEC §V11).
     *
     * Hooked on `wp-wireframe/save/payload`. Schema:
     *   {Scope: }Copy {Taxonomy} terms to children{ (conflict)}
     *   - Scope prefix ("Pages: ") only when the rule is restricted to specific
     *     post types; omitted when it applies to all (empty post_types).
     *   - conflict suffix always shown.
     *   e.g. "Pages: Copy Breakers terms to children (replace)"
     *        "Copy Categories terms to children (merge)"
     * No arrow — direction is stated in words ("to children").
     *
     * @param array $clean_values
     * @return array
     */
    public static function snapshot_propagation_labels(array $clean_values): array {
        if (empty($clean_values['propagation_rules']) || !is_array($clean_values['propagation_rules'])) {
            return $clean_values;
        }

        foreach ($clean_values['propagation_rules'] as &$rule) {
            if (!is_array($rule)) {
                continue;
            }

            $scope    = self::propagation_scope_prefix($rule['post_types'] ?? []);
            $tax      = self::taxonomy_label($rule['taxonomy'] ?? '');
            $conflict = self::conflict_label($rule['conflict_handling'] ?? 'merge');

            $title = sprintf(
                /* translators: 1: taxonomy label 2: conflict mode */
                __('Copy %1$s terms to children (%2$s)', 'meta-conductor'),
                $tax,
                $conflict
            );

            $rule['row_title'] = self::disabled_prefix($rule) . $scope . \esc_html($title);
        }
        unset($rule);

        return $clean_values;
    }

    /**
     * Leading "Post type: " prefix for the propagation row title, shown ONLY
     * when the rule is restricted to specific post types. Empty (= applies to
     * all hierarchical types) ⇒ '' so the title reads "Copy … terms to children".
     *
     * @param mixed $post_types Checkbox {slug:bool} map or list of slugs.
     * @return string Unescaped, trailing ": " when present.
     */
    private static function propagation_scope_prefix($post_types): string {
        $labels = self::post_type_labels($post_types);
        return empty($labels) ? '' : \esc_html(implode(', ', $labels)) . ': ';
    }

    /**
     * Human label for a propagation conflict_handling value.
     *
     * @param string $value merge|replace|skip.
     * @return string Unescaped label.
     */
    private static function conflict_label($value): string {
        switch ($value) {
            case 'replace':
                return __('replace', 'meta-conductor');
            case 'skip':
                return __('skip if set', 'meta-conductor');
            case 'merge':
            default:
                return __('merge', 'meta-conductor');
        }
    }

    /**
     * Assemble each time-based rule's row title (SPEC §V11).
     *
     * Hooked on `wp-wireframe/save/payload`. Date-first (the window is the most
     * salient part of a manually configured date rule), then a sentence:
     *   {start}–{end}: Apply {target} to {scope}{ with {filter}}
     *   - dates joined by an en dash, no surrounding spaces.
     *   - scope = "posts" (all types) or the post-type labels (when restricted).
     *   - filter clause only when set: specific terms → "with {Term, …}";
     *     else taxonomies → "with any {Taxonomy} term"; neither → omitted.
     *   e.g. "2026-05-26–2026-05-27: Apply Shakers: Grandchild ii to posts"
     *        "2026-05-26–2026-05-27: Apply … to Pages with Breakers: Term A"
     *
     * @param array $clean_values
     * @return array
     */
    public static function snapshot_time_based_labels(array $clean_values): array {
        if (empty($clean_values['time_based_rules']) || !is_array($clean_values['time_based_rules'])) {
            return $clean_values;
        }

        foreach ($clean_values['time_based_rules'] as &$rule) {
            if (!is_array($rule)) {
                continue;
            }

            $start  = (string) ($rule['start_date'] ?? '');
            $end    = (string) ($rule['end_date'] ?? '');
            $target = self::term_label($rule['target_term_id'] ?? null);
            $scope  = self::time_based_scope_phrase($rule['post_types'] ?? []);
            $filter = self::time_based_filter_clause($rule);

            // en dash, no surrounding spaces.
            $window = ($start !== '' || $end !== '') ? $start . "\xE2\x80\x93" . $end . ': ' : '';

            $sentence = sprintf(
                /* translators: 1: target term 2: post-type scope phrase */
                __('Apply %1$s to %2$s', 'meta-conductor'),
                $target !== '' ? $target : __('(no term)', 'meta-conductor'),
                $scope
            );

            $title = $window . $sentence . $filter;

            $rule['row_title'] = self::disabled_prefix($rule) . \esc_html($title);
        }
        unset($rule);

        return $clean_values;
    }

    /**
     * Scope phrase for the time-based title's "to …" clause: "posts" when the
     * rule applies to all post types (empty post_types), else the human
     * post-type labels ("Pages", "Posts, Pages"). Unescaped.
     *
     * @param mixed $post_types Checkbox {slug:bool} map or list of slugs.
     * @return string
     */
    private static function time_based_scope_phrase($post_types): string {
        $labels = self::post_type_labels($post_types);
        return empty($labels) ? __('posts', 'meta-conductor') : implode(', ', $labels);
    }

    /**
     * Filter clause for the time-based title: " with {specific terms}" when
     * filter_terms is set; else " with any {taxonomy} term" when
     * filter_taxonomies is set; else '' (no filter). Unescaped.
     *
     * @param array $rule
     * @return string
     */
    private static function time_based_filter_clause(array $rule): string {
        $terms = self::trigger_terms_label($rule['filter_terms'] ?? null);
        if ($terms !== '') {
            return ' ' . sprintf(__('with %s', 'meta-conductor'), $terms);
        }

        $taxonomies = Config\ConfigHelpers::selected_checkbox_slugs($rule['filter_taxonomies'] ?? []);
        $labels = [];
        foreach ($taxonomies as $slug) {
            $label = self::taxonomy_label((string) $slug);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        if (!empty($labels)) {
            return ' ' . sprintf(__('with any %s term', 'meta-conductor'), implode(', ', $labels));
        }

        return '';
    }

    /**
     * Resolve a raw "post_type:field_name" (or bare name) ACF relationship
     * field to its clean human label via acf_get_field(). Falls back to the
     * bare field name. (SPEC §V10)
     *
     * @param string $stored Raw option value.
     * @return string Unescaped label.
     */
    private static function acf_field_label($stored): string {
        $raw = (string) $stored;
        if ($raw === '') {
            return '';
        }
        // Strip the "post_type:" prefix the option value carries.
        $name = \str_contains($raw, ':') ? explode(':', $raw, 2)[1] : $raw;

        if (function_exists('acf_get_field')) {
            $field = \acf_get_field($name);
            if (is_array($field) && !empty($field['label'])) {
                return (string) $field['label'];
            }
        }
        return $name;
    }

    /**
     * Comma-joined human labels for a post_status gate (Wireframe {slug:bool}
     * map or list). '' when no gate set. (SPEC §V10)
     *
     * @param mixed $post_status
     * @return string Unescaped.
     */
    private static function status_gate_label($post_status): string {
        $slugs = Config\ConfigHelpers::selected_checkbox_slugs($post_status);
        if (empty($slugs)) {
            return '';
        }

        $labels = [];
        foreach ($slugs as $slug) {
            $obj = \get_post_status_object((string) $slug);
            $labels[] = $obj ? $obj->label : (string) $slug;
        }
        return implode(', ', $labels);
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
     * Resolve a Wireframe post-type checkbox value ({slug:bool} map or slug
     * list) to a flat array of human post-type labels. Unresolvable slugs (a
     * type unregistered after save) are dropped. Single source for the three
     * row-title scope formatters below. (0.6.0 review — was triplicated.)
     *
     * @param mixed $post_types
     * @return string[] Post-type labels.
     */
    private static function post_type_labels($post_types): array {
        $labels = [];
        foreach (Config\ConfigHelpers::selected_checkbox_slugs($post_types) as $slug) {
            $obj = \get_post_type_object((string) $slug);
            if ($obj) {
                $labels[] = $obj->label;
            }
        }
        return $labels;
    }

    /**
     * Post-type scope SUFFIX " (Label, Label)" for a row title, '' when the rule
     * applies to all post types. The " (" / ")" decoration lives here, not in
     * the template.
     *
     * @param mixed $post_types
     * @return string
     */
    private static function scope_label($post_types): string {
        $labels = self::post_type_labels($post_types);
        return empty($labels) ? '' : ' (' . implode(', ', $labels) . ')';
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
            __('Data Conversion', 'meta-conductor'),
            __('Data Conversion', 'meta-conductor'),
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
            wp_die(esc_html__('Conversion components unavailable.', 'meta-conductor'));
        }

        $plugin             = TaxonomyManager::get_instance();
        $conversion_manager = method_exists($plugin, 'get_conversion_manager') ? $plugin->get_conversion_manager() : null;

        if (!$conversion_manager) {
            echo '<div class="wrap"><h1>' . esc_html__('Data Conversion', 'meta-conductor') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Conversion manager not initialized.', 'meta-conductor') . '</p></div>';
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

        // One-time persist of the related_post_terms_rules read-time migration,
        // BEFORE Wireframe reads the option raw (it bypasses normalize_rule_shape,
        // so the form would otherwise render legacy rows with config defaults and
        // a resave would corrupt them). Flag-gated → at most one write. (SPEC §V16/B6)
        $storage = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
        if (method_exists($storage, 'maybe_migrate_acf_ref_storage')) {
            $storage->maybe_migrate_acf_ref_storage();
        }

        // WireframeConfig autoloads via PSR-4 (autoload.php) — no manual require (Phase 2a).

        // Multi-page mode with one page. The single-page menu_slug bug
        // (wp-wireframe#5) is fixed as of 1.0.6, but the `pages[]` form stays
        // — it's the natural shape for adding more Wireframe pages later.
        \Wireframe\App::boot([
            'prefix'     => 'bws-meta-conductor',
            'capability' => 'manage_options',
            'version'    => defined('BWS_META_MANAGER_VERSION') ? BWS_META_MANAGER_VERSION : '0.3.0',
            // Symlinked installs (local dev) resolve the package via realpath()
            // to a path outside WP_PLUGIN_DIR, so Wireframe's assetsUrl() prefix
            // match fails and emits a broken asset base. plugins_url() keyed off
            // the symlinked main-file path derives the correct URL. Wireframe
            // appends src/assets/ internally. (SPEC: symlink asset-URL fix.)
            'assets_url' => \plugins_url(
                'vendor/tdrayson/wp-wireframe/src/assets/',
                dirname(__DIR__, 2) . '/meta-conductor.php'
            ),
            'pages'      => [
                [
                    'id'            => 'settings',
                    'option_key'    => 'bws_meta_conductor_settings',
                    'page_title'    => __('Meta Conductor', 'meta-conductor'),
                    'menu_title'    => __('Meta Conductor', 'meta-conductor'),
                    'menu_slug'     => 'meta-conductor',
                    'menu_icon'     => 'dashicons-category',
                    'menu_position' => 80,
                    'config'        => \BWS\MetaConductor\Admin\Config\WireframeConfig::build(),
                ],
            ],
        ]);
    }
}
