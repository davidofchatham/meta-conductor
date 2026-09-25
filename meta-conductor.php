<?php
/**
 * Plugin Name: Meta Conductor
 * Plugin URI: https://github.com/davidofchatham/meta-conductor
 * Description: Unified meta and taxonomy management with hierarchical inheritance, entity relationships, and intelligent automation
 * Version: 0.9.0
 * Author: David Mitchell (Bridge Web Solutions) and Claude AI
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: meta-conductor
 * Requires PHP: 8.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('META_CONDUCTOR_VERSION', '0.9.0');
define('META_CONDUCTOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('META_CONDUCTOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// PSR-4 autoloader base path (Phase 2a). Same value as
// META_CONDUCTOR_PLUGIN_DIR; the kebab autoloader keys off this.
define('BWS_META_CONDUCTOR_PATH', plugin_dir_path(__FILE__));

/**
 * Check if BWS Meta Manager class exists to prevent conflicts
 */
if (!function_exists('bws_meta_manager_init')) {

    /**
     * Initialize the BWS Meta Manager
     */
    function bws_meta_manager_init() {
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            add_action('admin_notices', 'bws_meta_manager_php_version_notice');
            return;
        }

        // Composer autoloader (WP Wireframe + rakit/validation)
        if (file_exists(META_CONDUCTOR_PLUGIN_DIR . 'vendor/autoload.php')) {
            require_once META_CONDUCTOR_PLUGIN_DIR . 'vendor/autoload.php';
        }

        // PSR-4 autoloader for plugin classes (BWS\MetaConductor\). Loaded after
        // the composer autoloader so namespaced classes that extend or implement
        // vendor types (e.g. Wireframe) resolve. Replaces the former manual
        // require_once chain — every includes/ class is now autoloaded on demand.
        require_once BWS_META_CONDUCTOR_PATH . 'autoload.php';

        // Plugin Update Checker — pulls updates from public GitHub releases.
        // Matches the release ZIP attached by .github/workflows/release.yml on each
        // `v*` tag. Slug must equal the installed plugin folder (meta-conductor).
        if (file_exists(META_CONDUCTOR_PLUGIN_DIR . 'libs/plugin-update-checker/load-v5p7.php')) {
            require_once META_CONDUCTOR_PLUGIN_DIR . 'libs/plugin-update-checker/load-v5p7.php';

            $bws_mc_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/davidofchatham/meta-conductor/',
                __FILE__,
                'meta-conductor'
            );

            // Use GitHub Releases (not branch tips) and the attached ZIP asset.
            // Asset filename is versioned (meta-conductor-X.Y.Z.zip), so match by
            // regex rather than relying on PUC's exact-name auto-picker.
            $bws_mc_update_checker->getVcsApi()->enableReleaseAssets('/meta-conductor-[\d.]+\.zip/');
        }

        // Wireframe bootstrap (Phase 2c pilot — runs alongside legacy UI until verified).
        // Must register on both admin requests (for menu) and REST requests (for save endpoint),
        // so no is_admin() gate. Classes autoloaded via autoload.php (no manual require).
        if (class_exists(\Wireframe\App::class)) {
            \BWS\MetaConductor\Admin\WireframeBootstrap::init();

            // Diagnostics subpage. Dev sections gated on WP_DEBUG; future
            // user-level sections gated on filter `bws_meta_conductor_show_diagnostics`.
            if (is_admin()) {
                \BWS\MetaConductor\Admin\Diagnostics::init();
            }
        }

        // Initialize the plugin. All includes/ classes resolve through the PSR-4
        // autoloader registered above — storage, core, handlers, and the main
        // class are pulled in on first reference.
        \BWS\MetaConductor\TaxonomyManager::get_instance();
    }

    /**
     * Display PHP version notice
     */
    function bws_meta_manager_php_version_notice() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Meta Conductor requires PHP 8.1 or higher. Please update your PHP version.', 'meta-conductor');
        echo '</p></div>';
    }

    // Initialize the plugin
    add_action('plugins_loaded', 'bws_meta_manager_init');

    // Legacy function name for backward compatibility
    function bws_taxonomy_manager_init() {
        bws_meta_manager_init();
    }
    
    /**
     * Plugin activation hook
     */
	function bws_taxonomy_manager_activate() {
		// Check PHP version
		if (version_compare(PHP_VERSION, '8.1', '<')) {
			deactivate_plugins(plugin_basename(__FILE__));
			wp_die(
				__('Meta Conductor requires PHP 8.1 or higher. Please update your PHP version.', 'meta-conductor'),
				__('Plugin Activation Error', 'meta-conductor'),
				array('back_link' => true)
			);
		}
		
		// Seed default options. Key must match BWS_Option_Rule_Storage::OPTION_NAME.
		// Hard-coded literal because storage class isn't loaded during activation hook.
		if (!get_option('bws_meta_conductor_settings')) {
			add_option('bws_meta_conductor_settings', array(
				'hierarchical_rules' => array(),
				'propagation_rules' => array(),
				'related_rules' => array(),
				'time_based_rules' => array(),
				'related_post_terms_rules' => array(),
				'hierarchical_level_restriction_rules' => array(),
				'title_slug_rules' => array(),
				'conflict_handling' => array(),
			));
		}

		// Clean up legacy option keys from old dev builds. Nothing has shipped
		// to a deployment yet, so no data migration is needed.
		delete_option('bws_taxonomy_manager_settings');
		delete_option('bws_taxonomy_manager_version');
		
		// Schedule cleanup for expired time-based rules
		if (!wp_next_scheduled('bws_taxonomy_manager_cleanup')) {
			wp_schedule_event(time(), 'daily', 'bws_taxonomy_manager_cleanup');
		}
		
		// Set activation flag for welcome screen
		set_transient('bws_taxonomy_manager_activated', true, 30);
		
		// Flush rewrite rules
		flush_rewrite_rules();
	}
    
    /**
     * Plugin deactivation hook
     */
	function bws_taxonomy_manager_deactivate() {
		// Clear scheduled events
		wp_clear_scheduled_hook('bws_taxonomy_manager_cleanup');
		
		// Clear any transients
		delete_transient('bws_taxonomy_manager_activated');
		
		// Optional: Clear term level cache
		bws_taxonomy_manager_clear_caches();
		
		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin uninstall hook (for complete removal)
	 */
	function bws_taxonomy_manager_uninstall() {
		// Remove all options
		delete_option('bws_meta_conductor_settings');
		delete_option('bws_meta_conductor_version');
		delete_option('bws_title_slug_rule_status');
		delete_option('bws_taxonomy_manager_settings'); // legacy
		delete_option('bws_taxonomy_manager_version');  // legacy
		
		// Remove any transients
		delete_transient('bws_taxonomy_manager_activated');
		
		// Clear all scheduled events
		wp_clear_scheduled_hook('bws_taxonomy_manager_cleanup');
		
		// Remove database tables if they exist
		bws_meta_conductor_drop_unused_tables();
		
		// Clear all caches
		bws_taxonomy_manager_clear_caches();
	}
	
	/**
	 * Clear all plugin caches
	 */
	function bws_taxonomy_manager_clear_caches() {
		// Clear term level cache
		wp_cache_delete('bws_term_levels', 'bws_taxonomy_manager');
		
		// Clear any other plugin-specific caches
		wp_cache_flush_group('bws_taxonomy_manager');
	}
	
	/**
	 * Check for plugin updates and migrations.
	 *
	 * Tracks the installed version under `bws_meta_conductor_version`. Add
	 * version_compare branches here when shipping schema changes.
	 */
	function bws_taxonomy_manager_check_version() {
		$current_version = get_option('bws_meta_conductor_version');

		if ($current_version !== META_CONDUCTOR_VERSION) {
			// The Data Conversion page was deleted with the Apply page's arrival.
			// Its hourly cleanup event would otherwise keep firing into a hook
			// nothing listens on. Idempotent.
			wp_clear_scheduled_hook('bws_meta_manager_conversion_cleanup');

			// No table the plugin ever created has a reader or writer left. Idempotent.
			bws_meta_conductor_drop_unused_tables();

			// The General tab's bulk-actions toggle was deleted: nothing ever
			// read it. Drop its stored value. Idempotent.
			$settings = get_option('bws_meta_conductor_settings');
			if (is_array($settings) && array_key_exists('manual_processing_enabled', $settings)) {
				unset($settings['manual_processing_enabled']);
				update_option('bws_meta_conductor_settings', $settings);
			}

			// Title/slug's per-rule status record was deleted: nothing read it, and
			// it was keyed on a positional id that a reorder repoints. Idempotent.
			delete_option('bws_title_slug_rule_status');

			update_option('bws_meta_conductor_version', META_CONDUCTOR_VERSION);
			bws_taxonomy_manager_clear_caches();
		}
	}

	/**
	 * Drop every table the plugin has ever created.
	 *
	 * None has a reader or writer left: the conversion tool's scratch tables
	 * went with that tool, the run log with the deleted rule engine (#26), and
	 * the relationship log and batch queue never had either. Legacy names are
	 * included so an install from any past version is left clean. Called from
	 * the upgrade routine and from uninstall.
	 */
	function bws_meta_conductor_drop_unused_tables() {
		global $wpdb;

		$tables = [
			'bws_acf_conversion_preview',
			'bws_acf_conversion_sessions',
			'bws_meta_conductor_log',
			'bws_meta_manager_log',
			'bws_taxonomy_manager_log',
			'bws_relationship_log',
			'bws_batch_queue',
		];
		foreach ($tables as $t) {
			$table_name = $wpdb->prefix . $t;
			$wpdb->query("DROP TABLE IF EXISTS `$table_name`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Register activation/deactivation hooks
	 */
	register_activation_hook(__FILE__, 'bws_taxonomy_manager_activate');
	register_deactivation_hook(__FILE__, 'bws_taxonomy_manager_deactivate');
	
	// For uninstall, use separate file as per WordPress standards
	// Create uninstall.php file with the uninstall function
	
	/**
	 * Check version on admin init
	 */
	add_action('admin_init', 'bws_taxonomy_manager_check_version');
	
	/**
	 * Show admin notice after activation
	 */
	add_action('admin_notices', function() {
		if (get_transient('bws_taxonomy_manager_activated')) {
			delete_transient('bws_taxonomy_manager_activated');
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<strong><?php esc_html_e('Meta Conductor', 'meta-conductor'); ?></strong>
					<?php esc_html_e('has been activated successfully!', 'meta-conductor'); ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=meta-conductor')); ?>" class="button button-primary" style="margin-left: 10px;">
						<?php esc_html_e('Configure Rules', 'meta-conductor'); ?>
					</a>
				</p>
			</div>
			<?php
		}
	});
	
	/**
	 * Add action links to plugin page
	 */
	add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
		$settings_link = '<a href="' . esc_url(admin_url('admin.php?page=meta-conductor')) . '">' . esc_html__('Settings', 'meta-conductor') . '</a>';
		array_unshift($links, $settings_link);

		return $links;
	});
	
	/**
	 * Add plugin row meta
	 */
	add_filter('plugin_row_meta', function($plugin_meta, $plugin_file) {
		if (plugin_basename(__FILE__) === $plugin_file) {
			$plugin_meta[] = '<a href="https://github.com/davidofchatham/meta-conductor" target="_blank">' . esc_html__('GitHub', 'meta-conductor') . '</a>';
		}
		return $plugin_meta;
	}, 10, 2);
	
	/**
	 * Check system requirements on activation
	 */
	function bws_taxonomy_manager_check_requirements() {
		$errors = array();
		
		// Check PHP version
		if (version_compare(PHP_VERSION, '8.1', '<')) {
			$errors[] = sprintf(
				__('Meta Conductor requires PHP 8.1 or higher. You are running PHP %s.', 'meta-conductor'),
				PHP_VERSION
			);
		}
		
		// Check WordPress version
		if (version_compare(get_bloginfo('version'), '5.0', '<')) {
			$errors[] = sprintf(
				__('Meta Conductor requires WordPress 5.0 or higher. You are running WordPress %s.', 'meta-conductor'),
				get_bloginfo('version')
			);
		}
		
		// Warn about missing recommended plugins
		$warnings = array();
		
		if (!function_exists('get_field')) {
			$warnings[] = __('ACF Pro is not active. Related post terms functionality will be limited.', 'meta-conductor');
		}
		
		if (!class_exists('ACP\\Plugin')) {
			$warnings[] = __('Admin Columns Pro is not active. Quick edit integration will not be available.', 'meta-conductor');
		}
		
		// Display errors and warnings
		if (!empty($errors)) {
			$error_message = '<h3>' . __('Meta Conductor Requirements Not Met', 'meta-conductor') . '</h3>';
			$error_message .= '<ul><li>' . implode('</li><li>', $errors) . '</li></ul>';
			
			wp_die($error_message, __('Plugin Activation Error', 'meta-conductor'), array('back_link' => true));
		}
		
		if (!empty($warnings) && is_admin()) {
			set_transient('bws_taxonomy_manager_warnings', $warnings, 300); // 5 minutes
		}
	}
	
	/**
	 * Show requirement warnings
	 */
	add_action('admin_notices', function() {
		$warnings = get_transient('bws_taxonomy_manager_warnings');
		
		if ($warnings) {
			delete_transient('bws_taxonomy_manager_warnings');
			?>
			<div class="notice notice-warning is-dismissible">
				<h3><?php _e('Meta Conductor Recommendations', 'meta-conductor'); ?></h3>
				<ul>
					<?php foreach ($warnings as $warning): ?>
						<li><?php echo esc_html($warning); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	});
	
	/**
	 * Add system check to activation
	 */
	add_action('activate_' . plugin_basename(__FILE__), 'bws_taxonomy_manager_check_requirements');
}
