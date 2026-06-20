<?php
/**
 * BWS Taxonomy Manager Admin Columns Pro Integration
 * Handles integration with Admin Columns Pro Quick Edit functionality
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Integrations;

use BWS\MetaConductor\TaxonomyManager;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AdminColumnsIntegration {
    
    /**
     * Handler instances
     */
    private $handlers;
    
    /**
     * Constructor
     */
    public function __construct($handlers) {
        $this->handlers = $handlers;
        
        // Only initialize if Admin Columns Pro is active
        if ($this->is_admin_columns_pro_active()) {
            $this->init_hooks();
        }
    }
    
    /**
     * Check if Admin Columns Pro is active
     */
    private function is_admin_columns_pro_active() {
        return class_exists('AC\\Plugin') && class_exists('ACP\\Plugin');
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Hook into Admin Columns Pro Quick Edit save
        add_action('acp/editing/saved', array($this, 'on_quick_edit_save'), 10, 3);
        
        // Hook into bulk edit operations
        add_action('acp/editing/bulk_saved', array($this, 'on_bulk_edit_save'), 10, 2);
        
        // Add custom column types if needed
        add_action('ac/column_types', array($this, 'register_custom_columns'));
        
        // Modify existing taxonomy columns
        add_filter('ac/column/taxonomy', array($this, 'modify_taxonomy_column'), 10, 2);
    }
    
    /**
     * Handle Quick Edit save operations
     */
    public function on_quick_edit_save($id, $value, $column) {
        // Skip if not a post ID
        if (!is_numeric($id)) {
            return;
        }
        
        $post = get_post($id);
        if (!$post) {
            return;
        }
        
        // Check if this is a taxonomy column
        if ($this->is_taxonomy_column($column)) {
            $this->process_taxonomy_column_update($id, $post, $column, $value);
        }
    }
    
    /**
     * Handle Bulk Edit save operations
     */
    public function on_bulk_edit_save($ids, $column) {
        if (!is_array($ids)) {
            $ids = array($ids);
        }
        
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            
            $post = get_post($id);
            if (!$post) {
                continue;
            }
            
            // Check if this is a taxonomy column
            if ($this->is_taxonomy_column($column)) {
                // For bulk operations, we need to get the value differently
                $this->process_taxonomy_bulk_update($id, $post, $column);
            }
        }
    }
    
    /**
     * Check if column is a taxonomy column
     */
    private function is_taxonomy_column($column) {
        if (!is_object($column)) {
            return false;
        }
        
        $column_type = $column->get_type();
        
        // Check for standard taxonomy columns
        if ($column_type === 'taxonomy' || $column_type === 'column-taxonomy') {
            return true;
        }
        
        // Check for custom taxonomy columns
        if (method_exists($column, 'get_taxonomy') && $column->get_taxonomy()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Process taxonomy column update
     */
    private function process_taxonomy_column_update($post_id, $post, $column, $value) {
        $taxonomy = $this->get_column_taxonomy($column);
        
        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return;
        }
        
        // Process through all handlers after a slight delay to ensure the taxonomy terms are saved
        wp_schedule_single_event(time() + 1, 'bws_process_post_after_column_update', array($post_id));
        
        // Also process immediately for handlers that need it
        $this->process_handlers_for_post($post_id, $post, $taxonomy);
    }
    
    /**
     * Process taxonomy bulk update
     */
    private function process_taxonomy_bulk_update($post_id, $post, $column) {
        $taxonomy = $this->get_column_taxonomy($column);
        
        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return;
        }
        
        // For bulk updates, process immediately
        $this->process_handlers_for_post($post_id, $post, $taxonomy);
    }
    
    /**
     * Get taxonomy from column
     */
    private function get_column_taxonomy($column) {
        if (method_exists($column, 'get_taxonomy')) {
            return $column->get_taxonomy();
        }
        
        // Try to get from column settings
        $settings = $column->get_options();
        if (isset($settings['taxonomy'])) {
            return $settings['taxonomy'];
        }
        
        return null;
    }
    
    /**
     * Process handlers for a post after column update
     */
    private function process_handlers_for_post($post_id, $post, $taxonomy) {
        foreach ($this->handlers as $handler_type => $handler) {
            if ($this->handler_uses_taxonomy($handler, $taxonomy)) {
                $handler->process_post($post_id, $post, true);
            }
        }
    }
    
    /**
     * Check if handler uses specific taxonomy
     */
    private function handler_uses_taxonomy($handler, $taxonomy) {
        $rules = $this->get_handler_rules($handler);
        
        foreach ($rules as $rule) {
            if (!empty($rule['enabled'])) {
                // Check different rule types
                if (isset($rule['taxonomy']) && $rule['taxonomy'] === $taxonomy) {
                    return true;
                }
                
                if (isset($rule['trigger_taxonomy']) && $rule['trigger_taxonomy'] === $taxonomy) {
                    return true;
                }
                
                // Check if any trigger term belongs to taxonomy.
                // trigger_term_id is int[] post-normalize (related rules); a
                // scalar is tolerated for any other rule type / legacy shape.
                if (isset($rule['trigger_term_id'])) {
                    foreach ((array) $rule['trigger_term_id'] as $tid) {
                        $term = get_term((int) $tid);
                        if ($term && !is_wp_error($term) && $term->taxonomy === $taxonomy) {
                            return true;
                        }
                    }
                }
                
                // Check if target term belongs to taxonomy
                if (isset($rule['target_term_id'])) {
                    $term = get_term($rule['target_term_id']);
                    if ($term && !is_wp_error($term) && $term->taxonomy === $taxonomy) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get rules from handler
     */
    private function get_handler_rules($handler) {
        if (method_exists($handler, 'get_enabled_rules')) {
            return $handler->get_enabled_rules();
        }
        
        return array();
    }
    
    /**
     * Register custom column types
     */
    public function register_custom_columns($columns) {
        // Add custom BWS Taxonomy Manager columns if needed
        // For now, we'll work with existing taxonomy columns
        return $columns;
    }
    
    /**
     * Modify existing taxonomy columns
     */
    public function modify_taxonomy_column($column, $taxonomy) {
        // Add any modifications to existing taxonomy columns
        // For example, add tooltips or additional functionality
        
        if ($this->taxonomy_has_bws_rules($taxonomy)) {
            // Add a note that this taxonomy is managed by BWS Taxonomy Manager
            add_filter("ac/column/taxonomy/meta/{$taxonomy}", array($this, 'add_bws_column_meta'));
        }
        
        return $column;
    }
    
    /**
     * Check if taxonomy has BWS rules
     */
    private function taxonomy_has_bws_rules($taxonomy) {
        foreach ($this->handlers as $handler) {
            $rules = $this->get_handler_rules($handler);
            
            foreach ($rules as $rule) {
                if (!empty($rule['enabled']) && 
                    (($rule['taxonomy'] ?? null) === $taxonomy ||
                     ($rule['trigger_taxonomy'] ?? null) === $taxonomy)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Add BWS meta to column
     */
    public function add_bws_column_meta($meta) {
        $meta['bws_managed'] = true;
        $meta['bws_tooltip'] = __('This taxonomy is managed by BWS Taxonomy Manager', 'bws-taxonomy-manager');
        
        return $meta;
    }
    
    /**
     * Add JavaScript for Quick Edit enhancements
     */
    public function enqueue_quick_edit_scripts() {
        if (!$this->is_admin_columns_screen()) {
            return;
        }
        
        wp_enqueue_script(
            'bws-taxonomy-manager-quick-edit',
            BWS_TAX_MANAGER_PLUGIN_URL . 'assets/js/quick-edit.js',
            array('jquery', 'ac-quick-edit'),
            BWS_TAX_MANAGER_VERSION,
            true
        );
        
        wp_localize_script('bws-taxonomy-manager-quick-edit', 'bwsTaxManagerQuickEdit', array(
            'nonce' => wp_create_nonce('bws_taxonomy_manager_quick_edit'),
            'strings' => array(
                'processing' => __('Processing taxonomy rules...', 'bws-taxonomy-manager'),
                'complete' => __('Taxonomy rules applied.', 'bws-taxonomy-manager'),
                'error' => __('Error applying taxonomy rules.', 'bws-taxonomy-manager')
            )
        ));
    }
    
    /**
     * Check if current screen is Admin Columns
     */
    private function is_admin_columns_screen() {
        $screen = get_current_screen();
        
        if (!$screen) {
            return false;
        }
        
        // Check if we're on a post list screen with Admin Columns active
        return ($screen->base === 'edit' && function_exists('AC'));
    }
    
    /**
     * Handle delayed post processing
     */
    public function process_post_after_column_update($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        
        // Process through all handlers
        foreach ($this->handlers as $handler) {
            $handler->process_post($post_id, $post, true);
        }
    }
    
    /**
     * Add column CSS for BWS managed taxonomies
     */
    public function enqueue_column_styles() {
        if (!$this->is_admin_columns_screen()) {
            return;
        }
        
        wp_enqueue_style(
            'bws-taxonomy-manager-columns',
            BWS_TAX_MANAGER_PLUGIN_URL . 'assets/css/admin-columns.css',
            array(),
            BWS_TAX_MANAGER_VERSION
        );
    }
    
    /**
     * Add quick edit validation
     */
    public function validate_quick_edit_data($post_id, $column, $value) {
        if (!$this->is_taxonomy_column($column)) {
            return true;
        }
        
        $taxonomy = $this->get_column_taxonomy($column);
        
        if (!$taxonomy) {
            return true;
        }
        
        // Add any specific validation rules for BWS managed taxonomies
        return $this->validate_taxonomy_terms($taxonomy, $value);
    }
    
    /**
     * Validate taxonomy terms
     */
    private function validate_taxonomy_terms($taxonomy, $terms) {
        if (!is_array($terms)) {
            $terms = array($terms);
        }
        
        foreach ($terms as $term_id) {
            $term = get_term($term_id, $taxonomy);
            if (!$term || is_wp_error($term)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get taxonomy column configuration
     */
    public function get_taxonomy_column_config($taxonomy) {
        $config = array(
            'taxonomy' => $taxonomy,
            'bws_managed' => $this->taxonomy_has_bws_rules($taxonomy),
            'rules_count' => $this->get_taxonomy_rules_count($taxonomy),
            'handlers' => array()
        );
        
        // Get applicable handlers
        foreach ($this->handlers as $handler_type => $handler) {
            if ($this->handler_uses_taxonomy($handler, $taxonomy)) {
                $config['handlers'][] = $handler_type;
            }
        }
        
        return $config;
    }
    
    /**
     * Get count of rules for taxonomy
     */
    private function get_taxonomy_rules_count($taxonomy) {
        $count = 0;
        
        foreach ($this->handlers as $handler) {
            $rules = $this->get_handler_rules($handler);
            
            foreach ($rules as $rule) {
                if (!empty($rule['enabled']) && $this->rule_affects_taxonomy($rule, $taxonomy)) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Check if rule affects taxonomy
     */
    private function rule_affects_taxonomy($rule, $taxonomy) {
        // Check direct taxonomy references
        if (($rule['taxonomy'] ?? null) === $taxonomy ||
            ($rule['trigger_taxonomy'] ?? null) === $taxonomy) {
            return true;
        }
        
        // Check term references. trigger_term_id may be int[] (related rules
        // post-normalize); target_term_id is scalar. (array) cast handles both.
        $term_fields = array('trigger_term_id', 'target_term_id');
        foreach ($term_fields as $field) {
            if (empty($rule[$field])) {
                continue;
            }
            foreach ((array) $rule[$field] as $tid) {
                $term = get_term((int) $tid);
                if ($term && !is_wp_error($term) && $term->taxonomy === $taxonomy) {
                    return true;
                }
            }
        }
        
        return false;
    }
}

// Hook to process delayed post updates
add_action('bws_process_post_after_column_update', function($post_id) {
    $taxonomy_manager = TaxonomyManager::get_instance();
    $integration = new AdminColumnsIntegration($taxonomy_manager->get_handlers());
    $integration->process_post_after_column_update($post_id);
});
