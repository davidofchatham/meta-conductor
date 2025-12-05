<?php
/**
 * Plugin Name: ACF Data Conversion Tool
 * Description: Tool for converting ACF field data, mapping values to taxonomies, and batch processing with dry run capabilities.
 * Version: 1.0.3.3
 * Author: Bridge Web Solutions
 * Author URI: https://bridgewebsolutions.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.0
 * Tested up to: 6.6
 * Requires PHP: 8.1
 * Text Domain: acf-data-conversion
 * Domain Path: /languages
 *
 * @package ACF_Data_Conversion_Tool
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'BWS_ACF_CONVERSION_VERSION', '1.0.3.3' );
define( 'BWS_ACF_CONVERSION_PLUGIN_FILE', __FILE__ );
define( 'BWS_ACF_CONVERSION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BWS_ACF_CONVERSION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BWS_ACF_CONVERSION_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
class BWS_ACF_Data_Conversion_Tool {

    /**
     * Single instance of the plugin
     *
     * @var BWS_ACF_Data_Conversion_Tool
     */
    private static $instance = null;

    /**
     * Plugin components
     *
     * @var array
     */
    private $components = [];

    /**
     * Get single instance of the plugin
     *
     * @return BWS_ACF_Data_Conversion_Tool
     */
    public static function get_instance(): BWS_ACF_Data_Conversion_Tool {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        add_action( 'plugins_loaded', [ $this, 'load_plugin' ] );
        add_action( 'init', [ $this, 'load_textdomain' ] );
        
        // Security and cleanup hooks
        register_activation_hook( BWS_ACF_CONVERSION_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( BWS_ACF_CONVERSION_PLUGIN_FILE, [ $this, 'deactivate' ] );
        register_uninstall_hook( BWS_ACF_CONVERSION_PLUGIN_FILE, [ __CLASS__, 'uninstall' ] );
    }

    /**
     * Load plugin components
     */
    public function load_plugin(): void {
        // Check requirements
        if ( ! $this->check_requirements() ) {
            return;
        }

        // Load plugin components
        $this->load_dependencies();
        $this->init_components();
        $this->setup_admin();

        do_action( 'bws_acf_conversion_loaded' );
    }

    /**
     * Check plugin requirements - FIXED: Removed user capability check
     *
     * @return bool
     */
    private function check_requirements(): bool {
        $errors = [];

        // Check PHP version
        if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
            $errors[] = sprintf(
                /* translators: %s: required PHP version */
                __( 'PHP version %s or higher is required.', 'acf-data-conversion' ),
                '8.1'
            );
        }

        // Check WordPress version
        if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
            $errors[] = sprintf(
                /* translators: %s: required WordPress version */
                __( 'WordPress version %s or higher is required.', 'acf-data-conversion' ),
                '6.0'
            );
        }

        // Check if ACF Pro is active
        if ( ! $this->is_acf_pro_active() ) {
            $errors[] = __( 'Advanced Custom Fields Pro is required and must be activated.', 'acf-data-conversion' );
        }

        // REMOVED: User capability check - this should only be checked when accessing the plugin admin page

        // Display errors if any
        if ( ! empty( $errors ) ) {
            add_action( 'admin_notices', function() use ( $errors ) {
                $this->display_requirements_notice( $errors );
            } );
            return false;
        }

        return true;
    }

    /**
     * Check if ACF Pro is active and compatible
     *
     * @return bool
     */
    private function is_acf_pro_active(): bool {
        if ( ! function_exists( 'acf' ) ) {
            return false;
        }

        // Check for ACF Pro (not just free version)
        if ( ! class_exists( 'ACF_PRO' ) && ! defined( 'ACF_PRO' ) ) {
            return false;
        }

        // Check minimum ACF version
        if ( function_exists( 'acf_get_setting' ) ) {
            $acf_version = acf_get_setting( 'version' );
            if ( version_compare( $acf_version, '6.0', '<' ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Display requirements error notice
     *
     * @param array $errors Array of error messages
     */
    private function display_requirements_notice( array $errors ): void {
        $class = 'notice notice-error';
        $title = __( 'ACF Data Conversion Tool - Requirements Not Met', 'acf-data-conversion' );
        
        echo '<div class="' . esc_attr( $class ) . '">';
        echo '<p><strong>' . esc_html( $title ) . '</strong></p>';
        echo '<ul>';
        foreach ( $errors as $error ) {
            echo '<li>' . esc_html( $error ) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies(): void {
        $dependencies = [
            'includes/class-field-mapper.php',
            'includes/class-data-processor.php',
            'includes/class-preview-system.php',
            'includes/class-admin-interface.php',
        ];

        foreach ( $dependencies as $file ) {
            $file_path = BWS_ACF_CONVERSION_PLUGIN_DIR . $file;
            if ( file_exists( $file_path ) ) {
                require_once $file_path;
            } else {
                wp_die(
                    sprintf(
                        /* translators: %s: file path */
                        esc_html__( 'Required file missing: %s', 'acf-data-conversion' ),
                        esc_html( $file )
                    )
                );
            }
        }
    }

    /**
     * Initialize plugin components
     */
    private function init_components(): void {
        // Initialize components in dependency order
        $this->components['field_mapper'] = new BWS_ACF_Field_Mapper();
        $this->components['data_processor'] = new BWS_ACF_Data_Processor( $this->components['field_mapper'] );
        $this->components['preview_system'] = new BWS_ACF_Preview_System( $this->components['data_processor'] );
        
        // Admin interface depends on all other components
        if ( is_admin() ) {
            $this->components['admin_interface'] = new BWS_ACF_Admin_Interface(
                $this->components['field_mapper'],
                $this->components['data_processor'],
                $this->components['preview_system']
            );
        }
    }

    /**
     * Setup admin functionality
     */
    private function setup_admin(): void {
        if ( ! is_admin() ) {
            return;
        }

        // Add admin menu - WordPress handles capability checking via the manage_options parameter
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        
        // Enqueue admin scripts and styles
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        
		// AJAX handlers for conversion processing
		add_action( 'wp_ajax_bws_acf_conversion_process', [ $this, 'handle_conversion_ajax' ] );
		add_action( 'wp_ajax_bws_acf_conversion_preview', [ $this, 'handle_preview_ajax' ] );
		
		// NEW: AJAX handlers for chunked processing
		add_action( 'wp_ajax_bws_acf_conversion_process_chunk', [ $this, 'handle_chunked_conversion_ajax' ] );
		add_action( 'wp_ajax_bws_acf_conversion_estimate_size', [ $this, 'handle_estimate_size_ajax' ] );
		
		// AJAX handlers for data retrieval
		add_action( 'wp_ajax_bws_acf_conversion_get_fields', [ $this, 'handle_get_fields_ajax' ] );
		add_action( 'wp_ajax_bws_acf_conversion_get_options', [ $this, 'handle_get_options_ajax' ] );
		add_action( 'wp_ajax_bws_acf_conversion_get_taxonomies', [ $this, 'handle_get_taxonomies_ajax' ] );
		add_action( 'wp_ajax_bws_acf_conversion_get_taxonomy_terms', [ $this, 'handle_get_taxonomy_terms_ajax' ] );
		
		// AJAX handlers for utility functions
		add_action( 'wp_ajax_bws_acf_conversion_clear_cache', [ $this, 'handle_clear_cache_ajax' ] );
		add_action( 'wp_ajax_bws_acf_conversion_validate_fields', [ $this, 'handle_validate_fields_ajax' ] );
		add_action( 'wp_ajax_bws_acf_conversion_validate_taxonomy', [ $this, 'handle_validate_taxonomy_ajax' ] );
		
		// Schedule cleanup for sessions
		if ( ! wp_next_scheduled( 'bws_acf_conversion_cleanup_sessions' ) ) {
			wp_schedule_event( time(), 'hourly', 'bws_acf_conversion_cleanup_sessions' );
		}
		add_action( 'bws_acf_conversion_cleanup_sessions', [ $this, 'cleanup_old_sessions' ] );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_management_page(
            __( 'ACF Data Conversion', 'acf-data-conversion' ),
            __( 'ACF Data Conversion', 'acf-data-conversion' ),
            'manage_options', // WordPress handles capability checking automatically
            'acf-data-conversion',
            [ $this, 'display_admin_page' ]
        );
    }

    /**
     * Display admin page - FIXED: Added capability check here where it belongs
     */
    public function display_admin_page(): void {
        // Check user capabilities at the point of access
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__( 'You do not have sufficient permissions to access this page.', 'acf-data-conversion' ),
                esc_html__( 'Insufficient Permissions', 'acf-data-conversion' ),
                [ 'response' => 403 ]
            );
        }

        if ( isset( $this->components['admin_interface'] ) ) {
            $this->components['admin_interface']->display_page();
        }
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets( string $hook ): void {
        // Only load on our admin page
        if ( 'tools_page_acf-data-conversion' !== $hook ) {
            return;
        }

        // Double-check capabilities when enqueueing assets for our page
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Enqueue WordPress admin styles for consistency
        wp_enqueue_style( 'wp-admin' );
        wp_enqueue_style( 'forms' );
        wp_enqueue_style( 'common' );

        // Plugin styles
        wp_enqueue_style(
            'bws-acf-conversion-admin',
            BWS_ACF_CONVERSION_PLUGIN_URL . 'assets/css/admin.css',
            [ 'wp-admin', 'forms' ],
            BWS_ACF_CONVERSION_VERSION
        );

        // Plugin scripts
        wp_enqueue_script(
            'bws-acf-conversion-admin',
            BWS_ACF_CONVERSION_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery', 'wp-util' ],
            BWS_ACF_CONVERSION_VERSION,
            true
        );

        // Localize script for AJAX and translations
        wp_localize_script(
            'bws-acf-conversion-admin',
            'bwsAcfConversion',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'bws_acf_conversion_nonce' ),
                'strings' => [
                    'processing' => __( 'Processing...', 'acf-data-conversion' ),
                    'complete' => __( 'Complete!', 'acf-data-conversion' ),
                    'error' => __( 'Error occurred', 'acf-data-conversion' ),
                    'confirm_conversion' => __( 'Are you sure you want to proceed with this conversion? This action cannot be undone.', 'acf-data-conversion' ),
                    'confirm_preview' => __( 'Generate preview? This will create temporary data for review.', 'acf-data-conversion' ),
                    'skip_unmapped' => __( 'Skip this value', 'acf-data-conversion' ),
                ]
            ]
        );
    }

    /**
     * Handle conversion AJAX request
     */
    public function handle_conversion_ajax(): void {
        $this->verify_ajax_request();
        
        if ( isset( $this->components['admin_interface'] ) ) {
            $this->components['admin_interface']->handle_conversion_ajax();
        }
    }
    
	/**
	 * NEW: Handle chunked conversion AJAX request
	 */
	public function handle_chunked_conversion_ajax(): void {
		$this->verify_ajax_request();
		
		if ( isset( $this->components['admin_interface'] ) ) {
			$this->components['admin_interface']->handle_chunked_conversion_ajax();
		}
	}
	
	/**
	 * NEW: Handle estimate conversion size AJAX request
	 */
	public function handle_estimate_size_ajax(): void {
		$this->verify_ajax_request();
		
		if ( isset( $this->components['admin_interface'] ) ) {
			$this->components['admin_interface']->handle_estimate_conversion_size_ajax();
		}
	}

    /**
     * Handle preview AJAX request
     */
    public function handle_preview_ajax(): void {
        $this->verify_ajax_request();
        
        if ( isset( $this->components['admin_interface'] ) ) {
            $this->components['admin_interface']->handle_preview_ajax();
        }
    }

    /**
     * Handle get fields AJAX request
     */
    public function handle_get_fields_ajax(): void {
        $this->verify_ajax_request();
        
        if ( isset( $this->components['admin_interface'] ) ) {
            $this->components['admin_interface']->handle_get_fields_ajax();
        }
    }

    /**
     * Handle get options AJAX request
     */
    public function handle_get_options_ajax(): void {
        $this->verify_ajax_request();
        
        if ( isset( $this->components['admin_interface'] ) ) {
            $this->components['admin_interface']->handle_get_options_ajax();
        }
    }

    /**
     * Handle get taxonomies AJAX request
     */
    public function handle_get_taxonomies_ajax(): void {
        $this->verify_ajax_request();
        
        if ( isset( $this->components['admin_interface'] ) ) {
            $this->components['admin_interface']->handle_get_taxonomies_ajax();
        }
    }

    /**
     * Handle get taxonomy terms AJAX request - NEW
     */
    public function handle_get_taxonomy_terms_ajax(): void {
        $this->verify_ajax_request();
        
        if ( isset( $this->components['admin_interface'] ) ) {
            $this->components['admin_interface']->handle_get_taxonomy_terms_ajax();
        }
    }
    
    /**
     * Handle clear cache AJAX request
     */
    public function handle_clear_cache_ajax(): void {
        $this->verify_ajax_request();
        
        if ( isset( $this->components['field_mapper'] ) ) {
            $this->components['field_mapper']->clear_cache();
            wp_send_json_success( [ 'message' => __( 'Cache cleared successfully.', 'acf-data-conversion' ) ] );
        }
        
        wp_send_json_error( [ 'message' => __( 'Failed to clear cache.', 'acf-data-conversion' ) ] );
    }

    /**
     * Handle validate fields AJAX request
     */
    public function handle_validate_fields_ajax(): void {
        $this->verify_ajax_request();
        
        $source_field = sanitize_text_field( $_POST['source_field'] ?? '' );
        $target_field = sanitize_text_field( $_POST['target_field'] ?? '' );
        
        if ( ! $source_field || ! $target_field ) {
            wp_send_json_error( __( 'Source and target fields are required.', 'acf-data-conversion' ) );
        }
        
        if ( isset( $this->components['field_mapper'] ) ) {
            $validation = $this->components['field_mapper']->validate_field_conversion( $source_field, $target_field );
            wp_send_json_success( $validation );
        }
        
        wp_send_json_error( __( 'Unable to validate fields.', 'acf-data-conversion' ) );
    }

    /**
     * Handle validate taxonomy AJAX request
     */
    public function handle_validate_taxonomy_ajax(): void {
        $this->verify_ajax_request();
        
        $source_field = sanitize_text_field( $_POST['source_field'] ?? '' );
        $target_taxonomy = sanitize_text_field( $_POST['target_taxonomy'] ?? '' );
        
        if ( ! $source_field || ! $target_taxonomy ) {
            wp_send_json_error( __( 'Source field and target taxonomy are required.', 'acf-data-conversion' ) );
        }
        
        if ( isset( $this->components['field_mapper'] ) ) {
            $validation = $this->components['field_mapper']->validate_taxonomy_mapping( $source_field, $target_taxonomy );
            wp_send_json_success( $validation );
        }
        
        wp_send_json_error( __( 'Unable to validate taxonomy mapping.', 'acf-data-conversion' ) );
    }

    /**
     * Verify AJAX request security
     */
    private function verify_ajax_request(): void {
        // Verify nonce
        if ( ! check_ajax_referer( 'bws_acf_conversion_nonce', 'nonce', false ) ) {
            wp_die( 
                esc_html__( 'Security check failed.', 'acf-data-conversion' ),
                esc_html__( 'Forbidden', 'acf-data-conversion' ),
                [ 'response' => 403 ]
            );
        }

        // Verify capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to perform this action.', 'acf-data-conversion' ),
                esc_html__( 'Forbidden', 'acf-data-conversion' ),
                [ 'response' => 403 ]
            );
        }
    }
    
	/**
	 * NEW: Cleanup old conversion sessions
	 */
	public function cleanup_old_sessions(): void {
		if ( isset( $this->components['data_processor'] ) ) {
			$this->components['data_processor']->cleanup_old_sessions();
		}
	}

    /**
     * Load plugin textdomain
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'acf-data-conversion',
            false,
            dirname( BWS_ACF_CONVERSION_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Plugin activation - FIXED: Enhanced capability check for activation only
     */
    public function activate(): void {
        // Check if current user can activate plugins (only relevant during activation)
        if ( ! current_user_can( 'activate_plugins' ) ) {
            deactivate_plugins( BWS_ACF_CONVERSION_PLUGIN_BASENAME );
            wp_die(
                esc_html__( 'You do not have permission to activate this plugin.', 'acf-data-conversion' ),
                esc_html__( 'Plugin Activation Error', 'acf-data-conversion' ),
                [ 'back_link' => true ]
            );
        }

        // Check requirements on activation (but not user manage_options capability)
        if ( ! $this->check_requirements() ) {
            deactivate_plugins( BWS_ACF_CONVERSION_PLUGIN_BASENAME );
            wp_die(
                esc_html__( 'Plugin activation failed due to unmet requirements.', 'acf-data-conversion' ),
                esc_html__( 'Plugin Activation Error', 'acf-data-conversion' ),
                [ 'back_link' => true ]
            );
        }

        // Set activation flag for future use
        update_option( 'bws_acf_conversion_activated', time() );
        
        // Create necessary database tables if needed
        $this->create_database_tables();
        
        // Clear any existing caches
        $this->clear_plugin_caches();
    }

    /**
     * Plugin deactivation
     */
	public function deactivate(): void {
		// Clear scheduled events
		wp_clear_scheduled_hook( 'bws_acf_conversion_cleanup' );
		wp_clear_scheduled_hook( 'bws_acf_conversion_cleanup_sessions' ); // NEW
		
		// Clear caches
		$this->clear_plugin_caches();
		
		// Remove activation flag
		delete_option( 'bws_acf_conversion_activated' );
	}

    /**
     * Plugin uninstallation
     */
	public static function uninstall(): void {
		// Clean up options
		delete_option( 'bws_acf_conversion_activated' );
		delete_option( 'bws_acf_conversion_settings' );
		
		// Clean up transients
		delete_transient( 'bws_acf_conversion_field_groups' );
		delete_transient( 'bws_acf_conversion_fields_by_type' );
		delete_transient( 'bws_acf_conversion_taxonomies' );
		
		// Clean up temporary tables
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bws_acf_conversion_preview" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bws_acf_conversion_sessions" ); // NEW
		
		// Clear scheduled events
		wp_clear_scheduled_hook( 'bws_acf_conversion_cleanup' );
		wp_clear_scheduled_hook( 'bws_acf_conversion_cleanup_sessions' ); // NEW
		
		// Clear any remaining caches
		wp_cache_flush();
	}

    /**
     * Create necessary database tables
     */
	private function create_database_tables(): void {
		global $wpdb;
	
		$charset_collate = $wpdb->get_charset_collate();
	
		// Table for storing preview data
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
	
		// NEW: Table for storing conversion sessions
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
	
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $preview_sql );
		dbDelta( $sessions_sql );
	}

    /**
     * Clear plugin caches
     */
    private function clear_plugin_caches(): void {
        // Clear transients
        delete_transient( 'bws_acf_conversion_field_groups' );
        delete_transient( 'bws_acf_conversion_fields_by_type' );
        delete_transient( 'bws_acf_conversion_taxonomies' );
        
        // Clear taxonomy-specific caches
        $taxonomies = get_taxonomies();
        foreach ( $taxonomies as $taxonomy ) {
            delete_transient( "bws_acf_conversion_taxonomy_fields_{$taxonomy}" );
        }
        
        // Clear object cache if available
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
    }

    /**
     * Get plugin component
     *
     * @param string $component Component name
     * @return object|null
     */
    public function get_component( string $component ) {
        return $this->components[ $component ] ?? null;
    }
}

/**
 * Helper function to get plugin instance
 *
 * @return BWS_ACF_Data_Conversion_Tool
 */
if ( ! function_exists( 'bws_acf_conversion_tool' ) ) {
    function bws_acf_conversion_tool(): BWS_ACF_Data_Conversion_Tool {
        return BWS_ACF_Data_Conversion_Tool::get_instance();
    }
}

// Initialize the plugin
bws_acf_conversion_tool();