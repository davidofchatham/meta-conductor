<?php
/**
 * Plugin Name: Date-Based Taxonomy Term Updater
 * Description: Automatically sets and removes taxonomy terms on posts based on ACF date/datetime field values. Ideal for managing expired content, event statuses, and time-sensitive categorization.
 * Version: 1.0.0
 * Author: Bridge Web Solutions
 * Author URI: https://bridgewebsolutions.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.1
 * Requires at least: 5.8
 * Tested up to: 6.6
 * Text Domain: date-based-taxonomy-updater
 * Domain Path: /languages
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('DBTTU_VERSION', '1.0.0');
define('DBTTU_PLUGIN_FILE', __FILE__);
define('DBTTU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBTTU_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBTTU_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Configuration Array
 * 
 * Configure which post types to monitor and what actions to take.
 * 
 * Structure:
 * 'post_type' => [
 *     'acf_field' => 'field_name',        // ACF date/datetime field to monitor
 *     'taxonomy' => 'taxonomy_name',      // Taxonomy to update
 *     'term_slug' => 'term_slug_to_set'   // Term slug to set/remove
 * ]
 */
const DBTTU_CONFIG = [
    'notice' => [
        'acf_field' => 'end_date',
        'taxonomy' => 'display_controls',
        'term_slug' => 'expired'
    ],
    'event' => [
        'acf_field' => 'event_end_date',
        'taxonomy' => 'event_status',
        'term_slug' => 'expired'
    ],
    'product' => [
        'acf_field' => 'sale_end_datetime',
        'taxonomy' => 'product_status', 
        'term_slug' => 'sale-ended'
    ]
    // Add more post types as needed
];

// Global logging configuration
const DBTTU_ENABLE_LOGGING = true;

/**
 * Main Plugin Class
 */
if (!class_exists('DBTTU_Date_Taxonomy_Updater')) {
    class DBTTU_Date_Taxonomy_Updater {
        
        private static $instance = null;
        private $cron_hook = 'dbttu_hourly_check';
        private $log_file = '';
        
        public static function get_instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        private function __construct() {
            $this->log_file = DBTTU_PLUGIN_DIR . 'logs/date-taxonomy-updater.log';
            $this->init();
        }
        
        private function init() {
            // Validate configuration before proceeding
            if (!$this->validate_configuration()) {
                add_action('admin_notices', [$this, 'config_error_notice']);
                return;
            }
            
            // Create logs directory
            $this->ensure_logs_directory();
            
            // Hook into WordPress
            add_action('init', [$this, 'setup_hooks']);
            
            // Activation and deactivation
            register_activation_hook(DBTTU_PLUGIN_FILE, [$this, 'activate']);
            register_deactivation_hook(DBTTU_PLUGIN_FILE, [$this, 'deactivate']);
        }
        
        /**
         * Ensure logs directory exists and is protected
         */
        private function ensure_logs_directory() {
            $logs_dir = dirname($this->log_file);
            
            if (!file_exists($logs_dir)) {
                wp_mkdir_p($logs_dir);
            }
            
            // Create .htaccess to protect log files
            $htaccess_file = $logs_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, "Deny from all\n");
            }
            
            // Create index.php to prevent directory listing
            $index_file = $logs_dir . '/index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }
        }
        
        /**
         * Validate plugin configuration
         */
        private function validate_configuration() {
            if (empty(DBTTU_CONFIG) || !is_array(DBTTU_CONFIG)) {
                return false;
            }
            
            foreach (DBTTU_CONFIG as $post_type => $config) {
                if (!is_array($config)) {
                    return false;
                }
                
                $required_keys = ['acf_field', 'taxonomy', 'term_slug'];
                foreach ($required_keys as $key) {
                    if (!isset($config[$key]) || !is_string($config[$key]) || empty($config[$key])) {
                        return false;
                    }
                }
                
                // Validate post type name (alphanumeric and underscores only)
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $post_type)) {
                    return false;
                }
                
                // Validate field names (alphanumeric, underscores, hyphens only)
                foreach (['acf_field', 'taxonomy', 'term_slug'] as $field) {
                    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $config[$field])) {
                        return false;
                    }
                }
            }
            
            return true;
        }
        
        public function config_error_notice() {
            echo '<div class="notice notice-error"><p><strong>Date-Based Taxonomy Term Updater:</strong> Plugin configuration is invalid. Please check your DBTTU_CONFIG constant.</p></div>';
        }
        
        public function setup_hooks() {
            // Only proceed if ACF is active
            if (!function_exists('get_field')) {
                add_action('admin_notices', [$this, 'acf_missing_notice']);
                return;
            }
            
            // Setup cron job
            add_action($this->cron_hook, [$this, 'process_posts']);
            
            // Hook into post save for reversibility check
            add_action('save_post', [$this, 'check_post_on_save'], 10, 2);
            
            // Manual trigger hook (after WordPress is fully loaded)
            add_action('wp_loaded', [$this, 'check_manual_trigger']);
            
            // Schedule cron if not already scheduled
            if (!wp_next_scheduled($this->cron_hook)) {
                wp_schedule_event(time(), 'hourly', $this->cron_hook);
            }
        }
        
        public function activate() {
            // Schedule the cron job
            if (!wp_next_scheduled($this->cron_hook)) {
                wp_schedule_event(time(), 'hourly', $this->cron_hook);
            }
            
            // Test logging on activation
            $this->log('Plugin activated and cron scheduled');
            $this->test_logging_setup();
        }
        
        public function deactivate() {
            // Clear the scheduled cron
            wp_clear_scheduled_hook($this->cron_hook);
            $this->log('Plugin deactivated and cron cleared');
        }
        
        public function acf_missing_notice() {
            echo '<div class="notice notice-error"><p><strong>Date-Based Taxonomy Term Updater:</strong> Advanced Custom Fields plugin is required.</p></div>';
        }
        
        /**
         * Test logging configuration and provide diagnostics
         */
        private function test_logging_setup() {
            $diagnostics = [];
            
            // Check WP_DEBUG_LOG setting
            $diagnostics[] = 'WP_DEBUG_LOG: ' . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'ENABLED' : 'DISABLED');
            $diagnostics[] = 'WP_DEBUG: ' . (defined('WP_DEBUG') && WP_DEBUG ? 'ENABLED' : 'DISABLED');
            
            // Check debug.log file
            $debug_log_path = WP_CONTENT_DIR . '/debug.log';
            $diagnostics[] = 'debug.log exists: ' . (file_exists($debug_log_path) ? 'YES' : 'NO');
            $diagnostics[] = 'debug.log writable: ' . (is_writable(dirname($debug_log_path)) ? 'YES' : 'NO');
            
            // Check plugin log file
            $diagnostics[] = 'Plugin log writable: ' . (is_writable(dirname($this->log_file)) ? 'YES' : 'NO');
            
            // Check plugin configuration
            $diagnostics[] = 'Logging enabled: ' . (DBTTU_ENABLE_LOGGING ? 'YES' : 'NO');
            $diagnostics[] = 'ACF available: ' . (function_exists('get_field') ? 'YES' : 'NO');
            
            $diagnostic_message = 'LOGGING DIAGNOSTICS: ' . implode(' | ', $diagnostics);
            
            // Force log this diagnostic info using multiple methods
            error_log("[" . current_time('Y-m-d H:i:s') . "] Date-Based Taxonomy Updater: " . $diagnostic_message);
            file_put_contents($this->log_file, "[" . current_time('Y-m-d H:i:s') . "] Date-Based Taxonomy Updater: " . $diagnostic_message . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        
        /**
         * Check for manual trigger request
         */
        public function check_manual_trigger() {
            if (isset($_GET['dbttu_manual_trigger']) && current_user_can('manage_options')) {
                // CSRF protection
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dbttu_manual_trigger')) {
                    wp_die('Security check failed. Please use the proper admin link.', 'Security Error', ['response' => 403]);
                }
                
                $this->process_posts();
                wp_die('Manual processing completed. Check logs for details.<br><br><strong>Log file:</strong> ' . $this->log_file);
            }
        }
        
        /**
         * Main processing function - runs hourly
         */
        public function process_posts() {
            $this->log('Starting hourly post processing');
            $processed_count = 0;
            $updated_count = 0;
            
            foreach (DBTTU_CONFIG as $post_type => $config) {
                $posts = $this->get_posts_to_check($post_type, $config);
                
                foreach ($posts as $post) {
                    $processed_count++;
                    
                    if ($this->should_set_term($post->ID, $config)) {
                        if ($this->set_taxonomy_term($post->ID, $config)) {
                            $updated_count++;
                            $this->log("Set term '{$config['term_slug']}' on post ID {$post->ID} ({$post_type})");
                        }
                    }
                }
            }
            
            $this->log("Processed {$processed_count} posts, updated {$updated_count} posts");
        }
        
        /**
         * Get posts that need to be checked for a specific post type
         */
        private function get_posts_to_check($post_type, $config) {
            // Get posts that don't already have the target term
            $args = [
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => $config['acf_field'],
                        'value' => '',
                        'compare' => '!='
                    ]
                ],
                'tax_query' => [
                    [
                        'taxonomy' => $config['taxonomy'],
                        'field' => 'slug',
                        'terms' => $config['term_slug'],
                        'operator' => 'NOT IN'
                    ]
                ]
            ];
            
            return get_posts($args);
        }
        
        /**
         * Check if we should set the term based on date comparison
         */
        private function should_set_term($post_id, $config) {
            $date_value = get_field($config['acf_field'], $post_id);
            
            if (!$date_value) {
                return false;
            }
            
            // Handle different ACF date formats
            $date_info = $this->parse_acf_date_with_type($date_value);
            
            if (!$date_info) {
                $this->log("Could not parse date value for post ID {$post_id}: " . print_r($date_value, true));
                return false;
            }
            
            // Check if date has passed (using appropriate comparison)
            if ($date_info['is_date_only']) {
                // For date-only fields, compare dates only (ignore time)
                $date_only = date('Y-m-d', $date_info['timestamp']);
                $current_date_only = current_time('Y-m-d');
                return $date_only < $current_date_only; // Only past dates, not today
            } else {
                // For datetime fields, compare full timestamps
                return $date_info['timestamp'] <= current_time('timestamp');
            }
        }
        
        /**
         * Parse ACF date value to timestamp (with security validation)
         */
        private function parse_acf_date($date_value) {
            $result = $this->parse_acf_date_with_type($date_value);
            return $result ? $result['timestamp'] : false;
        }
        
        /**
         * Parse ACF date value and determine if it's date-only or datetime
         */
        private function parse_acf_date_with_type($date_value) {
            $this->log("DEBUG: Starting date parsing for value: " . print_r($date_value, true));
            
            // Handle DateTime object (ACF date/time picker)
            if ($date_value instanceof DateTime) {
                $timestamp = $date_value->getTimestamp();
                // Check if it has time component (not at midnight)
                $is_date_only = ($date_value->format('H:i:s') === '00:00:00');
                $this->log("DEBUG: DateTime object parsed to timestamp: {$timestamp}, date-only: " . ($is_date_only ? 'YES' : 'NO'));
                return [
                    'timestamp' => $timestamp,
                    'is_date_only' => $is_date_only
                ];
            }
            
            // Handle string dates (with validation)
            if (is_string($date_value)) {
                // Sanitize and validate the string
                $date_value = trim($date_value);
                $this->log("DEBUG: Processing string date: '{$date_value}'");
                
                // Basic validation - reasonable length
                if (strlen($date_value) > 100 || empty($date_value)) {
                    $this->log("DEBUG: Date string too long or empty");
                    return false;
                }
                
                // More flexible validation - allow letters, numbers, spaces, common punctuation
                if (!preg_match('/^[a-zA-Z0-9\s\-:\/,.]+$/', $date_value)) {
                    $this->log("DEBUG: Date string contains invalid characters");
                    return false;
                }
                
                // Detect if string contains time component
                $has_time = preg_match('/\d{1,2}:\d{2}/', $date_value); // Look for HH:MM pattern
                $is_date_only = !$has_time;
                
                $this->log("DEBUG: String appears to be date-only: " . ($is_date_only ? 'YES' : 'NO'));
                
                // Try to parse the date using strtotime (handles many formats)
                $timestamp = strtotime($date_value);
                $this->log("DEBUG: strtotime() result: {$timestamp}");
                
                if ($timestamp !== false && $timestamp > 0) {
                    // Additional validation - ensure reasonable date range (1970-2100)
                    if ($timestamp >= 0 && $timestamp <= 4102444800) { // Jan 1, 2100
                        $this->log("DEBUG: Successfully parsed to timestamp: {$timestamp} (" . date('Y-m-d H:i:s', $timestamp) . ")");
                        return [
                            'timestamp' => $timestamp,
                            'is_date_only' => $is_date_only
                        ];
                    } else {
                        $this->log("DEBUG: Timestamp outside valid range: {$timestamp}");
                    }
                } else {
                    $this->log("DEBUG: strtotime() failed to parse date");
                }
                
                // Try additional parsing methods for common ACF formats
                $date_only_formats = [
                    'F j, Y',           // August 1, 2025
                    'M j, Y',           // Aug 1, 2025  
                    'Y-m-d',            // 2025-08-01
                    'm/d/Y',            // 08/01/2025
                    'd/m/Y',            // 01/08/2025
                ];
                
                $datetime_formats = [
                    'Y-m-d H:i:s',      // 2025-08-01 12:30:00
                    'm/d/Y H:i:s',      // 08/01/2025 12:30:00
                    'F j, Y g:i A',     // August 1, 2025 3:30 PM
                ];
                
                // Try date-only formats first
                foreach ($date_only_formats as $format) {
                    $parsed_date = DateTime::createFromFormat($format, $date_value);
                    if ($parsed_date !== false) {
                        $timestamp = $parsed_date->getTimestamp();
                        $this->log("DEBUG: Successfully parsed using date-only format '{$format}' to timestamp: {$timestamp}");
                        return [
                            'timestamp' => $timestamp,
                            'is_date_only' => true
                        ];
                    }
                }
                
                // Try datetime formats
                foreach ($datetime_formats as $format) {
                    $parsed_date = DateTime::createFromFormat($format, $date_value);
                    if ($parsed_date !== false) {
                        $timestamp = $parsed_date->getTimestamp();
                        $this->log("DEBUG: Successfully parsed using datetime format '{$format}' to timestamp: {$timestamp}");
                        return [
                            'timestamp' => $timestamp,
                            'is_date_only' => false
                        ];
                    }
                }
                
                $this->log("DEBUG: All parsing methods failed for string: '{$date_value}'");
            }
            
            // Handle array format (some ACF configurations)
            if (is_array($date_value)) {
                $this->log("DEBUG: Processing array date: " . print_r($date_value, true));
                
                if (isset($date_value['date']) && is_string($date_value['date'])) {
                    return $this->parse_acf_date_with_type($date_value['date']); // Recursive validation
                }
                
                // Some ACF configurations might use different array keys
                foreach (['value', 'timestamp', 'raw'] as $key) {
                    if (isset($date_value[$key])) {
                        return $this->parse_acf_date_with_type($date_value[$key]);
                    }
                }
            }
            
            $this->log("DEBUG: Could not parse date value");
            return false;
        }
        
        /**
         * Set the taxonomy term on a post
         */
        private function set_taxonomy_term($post_id, $config) {
            $term = get_term_by('slug', $config['term_slug'], $config['taxonomy']);
            
            if (!$term) {
                $this->log("Term '{$config['term_slug']}' not found in taxonomy '{$config['taxonomy']}'");
                return false;
            }
            
            $result = wp_set_post_terms($post_id, [$term->term_id], $config['taxonomy'], true);
            
            return !is_wp_error($result);
        }
        
        /**
         * Check post on save for reversibility
         */
        public function check_post_on_save($post_id, $post) {
            // ALWAYS log that this function was called (even before other checks)
            error_log('[' . current_time('Y-m-d H:i:s') . '] Date-Based Taxonomy Updater: POST SAVE HOOK TRIGGERED for post ID ' . $post_id);
            
            $this->log("=== POST SAVE DEBUG: Starting check for post ID {$post_id} (type: {$post->post_type}) ===");
            
            // Skip autosaves and revisions
            if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
                $this->log("Skipping autosave/revision for post ID {$post_id}");
                return;
            }
            
            // Check if this post type is configured
            if (!isset(DBTTU_CONFIG[$post->post_type])) {
                $this->log("Post type '{$post->post_type}' not configured - skipping");
                return;
            }
            
            $config = DBTTU_CONFIG[$post->post_type];
            $this->log("Found configuration for post type '{$post->post_type}': " . print_r($config, true));
            
            // Check if post has the target term
            if (!$this->post_has_term($post_id, $config)) {
                $this->log("Post ID {$post_id} does not have target term '{$config['term_slug']}' - skipping removal check");
                return;
            }
            
            $this->log("Post ID {$post_id} HAS target term '{$config['term_slug']}' - checking if date should trigger removal");
            
            // Get the ACF date value
            $date_value = get_field($config['acf_field'], $post_id);
            $this->log("Raw ACF date value for field '{$config['acf_field']}': " . print_r($date_value, true));
            
            if (!$date_value) {
                $this->log("No date value found for field '{$config['acf_field']}' on post ID {$post_id}");
                return;
            }
            
            $date_info = $this->parse_acf_date_with_type($date_value);
            $current_timestamp = current_time('timestamp');
            
            if (!$date_info) {
                $this->log("Could not parse date value - skipping removal check");
                return;
            }
            
            $this->log("Parsed date timestamp: {$date_info['timestamp']} (" . date('Y-m-d H:i:s', $date_info['timestamp']) . ")");
            $this->log("Is date-only field: " . ($date_info['is_date_only'] ? 'YES' : 'NO'));
            $this->log("Current timestamp: {$current_timestamp} (" . date('Y-m-d H:i:s', $current_timestamp) . ")");
            
            // Determine if we should remove the term
            $should_remove_term = false;
            
            if ($date_info['is_date_only']) {
                // For date-only fields, compare dates only (ignore time)
                $date_only = date('Y-m-d', $date_info['timestamp']);
                $current_date_only = current_time('Y-m-d');
                $should_remove_term = $date_only >= $current_date_only; // Today or future
                $this->log("Date-only comparison: '{$date_only}' >= '{$current_date_only}' = " . ($should_remove_term ? 'TRUE' : 'FALSE'));
            } else {
                // For datetime fields, compare full timestamps  
                $should_remove_term = $date_info['timestamp'] >= $current_timestamp; // Now or future
                $this->log("DateTime comparison: {$date_info['timestamp']} >= {$current_timestamp} = " . ($should_remove_term ? 'TRUE' : 'FALSE'));
            }
            
            if ($should_remove_term) {
                $this->log("Date is today/now or in future - attempting to remove term");
                
                if ($this->remove_taxonomy_term($post_id, $config)) {
                    $this->log("SUCCESS: Removed term '{$config['term_slug']}' from post ID {$post_id} - date is not fully in the past");
                } else {
                    $this->log("FAILED: Could not remove term '{$config['term_slug']}' from post ID {$post_id}");
                }
            } else {
                $this->log("Date is fully in the past - term should remain set");
            }
            
            $this->log("=== POST SAVE DEBUG: Completed check for post ID {$post_id} ===");
        }
        
        /**
         * Check if post has the target term
         */
        private function post_has_term($post_id, $config) {
            $terms = wp_get_post_terms($post_id, $config['taxonomy'], ['fields' => 'slugs']);
            
            if (is_wp_error($terms)) {
                $this->log("ERROR getting terms for post ID {$post_id}: " . $terms->get_error_message());
                return false;
            }
            
            $has_term = in_array($config['term_slug'], $terms);
            $this->log("Post ID {$post_id} terms in '{$config['taxonomy']}': " . implode(', ', $terms) . " | Has target term '{$config['term_slug']}': " . ($has_term ? 'YES' : 'NO'));
            
            return $has_term;
        }
        
        /**
         * Remove the taxonomy term from a post
         */
        private function remove_taxonomy_term($post_id, $config) {
            $this->log("Attempting to remove term '{$config['term_slug']}' from taxonomy '{$config['taxonomy']}' on post ID {$post_id}");
            
            $term = get_term_by('slug', $config['term_slug'], $config['taxonomy']);
            
            if (!$term) {
                $this->log("ERROR: Term '{$config['term_slug']}' not found in taxonomy '{$config['taxonomy']}'");
                return false;
            }
            
            $this->log("Found term: ID {$term->term_id}, Name: '{$term->name}', Slug: '{$term->slug}'");
            
            // Get current terms before removal for debugging
            $current_terms = wp_get_post_terms($post_id, $config['taxonomy'], ['fields' => 'slugs']);
            $this->log("Current terms before removal: " . implode(', ', $current_terms));
            
            $result = wp_remove_object_terms($post_id, $term->term_id, $config['taxonomy']);
            
            if (is_wp_error($result)) {
                $this->log("ERROR removing term: " . $result->get_error_message());
                return false;
            }
            
            // Get terms after removal for verification
            $terms_after = wp_get_post_terms($post_id, $config['taxonomy'], ['fields' => 'slugs']);
            $this->log("Terms after removal: " . implode(', ', $terms_after));
            
            // Verify the term was actually removed
            $was_removed = !in_array($config['term_slug'], $terms_after);
            $this->log("Term removal " . ($was_removed ? "SUCCESS" : "FAILED"));
            
            return $was_removed;
        }
        
        /**
         * Basic logging function (with data sanitization)
         */
        private function log($message) {
            if (!DBTTU_ENABLE_LOGGING) {
                return;
            }
            
            // Sanitize the message to prevent log injection
            $message = $this->sanitize_log_message($message);
            
            $timestamp = current_time('Y-m-d H:i:s');
            $log_message = "[{$timestamp}] Date-Based Taxonomy Updater: {$message}";
            
            // Try multiple logging methods
            
            // Method 1: WordPress debug.log (if WP_DEBUG_LOG is enabled)
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log($log_message);
            }
            
            // Method 2: Plugin log file (always works)
            file_put_contents($this->log_file, $log_message . PHP_EOL, FILE_APPEND | LOCK_EX);
            
            // Method 3: Admin notice for critical messages (if in admin)
            if (is_admin() && strpos($message, 'ERROR') !== false) {
                add_action('admin_notices', function() use ($message) {
                    echo '<div class="notice notice-warning"><p><strong>Date-Based Taxonomy Updater Debug:</strong> ' . esc_html($message) . '</p></div>';
                });
            }
        }
        
        /**
         * Sanitize log messages to prevent log injection
         */
        private function sanitize_log_message($message) {
            // Convert to string if not already
            if (!is_string($message)) {
                $message = print_r($message, true);
            }
            
            // Remove or escape potentially dangerous characters
            $message = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $message);
            $message = trim($message);
            
            // Limit message length to prevent log bloat
            if (strlen($message) > 500) {
                $message = substr($message, 0, 497) . '...';
            }
            
            return $message;
        }
    }
}

// Initialize the plugin
if (!function_exists('dbttu_init')) {
    function dbttu_init() {
        DBTTU_Date_Taxonomy_Updater::get_instance();
    }
    add_action('plugins_loaded', 'dbttu_init');
}

// Helper function to generate secure manual trigger URL
if (!function_exists('dbttu_get_manual_trigger_url')) {
    function dbttu_get_manual_trigger_url() {
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        $nonce = wp_create_nonce('dbttu_manual_trigger');
        return add_query_arg([
            'dbttu_manual_trigger' => '1',
            '_wpnonce' => $nonce
        ], admin_url());
    }
}

// Debug function to test logging (add ?dbttu_test_logging=1 to any admin URL)
if (!function_exists('dbttu_test_logging_function')) {
    function dbttu_test_logging_function() {
        if (isset($_GET['dbttu_test_logging']) && current_user_can('manage_options')) {
            $instance = DBTTU_Date_Taxonomy_Updater::get_instance();
            
            // Test basic logging
            error_log('[' . current_time('Y-m-d H:i:s') . '] DIRECT ERROR_LOG TEST: This should appear in debug.log');
            
            // Test plugin log file
            $plugin_log = DBTTU_PLUGIN_DIR . 'logs/date-taxonomy-updater.log';
            file_put_contents($plugin_log, '[' . current_time('Y-m-d H:i:s') . '] DIRECT FILE TEST: This should appear in plugin log' . PHP_EOL, FILE_APPEND);
            
            // Test plugin logging method using reflection
            $reflection = new ReflectionClass($instance);
            $method = $reflection->getMethod('log');
            $method->setAccessible(true);
            $method->invoke($instance, 'PLUGIN LOG TEST: This should appear in both logs');
            
            wp_die('
                <h2>Logging Test Complete</h2>
                <p><strong>Check these locations for log messages:</strong></p>
                <ul>
                    <li>WordPress debug.log: <code>' . WP_CONTENT_DIR . '/debug.log</code></li>
                    <li>Plugin log: <code>' . $plugin_log . '</code></li>
                </ul>
                <p><strong>WordPress Settings:</strong></p>
                <ul>
                    <li>WP_DEBUG_LOG: ' . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'ENABLED' : 'DISABLED') . '</li>
                    <li>Plugin Logging: ' . (DBTTU_ENABLE_LOGGING ? 'ENABLED' : 'DISABLED') . '</li>
                </ul>
            ');
        }
    }
    add_action('wp_loaded', 'dbttu_test_logging_function');
}

/*
 * ============================================================================
 * PLUGIN INSTALLATION INSTRUCTIONS
 * ============================================================================
 * 
 * 1. Create directory: /wp-content/plugins/date-based-taxonomy-term-updater/
 * 2. Save this file as: date-based-taxonomy-term-updater.php
 * 3. Activate the plugin through WordPress admin
 * 4. Configure the DBTTU_CONFIG constant above to match your needs
 * 
 * ============================================================================
 * TROUBLESHOOTING & TESTING
 * ============================================================================
 * 
 * Manual Processing:
 * - Use: dbttu_get_manual_trigger_url() to get secure trigger URL
 * - Visit: /wp-admin/?dbttu_manual_trigger=1&_wpnonce=[generated_nonce]
 * 
 * Test Logging:
 * - Visit: /wp-admin/?dbttu_test_logging=1
 * - Check logs at: /wp-content/plugins/date-based-taxonomy-term-updater/logs/
 * 
 * ============================================================================
 * BEHAVIOR SUMMARY
 * ============================================================================
 * 
 * DATE-ONLY FIELDS (e.g., "July 31, 2025"):
 * - Sets target term when date is fully in the past (< current date)
 * - Removes target term when date is today or future (>= current date)
 * 
 * DATETIME FIELDS (e.g., "July 31, 2025 3:30 PM"):
 * - Sets target term when datetime has passed (< current datetime)
 * - Removes target term when datetime is now or future (>= current datetime)
 * 
 * ============================================================================
 * SECURITY FEATURES
 * ============================================================================
 * 
 * - CSRF protection with nonces for manual triggers
 * - Input validation and sanitization for date parsing
 * - Configuration structure validation on plugin initialization
 * - Log message sanitization to prevent log injection
 * - User capability checks for admin functions
 * - Direct file access protection
 * - Protected log directory with .htaccess
 * 
 * ============================================================================
 * DEVELOPED BY BRIDGE WEB SOLUTIONS
 * https://bridgewebsolutions.com
 * ============================================================================
 */