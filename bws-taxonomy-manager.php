<?php
/**
 * Plugin Name: BWS Meta Manager
 * Description: Unified meta and taxonomy management with hierarchical inheritance, entity relationships, data conversion, and intelligent automation
 * Version: 2.0.0
 * Author: Bridge Web Solutions
 * Author URI: https://bridgewebsolutions.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: bws-meta-manager
 * Requires PHP: 8.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BWS_META_MANAGER_VERSION', '2.0.0');
define('BWS_META_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BWS_META_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Backward compatibility constants
define('BWS_TAX_MANAGER_VERSION', '2.0.0'); // For legacy code
define('BWS_TAX_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BWS_TAX_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));

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
        if (file_exists(BWS_META_MANAGER_PLUGIN_DIR . 'vendor/autoload.php')) {
            require_once BWS_META_MANAGER_PLUGIN_DIR . 'vendor/autoload.php';
        }

        // Wireframe bootstrap (Phase 2c pilot — runs alongside legacy UI until verified).
        // Must register on both admin requests (for menu) and REST requests (for save endpoint),
        // so no is_admin() gate.
        if (class_exists(\Wireframe\App::class)) {
            require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/admin/class-bws-wireframe-bootstrap.php';
            BWS_Wireframe_Bootstrap::init();

            // Phase 2c debug page (REMOVE before merge).
            if (is_admin()) {
                require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/admin/class-bws-wireframe-debug.php';
                BWS_Wireframe_Debug::init();
            }
        }

        // Load storage abstraction layer (v2.0 - prepares for CPT migration)
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/abstracts/interface-bws-rule-storage.php';
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/storage/class-bws-option-rule-storage.php';
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/storage/class-bws-storage-factory.php';

        // Load core unified framework classes
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/core/class-bws-entity.php';
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/core/class-bws-condition-evaluator.php';
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/core/class-bws-action-executor.php';
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/core/class-bws-rule-engine.php';

        // Load unified handler base
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/abstracts/class-bws-unified-handler-base.php';

        // Load legacy handler base (for backward compatibility)
        if (file_exists(BWS_META_MANAGER_PLUGIN_DIR . 'includes/abstracts/class-bws-handler-base.php')) {
            require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/abstracts/class-bws-handler-base.php';
        }

        // Load the main class
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/class-bws-taxonomy-manager.php';

        // Initialize the plugin
        BWS_Taxonomy_Manager::get_instance();
    }

    /**
     * Display PHP version notice
     */
    function bws_meta_manager_php_version_notice() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('BWS Meta Manager requires PHP 8.1 or higher. Please update your PHP version.', 'bws-meta-manager');
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
				__('BWS Taxonomy Manager requires PHP 8.1 or higher. Please update your PHP version.', 'bws-taxonomy-manager'),
				__('Plugin Activation Error', 'bws-taxonomy-manager'),
				array('back_link' => true)
			);
		}
		
		// Create default options with new rule types
		if (!get_option('bws_taxonomy_manager_settings')) {
			add_option('bws_taxonomy_manager_settings', array(
				'hierarchical_rules' => array(),
				'propagation_rules' => array(),
				'related_rules' => array(),
				'time_based_rules' => array(),
				// NEW rule types
				'related_post_terms_rules' => array(),
				'hierarchical_level_restriction_rules' => array(),
				'title_slug_rules' => array(),
				'conflict_handling' => array(),
				'manual_processing_enabled' => true
			));
		} else {
			// Update existing settings to include new rule types
			$existing_settings = get_option('bws_taxonomy_manager_settings');
			
			// Add new rule types if they don't exist
			if (!isset($existing_settings['related_post_terms_rules'])) {
				$existing_settings['related_post_terms_rules'] = array();
			}
			
			if (!isset($existing_settings['hierarchical_level_restriction_rules'])) {
				$existing_settings['hierarchical_level_restriction_rules'] = array();
			}

			if (!isset($existing_settings['title_slug_rules'])) {
				$existing_settings['title_slug_rules'] = array();
			}

			update_option('bws_taxonomy_manager_settings', $existing_settings);
		}
		
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
		delete_option('bws_taxonomy_manager_settings');
		
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

		// Enhanced log table with entity support (v2.0)
		$log_table = $wpdb->prefix . 'bws_meta_manager_log';
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

		// Legacy log table (for backward compatibility - will be migrated)
		$legacy_log_table = $wpdb->prefix . 'bws_taxonomy_manager_log';
		$legacy_log_sql = "CREATE TABLE $legacy_log_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			post_id bigint(20) NOT NULL,
			rule_type varchar(50) NOT NULL,
			rule_data text,
			taxonomy varchar(100) NOT NULL,
			terms_applied text,
			applied_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY rule_type (rule_type),
			KEY taxonomy (taxonomy),
			KEY applied_at (applied_at)
		) $charset_collate;";

		// Execute dbDelta to create tables
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($log_sql);
		dbDelta($preview_sql);
		dbDelta($sessions_sql);
		dbDelta($relationship_sql);
		dbDelta($queue_sql);
		dbDelta($legacy_log_sql);

		// Verify all tables were created successfully
		$required_tables = [
			'bws_meta_manager_log' => 'Enhanced log table with entity support',
			'bws_acf_conversion_preview' => 'ACF conversion preview data',
			'bws_acf_conversion_sessions' => 'Conversion session tracking',
			'bws_relationship_log' => 'Relationship tracking',
			'bws_batch_queue' => 'Background job queue',
			'bws_taxonomy_manager_log' => 'Legacy log table (backward compatibility)',
		];

		foreach ($required_tables as $table => $description) {
			$table_name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
				$error_msg = sprintf('Failed to create table: %s (%s)', $table, $description);
				$errors[] = $error_msg;
				error_log('BWS Meta Manager: ' . $error_msg);
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
		echo '<strong>' . esc_html__('BWS Meta Manager: Database table creation failed', 'bws-meta-manager') . '</strong><br>';
		echo esc_html__('The following tables could not be created. Some features may not work correctly:', 'bws-meta-manager') . '<br>';
		echo '<ul style="list-style: disc; margin-left: 20px;">';
		foreach ($errors as $error) {
			echo '<li>' . esc_html($error) . '</li>';
		}
		echo '</ul>';
		echo esc_html__('Please check your database permissions and try reactivating the plugin.', 'bws-meta-manager');
		echo '</p></div>';

		// Clear the transient after displaying
		delete_transient('bws_meta_manager_table_errors');
	}
	
	/**
	 * Drop database tables
	 */
	function bws_taxonomy_manager_drop_tables() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'bws_taxonomy_manager_log';
		$wpdb->query("DROP TABLE IF EXISTS $table_name");
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
	 * Check for plugin updates and migrations
	 */
	function bws_taxonomy_manager_check_version() {
		$current_version = get_option('bws_taxonomy_manager_version');
		
		if ($current_version !== BWS_TAX_MANAGER_VERSION) {
			bws_taxonomy_manager_upgrade($current_version);
			update_option('bws_taxonomy_manager_version', BWS_TAX_MANAGER_VERSION);
		}
	}
	
	/**
	 * Handle plugin upgrades
	 */
	function bws_taxonomy_manager_upgrade($from_version) {
		// Migration logic for different versions
		
		if (version_compare($from_version, '1.0.0', '<')) {
			// Initial version - no migration needed
			return;
		}
		
		// Example migration for future versions
		if (version_compare($from_version, '1.1.0', '<')) {
			// Add new rule types to existing settings
			$settings = get_option('bws_taxonomy_manager_settings', array());
			
			if (!isset($settings['related_post_terms_rules'])) {
				$settings['related_post_terms_rules'] = array();
			}
			
			if (!isset($settings['hierarchical_level_restriction_rules'])) {
				$settings['hierarchical_level_restriction_rules'] = array();
			}
			
			update_option('bws_taxonomy_manager_settings', $settings);
		}
		
		// Clear caches after upgrade
		bws_taxonomy_manager_clear_caches();
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
					<strong><?php _e('BWS Taxonomy Manager', 'bws-taxonomy-manager'); ?></strong>
					<?php _e('has been activated successfully!', 'bws-taxonomy-manager'); ?>
					<a href="<?php echo admin_url('options-general.php?page=bws-taxonomy-manager'); ?>" class="button button-primary" style="margin-left: 10px;">
						<?php _e('Configure Rules', 'bws-taxonomy-manager'); ?>
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
		$settings_link = '<a href="' . admin_url('options-general.php?page=bws-taxonomy-manager') . '">' . __('Settings', 'bws-taxonomy-manager') . '</a>';
		array_unshift($links, $settings_link);
		
		$docs_link = '<a href="https://bridgewebsolutions.com/docs/bws-taxonomy-manager/" target="_blank">' . __('Documentation', 'bws-taxonomy-manager') . '</a>';
		array_push($links, $docs_link);
		
		return $links;
	});
	
	/**
	 * Add plugin row meta
	 */
	add_filter('plugin_row_meta', function($plugin_meta, $plugin_file) {
		if (plugin_basename(__FILE__) === $plugin_file) {
			$plugin_meta[] = '<a href="https://bridgewebsolutions.com/support/" target="_blank">' . __('Support', 'bws-taxonomy-manager') . '</a>';
			$plugin_meta[] = '<a href="https://github.com/bridgewebsolutions/bws-taxonomy-manager" target="_blank">' . __('GitHub', 'bws-taxonomy-manager') . '</a>';
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
				__('BWS Taxonomy Manager requires PHP 8.1 or higher. You are running PHP %s.', 'bws-taxonomy-manager'),
				PHP_VERSION
			);
		}
		
		// Check WordPress version
		if (version_compare(get_bloginfo('version'), '5.0', '<')) {
			$errors[] = sprintf(
				__('BWS Taxonomy Manager requires WordPress 5.0 or higher. You are running WordPress %s.', 'bws-taxonomy-manager'),
				get_bloginfo('version')
			);
		}
		
		// Warn about missing recommended plugins
		$warnings = array();
		
		if (!function_exists('get_field')) {
			$warnings[] = __('ACF Pro is not active. Related post terms functionality will be limited.', 'bws-taxonomy-manager');
		}
		
		if (!class_exists('ACP\\Plugin')) {
			$warnings[] = __('Admin Columns Pro is not active. Quick edit integration will not be available.', 'bws-taxonomy-manager');
		}
		
		// Display errors and warnings
		if (!empty($errors)) {
			$error_message = '<h3>' . __('BWS Taxonomy Manager Requirements Not Met', 'bws-taxonomy-manager') . '</h3>';
			$error_message .= '<ul><li>' . implode('</li><li>', $errors) . '</li></ul>';
			
			wp_die($error_message, __('Plugin Activation Error', 'bws-taxonomy-manager'), array('back_link' => true));
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
				<h3><?php _e('BWS Taxonomy Manager Recommendations', 'bws-taxonomy-manager'); ?></h3>
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
