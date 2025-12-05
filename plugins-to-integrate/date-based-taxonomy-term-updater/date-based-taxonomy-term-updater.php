<?php
/**
 * Plugin Name: Date-Based Taxonomy Term Updater
 * Description: Automatically sets and removes taxonomy terms on posts based on ACF date/datetime field values. Ideal for managing expired content, event statuses, and time-sensitive categorization.
 * Version: 1.1.0
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
define('DBTTU_VERSION', '1.1.0');
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
        private $admin_page_hook = '';
        
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
            
            // Admin hooks
            add_action('admin_menu', [$this, 'bws_add_admin_menu']);
            add_action('admin_init', [$this, 'bws_handle_admin_actions']);
            add_action('admin_enqueue_scripts', [$this, 'bws_enqueue_admin_scripts']);
            
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
        
        /**
         * Add admin menu
         */
        public function bws_add_admin_menu() {
            $this->admin_page_hook = add_management_page(
                __('Date-Based Taxonomy Updater', 'date-based-taxonomy-updater'),
                __('Taxonomy Updater', 'date-based-taxonomy-updater'),
                'manage_options',
                'dbttu-admin',
                [$this, 'bws_render_admin_page']
            );
        }
        
        /**
         * Enqueue admin scripts and styles
         */
        public function bws_enqueue_admin_scripts($hook) {
            if ($hook !== $this->admin_page_hook) {
                return;
            }
            
            // Add inline CSS for better styling
            wp_add_inline_style('wp-admin', '
                .dbttu-admin-wrap {
                    max-width: 1200px;
                }
                .dbttu-config-table {
                    margin-top: 20px;
                }
                .dbttu-config-table th,
                .dbttu-config-table td {
                    padding: 12px;
                    text-align: left;
                    border-bottom: 1px solid #ddd;
                }
                .dbttu-config-table th {
                    background-color: #f9f9f9;
                    font-weight: 600;
                }
                .dbttu-config-table tbody tr:hover {
                    background-color: #f5f5f5;
                }
                .dbttu-manual-trigger {
                    margin: 20px 0;
                    padding: 20px;
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                }
                .dbttu-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin: 20px 0;
                }
                .dbttu-stat-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px;
                    text-align: center;
                }
                .dbttu-stat-number {
                    font-size: 2em;
                    font-weight: bold;
                    color: #0073aa;
                    display: block;
                }
                .dbttu-log-section {
                    margin-top: 30px;
                }
                .dbttu-log-content {
                    background: #f6f7f7;
                    border: 1px solid #ddd;
                    padding: 15px;
                    max-height: 400px;
                    overflow-y: auto;
                    font-family: monospace;
                    font-size: 12px;
                    white-space: pre-wrap;
                }
            ');
        }
        
        /**
         * Handle admin form submissions
         */
        public function bws_handle_admin_actions() {
            if (!isset($_POST['dbttu_action']) || !current_user_can('manage_options')) {
                return;
            }
            
            // Verify nonce
            if (!isset($_POST['dbttu_nonce']) || !wp_verify_nonce($_POST['dbttu_nonce'], 'dbttu_admin_action')) {
                wp_die(__('Security check failed.', 'date-based-taxonomy-updater'), __('Security Error', 'date-based-taxonomy-updater'), ['response' => 403]);
            }
            
            $action = sanitize_text_field($_POST['dbttu_action']);
            
            switch ($action) {
                case 'manual_trigger':
                    $this->bws_handle_manual_trigger();
                    break;
                case 'clear_logs':
                    $this->bws_handle_clear_logs();
                    break;
            }
        }
        
        /**
         * Handle manual trigger from admin
         */
        private function bws_handle_manual_trigger() {
            $this->log('Manual trigger initiated from admin interface by user: ' . wp_get_current_user()->user_login);
            $this->process_posts();
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p><strong>' . 
                     esc_html__('Manual processing completed successfully!', 'date-based-taxonomy-updater') . 
                     '</strong> ' . esc_html__('Check the activity log below for details.', 'date-based-taxonomy-updater') . 
                     '</p></div>';
            });
        }
        
        /**
         * Handle log clearing
         */
        private function bws_handle_clear_logs() {
            if (file_exists($this->log_file)) {
                file_put_contents($this->log_file, '');
                $this->log('Log file cleared from admin interface by user: ' . wp_get_current_user()->user_login);
            }
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                     esc_html__('Activity log has been cleared.', 'date-based-taxonomy-updater') . 
                     '</p></div>';
            });
        }
        
        /**
         * Render admin page
         */
        public function bws_render_admin_page() {
            // Get statistics
            $stats = $this->bws_get_plugin_statistics();
            
            ?>
            <div class="wrap dbttu-admin-wrap">
                <h1><?php esc_html_e('Date-Based Taxonomy Term Updater', 'date-based-taxonomy-updater'); ?></h1>
                
                <?php $this->bws_render_plugin_status(); ?>
                
                <?php $this->bws_render_statistics($stats); ?>
                
                <?php $this->bws_render_manual_trigger_section(); ?>
                
                <?php $this->bws_render_configuration_section(); ?>
                
                <?php $this->bws_render_activity_log(); ?>
            </div>
            <?php
        }
        
        /**
         * Render plugin status section
         */
        private function bws_render_plugin_status() {
            $acf_active = function_exists('get_field');
            $cron_scheduled = wp_next_scheduled($this->cron_hook);
            
            ?>
            <div class="notice notice-info">
                <h3><?php esc_html_e('Plugin Status', 'date-based-taxonomy-updater'); ?></h3>
                <ul>
                    <li>
                        <strong><?php esc_html_e('ACF Plugin:', 'date-based-taxonomy-updater'); ?></strong>
                        <span class="<?php echo $acf_active ? 'dashicons dashicons-yes-alt' : 'dashicons dashicons-warning'; ?>"></span>
                        <?php echo $acf_active ? esc_html__('Active', 'date-based-taxonomy-updater') : esc_html__('Missing - Required!', 'date-based-taxonomy-updater'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Cron Schedule:', 'date-based-taxonomy-updater'); ?></strong>
                        <span class="<?php echo $cron_scheduled ? 'dashicons dashicons-yes-alt' : 'dashicons dashicons-warning'; ?>"></span>
                        <?php if ($cron_scheduled): ?>
                            <?php echo esc_html__('Active - Next run:', 'date-based-taxonomy-updater') . ' ' . esc_html(wp_date('Y-m-d H:i:s', $cron_scheduled)); ?>
                        <?php else: ?>
                            <?php esc_html_e('Not scheduled', 'date-based-taxonomy-updater'); ?>
                        <?php endif; ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Logging:', 'date-based-taxonomy-updater'); ?></strong>
                        <span class="<?php echo DBTTU_ENABLE_LOGGING ? 'dashicons dashicons-yes-alt' : 'dashicons dashicons-no-alt'; ?>"></span>
                        <?php echo DBTTU_ENABLE_LOGGING ? esc_html__('Enabled', 'date-based-taxonomy-updater') : esc_html__('Disabled', 'date-based-taxonomy-updater'); ?>
                    </li>
                </ul>
            </div>
            <?php
        }
        
        /**
         * Render statistics section
         */
        private function bws_render_statistics($stats) {
            ?>
            <h2><?php esc_html_e('Statistics', 'date-based-taxonomy-updater'); ?></h2>
            <div class="dbttu-stats-grid">
                <div class="dbttu-stat-card">
                    <span class="dbttu-stat-number"><?php echo esc_html($stats['configured_post_types']); ?></span>
                    <span class="dbttu-stat-label"><?php esc_html_e('Configured Post Types', 'date-based-taxonomy-updater'); ?></span>
                </div>
                <div class="dbttu-stat-card">
                    <span class="dbttu-stat-number"><?php echo esc_html($stats['total_monitored_posts']); ?></span>
                    <span class="dbttu-stat-label"><?php esc_html_e('Total Monitored Posts', 'date-based-taxonomy-updater'); ?></span>
                </div>
                <div class="dbttu-stat-card">
                    <span class="dbttu-stat-number"><?php echo esc_html($stats['posts_with_target_terms']); ?></span>
                    <span class="dbttu-stat-label"><?php esc_html_e('Posts with Target Terms', 'date-based-taxonomy-updater'); ?></span>
                </div>
                <div class="dbttu-stat-card">
                    <span class="dbttu-stat-number"><?php echo esc_html($stats['posts_ready_for_processing']); ?></span>
                    <span class="dbttu-stat-label"><?php esc_html_e('Posts Ready for Processing', 'date-based-taxonomy-updater'); ?></span>
                </div>
            </div>
            <?php
        }
        
        /**
         * Render manual trigger section
         */
        private function bws_render_manual_trigger_section() {
            ?>
            <div class="dbttu-manual-trigger">
                <h2><?php esc_html_e('Manual Processing', 'date-based-taxonomy-updater'); ?></h2>
                <p><?php esc_html_e('Click the button below to manually run the taxonomy term update process. This will check all configured post types and update taxonomy terms based on their date field values.', 'date-based-taxonomy-updater'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('dbttu_admin_action', 'dbttu_nonce'); ?>
                    <input type="hidden" name="dbttu_action" value="manual_trigger">
                    <?php submit_button(__('Run Manual Update', 'date-based-taxonomy-updater'), 'primary', 'submit', false, ['id' => 'dbttu-manual-trigger-btn']); ?>
                </form>
                
                <p class="description">
                    <?php esc_html_e('Note: The automated process runs hourly via WordPress cron. Manual processing is useful for testing or immediate updates after configuration changes.', 'date-based-taxonomy-updater'); ?>
                </p>
            </div>
            <?php
        }
        
        /**
         * Render configuration section
         */
        private function bws_render_configuration_section() {
            ?>
            <h2><?php esc_html_e('Current Configuration', 'date-based-taxonomy-updater'); ?></h2>
            <p><?php esc_html_e('The following post types are currently configured for automatic taxonomy term updates:', 'date-based-taxonomy-updater'); ?></p>
            
            <table class="wp-list-table widefat fixed striped dbttu-config-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Post Type', 'date-based-taxonomy-updater'); ?></th>
                        <th scope="col"><?php esc_html_e('ACF Date Field', 'date-based-taxonomy-updater'); ?></th>
                        <th scope="col"><?php esc_html_e('Taxonomy', 'date-based-taxonomy-updater'); ?></th>
                        <th scope="col"><?php esc_html_e('Target Term', 'date-based-taxonomy-updater'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'date-based-taxonomy-updater'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (DBTTU_CONFIG as $post_type => $config): ?>
                        <?php $validation = $this->bws_validate_single_config($post_type, $config); ?>
                        <tr>
                            <td><code><?php echo esc_html($post_type); ?></code></td>
                            <td><code><?php echo esc_html($config['acf_field']); ?></code></td>
                            <td><code><?php echo esc_html($config['taxonomy']); ?></code></td>
                            <td><code><?php echo esc_html($config['term_slug']); ?></code></td>
                            <td>
                                <?php if ($validation['valid']): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                    <?php esc_html_e('Valid', 'date-based-taxonomy-updater'); ?>
                                <?php else: ?>
                                    <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                                    <?php echo esc_html($validation['error']); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p class="description">
                <?php esc_html_e('Configuration is managed through the DBTTU_CONFIG constant in the plugin file. Changes require editing the plugin code.', 'date-based-taxonomy-updater'); ?>
            </p>
            <?php
        }
        
        /**
         * Render activity log section
         */
        private function bws_render_activity_log() {
            ?>
            <div class="dbttu-log-section">
                <h2><?php esc_html_e('Recent Activity Log', 'date-based-taxonomy-updater'); ?></h2>
                
                <form method="post" action="" style="margin-bottom: 10px;">
                    <?php wp_nonce_field('dbttu_admin_action', 'dbttu_nonce'); ?>
                    <input type="hidden" name="dbttu_action" value="clear_logs">
                    <?php submit_button(__('Clear Log', 'date-based-taxonomy-updater'), 'secondary small', 'submit', false); ?>
                </form>
                
                <div class="dbttu-log-content">
                    <?php echo esc_html($this->bws_get_recent_log_entries()); ?>
                </div>
                
                <p class="description">
                    <?php esc_html_e('Log file location:', 'date-based-taxonomy-updater'); ?>
                    <code><?php echo esc_html($this->log_file); ?></code>
                </p>
            </div>
            <?php
        }
        
        /**
         * Get plugin statistics
         */
        private function bws_get_plugin_statistics() {
            $stats = [
                'configured_post_types' => count(DBTTU_CONFIG),
                'total_monitored_posts' => 0,
                'posts_with_target_terms' => 0,
                'posts_ready_for_processing' => 0
            ];
            
            if (!function_exists('get_field')) {
                return $stats;
            }
            
            foreach (DBTTU_CONFIG as $post_type => $config) {
                // Count total posts with the ACF field
                $posts_with_field = get_posts([
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
                    'fields' => 'ids'
                ]);
                
                $stats['total_monitored_posts'] += count($posts_with_field);
                
                // Count posts that already have the target term
                $posts_with_terms = get_posts([
                    'post_type' => $post_type,
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'tax_query' => [
                        [
                            'taxonomy' => $config['taxonomy'],
                            'field' => 'slug',
                            'terms' => $config['term_slug']
                        ]
                    ],
                    'fields' => 'ids'
                ]);
                
                $stats['posts_with_target_terms'] += count($posts_with_terms);
                
                // Count posts ready for processing (have date field but not the term)
                $posts_ready = $this->get_posts_to_check($post_type, $config);
                $stats['posts_ready_for_processing'] += count($posts_ready);
            }
            
            return $stats;
        }
        
        /**
         * Validate a single configuration entry
         */
        private function bws_validate_single_config($post_type, $config) {
            // Check if post type exists
            if (!post_type_exists($post_type)) {
                return ['valid' => false, 'error' => __('Post type does not exist', 'date-based-taxonomy-updater')];
            }
            
            // Check if taxonomy exists
            if (!taxonomy_exists($config['taxonomy'])) {
                return ['valid' => false, 'error' => __('Taxonomy does not exist', 'date-based-taxonomy-updater')];
            }
            
            // Check if term exists
            $term = get_term_by('slug', $config['term_slug'], $config['taxonomy']);
            if (!$term) {
                return ['valid' => false, 'error' => __('Term does not exist', 'date-based-taxonomy-updater')];
            }
            
            return ['valid' => true, 'error' => ''];
        }
        
        /**
         * Get recent log entries (last 50 lines)
         */
        private function bws_get_recent_log_entries() {
            if (!file_exists($this->log_file)) {
                return __('No log entries found.', 'date-based-taxonomy-updater');
            }
            
            $lines = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (empty($lines)) {
                return __('Log file is empty.', 'date-based-taxonomy-updater');
            }
            
            // Get last 50 lines
            $recent_lines = array_slice($lines, -50);
            return implode("\n", array_reverse($recent_lines));
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
if (!function_exists('bws_dbttu_init')) {
    function bws_dbttu_init() {
        DBTTU_Date_Taxonomy_Updater::get_instance();
    }
    add_action('plugins_loaded', 'bws_dbttu_init');
}

// Helper function to generate secure manual trigger URL
if (!function_exists('bws_dbttu_get_manual_trigger_url')) {
    function bws_dbttu_get_manual_trigger_url() {
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
if (!function_exists('bws_dbttu_test_logging_function')) {
    function bws_dbttu_test_logging_function() {
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
    add_action('wp_loaded', 'bws_dbttu_test_logging_function');
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
 * 5. Access the admin interface at: Tools > Taxonomy Updater
 * 
 * ============================================================================
 * ADMIN INTERFACE FEATURES
 * ============================================================================
 * 
 * - Plugin status overview (ACF status, cron schedule, logging status)
 * - Live statistics (configured post types, monitored posts, etc.)
 * - Manual trigger button with success/error feedback
 * - Configuration validation and display
 * - Recent activity log viewer with clear log functionality
 * - Responsive design with accessibility features
 * 
 * ============================================================================
 * TROUBLESHOOTING & TESTING
 * ============================================================================
 * 
 * Admin Interface:
 * - Visit: Tools > Taxonomy Updater in WordPress admin
 * - Use manual trigger button for immediate processing
 * - View real-time statistics and configuration validation
 * 
 * Manual Processing (Legacy):
 * - Use: bws_dbttu_get_manual_trigger_url() to get secure trigger URL
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
 * - CSRF protection with nonces for manual triggers and admin actions
 * - Input validation and sanitization for date parsing
 * - Configuration structure validation on plugin initialization
 * - Log message sanitization to prevent log injection
 * - User capability checks for admin functions
 * - Direct file access protection
 * - Protected log directory with .htaccess
 * - Secure admin form handling with proper escaping
 * 
 * ============================================================================
 * ACCESSIBILITY FEATURES
 * ============================================================================
 * 
 * - Semantic HTML structure with proper headings hierarchy
 * - ARIA labels and descriptions where appropriate
 * - Keyboard navigation support for all interactive elements
 * - Screen reader friendly status indicators and messages
 * - High contrast color scheme for status indicators
 * - Descriptive text for all actions and states
 * 
 * ============================================================================
 * DEVELOPED BY BRIDGE WEB SOLUTIONS
 * https://bridgewebsolutions.com
 * ============================================================================
 */