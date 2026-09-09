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
     * Effect kind ⇒ the rule types it holds, IN ORDER.
     *
     * Two jobs since #66. It is the ENUMERATION of every rule type storage
     * knows (`all_types()` flattens it, `get_kind_for_type()` inverts it) — the
     * seven-entry `$valid_types` list that used to be a second copy is gone.
     * And it is still the seed order for the pre-#56 migration: the position
     * each type's rules take inside its kind list when `fan_in()` builds one,
     * where position IS order (ADR 0003 — no stable `_id`). That order
     * reproduces the old Auto-Set tab order, so a rule set authored before the
     * migration keeps the sequence its author was looking at. Do not reorder
     * this without a migration.
     *
     * The `type` value written onto each row is the legacy type key verbatim
     * (`hierarchical_rules`, not `hierarchical`). Rule-type RENAMING stays
     * deferred per ADR 0002/0003, and reusing the existing key means
     * `get_enabled_rules()` can filter on `get_rule_type()` with no mapping
     * table between the two vocabularies. Author-facing labels are a config
     * concern (#57), not a storage one.
     *
     * @since 0.8.0
     * @var array<string,string[]>
     */
    private const KIND_TYPES = [
        self::KIND_TERM => [
            'propagation_rules',
            'related_post_terms_rules',
            'time_based_rules',
            'related_rules',
            'hierarchical_rules',
            'hierarchical_level_restriction_rules',
        ],
        self::KIND_FORMAT => [
            'title_slug_rules',
        ],
    ];

    /**
     * Rule types whose AUTHORING SURFACE has collapsed into the ordered
     * kind-list repeater (#57, §2 batch 1).
     *
     * This is the one fact that tells storage which persisted kind list is
     * *authored* rather than *derived*, and it exists here — not on the
     * config class — because the config classes read it to build their
     * repeaters, and storage runs on CLI and front-end paths that must never
     * resolve `Admin\Config` (CLAUDE.md don't #4).
     *
     * **Every rule type is in as of #59.** Batch 1 (#57) took the four term
     * types not live on a real site, the two live ones (`related_rules`,
     * `related_post_terms_rules`) followed in #58, and `title_slug_rules`
     * joined the format repeater in #59. A row of a type absent here must NOT
     * appear in a persisted kind list, because the repeater renders every row
     * in the key it is bound to and Wireframe DROPS any subfield the config
     * does not declare (`RepeaterField::sanitize`) — rendering a rule the
     * repeater has no subfields for would silently gut it on the next save.
     *
     * Add a type here in the same change that gives it repeater subfields,
     * never before. The list is now identical to the flattened KIND_TYPES,
     * and it stays a separate constant precisely so the NEXT type
     * (`field_transformation`) can be declared in KIND_TYPES — and therefore
     * fanned in and read — a change before its subfields exist.
     *
     * @since 0.8.0
     * @var string[]
     */
    private const CONFIG_MIGRATED_TYPES = [
        'propagation_rules',
        'time_based_rules',
        'hierarchical_rules',
        'hierarchical_level_restriction_rules',
        'related_rules',
        'related_post_terms_rules',
        'title_slug_rules',
    ];

    /**
     * The migrated types belonging to one kind, in KIND_TYPES order.
     *
     * Empty ⇒ no repeater is bound to that kind's list yet, so the config
     * classes render no rows for it.
     *
     * @since 0.8.0
     * @param string $kind KIND_TERM or KIND_FORMAT.
     * @return string[]
     */
    public static function migrated_types_for_kind(string $kind): array {
        return array_values(array_intersect(
            self::KIND_TYPES[$kind] ?? [],
            self::CONFIG_MIGRATED_TYPES
        ));
    }

    /**
     * Every rule type storage knows, flattened out of KIND_TYPES in kind order.
     *
     * The single enumeration since #66 — the seven-entry `$valid_types` list it
     * replaces was a second copy that could drift out of step with the kind map
     * and read zero rules for a type that fell out of one of them.
     *
     * @since 0.8.0
     * @return string[]
     */
    public static function all_types(): array {
        return array_merge(...array_values(self::KIND_TYPES));
    }

    /**
     * Get all settings from options, in the kind-list shape.
     *
     * The kind lists are the ONLY rule shape as of #66. Anything still holding
     * the pre-#56 type-keyed arrays is upgraded here, on read, so a front-end
     * or cron request on a site whose admin has never been loaded still sees
     * its rules (see upgrade_legacy_shape()).
     *
     * @return array Complete settings array
     */
    private function get_all_settings(): array {
        if ($this->cached_settings === null) {
            $stored = get_option(self::OPTION_NAME, []);
            $this->cached_settings = self::upgrade_legacy_shape(
                is_array($stored) ? $stored : []
            );
        }

        return $this->cached_settings;
    }

    /**
     * The one-time pre-#56 upgrade, applied at READ time so it cannot be missed.
     *
     * A site that last ran a version before the expand ticket (#56) stores seven
     * type-keyed rule arrays and no kind list. The contract ticket (#66) deleted
     * every reader of those arrays, so without this such a site would read zero
     * rules — silently, on every request, until an admin load. Running it on
     * read rather than only on admin load is the same reasoning #56 used for its
     * read-time adapter: handlers read storage on front-end and cron requests
     * that never reach `WireframeBootstrap::boot()`.
     *
     * A kind list that is ALREADY present wins. That is the whole safety
     * argument: from #56 onwards both shapes exist side by side, the kind list
     * is the authored one, and re-deriving it from the type-keyed arrays would
     * discard the author's cross-type order (the fan-in groups by type). Only a
     * kind key that is absent or unusable is seeded from the legacy arrays.
     *
     * The legacy keys are then dropped from the working copy, so the next write
     * this class performs prunes them from storage. Nothing is persisted here —
     * a read that migrates must not write, or a front-end request would rewrite
     * the option out from under an admin editing it.
     *
     * **What makes the drop safe is that `$legacy` is computed BEFORE the loop.**
     * The seed and the `unset()` happen in the same iteration, so a seed that
     * read `$settings` directly would be reading an array the previous iteration
     * had already pruned. It reads the pre-prune fan-in instead. Don't move the
     * `fan_in()` call inside the loop.
     *
     * @since 0.8.0
     * @param array $settings Raw stored option.
     * @return array
     */
    private static function upgrade_legacy_shape(array $settings): array {
        $legacy = self::fan_in($settings);

        foreach (self::KIND_TYPES as $kind => $types) {
            if (!isset($settings[$kind]) || !is_array($settings[$kind])) {
                $settings[$kind] = $legacy[$kind];
            }

            foreach ($types as $type) {
                unset($settings[$type]);
            }
        }

        return $settings;
    }

    /**
     * Read the raw, cached settings option — including non-rule global keys
     * (e.g. conflict_handling_overrides, manual_processing_enabled) that the
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
     * Regroup the seven type-keyed rule arrays into the two kind-keyed ordered
     * lists (ADR 0003 decision 1).
     *
     * **Migration code since #66.** It was the read path for the expand half of
     * expand-then-contract; with the type-keyed arrays gone its only remaining
     * job is seeding a kind list that does not exist yet, from a site that last
     * ran a pre-#56 version (see upgrade_legacy_shape()). It stays public, and
     * `fan_out()` stays with it, because "the migration is lossless" is a claim
     * H10 has to be able to check.
     *
     * A pure regroup, deliberately: each row is carried across VERBATIM plus a
     * `type` key naming the array it came from. It applies no shape coercion —
     * not `normalize_rule_shape()`, not `migrate_related_post_terms_shape()`.
     * Two reasons. (1) Losslessness: `fan_out(fan_in($s))` must reproduce the
     * type-keyed arrays byte-for-byte, which is what makes persisting the lists
     * safe on live data. (2) The persisted lists are what the Wireframe admin
     * will read raw once the config collapses (#57), and the admin needs the
     * STORED shape — e.g. `acf_field_name` must stay the combined
     * "post_type:field" the select's option keys use. Coercion stays at read
     * time, in `project_kind_rules()`, exactly where it already is for the
     * type-keyed path.
     *
     * Rows that are not arrays cannot carry a `type` and are dropped; nothing
     * the plugin writes produces one.
     *
     * @since 0.8.0
     * @param array $settings Raw settings option.
     * @return array<string,array[]> Both kind keys, always present.
     */
    public static function fan_in(array $settings): array {
        $lists = [];

        foreach (self::KIND_TYPES as $kind => $types) {
            $rows = [];
            foreach ($types as $type) {
                $source = $settings[$type] ?? [];
                if (!is_array($source)) {
                    continue;
                }
                foreach ($source as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    // Authoritative: the owning array names the type, so a
                    // stale `type` on the row (a round-tripped kind row saved
                    // back through save_rule) is corrected rather than trusted.
                    $row['type'] = $type;
                    $rows[]      = $row;
                }
            }
            $lists[$kind] = $rows;
        }

        return $lists;
    }

    /**
     * Inverse of fan_in(): split kind lists back into type-keyed arrays.
     *
     * Exists to PROVE fan_in is lossless — Phase 4 Gate 1 asks for exactly
     * that, and "lossless" is only checkable against an inverse. Its caller is
     * H10, deliberately: it is a proof obligation, not a rollback path (#56
     * asks for no reversal, and the migration only ADDS keys, so there is
     * nothing to roll back).
     *
     * Rows are stripped of `type` (it is the grouping, not rule data) and
     * reindexed, so a round-trip reproduces the original arrays.
     *
     * All seven type keys are always present, empty where the kind lists hold
     * no rows of that type.
     *
     * @since 0.8.0
     * @param array $lists Kind-keyed lists as produced by fan_in().
     * @return array<string,array[]> Type-keyed rule arrays.
     */
    public static function fan_out(array $lists): array {
        $out = [];

        foreach (self::KIND_TYPES as $kind => $types) {
            $out = array_merge($out, array_fill_keys($types, []));

            foreach ($lists[$kind] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $type = (string) ($row['type'] ?? '');
                if (!in_array($type, $types, true)) {
                    continue;
                }
                unset($row['type']);
                $out[$type][] = $row;
            }
        }

        return $out;
    }

    /**
     * Which kind list a rule type lives in.
     *
     * Every type storage knows is covered — the kind map IS the enumeration
     * since #66 (`all_types()` flattens it), so there is no second list for it
     * to drift out of step with.
     *
     * @since 0.8.0
     * @param string $type Legacy rule type key.
     * @return string Kind key, or '' if the type is unknown.
     */
    public function get_kind_for_type(string $type): string {
        foreach (self::KIND_TYPES as $kind => $types) {
            if (in_array($type, $types, true)) {
                return $kind;
            }
        }

        return '';
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
     * it. Handlers persist state keyed on it (`TitleSlugHandler`'s
     * `write_rule_status()`), so re-basing it onto the kind list would silently
     * repoint every stored per-rule status. It carries the same warning as
     * `get_rules()`: positional, re-derived on read, never a stable identity.
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
        if (!isset(self::KIND_TYPES[$kind])) {
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
     * Save settings to options.
     *
     * Returns TRUE when the option round-trips — the write succeeded, OR the
     * bytes already equalled what was stored so no write was needed. FALSE only
     * when a re-read shows the data did not persist (#27). `update_option()`
     * cannot be believed on its own: it returns false for both of those, and
     * every mutator on this class treats false as failure.
     *
     * The request cache is refreshed to match what is ACTUALLY stored, for the
     * same reason. Adopting $settings after a genuine DB failure would have a
     * later save in the same request (import_rules looping save_rule) read the
     * poisoned cache and persist the failed data as its baseline.
     * (PR#24 round 5 #5 + round 6 #4)
     *
     * @param array $settings Complete settings array
     * @return bool True when storage holds $settings afterwards.
     */
    private function save_all_settings(array $settings): bool {
        if (update_option(self::OPTION_NAME, $settings)) {
            $this->cached_settings = $settings;

            return true;
        }

        // false ⇒ a no-op on equality, or a genuine failure. Re-read to tell
        // them apart, bypassing the request cache we may have primed earlier.
        $stored = get_option(self::OPTION_NAME, []);

        if (is_array($stored) && $stored === $settings) {
            $this->cached_settings = $settings;

            return true;
        }

        $this->cached_settings = null;

        return false;
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

            // Filter by post type
            if (isset($filters['post_type']) && $matches) {
                $post_types = $rule['post_types'] ?? [];
                if (!in_array($filters['post_type'], (array)$post_types, true)) {
                    $matches = false;
                }
            }

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
     * Current coercions:
     *   - Single-value term IDs: [N] array (from FormTokenField max=1) → int N
     *   - ACF relationship field: "post_type:field_name" prefix → split into
     *     scalar post_type + bare acf_field_name
     */
    private static function normalize_rule_shape(string $type, array $rule): array {
        $single_term_fields = [
            'related_rules'    => ['target_term_id'],
            'time_based_rules' => ['target_term_id'],
        ];

        if (isset($single_term_fields[$type])) {
            foreach ($single_term_fields[$type] as $field) {
                if (!isset($rule[$field])) {
                    continue;
                }
                if (is_array($rule[$field])) {
                    $first        = reset($rule[$field]);
                    $rule[$field] = $first === false ? 0 : (int) $first;
                } else {
                    $rule[$field] = (int) $rule[$field];
                }
            }
        }

        // related_rules trigger_term_id: canonical shape is int[] (V1).
        // Stored value may be a FormTokenField array [a,b,...] or a legacy scalar.
        // Dedupe, cast, and drop zeros.
        if ($type === 'related_rules' && isset($rule['trigger_term_id'])) {
            $raw = $rule['trigger_term_id'];
            $ids = is_array($raw) ? $raw : [$raw];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $rule['trigger_term_id'] = $ids;
        }

        if ($type === 'related_post_terms_rules') {
            if (!empty($rule['acf_field_name'])) {
                $raw = (string) $rule['acf_field_name'];
                if (str_contains($raw, ':')) {
                    [$pt, $bare]            = explode(':', $raw, 2);
                    $rule['post_type']      = $pt;
                    $rule['acf_field_name'] = $bare;
                } elseif (!isset($rule['post_type'])) {
                    $rule['post_type'] = '';
                }
            }

            // Reverse field is stored in the same "post_type:field_name" option
            // format; the handler wants the bare field name. (SPEC §V6)
            if (!empty($rule['reverse_acf_field_name'])) {
                $rraw = (string) $rule['reverse_acf_field_name'];
                if (str_contains($rraw, ':')) {
                    [, $rbare]                      = explode(':', $rraw, 2);
                    $rule['reverse_acf_field_name'] = $rbare;
                }
            }

            $rule = self::migrate_related_post_terms_shape($rule);
        }

        return $rule;
    }

    /**
     * Live-data-safe old→new shape migration for related_post_terms rules.
     *
     * Read-time only (no stored rewrite) — applies on every get_rules/get_rule
     * so legacy rows behave identically until re-saved in the new UI. (SPEC §V8)
     *
     *   source_taxonomy(+target fallback) → single `taxonomy` (prefer source;
     *     cross-tax never worked — term-ID copy rejects foreign-tax IDs)
     *   bidirectional (bool)              → keep_in_sync (bool)
     *   conflict_handling                 → DROPPED (merge→off, replace→on,
     *                                       skip→off + _migration_flag)
     *   holder_role absent                → 'target' (= legacy pull-to-holder)
     *   post_status absent                → untouched (= any)
     *
     * Public since #58: `WireframeBootstrap::repair_stored_rules()` applies it
     * to the kind-list rows too, closing the gap the one-shot flag leaves — a
     * legacy-shaped row written AFTER the flag is set (CLI, import) would
     * otherwise render with config defaults in the unified repeater and be
     * persisted that way, e.g. an absent `holder_role` rendering as the radio
     * default `source` and silently reversing a live rule's direction on the
     * next save. Deliberately does NOT split acf_field_name (see below), so
     * it is safe on admin-facing shapes.
     *
     * @param array $rule Normalized-so-far rule (post_type/acf split already done).
     * @return array
     */
    public static function migrate_related_post_terms_shape(array $rule): array {
        // Taxonomy collapse.
        if (!isset($rule['taxonomy']) || $rule['taxonomy'] === '') {
            $rule['taxonomy'] = $rule['source_taxonomy'] ?? $rule['target_taxonomy'] ?? '';
        }
        unset($rule['source_taxonomy'], $rule['target_taxonomy']);

        // bidirectional → keep_in_sync.
        if (!isset($rule['keep_in_sync']) && isset($rule['bidirectional'])) {
            $rule['keep_in_sync'] = !empty($rule['bidirectional']);
        }
        unset($rule['bidirectional']);

        // conflict_handling → keep_in_sync axis, then drop.
        if (isset($rule['conflict_handling'])) {
            if (!isset($rule['keep_in_sync'])) {
                $rule['keep_in_sync'] = ($rule['conflict_handling'] === 'replace');
            }
            if ($rule['conflict_handling'] === 'skip') {
                // No clean equivalent; flag for manual review (rare).
                $rule['_migration_flag'] = 'conflict_handling=skip dropped';
            }
            unset($rule['conflict_handling']);
        }

        // Defaults for absent keys.
        if (!isset($rule['keep_in_sync'])) {
            $rule['keep_in_sync'] = false;
        }
        if (!isset($rule['holder_role']) || $rule['holder_role'] === '') {
            // Legacy behavior was pull-to-holder.
            $rule['holder_role'] = 'target';
        }

        return $rule;
    }

    /**
     * Schema-version flag for the one-time related_post_terms_rules rewrite.
     *
     * Bumped whenever a future key-RENAMING migration is added that the admin
     * (raw get_option) can't see at read time. Re-running the rewrite is
     * idempotent, so the gate is purely to avoid a write on every admin load.
     */
    const ACFREF_SCHEMA_VERSION = 1;
    const ACFREF_SCHEMA_FLAG    = 'bws_mc_acfref_schema';

    /**
     * One-time, flag-gated persistence of the related_post_terms_rules
     * read-time migration. (SPEC §V16, B6, T18)
     *
     * The Wireframe admin reads the settings option RAW (get_option, no filter
     * seam), bypassing normalize_rule_shape — so the read-time key-RENAME
     * migration (source_taxonomy→taxonomy, bidirectional→keep_in_sync,
     * conflict_handling drop, holder_role default) never reaches the form. A
     * legacy row then renders with config DEFAULTS (push, empty taxonomy) and a
     * resave PERSISTS that corruption. This rewrites the legacy rows in storage
     * once so the admin reads already-migrated data.
     *
     * Applies ONLY the key-rename migration (migrate_related_post_terms_shape).
     * Deliberately does NOT split acf_field_name "post:field" → that split is a
     * SAFE directional adapter: the admin stores+round-trips the COMBINED value,
     * the handler splits at read time. Persisting the split would break the
     * admin select (its option keys are "post:field"). (SPEC §V16)
     *
     * Idempotent: a row already in the new shape is unchanged. Runs once per
     * schema version via the option flag.
     *
     * @return bool True if a rewrite was performed.
     */
    public function maybe_migrate_acf_ref_storage(): bool {
        if ((int) get_option(self::ACFREF_SCHEMA_FLAG, 0) >= self::ACFREF_SCHEMA_VERSION) {
            return false;
        }

        // Through the request cache, so a pre-#56 site is upgraded to the kind
        // shape first and the rewrite lands on the rows the repeater will read.
        $settings = $this->get_all_settings();
        $rows     = $settings[self::KIND_TERM] ?? [];
        $changed  = false;

        foreach ($rows as $i => $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'related_post_terms_rules') {
                continue;
            }
            $migrated = self::migrate_related_post_terms_shape($row);
            if ($migrated !== $row) {
                $rows[$i] = $migrated;
                $changed  = true;
            }
        }

        if (!$changed) {
            // Nothing to migrate (fresh install / already-new data): no write
            // was needed, so flag unconditionally to skip future scans.
            update_option(self::ACFREF_SCHEMA_FLAG, self::ACFREF_SCHEMA_VERSION);

            return false;
        }

        $settings[self::KIND_TERM] = $rows;

        // Flag iff the migrated rows are ACTUALLY in storage now. save_all_settings()
        // reports true for a successful write AND for a no-op on equality (a
        // concurrent writer got there first) — both mean the rows are stored, so
        // both should flag. A genuine DB failure leaves the flag unset so the next
        // load retries rather than permanently skipping and later corrupting on a
        // raw resave (PR#24 round 2 #3 / round 4 #3), and reports false so a caller
        // doesn't log "migration done" (round 8 #3).
        if (!$this->save_all_settings($settings)) {
            return false;
        }

        update_option(self::ACFREF_SCHEMA_FLAG, self::ACFREF_SCHEMA_VERSION);

        return true;
    }

    /**
     * Schema marker recording that the kind-list shape has been persisted.
     *
     * Bumped only if the stored shape changes again (a kind added, the
     * documented order changed). It is a MARKER, not a gate — see
     * maybe_migrate_kind_lists() for why gating on it would be wrong.
     *
     * @since 0.8.0
     */
    const KIND_SCHEMA_VERSION = 1;
    const KIND_SCHEMA_FLAG    = 'bws_mc_kind_schema';

    /**
     * Persist the kind-list shape (#56 → #66, ADR 0003). Runs on admin load,
     * AFTER maybe_migrate_acf_ref_storage() so the persisted lists carry
     * already-key-renamed `related_post_terms` rows rather than freezing a
     * legacy shape into the new one.
     *
     * All it does now is write back what `get_all_settings()` already read:
     * the kind lists seeded from any pre-#56 type-keyed arrays, and those
     * arrays pruned. Reads apply that upgrade themselves
     * (`upgrade_legacy_shape()`), so nothing depends on this having run —
     * except the ADMIN, which reads the settings option RAW and can therefore
     * only bind the ordered repeater to `term_rules` / `format_rules` if those
     * keys exist in storage. That is the whole reason it persists.
     *
     * **Deliberately NOT flag-gated, though #56 asked for "flag-gated".** The
     * flag was asked for to avoid a write on every admin load; writing only
     * when the upgraded settings differ from the stored ones achieves that and
     * is strictly better, because it also covers a site that is upgraded, rolled
     * back and upgraded again. Idempotent: once the option is in the kind shape
     * the comparison matches and no write happens.
     *
     * It is NOT a reconciliation. Before #66 this method had to decide whether
     * a stored kind list could still be trusted against the type-keyed arrays,
     * and rebuild it in KIND_TYPES order when it could not — which silently
     * reordered the author's list, and since #60 that meant changing what a pass
     * DOES. With one stored shape there is nothing to reconcile: the list is
     * authoritative, full stop.
     *
     * @since 0.8.0
     * @return bool True if a rewrite was performed AND persisted.
     */
    public function maybe_migrate_kind_lists(): bool {
        // Through the request cache: this is exactly the upgraded shape every
        // read in this request is already serving.
        $settings = $this->get_all_settings();
        $stored   = get_option(self::OPTION_NAME, []);

        if (is_array($stored) && $stored === $settings) {
            // Already in the stored shape (a migrated site, or a fresh install
            // whose first save wrote it). Record the schema version.
            update_option(self::KIND_SCHEMA_FLAG, self::KIND_SCHEMA_VERSION);

            return false;
        }

        // save_all_settings() reports true only when storage actually holds
        // $settings afterwards, so a genuine DB failure leaves the marker unset
        // and the next load retries. The marker gates nothing, so a missed mark
        // costs only its own accuracy.
        if (!$this->save_all_settings($settings)) {
            return false;
        }

        update_option(self::KIND_SCHEMA_FLAG, self::KIND_SCHEMA_VERSION);

        return true;
    }

    /**
     * The kind-list POSITION of one type's Nth rule, or null if it has no Nth.
     *
     * The bridge every mutator crosses: `$rule_id` is the per-type index the
     * read paths report (see get_rules()), the stored list is cross-type, and
     * these two numbers only coincide for a single-type kind.
     *
     * @since 0.8.0
     * @param array  $rows    One kind list.
     * @param string $type    Rule type.
     * @param int    $rule_id Per-type index.
     * @return int|null
     */
    private static function position_of(array $rows, string $type, int $rule_id): ?int {
        $index = 0;

        foreach ($rows as $position => $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== $type) {
                continue;
            }
            if ($index === $rule_id) {
                return (int) $position;
            }
            $index++;
        }

        return null;
    }

    /**
     * How many rules of one type a kind list holds.
     *
     * @since 0.8.0
     * @param array  $rows One kind list.
     * @param string $type Rule type.
     * @return int
     */
    private static function count_of(array $rows, string $type): int {
        return count(array_filter(
            $rows,
            static fn($row): bool => is_array($row) && ($row['type'] ?? '') === $type
        ));
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
     *
     * A new rule is APPENDED to the end of its kind list, not slotted in beside
     * the other rules of its type. Position is order and order is composition
     * (ADR 0003 decision 3), so a rule the author has not placed belongs last —
     * where it cannot change what the existing list already does.
     */
    public function save_rule(string $type, int $rule_id, array $data): int {
        $kind = $this->get_kind_for_type($type);

        if ($kind === '') {
            return -1;
        }

        // Guard: -1 is the only valid "create new" sentinel. Any other negative
        // id means a caller round-tripped a failure return (-1 collides with
        // the create sentinel only by value, not intent) or passed garbage.
        // Fail loud rather than silently create or corrupt an index.
        if ($rule_id < -1) {
            return -1;
        }

        $all_settings = $this->get_all_settings();
        $rows         = $all_settings[$kind] ?? [];

        // `id` is the read-time position, never stored; `type` is the row's
        // place in the list, so it is written from the argument rather than
        // trusted off the payload.
        unset($data['id']);
        $data['type'] = $type;

        if ($rule_id === -1) {
            // Count BEFORE the append: the number of rows of this type already
            // present is the new rule's per-type index, which is the number
            // get_rules() will report for it. Counting after and subtracting one
            // says the same thing twice, in opposite directions.
            $new_id = self::count_of($rows, $type);
            $rows[] = $data;
        } else {
            $position = self::position_of($rows, $type, $rule_id);

            if ($position === null) {
                return -1;
            }

            $rows[$position] = $data;
            $new_id          = $rule_id;
        }

        $all_settings[$kind] = array_values($rows);

        return $this->save_all_settings($all_settings) ? $new_id : -1;
    }

    /**
     * {@inheritDoc}
     */
    public function delete_rule(string $type, int $rule_id): bool {
        $kind = $this->get_kind_for_type($type);

        if ($kind === '' || $rule_id < 0) {
            return false;
        }

        $all_settings = $this->get_all_settings();
        $rows         = $all_settings[$kind] ?? [];
        $position     = self::position_of($rows, $type, $rule_id);

        if ($position === null) {
            return false;
        }

        // Remove rule and re-index — the remaining rules keep their order, and
        // every id after this one shifts down, exactly as before.
        unset($rows[$position]);
        $all_settings[$kind] = array_values($rows);

        return $this->save_all_settings($all_settings);
    }

    /**
     * {@inheritDoc}
     */
    public function search_rules(string $query, array $filters = []): array {
        $query_lower = strtolower($query);
        $results = [];

        foreach (array_keys(self::KIND_TYPES) as $kind) {
            foreach ($this->get_kind_rules($kind, $filters) as $rule) {
                $searchable = [
                    $rule['name'] ?? '',
                    $rule['taxonomy'] ?? '',
                    implode(' ', (array) ($rule['post_types'] ?? [])),
                ];

                $searchable_text = strtolower(implode(' ', $searchable));

                if (strpos($searchable_text, $query_lower) !== false) {
                    // `type` is already on the row — it is the grouping key of
                    // the list this came out of, not something added here.
                    $results[] = $rule;
                }
            }
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function get_rule_types(): array {
        return self::all_types();
    }

    /**
     * {@inheritDoc}
     */
    public function count_rules(string $type, array $filters = []): int {
        return count($this->get_rules($type, $filters));
    }

    /**
     * {@inheritDoc}
     */
    public function rule_exists(string $type, int $rule_id): bool {
        return $this->get_rule($type, $rule_id) !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function duplicate_rule(string $type, int $rule_id, array $overrides = []): int {
        $original = $this->get_rule($type, $rule_id);

        if (!$original) {
            return 0;
        }

        // Remove ID
        unset($original['id']);

        // Apply overrides
        $duplicate = array_merge($original, $overrides);

        // Add " (Copy)" to name if not overridden
        if (!isset($overrides['name'])) {
            $duplicate['name'] = ($original['name'] ?? 'Rule') . ' (Copy)';
        }

        return $this->save_rule($type, -1, $duplicate);
    }

    /**
     * {@inheritDoc}
     */
    public function bulk_toggle_rules(string $type, array $rule_ids, bool $enabled): int {
        $kind = $this->get_kind_for_type($type);

        if ($kind === '') {
            return 0;
        }

        $all_settings  = $this->get_all_settings();
        $rows          = $all_settings[$kind] ?? [];
        $updated_count = 0;

        foreach ($rule_ids as $rule_id) {
            $position = self::position_of($rows, $type, (int) $rule_id);

            if ($position === null) {
                continue;
            }

            // Only count + mutate rules whose state ACTUALLY changes. Counting
            // no-ops would (a) overstate the toggle count, and (b) when EVERY
            // target is already in the requested state, leave $settings ===
            // stored ⇒ nothing to write ⇒ the failure guard below would report
            // 0 for a set of rules that are all correct. (PR#24 round 8 #1)
            if ((bool) ($rows[$position]['enabled'] ?? false) !== $enabled) {
                $rows[$position]['enabled'] = $enabled;
                $updated_count++;
            }
        }

        if ($updated_count === 0) {
            return 0;
        }

        $all_settings[$kind] = $rows;

        // Report 0 if the write didn't persist, so callers don't show success
        // on a genuine DB failure (save_rule/delete_rule already propagate the
        // save result; bulk_toggle must too). (PR#24 round 6 #3, round 8 #1)
        return $this->save_all_settings($all_settings) ? $updated_count : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function export_rules(array $filters = []): array {
        $export = [
            'version' => defined('META_CONDUCTOR_VERSION') ? META_CONDUCTOR_VERSION : '0.3.0',
            'storage_type' => 'options',
            'exported_at' => current_time('mysql'),
            'rules' => [],
        ];

        // Export specific types or all types
        $types_to_export = $filters['types'] ?? self::all_types();

        foreach ($types_to_export as $type) {
            if ($this->get_kind_for_type($type) === '') {
                continue;
            }

            $rules = $this->get_rules($type, $filters);

            if (!empty($rules)) {
                $export['rules'][$type] = $rules;
            }
        }

        return $export;
    }

    /**
     * {@inheritDoc}
     */
    public function import_rules(array $rules_data, array $options = []): array {
        $results = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $overwrite = $options['overwrite'] ?? false;
        $skip_duplicates = $options['skip_duplicates'] ?? true;
        $prefix_names = $options['prefix_names'] ?? '';

        foreach ($rules_data as $type => $rules) {
            if ($this->get_kind_for_type((string) $type) === '') {
                $results['errors'][] = "Invalid rule type: {$type}";
                continue;
            }

            foreach ($rules as $rule) {
                // Add prefix if specified
                if ($prefix_names && isset($rule['name'])) {
                    $rule['name'] = $prefix_names . $rule['name'];
                }

                // Check for duplicates by exact name (search_rules uses a
                // fuzzy substring match — "Foo" would falsely match "Food").
                if ($skip_duplicates) {
                    $import_name = $rule['name'] ?? '';
                    $is_duplicate = false;
                    foreach ($this->get_rules($type) as $existing_rule) {
                        if (($existing_rule['name'] ?? '') === $import_name) {
                            $is_duplicate = true;
                            break;
                        }
                    }
                    if ($is_duplicate) {
                        $results['skipped']++;
                        continue;
                    }
                }

                // Import the rule
                $new_id = $this->save_rule($type, -1, $rule);

                if ($new_id >= 0) {
                    $results['imported']++;
                } else {
                    $results['errors'][] = "Failed to import rule: " . ($rule['name'] ?? 'Unnamed');
                }
            }
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function clear_cache(?string $type = null): void {
        $this->cached_settings = null;

        // Clear WordPress object cache
        wp_cache_delete(self::OPTION_NAME, 'options');
    }

    /**
     * {@inheritDoc}
     */
    public function get_storage_type(): string {
        return 'options';
    }

    /**
     * {@inheritDoc}
     */
    public function validate_rule(string $type, array $data): array {
        // An unknown type names no kind list, so there is nowhere for the rule
        // to be saved and nothing to validate it against. This replaces the
        // `$valid_types` membership test the mutators used to share (#66).
        if ($this->get_kind_for_type($type) === '') {
            return [
                'valid'  => false,
                'errors' => ['Unknown rule type: ' . $type],
            ];
        }

        $errors = [];

        // Basic validation - check required fields by type
        switch ($type) {
            case 'hierarchical_rules':
                if (empty($data['taxonomy'])) {
                    $errors[] = 'Taxonomy is required for hierarchical rules';
                }
                if (!empty($data['taxonomy']) && !taxonomy_exists($data['taxonomy'])) {
                    $errors[] = 'Invalid taxonomy: ' . $data['taxonomy'];
                }
                break;

            case 'propagation_rules':
                if (empty($data['taxonomy'])) {
                    $errors[] = 'Taxonomy is required for propagation rules';
                }
                if (empty($data['post_type'])) {
                    $errors[] = 'Post type is required for propagation rules';
                }
                break;

            case 'related_rules':
                if (empty($data['source_taxonomy'])) {
                    $errors[] = 'Source taxonomy is required for related rules';
                }
                if (empty($data['target_taxonomy'])) {
                    $errors[] = 'Target taxonomy is required for related rules';
                }
                break;

            case 'time_based_rules':
                if (empty($data['taxonomy'])) {
                    $errors[] = 'Taxonomy is required for time-based rules';
                }
                if (empty($data['schedule_type'])) {
                    $errors[] = 'Schedule type is required for time-based rules';
                }
                break;

            case 'related_post_terms_rules':
                if (empty($data['acf_field_name'])) {
                    $errors[] = 'ACF field name is required';
                }
                // New schema: single `taxonomy`. Legacy rows: `source_taxonomy`.
                // Accept either so validation is live-data safe. (SPEC §V8)
                if (empty($data['taxonomy']) && empty($data['source_taxonomy'])) {
                    $errors[] = 'Taxonomy is required';
                }
                break;

            case 'hierarchical_level_restriction_rules':
                if (empty($data['taxonomy'])) {
                    $errors[] = 'Taxonomy is required';
                }
                if (empty($data['restriction_mode'])) {
                    $errors[] = 'Restriction mode is required';
                }
                break;

            case 'title_slug_rules':
                if (empty($data['post_type'])) {
                    $errors[] = 'Post type is required for title/slug rules';
                } elseif (!post_type_exists($data['post_type'])) {
                    $errors[] = 'Invalid post type: ' . $data['post_type'];
                }
                if (empty($data['title_pattern']) && empty($data['slug_pattern'])) {
                    $errors[] = 'At least one of title pattern or slug pattern is required';
                }
                if (!empty($data['slug_pattern']) && !empty($data['slug_mode'])
                    && !in_array($data['slug_mode'], ['replace', 'prefix', 'suffix'], true)) {
                    $errors[] = 'Invalid slug mode';
                }
                break;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
