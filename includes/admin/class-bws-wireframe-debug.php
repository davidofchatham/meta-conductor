<?php
/**
 * Wireframe pilot debug page.
 *
 * Hidden subpage under Meta Conductor menu. Dumps the stored option so we
 * can verify round-trip storage shape matches what handlers expect.
 *
 * REMOVE before merging Phase 2c.
 *
 * @package BWS_Meta_Manager
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Wireframe_Debug {

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_page'], 11);
    }

    public static function register_page(): void {
        add_submenu_page(
            'meta-conductor',
            __('Wireframe Debug', 'bws-meta-manager'),
            __('Debug', 'bws-meta-manager'),
            'manage_options',
            'meta-conductor-debug',
            [self::class, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'bws-meta-manager'));
        }

        $option = get_option('bws_meta_conductor_settings', null);
        $legacy = get_option('bws_taxonomy_manager_settings', null);

        echo '<div class="wrap"><h1>Wireframe Storage Debug</h1>';

        echo '<h2><code>bws_meta_conductor_settings</code> (new — Wireframe writes here)</h2>';
        echo '<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;overflow:auto;max-height:400px;">';
        echo esc_html($option === null ? '(option not set)' : wp_json_encode($option, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo '</pre>';

        echo '<h2><code>bws_taxonomy_manager_settings</code> (legacy — old UI writes here)</h2>';
        echo '<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;overflow:auto;max-height:400px;">';
        echo esc_html($legacy === null ? '(option not set)' : wp_json_encode($legacy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo '</pre>';

        echo '</div>';
    }
}
