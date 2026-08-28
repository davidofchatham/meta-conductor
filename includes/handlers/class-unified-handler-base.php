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
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\StorageFactory;

abstract class UnifiedHandlerBase {

    // Shared handler primitives. TermOperations = native WP term apply/remove/
    // membership/fingerprint; AcfBridge = ACF taxonomy-field read/write/discover.
    // Both compose in here (never per-handler) so every handler always has them —
    // that is what keeps the "a base helper a handler calls MUST exist on the
    // base" invariant true (B4/§V14). Grepping this file's body alone won't show
    // these methods; see class-term-operations.php / class-acf-bridge.php.
    use TermOperations, AcfBridge;

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
     * Memoized plugin settings option, loaded once per request.
     *
     * @var array|null
     */
    private $settings_option_cache = null;

    /**
     * Constructor
     *
     * Takes no arguments. The old `Settings|null $settings` parameter existed
     * only for the legacy handlers, which read rules through an injected
     * settings object; every handler now goes through StorageFactory and the
     * shell it pointed at is deleted (#55). Nothing ever read `$this->settings`
     * after the Phase 3 migration.
     */
    public function __construct() {
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
     * Public reader for the rule type key.
     *
     * The dispatcher keys its handler map by RULE type, because that is what a
     * kind-list row carries, and it resolves a handler's effect kind through
     * the same key. `get_rule_type()` is protected and stays that way — every
     * subclass declares it protected, so widening it in place would be a fatal
     * on all seven. This is the one-line seam instead. (#60)
     *
     * @since 0.8.0
     * @return string Rule type key (e.g. 'hierarchical_rules').
     */
    public function rule_type(): string {
        return $this->get_rule_type();
    }

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
     * Reads the handler's rules out of its EFFECT-KIND list (ADR 0003 —
     * `term_rules` / `format_rules`), filtering on each row's own `type`,
     * rather than out of the type-keyed array. The two are element-for-element
     * equal by construction (storage derives the kind list from the type-keyed
     * arrays and keeps `id` per-type), so no handler changed for this — the
     * point is that every handler now consumes rules through the ordered
     * model the dispatcher will iterate.
     *
     * Every rule type maps to a kind — H10 asserts the kind map and the storage
     * layer's valid-type list are the same set, so a type added to one and not
     * the other is a harness failure rather than a runtime read of zero rules.
     * That is why there is no type-keyed fallback here: a fallback would turn
     * that harness failure back into a silent one.
     *
     * @since 0.2.0 Updated to use storage abstraction
     * @since 0.8.0 Reads the kind list, filtered on row `type`.
     * @return array Enabled rules
     */
    public function get_enabled_rules() {
        $storage = StorageFactory::get_instance();
        $rule_type = $this->get_rule_type();
        $kind = $storage->get_kind_for_type($rule_type);

        return $storage->get_kind_rules($kind, ['enabled' => true, 'type' => $rule_type]);
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
            '[Meta Conductor - %s] Rule: %s | Processed: %d | Updated: %d | Skipped: %d',
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

        $table = $wpdb->prefix . 'bws_meta_conductor_log';

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

        $table = $wpdb->prefix . 'bws_meta_conductor_log';

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
     * Apply ONE rule to ONE post. THE applier seam.
     *
     * Why it exists (#31): the hook-driven handlers made process_post a no-op —
     * their real work fired from their own set_object_terms/save_post/acf hooks,
     * so the base process_post (which routes through RuleEngine) must NOT run
     * for them. That left the bulk "process existing posts" tool inert: it
     * looped process_post, which did nothing, yet counted every post as
     * processed (the "lying button"). Bulk loops THIS instead.
     *
     * What it became (#60): the seam a CONVERTED handler is reduced to. A
     * converted handler registers no hooks at all; `TermDispatcher` owns the
     * trigger union and calls this once per rule per pass, in authored order.
     * So an override must be the handler's WHOLE apply — everything its hooks
     * used to do — and it must be idempotent, because a pass re-runs every rule
     * whether or not that rule's own trigger fired. It must NOT carry a
     * re-entrancy boolean: the pass lock is the only guard, and a second one
     * silently suppresses the author's own chain (ADR 0003, rejected option).
     *
     * Handlers still awaiting conversion keep their hooks and their overrides
     * of this as the bulk primitive only — see TermDispatcher::UNCONVERTED_TYPES.
     *
     * ONLY `TermDispatcher::apply()` may call this. H13 asserts the single call
     * site; that is what makes "every execution went through a pass" checkable
     * rather than merely intended.
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
     * The OTHER entities this rule's effect reaches from $post_id. THE declared
     * fan-out seam (#62).
     *
     * WHY A DECLARATION RATHER THAN A WALK. `apply_to_post()` writes the entity
     * it was handed and nothing else — that is what makes a pass the unit of
     * execution, because an entity written from inside another entity's pass
     * never gets an ordered pass of its own. Propagation is the first rule type
     * whose effect is genuinely about a second entity, and it was writing its
     * descendants directly: the child was reconciled by ONE rule, out of band,
     * and hierarchical then expanded that write on its own hook — one extra
     * level of terms, which is #35.
     *
     * So a cross-entity rule INVERTS. Its applier pulls: it reconciles the post
     * it is given by reading the entities the rule points at. And separately it
     * declares, here, which entities its own change reaches. The dispatcher
     * marks those dirty; each gets its OWN full ordered pass, so every rule in
     * the list sees the child in list order instead of one rule reaching it
     * first.
     *
     * DECLARE NEIGHBOURS, NOT CLOSURES. Return the entities one step away — the
     * post's immediate children, not every descendant. Each of those gets a
     * pass, and its own fan-out carries the effect the next step, so the queue
     * performs the recursion. `TermDispatcher::drain()` bounds it at one pass
     * per entity per drain, which is what makes a parent/child cycle terminate.
     *
     * Empty by default: a rule whose effect stops at the entity it was applied
     * to declares nothing, and the dispatcher enqueues nothing extra for it.
     *
     * Called ONCE PER RULE PER PASS, whether or not the apply changed anything.
     * That is deliberate: the common case is a parent whose OWN terms a user
     * edited, where propagation's applier on the parent correctly reports "no
     * change to the parent" while the children are exactly what must now be
     * reconciled. Gate it on the rule being applicable to $post_id, not on the
     * apply's result.
     *
     * @param int   $post_id Entity the rule was just applied to.
     * @param array $rule    The same rule, canonical shape.
     * @return int[] Entity IDs to mark dirty. Empty for a same-entity rule.
     */
    public function fan_out(int $post_id, array $rule): array {
        return [];
    }

    /**
     * Entities an allow-listed CAPTURE hook says need a pass. THE capture-queue
     * seam (#63). Consuming: the dispatcher asks once per drain, and what it
     * was told is spent.
     *
     * WHY IT IS NOT `fan_out()`. The fan-out is asked while passing over a
     * post, and names entities reachable FROM it. A capture exists precisely
     * because the thing that would make an entity reachable is what the write
     * destroyed — a severed relationship, a deleted holder — so the entity it
     * names is reachable from no post that will get a pass. Folding the two
     * together would make the sever depend on some unrelated post happening to
     * be saved in the same request, which is the "keys under a post that is
     * never saved" trap invariant #15 records.
     *
     * WHY IT IS NOT the capture hook marking dirty itself. Capture is not
     * execution and must not reach into the dispatcher; keeping the direction
     * (dispatcher asks handler) is what lets H13 check the capture callbacks
     * for writes and find nothing but recording.
     *
     * Empty by default: a handler with no capture hooks has nothing to hand
     * over, and the dispatcher's ask costs an empty array.
     *
     * @return int[] Entity IDs to mark dirty. MUST be consumed — returning the
     *               same IDs on every call would refill the queue faster than
     *               the drain empties it.
     */
    public function drain_captures(): array {
        return [];
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

        // Loop rules × posts through the applier seam, NOT process_post — the
        // hook-driven handlers no-op process_post (#31). Count a post as
        // processed only when at least one rule actually applied to it, so the
        // reported total reflects work done, not just posts iterated (the old
        // loop counted every iteration → "Processed N of N" while writing
        // nothing).
        //
        // The call goes through TermDispatcher::apply() rather than straight to
        // $this->apply_to_post(): the dispatcher is the sole caller of that seam
        // (#60), which is what lets H13 prove by inspection that nothing
        // executes a rule outside it. It also means bulk apply takes the same
        // pass lock a hook-driven pass does, so the writes a rule makes here
        // don't enqueue this post for a redundant second pass at shutdown.
        //
        // A CONVERTED type takes the pass instead. Looping this handler's own
        // rules would apply them in isolation, so bulk would produce a
        // different end state than a save over the same rule set whenever a
        // rule of another type sits between two of this one's — which is
        // exactly the arrangement the ordered list exists to allow. A pass is
        // the same pass however it was provoked (CONTEXT.md → Pass), so bulk
        // provokes one. Handlers still owning their hooks keep the per-rule
        // loop; the branch goes away with the last of them (#66).
        $dispatcher   = TermDispatcher::instance();
        $run_full_pass = $dispatcher !== null && TermDispatcher::owns($this->get_rule_type());

        foreach ($query->posts as $post_id) {
            if (!get_post($post_id)) {
                continue;
            }
            $applied = false;
            if ($run_full_pass) {
                $applied = $dispatcher->run_pass((int) $post_id) > 0;
            } else {
                foreach ($rules as $rule) {
                    if (TermDispatcher::apply($post_id, $rule, $this)) {
                        $applied = true;
                    }
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
            //
            // On the pass path the count means "posts some rule in the ordered
            // list changed", not "posts THIS rule type changed", and the posts
            // scanned are still the ones this handler's rules name. Both are
            // consequences of bulk provoking a real pass, so the message says
            // which of the two it is rather than reporting the wider number
            // under the narrower sentence.
            'message' => $run_full_pass
                ? sprintf(
                    __('Ran the ordered rule list over %2$d posts; %1$d changed.', 'meta-conductor'),
                    $processed,
                    count($query->posts)
                )
                : sprintf(
                __('Applied to %d of %d posts scanned.', 'meta-conductor'),
                $processed,
                count($query->posts)
            )
        ];
    }
}
