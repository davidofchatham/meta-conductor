<?php
/**
 * Main BWS Taxonomy Manager Class
 * 
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Taxonomy_Manager {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Settings instance
     */
    private $settings;
    
    /**
     * Handler instances
     */
    private $handlers = array();

    /**
     * Conversion manager instance
     */
    private $conversion_manager;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get conversion manager instance
     */
    public function get_conversion_manager() {
        return $this->conversion_manager;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
        $this->init_handlers();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // Load base classes
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/class-bws-settings.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/abstracts/class-bws-handler-base.php';
        
        // Load handlers
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/handlers/class-bws-hierarchical-handler.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/handlers/class-bws-propagation-handler.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/handlers/class-bws-related-handler.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/handlers/class-bws-time-based-handler.php';
		require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/handlers/class-bws-related-post-terms-handler.php';
		require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/handlers/class-bws-hierarchical-level-restriction-handler.php';
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/handlers/class-bws-title-slug-handler.php';
        
        // Load integrations
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/integrations/class-bws-acf-integration.php';

        // Load Admin Columns Pro integration if available
        if (class_exists('AC\\Plugin')) {
            require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/integrations/class-bws-admin-columns-integration.php';
        }

        // Load conversion libraries (plugin-agnostic)
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/lib/term-migration/interface-term-migrator.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/lib/term-migration/class-term-migrator.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/lib/field-conversion/interface-field-converter.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/lib/field-conversion/class-field-converter.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/lib/data-mapper/interface-value-mapper.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/lib/data-mapper/class-value-mapper.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/lib/batch-processor/interface-batch-processor.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/lib/batch-processor/class-batch-processor.php';

        // Load conversion integration layer
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/conversion/class-bws-field-mapper.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/conversion/class-bws-data-processor.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/conversion/class-bws-preview-system.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/conversion/class-bws-conversion-manager.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/conversion/class-bws-conversion-ui.php';
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/conversion/class-bws-conversion-cli.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        }
        
        // AJAX hooks — data-population endpoints used by Wireframe field
        // options + Title/Slug preview/apply. Phase 2c dropped 5 rule-
        // management endpoints (toggle/delete/validate/process/search)
        // whose functionality is now in Wireframe's REST save path.
        add_action('wp_ajax_bws_get_taxonomy_terms', array($this, 'ajax_get_taxonomy_terms'));
        add_action('wp_ajax_bws_get_post_type_taxonomies', array($this, 'ajax_get_post_type_taxonomies'));
		add_action('wp_ajax_bws_validate_acf_field', array($this, 'ajax_validate_acf_field'));
		add_action('wp_ajax_bws_get_acf_fields', array($this, 'ajax_get_acf_fields'));
		add_action('wp_ajax_bws_test_related_posts', array($this, 'ajax_test_related_posts'));
		add_action('wp_ajax_bws_preview_level_restrictions', array($this, 'ajax_preview_level_restrictions'));

		// Conversion AJAX hooks
		add_action('wp_ajax_bws_meta_manager_conversion_get_fields', array($this, 'ajax_conversion_get_fields'));
		add_action('wp_ajax_bws_meta_manager_conversion_get_taxonomies', array($this, 'ajax_conversion_get_taxonomies'));
		add_action('wp_ajax_bws_meta_manager_conversion_get_taxonomy_terms', array($this, 'ajax_conversion_get_taxonomy_terms'));
		add_action('wp_ajax_bws_meta_manager_conversion_get_options', array($this, 'ajax_conversion_get_options'));
		add_action('wp_ajax_bws_meta_manager_conversion_estimate_size', array($this, 'ajax_conversion_estimate_size'));
		add_action('wp_ajax_bws_meta_manager_conversion_process_chunk', array($this, 'ajax_conversion_process_chunk'));
		add_action('wp_ajax_bws_meta_manager_conversion_process', array($this, 'ajax_conversion_process'));
		add_action('wp_ajax_bws_meta_manager_conversion_preview', array($this, 'ajax_conversion_preview'));
        add_action('wp_ajax_bws_title_slug_preview',          array($this, 'ajax_title_slug_preview'));
        add_action('wp_ajax_bws_title_slug_process_existing', array($this, 'ajax_title_slug_process_existing'));

        // Cleanup hook
        add_action('bws_taxonomy_manager_cleanup', array($this, 'cleanup_expired_rules'));
        
        // Post save hooks
        add_action('save_post', array($this, 'on_post_save'), 10, 3);
        add_action('wp_insert_post', array($this, 'on_post_insert'), 10, 3);
    }
    
    /**
     * Initialize handlers
     */
    private function init_handlers() {
        $this->settings = new BWS_Settings();
        
        $this->handlers = array(
			'hierarchical' => new BWS_Hierarchical_Handler($this->settings),
			'propagation' => new BWS_Propagation_Handler($this->settings),
			'related' => new BWS_Related_Handler($this->settings),
			'time_based' => new BWS_Time_Based_Handler($this->settings),
			'related_post_terms' => new BWS_Related_Post_Terms_Handler($this->settings),
			'hierarchical_level_restriction' => new BWS_Hierarchical_Level_Restriction_Handler($this->settings),
			'title_slug' => new BWS_Title_Slug_Handler($this->settings),
        );
        
        // Initialize integrations
        new BWS_ACF_Integration($this->handlers);

        if (class_exists('AC\\Plugin')) {
            new BWS_Admin_Columns_Integration($this->handlers);
        }

        // Initialize conversion manager
        $this->conversion_manager = new BWS_Conversion_Manager();

        // Register WP-CLI commands if available
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('bws-conversion', new BWS_Conversion_CLI($this->conversion_manager));
        }
    }
    
    /**
     * Add admin menu — legacy stub.
     *
     * Phase 2c (Wireframe swap) moved the settings UI to the top-level
     * "meta-conductor" menu. This function stays as a hook target to
     * avoid breaking any remaining references but registers no page.
     */
    public function add_admin_menu() {
        // Intentionally empty. Settings live at admin.php?page=meta-conductor.
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Conversion subpage under meta-conductor menu. Wireframe handles
        // its own asset enqueue for the settings page.
        if ('meta-conductor_page_meta-conductor-conversion' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'bws-conversion-admin',
            BWS_META_MANAGER_PLUGIN_URL . 'assets/js/conversion-admin.js',
            array('jquery', 'wp-util'),
            BWS_META_MANAGER_VERSION,
            true
        );

        wp_enqueue_style(
            'bws-conversion-admin',
            BWS_META_MANAGER_PLUGIN_URL . 'assets/css/conversion-admin.css',
            array(),
            BWS_META_MANAGER_VERSION
        );

        wp_localize_script('bws-conversion-admin', 'bwsMetaManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bws_taxonomy_manager_nonce'),
            'strings' => array(
                'confirm_conversion' => __('This will convert data. Continue?', 'bws-meta-manager'),
                'confirm_preview'    => __('Generate preview?', 'bws-meta-manager'),
                'skip_unmapped'      => __('Skip this value', 'bws-meta-manager'),
                'processing'         => __('Processing...', 'bws-meta-manager'),
                'complete'           => __('Conversion complete!', 'bws-meta-manager'),
                'error'              => __('An error occurred. Please try again.', 'bws-meta-manager'),
            )
        ));
    }
    
    /**
     * Handle post save events
     */
    public function on_post_save($post_id, $post, $update) {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Process through all handlers
        foreach ($this->handlers as $handler) {
            $handler->process_post($post_id, $post, $update);
        }
    }
    
    /**
     * Handle post insertion events
     */
    public function on_post_insert($post_id, $post, $update) {
        // Only process new posts
        if ($update) {
            return;
        }
        
        // Special handling for new child posts
        if ($post->post_parent > 0) {
            $this->handlers['propagation']->process_new_child_post($post_id, $post);
        }
    }
    
	/**
	 * AJAX handler for validating ACF fields
	 */
	public function ajax_validate_acf_field() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
		}
		
		$field_name = sanitize_text_field($_POST['field_name'] ?? '');
		
		if (empty($field_name)) {
			wp_send_json_error(__('Field name is required.', 'bws-taxonomy-manager'));
		}
		
		if (!function_exists('acf_get_field')) {
			wp_send_json_success(array(
				'exists' => false,
				'message' => __('ACF Pro not available for field validation.', 'bws-taxonomy-manager')
			));
		}
		
		// Try to get the field
		$field = acf_get_field($field_name);
		
		if ($field) {
			$field_type = $field['type'] ?? 'unknown';
			$is_relationship_field = in_array($field_type, array('post_object', 'relationship', 'page_link'));
			
			wp_send_json_success(array(
				'exists' => true,
				'field_type' => $field_type,
				'is_relationship_field' => $is_relationship_field,
				'field_label' => $field['label'] ?? $field_name
			));
		} else {
			wp_send_json_success(array(
				'exists' => false,
				'message' => __('Field not found in ACF.', 'bws-taxonomy-manager')
			));
		}
	}
	
	/**
	 * AJAX handler for getting ACF fields for a post type
	 */
	public function ajax_get_acf_fields() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
		}
		
		$post_type = sanitize_text_field($_POST['post_type'] ?? '');
		$field_types = $_POST['field_types'] ?? array('post_object', 'relationship');
		
		if (!post_type_exists($post_type)) {
			wp_send_json_error(__('Invalid post type.', 'bws-taxonomy-manager'));
		}
		
		$fields = array();
		
		if (function_exists('acf_get_field_groups')) {
			// Get field groups for this post type
			$field_groups = acf_get_field_groups(array(
				'post_type' => $post_type
			));
			
			foreach ($field_groups as $field_group) {
				$group_fields = acf_get_fields($field_group['key']);
				
				if ($group_fields) {
					foreach ($group_fields as $field) {
						if (in_array($field['type'], $field_types)) {
							$fields[] = array(
								'name' => $field['name'],
								'label' => $field['label'],
								'type' => $field['type'],
								'key' => $field['key']
							);
						}
					}
				}
			}
		}
		
		wp_send_json_success(array('fields' => $fields));
	}
	
	/**
	 * AJAX handler for testing related posts functionality
	 */
	public function ajax_test_related_posts() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
		}
		
		$post_id = absint($_POST['post_id'] ?? 0);
		$acf_field_name = sanitize_text_field($_POST['acf_field_name'] ?? '');
		
		if (!$post_id || !get_post($post_id)) {
			wp_send_json_error(__('Invalid post ID.', 'bws-taxonomy-manager'));
		}
		
		if (empty($acf_field_name)) {
			wp_send_json_error(__('ACF field name is required.', 'bws-taxonomy-manager'));
		}
		
		// Get related posts
		$related_posts = array();
		
		if (function_exists('get_field')) {
			$field_value = get_field($acf_field_name, $post_id);
			
			if (!empty($field_value)) {
				if (is_array($field_value)) {
					foreach ($field_value as $item) {
						if (is_object($item) && isset($item->ID)) {
							$related_posts[] = array(
								'ID' => $item->ID,
								'title' => $item->post_title,
								'type' => $item->post_type
							);
						} elseif (is_numeric($item)) {
							$related_post = get_post($item);
							if ($related_post) {
								$related_posts[] = array(
									'ID' => $related_post->ID,
									'title' => $related_post->post_title,
									'type' => $related_post->post_type
								);
							}
						}
					}
				} elseif (is_object($field_value) && isset($field_value->ID)) {
					$related_posts[] = array(
						'ID' => $field_value->ID,
						'title' => $field_value->post_title,
						'type' => $field_value->post_type
					);
				} elseif (is_numeric($field_value)) {
					$related_post = get_post($field_value);
					if ($related_post) {
						$related_posts[] = array(
							'ID' => $related_post->ID,
							'title' => $related_post->post_title,
							'type' => $related_post->post_type
						);
					}
				}
			}
		}
		
		wp_send_json_success(array(
			'related_posts' => $related_posts,
			'field_value_type' => gettype($field_value ?? null),
			'total_related' => count($related_posts)
		));
	}
	
	/**
	 * AJAX handler for previewing level restrictions
	 */
	public function ajax_preview_level_restrictions() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
		}
		
		$taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
		$term_ids = array_map('absint', $_POST['term_ids'] ?? array());
		$restriction_mode = sanitize_text_field($_POST['restriction_mode'] ?? 'one_per_level');
		$include_ancestors = !empty($_POST['include_ancestors']);
		
		if (!taxonomy_exists($taxonomy)) {
			wp_send_json_error(__('Invalid taxonomy.', 'bws-taxonomy-manager'));
		}
		
		if (empty($term_ids)) {
			wp_send_json_success(array(
				'original_terms' => array(),
				'restricted_terms' => array(),
				'removed_terms' => array(),
				'preview' => __('No terms selected.', 'bws-taxonomy-manager')
			));
		}
		
		// Create a temporary handler instance for preview
		$handler = new BWS_Hierarchical_Level_Restriction_Handler($this->settings);
		
		// Simulate the restriction logic
		$restricted_terms = $this->simulate_level_restrictions($term_ids, $taxonomy, $restriction_mode, $include_ancestors);
		
		$removed_terms = array_diff($term_ids, $restricted_terms);
		
		// Get term names for display
		$original_term_names = array();
		$restricted_term_names = array();
		$removed_term_names = array();
		
		foreach ($term_ids as $term_id) {
			$term = get_term($term_id, $taxonomy);
			if ($term && !is_wp_error($term)) {
				$original_term_names[] = $term->name;
			}
		}
		
		foreach ($restricted_terms as $term_id) {
			$term = get_term($term_id, $taxonomy);
			if ($term && !is_wp_error($term)) {
				$restricted_term_names[] = $term->name;
			}
		}
		
		foreach ($removed_terms as $term_id) {
			$term = get_term($term_id, $taxonomy);
			if ($term && !is_wp_error($term)) {
				$removed_term_names[] = $term->name;
			}
		}
		
		wp_send_json_success(array(
			'original_terms' => $original_term_names,
			'restricted_terms' => $restricted_term_names,
			'removed_terms' => $removed_term_names,
			'preview' => $this->generate_restriction_preview($original_term_names, $restricted_term_names, $removed_term_names, $restriction_mode)
		));
	}
	
	/**
	 * Simulate level restrictions for preview
	 */
	private function simulate_level_restrictions($term_ids, $taxonomy, $restriction_mode, $include_ancestors) {
		if (empty($term_ids)) {
			return $term_ids;
		}
		
		// Group terms by their hierarchical level
		$terms_by_level = array();
		
		foreach ($term_ids as $term_id) {
			$level = $this->get_term_level($term_id, $taxonomy);
			
			if (!isset($terms_by_level[$level])) {
				$terms_by_level[$level] = array();
			}
			
			$terms_by_level[$level][] = $term_id;
		}
		
		$final_terms = array();
		
		if ($restriction_mode === 'one_per_level') {
			// Keep only one term per level (prefer the last one)
			foreach ($terms_by_level as $level => $level_terms) {
				$final_terms[] = end($level_terms);
			}
		} elseif ($restriction_mode === 'deepest_only') {
			// Keep only terms from the deepest level
			$max_level = max(array_keys($terms_by_level));
			$final_terms = $terms_by_level[$max_level];
			
			// If including ancestors, add ancestors of the deepest terms
			if ($include_ancestors) {
				foreach ($final_terms as $term_id) {
					$ancestors = get_ancestors($term_id, $taxonomy);
					$final_terms = array_merge($final_terms, $ancestors);
				}
			}
		} elseif ($restriction_mode === 'shallowest_only') {
			// Keep only terms from the shallowest level
			$min_level = min(array_keys($terms_by_level));
			$final_terms = $terms_by_level[$min_level];
		}
		
		return array_unique($final_terms);
	}
	
	/**
	 * Get the hierarchical level of a term (helper for preview)
	 */
	private function get_term_level($term_id, $taxonomy) {
		$level = 0;
		$current_term = get_term($term_id, $taxonomy);
		
		while ($current_term && !is_wp_error($current_term) && $current_term->parent > 0) {
			$level++;
			$current_term = get_term($current_term->parent, $taxonomy);
			
			// Prevent infinite loops
			if ($level > 20) {
				break;
			}
		}
		
		return $level;
	}
	
	/**
	 * Generate restriction preview text
	 */
	private function generate_restriction_preview($original, $restricted, $removed, $mode) {
		$preview = '';
		
		$preview .= '<strong>' . __('Original terms:', 'bws-taxonomy-manager') . '</strong><br>';
		$preview .= implode(', ', $original) . '<br><br>';
		
		$preview .= '<strong>' . __('After restrictions:', 'bws-taxonomy-manager') . '</strong><br>';
		$preview .= implode(', ', $restricted) . '<br><br>';
		
		if (!empty($removed)) {
			$preview .= '<strong style="color: #d63638;">' . __('Removed terms:', 'bws-taxonomy-manager') . '</strong><br>';
			$preview .= '<span style="color: #d63638;">' . implode(', ', $removed) . '</span><br><br>';
		}
		
		$mode_description = '';
		switch ($mode) {
			case 'one_per_level':
				$mode_description = __('Only one term per hierarchical level is allowed.', 'bws-taxonomy-manager');
				break;
			case 'deepest_only':
				$mode_description = __('Only the deepest level terms are kept.', 'bws-taxonomy-manager');
				break;
			case 'shallowest_only':
				$mode_description = __('Only the shallowest level terms are kept.', 'bws-taxonomy-manager');
				break;
		}
		
		$preview .= '<em>' . $mode_description . '</em>';
		
		return $preview;
	}
    
    /**
     * AJAX handler for getting taxonomy terms
     */
    public function ajax_get_taxonomy_terms() {
        check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
        }
        
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
        
        if (!taxonomy_exists($taxonomy)) {
            wp_send_json_error(__('Invalid taxonomy.', 'bws-taxonomy-manager'));
        }
        
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 100
        ));
        
        if (is_wp_error($terms)) {
            wp_send_json_error(__('Error loading terms.', 'bws-taxonomy-manager'));
        }
        
        $formatted_terms = array();
        foreach ($terms as $term) {
            $formatted_terms[] = array(
                'term_id' => $term->term_id,
                'name' => $term->name,
                'taxonomy' => $term->taxonomy
            );
        }
        
        wp_send_json_success(array('terms' => $formatted_terms));
    }
    
    /**
     * AJAX handler for getting post type taxonomies
     */
    public function ajax_get_post_type_taxonomies() {
        check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
        }
        
        $post_type = sanitize_text_field($_POST['post_type'] ?? '');
        
        if (!post_type_exists($post_type)) {
            wp_send_json_error(__('Invalid post type.', 'bws-taxonomy-manager'));
        }
        
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        
        $formatted_taxonomies = array();
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->public) {
                $formatted_taxonomies[] = array(
                    'name' => $taxonomy->name,
                    'label' => $taxonomy->label,
                    'hierarchical' => $taxonomy->hierarchical
                );
            }
        }
        
        wp_send_json_success(array('taxonomies' => $formatted_taxonomies));
    }
    
    /**
     * Cleanup expired time-based rules
     */
    public function cleanup_expired_rules() {
        if (isset($this->handlers['time_based'])) {
            $this->handlers['time_based']->cleanup_expired_rules();
        }
    }
    
    /**
     * Get handler instance
     */
    public function get_handler($type) {
        return $this->handlers[$type] ?? null;
    }
    
    /**
	 * Get all handlers (for external access)
	 */
	public function get_handlers() {
		return $this->handlers;
	}
	
	/**
	 * Get handler summary information for dashboard
	 */
	public function get_handlers_summary() {
		$summary = array();
		
		foreach ($this->handlers as $type => $handler) {
			if (method_exists($handler, 'get_rules_summary')) {
				$summary[$type] = $handler->get_rules_summary();
			} else {
				// Fallback for handlers without summary method
				$rules = $handler->get_enabled_rules();
				$summary[$type] = array(
					'total_rules' => count($rules),
					'enabled_rules' => count($rules)
				);
			}
		}
		
		return $summary;
	}
    
    /**
     * Get settings instance
     */
    public function get_settings() {
        return $this->settings;
    }

	/**
	 * Check system requirements and compatibility
	 */
	public function check_system_requirements() {
		$requirements = array(
			'php_version' => array(
				'required' => '8.1',
				'current' => PHP_VERSION,
				'met' => version_compare(PHP_VERSION, '8.1', '>=')
			),
			'wordpress_version' => array(
				'required' => '5.0',
				'current' => get_bloginfo('version'),
				'met' => version_compare(get_bloginfo('version'), '5.0', '>=')
			),
			'acf_pro' => array(
				'required' => 'Recommended',
				'current' => function_exists('get_field') ? 'Active' : 'Not Active',
				'met' => function_exists('get_field')
			),
			'admin_columns_pro' => array(
				'required' => 'Optional',
				'current' => class_exists('ACP\\Plugin') ? 'Active' : 'Not Active',
				'met' => true // Optional, so always met
			)
		);
		
		return $requirements;
	}
	
	/**
	 * Get plugin status information
	 */
	public function get_plugin_status() {
		$handlers_summary = $this->get_handlers_summary();
		$requirements = $this->check_system_requirements();
		
		$total_rules = 0;
		foreach ($handlers_summary as $handler_summary) {
			$total_rules += $handler_summary['total_rules'] ?? 0;
		}
		
		return array(
			'total_handlers' => count($this->handlers),
			'total_rules' => $total_rules,
			'requirements_met' => array_reduce($requirements, function($carry, $req) {
				return $carry && $req['met'];
			}, true),
			'handlers_summary' => $handlers_summary,
			'requirements' => $requirements
		);
	}

	/**
	 * Get statistics for dashboard widget
	 */
	public function get_dashboard_stats() {
		$stats = array(
			'total_rules' => 0,
			'active_rules' => 0,
			'handlers' => array()
		);
		
		foreach ($this->handlers as $handler_type => $handler) {
			$rules = $handler->get_enabled_rules();
			$handler_stats = array(
				'total_rules' => count($rules),
				'active_rules' => count($rules)
			);
			
			// Special handling for time-based rules
			if ($handler_type === 'time_based' && method_exists($handler, 'get_active_rules')) {
				$handler_stats['active_rules'] = count($handler->get_active_rules());
			}
			
			$stats['handlers'][$handler_type] = $handler_stats;
			$stats['total_rules'] += $handler_stats['total_rules'];
			$stats['active_rules'] += $handler_stats['active_rules'];
		}
		
		return $stats;
	}
	
	/**
	 * AJAX handler for getting dashboard stats
	 */
	public function ajax_get_dashboard_stats() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
		}

		$stats = $this->get_dashboard_stats();

		wp_send_json_success($stats);
	}

	// ========================================
	// Conversion AJAX Handlers
	// ========================================

	/**
	 * AJAX handler: Get ACF fields
	 */
	public function ajax_conversion_get_fields() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'bws-meta-manager')]);
		}

		// Get all field groups
		$field_groups = $this->conversion_manager->get_field_groups();

		// Filter by field_type_filter if provided
		$field_type_filter = sanitize_text_field($_POST['field_type_filter'] ?? '');
		if ($field_type_filter === 'option_fields') {
			// Only include fields that support options
			$option_types = ['select', 'checkbox', 'radio', 'button_group'];

			// Filter fields within each group
			foreach ($field_groups as &$group) {
				if (isset($group['fields']) && is_array($group['fields'])) {
					$group['fields'] = array_filter($group['fields'], function($field) use ($option_types) {
						return in_array($field['type'] ?? '', $option_types);
					});
					// Re-index array
					$group['fields'] = array_values($group['fields']);
				}
			}
			unset($group); // Break reference
		}

		// Convert to numeric array (Field Mapper uses associative array with keys)
		// JavaScript needs a proper array, not an object
		wp_send_json_success(array_values($field_groups));
	}

	/**
	 * AJAX handler: Get taxonomies
	 */
	public function ajax_conversion_get_taxonomies() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'bws-meta-manager')]);
		}

		$taxonomies = $this->conversion_manager->get_taxonomies();

		// Convert to numeric array (Field Mapper uses associative array)
		wp_send_json_success(['taxonomies' => array_values($taxonomies)]);
	}

	/**
	 * AJAX handler: Get taxonomy terms
	 */
	public function ajax_conversion_get_taxonomy_terms() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'bws-meta-manager')]);
		}

		$taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');

		if (empty($taxonomy)) {
			wp_send_json_error(['message' => __('Taxonomy is required', 'bws-meta-manager')]);
		}

		$terms = get_terms([
			'taxonomy' => $taxonomy,
			'hide_empty' => false,
		]);

		if (is_wp_error($terms)) {
			wp_send_json_error(['message' => $terms->get_error_message()]);
		}

		wp_send_json_success(['terms' => $terms]);
	}

	/**
	 * AJAX handler: Get field options
	 */
	public function ajax_conversion_get_options() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'bws-meta-manager')]);
		}

		$field_key = sanitize_text_field($_POST['field_key'] ?? '');

		if (empty($field_key)) {
			wp_send_json_error(['message' => __('Field key is required', 'bws-meta-manager')]);
		}

		$field_data = $this->conversion_manager->get_field_by_key($field_key);

		if (!$field_data || empty($field_data['choices'])) {
			wp_send_json_error(['message' => __('Field has no options', 'bws-meta-manager')]);
		}

		wp_send_json_success(['options' => $field_data['choices']]);
	}

	/**
	 * AJAX handler: Estimate conversion size
	 */
	public function ajax_conversion_estimate_size() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'bws-meta-manager')]);
		}

		// For now, return a simple estimate
		// TODO: Implement actual size estimation
		wp_send_json_success([
			'estimated_items' => 0,
			'estimated_batches' => 0
		]);
	}

	/**
	 * AJAX handler: Process conversion chunk
	 */
	public function ajax_conversion_process_chunk() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'bws-meta-manager')]);
		}

		// For now, return success
		// TODO: Implement chunk processing
		wp_send_json_success([
			'processed' => 0,
			'total' => 0,
			'complete' => true
		]);
	}

	/**
	 * AJAX handler: Process conversion
	 */
	public function ajax_conversion_process() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'bws-meta-manager')]);
		}

		$conversion_type = sanitize_text_field($_POST['conversion_type'] ?? '');
		$config = $_POST['config'] ?? [];

		if (empty($conversion_type)) {
			wp_send_json_error(['message' => __('Conversion type is required', 'bws-meta-manager')]);
		}

		try {
			if ($conversion_type === 'copy_data') {
				$result = $this->conversion_manager->execute_copy_conversion($config);
			} elseif ($conversion_type === 'map_data') {
				$result = $this->conversion_manager->execute_map_conversion($config);
			} else {
				wp_send_json_error(['message' => __('Invalid conversion type', 'bws-meta-manager')]);
				return;
			}

			wp_send_json_success($result);
		} catch (Exception $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

	/**
	 * AJAX handler: Generate preview
	 */
	public function ajax_conversion_preview() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'bws-meta-manager')]);
		}

		$conversion_type = sanitize_text_field($_POST['conversion_type'] ?? '');
		$config = $_POST['config'] ?? [];
		$sample_count = intval($_POST['sample_count'] ?? 10);

		if (empty($conversion_type)) {
			wp_send_json_error(['message' => __('Conversion type is required', 'bws-meta-manager')]);
		}

		try {
			if ($conversion_type === 'copy_data') {
				$result = $this->conversion_manager->generate_copy_preview($config, $sample_count);
			} elseif ($conversion_type === 'map_data') {
				$result = $this->conversion_manager->generate_map_preview($config, $sample_count);
			} else {
				wp_send_json_error(['message' => __('Invalid conversion type', 'bws-meta-manager')]);
				return;
			}

			wp_send_json_success($result);
		} catch (Exception $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

    public function ajax_title_slug_preview() {
        check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'bws-taxonomy-manager')]);
        }
        $rule_index = intval($_POST['rule_index'] ?? -1);
        $storage    = BWS_Storage_Factory::get_instance();
        $rules      = $storage->get_rules('title_slug_rules');
        $rule       = $rules[$rule_index] ?? null;
        if (!$rule) {
            wp_send_json_error(['message' => __('Rule not found', 'bws-taxonomy-manager')]);
        }
        $result = $this->handlers['title_slug']->preview_rule($rule);
        isset($result['error']) ? wp_send_json_error($result) : wp_send_json_success($result);
    }

    public function ajax_title_slug_process_existing() {
        check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'bws-taxonomy-manager')]);
        }
        $batch_size = intval($_POST['batch_size'] ?? 50);
        $offset     = intval($_POST['offset'] ?? 0);
        $result     = $this->handlers['title_slug']->process_existing_posts($batch_size, $offset);
        wp_send_json_success($result);
    }

}
