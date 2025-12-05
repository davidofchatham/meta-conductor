<?php
/**
 * Core functionality for ACF Post Relationship Manager
 * 
 * @package ACF_Post_Relationship_Manager
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core relationship processing class
 */
if (!class_exists('BWS_ACF_Relationship_Core')) {
    class BWS_ACF_Relationship_Core {
        
        /**
         * Class instance
         * 
         * @var BWS_ACF_Relationship_Core
         */
        private static $instance = null;
        
        /**
         * Configuration instance
         * 
         * @var BWS_ACF_Relationship_Config
         */
        private $config;
        
        /**
         * Processing lock to prevent infinite loops
         * 
         * @var bool
         */
        private $processing = false;
        
        /**
         * Get class instance
         * 
         * @return BWS_ACF_Relationship_Core
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
            $this->init_hooks();
        }
        
        /**
         * Initialize WordPress hooks
         */
        private function init_hooks() {
            // Hook into post save with priority 20 to ensure ACF fields are saved first
            add_action('save_post', array($this, 'process_post_relationships'), 20, 3);
            
            // Hook into ACF save_post for more reliable field data
            add_action('acf/save_post', array($this, 'process_acf_post_relationships'), 20);
        }
        
        /**
         * Main function to process post relationships based on ACF fields
         * 
         * @param int $post_id The post ID being processed
         * @param WP_Post $post The post object
         * @param bool $update Whether this is an update
         * @return void
         */
        public function process_post_relationships($post_id, $post, $update) {
            // Skip if already processing to prevent infinite loops
            if ($this->processing) {
                return;
            }
            
            // Skip autosave, revisions, and auto-drafts
            if (wp_is_post_autosave($post_id) || 
                wp_is_post_revision($post_id) || 
                $post->post_status === 'auto-draft') {
                return;
            }
            
            // Check if ACF is ready
            if (!$this->is_acf_ready()) {
                return;
            }
            
            // Get configuration for this post type
            $config = $this->config->get_post_type_config($post->post_type);
            if (!$config) {
                return;
            }
            
            $this->process_relationships($post_id, $config);
        }
        
        /**
         * Process relationships after ACF save_post
         * 
         * @param int $post_id Post ID
         */
        public function process_acf_post_relationships($post_id) {
            $post = get_post($post_id);
            if (!$post) {
                return;
            }
            
            $this->process_post_relationships($post_id, $post, true);
        }
        
        /**
         * Process relationships for a specific post
         * 
         * @param int $post_id Post ID
         * @param array $config Configuration array
         */
        private function process_relationships($post_id, $config) {
            // Set processing lock
            $this->processing = true;
            
            try {
                // Process parent relationship
                if (!empty($config['parent_field'])) {
                    $this->process_parent_relationship($post_id, $config['parent_field']);
                }
                
                // Process children relationships
                if (!empty($config['children_field'])) {
                    $this->process_children_relationship($post_id, $config['children_field']);
                }
            } finally {
                // Always release the lock
                $this->processing = false;
            }
        }
        
        /**
         * Process parent relationship for a post
         * 
         * @param int $post_id The current post ID
         * @param string $parent_field The ACF field containing parent post ID(s)
         * @return bool Success status
         */
        private function process_parent_relationship($post_id, $parent_field) {
            $parent_ids = get_field($parent_field, $post_id);
            
            if (empty($parent_ids)) {
                return false;
            }
            
            // Handle both single values and arrays
            if (!is_array($parent_ids)) {
                $parent_ids = array($parent_ids);
            }
            
            // Get the first parent ID (primary parent)
            $parent_id = absint($parent_ids[0]);
            
            if (!$parent_id || $parent_id === $post_id) {
                return false;
            }
            
            // Check if parent post exists
            if (!get_post($parent_id)) {
                return false;
            }
            
            // Check for circular reference
            if ($this->would_create_circular_reference($post_id, $parent_id)) {
                return false;
            }
            
            // Get current parent
            $current_parent = wp_get_post_parent_id($post_id);
            
            // Only update if parent has changed
            if ($current_parent !== $parent_id) {
                $result = wp_update_post(array(
                    'ID' => $post_id,
                    'post_parent' => $parent_id,
                ));
                
                return !is_wp_error($result);
            }
            
            return true;
        }
        
        /**
         * Process children relationships for a post
         * 
         * @param int $post_id The current post ID (parent)
         * @param string $children_field The ACF field containing child post ID(s)
         * @return bool Success status
         */
        private function process_children_relationship($post_id, $children_field) {
            $child_ids = get_field($children_field, $post_id);
            
            if (empty($child_ids)) {
                return false;
            }
            
            // Handle both single values and arrays
            if (!is_array($child_ids)) {
                $child_ids = array($child_ids);
            }
            
            $success_count = 0;
            
            foreach ($child_ids as $child_id) {
                $child_id = absint($child_id);
                
                if (!$child_id || $child_id === $post_id) {
                    continue;
                }
                
                // Check if child post exists
                if (!get_post($child_id)) {
                    continue;
                }
                
                // Check for circular reference
                if ($this->would_create_circular_reference($child_id, $post_id)) {
                    continue;
                }
                
                // Get current parent of the child
                $current_parent = wp_get_post_parent_id($child_id);
                
                // Only update if parent has changed
                if ($current_parent !== $post_id) {
                    $result = wp_update_post(array(
                        'ID' => $child_id,
                        'post_parent' => $post_id,
                    ));
                    
                    if (!is_wp_error($result)) {
                        $success_count++;
                    }
                }
            }
            
            return $success_count > 0;
        }
        
        /**
         * Check if setting a parent would create a circular reference
         * 
         * @param int $child_id The child post ID
         * @param int $parent_id The proposed parent post ID
         * @param int $depth Maximum depth to check (prevents infinite recursion)
         * @return bool True if circular reference would be created
         */
        private function would_create_circular_reference($child_id, $parent_id, $depth = 10) {
            // Prevent infinite recursion
            if ($depth <= 0) {
                return true;
            }
            
            // Direct circular reference
            if ($child_id === $parent_id) {
                return true;
            }
            
            // Check if parent is actually a descendant of child
            $ancestors = get_post_ancestors($parent_id);
            
            if (in_array($child_id, $ancestors)) {
                return true;
            }
            
            return false;
        }
        
        /**
         * Check if ACF is ready and required functions exist
         * 
         * @return bool
         */
        private function is_acf_ready() {
            return function_exists('get_field') && function_exists('acf_get_field');
        }
        
        /**
         * Manually process relationships for a specific post
         * Useful for bulk updates or migration
         * 
         * @param int $post_id Post ID to process
         * @return bool Success status
         */
        public function manual_process_post_relationships($post_id) {
            $post = get_post($post_id);
            if (!$post) {
                return false;
            }
            
            $config = $this->config->get_post_type_config($post->post_type);
            if (!$config) {
                return false;
            }
            
            $this->process_relationships($post_id, $config);
            return true;
        }
        
        /**
         * Get relationship statistics for a post type
         * 
         * @param string $post_type Post type to analyze
         * @return array Statistics array
         */
        public function get_relationship_stats($post_type) {
            $stats = array(
                'total_posts' => 0,
                'posts_with_parents' => 0,
                'posts_with_children' => 0,
                'orphaned_posts' => 0,
            );
            
            $posts = get_posts(array(
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => array('publish', 'private', 'draft'),
                'fields' => 'ids',
            ));
            
            $stats['total_posts'] = count($posts);
            
            foreach ($posts as $post_id) {
                $parent_id = wp_get_post_parent_id($post_id);
                $children = get_children(array(
                    'post_parent' => $post_id,
                    'post_type' => $post_type,
                    'numberposts' => 1,
                ));
                
                if ($parent_id) {
                    $stats['posts_with_parents']++;
                }
                
                if (!empty($children)) {
                    $stats['posts_with_children']++;
                }
                
                if (!$parent_id && empty($children)) {
                    $stats['orphaned_posts']++;
                }
            }
            
            return $stats;
        }
    }
}