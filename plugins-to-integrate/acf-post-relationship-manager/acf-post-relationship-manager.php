<?php
/**
 * Plugin Name: ACF Post Relationship Manager
 * Plugin URI: https://yoursite.com
 * Description: Automatically manages WordPress parent/child post relationships based on ACF post object or relationship fields.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * Text Domain: acf-post-relationship-manager
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.6
 * Requires PHP: 8.1
 * Network: false
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BWS_ACF_RELATIONSHIP_VERSION', '1.0.0');
define('BWS_ACF_RELATIONSHIP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BWS_ACF_RELATIONSHIP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BWS_ACF_RELATIONSHIP_PLUGIN_FILE', __FILE__);

/**
 * Main plugin class
 */
if (!class_exists('BWS_ACF_Post_Relationship_Manager')) {
    class BWS_ACF_Post_Relationship_Manager {
        
        /**
         * Plugin instance
         * 
         * @var BWS_ACF_Post_Relationship_Manager
         */
        private static $instance = null;
        
        /**
         * Get plugin instance
         * 
         * @return BWS_ACF_Post_Relationship_Manager
         */
        public static function get_instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         * Constructor
         */
        private function __construct() {
            add_action('plugins_loaded', array($this, 'init'));
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        }
        
        /**
         * Initialize the plugin
         */
        public function init() {
            // Check if ACF is active
            if (!$this->is_acf_active()) {
                add_action('admin_notices', array($this, 'acf_missing_notice'));
                return;
            }
            
            // Load plugin files
            $this->load_includes();
            
            // Initialize components
            $this->init_components();
            
            // Load textdomain
            load_plugin_textdomain(
                'acf-post-relationship-manager',
                false,
                dirname(plugin_basename(__FILE__)) . '/languages'
            );
        }
        
        /**
         * Load required files
         */
        private function load_includes() {
            require_once BWS_ACF_RELATIONSHIP_PLUGIN_DIR . 'inc/class-config.php';
            require_once BWS_ACF_RELATIONSHIP_PLUGIN_DIR . 'inc/class-core.php';
            require_once BWS_ACF_RELATIONSHIP_PLUGIN_DIR . 'inc/class-admin.php';
        }
        
        /**
         * Initialize plugin components
         */
        private function init_components() {
            // Initialize configuration
            BWS_ACF_Relationship_Config::get_instance();
            
            // Initialize core functionality
            BWS_ACF_Relationship_Core::get_instance();
            
            // Initialize admin interface
            if (is_admin()) {
                BWS_ACF_Relationship_Admin::get_instance();
            }
        }
        
        /**
         * Check if ACF is active
         * 
         * @return bool
         */
        private function is_acf_active() {
            return function_exists('get_field') && function_exists('acf_get_field');
        }
        
        /**
         * Display notice when ACF is missing
         */
        public function acf_missing_notice() {
            if (current_user_can('activate_plugins')) {
                $message = sprintf(
                    /* translators: %s: Plugin name */
                    __('%s requires Advanced Custom Fields Pro to be installed and activated.', 'acf-post-relationship-manager'),
                    '<strong>ACF Post Relationship Manager</strong>'
                );
                
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    wp_kses_post($message)
                );
            }
        }
        
        /**
         * Plugin activation
         */
        public function activate() {
            // Flush rewrite rules if needed
            flush_rewrite_rules();
            
            // Set activation flag for any setup routines
            set_transient('bws_acf_relationship_activated', true, 30);
        }
        
        /**
         * Plugin deactivation
         */
        public function deactivate() {
            // Clean up if needed
            flush_rewrite_rules();
        }
        
        /**
         * Get plugin version
         * 
         * @return string
         */
        public function get_version() {
            return BWS_ACF_RELATIONSHIP_VERSION;
        }
    }
}

/**
 * Get plugin instance
 * 
 * @return BWS_ACF_Post_Relationship_Manager
 */
if (!function_exists('bws_acf_post_relationship_manager')) {
    function bws_acf_post_relationship_manager() {
        return BWS_ACF_Post_Relationship_Manager::get_instance();
    }
}

// Initialize the plugin
bws_acf_post_relationship_manager();