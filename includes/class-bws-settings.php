<?php
/**
 * BWS Taxonomy Manager Settings Class
 * 
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Settings {
    
    /**
     * Settings option name
     */
    const OPTION_NAME = 'bws_taxonomy_manager_settings';
    
    /**
     * Default settings
     */
    private $defaults = array(
        'hierarchical_rules' => array(),
        'propagation_rules' => array(),
        'related_rules' => array(),
        'time_based_rules' => array(),
        'related_post_terms_rules' => array(),
        'hierarchical_level_restriction_rules' => array(),
        'title_slug_rules' => array(),
        'conflict_handling' => array(),
        'manual_processing_enabled' => true,
        'conversion_history' => array()
    );
    
    /**
     * Current settings
     */
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = wp_parse_args(get_option(self::OPTION_NAME, array()), $this->defaults);
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'bws_taxonomy_manager_settings_group',
            self::OPTION_NAME,
            array($this, 'sanitize_settings')
        );
    }
    
    /**
     * Get all settings
     */
    public function get_settings() {
        return $this->settings;
    }
    
    /**
     * Get specific setting
     */
    public function get_setting($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    /**
     * Update settings
     */
    public function update_settings($new_settings) {
        $this->settings = wp_parse_args($new_settings, $this->defaults);
        return update_option(self::OPTION_NAME, $this->settings);
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $save_tab = sanitize_text_field($_POST['save_tab'] ?? 'all');

        // Map tab names to rule types and setting keys
        $tab_to_keys = array(
            'hierarchical' => array('hierarchical_rules'),
            'propagation' => array('propagation_rules'),
            'related' => array('related_rules'),
            'time-based' => array('time_based_rules'),
            'related-post-terms' => array('related_post_terms_rules'),
            'level-restriction' => array('hierarchical_level_restriction_rules'),
            'title-slug' => array('title_slug_rules'),
            'settings' => array('conflict_handling', 'manual_processing_enabled')
        );

        // If saving a specific tab, merge only that tab's data
        if ($save_tab !== 'all' && isset($tab_to_keys[$save_tab])) {
            $existing = $this->get_settings();
            $sanitized = $this->sanitize_all_settings($input);

            $keys_to_update = $tab_to_keys[$save_tab];

            // Merge only the specific tab's data
            foreach ($keys_to_update as $key) {
                $existing[$key] = $sanitized[$key] ?? $existing[$key];
            }

            return $existing;
        }

        // Default: save all settings
        return $this->sanitize_all_settings($input);
    }

    /**
     * Sanitize all settings (extracted from sanitize_settings)
     */
    private function sanitize_all_settings($input) {
        $sanitized = array();

        // Sanitize hierarchical rules
        $sanitized['hierarchical_rules'] = $this->sanitize_hierarchical_rules($input['hierarchical_rules'] ?? array());

        // Sanitize propagation rules
        $sanitized['propagation_rules'] = $this->sanitize_propagation_rules($input['propagation_rules'] ?? array());

        // Sanitize related rules
        $sanitized['related_rules'] = $this->sanitize_related_rules($input['related_rules'] ?? array());

        // Sanitize time-based rules
        $sanitized['time_based_rules'] = $this->sanitize_time_based_rules($input['time_based_rules'] ?? array());

        // Sanitize related post rules
        $sanitized['related_post_terms_rules'] = $this->sanitize_related_post_terms_rules($input['related_post_terms_rules'] ?? array());

        // Sanitize level-based term restrictions
        $sanitized['hierarchical_level_restriction_rules'] = $this->sanitize_hierarchical_level_restriction_rules($input['hierarchical_level_restriction_rules'] ?? array());

        // Sanitize title/slug rules
        $sanitized['title_slug_rules'] = $this->sanitize_title_slug_rules($input['title_slug_rules'] ?? array());

        // Sanitize conflict handling
        $sanitized['conflict_handling'] = $this->sanitize_conflict_handling($input['conflict_handling'] ?? array());

        // Sanitize manual processing flag
        $sanitized['manual_processing_enabled'] = !empty($input['manual_processing_enabled']);

        return $sanitized;
    }
    
    /**
     * Sanitize hierarchical rules
     */
    private function sanitize_hierarchical_rules($rules) {
        $sanitized = array();
        
        foreach ($rules as $rule) {
            if (empty($rule['taxonomy']) || !taxonomy_exists($rule['taxonomy'])) {
                continue;
            }
            
            $sanitized[] = array(
                'taxonomy' => sanitize_text_field($rule['taxonomy']),
                'inheritance_depth' => sanitize_text_field($rule['inheritance_depth'] ?? 'all'), // 'immediate' or 'all'
                'post_types' => array_map('sanitize_text_field', $rule['post_types'] ?? array()),
                'enabled' => !empty($rule['enabled'])
            );
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize propagation rules
     */
    private function sanitize_propagation_rules($rules) {
        $sanitized = array();
        
        foreach ($rules as $rule) {
            if (empty($rule['taxonomy']) || !taxonomy_exists($rule['taxonomy'])) {
                continue;
            }
            
            if (empty($rule['post_type']) || !post_type_exists($rule['post_type'])) {
                continue;
            }
            
            $sanitized[] = array(
                'post_type' => sanitize_text_field($rule['post_type']),
                'taxonomy' => sanitize_text_field($rule['taxonomy']),
                'conflict_handling' => sanitize_text_field($rule['conflict_handling'] ?? 'merge'), // 'replace', 'merge', 'skip'
                'enabled' => !empty($rule['enabled'])
            );
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize related rules
     */
    private function sanitize_related_rules($rules) {
        $sanitized = array();
        
        foreach ($rules as $rule) {
            if (empty($rule['post_type']) || !post_type_exists($rule['post_type'])) {
                continue;
            }
            
            // Validate trigger
            if (empty($rule['trigger_type']) || !in_array($rule['trigger_type'], array('term', 'taxonomy'))) {
                continue;
            }
            
            if ($rule['trigger_type'] === 'term' && empty($rule['trigger_term_id'])) {
                continue;
            }
            
            if ($rule['trigger_type'] === 'taxonomy' && (empty($rule['trigger_taxonomy']) || !taxonomy_exists($rule['trigger_taxonomy']))) {
                continue;
            }
            
            // Validate target
            if (empty($rule['target_term_id'])) {
                continue;
            }
            
            $sanitized[] = array(
                'post_type' => sanitize_text_field($rule['post_type']),
                'trigger_type' => sanitize_text_field($rule['trigger_type']),
                'trigger_term_id' => $rule['trigger_type'] === 'term' ? absint($rule['trigger_term_id']) : null,
                'trigger_taxonomy' => $rule['trigger_type'] === 'taxonomy' ? sanitize_text_field($rule['trigger_taxonomy']) : null,
                'target_term_id' => absint($rule['target_term_id']),
                'bidirectional' => !empty($rule['bidirectional']),
                'enabled' => !empty($rule['enabled'])
            );
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize time-based rules
     */
    private function sanitize_time_based_rules($rules) {
        $sanitized = array();
        
        foreach ($rules as $rule) {
            if (empty($rule['post_type']) || !post_type_exists($rule['post_type'])) {
                continue;
            }
            
            if (empty($rule['target_term_id'])) {
                continue;
            }
            
            $start_date = sanitize_text_field($rule['start_date'] ?? '');
            $end_date = sanitize_text_field($rule['end_date'] ?? '');
            
            if (!$this->is_valid_date($start_date) || !$this->is_valid_date($end_date)) {
                continue;
            }
            
            $sanitized[] = array(
                'post_type' => sanitize_text_field($rule['post_type']),
                'target_term_id' => absint($rule['target_term_id']),
                'start_date' => $start_date,
                'end_date' => $end_date,
                'filter_taxonomies' => array_map('sanitize_text_field', $rule['filter_taxonomies'] ?? array()),
                'filter_terms' => array_map('absint', $rule['filter_terms'] ?? array()),
                'enabled' => !empty($rule['enabled'])
            );
        }
        
        return $sanitized;
    }

	/**
	 * Sanitize related post terms rules
	 */
	private function sanitize_related_post_terms_rules($rules) {
		$sanitized = array();
		
		foreach ($rules as $rule) {
			if (empty($rule['post_type']) || !post_type_exists($rule['post_type'])) {
				continue;
			}
			
			if (empty($rule['acf_field_name'])) {
				continue;
			}
			
			if (empty($rule['source_taxonomy']) || !taxonomy_exists($rule['source_taxonomy'])) {
				continue;
			}
			
			if (empty($rule['target_taxonomy']) || !taxonomy_exists($rule['target_taxonomy'])) {
				continue;
			}
			
			$sanitized[] = array(
				'post_type' => sanitize_text_field($rule['post_type']),
				'acf_field_name' => sanitize_text_field($rule['acf_field_name']),
				'source_taxonomy' => sanitize_text_field($rule['source_taxonomy']),
				'target_taxonomy' => sanitize_text_field($rule['target_taxonomy']),
				'conflict_handling' => sanitize_text_field($rule['conflict_handling'] ?? 'merge'),
				'bidirectional' => !empty($rule['bidirectional']),
				'enabled' => !empty($rule['enabled'])
			);
		}
		
		return $sanitized;
	}
	
	/**
	 * Sanitize hierarchical level restriction rules
	 */
	private function sanitize_hierarchical_level_restriction_rules($rules) {
		$sanitized = array();
		
		foreach ($rules as $rule) {
			if (empty($rule['taxonomy']) || !taxonomy_exists($rule['taxonomy'])) {
				continue;
			}
			
			$taxonomy = get_taxonomy($rule['taxonomy']);
			if (!$taxonomy->hierarchical) {
				continue;
			}
			
			$sanitized[] = array(
				'taxonomy' => sanitize_text_field($rule['taxonomy']),
				'restriction_mode' => sanitize_text_field($rule['restriction_mode'] ?? 'one_per_level'),
				'include_ancestors' => !empty($rule['include_ancestors']),
				'post_types' => array_map('sanitize_text_field', $rule['post_types'] ?? array()),
				'enabled' => !empty($rule['enabled'])
			);
		}
		
		return $sanitized;
	}

    /**
     * Sanitize title/slug rules
     */
    private function sanitize_title_slug_rules($rules) {
        $sanitized = array();
        if (!is_array($rules)) return $sanitized;

        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            $sanitized[] = array(
                'enabled'         => !empty($rule['enabled']),
                'name'            => sanitize_text_field($rule['name'] ?? ''),
                'post_type'       => sanitize_key($rule['post_type'] ?? ''),
                'title_pattern'   => sanitize_text_field($rule['title_pattern'] ?? ''),
                'slug_pattern'    => sanitize_text_field($rule['slug_pattern'] ?? ''),
                'slug_mode'       => in_array($rule['slug_mode'] ?? 'prefix', ['replace', 'prefix', 'suffix'])
                                     ? $rule['slug_mode'] : 'prefix',
                'date_escalation' => !empty($rule['date_escalation']),
                'date_field'      => sanitize_text_field($rule['date_field'] ?? ''),
            );
        }
        return $sanitized;
    }

    /**
     * Sanitize conflict handling rules
     */
    private function sanitize_conflict_handling($rules) {
        $sanitized = array();
        
        foreach ($rules as $taxonomy => $handling) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            
            if (!in_array($handling, array('replace', 'merge', 'skip'))) {
                continue;
            }
            
            $sanitized[sanitize_text_field($taxonomy)] = sanitize_text_field($handling);
        }
        
        return $sanitized;
    }
    
    /**
     * Validate date format
     */
    private function is_valid_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Render settings page
     */
	public function render_settings_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'bws-taxonomy-manager'));
		}

		$current_tab = sanitize_text_field($_GET['tab'] ?? 'hierarchical');
		$success_message = '';

		// Handle form submission
		if (isset($_POST['submit']) && check_admin_referer('bws_taxonomy_manager_settings', 'bws_taxonomy_manager_nonce')) {
			$this->update_settings($_POST[self::OPTION_NAME] ?? array());

			// Get tab-specific success message
			$save_tab = sanitize_text_field($_POST['save_tab'] ?? 'all');
			$messages = array(
				'hierarchical' => __('Hierarchical Rules settings saved.', 'bws-taxonomy-manager'),
				'propagation' => __('Propagation Rules settings saved.', 'bws-taxonomy-manager'),
				'related' => __('Related Terms Rules settings saved.', 'bws-taxonomy-manager'),
				'time-based' => __('Time-Based Rules settings saved.', 'bws-taxonomy-manager'),
				'related-post-terms' => __('Related Post Terms Rules settings saved.', 'bws-taxonomy-manager'),
				'level-restriction' => __('Level Restriction Rules settings saved.', 'bws-taxonomy-manager'),
				'settings' => __('Settings saved.', 'bws-taxonomy-manager'),
				'all' => __('Settings saved.', 'bws-taxonomy-manager')
			);

			$success_message = $messages[$save_tab] ?? $messages['all'];

			// Preserve the current tab after save
			$current_tab = sanitize_text_field($_POST['current_tab'] ?? 'hierarchical');
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('BWS Taxonomy Manager Settings', 'bws-taxonomy-manager'); ?></h1>

			<?php if ($success_message): ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html($success_message); ?></p>
				</div>
			<?php endif; ?>
			
			<form method="post" action="<?php echo admin_url('options-general.php?page=bws-taxonomy-manager&tab=' . esc_attr($current_tab)); ?>" novalidate>
				<?php wp_nonce_field('bws_taxonomy_manager_settings', 'bws_taxonomy_manager_nonce'); ?>
				<input type="hidden" name="current_tab" id="current_tab" value="<?php echo esc_attr($current_tab); ?>">
				
				<div class="bws-taxonomy-manager-tabs">
					<nav class="nav-tab-wrapper" role="tablist" aria-label="<?php esc_attr_e('Rule type navigation', 'bws-taxonomy-manager'); ?>">
						<a href="#hierarchical"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'hierarchical' ? 'true' : 'false'; ?>"
						   aria-controls="hierarchical-panel"
						   id="hierarchical-tab"
						   tabindex="<?php echo $current_tab === 'hierarchical' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'hierarchical' ? 'nav-tab-active' : ''; ?>"
						   data-tab="hierarchical">
							<?php _e('Hierarchical Rules', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#propagation"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'propagation' ? 'true' : 'false'; ?>"
						   aria-controls="propagation-panel"
						   id="propagation-tab"
						   tabindex="<?php echo $current_tab === 'propagation' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'propagation' ? 'nav-tab-active' : ''; ?>"
						   data-tab="propagation">
							<?php _e('Propagation Rules', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#related"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'related' ? 'true' : 'false'; ?>"
						   aria-controls="related-panel"
						   id="related-tab"
						   tabindex="<?php echo $current_tab === 'related' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'related' ? 'nav-tab-active' : ''; ?>"
						   data-tab="related">
							<?php _e('Related Terms', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#time-based"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'time-based' ? 'true' : 'false'; ?>"
						   aria-controls="time-based-panel"
						   id="time-based-tab"
						   tabindex="<?php echo $current_tab === 'time-based' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'time-based' ? 'nav-tab-active' : ''; ?>"
						   data-tab="time-based">
							<?php _e('Time-Based Rules', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#related-post-terms"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'related-post-terms' ? 'true' : 'false'; ?>"
						   aria-controls="related-post-terms-panel"
						   id="related-post-terms-tab"
						   tabindex="<?php echo $current_tab === 'related-post-terms' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'related-post-terms' ? 'nav-tab-active' : ''; ?>"
						   data-tab="related-post-terms">
							<?php _e('Related Post Terms', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#level-restriction"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'level-restriction' ? 'true' : 'false'; ?>"
						   aria-controls="level-restriction-panel"
						   id="level-restriction-tab"
						   tabindex="<?php echo $current_tab === 'level-restriction' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'level-restriction' ? 'nav-tab-active' : ''; ?>"
						   data-tab="level-restriction">
							<?php _e('Level Restrictions', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#title-slug"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'title-slug' ? 'true' : 'false'; ?>"
						   aria-controls="title-slug-panel"
						   id="title-slug-tab"
						   tabindex="<?php echo $current_tab === 'title-slug' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'title-slug' ? 'nav-tab-active' : ''; ?>"
						   data-tab="title-slug">
							<?php _e('Title &amp; Slug Rules', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#settings"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'settings' ? 'true' : 'false'; ?>"
						   aria-controls="settings-panel"
						   id="settings-tab"
						   tabindex="<?php echo $current_tab === 'settings' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>"
						   data-tab="settings">
							<?php _e('General Settings', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#system-info"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'system-info' ? 'true' : 'false'; ?>"
						   aria-controls="system-info-panel"
						   id="system-info-tab"
						   tabindex="<?php echo $current_tab === 'system-info' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'system-info' ? 'nav-tab-active' : ''; ?>"
						   data-tab="system-info">
							<?php _e('System Information', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#conversion"
						   role="tab"
						   aria-selected="<?php echo $current_tab === 'conversion' ? 'true' : 'false'; ?>"
						   aria-controls="conversion-panel"
						   id="conversion-tab"
						   tabindex="<?php echo $current_tab === 'conversion' ? '0' : '-1'; ?>"
						   class="nav-tab <?php echo $current_tab === 'conversion' ? 'nav-tab-active' : ''; ?>"
						   data-tab="conversion">
							<?php _e('Data Conversion', 'bws-taxonomy-manager'); ?>
						</a>
					</nav>
					
					<div id="hierarchical"
					     class="tab-content <?php echo $current_tab === 'hierarchical' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="hierarchical-tab"
					     tabindex="0">
						<?php $this->render_hierarchical_rules(); ?>
					</div>

					<div id="propagation"
					     class="tab-content <?php echo $current_tab === 'propagation' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="propagation-tab"
					     tabindex="0">
						<?php $this->render_propagation_rules(); ?>
					</div>

					<div id="related"
					     class="tab-content <?php echo $current_tab === 'related' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="related-tab"
					     tabindex="0">
						<?php $this->render_related_rules(); ?>
					</div>

					<div id="time-based"
					     class="tab-content <?php echo $current_tab === 'time-based' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="time-based-tab"
					     tabindex="0">
						<?php $this->render_time_based_rules(); ?>
					</div>

					<div id="related-post-terms"
					     class="tab-content <?php echo $current_tab === 'related-post-terms' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="related-post-terms-tab"
					     tabindex="0">
						<?php $this->render_related_post_terms_rules(); ?>
					</div>

					<div id="level-restriction"
					     class="tab-content <?php echo $current_tab === 'level-restriction' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="level-restriction-tab"
					     tabindex="0">
						<?php $this->render_hierarchical_level_restriction_rules(); ?>
					</div>

					<div id="title-slug"
					     class="tab-content <?php echo $current_tab === 'title-slug' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="title-slug-tab"
					     tabindex="0">
						<?php $this->render_title_slug_rules(); ?>
					</div>

					<div id="settings"
					     class="tab-content <?php echo $current_tab === 'settings' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="settings-tab"
					     tabindex="0">
						<?php $this->render_general_settings(); ?>
					</div>

					<div id="system-info"
					     class="tab-content <?php echo $current_tab === 'system-info' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="system-info-tab"
					     tabindex="0">
						<?php $this->render_system_information(); ?>
					</div>

					<div id="conversion"
					     class="tab-content <?php echo $current_tab === 'conversion' ? 'active' : ''; ?>"
					     role="tabpanel"
					     aria-labelledby="conversion-tab"
					     tabindex="0">
						<?php $this->render_conversion_tab(); ?>
					</div>
				</div>
			</form>

			<!-- ARIA live regions for screen reader announcements -->
			<div class="bws-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
			<div class="bws-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Update current tab when tab is clicked
			$('.nav-tab').on('click', function(e) {
				var tab = $(this).data('tab');
				$('#current_tab').val(tab);
			});
		});
		</script>
		<?php
	}
    
    /**
     * Render hierarchical rules section
     */
    private function render_hierarchical_rules() {
        $rules = $this->get_setting('hierarchical_rules', array());
        ?>
        <div class="bws-rules-section">
            <h2><?php _e('Hierarchical Term Inheritance', 'bws-taxonomy-manager'); ?></h2>
            <p><?php _e('When a child term is selected, automatically apply its parent/grandparent terms.', 'bws-taxonomy-manager'); ?></p>

            <?php if (empty($rules)): ?>
                <div class="bws-empty-state">
                    <div class="bws-empty-state-icon"></div>
                    <h3><?php _e('No Hierarchical Rules Yet', 'bws-taxonomy-manager'); ?></h3>
                    <p><?php _e('Hierarchical rules automatically apply parent and ancestor terms when a child term is selected. This ensures proper taxonomy inheritance across your content.', 'bws-taxonomy-manager'); ?></p>
                    <button type="button" class="button button-primary" id="add-hierarchical-rule-empty">
                        <?php _e('Add Your First Hierarchical Rule', 'bws-taxonomy-manager'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <div id="hierarchical-rules-container" <?php echo empty($rules) ? 'style="display:none;"' : ''; ?>>
                <?php foreach ($rules as $index => $rule): ?>
                    <?php $this->render_hierarchical_rule($rule, $index); ?>
                <?php endforeach; ?>
            </div>

            <template id="hierarchical-rule-template">
                <?php $this->render_hierarchical_rule(array()); ?>
            </template>

            <div class="bws-tab-actions-container">
                <div class="bws-tab-actions-left">
                    <button type="button" class="button button-secondary" id="add-hierarchical-rule">
                        <?php _e('Add Hierarchical Rule', 'bws-taxonomy-manager'); ?>
                    </button>
                </div>
                <div class="bws-tab-actions-right">
                    <input type="hidden" name="save_tab" value="hierarchical">
                    <?php submit_button(__('Save Hierarchical Rules', 'bws-taxonomy-manager'), 'primary', 'submit', false); ?>
                    <span class="spinner"></span>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render single hierarchical rule
     */
    private function render_hierarchical_rule($rule = array(), $index = '{{INDEX}}') {
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        $post_types = get_post_types(array('public' => true), 'objects');
        ?>
        <div class="bws-rule-item" data-rule-type="hierarchical">
            <div class="bws-rule-header">
                <div class="bws-rule-header-left">
                    <strong><?php printf(__('%s Rule', 'bws-taxonomy-manager'), isset($rule['taxonomy']) ? esc_html(ucfirst(get_taxonomy($rule['taxonomy'])->labels->singular_name ?? $rule['taxonomy'])) : __('New', 'bws-taxonomy-manager')); ?></strong>
                </div>
                <div class="bws-rule-header-actions">
                    <?php
                    $is_enabled = $rule['enabled'] ?? true; // Default to enabled for new rules
                    $button_class = $is_enabled ? 'button-secondary disable-rule-btn' : 'button-primary enable-rule-btn';
                    $button_text = $is_enabled ? __('Disable', 'bws-taxonomy-manager') : __('Enable', 'bws-taxonomy-manager');
                    ?>
                    <button type="button"
                            class="button <?php echo $button_class; ?>"
                            data-rule-index="<?php echo $index; ?>"
                            data-rule-type="hierarchical_rules"
                            data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
                        <?php echo $button_text; ?>
                    </button>
                    <button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>

                    <!-- Hidden field to maintain state for form submission -->
                    <input type="hidden"
                           name="<?php echo self::OPTION_NAME; ?>[hierarchical_rules][<?php echo $index; ?>][enabled]"
                           value="<?php echo $is_enabled ? '1' : '0'; ?>"
                           class="rule-enabled-field">
                </div>
            </div>
            
            <div class="bws-rule-content">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Taxonomy', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[hierarchical_rules][<?php echo $index; ?>][taxonomy]" required>
                                <option value=""><?php _e('Select Taxonomy', 'bws-taxonomy-manager'); ?></option>
                                <?php foreach ($taxonomies as $taxonomy): ?>
                                    <?php if ($taxonomy->hierarchical): ?>
                                        <option value="<?php echo esc_attr($taxonomy->name); ?>" 
                                                <?php selected($rule['taxonomy'] ?? '', $taxonomy->name); ?>>
                                            <?php echo esc_html($taxonomy->label); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Hierarchy Direction', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[hierarchical_rules][<?php echo $index; ?>][hierarchy_direction]" class="bws-hierarchy-direction">
                                <option value="child_to_parent" <?php selected($rule['hierarchy_direction'] ?? 'child_to_parent', 'child_to_parent'); ?>>
                                    <?php _e('Child to Parent (Apply ancestor terms)', 'bws-taxonomy-manager'); ?>
                                </option>
                                <option value="parent_to_child" <?php selected($rule['hierarchy_direction'] ?? 'child_to_parent', 'parent_to_child'); ?>>
                                    <?php _e('Parent to Child (Apply child terms)', 'bws-taxonomy-manager'); ?>
                                </option>
                                <option value="both" <?php selected($rule['hierarchy_direction'] ?? 'child_to_parent', 'both'); ?>>
                                    <?php _e('Both Directions', 'bws-taxonomy-manager'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('Choose whether to apply parent terms to children, or child terms to parents.', 'bws-taxonomy-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Hierarchy Depth', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <label>
                                <input type="radio"
                                       name="<?php echo self::OPTION_NAME; ?>[hierarchical_rules][<?php echo $index; ?>][inheritance_depth]"
                                       value="immediate" <?php checked($rule['inheritance_depth'] ?? 'all', 'immediate'); ?>>
                                <?php _e('One level only', 'bws-taxonomy-manager'); ?>
                            </label><br>
                            <label>
                                <input type="radio"
                                       name="<?php echo self::OPTION_NAME; ?>[hierarchical_rules][<?php echo $index; ?>][inheritance_depth]"
                                       value="all" <?php checked($rule['inheritance_depth'] ?? 'all', 'all'); ?>>
                                <?php _e('All levels (entire hierarchy)', 'bws-taxonomy-manager'); ?>
                            </label>
                            <p class="description">
                                <?php _e('One level: Direct parent or children only. All levels: All ancestors or descendants in the hierarchy.', 'bws-taxonomy-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr class="bws-expansion-behavior-row" style="display: none;">
                        <th scope="row"><?php _e('Child Expansion Behavior', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[hierarchical_rules][<?php echo $index; ?>][expansion_behavior]">
                                <option value="smart" <?php selected($rule['expansion_behavior'] ?? 'smart', 'smart'); ?>>
                                    <?php _e('Smart - Only if none selected', 'bws-taxonomy-manager'); ?>
                                </option>
                                <option value="merge" <?php selected($rule['expansion_behavior'] ?? 'smart', 'merge'); ?>>
                                    <?php _e('Always - Merge with manual selections', 'bws-taxonomy-manager'); ?>
                                </option>
                                <option value="never" <?php selected($rule['expansion_behavior'] ?? 'smart', 'never'); ?>>
                                    <?php _e('Manual only - No auto-expansion', 'bws-taxonomy-manager'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <strong><?php _e('Smart (Recommended):', 'bws-taxonomy-manager'); ?></strong> <?php _e('Automatically adds all child terms, but only if the user hasn\'t manually selected any children. This prevents confusion when users make specific child selections.', 'bws-taxonomy-manager'); ?><br><br>
                                <strong><?php _e('Always:', 'bws-taxonomy-manager'); ?></strong> <?php _e('Always adds all child terms, even if some are already manually selected. New children will merge with existing selections.', 'bws-taxonomy-manager'); ?><br><br>
                                <strong><?php _e('Manual only:', 'bws-taxonomy-manager'); ?></strong> <?php _e('Never automatically adds child terms. Users must manually select both parent and any desired children. Useful when combined with "Both" direction to get ancestors but not descendants.', 'bws-taxonomy-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Post Types (Optional)', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <?php foreach ($post_types as $post_type): ?>
                                <label>
                                    <input type="checkbox"
                                           name="<?php echo self::OPTION_NAME; ?>[hierarchical_rules][<?php echo $index; ?>][post_types][]"
                                           value="<?php echo esc_attr($post_type->name); ?>"
                                           <?php checked(in_array($post_type->name, $rule['post_types'] ?? array())); ?>>
                                    <?php echo esc_html($post_type->label); ?>
                                </label><br>
                            <?php endforeach; ?>
                            <small><?php _e('Leave empty to apply to all post types using this taxonomy.', 'bws-taxonomy-manager'); ?></small>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render propagation rules section
     */
    private function render_propagation_rules() {
        $rules = $this->get_setting('propagation_rules', array());
        ?>
        <div class="bws-rules-section">
            <h2><?php _e('Parent-Child Propagation', 'bws-taxonomy-manager'); ?></h2>
            <p><?php _e('When a parent post has terms, automatically apply them to child posts.', 'bws-taxonomy-manager'); ?></p>

            <?php if (empty($rules)): ?>
                <div class="bws-empty-state">
                    <div class="bws-empty-state-icon"></div>
                    <h3><?php _e('No Propagation Rules Yet', 'bws-taxonomy-manager'); ?></h3>
                    <p><?php _e('Propagation rules automatically copy taxonomy terms from parent posts to their child posts. Perfect for maintaining consistent categorization across post hierarchies.', 'bws-taxonomy-manager'); ?></p>
                    <button type="button" class="button button-primary" id="add-propagation-rule-empty">
                        <?php _e('Add Your First Propagation Rule', 'bws-taxonomy-manager'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <div id="propagation-rules-container" <?php echo empty($rules) ? 'style="display:none;"' : ''; ?>>
                <?php foreach ($rules as $index => $rule): ?>
                    <?php $this->render_propagation_rule($rule, $index); ?>
                <?php endforeach; ?>
            </div>

            <template id="propagation-rule-template">
                <?php $this->render_propagation_rule(array()); ?>
            </template>

            <div class="bws-tab-actions-container">
                <div class="bws-tab-actions-left">
                    <button type="button" class="button button-secondary" id="add-propagation-rule">
                        <?php _e('Add Propagation Rule', 'bws-taxonomy-manager'); ?>
                    </button>
                </div>
                <div class="bws-tab-actions-right">
                    <input type="hidden" name="save_tab" value="propagation">
                    <?php submit_button(__('Save Propagation Rules', 'bws-taxonomy-manager'), 'primary', 'submit', false); ?>
                    <span class="spinner"></span>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render single propagation rule
     */
    private function render_propagation_rule($rule = array(), $index = '{{INDEX}}') {
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        $post_types = get_post_types(array('public' => true, 'hierarchical' => true), 'objects');
        ?>
        <div class="bws-rule-item" data-rule-type="propagation">
            <div class="bws-rule-header">
                <div class="bws-rule-header-left">
                    <strong><?php printf(__('%s Rule', 'bws-taxonomy-manager'), isset($rule['post_type']) ? esc_html(ucfirst(get_post_type_object($rule['post_type'])->labels->singular_name ?? $rule['post_type'])) : __('New', 'bws-taxonomy-manager')); ?></strong>
                </div>
                <div class="bws-rule-header-actions">
                    <?php
                    $is_enabled = $rule['enabled'] ?? true;
                    $button_class = $is_enabled ? 'button-secondary disable-rule-btn' : 'button-primary enable-rule-btn';
                    $button_text = $is_enabled ? __('Disable', 'bws-taxonomy-manager') : __('Enable', 'bws-taxonomy-manager');
                    ?>
                    <button type="button"
                            class="button <?php echo $button_class; ?>"
                            data-rule-index="<?php echo $index; ?>"
                            data-rule-type="propagation_rules"
                            data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
                        <?php echo $button_text; ?>
                    </button>
                    <button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>

                    <input type="hidden"
                           name="<?php echo self::OPTION_NAME; ?>[propagation_rules][<?php echo $index; ?>][enabled]"
                           value="<?php echo $is_enabled ? '1' : '0'; ?>"
                           class="rule-enabled-field">
                </div>
            </div>
            
            <div class="bws-rule-content">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Post Type', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[propagation_rules][<?php echo $index; ?>][post_type]" required>
                                <option value=""><?php _e('Select Post Type', 'bws-taxonomy-manager'); ?></option>
                                <?php foreach ($post_types as $post_type): ?>
                                    <option value="<?php echo esc_attr($post_type->name); ?>" 
                                            <?php selected($rule['post_type'] ?? '', $post_type->name); ?>>
                                        <?php echo esc_html($post_type->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Taxonomy', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[propagation_rules][<?php echo $index; ?>][taxonomy]" required>
                                <option value=""><?php _e('Select Taxonomy', 'bws-taxonomy-manager'); ?></option>
                                <?php foreach ($taxonomies as $taxonomy): ?>
                                    <option value="<?php echo esc_attr($taxonomy->name); ?>" 
                                            <?php selected($rule['taxonomy'] ?? '', $taxonomy->name); ?>>
                                        <?php echo esc_html($taxonomy->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Conflict Handling', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[propagation_rules][<?php echo $index; ?>][conflict_handling]">
                                <option value="merge" <?php selected($rule['conflict_handling'] ?? 'merge', 'merge'); ?>>
                                    <?php _e('Merge with existing terms', 'bws-taxonomy-manager'); ?>
                                </option>
                                <option value="replace" <?php selected($rule['conflict_handling'] ?? 'merge', 'replace'); ?>>
                                    <?php _e('Replace existing terms', 'bws-taxonomy-manager'); ?>
                                </option>
                                <option value="skip" <?php selected($rule['conflict_handling'] ?? 'merge', 'skip'); ?>>
                                    <?php _e('Skip if terms exist', 'bws-taxonomy-manager'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render Title & Slug Rules tab
     */
    private function render_title_slug_rules() {
        $rules  = $this->settings['title_slug_rules'] ?? array();
        $status = get_option('bws_title_slug_rule_status', []);
        ?>
        <div class="bws-section">
            <h2><?php _e('Title & Slug Rules', 'bws-taxonomy-manager'); ?></h2>
            <p class="description"><?php _e('Automatically build post titles and slugs from custom field values, taxonomy terms, and date components. Rules are evaluated top to bottom — the first matching rule for a post type wins.', 'bws-taxonomy-manager'); ?></p>

            <?php if (empty($rules)) : ?>
            <div id="title-slug-rules-empty-state" style="padding: 20px; background: #f9f9f9; border: 1px dashed #ccc; margin: 15px 0;">
                <p><?php _e('No title/slug rules configured yet.', 'bws-taxonomy-manager'); ?></p>
                <button type="button" id="add-title-slug-rule-empty" class="button button-primary">
                    <?php _e('Add Your First Rule', 'bws-taxonomy-manager'); ?>
                </button>
            </div>
            <?php endif; ?>

            <div id="title-slug-rules-container">
                <?php foreach ($rules as $index => $rule) : ?>
                    <?php $this->render_title_slug_rule($rule, $index, $status[$index] ?? []); ?>
                <?php endforeach; ?>
            </div>

            <template id="title-slug-rule-template">
                <?php $this->render_title_slug_rule([], '{{INDEX}}', []); ?>
            </template>

            <p>
                <button type="button" id="add-title-slug-rule" class="button">
                    <?php _e('+ Add Rule', 'bws-taxonomy-manager'); ?>
                </button>
            </p>

            <p class="submit">
                <input type="hidden" name="save_tab" value="title-slug">
                <input type="submit" class="button-primary" value="<?php _e('Save Title & Slug Rules', 'bws-taxonomy-manager'); ?>">
            </p>
        </div>
        <?php
    }

    /**
     * Render a single Title & Slug rule
     */
    private function render_title_slug_rule($rule = [], $index = '{{INDEX}}', $rule_status = []) {
        $enabled         = !empty($rule['enabled']);
        $name            = $rule['name'] ?? '';
        $post_type       = $rule['post_type'] ?? '';
        $title_pattern   = $rule['title_pattern'] ?? '';
        $slug_pattern    = $rule['slug_pattern'] ?? '';
        $slug_mode       = $rule['slug_mode'] ?? 'prefix';
        $date_escalation = !empty($rule['date_escalation']);
        $date_field      = $rule['date_field'] ?? '';

        // Public post types excluding attachment.
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        $last_applied = $rule_status['last_applied'] ?? null;
        $warnings     = $rule_status['warnings'] ?? [];
        ?>
        <div class="bws-rule-item postbox" style="margin-bottom: 15px; padding: 12px;">
            <div class="bws-rule-header" style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                <button type="button" class="button-secondary move-rule-up" title="<?php esc_attr_e('Move up', 'bws-taxonomy-manager'); ?>">&#8593;</button>
                <button type="button" class="button-secondary move-rule-down" title="<?php esc_attr_e('Move down', 'bws-taxonomy-manager'); ?>">&#8595;</button>
                <strong style="flex: 1;"><?php echo esc_html($name ?: __('(Untitled Rule)', 'bws-taxonomy-manager')); ?></strong>
                <button type="button" class="button button-small bws-toggle-rule <?php echo $enabled ? 'bws-rule-enabled' : 'bws-rule-disabled'; ?>"
                        data-enabled="<?php echo $enabled ? '1' : '0'; ?>">
                    <?php echo $enabled ? __('Enabled', 'bws-taxonomy-manager') : __('Disabled', 'bws-taxonomy-manager'); ?>
                </button>
                <button type="button" class="button button-small button-link-delete bws-delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>
            </div>

            <input type="hidden" name="title_slug_rules[<?php echo $index; ?>][enabled]" value="<?php echo $enabled ? '1' : '0'; ?>" class="rule-enabled-field">

            <table class="form-table" style="margin: 0;">
                <tr>
                    <th><?php _e('Rule Name', 'bws-taxonomy-manager'); ?></th>
                    <td><input type="text" name="title_slug_rules[<?php echo $index; ?>][name]"
                               value="<?php echo esc_attr($name); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g. Personnel Title & Slug', 'bws-taxonomy-manager'); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('Post Type', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <select name="title_slug_rules[<?php echo $index; ?>][post_type]">
                            <option value=""><?php _e('— Select post type —', 'bws-taxonomy-manager'); ?></option>
                            <?php foreach ($post_types as $pt) : ?>
                                <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($post_type, $pt->name); ?>>
                                    <?php echo esc_html($pt->label . ' (' . $pt->name . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Title Pattern', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <input type="text" name="title_slug_rules[<?php echo $index; ?>][title_pattern]"
                               value="<?php echo esc_attr($title_pattern); ?>" class="large-text title-pattern-field"
                               placeholder="<?php esc_attr_e('e.g. {meta:first_name} {meta:last_name}', 'bws-taxonomy-manager'); ?>">
                        <p class="description"><?php _e('Optional. Leave blank to skip title modification.', 'bws-taxonomy-manager'); ?></p>
                        <details style="margin-top: 6px;">
                            <summary style="cursor: pointer; color: #2271b1;"><?php _e('Available tokens', 'bws-taxonomy-manager'); ?></summary>
                            <table class="widefat striped" style="margin-top: 6px; font-size: 12px;">
                                <thead><tr><th><?php _e('Token', 'bws-taxonomy-manager'); ?></th><th><?php _e('Title output', 'bws-taxonomy-manager'); ?></th><th><?php _e('Slug output', 'bws-taxonomy-manager'); ?></th></tr></thead>
                                <tbody>
                                    <tr><td><code>{meta:field_name}</code></td><td><?php _e('Raw field value', 'bws-taxonomy-manager'); ?></td><td><?php _e('Sanitized value', 'bws-taxonomy-manager'); ?></td></tr>
                                    <tr><td><code>{default_title}</code></td><td><?php _e('Title before this rule runs', 'bws-taxonomy-manager'); ?></td><td>&mdash;</td></tr>
                                    <tr><td><code>{default_slug}</code></td><td>&mdash;</td><td><?php _e('Slug derived from computed title', 'bws-taxonomy-manager'); ?></td></tr>
                                    <tr><td><code>{date_year:field}</code></td><td>2024</td><td>2024</td></tr>
                                    <tr><td><code>{date_month:field}</code></td><td><?php _e('March', 'bws-taxonomy-manager'); ?></td><td>03</td></tr>
                                    <tr><td><code>{date_day:field}</code></td><td>15</td><td>15</td></tr>
                                    <tr><td><code>{date_hour:field}</code></td><td>14</td><td>14</td></tr>
                                    <tr><td><code>{date_minute:field}</code></td><td>30</td><td>30</td></tr>
                                    <tr><td><code>{pub_year}</code></td><td><?php _e('2024 (publication date, local time)', 'bws-taxonomy-manager'); ?></td><td>2024</td></tr>
                                    <tr><td><code>{pub_month}</code></td><td><?php _e('March', 'bws-taxonomy-manager'); ?></td><td>03</td></tr>
                                    <tr><td><code>{pub_day} / {pub_hour} / {pub_minute}</code></td><td colspan="2"><?php _e('Same numeric pattern as above', 'bws-taxonomy-manager'); ?></td></tr>
                                    <tr><td><code>{term:taxonomy}</code></td><td><?php _e('First term name (alpha)', 'bws-taxonomy-manager'); ?></td><td><?php _e('First term slug', 'bws-taxonomy-manager'); ?></td></tr>
                                    <tr><td><code>{terms:taxonomy}</code></td><td><?php _e('All term names, comma-separated', 'bws-taxonomy-manager'); ?></td><td><?php _e('All slugs, hyphen-joined', 'bws-taxonomy-manager'); ?></td></tr>
                                </tbody>
                            </table>
                        </details>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Slug Pattern', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <input type="text" name="title_slug_rules[<?php echo $index; ?>][slug_pattern]"
                               value="<?php echo esc_attr($slug_pattern); ?>" class="large-text slug-pattern-field"
                               placeholder="<?php esc_attr_e('e.g. {pub_year}-{meta:first_name}-{meta:last_name}', 'bws-taxonomy-manager'); ?>">
                        <p class="description"><?php _e('Optional. Leave blank to skip slug modification (or to derive slug implicitly from title pattern).', 'bws-taxonomy-manager'); ?></p>
                    </td>
                </tr>
                <tr class="slug-mode-row" <?php echo empty($slug_pattern) ? 'style="display:none;"' : ''; ?>>
                    <th><?php _e('Slug Mode', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <select name="title_slug_rules[<?php echo $index; ?>][slug_mode]" class="slug-mode-select" <?php echo $slug_mode ? 'data-initial="1"' : ''; ?>>
                            <option value="prefix" <?php selected($slug_mode, 'prefix'); ?>><?php _e('Prefix — pattern-default-slug', 'bws-taxonomy-manager'); ?></option>
                            <option value="suffix" <?php selected($slug_mode, 'suffix'); ?>><?php _e('Suffix — default-slug-pattern', 'bws-taxonomy-manager'); ?></option>
                            <option value="replace" <?php selected($slug_mode, 'replace'); ?>><?php _e('Replace — pattern only', 'bws-taxonomy-manager'); ?></option>
                        </select>
                        <span class="slug-mode-hint description" style="display:none; margin-left: 8px;"><?php _e('Mode locked to Replace because {default_slug} is already in the pattern.', 'bws-taxonomy-manager'); ?></span>
                    </td>
                </tr>
                <tr class="escalation-row" <?php echo (empty($title_pattern) && empty($slug_pattern)) ? 'style="display:none;"' : ''; ?>>
                    <th><?php _e('Collision Avoidance', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="title_slug_rules[<?php echo $index; ?>][date_escalation]"
                                   value="1" class="date-escalation-checkbox" <?php checked($date_escalation); ?>>
                            <?php _e('Add date precision to resolve slug collisions', 'bws-taxonomy-manager'); ?>
                        </label>
                    </td>
                </tr>
                <tr class="date-field-row" <?php echo !$date_escalation ? 'style="display:none;"' : ''; ?>>
                    <th><?php _e('Date Field', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <input type="text" name="title_slug_rules[<?php echo $index; ?>][date_field]"
                               value="<?php echo esc_attr($date_field); ?>" class="regular-text"
                               placeholder="<?php esc_attr_e('e.g. event_date', 'bws-taxonomy-manager'); ?>">
                        <p class="description"><?php _e('Meta field key to read date from. Leave blank to use publication date.', 'bws-taxonomy-manager'); ?></p>
                    </td>
                </tr>
            </table>

            <div class="bws-rule-actions" style="margin-top: 10px; display: flex; gap: 8px; align-items: center;">
                <button type="button" class="button preview-title-slug-rule" data-rule-index="<?php echo $index; ?>">
                    <?php _e('Preview', 'bws-taxonomy-manager'); ?>
                </button>
                <button type="button" class="button apply-title-slug-rule" data-rule-index="<?php echo $index; ?>">
                    <?php _e('Apply to Existing Posts', 'bws-taxonomy-manager'); ?>
                </button>
            </div>

            <?php if ($last_applied) : ?>
            <p class="description" style="margin-top: 8px;">
                <?php printf(
                    __('Last applied: %s on &ldquo;%s&rdquo; (post #%d)', 'bws-taxonomy-manager'),
                    esc_html($last_applied['timestamp']),
                    esc_html($last_applied['title']),
                    (int) $last_applied['post_id']
                ); ?>
            </p>
            <?php endif; ?>

            <?php if (!empty($warnings)) : ?>
            <div class="bws-rule-warnings" style="margin-top: 8px; padding: 8px; background: #fff8e1; border-left: 4px solid #ffb900;">
                <strong><?php _e('Recent warnings:', 'bws-taxonomy-manager'); ?></strong>
                <ul style="margin: 4px 0 0 16px;">
                    <?php foreach (array_reverse($warnings) as $w) : ?>
                    <li style="font-size: 12px;"><?php echo esc_html($w['timestamp'] . ': ' . $w['message']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render related rules section
     */
    private function render_related_rules() {
        $rules = $this->get_setting('related_rules', array());
        ?>
        <div class="bws-rules-section">
            <h2><?php _e('Related Terms', 'bws-taxonomy-manager'); ?></h2>
            <p><?php _e('Automatically apply related terms when trigger terms or taxonomies are applied to posts.', 'bws-taxonomy-manager'); ?></p>

            <?php if (empty($rules)): ?>
                <div class="bws-empty-state">
                    <div class="bws-empty-state-icon"></div>
                    <h3><?php _e('No Related Term Rules Yet', 'bws-taxonomy-manager'); ?></h3>
                    <p><?php _e('Related term rules automatically apply terms from one taxonomy when terms from another taxonomy are added. Create cross-taxonomy relationships to keep your content organized.', 'bws-taxonomy-manager'); ?></p>
                    <button type="button" class="button button-primary" id="add-related-rule-empty">
                        <?php _e('Add Your First Related Rule', 'bws-taxonomy-manager'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <div id="related-rules-container" <?php echo empty($rules) ? 'style="display:none;"' : ''; ?>>
                <?php foreach ($rules as $index => $rule): ?>
                    <?php $this->render_related_rule($rule, $index); ?>
                <?php endforeach; ?>
            </div>

            <template id="related-rule-template">
                <?php $this->render_related_rule(array()); ?>
            </template>

            <div class="bws-tab-actions-container">
                <div class="bws-tab-actions-left">
                    <button type="button" class="button button-secondary" id="add-related-rule">
                        <?php _e('Add Related Rule', 'bws-taxonomy-manager'); ?>
                    </button>
                </div>
                <div class="bws-tab-actions-right">
                    <input type="hidden" name="save_tab" value="related">
                    <?php submit_button(__('Save Related Terms Rules', 'bws-taxonomy-manager'), 'primary', 'submit', false); ?>
                    <span class="spinner"></span>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render single related rule
     */
    private function render_related_rule($rule = array(), $index = '{{INDEX}}') {
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        $post_types = get_post_types(array('public' => true), 'objects');
        ?>
        <div class="bws-rule-item" data-rule-type="related">
            <div class="bws-rule-header">
                <div class="bws-rule-header-left">
                    <strong><?php printf(__('%s Rule', 'bws-taxonomy-manager'), isset($rule['post_type']) ? esc_html(ucfirst(get_post_type_object($rule['post_type'])->labels->singular_name ?? $rule['post_type'])) : __('New', 'bws-taxonomy-manager')); ?></strong>
                </div>
                <div class="bws-rule-header-actions">
                    <?php
                    $is_enabled = $rule['enabled'] ?? true;
                    $button_class = $is_enabled ? 'button-secondary disable-rule-btn' : 'button-primary enable-rule-btn';
                    $button_text = $is_enabled ? __('Disable', 'bws-taxonomy-manager') : __('Enable', 'bws-taxonomy-manager');
                    ?>
                    <button type="button"
                            class="button <?php echo $button_class; ?>"
                            data-rule-index="<?php echo $index; ?>"
                            data-rule-type="related_rules"
                            data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
                        <?php echo $button_text; ?>
                    </button>
                    <button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>

                    <input type="hidden"
                           name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][enabled]"
                           value="<?php echo $is_enabled ? '1' : '0'; ?>"
                           class="rule-enabled-field">
                </div>
            </div>
            
            <div class="bws-rule-content">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Post Type', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][post_type]" required>
                                <option value=""><?php _e('Select Post Type', 'bws-taxonomy-manager'); ?></option>
                                <?php foreach ($post_types as $post_type): ?>
                                    <option value="<?php echo esc_attr($post_type->name); ?>" 
                                            <?php selected($rule['post_type'] ?? '', $post_type->name); ?>>
                                        <?php echo esc_html($post_type->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Trigger Type', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <label>
                                <input type="radio" 
                                       name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][trigger_type]" 
                                       value="term" <?php checked($rule['trigger_type'] ?? 'term', 'term'); ?>
                                       class="trigger-type-radio">
                                <?php _e('Specific term', 'bws-taxonomy-manager'); ?>
                            </label><br>
                            <label>
                                <input type="radio" 
                                       name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][trigger_type]" 
                                       value="taxonomy" <?php checked($rule['trigger_type'] ?? 'term', 'taxonomy'); ?>
                                       class="trigger-type-radio">
                                <?php _e('Any term from taxonomy', 'bws-taxonomy-manager'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr class="trigger-term-row" <?php echo ($rule['trigger_type'] ?? 'term') !== 'term' ? 'style="display:none"' : ''; ?>>
                        <th scope="row"><?php _e('Trigger Term', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][trigger_term_id]" class="term-select">
                                <option value=""><?php _e('Select Term', 'bws-taxonomy-manager'); ?></option>
                                <?php 
                                if (!empty($rule['trigger_term_id'])) {
                                    $term = get_term($rule['trigger_term_id']);
                                    if ($term && !is_wp_error($term)) {
                                        echo '<option value="' . esc_attr($term->term_id) . '" selected>' . esc_html($term->name) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="trigger-taxonomy-row" <?php echo ($rule['trigger_type'] ?? 'term') !== 'taxonomy' ? 'style="display:none"' : ''; ?>>
                        <th scope="row"><?php _e('Trigger Taxonomy', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][trigger_taxonomy]">
                                <option value=""><?php _e('Select Taxonomy', 'bws-taxonomy-manager'); ?></option>
                                <?php foreach ($taxonomies as $taxonomy): ?>
                                    <option value="<?php echo esc_attr($taxonomy->name); ?>" 
                                            <?php selected($rule['trigger_taxonomy'] ?? '', $taxonomy->name); ?>>
                                        <?php echo esc_html($taxonomy->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Target Term', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][target_term_id]" class="term-select" required>
                                <option value=""><?php _e('Select Target Term', 'bws-taxonomy-manager'); ?></option>
                                <?php 
                                if (!empty($rule['target_term_id'])) {
                                    $term = get_term($rule['target_term_id']);
                                    if ($term && !is_wp_error($term)) {
                                        echo '<option value="' . esc_attr($term->term_id) . '" selected>' . esc_html($term->name) . ' (' . esc_html($term->taxonomy) . ')</option>';
                                    }
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Bidirectional', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][bidirectional]" 
                                       value="1" <?php checked($rule['bidirectional'] ?? false); ?>>
                                <?php _e('Remove target term when trigger term is removed', 'bws-taxonomy-manager'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render time-based rules section
     */
    private function render_time_based_rules() {
        $rules = $this->get_setting('time_based_rules', array());
        ?>
        <div class="bws-rules-section">
            <h2><?php _e('Time-Based Rules', 'bws-taxonomy-manager'); ?></h2>
            <p><?php _e('Automatically apply terms to posts during specific date ranges, optionally filtered by existing taxonomy terms.', 'bws-taxonomy-manager'); ?></p>

            <?php if (empty($rules)): ?>
                <div class="bws-empty-state">
                    <div class="bws-empty-state-icon"></div>
                    <h3><?php _e('No Time-Based Rules Yet', 'bws-taxonomy-manager'); ?></h3>
                    <p><?php _e('Time-based rules automatically apply taxonomy terms based on date ranges. Perfect for seasonal content, campaigns, or time-sensitive categorization.', 'bws-taxonomy-manager'); ?></p>
                    <button type="button" class="button button-primary" id="add-time-based-rule-empty">
                        <?php _e('Add Your First Time-Based Rule', 'bws-taxonomy-manager'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <div id="time-based-rules-container" <?php echo empty($rules) ? 'style="display:none;"' : ''; ?>>
                <?php foreach ($rules as $index => $rule): ?>
                    <?php $this->render_time_based_rule($rule, $index); ?>
                <?php endforeach; ?>
            </div>

            <template id="time-based-rule-template">
                <?php $this->render_time_based_rule(array()); ?>
            </template>

            <div class="bws-tab-actions-container">
                <div class="bws-tab-actions-left">
                    <button type="button" class="button button-secondary" id="add-time-based-rule">
                        <?php _e('Add Time-Based Rule', 'bws-taxonomy-manager'); ?>
                    </button>
                </div>
                <div class="bws-tab-actions-right">
                    <input type="hidden" name="save_tab" value="time-based">
                    <?php submit_button(__('Save Time-Based Rules', 'bws-taxonomy-manager'), 'primary', 'submit', false); ?>
                    <span class="spinner"></span>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render single time-based rule
     */
    private function render_time_based_rule($rule = array(), $index = '{{INDEX}}') {
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        $post_types = get_post_types(array('public' => true), 'objects');
        ?>
        <div class="bws-rule-item" data-rule-type="time_based">
            <div class="bws-rule-header">
                <div class="bws-rule-header-left">
                    <strong><?php printf(__('%s Rule', 'bws-taxonomy-manager'), isset($rule['post_type']) ? esc_html(ucfirst(get_post_type_object($rule['post_type'])->labels->singular_name ?? $rule['post_type'])) : __('New', 'bws-taxonomy-manager')); ?></strong>
                </div>
                <div class="bws-rule-header-actions">
                    <?php
                    $is_enabled = $rule['enabled'] ?? true;
                    $button_class = $is_enabled ? 'button-secondary disable-rule-btn' : 'button-primary enable-rule-btn';
                    $button_text = $is_enabled ? __('Disable', 'bws-taxonomy-manager') : __('Enable', 'bws-taxonomy-manager');
                    ?>
                    <button type="button"
                            class="button <?php echo $button_class; ?>"
                            data-rule-index="<?php echo $index; ?>"
                            data-rule-type="time_based_rules"
                            data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
                        <?php echo $button_text; ?>
                    </button>
                    <button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>

                    <input type="hidden"
                           name="<?php echo self::OPTION_NAME; ?>[time_based_rules][<?php echo $index; ?>][enabled]"
                           value="<?php echo $is_enabled ? '1' : '0'; ?>"
                           class="rule-enabled-field">
                </div>
            </div>
            
            <div class="bws-rule-content">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Post Type', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[time_based_rules][<?php echo $index; ?>][post_type]" required>
                                <option value=""><?php _e('Select Post Type', 'bws-taxonomy-manager'); ?></option>
                                <?php foreach ($post_types as $post_type): ?>
                                    <option value="<?php echo esc_attr($post_type->name); ?>" 
                                            <?php selected($rule['post_type'] ?? '', $post_type->name); ?>>
                                        <?php echo esc_html($post_type->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Target Term', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[time_based_rules][<?php echo $index; ?>][target_term_id]" class="term-select" required>
                                <option value=""><?php _e('Select Term to Apply', 'bws-taxonomy-manager'); ?></option>
                                <?php 
                                if (!empty($rule['target_term_id'])) {
                                    $term = get_term($rule['target_term_id']);
                                    if ($term && !is_wp_error($term)) {
                                        echo '<option value="' . esc_attr($term->term_id) . '" selected>' . esc_html($term->name) . ' (' . esc_html($term->taxonomy) . ')</option>';
                                    }
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Date Range', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <div class="date-range">
                                <label><?php _e('Start Date:', 'bws-taxonomy-manager'); ?></label>
                                <input type="date" 
                                       name="<?php echo self::OPTION_NAME; ?>[time_based_rules][<?php echo $index; ?>][start_date]" 
                                       value="<?php echo esc_attr($rule['start_date'] ?? ''); ?>" required>
                                
                                <label><?php _e('End Date:', 'bws-taxonomy-manager'); ?></label>
                                <input type="date" 
                                       name="<?php echo self::OPTION_NAME; ?>[time_based_rules][<?php echo $index; ?>][end_date]" 
                                       value="<?php echo esc_attr($rule['end_date'] ?? ''); ?>" required>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Filter by Taxonomies (Optional)', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <div class="checkbox-group">
                                <?php foreach ($taxonomies as $taxonomy): ?>
                                    <label>
                                        <input type="checkbox" 
                                               name="<?php echo self::OPTION_NAME; ?>[time_based_rules][<?php echo $index; ?>][filter_taxonomies][]" 
                                               value="<?php echo esc_attr($taxonomy->name); ?>"
                                               <?php checked(in_array($taxonomy->name, $rule['filter_taxonomies'] ?? array())); ?>>
                                        <?php echo esc_html($taxonomy->label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small><?php _e('Only apply to posts that have terms in these taxonomies. Leave empty to apply to all posts.', 'bws-taxonomy-manager'); ?></small>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Filter by Specific Terms (Optional)', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <select name="<?php echo self::OPTION_NAME; ?>[time_based_rules][<?php echo $index; ?>][filter_terms][]" 
                                    class="term-select" multiple style="min-height: 100px;">
                                <?php 
                                if (!empty($rule['filter_terms'])) {
                                    foreach ($rule['filter_terms'] as $term_id) {
                                        $term = get_term($term_id);
                                        if ($term && !is_wp_error($term)) {
                                            echo '<option value="' . esc_attr($term->term_id) . '" selected>' . esc_html($term->name) . ' (' . esc_html($term->taxonomy) . ')</option>';
                                        }
                                    }
                                }
                                ?>
                            </select>
                            <small><?php _e('Only apply to posts that have these specific terms. Leave empty to use taxonomy filter instead.', 'bws-taxonomy-manager'); ?></small>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }


	/**
	 * Render related post terms rules section
	 */
	private function render_related_post_terms_rules() {
		$rules = $this->get_setting('related_post_terms_rules', array());
		?>
		<div class="bws-rules-section">
			<h2><?php _e('Related Post Terms', 'bws-taxonomy-manager'); ?></h2>
			<p><?php _e('Sync taxonomy terms from related posts via ACF relationship or post object fields. When related posts have terms in a source taxonomy, those terms are applied to the target taxonomy on the main post.', 'bws-taxonomy-manager'); ?></p>

			<?php if (empty($rules)): ?>
				<div class="bws-empty-state">
					<div class="bws-empty-state-icon"></div>
					<h3><?php _e('No Related Post Terms Rules Yet', 'bws-taxonomy-manager'); ?></h3>
					<p><?php _e('Related post terms rules sync taxonomy terms from posts connected via ACF relationship fields. Link terms across related content automatically.', 'bws-taxonomy-manager'); ?></p>
					<button type="button" class="button button-primary" id="add-related-post-terms-rule-empty">
						<?php _e('Add Your First Related Post Terms Rule', 'bws-taxonomy-manager'); ?>
					</button>
				</div>
			<?php endif; ?>

			<div id="related-post-terms-rules-container" <?php echo empty($rules) ? 'style="display:none;"' : ''; ?>>
				<?php foreach ($rules as $index => $rule): ?>
					<?php $this->render_related_post_terms_rule($rule, $index); ?>
				<?php endforeach; ?>
			</div>

			<template id="related-post-terms-rule-template">
				<?php $this->render_related_post_terms_rule(array()); ?>
			</template>

			<div class="bws-tab-actions-container">
				<div class="bws-tab-actions-left">
					<button type="button" class="button button-secondary" id="add-related-post-terms-rule">
						<?php _e('Add Related Post Terms Rule', 'bws-taxonomy-manager'); ?>
					</button>
					<?php if ($this->get_setting('manual_processing_enabled', true)): ?>
						<button type="button" class="button button-secondary process-existing-btn" data-rule-type="related_post_terms">
							<?php _e('Process Existing Posts', 'bws-taxonomy-manager'); ?>
						</button>
					<?php endif; ?>
				</div>
				<div class="bws-tab-actions-right">
					<input type="hidden" name="save_tab" value="related-post-terms">
					<?php submit_button(__('Save Related Post Terms Rules', 'bws-taxonomy-manager'), 'primary', 'submit', false); ?>
					<span class="spinner"></span>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render single related post terms rule
	 */
	private function render_related_post_terms_rule($rule = array(), $index = '{{INDEX}}') {
		$taxonomies = get_taxonomies(array('public' => true), 'objects');
		$post_types = get_post_types(array('public' => true), 'objects');
		?>
		<div class="bws-rule-item" data-rule-type="related_post_terms">
			<div class="bws-rule-header">
				<div class="bws-rule-header-left">
					<strong><?php printf(__('%s Rule', 'bws-taxonomy-manager'), isset($rule['post_type']) ? esc_html(ucfirst(get_post_type_object($rule['post_type'])->labels->singular_name ?? $rule['post_type'])) : __('New', 'bws-taxonomy-manager')); ?></strong>
				</div>
				<div class="bws-rule-header-actions">
					<?php
					$is_enabled = $rule['enabled'] ?? true;
					$button_class = $is_enabled ? 'button-secondary disable-rule-btn' : 'button-primary enable-rule-btn';
					$button_text = $is_enabled ? __('Disable', 'bws-taxonomy-manager') : __('Enable', 'bws-taxonomy-manager');
					?>
					<button type="button"
							class="button <?php echo $button_class; ?>"
							data-rule-index="<?php echo $index; ?>"
							data-rule-type="related_post_terms_rules"
							data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
						<?php echo $button_text; ?>
					</button>
					<button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>

					<input type="hidden"
						   name="<?php echo self::OPTION_NAME; ?>[related_post_terms_rules][<?php echo $index; ?>][enabled]"
						   value="<?php echo $is_enabled ? '1' : '0'; ?>"
						   class="rule-enabled-field">
				</div>
			</div>
			
			<div class="bws-rule-content">
				<table class="form-table">
					<tr>
						<th scope="row"><?php _e('Post Type', 'bws-taxonomy-manager'); ?></th>
						<td>
							<select name="<?php echo self::OPTION_NAME; ?>[related_post_terms_rules][<?php echo $index; ?>][post_type]" required>
								<option value=""><?php _e('Select Post Type', 'bws-taxonomy-manager'); ?></option>
								<?php foreach ($post_types as $post_type): ?>
									<option value="<?php echo esc_attr($post_type->name); ?>" 
											<?php selected($rule['post_type'] ?? '', $post_type->name); ?>>
										<?php echo esc_html($post_type->label); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php _e('The post type that contains the ACF relationship field.', 'bws-taxonomy-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('ACF Field Name', 'bws-taxonomy-manager'); ?></th>
						<td>
							<input type="text" 
								   name="<?php echo self::OPTION_NAME; ?>[related_post_terms_rules][<?php echo $index; ?>][acf_field_name]" 
								   value="<?php echo esc_attr($rule['acf_field_name'] ?? ''); ?>" 
								   required 
								   placeholder="field_name">
							<p class="description"><?php _e('The ACF field name (not label) for the relationship or post object field.', 'bws-taxonomy-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Source Taxonomy', 'bws-taxonomy-manager'); ?></th>
						<td>
							<select name="<?php echo self::OPTION_NAME; ?>[related_post_terms_rules][<?php echo $index; ?>][source_taxonomy]" required>
								<option value=""><?php _e('Select Source Taxonomy', 'bws-taxonomy-manager'); ?></option>
								<?php foreach ($taxonomies as $taxonomy): ?>
									<option value="<?php echo esc_attr($taxonomy->name); ?>" 
											<?php selected($rule['source_taxonomy'] ?? '', $taxonomy->name); ?>>
										<?php echo esc_html($taxonomy->label); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php _e('The taxonomy on the related posts to get terms from.', 'bws-taxonomy-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Target Taxonomy', 'bws-taxonomy-manager'); ?></th>
						<td>
							<select name="<?php echo self::OPTION_NAME; ?>[related_post_terms_rules][<?php echo $index; ?>][target_taxonomy]" required>
								<option value=""><?php _e('Select Target Taxonomy', 'bws-taxonomy-manager'); ?></option>
								<?php foreach ($taxonomies as $taxonomy): ?>
									<option value="<?php echo esc_attr($taxonomy->name); ?>" 
											<?php selected($rule['target_taxonomy'] ?? '', $taxonomy->name); ?>>
										<?php echo esc_html($taxonomy->label); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php _e('The taxonomy on the main post to apply terms to.', 'bws-taxonomy-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Conflict Handling', 'bws-taxonomy-manager'); ?></th>
						<td>
							<select name="<?php echo self::OPTION_NAME; ?>[related_post_terms_rules][<?php echo $index; ?>][conflict_handling]">
								<option value="merge" <?php selected($rule['conflict_handling'] ?? 'merge', 'merge'); ?>>
									<?php _e('Merge with existing terms', 'bws-taxonomy-manager'); ?>
								</option>
								<option value="replace" <?php selected($rule['conflict_handling'] ?? 'merge', 'replace'); ?>>
									<?php _e('Replace existing terms', 'bws-taxonomy-manager'); ?>
								</option>
								<option value="skip" <?php selected($rule['conflict_handling'] ?? 'merge', 'skip'); ?>>
									<?php _e('Skip if terms exist', 'bws-taxonomy-manager'); ?>
								</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Bidirectional', 'bws-taxonomy-manager'); ?></th>
						<td>
							<label>
								<input type="checkbox" 
									   name="<?php echo self::OPTION_NAME; ?>[related_post_terms_rules][<?php echo $index; ?>][bidirectional]" 
									   value="1" <?php checked($rule['bidirectional'] ?? false); ?>>
								<?php _e('Remove target terms when no related posts have source terms', 'bws-taxonomy-manager'); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render hierarchical level restriction rules section
	 */
	private function render_hierarchical_level_restriction_rules() {
		$rules = $this->get_setting('hierarchical_level_restriction_rules', array());
		?>
		<div class="bws-rules-section">
			<h2><?php _e('Hierarchical Level Restrictions', 'bws-taxonomy-manager'); ?></h2>
			<p><?php _e('Restrict taxonomy terms to one per hierarchical level. When a new term is applied, remove sibling terms at the same level. Works with hierarchical inheritance rules.', 'bws-taxonomy-manager'); ?></p>

			<?php if (empty($rules)): ?>
				<div class="bws-empty-state">
					<div class="bws-empty-state-icon"></div>
					<h3><?php _e('No Level Restriction Rules Yet', 'bws-taxonomy-manager'); ?></h3>
					<p><?php _e('Level restriction rules enforce single-term selection per hierarchical level. Prevent users from selecting multiple sibling terms and maintain clean taxonomy structures.', 'bws-taxonomy-manager'); ?></p>
					<button type="button" class="button button-primary" id="add-hierarchical-level-restriction-rule-empty">
						<?php _e('Add Your First Level Restriction Rule', 'bws-taxonomy-manager'); ?>
					</button>
				</div>
			<?php endif; ?>

			<div id="hierarchical-level-restriction-rules-container" <?php echo empty($rules) ? 'style="display:none;"' : ''; ?>>
				<?php foreach ($rules as $index => $rule): ?>
					<?php $this->render_hierarchical_level_restriction_rule($rule, $index); ?>
				<?php endforeach; ?>
			</div>

			<template id="hierarchical-level-restriction-rule-template">
				<?php $this->render_hierarchical_level_restriction_rule(array()); ?>
			</template>

			<div class="bws-tab-actions-container">
				<div class="bws-tab-actions-left">
					<button type="button" class="button button-secondary" id="add-hierarchical-level-restriction-rule">
						<?php _e('Add Level Restriction Rule', 'bws-taxonomy-manager'); ?>
					</button>
					<?php if ($this->get_setting('manual_processing_enabled', true)): ?>
						<button type="button" class="button button-secondary process-existing-btn" data-rule-type="hierarchical_level_restriction">
							<?php _e('Process Existing Posts', 'bws-taxonomy-manager'); ?>
						</button>
					<?php endif; ?>
				</div>
				<div class="bws-tab-actions-right">
					<input type="hidden" name="save_tab" value="level-restriction">
					<?php submit_button(__('Save Level Restriction Rules', 'bws-taxonomy-manager'), 'primary', 'submit', false); ?>
					<span class="spinner"></span>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render single hierarchical level restriction rule
	 */
	private function render_hierarchical_level_restriction_rule($rule = array(), $index = '{{INDEX}}') {
		$taxonomies = get_taxonomies(array('public' => true), 'objects');
		$post_types = get_post_types(array('public' => true), 'objects');
		?>
		<div class="bws-rule-item" data-rule-type="hierarchical_level_restriction">
			<div class="bws-rule-header">
				<div class="bws-rule-header-left">
					<strong><?php printf(__('%s Rule', 'bws-taxonomy-manager'), isset($rule['taxonomy']) ? esc_html(ucfirst(get_taxonomy($rule['taxonomy'])->labels->singular_name ?? $rule['taxonomy'])) : __('New', 'bws-taxonomy-manager')); ?></strong>
				</div>
				<div class="bws-rule-header-actions">
					<?php
					$is_enabled = $rule['enabled'] ?? true;
					$button_class = $is_enabled ? 'button-secondary disable-rule-btn' : 'button-primary enable-rule-btn';
					$button_text = $is_enabled ? __('Disable', 'bws-taxonomy-manager') : __('Enable', 'bws-taxonomy-manager');
					?>
					<button type="button"
							class="button <?php echo $button_class; ?>"
							data-rule-index="<?php echo $index; ?>"
							data-rule-type="hierarchical_level_restriction_rules"
							data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
						<?php echo $button_text; ?>
					</button>
					<button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>

					<input type="hidden"
						   name="<?php echo self::OPTION_NAME; ?>[hierarchical_level_restriction_rules][<?php echo $index; ?>][enabled]"
						   value="<?php echo $is_enabled ? '1' : '0'; ?>"
						   class="rule-enabled-field">
				</div>
			</div>
			
			<div class="bws-rule-content">
				<table class="form-table">
					<tr>
						<th scope="row"><?php _e('Taxonomy', 'bws-taxonomy-manager'); ?></th>
						<td>
							<select name="<?php echo self::OPTION_NAME; ?>[hierarchical_level_restriction_rules][<?php echo $index; ?>][taxonomy]" required>
								<option value=""><?php _e('Select Taxonomy', 'bws-taxonomy-manager'); ?></option>
								<?php foreach ($taxonomies as $taxonomy): ?>
									<?php if ($taxonomy->hierarchical): ?>
										<option value="<?php echo esc_attr($taxonomy->name); ?>" 
												<?php selected($rule['taxonomy'] ?? '', $taxonomy->name); ?>>
											<?php echo esc_html($taxonomy->label); ?>
										</option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php _e('Must be a hierarchical taxonomy.', 'bws-taxonomy-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Restriction Mode', 'bws-taxonomy-manager'); ?></th>
						<td>
							<div class="radio-group">
								<label>
									<input type="radio" 
										   name="<?php echo self::OPTION_NAME; ?>[hierarchical_level_restriction_rules][<?php echo $index; ?>][restriction_mode]" 
										   value="one_per_level" <?php checked($rule['restriction_mode'] ?? 'one_per_level', 'one_per_level'); ?>>
									<?php _e('One term per hierarchical level', 'bws-taxonomy-manager'); ?>
								</label>
								<label>
									<input type="radio" 
										   name="<?php echo self::OPTION_NAME; ?>[hierarchical_level_restriction_rules][<?php echo $index; ?>][restriction_mode]" 
										   value="deepest_only" <?php checked($rule['restriction_mode'] ?? 'one_per_level', 'deepest_only'); ?>>
									<?php _e('Only deepest level terms', 'bws-taxonomy-manager'); ?>
								</label>
								<label>
									<input type="radio" 
										   name="<?php echo self::OPTION_NAME; ?>[hierarchical_level_restriction_rules][<?php echo $index; ?>][restriction_mode]" 
										   value="shallowest_only" <?php checked($rule['restriction_mode'] ?? 'one_per_level', 'shallowest_only'); ?>>
									<?php _e('Only shallowest level terms', 'bws-taxonomy-manager'); ?>
								</label>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Include Ancestors', 'bws-taxonomy-manager'); ?></th>
						<td>
							<label>
								<input type="checkbox" 
									   name="<?php echo self::OPTION_NAME; ?>[hierarchical_level_restriction_rules][<?php echo $index; ?>][include_ancestors]" 
									   value="1" <?php checked($rule['include_ancestors'] ?? false); ?>>
								<?php _e('Include ancestor terms when using "deepest only" mode', 'bws-taxonomy-manager'); ?>
							</label>
							<p class="description"><?php _e('Works with existing hierarchical inheritance rules.', 'bws-taxonomy-manager'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Post Types (Optional)', 'bws-taxonomy-manager'); ?></th>
						<td>
							<div class="checkbox-group">
								<?php foreach ($post_types as $post_type): ?>
									<label>
										<input type="checkbox" 
											   name="<?php echo self::OPTION_NAME; ?>[hierarchical_level_restriction_rules][<?php echo $index; ?>][post_types][]" 
											   value="<?php echo esc_attr($post_type->name); ?>"
											   <?php checked(in_array($post_type->name, $rule['post_types'] ?? array())); ?>>
										<?php echo esc_html($post_type->label); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<small><?php _e('Leave empty to apply to all post types using this taxonomy.', 'bws-taxonomy-manager'); ?></small>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
    
    /**
     * Render general settings section
     */
    private function render_general_settings() {
        $conflict_handling = $this->get_setting('conflict_handling', array());
        $manual_processing = $this->get_setting('manual_processing_enabled', true);
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        ?>
        <div class="bws-rules-section">
            <h2><?php _e('General Settings', 'bws-taxonomy-manager'); ?></h2>
            
            <div class="general-settings-section">
                <h3><?php _e('Global Conflict Handling', 'bws-taxonomy-manager'); ?></h3>
                <p><?php _e('Set default behavior when existing terms conflict with new terms being applied.', 'bws-taxonomy-manager'); ?></p>
                
                <table class="conflict-handling-table">
                    <thead>
                        <tr>
                            <th><?php _e('Taxonomy', 'bws-taxonomy-manager'); ?></th>
                            <th><?php _e('Conflict Handling', 'bws-taxonomy-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($taxonomies as $taxonomy): ?>
                            <tr>
                                <td><strong><?php echo esc_html($taxonomy->label); ?></strong><br>
                                    <small><?php echo esc_html($taxonomy->name); ?></small>
                                </td>
                                <td>
                                    <select name="<?php echo self::OPTION_NAME; ?>[conflict_handling][<?php echo esc_attr($taxonomy->name); ?>]">
                                        <option value="merge" <?php selected($conflict_handling[$taxonomy->name] ?? 'merge', 'merge'); ?>>
                                            <?php _e('Merge with existing terms', 'bws-taxonomy-manager'); ?>
                                        </option>
                                        <option value="replace" <?php selected($conflict_handling[$taxonomy->name] ?? 'merge', 'replace'); ?>>
                                            <?php _e('Replace existing terms', 'bws-taxonomy-manager'); ?>
                                        </option>
                                        <option value="skip" <?php selected($conflict_handling[$taxonomy->name] ?? 'merge', 'skip'); ?>>
                                            <?php _e('Skip if terms exist', 'bws-taxonomy-manager'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="general-settings-section">
                <h3><?php _e('Processing Options', 'bws-taxonomy-manager'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Manual Processing', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo self::OPTION_NAME; ?>[manual_processing_enabled]" 
                                       value="1" <?php checked($manual_processing); ?>>
                                <?php _e('Enable "Process Existing Posts" buttons', 'bws-taxonomy-manager'); ?>
                            </label>
                            <p class="description">
                                <?php _e('When enabled, you can manually process existing posts after changing rules. Disable this on production sites to prevent accidental bulk operations.', 'bws-taxonomy-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="bws-tab-actions-container">
                <div class="bws-tab-actions-left">
                    <!-- No add button for general settings -->
                </div>
                <div class="bws-tab-actions-right">
                    <input type="hidden" name="save_tab" value="settings">
                    <?php submit_button(__('Save Settings', 'bws-taxonomy-manager'), 'primary', 'submit', false); ?>
                    <span class="spinner"></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render system information section
     */
    private function render_system_information() {
        ?>
        <div class="bws-rules-section">
            <h2><?php _e('System Information', 'bws-taxonomy-manager'); ?></h2>
            <p class="description">
                <?php _e('View your system configuration and plugin dependencies. This information is useful for debugging and support.', 'bws-taxonomy-manager'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Plugin Version', 'bws-taxonomy-manager'); ?></th>
                    <td><code><?php echo esc_html(BWS_TAX_MANAGER_VERSION); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('PHP Version', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <code><?php echo esc_html(PHP_VERSION); ?></code>
                        <?php if (version_compare(PHP_VERSION, '8.1', '>=')): ?>
                            <span class="status-indicator active" aria-label="<?php esc_attr_e('PHP version supported', 'bws-taxonomy-manager'); ?>"></span>
                            <span style="color: #00a32a;"><?php _e('Supported', 'bws-taxonomy-manager'); ?></span>
                        <?php else: ?>
                            <span class="status-indicator error" aria-label="<?php esc_attr_e('PHP version not supported', 'bws-taxonomy-manager'); ?>"></span>
                            <span style="color: #d63638;"><?php _e('Not Supported (8.1+ required)', 'bws-taxonomy-manager'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('WordPress Version', 'bws-taxonomy-manager'); ?></th>
                    <td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('ACF Pro', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <?php if (function_exists('get_field')): ?>
                            <span class="status-indicator active" aria-label="<?php esc_attr_e('ACF Pro is active', 'bws-taxonomy-manager'); ?>"></span>
                            <?php _e('Active', 'bws-taxonomy-manager'); ?>
                            <?php if (defined('ACF_VERSION')): ?>
                                <code><?php echo esc_html(ACF_VERSION); ?></code>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-indicator inactive" aria-label="<?php esc_attr_e('ACF Pro is not active', 'bws-taxonomy-manager'); ?>"></span>
                            <?php _e('Not Active', 'bws-taxonomy-manager'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Admin Columns Pro', 'bws-taxonomy-manager'); ?></th>
                    <td>
                        <?php if (class_exists('ACP\\Plugin')): ?>
                            <span class="status-indicator active" aria-label="<?php esc_attr_e('Admin Columns Pro is active', 'bws-taxonomy-manager'); ?>"></span>
                            <?php _e('Active', 'bws-taxonomy-manager'); ?>
                        <?php else: ?>
                            <span class="status-indicator inactive" aria-label="<?php esc_attr_e('Admin Columns Pro is not active', 'bws-taxonomy-manager'); ?>"></span>
                            <?php _e('Not Active', 'bws-taxonomy-manager'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <!-- No save button - this is read-only information -->
        </div>
        <?php
    }

    /**
     * Render data conversion tab
     */
    private function render_conversion_tab() {
        // Check if ACF Pro is available
        if (!function_exists('acf_get_field_groups')) {
            ?>
            <div class="notice notice-error inline">
                <p>
                    <strong><?php _e('Data Conversion', 'bws-taxonomy-manager'); ?></strong><br>
                    <?php _e('Advanced Custom Fields Pro is required for data conversion functionality.', 'bws-taxonomy-manager'); ?>
                </p>
            </div>
            <?php
            return;
        }

        // Get conversion manager from main plugin instance
        $plugin = BWS_Taxonomy_Manager::get_instance();
        $conversion_manager = $plugin->get_conversion_manager();

        if (!$conversion_manager) {
            ?>
            <div class="notice notice-error inline">
                <p><?php _e('Conversion manager not initialized.', 'bws-taxonomy-manager'); ?></p>
            </div>
            <?php
            return;
        }

        // Create UI instance and render
        $conversion_ui = new BWS_Conversion_UI(
            $conversion_manager->get_field_mapper(),
            $conversion_manager->get_data_processor(),
            $conversion_manager->get_preview_system()
        );

        $conversion_ui->render_tab_content();
    }
}
