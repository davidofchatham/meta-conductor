<?php
/**
 * Meta Conductor Diagnostics page.
 *
 * Subpage under Meta Conductor menu for dev + power-user inspection of
 * runtime state. Sections are gated:
 *   - dev sections (storage dumps, raw option contents) require WP_DEBUG
 *     or filter `bws_meta_conductor_diagnostics_dev`
 *   - future user-facing sections (rule counts, handler status) can be
 *     surfaced without dev mode
 *
 * @package BWS_Meta_Manager
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Diagnostics {

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_page'], 11);
    }

    public static function register_page(): void {
        if (!self::is_visible()) {
            return;
        }

        add_submenu_page(
            'meta-conductor',
            __('Diagnostics', 'bws-meta-manager'),
            __('Diagnostics', 'bws-meta-manager'),
            'manage_options',
            'meta-conductor-diagnostics',
            [self::class, 'render']
        );
    }

    /**
     * Whether the diagnostics page should appear in the menu.
     *
     * Dev mode = WP_DEBUG on, or filter override.
     * User mode = filter `bws_meta_conductor_show_diagnostics` returns true.
     */
    public static function is_visible(): bool {
        if (self::is_dev_mode()) {
            return true;
        }

        return (bool) apply_filters('bws_meta_conductor_show_diagnostics', false);
    }

    /**
     * Whether dev-only diagnostic sections render.
     */
    public static function is_dev_mode(): bool {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }

        return (bool) apply_filters('bws_meta_conductor_diagnostics_dev', false);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'bws-meta-manager'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meta Conductor Diagnostics', 'bws-meta-manager') . '</h1>';

        if (self::is_dev_mode()) {
            self::render_storage_section();
        } else {
            echo '<p>' . esc_html__('User-level diagnostics coming soon. Enable WP_DEBUG to see dev sections.', 'bws-meta-manager') . '</p>';
        }

        echo '</div>';
    }

    private static function render_storage_section(): void {
        $option = get_option('bws_meta_conductor_settings', null);
        $legacy = get_option('bws_taxonomy_manager_settings', null);

        echo '<h2>' . esc_html__('Storage', 'bws-meta-manager') . '</h2>';
        echo '<p class="description">' . esc_html__('Raw contents of plugin option keys. Dev-only.', 'bws-meta-manager') . '</p>';

        self::render_option_dump('bws_meta_conductor_settings', $option, __('New — Wireframe writes here', 'bws-meta-manager'));
        self::render_option_dump('bws_taxonomy_manager_settings', $legacy, __('Legacy — old UI writes here', 'bws-meta-manager'));
    }

    private static function render_option_dump(string $key, mixed $value, string $note): void {
        echo '<h3><code>' . esc_html($key) . '</code> <span style="font-weight:normal;color:#646970;">— ' . esc_html($note) . '</span></h3>';
        echo '<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;overflow:auto;max-height:400px;">';
        echo esc_html($value === null ? __('(option not set)', 'bws-meta-manager') : wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo '</pre>';
    }
}
