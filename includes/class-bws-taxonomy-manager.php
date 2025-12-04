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
     * Get singleton instance
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
        
        // Load integrations
        require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/integrations/class-bws-acf-integration.php';
        
        // Load Admin Columns Pro integration if available
        if (class_exists('AC\\Plugin')) {
            require_once BWS_TAX_MANAGER_PLUGIN_DIR . 'includes/integrations/class-bws-admin-columns-integration.php';
        }
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
        
        // AJAX hooks
        add_action('wp_ajax_bws_process_existing_posts', array($this, 'ajax_process_existing_posts'));
        add_action('wp_ajax_bws_validate_rule', array($this, 'ajax_validate_rule'));
        add_action('wp_ajax_bws_get_taxonomy_terms', array($this, 'ajax_get_taxonomy_terms'));
        add_action('wp_ajax_bws_get_post_type_taxonomies', array($this, 'ajax_get_post_type_taxonomies'));
        add_action('wp_ajax_bws_search_terms', array($this, 'ajax_search_terms'));
		add_action('wp_ajax_bws_validate_acf_field', array($this, 'ajax_validate_acf_field'));
		add_action('wp_ajax_bws_get_acf_fields', array($this, 'ajax_get_acf_fields'));
		add_action('wp_ajax_bws_test_related_posts', array($this, 'ajax_test_related_posts'));
		add_action('wp_ajax_bws_preview_level_restrictions', array($this, 'ajax_preview_level_restrictions'));
		
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
			'hierarchical_level_restriction' => new BWS_Hierarchical_Level_Restriction_Handler($this->settings)
        );
        
        // Initialize integrations
        new BWS_ACF_Integration($this->handlers);
        
        if (class_exists('AC\\Plugin')) {
            new BWS_Admin_Columns_Integration($this->handlers);
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('BWS Taxonomy Manager', 'bws-taxonomy-manager'),
            __('Taxonomy Manager', 'bws-taxonomy-manager'),
            'manage_options',
            'bws-taxonomy-manager',
            array($this->settings, 'render_settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ('settings_page_bws-taxonomy-manager' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'bws-taxonomy-manager-admin',
            BWS_TAX_MANAGER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'wp-util'),
            BWS_TAX_MANAGER_VERSION,
            true
        );
        
        wp_enqueue_style(
            'bws-taxonomy-manager-admin',
            BWS_TAX_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BWS_TAX_MANAGER_VERSION
        );
        
        wp_localize_script('bws-taxonomy-manager-admin', 'bwsTaxManager', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bws_taxonomy_manager_nonce'),
            'strings' => array(
                'processing' => __('Processing...', 'bws-taxonomy-manager'),
                'complete' => __('Processing complete!', 'bws-taxonomy-manager'),
                'error' => __('An error occurred. Please try again.', 'bws-taxonomy-manager'),
                'confirm_process' => __('This will process existing posts. Continue?', 'bws-taxonomy-manager')
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
     * AJAX handler for processing existing posts
     */
    public function ajax_process_existing_posts() {
        check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
        }
        
        $rule_type = sanitize_text_field($_POST['rule_type'] ?? '');
        $batch_size = absint($_POST['batch_size'] ?? 50);
        $offset = absint($_POST['offset'] ?? 0);
        
        if (!isset($this->handlers[$rule_type])) {
            wp_send_json_error(__('Invalid rule type.', 'bws-taxonomy-manager'));
        }
        
        $result = $this->handlers[$rule_type]->process_existing_posts($batch_size, $offset);
        
        wp_send_json_success($result);
    }
    
    /**
	 * Updated AJAX handler for rule validation
	 */
	public function ajax_validate_rule() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
		}
		
		$rule_type = sanitize_text_field($_POST['rule_type'] ?? '');
		$rule_data = $_POST['rule_data'] ?? array();
		
		if (!isset($this->handlers[$rule_type])) {
			wp_send_json_error(__('Invalid rule type.', 'bws-taxonomy-manager'));
		}
		
		$validation_result = $this->handlers[$rule_type]->validate_rule($rule_data);
		
		if ($validation_result['valid']) {
			wp_send_json_success(__('Rule is valid.', 'bws-taxonomy-manager'));
		} else {
			wp_send_json_error(array('errors' => $validation_result['errors']));
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
     * AJAX handler for searching terms
     */
    public function ajax_search_terms() {
        check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
        }
        
        $search = sanitize_text_field($_POST['search'] ?? '');
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
        
        $args = array(
            'hide_empty' => false,
            'number' => 50
        );
        
        if (!empty($search)) {
            $args['search'] = $search;
        }
        
        if (!empty($taxonomy) && taxonomy_exists($taxonomy)) {
            $args['taxonomy'] = $taxonomy;
        } else {
            $args['taxonomy'] = get_taxonomies(array('public' => true));
        }
        
        $terms = get_terms($args);
        
        if (is_wp_error($terms)) {
            wp_send_json_error(__('Error searching terms.', 'bws-taxonomy-manager'));
        }
        
        $formatted_terms = array();
        foreach ($terms as $term) {
            $formatted_terms[] = array(
                'term_id' => $term->term_id,
                'name' => $term->name,
                'taxonomy' => $term->taxonomy,
                'taxonomy_label' => get_taxonomy($term->taxonomy)->label ?? $term->taxonomy
            );
        }
        
        wp_send_json_success(array('terms' => $formatted_terms));
    }

	/**
	 * AJAX handler for bulk rule operations
	 */
	public function ajax_bulk_rule_operation() {
		check_ajax_referer('bws_taxonomy_manager_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
		}
		
		$operation = sanitize_text_field($_POST['operation'] ?? '');
		$rule_type = sanitize_text_field($_POST['rule_type'] ?? '');
		$rule_indices = array_map('absint', $_POST['rule_indices'] ?? array());
		
		if (!isset($this->handlers[$rule_type])) {
			wp_send_json_error(__('Invalid rule type.', 'bws-taxonomy-manager'));
		}
		
		$settings = $this->settings->get_settings();
		$rules_key = $rule_type . '_rules';
		
		if (!isset($settings[$rules_key])) {
			wp_send_json_error(__('Rules not found.', 'bws-taxonomy-manager'));
		}
		
		$updated_count = 0;
		
		switch ($operation) {
			case 'enable':
				foreach ($rule_indices as $index) {
					if (isset($settings[$rules_key][$index])) {
						$settings[$rules_key][$index]['enabled'] = true;
						$updated_count++;
					}
				}
				break;
				
			case 'disable':
				foreach ($rule_indices as $index) {
					if (isset($settings[$rules_key][$index])) {
						$settings[$rules_key][$index]['enabled'] = false;
						$updated_count++;
					}
				}
				break;
				
			case 'delete':
				// Delete in reverse order to maintain indices
				rsort($rule_indices);
				foreach ($rule_indices as $index) {
					if (isset($settings[$rules_key][$index])) {
						unset($settings[$rules_key][$index]);
						$updated_count++;
					}
				}
				// Re-index array
				$settings[$rules_key] = array_values($settings[$rules_key]);
				break;
				
			default:
				wp_send_json_error(__('Invalid operation.', 'bws-taxonomy-manager'));
		}
		
		if ($updated_count > 0) {
			$this->settings->update_settings($settings);
			wp_send_json_success(array(
				'message' => sprintf(__('%d rules updated.', 'bws-taxonomy-manager'), $updated_count),
				'updated_count' => $updated_count
			));
		} else {
			wp_send_json_error(__('No rules were updated.', 'bws-taxonomy-manager'));
		}
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

}
