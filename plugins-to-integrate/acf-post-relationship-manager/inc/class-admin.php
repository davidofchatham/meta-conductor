<?php
/**
 * Admin interface for ACF Post Relationship Manager
 * 
 * @package ACF_Post_Relationship_Manager
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin interface class
 */
if (!class_exists('BWS_ACF_Relationship_Admin')) {
    class BWS_ACF_Relationship_Admin {
        
        /**
         * Class instance
         * 
         * @var BWS_ACF_Relationship_Admin
         */
        private static $instance = null;
        
        /**
         * Configuration instance
         * 
         * @var BWS_ACF_Relationship_Config
         */
        private $config;
        
        /**
         * Core instance
         * 
         * @var BWS_ACF_Relationship_Core
         */
        private $core;
        
        /**
         * Get class instance
         * 
         * @return BWS_ACF_Relationship_Admin
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
            $this->config = BWS_ACF_Relationship_Config::get_instance();
            $this->core = BWS_ACF_Relationship_Core::get_instance();
            $this->init_hooks();
        }
        
        /**
         * Initialize WordPress hooks
         */
        private function init_hooks() {
            // Add admin columns
            add_action('init', array($this, 'setup_admin_columns'));
            
            // Add admin menu
            add_action('admin_menu', array($this, 'add_admin_menu'));
            
            // Add admin notices
            add_action('admin_notices', array($this, 'show_admin_notices'));
            
            // Add bulk actions
            add_filter('bulk_actions-edit-post', array($this, 'add_bulk_actions'));
            add_filter('handle_bulk_actions-edit-post', array($this, 'handle_bulk_actions'), 10, 3);
            
            // Enqueue admin scripts and styles
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }
        
        /**
         * Setup admin columns for monitored post types
         */
        public function setup_admin_columns() {
            $post_types = $this->config->get_monitored_post_types();
            
            foreach ($post_types as $post_type) {
                add_filter("manage_{$post_type}_posts_columns", array($this, 'add_relationship_column'));
                add_action("manage_{$post_type}_posts_custom_column", array($this, 'display_relationship_column'), 10, 2);
                add_filter("manage_edit-{$post_type}_sortable_columns", array($this, 'make_relationship_column_sortable'));
            }
        }
        
        /**
         * Add relationship column to admin post list
         * 
         * @param array $columns Existing columns
         * @return array Modified columns
         */
        public function add_relationship_column($columns) {
            $new_columns = array();
            
            // Insert after title column
            foreach ($columns as $key => $value) {
                $new_columns[$key] = $value;
                if ($key === 'title') {
                    $new_columns['post_relationships'] = __('Relationships', 'acf-post-relationship-manager');
                }
            }
            
            return $new_columns;
        }
        
        /**
         * Display relationship information in admin column
         * 
         * @param string $column Column name
         * @param int $post_id Post ID
         * @return void
         */
        public function display_relationship_column($column, $post_id) {
            if ($column !== 'post_relationships') {
                return;
            }
            
            $output = array();
            
            // Show parent
            $parent_id = wp_get_post_parent_id($post_id);
            if ($parent_id) {
                $parent_title = get_the_title($parent_id);
                $parent_link = get_edit_post_link($parent_id);
                if ($parent_link && current_user_can('edit_post', $parent_id)) {
                    $output[] = sprintf(
                        '<div class="parent-relationship"><strong>%s:</strong> <a href="%s">%s</a></div>',
                        esc_html__('Parent', 'acf-post-relationship-manager'),
                        esc_url($parent_link),
                        esc_html($parent_title)
                    );
                } else {
                    $output[] = sprintf(
                        '<div class="parent-relationship"><strong>%s:</strong> %s</div>',
                        esc_html__('Parent', 'acf-post-relationship-manager'),
                        esc_html($parent_title)
                    );
                }
            }
            
            // Show children
            $children = get_children(array(
                'post_parent' => $post_id,
                'post_type' => get_post_type($post_id),
                'numberposts' => 5, // Limit to avoid clutter
                'post_status' => 'any',
            ));
            
            if (!empty($children)) {
                $child_links = array();
                foreach ($children as $child) {
                    $child_link = get_edit_post_link($child->ID);
                    if ($child_link && current_user_can('edit_post', $child->ID)) {
                        $child_links[] = sprintf(
                            '<a href="%s">%s</a>',
                            esc_url($child_link),
                            esc_html($child->post_title)
                        );
                    } else {
                        $child_links[] = esc_html($child->post_title);
                    }
                }
                
                if (!empty($child_links)) {
                    $children_count = count(get_children(array(
                        'post_parent' => $post_id,
                        'post_type' => get_post_type($post_id),
                        'numberposts' => -1,
                    )));
                    
                    $children_text = implode(', ', $child_links);
                    if ($children_count > 5) {
                        $children_text .= sprintf(
                            ' <span class="description">(%s)</span>',
                            sprintf(
                                /* translators: %d: number of additional children */
                                esc_html__('and %d more', 'acf-post-relationship-manager'),
                                $children_count - 5
                            )
                        );
                    }
                    
                    $output[] = sprintf(
                        '<div class="children-relationship"><strong>%s:</strong> %s</div>',
                        esc_html__('Children', 'acf-post-relationship-manager'),
                        $children_text
                    );
                }
            }
            
            if (!empty($output)) {
                echo '<div class="post-relationships">' . implode('', $output) . '</div>';
            } else {
                echo '<span class="description">' . esc_html__('No relationships', 'acf-post-relationship-manager') . '</span>';
            }
        }
        
        /**
         * Make relationship column sortable
         * 
         * @param array $columns Sortable columns
         * @return array Modified columns
         */
        public function make_relationship_column_sortable($columns) {
            $columns['post_relationships'] = 'post_parent';
            return $columns;
        }
        
        /**
         * Add admin menu
         */
        public function add_admin_menu() {
            add_management_page(
                __('ACF Post Relationships', 'acf-post-relationship-manager'),
                __('Post Relationships', 'acf-post-relationship-manager'),
                'manage_options',
                'acf-post-relationships',
                array($this, 'admin_page')
            );
        }
        
        /**
         * Display admin page
         */
        public function admin_page() {
            // Handle form submissions
            if (isset($_POST['bulk_process']) && wp_verify_nonce($_POST['_wpnonce'], 'bulk_process_relationships')) {
                $this->handle_bulk_process();
            }
            
            include BWS_ACF_RELATIONSHIP_PLUGIN_DIR . 'templates/admin-page.php';
        }
        
        /**
         * Handle bulk processing
         */
        private function handle_bulk_process() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to perform this action.', 'acf-post-relationship-manager'));
            }
            
            $post_types = $this->config->get_monitored_post_types();
            $processed = 0;
            
            foreach ($post_types as $post_type) {
                $posts = get_posts(array(
                    'post_type' => $post_type,
                    'posts_per_page' => -1,
                    'post_status' => array('publish', 'private', 'draft'),
                    'fields' => 'ids',
                ));
                
                foreach ($posts as $post_id) {
                    if ($this->core->manual_process_post_relationships($post_id)) {
                        $processed++;
                    }
                }
            }
            
            add_settings_error(
                'acf_relationships',
                'bulk_processed',
                sprintf(
                    /* translators: %d: number of posts processed */
                    __('Successfully processed relationships for %d posts.', 'acf-post-relationship-manager'),
                    $processed
                ),
                'updated'
            );
        }
        
        /**
         * Add bulk actions to post list
         * 
         * @param array $actions Existing actions
         * @return array Modified actions
         */
        public function add_bulk_actions($actions) {
            global $typenow;
            
            if ($this->config->is_post_type_monitored($typenow)) {
                $actions['process_relationships'] = __('Process Relationships', 'acf-post-relationship-manager');
            }
            
            return $actions;
        }
        
        /**
         * Handle bulk actions
         * 
         * @param string $redirect_to Redirect URL
         * @param string $doaction Action being performed
         * @param array $post_ids Post IDs
         * @return string Modified redirect URL
         */
        public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
            if ($doaction !== 'process_relationships') {
                return $redirect_to;
            }
            
            if (!current_user_can('edit_posts')) {
                return $redirect_to;
            }
            
            $processed = 0;
            foreach ($post_ids as $post_id) {
                if ($this->core->manual_process_post_relationships($post_id)) {
                    $processed++;
                }
            }
            
            $redirect_to = add_query_arg('relationships_processed', $processed, $redirect_to);
            return $redirect_to;
        }
        
        /**
         * Show admin notices
         */
        public function show_admin_notices() {
            // Show activation notice
            if (get_transient('bws_acf_relationship_activated')) {
                delete_transient('bws_acf_relationship_activated');
                
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html__('ACF Post Relationship Manager has been activated successfully.', 'acf-post-relationship-manager')
                );
            }
            
            // Show bulk action results
            if (isset($_GET['relationships_processed'])) {
                $processed = absint($_GET['relationships_processed']);
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    sprintf(
                        /* translators: %d: number of posts processed */
                        esc_html__('Successfully processed relationships for %d posts.', 'acf-post-relationship-manager'),
                        $processed
                    )
                );
            }
            
            // Show settings errors
            settings_errors('acf_relationships');
        }
        
        /**
         * Enqueue admin assets
         * 
         * @param string $hook Current admin page hook
         */
        public function enqueue_admin_assets($hook) {
            // Only load on relevant pages
            if (!in_array($hook, array('edit.php', 'post.php', 'post-new.php', 'tools_page_acf-post-relationships'))) {
                return;
            }
            
            // Check if current post type is monitored
            global $typenow;
            if ($hook === 'edit.php' && !$this->config->is_post_type_monitored($typenow)) {
                return;
            }
            
            // Enqueue styles
            wp_enqueue_style(
                'acf-relationship-admin',
                BWS_ACF_RELATIONSHIP_PLUGIN_URL . 'assets/admin.css',
                array(),
                BWS_ACF_RELATIONSHIP_VERSION
            );
        }
        
        /**
         * Get relationship statistics for display
         * 
         * @return array Statistics for all monitored post types
         */
        public function get_admin_stats() {
            $stats = array();
            $post_types = $this->config->get_monitored_post_types();
            
            foreach ($post_types as $post_type) {
                $stats[$post_type] = $this->core->get_relationship_stats($post_type);
                $stats[$post_type]['post_type_object'] = get_post_type_object($post_type);
            }
            
            return $stats;
        }
    }
}