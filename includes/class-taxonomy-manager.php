<?php
/**
 * Main BWS Taxonomy Manager Class
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor;

use BWS\MetaConductor\Handlers\HierarchicalHandler;
use BWS\MetaConductor\Handlers\PropagationHandler;
use BWS\MetaConductor\Handlers\RelatedHandler;
use BWS\MetaConductor\Handlers\TimeBasedHandler;
use BWS\MetaConductor\Handlers\RelatedPostTermsHandler;
use BWS\MetaConductor\Handlers\HierarchicalLevelRestrictionHandler;
use BWS\MetaConductor\Handlers\TitleSlugHandler;
use BWS\MetaConductor\Conversion\ConversionManager;
use BWS\MetaConductor\Conversion\ConversionCli;
use BWS\MetaConductor\Storage\StorageFactory;
use BWS\MetaConductor\Core\AcfWriteQueue;
use BWS\MetaConductor\Core\TermDispatcher;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class TaxonomyManager {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Handler instances
     */
    private $handlers = array();

    /**
     * Conversion manager instance
     */
    private $conversion_manager;

    /**
     * ACF write queue — AC-agnostic reapply trigger (#42).
     *
     * @var AcfWriteQueue|null
     */
    private $acf_write_queue = null;

    /**
     * Term dispatcher — sole entry point to term-rule execution (#60).
     *
     * @var TermDispatcher|null
     */
    private $term_dispatcher = null;

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
     * Get the ACF write queue (#42).
     *
     * Exposed so a behavior sweep can drive flush_post() directly — the Admin
     * Columns entry point is otherwise only reachable from an admin request
     * with AC Pro loaded, which WP-CLI cannot produce (AC returns early on
     * !is_admin()). Mirrors get_conversion_manager().
     *
     * @return AcfWriteQueue|null
     */
    public function get_acf_write_queue() {
        return $this->acf_write_queue;
    }

    /**
     * Get the term dispatcher (#60).
     *
     * Exposed for the same reason as get_acf_write_queue(): a behaviour sweep
     * needs to provoke a pass directly (`run_pass`/`drain_post`) rather than
     * wait for the shutdown drain, which WP-CLI reaches only at the very end of
     * a run.
     *
     * @return TermDispatcher|null
     */
    public function get_term_dispatcher() {
        return $this->term_dispatcher;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Dependencies load on demand via the PSR-4 autoloader (autoload.php);
        // no manual require chain (Phase 2a).
        $this->init_hooks();
        $this->init_handlers();
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

		// Conversion AJAX hooks. All eight endpoints route to ConversionUi's
		// canonical handlers (via the lazily-built instance on the conversion
		// manager) so responses carry the exact shapes conversion-admin.js
		// expects — indexed field arrays, bare taxonomy/term arrays, and the
		// flat-POST config the estimate/process/preview paths read. Divergent
		// local copies previously emitted key-preserved (object) field lists +
		// wrapped payloads and read a nested config the client never sends,
		// leaving every selector empty and every conversion inert.
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

    }
    
    /**
     * Initialize handlers
     */
    private function init_handlers() {
        // Handlers take no constructor argument: they read rules through
        // StorageFactory, not through an injected settings object. The
        // `Settings` compat shell that used to be passed here died with the
        // last legacy handler (#55).
        $this->handlers = array(
			'hierarchical' => new HierarchicalHandler(),
			'propagation' => new PropagationHandler(),
			'related' => new RelatedHandler(),
			'time_based' => new TimeBasedHandler(),
			'related_post_terms' => new RelatedPostTermsHandler(),
			'hierarchical_level_restriction' => new HierarchicalLevelRestrictionHandler(),
			'title_slug' => new TitleSlugHandler(),
        );

        // Term dispatcher (#60) — sole entry point to term-rule execution.
        // Built BEFORE the ACF write queue because the queue marks entities
        // dirty on it: a bare update_field() fires none of the dispatcher's
        // triggers, so the queue is how that write reaches a pass.
        //
        // Construction order carries no execution meaning any more, which is
        // the point. The propagation-before-hierarchical dependency that used
        // to live in the array above is DEAD (#62): both types are converted,
        // the pass runs them in authored order, and propagation no longer
        // writes the child a hierarchical hook would then expand out of band.
        // Title-slug-last dies with the format dispatcher (#64/#66).
        $this->term_dispatcher = new TermDispatcher($this->handlers);
        $this->term_dispatcher->register();

        // Time-based's daily sweep (#61). It lives HERE rather than in
        // TimeBasedHandler::init_hooks() because a converted handler must
        // register nothing at all — that is the bright line H13 holds, and
        // "nothing except the one hook that only enqueues" is not a line a
        // static check can hold. The sweep is a provocation like bulk apply,
        // not an apply: it selects the posts an expired rule still holds, marks
        // them dirty on the dispatcher above and drains ordered passes.
        add_action('bws_taxonomy_manager_cleanup', array($this->handlers['time_based'], 'cleanup_expired_rules'));

        // AC-agnostic ACF write queue (#42). Watches ACF's own write filter, so
        // EVERY write that bypasses the save_post family — AC v7 inline/bulk,
        // bare update_field(), REST — reapplies the handlers afterwards. This
        // generalises the #37 AC-only fallback below, which now just asks the
        // queue to flush one post immediately.
        $this->acf_write_queue = new AcfWriteQueue($this->handlers, $this->term_dispatcher);
        $this->acf_write_queue->register();

        // Admin Columns v7 immediate flush (#37). Gate on the ACP_VERSION
        // constant (defined iff AC Pro active, any v7.x) — NOT a class_exists
        // on ACP\Plugin, which does NOT exist in v7 (B3/§V5). The
        // ac/editing/saved hook is itself v7-only, so const + hook name self-gate.
        // (SPEC §V1/§V3/§V5/§V6, B3)
        if (defined('ACP_VERSION')) {
            $this->register_admin_columns_v7_reapply();
        }

        // Initialize conversion manager
        $this->conversion_manager = new ConversionManager();

        // Register WP-CLI commands if available
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('bws-conversion', new ConversionCli($this->conversion_manager));
        }
    }

    /**
     * Admin Columns v7 IMMEDIATE flush (#37, generalised by #42).
     *
     * AC v7 inline/bulk edits of an ACF field column write via update_field(),
     * which fires acf/update_value ONLY — never acf/save_post / save_post /
     * set_object_terms. So every handler whose apply is gated on the save_post
     * family (related-post-terms, related, level-restriction, propagation,
     * title-slug) silently fails to reapply after such an edit. (SPEC §V1/§V6)
     *
     * The apply itself is now AcfWriteQueue's job — the update_field() call has
     * ALREADY enqueued this post, and the shutdown flush would apply it anyway.
     * This hook exists only to make the apply happen EARLIER: AC builds its
     * inline-edit AJAX response before shutdown, so without it the column would
     * render pre-sync terms and the editor would see a stale value. flush_post
     * removes the post from the pending set, so there is no double apply.
     *
     * The native taxonomy-column path is already covered — AC v7 writes native
     * terms via wp_set_object_terms, which fires set_object_terms → the handlers'
     * own listeners run. So we act ONLY on ACF field columns
     * (\AC\Column\CustomFieldContext). (SPEC §V1/§V3)
     *
     * ac/editing/saved fires AFTER AC's storage write (InlineSave/BulkSave), so
     * reads see the new value — post-persist, no pre-write hazard. (SPEC §V2)
     */
    private function register_admin_columns_v7_reapply() {
        add_action('ac/editing/saved', function ($column, $id, $value, $table) {
            // ACF field columns only; native taxonomy edits self-cover (§V1).
            if (!$column instanceof \AC\Column\CustomFieldContext) {
                return;
            }

            $post_id = (int) $id;
            if ($post_id <= 0) {
                return;
            }

            $this->acf_write_queue->flush_post($post_id);
        }, 20, 4);
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
            META_CONDUCTOR_PLUGIN_URL . 'assets/js/conversion-admin.js',
            array('jquery', 'wp-util'),
            META_CONDUCTOR_VERSION,
            true
        );

        wp_enqueue_style(
            'bws-conversion-admin',
            META_CONDUCTOR_PLUGIN_URL . 'assets/css/conversion-admin.css',
            array(),
            META_CONDUCTOR_VERSION
        );

        wp_localize_script('bws-conversion-admin', 'bwsMetaManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bws_meta_conductor_nonce'),
            'strings' => array(
                'confirm_conversion' => __('This will convert data. Continue?', 'meta-conductor'),
                'confirm_preview'    => __('Generate preview?', 'meta-conductor'),
                'skip_unmapped'      => __('Skip this value', 'meta-conductor'),
                'processing'         => __('Processing...', 'meta-conductor'),
                'complete'           => __('Conversion complete!', 'meta-conductor'),
                'error'              => __('An error occurred. Please try again.', 'meta-conductor'),
            )
        ));
    }
    
	/**
	 * AJAX handler for validating ACF fields
	 */
	public function ajax_validate_acf_field() {
		check_ajax_referer('bws_meta_conductor_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'meta-conductor'));
		}
		
		$field_name = sanitize_text_field($_POST['field_name'] ?? '');
		
		if (empty($field_name)) {
			wp_send_json_error(__('Field name is required.', 'meta-conductor'));
		}
		
		if (!function_exists('acf_get_field')) {
			wp_send_json_success(array(
				'exists' => false,
				'message' => __('ACF Pro not available for field validation.', 'meta-conductor')
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
				'message' => __('Field not found in ACF.', 'meta-conductor')
			));
		}
	}
	
	/**
	 * AJAX handler for getting ACF fields for a post type
	 */
	public function ajax_get_acf_fields() {
		check_ajax_referer('bws_meta_conductor_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'meta-conductor'));
		}
		
		$post_type = sanitize_text_field($_POST['post_type'] ?? '');
		$field_types = $_POST['field_types'] ?? array('post_object', 'relationship');
		
		if (!post_type_exists($post_type)) {
			wp_send_json_error(__('Invalid post type.', 'meta-conductor'));
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
		check_ajax_referer('bws_meta_conductor_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'meta-conductor'));
		}
		
		$post_id = absint($_POST['post_id'] ?? 0);
		$acf_field_name = sanitize_text_field($_POST['acf_field_name'] ?? '');
		
		if (!$post_id || !get_post($post_id)) {
			wp_send_json_error(__('Invalid post ID.', 'meta-conductor'));
		}
		
		if (empty($acf_field_name)) {
			wp_send_json_error(__('ACF field name is required.', 'meta-conductor'));
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
		check_ajax_referer('bws_meta_conductor_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'meta-conductor'));
		}
		
		$taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
		$term_ids = array_map('absint', $_POST['term_ids'] ?? array());
		$restriction_mode = sanitize_text_field($_POST['restriction_mode'] ?? 'one_per_level');
		$include_ancestors = !empty($_POST['include_ancestors']);
		
		if (!taxonomy_exists($taxonomy)) {
			wp_send_json_error(__('Invalid taxonomy.', 'meta-conductor'));
		}
		
		if (empty($term_ids)) {
			wp_send_json_success(array(
				'original_terms' => array(),
				'restricted_terms' => array(),
				'removed_terms' => array(),
				'preview' => __('No terms selected.', 'meta-conductor')
			));
		}
		
		// Create a temporary handler instance for preview
		$handler = new HierarchicalLevelRestrictionHandler();
		
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
		
		$preview .= '<strong>' . __('Original terms:', 'meta-conductor') . '</strong><br>';
		$preview .= implode(', ', $original) . '<br><br>';
		
		$preview .= '<strong>' . __('After restrictions:', 'meta-conductor') . '</strong><br>';
		$preview .= implode(', ', $restricted) . '<br><br>';
		
		if (!empty($removed)) {
			$preview .= '<strong style="color: #d63638;">' . __('Removed terms:', 'meta-conductor') . '</strong><br>';
			$preview .= '<span style="color: #d63638;">' . implode(', ', $removed) . '</span><br><br>';
		}
		
		$mode_description = '';
		switch ($mode) {
			case 'one_per_level':
				$mode_description = __('Only one term per hierarchical level is allowed.', 'meta-conductor');
				break;
			case 'deepest_only':
				$mode_description = __('Only the deepest level terms are kept.', 'meta-conductor');
				break;
			case 'shallowest_only':
				$mode_description = __('Only the shallowest level terms are kept.', 'meta-conductor');
				break;
		}
		
		$preview .= '<em>' . $mode_description . '</em>';
		
		return $preview;
	}
    
    /**
     * AJAX handler for getting taxonomy terms
     */
    public function ajax_get_taxonomy_terms() {
        check_ajax_referer('bws_meta_conductor_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'meta-conductor'));
        }
        
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
        
        if (!taxonomy_exists($taxonomy)) {
            wp_send_json_error(__('Invalid taxonomy.', 'meta-conductor'));
        }
        
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 100
        ));
        
        if (is_wp_error($terms)) {
            wp_send_json_error(__('Error loading terms.', 'meta-conductor'));
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
        check_ajax_referer('bws_meta_conductor_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'meta-conductor'));
        }
        
        $post_type = sanitize_text_field($_POST['post_type'] ?? '');
        
        if (!post_type_exists($post_type)) {
            wp_send_json_error(__('Invalid post type.', 'meta-conductor'));
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
				// ACP_VERSION, not class_exists('ACP\Plugin') — the latter is false
				// on AC Pro v7 (no such class). (B3/§V5)
				'current' => defined('ACP_VERSION') ? 'Active' : 'Not Active',
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

	// ========================================
	// Conversion AJAX Handlers
	// ========================================

	// The conversion handlers below are thin delegators to ConversionUi, which
	// owns the canonical response shapes expected by conversion-admin.js.
	// Keeping the wp_ajax_* method names stable avoids touching the hook
	// registrations; the real logic lives in ConversionUi.

	/**
	 * AJAX handler: Get ACF fields (delegates to ConversionUi).
	 */
	public function ajax_conversion_get_fields() {
		$this->conversion_manager->get_conversion_ui()->handle_get_fields_ajax();
	}

	/**
	 * AJAX handler: Get taxonomies (delegates to ConversionUi).
	 */
	public function ajax_conversion_get_taxonomies() {
		$this->conversion_manager->get_conversion_ui()->handle_get_taxonomies_ajax();
	}

	/**
	 * AJAX handler: Get taxonomy terms (delegates to ConversionUi).
	 */
	public function ajax_conversion_get_taxonomy_terms() {
		$this->conversion_manager->get_conversion_ui()->handle_get_taxonomy_terms_ajax();
	}

	/**
	 * AJAX handler: Get field options (delegates to ConversionUi).
	 */
	public function ajax_conversion_get_options() {
		$this->conversion_manager->get_conversion_ui()->handle_get_options_ajax();
	}

	// The estimate/chunk/process/preview handlers also delegate to ConversionUi.
	// The local copies were either unimplemented stubs (estimate_size,
	// process_chunk returned hardcoded zeros) or read a nested $_POST['config']
	// array the client never sends — conversion-admin.js posts a FLAT FormData,
	// which ConversionUi::sanitize_conversion_config() reads directly.

	/**
	 * AJAX handler: Estimate conversion size (delegates to ConversionUi).
	 */
	public function ajax_conversion_estimate_size() {
		$this->conversion_manager->get_conversion_ui()->handle_estimate_conversion_size_ajax();
	}

	/**
	 * AJAX handler: Process conversion chunk (delegates to ConversionUi).
	 */
	public function ajax_conversion_process_chunk() {
		$this->conversion_manager->get_conversion_ui()->handle_chunked_conversion_ajax();
	}

	/**
	 * AJAX handler: Process conversion (delegates to ConversionUi).
	 */
	public function ajax_conversion_process() {
		$this->conversion_manager->get_conversion_ui()->handle_conversion_ajax();
	}

	/**
	 * AJAX handler: Generate preview (delegates to ConversionUi).
	 */
	public function ajax_conversion_preview() {
		$this->conversion_manager->get_conversion_ui()->handle_preview_ajax();
	}

    public function ajax_title_slug_preview() {
        check_ajax_referer('bws_meta_conductor_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'meta-conductor')]);
        }
        $rule_index = intval($_POST['rule_index'] ?? -1);
        $storage    = StorageFactory::get_instance();
        $rules      = $storage->get_rules('title_slug_rules');
        $rule       = $rules[$rule_index] ?? null;
        if (!$rule) {
            wp_send_json_error(['message' => __('Rule not found', 'meta-conductor')]);
        }
        $result = $this->handlers['title_slug']->preview_rule($rule);
        isset($result['error']) ? wp_send_json_error($result) : wp_send_json_success($result);
    }

    public function ajax_title_slug_process_existing() {
        check_ajax_referer('bws_meta_conductor_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'meta-conductor')]);
        }
        $batch_size = intval($_POST['batch_size'] ?? 50);
        $offset     = intval($_POST['offset'] ?? 0);
        $result     = $this->handlers['title_slug']->process_existing_posts($batch_size, $offset);
        wp_send_json_success($result);
    }

}
