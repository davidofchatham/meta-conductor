<?php
/**
 * Plugin Name: Meta Conductor
 * Plugin URI: https://github.com/davidofchatham/meta-conductor
 * Description: Unified meta and taxonomy management with hierarchical inheritance, entity relationships, data conversion, and intelligent automation
 * Version: 0.6.3
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
define('META_CONDUCTOR_VERSION', '0.6.3');
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
				'manual_processing_enabled' => true,
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
		
		// Create database tables if needed (for future extensions)
		bws_taxonomy_manager_create_tables();
		
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
		delete_option('bws_taxonomy_manager_settings'); // legacy
		delete_option('bws_taxonomy_manager_version');  // legacy
		
		// Remove any transients
		delete_transient('bws_taxonomy_manager_activated');
		
		// Clear all scheduled events
		wp_clear_scheduled_hook('bws_taxonomy_manager_cleanup');
		
		// Remove database tables if they exist
		bws_taxonomy_manager_drop_tables();
		
		// Clear all caches
		bws_taxonomy_manager_clear_caches();
	}
	
	/**
	 * Create database tables for unified system
	 *
	 * @return array Array with 'success' bool and 'errors' array if any failures
	 */
	function bws_taxonomy_manager_create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$errors = [];

		// Enhanced log table with entity support (unified-framework layer)
		$log_table = $wpdb->prefix . 'bws_meta_conductor_log';
		$log_sql = "CREATE TABLE $log_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			rule_id varchar(100) NOT NULL,
			handler_type varchar(50) NOT NULL,
			source_entity_type varchar(20) NOT NULL,
			source_entity_id bigint(20) NOT NULL,
			target_entity_type varchar(20) NOT NULL,
			target_entity_id bigint(20) NOT NULL,
			action_type varchar(50) NOT NULL,
			action_data text,
			result varchar(20) NOT NULL,
			applied_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY rule_id (rule_id),
			KEY handler_type (handler_type),
			KEY source_entity (source_entity_type, source_entity_id),
			KEY target_entity (target_entity_type, target_entity_id),
			KEY applied_at (applied_at)
		) $charset_collate;";

		// ACF conversion preview table
		$preview_table = $wpdb->prefix . 'bws_acf_conversion_preview';
		$preview_sql = "CREATE TABLE $preview_table (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			session_id varchar(32) NOT NULL,
			post_id bigint(20) NOT NULL,
			field_key varchar(255) NOT NULL,
			old_value longtext,
			new_value longtext,
			conversion_type varchar(50) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// ACF conversion sessions table
		$sessions_table = $wpdb->prefix . 'bws_acf_conversion_sessions';
		$sessions_sql = "CREATE TABLE $sessions_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			session_id varchar(32) NOT NULL,
			session_data longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_id (session_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// Relationship tracking table
		$relationship_table = $wpdb->prefix . 'bws_relationship_log';
		$relationship_sql = "CREATE TABLE $relationship_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			post_id bigint(20) NOT NULL,
			parent_id bigint(20),
			child_ids text,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY parent_id (parent_id),
			KEY updated_at (updated_at)
		) $charset_collate;";

		// Batch queue table
		$queue_table = $wpdb->prefix . 'bws_batch_queue';
		$queue_sql = "CREATE TABLE $queue_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			job_id varchar(32) NOT NULL,
			job_type varchar(50) NOT NULL,
			job_data longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			progress int(11) DEFAULT 0,
			total int(11) DEFAULT 0,
			created_at datetime NOT NULL,
			started_at datetime,
			completed_at datetime,
			PRIMARY KEY (id),
			UNIQUE KEY job_id (job_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		// Execute dbDelta to create tables
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($log_sql);
		dbDelta($preview_sql);
		dbDelta($sessions_sql);
		dbDelta($relationship_sql);
		dbDelta($queue_sql);

		// Verify all tables were created successfully
		$required_tables = [
			'bws_meta_conductor_log' => 'Enhanced log table with entity support',
			'bws_acf_conversion_preview' => 'ACF conversion preview data',
			'bws_acf_conversion_sessions' => 'Conversion session tracking',
			'bws_relationship_log' => 'Relationship tracking',
			'bws_batch_queue' => 'Background job queue',
		];

		foreach ($required_tables as $table => $description) {
			$table_name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
				$error_msg = sprintf('Failed to create table: %s (%s)', $table, $description);
				$errors[] = $error_msg;
				error_log('Meta Conductor: ' . $error_msg);
			}
		}

		// Show admin notice if there were errors
		if (!empty($errors)) {
			set_transient('bws_meta_manager_table_errors', $errors, 300); // Store for 5 minutes
			add_action('admin_notices', 'bws_meta_manager_table_creation_notice');

			return [
				'success' => false,
				'errors' => $errors,
			];
		}

		return [
			'success' => true,
			'errors' => [],
		];
	}

	/**
	 * Display admin notice if table creation failed
	 */
	function bws_meta_manager_table_creation_notice() {
		$errors = get_transient('bws_meta_manager_table_errors');
		if (!$errors) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>';
		echo '<strong>' . esc_html__('Meta Conductor: Database table creation failed', 'meta-conductor') . '</strong><br>';
		echo esc_html__('The following tables could not be created. Some features may not work correctly:', 'meta-conductor') . '<br>';
		echo '<ul style="list-style: disc; margin-left: 20px;">';
		foreach ($errors as $error) {
			echo '<li>' . esc_html($error) . '</li>';
		}
		echo '</ul>';
		echo esc_html__('Please check your database permissions and try reactivating the plugin.', 'meta-conductor');
		echo '</p></div>';

		// Clear the transient after displaying
		delete_transient('bws_meta_manager_table_errors');
	}
	
	/**
	 * Drop database tables
	 */
	function bws_taxonomy_manager_drop_tables() {
		global $wpdb;

		// Core log table (renamed in 2b) + its legacy predecessor and the
		// pre-2b name, so uninstall leaves nothing behind regardless of which
		// version the table was created under.
		foreach (['bws_meta_conductor_log', 'bws_meta_manager_log', 'bws_taxonomy_manager_log'] as $t) {
			$table_name = $wpdb->prefix . $t;
			$wpdb->query("DROP TABLE IF EXISTS `$table_name`");
		}
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
			// Phase 2b (0.6.3): rename the core log table to the new brand.
			// Idempotent — only renames when the old table exists and the new
			// one does not, so re-runs and fresh installs are both safe. Uses
			// RENAME TABLE to preserve existing log rows.
			bws_meta_conductor_migrate_log_table();

			update_option('bws_meta_conductor_version', META_CONDUCTOR_VERSION);
			bws_taxonomy_manager_clear_caches();
		}
	}

	/**
	 * Rename {prefix}bws_meta_manager_log -> {prefix}bws_meta_conductor_log.
	 *
	 * Guarded so it runs at most once: renames only if the old table is
	 * present and the new name is not yet taken. Preserves all rows.
	 */
	function bws_meta_conductor_migrate_log_table() {
		global $wpdb;

		$old = $wpdb->prefix . 'bws_meta_manager_log';
		$new = $wpdb->prefix . 'bws_meta_conductor_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$old_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old)) === $old;
		$new_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $new)) === $new;

		if ($old_exists && !$new_exists) {
			$wpdb->query("RENAME TABLE `$old` TO `$new`");
		}
		// phpcs:enable
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
