<?php
/**
 * Rule Storage Interface
 *
 * The read contract handlers, dispatchers and admin code call. It declares
 * only methods with callers; FW-17 grows it if a second backend ever lands.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Storage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rule Storage Interface
 *
 * Provides a unified API for storing and retrieving rules regardless
 * of the underlying storage mechanism.
 */
interface RuleStorage {

    /**
     * Get all rules of a specific type, in authored order.
     *
     * One type's slice of its kind list — since #66 the kind lists are the only
     * stored shape, so this is a view over get_kind_rules(), not a separate
     * read path.
     *
     * @since 0.2.0
     * @param string $type Rule type (hierarchical_rules, propagation_rules, etc.)
     * @param array  $filters Optional filters to apply:
     *                        - 'enabled' (bool): Filter by enabled status
     *                        - 'taxonomy' (string): Filter by taxonomy
     *                        - 'post_type' (string): Filter by post type
     *                        - 'limit' (int): Limit number of results
     *                        - 'offset' (int): Offset for pagination
     * @return array Array of rules, each rule is an associative array
     *
     * @example
     * $storage = StorageFactory::get_instance();
     * $rules = $storage->get_rules('hierarchical_rules', [
     *     'enabled' => true,
     *     'taxonomy' => 'category'
     * ]);
     */
    public function get_rules(string $type, array $filters = []): array;

    /**
     * Get the raw, cached settings option, including non-rule global keys
     * (conflict_handling_overrides, etc.). Served
     * from the same request cache as get_rules() so callers avoid a second
     * get_option() round-trip.
     *
     * @since 0.3.1
     * @return array Complete settings option (empty array if unset).
     */
    public function get_raw_settings(): array;

    /**
     * Get all rules in one effect-kind list, in AUTHORED order.
     *
     * The kind lists (`term_rules`, `format_rules`) are the ordered rule model
     * of ADR 0003 and, since #66, the only stored shape: every row carries its
     * own `type`, and order is array position. Order is the composition
     * semantics a dispatcher pass executes in, so this read never regroups or
     * re-sorts. Filtering on `['type' => X]` is what `get_rules(X, ...)` is.
     *
     * @since 0.8.0
     * @param string $kind    Kind list key (see get_kind_for_type()).
     * @param array  $filters Same filters as get_rules(), plus:
     *                        - 'type' (string): keep only rows of this rule type
     * @return array Array of rules in authored order; empty if the kind is unknown.
     */
    public function get_kind_rules(string $kind, array $filters = []): array;

    /**
     * Get a single rule by type and ID
     *
     * @since 0.2.0
     * @param string $type Rule type
     * @param int    $rule_id Rule ID (array index for options, post ID for CPT)
     * @return array|null Rule data array or null if not found
     *
     * @example
     * $rule = $storage->get_rule('hierarchical_rules', 0);
     */
    public function get_rule(string $type, int $rule_id): ?array;

    /**
     * Clear cache for rules
     *
     * Storage implementations that use caching should clear their cache
     * when this method is called.
     *
     * @since 0.2.0
     * @param string|null $type Optional rule type to clear, null for all
     * @return void
     */
    public function clear_cache(?string $type = null): void;
}
