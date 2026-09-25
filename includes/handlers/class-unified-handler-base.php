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
     * Handler type identifier
     *
     * @var string
     */
    protected $handler_type;

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
     * Validate rule configuration (internal method)
     * Can be overridden by child handlers for specific validation
     *
     * @param array $rule Rule configuration
     * @return bool Valid
     */
    protected function validate_rule_internal($rule) {
        return !empty($rule['enabled']);
    }

    /**
     * Get all enabled rules for this handler
     *
     * Uses the storage abstraction layer to retrieve rules.
     *
     * The handler's own rules, out of its EFFECT-KIND list (ADR 0003 —
     * `term_rules` / `format_rules`). Since #66 that list is the only shape
     * there is, so "this handler's rules" means the list narrowed to one type,
     * which is exactly what `get_rules()` is: the narrowing lives in storage,
     * once, rather than being spelled out again here.
     *
     * The narrowing itself cannot go. The list is CROSS-TYPE — a term pass
     * reads six rule types out of one array — and a handler must see only its
     * own. What #66 removed is the second read path this used to be paired
     * with, not the filter.
     *
     * Every rule type maps to a kind, and the kind map IS storage's enumeration
     * of the types (`OptionRuleStorage::all_types()` flattens it), so a type
     * cannot fall out of one list and stay in the other. That is why there is
     * no fallback here: there is nothing left to fall back to.
     *
     * @since 0.2.0 Updated to use storage abstraction
     * @since 0.8.0 Reads the kind list, narrowed to this handler's type.
     * @return array Enabled rules
     */
    public function get_enabled_rules() {
        return StorageFactory::get_instance()
            ->get_rules($this->get_rule_type(), ['enabled' => true]);
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

        // Check post type. Storage projects both gates to slug lists (FW-29).
        $post_types = $rule['post_types'] ?? [];

        if (!empty($post_types)) {
            if ($post_types[0] !== 'any' && !in_array($post->post_type, $post_types)) {
                return false;
            }
        }

        // Check post status.
        $post_statuses = $rule['post_status'] ?? [];

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
     * Apply ONE rule to ONE post. THE applier seam.
     *
     * Why it exists (#31): the hook-driven handlers made process_post a no-op,
     * which left the bulk "process existing posts" tool inert: it looped
     * process_post, which did nothing, yet counted every post as processed (the
     * "lying button"). Bulk looped THIS instead, until the existing-posts
     * applier made bulk a provocation of the pass (FW-16).
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
     *              False by default, like `apply_to_data()`'s null: every term
     *              handler overrides it.
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        return false;
    }

    /**
     * Apply ONE format rule to ONE entity's post data. THE format applier seam
     * (#64).
     *
     * WHY IT IS NOT `apply_to_post()`. A term rule's effect is a set of term
     * relationships: the applier can write them and report whether anything
     * moved. A format rule's effect is the post ROW, and several rules can land
     * on one row — so a format applier writes nothing and instead returns the
     * data the next rule in the list receives. `FormatDispatcher` performs the
     * single `wp_update_post()` at the end of the pass, which is what keeps one
     * save to one row update however many rules matched.
     *
     * It is also the shape the deferred two-phase split needs (ADR 0003): when
     * `field_transformation` lands and the format pass gains a pre-write half,
     * that half can hand `wp_insert_post_data`'s own `$data` array to this same
     * seam, because the seam never assumed the row existed.
     *
     * NULL MEANS "NOT MINE". Returning null says this rule does not apply to
     * this entity at all — wrong post type, no pattern, nothing to say. It is
     * distinct from returning the data UNCHANGED, which says the rule applied
     * and the entity is already in the state it wants. The dispatcher needs
     * both: first-match-of-a-type-wins is decided on the first non-null, and a
     * no-op re-apply must not consume that slot's decision differently from the
     * apply that produced it.
     *
     * An override MUST be idempotent and MUST be the handler's WHOLE apply, for
     * the same reason `apply_to_post()`'s must: a pass runs every rule whether
     * or not that rule's own trigger fired, because format rules have no
     * triggers of their own any more.
     *
     * Null by default: a handler of another effect kind is in the same handler
     * map and is asked nothing here.
     *
     * @param array $data    Post data as the previous rule left it. Keys: ID,
     *                       post_title, post_name, post_type, post_status,
     *                       post_date, post_parent. Only post_title and
     *                       post_name are written back.
     * @param int   $post_id Entity being passed over.
     * @param array $rule    One enabled rule (canonical shape).
     * @return array|null Post data, or null when the rule does not apply here.
     */
    public function apply_to_data(array $data, int $post_id, array $rule): ?array {
        return null;
    }

    /**
     * Persist the per-rule state of one `apply_to_data()` answer, as the pass
     * writes. Called by `FormatDispatcher::write()` for every rule that
     * answered non-null, before the row update.
     *
     * Split off the applier so the dispatcher's compute step writes nothing
     * (FW-16 03): the format preview runs the appliers, and any write in one
     * would be a write from a preview. What belongs here is state that is a
     * consequence of the rule resolving but is not post data — idempotency
     * meta, a status record.
     *
     * No-op by default.
     *
     * @param array $before  Post data the applier was handed.
     * @param array $after   Post data it returned.
     * @param int   $post_id Entity being passed over.
     * @param array $rule    The rule that answered.
     */
    public function commit_data(array $before, array $after, int $post_id, array $rule): void {}

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
}
