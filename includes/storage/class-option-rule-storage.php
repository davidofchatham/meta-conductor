<?php
/**
 * WordPress Options Rule Storage Implementation
 *
 * Stores rules in a single serialized array in wp_options.
 * This is the current storage method used by BWS Meta Manager.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Storage;

use BWS\MetaConductor\RuleTypes\Registry;
use BWS\MetaConductor\RuleTypes\RuleType;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Options-based Rule Storage
 *
 * Implements RuleStorage interface using WordPress options table.
 * All rules are stored in a single option as a nested array structure.
 */
class OptionRuleStorage implements RuleStorage {

    /**
     * Options key in wp_options table
     *
     * @var string
     */
    const OPTION_NAME = 'bws_meta_conductor_settings';

    /**
     * Cached settings array
     *
     * @var array|null
     */
    private $cached_settings = null;

    /**
     * Effect-kind list keys (ADR 0003 decision 1).
     *
     * @since 0.8.0
     */
    const KIND_TERM   = 'term_rules';
    const KIND_FORMAT = 'format_rules';

    /**
     * The config-migrated types of one kind, in registry order.
     *
     * A type is declared in `RuleTypes\Registry`; it is offered and persisted
     * only once its descriptor says the repeater declares its subfields
     * (`has_subfields()`). A row of a type without them must NOT appear in a
     * persisted kind list: the repeater renders every row in the key it is
     * bound to and Wireframe DROPS any subfield the config does not declare
     * (`RepeaterField::sanitize`), so rendering it would silently gut it on the
     * next save. That split is what lets the next type (`field_transformation`)
     * be declared — and therefore read — a change before its subfields exist.
     *
     * Empty ⇒ no repeater is bound to that kind's list yet, so the config
     * classes render no rows for it.
     *
     * @since 0.8.0
     * @param string $kind KIND_TERM or KIND_FORMAT.
     * @return string[]
     */
    public static function migrated_types_for_kind(string $kind): array {
        return array_keys(array_filter(
            Registry::of_kind($kind),
            static fn(RuleType $d): bool => $d->has_subfields()
        ));
    }

    /**
     * Every rule type storage knows, in registry order.
     *
     * The registry is the single enumeration; this is a view of it.
     *
     * @since 0.8.0
     * @return string[]
     */
    public static function all_types(): array {
        return array_keys(Registry::all());
    }

    /**
     * Get all settings from options.
     *
     * The kind lists are the ONLY rule shape. A pre-0.8.0 option still holding
     * the type-keyed arrays is not upgraded — it reads as no rules, and
     * holds_pre_08_rows() is what tells the author why.
     *
     * @return array Complete settings array
     */
    private function get_all_settings(): array {
        if ($this->cached_settings === null) {
            $stored = get_option(self::OPTION_NAME, []);
            $this->cached_settings = is_array($stored) ? $stored : [];
        }

        return $this->cached_settings;
    }

    /**
     * Does a stored option still hold pre-0.8.0 rule ROWS and no kind list?
     *
     * The migration off the type-keyed arrays was deleted after 0.9.x, so such
     * a site runs no rules; the admin notice this drives tells its author to
     * update through 0.9.x first. Empty legacy arrays are not rows — a 0.7.x
     * site with no rules has nothing to lose and must not be nagged.
     *
     * @param array $stored Raw stored option.
     * @return bool
     */
    public static function holds_pre_08_rows(array $stored): bool {
        if (isset($stored[self::KIND_TERM]) || isset($stored[self::KIND_FORMAT])) {
            return false;
        }

        foreach (self::all_types() as $type) {
            if (!empty($stored[$type]) && is_array($stored[$type])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the raw, cached settings option — including non-rule global keys
     * (e.g. conflict_handling_overrides) that the
     * rule-typed accessors don't expose. Served from the same request cache as
     * get_rules(), so callers needn't issue a second get_option().
     *
     * @return array Complete settings option.
     */
    public function get_raw_settings(): array {
        return $this->get_all_settings();
    }

    /**
     * Coerce the General tab's claim-override rows `[{taxonomy, mode}, ...]`
     * into the canonical `{taxonomy_slug: mode}` dict (ADR 0004).
     *
     * Same adapter boundary as normalize_rule_shape() below, for the same
     * reason: the rows are an artifact of the writer, not the meaning. They
     * exist only because Wireframe's Sanitizer skips dot-notation field ids
     * (CLAUDE.md don't #4), so the dict cannot be a field — this is the way
     * back. Public because the shape is a pure function of its input, so
     * callers needn't hold an instance; static for the same reason.
     *
     * Incomplete rows are dropped rather than landing an empty key — an
     * "Add taxonomy override" click with neither select touched is the usual
     * source. On a duplicate taxonomy the last row wins.
     *
     * **No runtime consumer yet.** Nothing reads the per-taxonomy default
     * when a rule omits its own claim; every handler still falls back to a
     * hard-coded `merge`. Wiring belongs with the Phase 4 dispatcher (#53),
     * where rule-level-vs-taxonomy-level precedence gets decided. Rehomed
     * here (#55) from the deleted `Settings` shell, whose get_settings() was
     * the only caller. Feed it `get_raw_settings()['conflict_handling_overrides']`.
     *
     * @since 0.8.0
     * @param array $rows Repeater rows from `conflict_handling_overrides`.
     * @return array<string,string> taxonomy slug ⇒ merge|replace|skip.
     */
    public static function flatten_conflict_overrides(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (empty($row['taxonomy']) || empty($row['mode'])) {
                continue;
            }
            $out[$row['taxonomy']] = $row['mode'];
        }
        return $out;
    }

    /**
     * Which kind list a rule type lives in.
     *
     * @since 0.8.0
     * @param string $type Storage type key.
     * @return string Kind key, or '' if the type is unknown.
     */
    public function get_kind_for_type(string $type): string {
        return Registry::get($type)?->kind() ?? '';
    }

    /**
     * Read a kind list, coerced to the canonical shape handlers consume.
     *
     * **The persisted `term_rules` / `format_rules` key IS the list** since
     * #66. It was derived at read time from the seven type-keyed arrays while
     * both shapes existed, and there were two read paths for exactly as long as
     * that lasted: a derived one that could not reproduce cross-type order, and
     * `get_authored_kind_rules()` reading the persisted key. The contract
     * ticket collapsed them — the type-keyed arrays are gone, so there is
     * nothing left for a derivation to reconcile against.
     *
     * The row order is the AUTHORED order, and authored order is the
     * composition semantics a pass executes in (ADR 0003 decision 3) — a
     * hierarchical rule sequenced after a level-restriction rule runs after it.
     * Never re-derive or re-sort this list: sorting it by type is precisely the
     * clobber the old derived path could not avoid.
     *
     * `id` is assigned PER TYPE, not per kind-list position — it stays the
     * index the rule has inside its own type, exactly as `get_rules()` reports
     * it, so it stays the number the type-facing mutators take. It carries the
     * same warning as `get_rules()`: positional, re-derived on read, never a
     * stable identity.
     *
     * Not memoized on purpose. It reads only what `get_all_settings()` already
     * cached, and a second request-scoped cache would need resetting in
     * lockstep with that one — a live trap for a cost that is not there.
     *
     * @since 0.8.0
     * @param string $kind    KIND_TERM or KIND_FORMAT.
     * @param array  $filters Same filters as get_rules(), plus 'type'.
     * @return array Rules in authored order.
     */
    public function get_kind_rules(string $kind, array $filters = []): array {
        if (Registry::of_kind($kind) === []) {
            return [];
        }

        $rows = $this->get_all_settings()[$kind] ?? [];

        return self::filter_rules(self::project_kind_rules($rows), $filters);
    }

    /**
     * Read-time projection of a kind list: assign per-type `id`, then apply the
     * canonical-shape coercion. Pure — the harness runs it without WordPress.
     *
     * @since 0.8.0
     * @param array $rows Kind-list rows, each carrying `type`.
     * @return array
     */
    public static function project_kind_rules(array $rows): array {
        $counters = [];
        $out      = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');

            // Per-type running index — the same number get_rules() derives from
            // the type array's own key.
            $index            = $counters[$type] ?? 0;
            $counters[$type]  = $index + 1;

            if (!isset($row['id'])) {
                $row['id'] = $index;
            }

            $out[] = self::normalize_rule_shape($type, $row);
        }

        return $out;
    }

    /**
     * Apply filters to rules array
     *
     * Static + pure so the harness can exercise it without WordPress. Renamed
     * from apply_filters() in 0.8.0 — it never was the WP hook of that name,
     * and the collision read as one.
     *
     * @param array $rules Rules array
     * @param array $filters Filters to apply
     * @return array Filtered rules
     */
    private static function filter_rules(array $rules, array $filters): array {
        if (empty($filters)) {
            return $rules;
        }

        $filtered = [];

        foreach ($rules as $index => $rule) {
            $matches = true;

            // Filter by enabled status
            if (isset($filters['enabled'])) {
                $rule_enabled = $rule['enabled'] ?? true;
                if ($rule_enabled !== $filters['enabled']) {
                    $matches = false;
                }
            }

            // Filter by rule type — the kind list is cross-type, so this is
            // what "one type's rules" means. (0.8.0)
            if (isset($filters['type']) && $matches) {
                if (($rule['type'] ?? '') !== $filters['type']) {
                    $matches = false;
                }
            }

            // Filter by taxonomy
            if (isset($filters['taxonomy']) && $matches) {
                $rule_taxonomy = $rule['taxonomy'] ?? '';
                if ($rule_taxonomy !== $filters['taxonomy']) {
                    $matches = false;
                }
            }

            // No post-type filter on purpose (#72): the gate is
            // should_process_post(), and a second copy here drifted from it.

            if ($matches) {
                // No id-default here: the caller projects first
                // (project_kind_rules), which assigns `id` before this runs. A
                // second copy would be dead code claiming to be a safety net.
                // (0.8.0)
                $filtered[] = $rule;
            }
        }

        // Apply limit and offset
        if (isset($filters['limit']) || isset($filters['offset'])) {
            $offset = $filters['offset'] ?? 0;
            $limit = $filters['limit'] ?? -1;

            if ($limit === -1) {
                $filtered = array_slice($filtered, $offset);
            } else {
                $filtered = array_slice($filtered, $offset, $limit);
            }
        }

        return $filtered;
    }

    /**
     * {@inheritDoc}
     *
     * One type's slice of its kind list, in authored order. Since #66 this is a
     * VIEW over the kind list rather than a read of a type-keyed array — the
     * kind list is the only stored shape, and a type filter over it is what
     * "this type's rules" now means.
     *
     * WARNING: `id` is the rule's per-type POSITION, re-derived on every read
     * and never persisted (save reindexes; the id key is stripped before write).
     * Rules are stored POSITIONALLY, so reordering or deleting one renumbers the
     * rest. NEVER key persistent per-rule state (tracking meta, caches) on this
     * id — use a stable identity (e.g. post id + field name). The ACF-reference
     * handler is declarative and stores nothing keyed on rules, so it is
     * unaffected.
     *
     * Rows carry their own `type` now, which the type-keyed read could not
     * report. Nothing depends on its absence.
     */
    public function get_rules(string $type, array $filters = []): array {
        $kind = $this->get_kind_for_type($type);

        if ($kind === '') {
            return [];
        }

        return $this->get_kind_rules($kind, array_merge($filters, ['type' => $type]));
    }

    /**
     * Coerce stored rule data into the canonical shape handlers consume.
     *
     * Storage is the adapter boundary between writers (current UI: WP
     * Wireframe REST; future writers: CLI, import) and handlers. Each
     * writer may serialize differently; handlers should see a single
     * canonical shape and not care which writer produced the row.
     *
     * Current coercions — GUARANTEED, so no consumer re-decodes or re-casts
     * (FW-29). A field absent from the row stays absent; read it `?? []`
     * (`?? 0` for `target_term_id`).
     *   - Checkbox gates `post_types`, `post_status`, `filter_taxonomies` →
     *     string[] slug list, empty = all.
     *   - `target_term_id` → int. The FormTokenField stores [N]; that is a FORM
     *     shape, never a runtime one.
     *   - `trigger_term_id`, `filter_terms` → int[], deduped, zeros dropped.
     *   - Per-type fields → the type descriptor's `normalize()` (today only
     *     `RelatedPostTermsRules`, which splits the combined ACF field values)
     *
     * Read-only: nothing writes the projection back, so widening it needs no
     * migration. Admin code holding raw form values runs them through
     * `project_kind_rules()` rather than decoding by hand.
     */
    private static function normalize_rule_shape(string $type, array $rule): array {
        foreach (['post_types', 'post_status', 'filter_taxonomies'] as $field) {
            if (isset($rule[$field])) {
                $rule[$field] = self::selected_checkbox_slugs($rule[$field]);
            }
        }

        if (isset($rule['target_term_id'])) {
            $raw                    = $rule['target_term_id'];
            $rule['target_term_id'] = (int) (is_array($raw) ? (reset($raw) ?: 0) : $raw);
        }

        // Stored value may be a FormTokenField array [a,b,...] or a legacy scalar.
        foreach (['trigger_term_id', 'filter_terms'] as $field) {
            if (isset($rule[$field])) {
                $raw          = $rule[$field];
                $rule[$field] = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]))));
            }
        }

        // Per-type branch: the descriptor's own normalize(). An unknown type
        // passes through with only the shared projection.
        return Registry::get($type)?->normalize($rule) ?? $rule;
    }

    /**
     * Normalize a Wireframe checkboxes value to a flat list of selected slugs.
     *
     * Wireframe stores a flat slug list; legacy and hand-seeded rows carry a
     * `{slug: bool}` map. Anything else (a scalar, empty) reads as nothing
     * selected.
     *
     * @param mixed $value Checkbox map, list of slugs, or empty.
     * @return string[] Selected slugs (truthy keys), or [] when nothing selected.
     */
    private static function selected_checkbox_slugs($value): array {
        if (empty($value) || !is_array($value)) {
            return [];
        }
        return array_is_list($value)
            ? array_values($value)
            : array_keys(array_filter($value));
    }

    /**
     * Split a stored ACF relationship-field option value into its three parts.
     *
     * The select's option KEY is the stored value, so the value has to carry
     * everything the handler needs and round-trip whole (don't #6). Shape,
     * since #25: `post_type:field_name:field_key`.
     *
     *   - `post_type` cannot be re-derived from the key — an ACF field group
     *     may be located on several post types, which is why the options list
     *     emits one row per post type per field in the first place.
     *   - `field_name` stays because the READ path is `get_field($name, $id)`,
     *     which is post-scoped and therefore never ambiguous, and because it
     *     is the fallback when a key no longer resolves (deleted field, a row
     *     imported from another site).
     *   - `field_key` is the only unambiguous identity: two separately-created
     *     fields can share a bare name on the SAME post type, in which case
     *     `acf_get_field($name)` returns one arbitrary match. (#25)
     *
     * Legacy two-part (`post_type:field_name`) and bare-name values parse to an
     * empty key, which every caller treats as "fall back to the name" — exactly
     * today's behavior, never worse.
     *
     * @since 0.9.0
     * @param string $value Raw stored option value.
     * @return array{0: ?string, 1: string, 2: string} [post_type|null, name, key]
     */
    public static function split_acf_field_value(string $value): array {
        $parts = explode(':', $value, 3);

        if (count($parts) === 1) {
            return [null, $parts[0], ''];
        }

        return [$parts[0], $parts[1], $parts[2] ?? ''];
    }

    /**
     * {@inheritDoc}
     */
    public function get_rule(string $type, int $rule_id): ?array {
        if ($rule_id < 0) {
            return null;
        }

        return $this->get_rules($type)[$rule_id] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function clear_cache(?string $type = null): void {
        $this->cached_settings = null;

        // Clear WordPress object cache
        wp_cache_delete(self::OPTION_NAME, 'options');
    }
}
