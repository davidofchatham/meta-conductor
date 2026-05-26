<?php
/**
 * WP Wireframe Bootstrap
 *
 * Registers custom field types, builds the settings config, and boots
 * Wireframe\App for the Meta Conductor admin page.
 *
 * @package BWS_Meta_Manager
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Wireframe_Bootstrap {

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_action('init', [self::class, 'boot'], 10);
    }

    /**
     * Boot Wireframe\App with the assembled config.
     */
    public static function boot(): void {
        if (!class_exists(\Wireframe\App::class)) {
            return;
        }

        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/admin/config/class-bws-wireframe-config.php';

        // Multi-page mode with one page — single-page mode ignores menu_slug
        // override (Wireframe 1.0.5 bug at src/App.php:135). Multi-page path
        // honors per-page menu_slug.
        \Wireframe\App::boot([
            'prefix'     => 'bws-meta-conductor',
            'capability' => 'manage_options',
            'version'    => defined('BWS_META_MANAGER_VERSION') ? BWS_META_MANAGER_VERSION : '3.0.0',
            'pages'      => [
                [
                    'id'            => 'settings',
                    'option_key'    => 'bws_meta_conductor_settings',
                    'page_title'    => __('Meta Conductor', 'bws-meta-manager'),
                    'menu_title'    => __('Meta Conductor', 'bws-meta-manager'),
                    'menu_slug'     => 'meta-conductor',
                    'menu_icon'     => 'dashicons-category',
                    'menu_position' => 80,
                    'config'        => BWS_Wireframe_Config::build(),
                ],
            ],
        ]);
    }
}
