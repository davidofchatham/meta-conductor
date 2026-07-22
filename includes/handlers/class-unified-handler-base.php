<?php
/**
 * Unified Handler Base Class
 *
 * New base class for handlers using unified entity-action framework
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use BWS\MetaConductor\Core\RuleEngine;
use BWS\MetaConductor\Core\Entity;
use BWS\MetaConductor\Storage\StorageFactory;
use BWS\MetaConductor\Settings;

abstract class UnifiedHandlerBase {

    /**
     * Rule engine instance
     *
     * @var RuleEngine
     */
    protected $rule_engine;

    /**
     * Handler type identifier
     *
     * @var string
     */
    protected $handler_type;

    /**
     * Settings instance (for backward compatibility)
     *
     * @var Settings|null
     */
    protected $settings;

    /**
     * Memoized plugin settings option, loaded once per request.
     *
     * @var array|null
     */
    private $settings_option_cache = null;

    /**
     * Constructor
     *
     * @param Settings|null $settings Settings instance (optional, for backward compatibility)
     */
    public function __construct($settings = null) {
        $this->settings = $settings;
        $this->rule_engine = new RuleEngine();
        $this->handler_type = $this->get_handler_type();
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     * Must be implemented by child handlers
     */
    abstract protected function init_hooks();

    /**
     * Get handler type identifier
     * Must be implemented by child handlers
     *
     * @return string Handler type (e.g., 'hierarchical', 'propagation')
     */
    abstract public function get_handler_type();

    /**
     * Get rule type key for settings
     * Must be implemented by child handlers
     *
     * @return string Rule type key (e.g., 'hierarchical_rules')
     */
    abstract protected function get_rule_type();

    /**
     * Process a rule using unified engine
     *
     * @param array $rule Rule configuration
     * @return array Processing results
     */
    public function process_rule($rule) {
        // Validate rule
        if (!$this->validate_rule_internal($rule)) {
            return [
                'processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['Rule validation failed'],
            ];
        }

        // Pre-process hook
        do_action("bws_meta_conductor_before_process_{$this->handler_type}", $rule);

        // Process via unified engine
        $results = $this->rule_engine->process_rule($rule);

        // Post-process hook
        do_action("bws_meta_conductor_after_process_{$this->handler_type}", $rule, $results);

        // Log results
        $this->log_results($rule, $results);

        return $results;
    }

    /**
     * Validate rule configuration (internal method)
     * Can be overridden by child handlers for specific validation
     *
     * @param array $rule Rule configuration
     * @return bool Valid
     */
    protected function validate_rule_internal($rule) {
        // Basic validation
        if (!isset($rule['enabled']) || !$rule['enabled']) {
            return false;
        }

        if (!isset($rule['action']['type'])) {
            return false;
        }

        // Validate source type
        $valid_source_types = ['post', 'term', 'user', 'comment', 'both'];
        if (!in_array($rule['source_type'] ?? 'post', $valid_source_types)) {
            return false;
        }

        // Validate target type
        $valid_target_types = ['self', 'post', 'term', 'user', 'comment', 'both'];
        if (!in_array($rule['target_type'] ?? 'self', $valid_target_types)) {
            return false;
        }

        return true;
    }

    /**
     * Get all enabled rules for this handler
     *
     * Uses the storage abstraction layer to retrieve rules.
     *
     * @since 0.2.0 Updated to use storage abstraction
     * @return array Enabled rules
     */
    public function get_enabled_rules() {
        $storage = StorageFactory::get_instance();
        $rule_type = $this->get_rule_type();

        return $storage->get_rules($rule_type, ['enabled' => true]);
    }

    /**
     * Get all rules (enabled and disabled) for this handler
     *
     * @since 0.2.0
     * @return array All rules
     */
    protected function get_all_rules() {
        $storage = StorageFactory::get_instance();
        $rule_type = $this->get_rule_type();

        return $storage->get_rules($rule_type);
    }

    /**
     * Get a specific rule by ID
     *
     * Uses the storage abstraction layer to retrieve a single rule.
     *
     * @since 0.2.0 Updated to use storage abstraction
     * @param int $rule_id Rule ID
     * @return array|null Rule configuration or null
     */
    protected function get_rule($rule_id) {
        $storage = StorageFactory::get_instance();
        $rule_type = $this->get_rule_type();

        return $storage->get_rule($rule_type, $rule_id);
    }

    /**
     * Save a rule
     *
     * @since 0.2.0
     * @param int   $rule_id Rule ID (-1 for new rule)
     * @param array $data Rule data
     * @return int Zero-based rule index on success, -1 on failure. Index 0 is
     *             a valid first rule — guard with `>= 0`, not `> 0`.
     */
    protected function save_rule($rule_id, array $data) {
        $storage = StorageFactory::get_instance();
        $rule_type = $this->get_rule_type();

        return $storage->save_rule($rule_type, $rule_id, $data);
    }

    /**
     * Delete a rule
     *
     * @since 0.2.0
     * @param int $rule_id Rule ID
     * @return bool True on success, false on failure
     */
    protected function delete_rule($rule_id) {
        $storage = StorageFactory::get_instance();
        $rule_type = $this->get_rule_type();

        return $storage->delete_rule($rule_type, $rule_id);
    }

    /**
     * Process a single entity against a rule
     *
     * Useful for processing individual posts/terms on save
     *
     * @param Entity $entity Entity to process
     * @param array $rule Rule configuration
     * @return array Processing results
     */
    protected function process_entity($entity, $rule) {
        // Modify rule to target this specific entity
        $single_rule = $rule;
        $single_rule['source_type'] = $entity->get_type();
        $single_rule['source_filters'] = ['ids' => [$entity->get_id()]];

        return $this->process_rule($single_rule);
    }

    /**
     * Process all enabled rules
     *
     * @return array Combined results
     */
    public function process_all_rules() {
        $rules = $this->get_enabled_rules();
        $combined_results = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($rules as $rule_id => $rule) {
            $results = $this->process_rule($rule);

            $combined_results['processed'] += $results['processed'];
            $combined_results['updated'] += $results['updated'];
            $combined_results['skipped'] += $results['skipped'];
            $combined_results['errors'] = array_merge($combined_results['errors'], $results['errors']);
        }

        return $combined_results;
    }

    /**
     * Bulk process a specific rule
     *
     * @param string $rule_id Rule ID
     * @return array Processing results
     */
    public function bulk_process($rule_id) {
        $rule = $this->get_rule($rule_id);

        if (!$rule) {
            return [
                'processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['Rule not found'],
            ];
        }

        return $this->process_rule($rule);
    }

    /**
     * Log processing results
     *
     * @param array $rule Rule configuration
     * @param array $results Processing results
     */
    protected function log_results($rule, $results) {
        // Only log if debugging is enabled or if there are errors
        if ((!defined('WP_DEBUG') || !WP_DEBUG) && empty($results['errors'])) {
            return;
        }

        $rule_name = $rule['name'] ?? 'Unnamed rule';

        $message = sprintf(
            '[BWS Meta Manager - %s] Rule: %s | Processed: %d | Updated: %d | Skipped: %d',
            $this->handler_type,
            $rule_name,
            $results['processed'],
            $results['updated'],
            $results['skipped']
        );

        if (!empty($results['errors'])) {
            $message .= ' | Errors: ' . implode(', ', $results['errors']);
        }

        error_log($message);

        // Optionally store in database. Memoized to avoid a fresh option read
        // on every rule run when the object cache is unavailable.
        if ($this->get_settings_option()['enable_logging'] ?? false) {
            $this->store_log_entry($rule, $results);
        }
    }

    /**
     * Read the plugin settings option once per request.
     *
     * @return array Settings option (empty array if unset).
     */
    private function get_settings_option() {
        if ($this->settings_option_cache === null) {
            $this->settings_option_cache = get_option('bws_meta_conductor_settings', []);
        }
        return $this->settings_option_cache;
    }

    /**
     * Store log entry in database
     *
     * @param array $rule Rule configuration
     * @param array $results Processing results
     */
    protected function store_log_entry($rule, $results) {
        global $wpdb;

        $table = $wpdb->prefix . 'bws_meta_manager_log';

        // Store summary entry
        $wpdb->insert(
            $table,
            [
                'rule_id' => $rule['id'] ?? 'unknown',
                'handler_type' => $this->handler_type,
                'source_entity_type' => $rule['source_type'] ?? 'post',
                'source_entity_id' => 0, // Summary entry
                'target_entity_type' => $rule['target_type'] ?? 'self',
                'target_entity_id' => 0,
                'action_type' => $rule['action']['type'] ?? 'unknown',
                'action_data' => wp_json_encode([
                    'processed' => $results['processed'],
                    'updated' => $results['updated'],
                    'skipped' => $results['skipped'],
                    'errors' => $results['errors'],
                ]),
                'result' => empty($results['errors']) ? 'success' : 'error',
                'applied_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Apply terms to a post honoring conflict handling.
     *
     * Ported from legacy HandlerBase (V10) so handlers migrated onto this
     * base inherit it. Behavior identical: merge/replace/skip.
     *
     * @param int    $post_id           Post ID
     * @param string $taxonomy          Taxonomy
     * @param array  $terms             Term IDs, objects, or arrays
     * @param string $conflict_handling 'merge' | 'replace' | 'skip'
     * @return array|false|\WP_Error wp_set_object_terms result, or false
     */
    protected function apply_terms_to_post(int $post_id, string $taxonomy, array $terms, string $conflict_handling = 'merge'): array|false|\WP_Error {
        if (empty($terms)) {
            return false;
        }

        // Ensure terms are term IDs
        $term_ids = array();
        foreach ($terms as $term) {
            if (is_object($term)) {
                $term_ids[] = $term->term_id;
            } elseif (is_array($term)) {
                $term_ids[] = $term['term_id'];
            } else {
                $term_ids[] = absint($term);
            }
        }

        $term_ids = array_unique(array_filter($term_ids));

        if (empty($term_ids)) {
            return false;
        }

        switch ($conflict_handling) {
            case 'replace':
                return \wp_set_object_terms($post_id, $term_ids, $taxonomy);

            case 'merge':
                $existing_terms = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
                if (\is_wp_error($existing_terms)) {
                    $existing_terms = array();
                }
                $merged_terms = array_unique(array_merge($existing_terms, $term_ids));
                return \wp_set_object_terms($post_id, $merged_terms, $taxonomy);

            case 'skip':
                $existing_terms = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
                if (\is_wp_error($existing_terms)) {
                    $existing_terms = array();
                }

                // Only apply if no existing terms
                if (empty($existing_terms)) {
                    return \wp_set_object_terms($post_id, $term_ids, $taxonomy);
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * Remove terms from a post.
     *
     * Ported from legacy HandlerBase (V10).
     *
     * @param int    $post_id  Post ID
     * @param string $taxonomy Taxonomy
     * @param array  $terms    Term IDs, objects, or arrays to remove
     * @return array|false|\WP_Error wp_set_object_terms result, or false
     */
    protected function remove_terms_from_post(int $post_id, string $taxonomy, array $terms): array|false|\WP_Error {
        if (empty($terms)) {
            return false;
        }

        $existing_terms = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (\is_wp_error($existing_terms)) {
            return false;
        }

        // Ensure terms are term IDs
        $term_ids_to_remove = array();
        foreach ($terms as $term) {
            if (is_object($term)) {
                $term_ids_to_remove[] = $term->term_id;
            } elseif (is_array($term)) {
                $term_ids_to_remove[] = $term['term_id'];
            } else {
                $term_ids_to_remove[] = absint($term);
            }
        }

        $remaining_terms = array_diff($existing_terms, $term_ids_to_remove);

        return \wp_set_object_terms($post_id, $remaining_terms, $taxonomy);
    }

    /**
     * Check if a post has specific terms in a taxonomy.
     *
     * Ported from legacy HandlerBase (V10). Null $term_ids ⇒ "has any term".
     *
     * @param int        $post_id  Post ID
     * @param string     $taxonomy Taxonomy
     * @param array|int|null $term_ids Term IDs to check, or null for any
     * @return bool
     */
    protected function post_has_terms(int $post_id, string $taxonomy, array|int|null $term_ids = null): bool {
        $post_terms = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));

        if (\is_wp_error($post_terms)) {
            return false;
        }

        if ($term_ids === null) {
            return !empty($post_terms);
        }

        if (!is_array($term_ids)) {
            $term_ids = array($term_ids);
        }

        return !empty(array_intersect($post_terms, $term_ids));
    }

    /**
     * Log a debug message when WP_DEBUG is on.
     *
     * Ported from legacy HandlerBase (V10).
     *
     * @param string $message
     * @param mixed  $data Optional context appended via print_r
     */
    protected function debug_log(string $message, mixed $data = null): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[Meta Conductor] ' . $message;
            if ($data !== null) {
                $log_message .= ' - Data: ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }

    /**
     * Read an ACF taxonomy field's value as a flat array of term IDs.
     *
     * Ported from legacy HandlerBase (B4/V14) — used by the propagation and
     * level-restriction ACF code paths, which now extend this base. Standalone
     * get_field() wrapper; unrelated to the removed AcfIntegration engine.
     * Returns [] when ACF is absent or the field is empty.
     *
     * @param int    $post_id
     * @param string $field_name
     * @param string $taxonomy   Accepted for signature parity; ACF returns the value directly.
     * @return int[]
     */
    protected function get_acf_taxonomy_value($post_id, $field_name, $taxonomy) {
        if (!function_exists('get_field')) {
            return array();
        }

        $value = get_field($field_name, $post_id);

        if (empty($value)) {
            return array();
        }

        // Handle different ACF taxonomy field return formats
        if (is_array($value)) {
            $term_ids = array();
            foreach ($value as $item) {
                if (is_object($item) && isset($item->term_id)) {
                    $term_ids[] = $item->term_id;
                } elseif (is_numeric($item)) {
                    $term_ids[] = absint($item);
                }
            }
            return $term_ids;
        } elseif (is_object($value) && isset($value->term_id)) {
            return array($value->term_id);
        } elseif (is_numeric($value)) {
            return array(absint($value));
        }

        return array();
    }

    /**
     * Write term IDs to an ACF taxonomy field.
     *
     * Ported from legacy HandlerBase (B4/V14). Standalone update_field()
     * wrapper; unrelated to the removed AcfIntegration engine.
     *
     * @param int       $post_id
     * @param string    $field_name
     * @param int[]|int $term_ids
     * @return mixed update_field() result, or false when ACF is absent.
     */
    protected function set_acf_taxonomy_value($post_id, $field_selector, $term_ids) {
        if (!function_exists('update_field')) {
            return false;
        }

        if (!is_array($term_ids)) {
            $term_ids = array($term_ids);
        }

        // $field_selector should be the ACF field KEY (field_xxxx), not the name,
        // when writing a field that may have NO prior value on this post. On a
        // first write ACF needs the key to register the hidden _{name} reference
        // row; passing the name falls back to a bare update_post_meta with no
        // reference, so get_field() can't later resolve/format the value.
        // (0.6.0 ACF B-sweep — get_acf_taxonomy_fields yields keys.)
        return update_field($field_selector, $term_ids, $post_id);
    }

    /**
     * Discover a post's ACF taxonomy fields for a taxonomy, INDEPENDENT of
     * whether the post has any saved field values.
     *
     * get_field_objects($post_id) enumerates from stored meta and returns FALSE
     * for a post with no ACF values yet — so a never-populated child could never
     * receive its first propagated/restricted ACF write (chicken-and-egg). This
     * resolves fields from field-group LOCATION rules instead (the same engine
     * the ACF admin uses), so attached-but-empty fields are found.
     *
     * Recurses sub_fields so a taxonomy field nested in a Group/Repeater is seen.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return array[] List of ['name' => string, 'key' => string] for each
     *                 matching taxonomy field. Empty when ACF is absent or none match.
     */
    protected function get_acf_taxonomy_fields($post_id, $taxonomy) {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return array();
        }

        $matches = array();

        $walk = function ($fields) use (&$walk, $taxonomy, &$matches) {
            foreach ((array) $fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (($field['type'] ?? '') === 'taxonomy'
                    && ($field['taxonomy'] ?? null) === $taxonomy) {
                    $matches[] = array(
                        'name' => $field['name'] ?? '',
                        'key'  => $field['key'] ?? '',
                    );
                }
                if (!empty($field['sub_fields'])) {
                    $walk($field['sub_fields']);
                }
            }
        };

        foreach (acf_get_field_groups(array('post_id' => $post_id)) as $group) {
            $walk(acf_get_fields($group['key']));
        }

        return $matches;
    }

    /**
     * Check if should process post (legacy compatibility)
     *
     * @param int $post_id Post ID
     * @param array $rule Rule configuration
     * @return bool Should process
     */
    protected function should_process_post($post_id, $rule) {
        $post = get_post($post_id);

        if (!$post) {
            return false;
        }

        // Check post type. Like post_status below, flatten the Wireframe
        // checkboxes {slug:bool} map via the canonical extractor rather than
        // hand-rolling it — keeps this from drifting from the status gate and
        // ConfigHelpers (the extractor's own docblock names this call site).
        $post_types = $rule['post_types'] ?? $rule['source_filters']['post_type'] ?? [];
        $post_types = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($post_types);

        if (!empty($post_types)) {
            if ($post_types[0] !== 'any' && !in_array($post->post_type, $post_types)) {
                return false;
            }
        }

        // Check post status. Like post_types, the config stores this as a
        // Wireframe checkboxes {slug:bool} map — flatten to selected slugs first,
        // else the (array) cast keeps the map and in_array compares against
        // boolean values (loose match => gate silently bypassed).
        $post_statuses = $rule['post_status'] ?? $rule['source_filters']['post_status'] ?? [];
        $post_statuses = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($post_statuses);

        if (!empty($post_statuses)) {
            if ($post_statuses[0] !== 'any' && !in_array($post->post_status, $post_statuses)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if should process term (for term-based handlers)
     *
     * @param int $term_id Term ID
     * @param string $taxonomy Taxonomy name
     * @param array $rule Rule configuration
     * @return bool Should process
     */
    protected function should_process_term($term_id, $taxonomy, $rule) {
        $term = get_term($term_id, $taxonomy);

        if (!$term || is_wp_error($term)) {
            return false;
        }

        // Check taxonomy
        $taxonomies = $rule['taxonomies'] ?? $rule['source_filters']['taxonomy'] ?? [];

        if (!empty($taxonomies)) {
            $taxonomies = (array)$taxonomies;
            if (!in_array($taxonomy, $taxonomies)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get processing statistics for this handler
     *
     * @return array Statistics
     */
    public function get_statistics() {
        global $wpdb;

        $table = $wpdb->prefix . 'bws_meta_manager_log';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_runs,
                SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END) as successful_runs,
                SUM(CASE WHEN result = 'error' THEN 1 ELSE 0 END) as failed_runs,
                MAX(applied_at) as last_run
            FROM {$table}
            WHERE handler_type = %s
            AND source_entity_id = 0",
            $this->handler_type
        ), ARRAY_A);

        return $stats ?: [
            'total_runs' => 0,
            'successful_runs' => 0,
            'failed_runs' => 0,
            'last_run' => null,
        ];
    }

    /**
     * Clear handler cache (if applicable)
     */
    public function clear_cache() {
        // Base implementation - override in child classes if needed
        delete_transient("bws_meta_conductor_{$this->handler_type}_cache");

        do_action("bws_meta_conductor_clear_{$this->handler_type}_cache");
    }

    /**
     * Get handler configuration defaults
     *
     * Can be overridden by child handlers
     *
     * @return array Default configuration
     */
    public function get_defaults() {
        return [
            'enabled' => true,
            'source_type' => 'post',
            'source_filters' => [],
            'condition' => [],
            'action' => [],
            'target_type' => 'self',
            'target_filters' => [],
        ];
    }

    /**
     * Convert legacy (pre-unified-framework) rule to unified format
     *
     * @param array $legacy_rule Legacy rule configuration
     * @return array Unified rule configuration
     */
    public function convert_legacy_rule($legacy_rule) {
        // Base implementation - should be overridden by child handlers
        // for handler-specific conversion logic

        $unified_rule = $this->get_defaults();
        $unified_rule['enabled'] = $legacy_rule['enabled'] ?? true;
        $unified_rule['name'] = $legacy_rule['name'] ?? '';

        return $unified_rule;
    }

    /**
     * Process a single post (backward compatibility method)
     *
     * This method provides backward compatibility with the legacy HandlerBase interface.
     * It processes a single post through all enabled rules.
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $update Whether this is an update
     */
    public function process_post($post_id, $post, $update) {
        // Get all enabled rules
        $rules = $this->get_enabled_rules();

        if (empty($rules)) {
            return;
        }

        // Create entity for this post
        $entity = new Entity('post', $post_id);

        // Process each rule
        foreach ($rules as $rule_id => $rule) {
            // Check if rule applies to this post
            if (!$this->should_process_post($post_id, $rule)) {
                continue;
            }

            // Process using unified engine
            $this->process_entity($entity, $rule);
        }
    }

    /**
     * Apply ONE rule to ONE existing post OUT OF BAND — the bulk-apply primitive
     * process_existing_posts() drives, decoupled from the hook-path process_post.
     *
     * Why this exists (#31): the hook-driven handlers (related, propagation,
     * level-restriction) make process_post a no-op — their real work fires from
     * their own set_object_terms/save_post/acf hooks, so the base process_post
     * (which routes through RuleEngine) must NOT run for them. That left the bulk
     * "process existing posts" tool inert: it looped process_post, which did
     * nothing, yet counted every post as processed (the "lying button"). Bulk now
     * loops THIS instead. Each hook-driven handler overrides it, delegating to its
     * own per-post primitive with the same $processing re-entry guard its hooks
     * use; RuleEngine handlers (hierarchical, title-slug, and time-based via its
     * functional process_post) inherit this default.
     *
     * @param int   $post_id Post to apply the rule to.
     * @param array $rule    One enabled rule (canonical shape).
     * @return bool True if the rule actually CHANGED the post's terms; false if
     *              the post-type gate rejected it OR the apply was a no-op (post
     *              already in the target state). Callers count only true — so the
     *              bulk tool reports posts genuinely touched, not merely scanned
     *              (#31: the honest-count contract is "changed", not "applicable").
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        if (!$this->should_process_post($post_id, $rule)) {
            return false;
        }

        // RuleEngine handlers (hierarchical) write the rule's own taxonomy;
        // fingerprint it across the apply so a no-op re-apply reports false.
        $taxonomy = $rule['taxonomy'] ?? '';
        $before   = $this->terms_fingerprint($post_id, $taxonomy);

        $entity = new Entity('post', $post_id);
        $this->process_entity($entity, $rule);

        return $this->terms_fingerprint($post_id, $taxonomy) !== $before;
    }

    /**
     * Stable fingerprint of a post's native term IDs in one taxonomy, for
     * before/after change detection in apply_to_post (#31). Empty taxonomy or a
     * WP_Error reads as the empty set — a subsequent real write then differs.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return string Sorted comma-joined term IDs (e.g. "13,14,15").
     */
    protected function terms_fingerprint(int $post_id, string $taxonomy): string {
        if ($taxonomy === '') {
            return '';
        }
        $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($ids)) {
            return '';
        }
        $ids = array_map('intval', $ids);
        sort($ids);
        return implode(',', $ids);
    }

    /**
     * Reapply this handler's term-sync to a single post after an out-of-band
     * write that fired NO save_post-family hook.
     *
     * The Admin Columns v7 reapply fallback (TaxonomyManager) calls this on
     * EVERY handler after an AC inline/bulk edit of an ACF field column — AC v7
     * writes ACF fields via update_field(), which fires acf/update_value only,
     * never acf/save_post/save_post/set_object_terms, so the handlers' normal
     * apply paths never run. (SPEC §V1/§V6, #37)
     *
     * No-op by default: only ACF-listening handlers override it (delegating to
     * their own gated on_acf_save_post). Handlers whose apply is already covered
     * by the native set_object_terms/save_post paths need no override — the
     * fallback calling this on them is a harmless no-op. (SPEC §V6/§V7)
     *
     * @param int $post_id Post whose fields were just edited.
     */
    public function reapply_for_post(int $post_id): void {}

    /**
     * Public validate_rule method for backward compatibility
     *
     * Wraps the protected validate_rule_internal method and returns array format
     * expected by legacy TaxonomyManager code.
     *
     * @param array $rule_data Rule data to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validate_rule($rule_data) {
        $errors = [];

        // Basic validation
        if (empty($rule_data['name'])) {
            $errors[] = __('Rule name is required.', 'meta-conductor');
        }

        // Call the protected validate_rule_internal for handler-specific validation
        $temp_rule = $rule_data;
        $temp_rule['enabled'] = $temp_rule['enabled'] ?? true;

        try {
            $is_valid = $this->validate_rule_internal($temp_rule);

            if (!$is_valid) {
                $errors[] = __('Rule configuration is invalid.', 'meta-conductor');
            }
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Process existing posts in batches (backward compatibility method)
     *
     * @param int $batch_size Number of posts to process per batch
     * @param int $offset Starting offset
     * @return array Processing results
     */
    public function process_existing_posts($batch_size = 50, $offset = 0) {
        $rules = $this->get_enabled_rules();

        if (empty($rules)) {
            return [
                'processed' => 0,
                'total' => 0,
                'complete' => true,
                'message' => __('No rules configured for this handler.', 'meta-conductor')
            ];
        }

        // Get post types from rules. The migrated handlers store the plural
        // `post_types` Wireframe checkbox map (empty ⇒ all); fall back to the
        // legacy scalar source_filters['post_type'] for any rule shape that
        // predates it. Flatten the map via the canonical extractor so this
        // matches should_process_post's gate.
        $post_types = [];
        foreach ($rules as $rule) {
            if (isset($rule['post_types'])) {
                $slugs = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($rule['post_types']);
                // Empty ⇒ "all" for this rule (matches the gate); widen to every
                // public type and stop narrowing.
                if (empty($slugs) || (isset($slugs[0]) && $slugs[0] === 'any')) {
                    $post_types = get_post_types(['public' => true]);
                    break;
                }
                $post_types = array_merge($post_types, $slugs);
                continue;
            }

            $source_filters = $rule['source_filters'] ?? [];
            $post_type = $source_filters['post_type'] ?? 'post';

            if ($post_type === 'any') {
                $post_types = get_post_types(['public' => true]);
                break;
            }

            if (is_array($post_type)) {
                $post_types = array_merge($post_types, $post_type);
            } else {
                $post_types[] = $post_type;
            }
        }

        $post_types = array_unique($post_types);

        if (empty($post_types)) {
            return [
                'processed' => 0,
                'total' => 0,
                'complete' => true,
                'message' => __('No applicable post types found.', 'meta-conductor')
            ];
        }

        // Get total count
        $total_query = new \WP_Query([
            'post_type' => $post_types,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => false
        ]);

        $total = $total_query->found_posts;

        // Get batch of posts
        $query = new \WP_Query([
            'post_type' => $post_types,
            'post_status' => 'any',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'fields' => 'ids'
        ]);

        $processed = 0;

        // Loop rules × posts through apply_to_post (the bulk primitive), NOT
        // process_post — the hook-driven handlers no-op process_post (#31). Count
        // a post as processed only when at least one rule actually applied to it,
        // so the reported total reflects work done, not just posts iterated (the
        // old loop counted every iteration → "Processed N of N" while writing
        // nothing).
        foreach ($query->posts as $post_id) {
            if (!get_post($post_id)) {
                continue;
            }
            $applied = false;
            foreach ($rules as $rule) {
                if ($this->apply_to_post($post_id, $rule)) {
                    $applied = true;
                }
            }
            if ($applied) {
                $processed++;
            }
        }

        $complete = ($offset + $batch_size) >= $total;

        return [
            'processed' => $processed,
            'total' => $total,
            'offset' => $offset + $batch_size,
            'complete' => $complete,
            // Report posts ACTUALLY changed out of those scanned THIS batch, not
            // raw iteration count — an applicable-to-none rule set now reads
            // "Applied to 0 of N" instead of a false "Processed N of N" (#31).
            // Both numerator and denominator are per-batch: $processed counts
            // this batch's changed posts, count($query->posts) is this batch's
            // scanned posts — mixing per-batch numerator with a cumulative
            // denominator would misreport under pagination.
            'message' => sprintf(
                __('Applied to %d of %d posts scanned.', 'meta-conductor'),
                $processed,
                count($query->posts)
            )
        ];
    }
}
