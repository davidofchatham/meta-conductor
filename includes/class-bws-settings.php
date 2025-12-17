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
        'conflict_handling' => array(),
        'manual_processing_enabled' => true
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
			$success_message = __('Settings saved.', 'bws-taxonomy-manager');
			
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
			
			<form method="post" action="<?php echo admin_url('options-general.php?page=bws-taxonomy-manager&tab=' . esc_attr($current_tab)); ?>">
				<?php wp_nonce_field('bws_taxonomy_manager_settings', 'bws_taxonomy_manager_nonce'); ?>
				<input type="hidden" name="current_tab" id="current_tab" value="<?php echo esc_attr($current_tab); ?>">
				
				<div class="bws-taxonomy-manager-tabs">
					<nav class="nav-tab-wrapper">
						<a href="#hierarchical" class="nav-tab <?php echo $current_tab === 'hierarchical' ? 'nav-tab-active' : ''; ?>" data-tab="hierarchical">
							<?php _e('Hierarchical Rules', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#propagation" class="nav-tab <?php echo $current_tab === 'propagation' ? 'nav-tab-active' : ''; ?>" data-tab="propagation">
							<?php _e('Propagation Rules', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#related" class="nav-tab <?php echo $current_tab === 'related' ? 'nav-tab-active' : ''; ?>" data-tab="related">
							<?php _e('Related Terms', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#time-based" class="nav-tab <?php echo $current_tab === 'time-based' ? 'nav-tab-active' : ''; ?>" data-tab="time-based">
							<?php _e('Time-Based Rules', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#related-post-terms" class="nav-tab <?php echo $current_tab === 'related-post-terms' ? 'nav-tab-active' : ''; ?>" data-tab="related-post-terms">
							<?php _e('Related Post Terms', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#level-restriction" class="nav-tab <?php echo $current_tab === 'level-restriction' ? 'nav-tab-active' : ''; ?>" data-tab="level-restriction">
							<?php _e('Level Restrictions', 'bws-taxonomy-manager'); ?>
						</a>
						<a href="#settings" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>" data-tab="settings">
							<?php _e('General Settings', 'bws-taxonomy-manager'); ?>
						</a>
					</nav>
					
					<div id="hierarchical" class="tab-content <?php echo $current_tab === 'hierarchical' ? 'active' : ''; ?>">
						<?php $this->render_hierarchical_rules(); ?>
					</div>
					
					<div id="propagation" class="tab-content <?php echo $current_tab === 'propagation' ? 'active' : ''; ?>">
						<?php $this->render_propagation_rules(); ?>
					</div>
					
					<div id="related" class="tab-content <?php echo $current_tab === 'related' ? 'active' : ''; ?>">
						<?php $this->render_related_rules(); ?>
					</div>
					
					<div id="time-based" class="tab-content <?php echo $current_tab === 'time-based' ? 'active' : ''; ?>">
						<?php $this->render_time_based_rules(); ?>
					</div>
					
					<div id="related-post-terms" class="tab-content <?php echo $current_tab === 'related-post-terms' ? 'active' : ''; ?>">
						<?php $this->render_related_post_terms_rules(); ?>
					</div>
					
					<div id="level-restriction" class="tab-content <?php echo $current_tab === 'level-restriction' ? 'active' : ''; ?>">
						<?php $this->render_hierarchical_level_restriction_rules(); ?>
					</div>
					
					<div id="settings" class="tab-content <?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
						<?php $this->render_general_settings(); ?>
					</div>
				</div>
				
				<?php submit_button(); ?>
			</form>
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
            
            <div id="hierarchical-rules-container">
                <?php foreach ($rules as $index => $rule): ?>
                    <?php $this->render_hierarchical_rule($rule, $index); ?>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="button" id="add-hierarchical-rule">
                <?php _e('Add Hierarchical Rule', 'bws-taxonomy-manager'); ?>
            </button>
            
            <template id="hierarchical-rule-template">
                <?php $this->render_hierarchical_rule(array()); ?>
            </template>
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
                <label>
                    <input type="checkbox" 
                           name="<?php echo self::OPTION_NAME; ?>[hierarchical_rules][<?php echo $index; ?>][enabled]" 
                           value="1" <?php checked($rule['enabled'] ?? false); ?>>
                    <?php _e('Enable Rule', 'bws-taxonomy-manager'); ?>
                </label>
                <button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>
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
            
            <div id="propagation-rules-container">
                <?php foreach ($rules as $index => $rule): ?>
                    <?php $this->render_propagation_rule($rule, $index); ?>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="button" id="add-propagation-rule">
                <?php _e('Add Propagation Rule', 'bws-taxonomy-manager'); ?>
            </button>
            
            <template id="propagation-rule-template">
                <?php $this->render_propagation_rule(array()); ?>
            </template>
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
                <label>
                    <input type="checkbox" 
                           name="<?php echo self::OPTION_NAME; ?>[propagation_rules][<?php echo $index; ?>][enabled]" 
                           value="1" <?php checked($rule['enabled'] ?? false); ?>>
                    <?php _e('Enable Rule', 'bws-taxonomy-manager'); ?>
                </label>
                <button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>
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
     * Render related rules section
     */
    private function render_related_rules() {
        $rules = $this->get_setting('related_rules', array());
        ?>
        <div class="bws-rules-section">
            <h2><?php _e('Related Terms', 'bws-taxonomy-manager'); ?></h2>
            <p><?php _e('Automatically apply related terms when trigger terms or taxonomies are applied to posts.', 'bws-taxonomy-manager'); ?></p>
            
            <div id="related-rules-container">
                <?php foreach ($rules as $index => $rule): ?>
                    <?php $this->render_related_rule($rule, $index); ?>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="button" id="add-related-rule">
                <?php _e('Add Related Rule', 'bws-taxonomy-manager'); ?>
            </button>
            
            <template id="related-rule-template">
                <?php $this->render_related_rule(array()); ?>
            </template>
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
                <label>
                    <input type="checkbox" 
                           name="<?php echo self::OPTION_NAME; ?>[related_rules][<?php echo $index; ?>][enabled]" 
                           value="1" <?php checked($rule['enabled'] ?? false); ?>>
                    <?php _e('Enable Rule', 'bws-taxonomy-manager'); ?>
                </label>
                <button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>
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
            
            <div id="time-based-rules-container">
                <?php foreach ($rules as $index => $rule): ?>
                    <?php $this->render_time_based_rule($rule, $index); ?>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="button" id="add-time-based-rule">
                <?php _e('Add Time-Based Rule', 'bws-taxonomy-manager'); ?>
            </button>
            
            <template id="time-based-rule-template">
                <?php $this->render_time_based_rule(array()); ?>
            </template>
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
                <label>
                    <input type="checkbox" 
                           name="<?php echo self::OPTION_NAME; ?>[time_based_rules][<?php echo $index; ?>][enabled]" 
                           value="1" <?php checked($rule['enabled'] ?? false); ?>>
                    <?php _e('Enable Rule', 'bws-taxonomy-manager'); ?>
                </label>
                <button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>
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
			
			<div id="related-post-terms-rules-container">
				<?php foreach ($rules as $index => $rule): ?>
					<?php $this->render_related_post_terms_rule($rule, $index); ?>
				<?php endforeach; ?>
			</div>
			
			<button type="button" class="button" id="add-related-post-terms-rule">
				<?php _e('Add Related Post Terms Rule', 'bws-taxonomy-manager'); ?>
			</button>
			
			<?php if ($this->get_setting('manual_processing_enabled', true)): ?>
				<button type="button" class="button process-existing-btn" data-rule-type="related_post_terms">
					<?php _e('Process Existing Posts', 'bws-taxonomy-manager'); ?>
				</button>
			<?php endif; ?>
			
			<template id="related-post-terms-rule-template">
				<?php $this->render_related_post_terms_rule(array()); ?>
			</template>
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
				<label>
					<input type="checkbox" 
						   name="<?php echo self::OPTION_NAME; ?>[related_post_terms_rules][<?php echo $index; ?>][enabled]" 
						   value="1" <?php checked($rule['enabled'] ?? false); ?>>
					<?php _e('Enable Rule', 'bws-taxonomy-manager'); ?>
				</label>
				<button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>
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
			
			<div id="hierarchical-level-restriction-rules-container">
				<?php foreach ($rules as $index => $rule): ?>
					<?php $this->render_hierarchical_level_restriction_rule($rule, $index); ?>
				<?php endforeach; ?>
			</div>
			
			<button type="button" class="button" id="add-hierarchical-level-restriction-rule">
				<?php _e('Add Level Restriction Rule', 'bws-taxonomy-manager'); ?>
			</button>
			
			<?php if ($this->get_setting('manual_processing_enabled', true)): ?>
				<button type="button" class="button process-existing-btn" data-rule-type="hierarchical_level_restriction">
					<?php _e('Process Existing Posts', 'bws-taxonomy-manager'); ?>
				</button>
			<?php endif; ?>
			
			<template id="hierarchical-level-restriction-rule-template">
				<?php $this->render_hierarchical_level_restriction_rule(array()); ?>
			</template>
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
				<label>
					<input type="checkbox" 
						   name="<?php echo self::OPTION_NAME; ?>[hierarchical_level_restriction_rules][<?php echo $index; ?>][enabled]" 
						   value="1" <?php checked($rule['enabled'] ?? false); ?>>
					<?php _e('Enable Rule', 'bws-taxonomy-manager'); ?>
				</label>
				<button type="button" class="button-link delete-rule"><?php _e('Delete', 'bws-taxonomy-manager'); ?></button>
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
            
            <div class="general-settings-section">
                <h3><?php _e('System Information', 'bws-taxonomy-manager'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Plugin Version', 'bws-taxonomy-manager'); ?></th>
                        <td><?php echo esc_html(BWS_TAX_MANAGER_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('PHP Version', 'bws-taxonomy-manager'); ?></th>
                        <td><?php echo esc_html(PHP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('WordPress Version', 'bws-taxonomy-manager'); ?></th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('ACF Pro', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <?php if (function_exists('get_field')): ?>
                                <span class="status-indicator active"></span> <?php _e('Active', 'bws-taxonomy-manager'); ?>
                            <?php else: ?>
                                <span class="status-indicator inactive"></span> <?php _e('Not Active', 'bws-taxonomy-manager'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Admin Columns Pro', 'bws-taxonomy-manager'); ?></th>
                        <td>
                            <?php if (class_exists('ACP\\Plugin')): ?>
                                <span class="status-indicator active"></span> <?php _e('Active', 'bws-taxonomy-manager'); ?>
                            <?php else: ?>
                                <span class="status-indicator inactive"></span> <?php _e('Not Active', 'bws-taxonomy-manager'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
}
