<?php
/**
 * BWS Taxonomy Manager ACF Integration
 * Handles integration with Advanced Custom Fields Pro taxonomy fields
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Integrations;

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

class AcfIntegration {
	
	/**
	 * Handler instances
	 */
	private $handlers;
	
	/**
	 * Constructor
	 */
	public function __construct($handlers) {
		$this->handlers = $handlers;
		
		// Only initialize if ACF is active
		if ($this->is_acf_active()) {
			$this->init_hooks();
		}
	}
	
	/**
	 * Check if ACF Pro is active
	 */
	private function is_acf_active() {
		return function_exists('get_field') && function_exists('update_field');
	}
	
	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Hook into ACF field updates
		add_action('acf/save_post', array($this, 'on_acf_save_post'), 15);
		
		// Hook into ACF field value updates
		add_filter('acf/update_value/type=taxonomy', array($this, 'on_taxonomy_field_update'), 10, 3);
		
		// Add custom ACF field settings
		add_action('acf/render_field_settings/type=taxonomy', array($this, 'add_taxonomy_field_settings'));
		
		// Modify ACF taxonomy field choices if needed
		add_filter('acf/fields/taxonomy/query', array($this, 'modify_taxonomy_field_query'), 10, 2);
	}
	
	/**
	 * Handle ACF post save
	 */
	public function on_acf_save_post($post_id) {
		// Kill-switch: this whole term-sync engine reimplements 6 of 7 rule
		// types on the OLD rule schema and is redundant for Load-Terms-on ACF
		// taxonomy fields (handlers read native terms; ACF keeps native == field
		// value). Disabled by default; the migrated handlers are the sole
		// writers. Slated for wholesale deletion after verify. (SPEC §V7/§V9)
		if (!apply_filters('bws_mc_acf_sync_engine_enabled', false)) {
			return;
		}

		// Skip if not a real post ID
		if (!is_numeric($post_id)) {
			return;
		}
		
		$post = get_post($post_id);
		if (!$post) {
			return;
		}
		
		// Get all ACF taxonomy fields for this post
		$taxonomy_fields = $this->get_acf_taxonomy_fields($post_id);
		
		if (empty($taxonomy_fields)) {
			return;
		}
		
		// Process each handler
		foreach ($this->handlers as $handler_type => $handler) {
			$this->process_handler_for_acf_fields($post_id, $post, $handler, $taxonomy_fields);
		}
	}
	
	/**
	 * Handle ACF taxonomy field value updates
	 */
	public function on_taxonomy_field_update($value, $post_id, $field) {
		// Skip if not a real post ID
		if (!is_numeric($post_id)) {
			return $value;
		}
		
		// Store the field update for processing
		if (!isset($GLOBALS['bws_acf_field_updates'])) {
			$GLOBALS['bws_acf_field_updates'] = array();
		}
		
		$GLOBALS['bws_acf_field_updates'][$post_id][$field['name']] = array(
			'field' => $field,
			'value' => $value,
			'taxonomy' => $field['taxonomy']
		);
		
		return $value;
	}
	
	/**
	 * Get ACF taxonomy fields for a post
	 */
	private function get_acf_taxonomy_fields($post_id) {
		if (!function_exists('get_field_objects')) {
			return array();
		}
		
		$field_objects = get_field_objects($post_id);
		if (!$field_objects) {
			return array();
		}
		
		$taxonomy_fields = array();
		
		foreach ($field_objects as $field_name => $field) {
			if ($field['type'] === 'taxonomy' && !empty($field['taxonomy'])) {
				$taxonomy_fields[$field_name] = $field;
			}
		}
		
		return $taxonomy_fields;
	}
	
	/**
	 * Process handler for ACF fields
	 */
	private function process_handler_for_acf_fields($post_id, $post, $handler, $taxonomy_fields) {
		$handler_type = $this->get_handler_type($handler);
		
		switch ($handler_type) {
			case 'hierarchical':
				$this->process_hierarchical_acf($post_id, $post, $handler, $taxonomy_fields);
				break;
				
			case 'propagation':
				$this->process_propagation_acf($post_id, $post, $handler, $taxonomy_fields);
				break;
				
			case 'related':
				$this->process_related_acf($post_id, $post, $handler, $taxonomy_fields);
				break;
				
			case 'time_based':
				// Time-based rules don't typically interact with ACF fields directly
				break;

			case 'related_post_terms':
				$this->process_related_post_terms_acf($post_id, $post, $handler, $taxonomy_fields);
				break;
				
			case 'hierarchical_level_restriction':
				$this->process_level_restriction_acf($post_id, $post, $handler, $taxonomy_fields);
				break;
		}
	}
	
	/**
	 * Process hierarchical rules for ACF fields
	 */
	private function process_hierarchical_acf($post_id, $post, $handler, $taxonomy_fields) {
		$rules = $handler->get_enabled_rules();
		
		foreach ($rules as $rule) {
			if (!$this->rule_applies_to_post($rule, $post)) {
				continue;
			}
			
			$taxonomy = $rule['taxonomy'];
			
			// Find ACF fields for this taxonomy
			foreach ($taxonomy_fields as $field_name => $field) {
				if ($field['taxonomy'] === $taxonomy) {
					$this->apply_hierarchical_terms_to_acf_field($post_id, $field, $rule);
				}
			}
		}
	}
	
	/**
	 * Apply hierarchical terms to ACF field
	 */
	private function apply_hierarchical_terms_to_acf_field($post_id, $field, $rule) {
		$current_terms = $this->get_acf_field_terms($post_id, $field);
		
		if (empty($current_terms)) {
			return;
		}
		
		$terms_to_add = array();
		$taxonomy = $field['taxonomy'];
		
		// Get ancestors for each current term
		foreach ($current_terms as $term_id) {
			$ancestors = $this->get_term_ancestors($term_id, $taxonomy, $rule);
			$terms_to_add = array_merge($terms_to_add, $ancestors);
		}
		
		// Remove duplicates and current terms
		$terms_to_add = array_unique($terms_to_add);
		$terms_to_add = array_diff($terms_to_add, $current_terms);
		
		if (!empty($terms_to_add)) {
			$all_terms = array_merge($current_terms, $terms_to_add);
			$this->update_acf_field_terms($post_id, $field, $all_terms);
			
			// Also update native taxonomy
			wp_set_object_terms($post_id, $all_terms, $taxonomy);
		}
	}
	
	/**
	 * Process propagation rules for ACF fields
	 */
	private function process_propagation_acf($post_id, $post, $handler, $taxonomy_fields) {
		$rules = $handler->get_enabled_rules();
		
		foreach ($rules as $rule) {
			if (!$this->rule_applies_to_post($rule, $post)) {
				continue;
			}
			
			$taxonomy = $rule['taxonomy'];
			
			// Check if this post has children or parent
			if ($post->post_parent > 0) {
				// Child post - inherit from parent
				$this->inherit_acf_terms_from_parent($post_id, $post, $rule, $taxonomy_fields);
			} else {
				// Potential parent - propagate to children
				$this->propagate_acf_terms_to_children($post_id, $post, $rule, $taxonomy_fields);
			}
		}
	}
	
	/**
	 * Inherit ACF terms from parent
	 */
	private function inherit_acf_terms_from_parent($post_id, $post, $rule, $taxonomy_fields) {
		$parent_id = $post->post_parent;
		$taxonomy = $rule['taxonomy'];
		$conflict_handling = $rule['conflict_handling'] ?? 'merge';
		
		// Get parent's ACF taxonomy field values
		$parent_taxonomy_fields = $this->get_acf_taxonomy_fields($parent_id);
		
		foreach ($taxonomy_fields as $field_name => $field) {
			if ($field['taxonomy'] !== $taxonomy) {
				continue;
			}
			
			// Find corresponding field in parent
			$parent_field = $parent_taxonomy_fields[$field_name] ?? null;
			if (!$parent_field) {
				continue;
			}
			
			$parent_terms = $this->get_acf_field_terms($parent_id, $parent_field);
			
			if (!empty($parent_terms)) {
				$current_terms = $this->get_acf_field_terms($post_id, $field);
				$new_terms = $this->merge_terms_by_conflict_handling($current_terms, $parent_terms, $conflict_handling);
				
				$this->update_acf_field_terms($post_id, $field, $new_terms);
				
				// Sync with native taxonomy
				wp_set_object_terms($post_id, $new_terms, $taxonomy);
			}
		}
	}
	
	/**
	 * Propagate ACF terms to children
	 */
	private function propagate_acf_terms_to_children($post_id, $post, $rule, $taxonomy_fields) {
		$children = $this->get_child_posts($post_id, $rule['post_type']);
		
		if (empty($children)) {
			return;
		}
		
		$taxonomy = $rule['taxonomy'];
		$conflict_handling = $rule['conflict_handling'] ?? 'merge';
		
		foreach ($taxonomy_fields as $field_name => $field) {
			if ($field['taxonomy'] !== $taxonomy) {
				continue;
			}
			
			$parent_terms = $this->get_acf_field_terms($post_id, $field);
			
			if (empty($parent_terms)) {
				continue;
			}
			
			foreach ($children as $child_id) {
				$child_taxonomy_fields = $this->get_acf_taxonomy_fields($child_id);
				$child_field = $child_taxonomy_fields[$field_name] ?? null;
				
				if ($child_field) {
					$current_terms = $this->get_acf_field_terms($child_id, $child_field);
					$new_terms = $this->merge_terms_by_conflict_handling($current_terms, $parent_terms, $conflict_handling);
					
					$this->update_acf_field_terms($child_id, $child_field, $new_terms);
					
					// Sync with native taxonomy
					wp_set_object_terms($child_id, $new_terms, $taxonomy);
				}
			}
		}
	}

	/**
	 * Sync related post terms via ACF
	 */
	private function sync_related_post_terms($post_id, $rule, $taxonomy_fields) {
		$acf_field_name = $rule['acf_field_name'];
		$source_taxonomy = $rule['source_taxonomy'];
		$target_taxonomy = $rule['target_taxonomy'];
		$conflict_handling = $rule['conflict_handling'] ?? 'merge';
		
		// Get related posts from ACF field
		$related_posts = $this->get_acf_related_posts($post_id, $acf_field_name);
		
		if (empty($related_posts)) {
			// If no related posts and bidirectional, clear target taxonomy terms
			if (!empty($rule['bidirectional'])) {
				$this->clear_taxonomy_terms($post_id, $target_taxonomy, $taxonomy_fields);
			}
			return;
		}
		
		// Collect terms from all related posts
		$terms_to_apply = array();
		
		foreach ($related_posts as $related_post_id) {
			// Get terms from both native taxonomy and ACF fields
			$related_terms = $this->get_post_terms($related_post_id, $source_taxonomy);
			$terms_to_apply = array_merge($terms_to_apply, $related_terms);
		}
		
		$terms_to_apply = array_unique($terms_to_apply);
		
		if (!empty($terms_to_apply)) {
			// Apply terms to target taxonomy (both native and ACF)
			$this->apply_terms_to_taxonomy($post_id, $target_taxonomy, $terms_to_apply, $conflict_handling, $taxonomy_fields);
		} elseif (!empty($rule['bidirectional'])) {
			// No terms found and bidirectional - clear target taxonomy
			$this->clear_taxonomy_terms($post_id, $target_taxonomy, $taxonomy_fields);
		}
	}

	/**
	 * Get post terms from both native taxonomy and ACF fields
	 */
	private function get_post_terms($post_id, $taxonomy) {
		$terms = array();
		
		// Get native taxonomy terms
		$native_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
		if (!is_wp_error($native_terms)) {
			$terms = array_merge($terms, $native_terms);
		}
		
		// Get ACF taxonomy field terms
		if (function_exists('get_field_objects')) {
			$field_objects = get_field_objects($post_id);
			if ($field_objects) {
				foreach ($field_objects as $field) {
					if ($field['type'] === 'taxonomy' && 
						isset($field['taxonomy']) && 
						$field['taxonomy'] === $taxonomy) {
						
						$acf_terms = $this->get_acf_field_terms($post_id, $field);
						$terms = array_merge($terms, $acf_terms);
					}
				}
			}
		}
		
		return array_unique(array_filter($terms));
	}
	
	/**
	 * Apply terms to taxonomy (both native and ACF)
	 */
	private function apply_terms_to_taxonomy($post_id, $taxonomy, $terms, $conflict_handling, $taxonomy_fields) {
		// Apply to native taxonomy
		$this->apply_terms_by_conflict_handling($post_id, $taxonomy, $terms, $conflict_handling);
		
		// Apply to ACF fields
		foreach ($taxonomy_fields as $field_name => $field) {
			if ($field['taxonomy'] === $taxonomy) {
				$current_terms = $this->get_acf_field_terms($post_id, $field);
				$new_terms = $this->merge_terms_by_conflict_handling($current_terms, $terms, $conflict_handling);
				
				if ($new_terms !== $current_terms) {
					$this->update_acf_field_terms($post_id, $field, $new_terms);
				}
			}
		}
	}
	
	/**
	 * Clear taxonomy terms (both native and ACF)
	 */
	private function clear_taxonomy_terms($post_id, $taxonomy, $taxonomy_fields) {
		// Clear native taxonomy
		wp_set_object_terms($post_id, array(), $taxonomy);
		
		// Clear ACF fields
		foreach ($taxonomy_fields as $field_name => $field) {
			if ($field['taxonomy'] === $taxonomy) {
				$this->update_acf_field_terms($post_id, $field, array());
			}
		}
	}
	
	/**
	 * Apply terms based on conflict handling
	 */
	private function apply_terms_by_conflict_handling($post_id, $taxonomy, $terms, $conflict_handling) {
		switch ($conflict_handling) {
			case 'replace':
				wp_set_object_terms($post_id, $terms, $taxonomy);
				break;
				
			case 'merge':
				$existing_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
				if (is_wp_error($existing_terms)) {
					$existing_terms = array();
				}
				$merged_terms = array_unique(array_merge($existing_terms, $terms));
				wp_set_object_terms($post_id, $merged_terms, $taxonomy);
				break;
				
			case 'skip':
				$existing_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
				if (is_wp_error($existing_terms)) {
					$existing_terms = array();
				}
				
				// Only apply if no existing terms
				if (empty($existing_terms)) {
					wp_set_object_terms($post_id, $terms, $taxonomy);
				}
				break;
		}
	}
	
	/**
	 * Calculate restricted terms based on hierarchical levels
	 */
	private function calculate_restricted_terms($term_ids, $taxonomy, $restriction_mode, $include_ancestors) {
		if (empty($term_ids)) {
			return $term_ids;
		}
		
		// Group terms by their hierarchical level
		$terms_by_level = $this->group_terms_by_level($term_ids, $taxonomy);
		
		$final_terms = array();
		
		if ($restriction_mode === 'one_per_level') {
			// Keep only one term per level (prefer the last one added/most specific)
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
	 * Group terms by their hierarchical level
	 */
	private function group_terms_by_level($term_ids, $taxonomy) {
		$terms_by_level = array();
		
		foreach ($term_ids as $term_id) {
			$level = $this->get_term_level($term_id, $taxonomy);
			
			if (!isset($terms_by_level[$level])) {
				$terms_by_level[$level] = array();
			}
			
			$terms_by_level[$level][] = $term_id;
		}
		
		return $terms_by_level;
	}
	
	/**
	 * Get the hierarchical level of a term (0 = root level)
	 */
	private function get_term_level($term_id, $taxonomy) {
		static $cache = array();
		$cache_key = $taxonomy . '_' . $term_id;
		
		if (isset($cache[$cache_key])) {
			return $cache[$cache_key];
		}
		
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
		
		$cache[$cache_key] = $level;
		
		return $level;
	}
	
	/**
	 * Check if an ACF field was updated in the current request
	 */
	private function was_acf_field_updated($post_id, $field_name) {
		// Check if we have stored field updates for this request
		if (isset($GLOBALS['bws_acf_field_updates'][$post_id][$field_name])) {
			return true;
		}
		
		// Check if the field exists in POST data (for form submissions)
		if (isset($_POST['acf'])) {
			$acf_data = $_POST['acf'];
			
			// Look for the field in ACF data
			foreach ($acf_data as $field_key => $value) {
				if (function_exists('acf_get_field')) {
					$field = acf_get_field($field_key);
					if ($field && $field['name'] === $field_name) {
						return true;
					}
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Debug log with ACF integration context
	 */
	private function debug_log($message, $data = null) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$log_message = '[BWS Taxonomy Manager - ACF Integration] ' . $message;
			if ($data !== null) {
				$log_message .= ' - Data: ' . print_r($data, true);
			}
			error_log($log_message);
		}
	}
	
	/**
	 * Enhanced field validation for relationship fields
	 */
	public function validate_relationship_field($field_name, $post_type = null) {
		if (!function_exists('acf_get_field')) {
			return array(
				'valid' => false,
				'message' => __('ACF Pro not available.', 'bws-taxonomy-manager')
			);
		}
		
		$field = acf_get_field($field_name);
		
		if (!$field) {
			return array(
				'valid' => false,
				'message' => __('Field not found.', 'bws-taxonomy-manager')
			);
		}
		
		$valid_types = array('post_object', 'relationship', 'page_link');
		
		if (!in_array($field['type'], $valid_types)) {
			return array(
				'valid' => false,
				'message' => sprintf(
					__('Field must be of type: %s. Found: %s', 'bws-taxonomy-manager'),
					implode(', ', $valid_types),
					$field['type']
				)
			);
		}
		
		// Additional validation for post type compatibility
		if ($post_type && !empty($field['post_type'])) {
			$field_post_types = is_array($field['post_type']) ? $field['post_type'] : array($field['post_type']);
			
			// Check if the field is configured for the specified post type
			if (!in_array($post_type, $field_post_types) && !in_array('all', $field_post_types)) {
				return array(
					'valid' => false,
					'message' => sprintf(
						__('Field is not configured for post type: %s', 'bws-taxonomy-manager'),
						$post_type
					)
				);
			}
		}
		
		return array(
			'valid' => true,
			'message' => __('Field is valid.', 'bws-taxonomy-manager'),
			'field_info' => array(
				'type' => $field['type'],
				'label' => $field['label'],
				'return_format' => $field['return_format'] ?? 'object'
			)
		);
	}
	
	/**
	 * Apply level restrictions to ACF field
	 */
	private function apply_level_restrictions_to_acf_field($post_id, $field, $rule) {
		$current_terms = $this->get_acf_field_terms($post_id, $field);
		
		if (empty($current_terms)) {
			return;
		}
		
		$taxonomy = $field['taxonomy'];
		$restriction_mode = $rule['restriction_mode'] ?? 'one_per_level';
		$include_ancestors = !empty($rule['include_ancestors']);
		
		// Calculate restricted terms
		$restricted_terms = $this->calculate_restricted_terms($current_terms, $taxonomy, $restriction_mode, $include_ancestors);
		
		if ($restricted_terms !== $current_terms) {
			// Update ACF field
			$this->update_acf_field_terms($post_id, $field, $restricted_terms);
			
			// Update native taxonomy
			wp_set_object_terms($post_id, $restricted_terms, $taxonomy);
			
			$this->debug_log(
				sprintf('Applied ACF level restrictions to post %d for taxonomy %s', $post_id, $taxonomy),
				array(
					'field' => $field['name'],
					'original_terms' => $current_terms,
					'restricted_terms' => $restricted_terms
				)
			);
		}
	}
	
	/**
	 * Get related posts from ACF field
	 */
	private function get_acf_related_posts($post_id, $field_name) {
		if (!function_exists('get_field')) {
			return array();
		}
		
		$field_value = get_field($field_name, $post_id);
		
		if (empty($field_value)) {
			return array();
		}
		
		$related_post_ids = array();
		
		// Handle different ACF field return formats
		if (is_array($field_value)) {
			foreach ($field_value as $item) {
				if (is_object($item) && isset($item->ID)) {
					$related_post_ids[] = $item->ID;
				} elseif (is_numeric($item)) {
					$related_post_ids[] = absint($item);
				}
			}
		} elseif (is_object($field_value) && isset($field_value->ID)) {
			$related_post_ids[] = $field_value->ID;
		} elseif (is_numeric($field_value)) {
			$related_post_ids[] = absint($field_value);
		}
		
		return array_unique(array_filter($related_post_ids));
	}

	/**
	 * Process related rules for ACF fields
	 */
	private function process_related_acf($post_id, $post, $handler, $taxonomy_fields) {
		$rules = $handler->get_enabled_rules();
		
		foreach ($rules as $rule) {
			if (!$this->rule_applies_to_post($rule, $post)) {
				continue;
			}
			
			$this->apply_related_terms_for_acf($post_id, $rule, $taxonomy_fields);
		}
	}
	
	/**
	 * Process related post terms for ACF fields
	 */
	private function process_related_post_terms_acf($post_id, $post, $handler, $taxonomy_fields) {
		$rules = $handler->get_enabled_rules();
		
		foreach ($rules as $rule) {
			if (!$this->rule_applies_to_post($rule, $post)) {
				continue;
			}
			
			$acf_field_name = $rule['acf_field_name'];
			$source_taxonomy = $rule['source_taxonomy'];
			$target_taxonomy = $rule['target_taxonomy'];
			$conflict_handling = $rule['conflict_handling'] ?? 'merge';
			
			// Check if the ACF field was updated
			if ($this->was_acf_field_updated($post_id, $acf_field_name)) {
				$this->sync_related_post_terms($post_id, $rule, $taxonomy_fields);
			}
		}
	}
	
	/**
	 * Process hierarchical level restrictions for ACF fields
	 */
	private function process_level_restriction_acf($post_id, $post, $handler, $taxonomy_fields) {
		$rules = $handler->get_enabled_rules();
		
		foreach ($rules as $rule) {
			if (!$this->rule_applies_to_post($rule, $post)) {
				continue;
			}
			
			$taxonomy = $rule['taxonomy'];
			
			// Find ACF fields for this taxonomy that were updated
			foreach ($taxonomy_fields as $field_name => $field) {
				if ($field['taxonomy'] === $taxonomy && $this->was_acf_field_updated($post_id, $field_name)) {
					$this->apply_level_restrictions_to_acf_field($post_id, $field, $rule);
				}
			}
		}
	}

	/**
	 * Apply related terms for ACF fields
	 */
	private function apply_related_terms_for_acf($post_id, $rule, $taxonomy_fields) {
		$trigger_found = false;
		
		// Check if trigger conditions are met in ACF fields
		if ($rule['trigger_type'] === 'taxonomy') {
			$trigger_taxonomy = $rule['trigger_taxonomy'];
			
			foreach ($taxonomy_fields as $field_name => $field) {
				if ($field['taxonomy'] === $trigger_taxonomy) {
					$terms = $this->get_acf_field_terms($post_id, $field);
					if (!empty($terms)) {
						$trigger_found = true;
						break;
					}
				}
			}
		} elseif ($rule['trigger_type'] === 'term') {
			// trigger_term_id is int[] post-normalize. Fire if ANY trigger term
			// is present in its taxonomy's ACF field (OR semantics, mirrors
			// RelatedHandler V3/V5).
			$trigger_ids = (array) ($rule['trigger_term_id'] ?? array());
			foreach ($trigger_ids as $tid) {
				$trigger_term = get_term((int) $tid);
				if (!$trigger_term || is_wp_error($trigger_term)) {
					continue;
				}
				$trigger_taxonomy = $trigger_term->taxonomy;

				foreach ($taxonomy_fields as $field_name => $field) {
					if ($field['taxonomy'] === $trigger_taxonomy) {
						$terms = $this->get_acf_field_terms($post_id, $field);
						if (in_array($trigger_term->term_id, $terms)) {
							$trigger_found = true;
							break 2;
						}
					}
				}
			}
		}
		
		// Apply or remove target term
		$target_term = get_term($rule['target_term_id']);
		if ($target_term && !is_wp_error($target_term)) {
			if ($trigger_found) {
				// Apply target term
				wp_set_object_terms($post_id, array($target_term->term_id), $target_term->taxonomy, true);
			} elseif (!empty($rule['bidirectional'])) {
				// Remove target term
				$current_terms = wp_get_object_terms($post_id, $target_term->taxonomy, array('fields' => 'ids'));
				if (!is_wp_error($current_terms)) {
					$remaining_terms = array_diff($current_terms, array($target_term->term_id));
					wp_set_object_terms($post_id, $remaining_terms, $target_term->taxonomy);
				}
			}
		}
	}
	
	/**
	 * Get terms from ACF field
	 */
	private function get_acf_field_terms($post_id, $field) {
		$value = get_field($field['name'], $post_id);
		
		if (empty($value)) {
			return array();
		}
		
		$terms = array();
		
		if (is_array($value)) {
			foreach ($value as $item) {
				if (is_object($item) && isset($item->term_id)) {
					$terms[] = $item->term_id;
				} elseif (is_numeric($item)) {
					$terms[] = absint($item);
				}
			}
		} elseif (is_object($value) && isset($value->term_id)) {
			$terms[] = $value->term_id;
		} elseif (is_numeric($value)) {
			$terms[] = absint($value);
		}
		
		return array_unique(array_filter($terms));
	}
	
	/**
	 * Update ACF field with terms
	 */
	private function update_acf_field_terms($post_id, $field, $terms) {
		if (!is_array($terms)) {
			$terms = array($terms);
		}
		
		$terms = array_unique(array_filter($terms));
		
		return update_field($field['name'], $terms, $post_id);
	}
	
	/**
	 * Get term ancestors based on rule
	 */
	private function get_term_ancestors($term_id, $taxonomy, $rule) {
		$inheritance_depth = $rule['inheritance_depth'] ?? 'all';
		
		if ($inheritance_depth === 'immediate') {
			$term = get_term($term_id, $taxonomy);
			if ($term && !is_wp_error($term) && $term->parent > 0) {
				return array($term->parent);
			}
			return array();
		} else {
			return get_ancestors($term_id, $taxonomy);
		}
	}
	
	/**
	 * Get child posts
	 */
	private function get_child_posts($parent_id, $post_type) {
		return get_posts(array(
			'post_type' => $post_type,
			'post_parent' => $parent_id,
			'post_status' => array('publish', 'draft', 'private'),
			'numberposts' => -1,
			'fields' => 'ids'
		));
	}
	
	/**
	 * Merge terms based on conflict handling
	 */
	private function merge_terms_by_conflict_handling($current_terms, $new_terms, $conflict_handling) {
		switch ($conflict_handling) {
			case 'replace':
				return $new_terms;
				
			case 'merge':
				return array_unique(array_merge($current_terms, $new_terms));
				
			case 'skip':
				return empty($current_terms) ? $new_terms : $current_terms;
				
			default:
				return $current_terms;
		}
	}
	
	/**
	 * Check if rule applies to post
	 */
	private function rule_applies_to_post($rule, $post) {
		if (!empty($rule['post_type']) && $rule['post_type'] !== $post->post_type) {
			return false;
		}
		
		if (!empty($rule['post_types']) && !in_array($post->post_type, $rule['post_types'])) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Get handler type
	 */
	private function get_handler_type($handler) {
		$class_name = get_class($handler);
		
		if (strpos($class_name, 'Hierarchical_Level_Restriction') !== false) {
			return 'hierarchical_level_restriction';
		} elseif (strpos($class_name, 'Related_Post_Terms') !== false) {
			return 'related_post_terms';
		} elseif (strpos($class_name, 'Hierarchical') !== false) {
			return 'hierarchical';
		} elseif (strpos($class_name, 'Propagation') !== false) {
			return 'propagation';
		} elseif (strpos($class_name, 'Related') !== false) {
			return 'related';
		} elseif (strpos($class_name, 'Time_Based') !== false) {
			return 'time_based';
		}
		
		return 'unknown';
	}
	
	/**
	 * Add custom settings to ACF taxonomy fields
	 */
	public function add_taxonomy_field_settings($field) {
		acf_render_field_setting($field, array(
			'label' => __('BWS Taxonomy Manager', 'bws-taxonomy-manager'),
			'instructions' => __('This field will be processed by BWS Taxonomy Manager rules.', 'bws-taxonomy-manager'),
			'name' => 'bws_taxonomy_manager_enabled',
			'type' => 'true_false',
			'ui' => 1,
			'default_value' => 1
		));
	}
	
	/**
	 * Modify taxonomy field query if needed
	 */
	public function modify_taxonomy_field_query($args, $field) {
		// Add any modifications to the taxonomy query if needed
		// For now, just return the args as-is
		return $args;
	}
}
