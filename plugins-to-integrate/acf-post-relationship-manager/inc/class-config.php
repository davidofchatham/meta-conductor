<?php
/**
 * Configuration management for ACF Post Relationship Manager
 * 
 * @package ACF_Post_Relationship_Manager
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Configuration management class
 */
if (!class_exists('BWS_ACF_Relationship_Config')) {
	class BWS_ACF_Relationship_Config {
		
		/**
		 * Class instance
		 * 
		 * @var BWS_ACF_Relationship_Config
		 */
		private static $instance = null;
		
		/**
		 * Configuration array
		 * 
		 * @var array
		 */
		private $config = array();
		
		/**
		 * Get class instance
		 * 
		 * @return BWS_ACF_Relationship_Config
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
			$this->init_config();
		}
		
		/**
		 * Initialize configuration
		 */
		private function init_config() {
			$this->config = $this->get_default_config();
			
			// Allow filtering of configuration
			$this->config = apply_filters('bws_post_relationship_config', $this->config);
		}
		
		/**
		 * Get default configuration array
		 * 
		 * @return array
		 */
		private function get_default_config() {
			return array(
				'athletics_events' => array(
					'post_type' => 'athletics_events',
					'parent_field' => 'athletics_event_parent_event',
					'children_field' => 'athletics_event_sub_events',
					'enabled' => true,
				),
				// Add more configurations as needed
				// 'example_config' => array(
				//     'post_type' => 'custom_post_type',
				//     'parent_field' => 'parent_post_field',
				//     'children_field' => 'child_posts_field',
				//     'enabled' => true,
				// ),
			);
		}
		
		/**
		 * Get all configurations
		 * 
		 * @return array
		 */
		public function get_all_configs() {
			return $this->config;
		}
		
		/**
		 * Get configuration for a specific post type
		 * 
		 * @param string $post_type The post type to get configuration for
		 * @return array|false Configuration array or false if not found
		 */
		public function get_post_type_config($post_type) {
			foreach ($this->config as $config) {
				if (isset($config['post_type']) && 
					$config['post_type'] === $post_type && 
					!empty($config['enabled'])) {
					return $config;
				}
			}
			
			return false;
		}
		
		/**
		 * Check if a post type is monitored
		 * 
		 * @param string $post_type Post type to check
		 * @return bool
		 */
		public function is_post_type_monitored($post_type) {
			return false !== $this->get_post_type_config($post_type);
		}
		
		/**
		 * Get all monitored post types
		 * 
		 * @return array
		 */
		public function get_monitored_post_types() {
			$post_types = array();
			
			foreach ($this->config as $config) {
				if (!empty($config['enabled']) && !empty($config['post_type'])) {
					$post_types[] = $config['post_type'];
				}
			}
			
			return array_unique($post_types);
		}
		
		/**
		 * Add or update a configuration
		 * 
		 * @param string $key Configuration key
		 * @param array $config Configuration array
		 * @return bool Success status
		 */
		public function add_config($key, $config) {
			// Validate required fields
			if (empty($config['post_type'])) {
				return false;
			}
			
			// Set defaults
			$config = wp_parse_args($config, array(
				'parent_field' => '',
				'children_field' => '',
				'enabled' => true,
			));
			
			$this->config[$key] = $config;
			return true;
		}
		
		/**
		 * Remove a configuration
		 * 
		 * @param string $key Configuration key
		 * @return bool Success status
		 */
		public function remove_config($key) {
			if (isset($this->config[$key])) {
				unset($this->config[$key]);
				return true;
			}
			
			return false;
		}
		
		/**
		 * Enable/disable a configuration
		 * 
		 * @param string $key Configuration key
		 * @param bool $enabled Whether to enable or disable
		 * @return bool Success status
		 */
		public function set_config_status($key, $enabled) {
			if (isset($this->config[$key])) {
				$this->config[$key]['enabled'] = (bool) $enabled;
				return true;
			}
			
			return false;
		}
		
		/**
		 * Validate configuration array
		 * 
		 * @param array $config Configuration to validate
		 * @return bool|WP_Error True if valid, WP_Error if not
		 */
		public function validate_config($config) {
			// Check required fields
			if (empty($config['post_type'])) {
				return new WP_Error('missing_post_type', __('Post type is required.', 'acf-post-relationship-manager'));
			}
			
			// Check if post type exists
			if (!post_type_exists($config['post_type'])) {
				return new WP_Error('invalid_post_type', __('Post type does not exist.', 'acf-post-relationship-manager'));
			}
			
			// At least one field should be specified
			if (empty($config['parent_field']) && empty($config['children_field'])) {
				return new WP_Error('no_fields', __('At least one field (parent_field or children_field) must be specified.', 'acf-post-relationship-manager'));
			}
			
			return true;
		}
	}
}