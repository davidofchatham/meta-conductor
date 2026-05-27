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
        add_action('admin_menu', [self::class, 'register_subpages'], 11);
        add_action('admin_head', [self::class, 'subpage_padding_fix']);
    }

    /**
     * Temporary workaround: restore #wpcontent padding on subpages.
     *
     * Wireframe adds body class `wireframe-admin` to every screen whose ID
     * contains its menu_slug — including our subpages. Its CSS sets
     * `#wpcontent { padding-left: 0 }` which its React layout compensates
     * for, but our non-Wireframe subpages need the standard 20px back.
     *
     * Remove when upstream fix lands: https://github.com/tdrayson/wp-wireframe/issues/6
     */
    public static function subpage_padding_fix(): void {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        $subpages = ['meta-conductor_page_meta-conductor-conversion', 'meta-conductor_page_meta-conductor-diagnostics'];
        if (in_array($screen->id, $subpages, true)) {
            echo '<style>.wireframe-admin #wpcontent { padding-left: 20px; }</style>' . "\n";
        }
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
        if (!class_exists('BWS_Conversion_UI') || !class_exists('BWS_Taxonomy_Manager')) {
            wp_die(esc_html__('Conversion components unavailable.', 'bws-meta-manager'));
        }

        $plugin             = BWS_Taxonomy_Manager::get_instance();
        $conversion_manager = method_exists($plugin, 'get_conversion_manager') ? $plugin->get_conversion_manager() : null;

        if (!$conversion_manager) {
            echo '<div class="wrap"><h1>' . esc_html__('Data Conversion', 'bws-meta-manager') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Conversion manager not initialized.', 'bws-meta-manager') . '</p></div>';
            echo '</div>';
            return;
        }

        $conversion_ui = new BWS_Conversion_UI(
            $conversion_manager->get_field_mapper(),
            $conversion_manager->get_data_processor(),
            $conversion_manager->get_preview_system()
        );

        $conversion_ui->render_page();
    }

    /**
     * Boot Wireframe\App with the assembled config.
     */
    public static function boot(): void {
        if (!class_exists(\Wireframe\App::class)) {
            return;
        }

        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/admin/config/class-wireframe-config.php';

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
