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
     * Memoized fan_in() of $cached_settings.
     *
     * get_enabled_rules() is called many times per request (time_based alone
     * has five call sites), and each call would otherwise regroup all seven
     * rule arrays where the type-keyed path did a single array lookup. Reset
     * in lockstep with $cached_settings — every assignment to that property
     * must clear this one, or reads serve pre-write rules.
     *
     * @since 0.8.0
     * @var array|null
     */
    private $cached_kind_lists = null;

    /**
     * Valid rule types
     *
     * @var array
     */
    private $valid_types = [
        'hierarchical_rules',
        'propagation_rules',
        'related_rules',
        'time_based_rules',
        'related_post_terms_rules',
        'hierarchical_level_restriction_rules',
        'title_slug_rules',
    ];

    /**
     * Effect-kind list keys (ADR 0003 decision 1).
     *
     * @since 0.8.0
     */
    const KIND_TERM   = 'term_rules';
    const KIND_FORMAT = 'format_rules';

    /**
     * Effect kind ⇒ the legacy type-keyed arrays that fan into it, IN ORDER.
     *
     * The order is load-bearing: it is the post-migration position of each
     * type's rules inside its kind list, and position IS order (ADR 0003 —
     * no stable `_id`). It reproduces the current Auto-Set tab order, so a
     * rule set authored before the migration keeps the sequence its author
     * was looking at. Do not reorder this without a migration.
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
     * config class — because `authored_kind_list()` runs on every write this
     * class owns, including CLI and front-end paths that must never resolve
     * `Admin\Config` (CLAUDE.md don't #4).
     *
     * The four here are the ones NOT live on a real site, so collapsing them
     * first was free. `related_rules` and `related_post_terms_rules` keep
     * their own per-type repeaters until #58, and `title_slug_rules` until
     * #59; a row of theirs must NOT appear in a persisted kind list while
     * that is true, because the repeater renders every row in the key it is
     * bound to and Wireframe DROPS any subfield the config does not declare
     * (`RepeaterField::sanitize`) — rendering a live rule the repeater has no
     * subfields for would silently gut it on the next save.
     *
     * Add a type here in the same change that gives it repeater subfields,
     * never before. #66 empties the exception list by moving the last one in.
     *
     * @since 0.8.0
     * @var string[]
     */
    private const CONFIG_MIGRATED_TYPES = [
        'propagation_rules',
        'time_based_rules',
        'hierarchical_rules',
        'hierarchical_level_restriction_rules',
    ];

    /**
     * The migrated types belonging to one kind, in KIND_TYPES order.
     *
     * Empty ⇒ no repeater is bound to that kind's list yet, so the list stays
     * a pure derived duplicate of the type-keyed arrays.
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
     * Get all settings from options
     *
     * @return array Complete settings array
     */
    private function get_all_settings(): array {
        if ($this->cached_settings === null) {
            $this->cached_kind_lists = null;
            $this->cached_settings = get_option(self::OPTION_NAME, []);

            // Ensure all rule types exist
            foreach ($this->valid_types as $type) {
                if (!isset($this->cached_settings[$type])) {
                    $this->cached_settings[$type] = [];
                }
            }
        }

        return $this->cached_settings;
    }

    /**
     * The kind lists for the current request, derived and memoized.
     *
     * @since 0.8.0
     * @return array<string,array[]>
     */
    private function get_kind_lists(): array {
        $settings = $this->get_all_settings();

        if ($this->cached_kind_lists === null) {
            $this->cached_kind_lists = self::fan_in($settings);
        }

        return $this->cached_kind_lists;
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
     * lists (ADR 0003 decision 1). The **expand** half of expand-then-contract:
     * the type-keyed arrays are untouched, so every existing reader keeps
     * working and a rule authored before the migration fires identically after.
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
            $out = array_merge($out, self::fan_out_types($lists[$kind] ?? [], $types));
        }

        return $out;
    }

    /**
     * Split ONE kind list into just the type-keyed arrays named in $types.
     *
     * The half of fan_out() the save path needs: the ordered repeater writes
     * a kind list, and the types it authors have to land back in their legacy
     * arrays because handlers still derive their rules from those (#66 flips
     * that). Restricting to an explicit type list is what keeps a repeater
     * that authors four of a kind's six types from blanking the other two.
     *
     * Rows of any other type are dropped, as is a row with no `type` at all —
     * the config's `type` select is `required`, so an untyped row cannot reach
     * here through the admin, and one that arrives some other way names no
     * array to be written into.
     *
     * Every requested type is always present, empty where the list holds no
     * rows of it — a rule deleted in the repeater must clear its legacy array,
     * not be left behind by an absent key.
     *
     * @since 0.8.0
     * @param mixed    $rows  One kind list (rows carrying `type`).
     * @param string[] $types Type keys to extract, in output order.
     * @return array<string,array[]>
     */
    public static function fan_out_types($rows, array $types): array {
        $out = array_fill_keys($types, []);

        if (!is_array($rows)) {
            return $out;
        }

        foreach ($rows as $row) {
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

        return $out;
    }

    /**
     * Which kind list a legacy rule type lives in.
     *
     * Every type in $valid_types is covered — H10 asserts the two lists are
     * the same set, so a type added to one and not the other fails the harness
     * rather than silently reading zero rules at runtime.
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
     * **The list is derived at read time**, not read from the persisted
     * `term_rules` / `format_rules` keys, and that stays true through the
     * config collapse. The type-keyed arrays remain the shape every handler
     * reads, so deriving is the only way a front-end or cron request is
     * guaranteed to see what the admin last saved. It also means no admin save
     * can desync behaviour, and that an emptied rule set cannot resurrect from
     * a stale persisted copy.
     *
     * Since #57 the ordered repeater writes the persisted `term_rules` key
     * directly, and `WireframeBootstrap::fan_out_rule_lists()` projects those
     * rows back into the type-keyed arrays on the same save — which is what
     * keeps this derived read seeing repeater edits. The one thing the derived
     * list therefore CANNOT reproduce is cross-type authored order, because it
     * groups by type; nothing consumes that order yet (each handler filters to
     * its own type), and the dispatcher tickets (#60-#64) are where it starts
     * to matter. Authority flips to the persisted copy in the contract ticket
     * (#66), when the type-keyed path is deleted.
     *
     * `id` is assigned PER TYPE, not per kind-list position — it stays the
     * index the rule has inside its own type array, exactly as `get_rules()`
     * reports it. Handlers persist state keyed on it (`TitleSlugHandler`'s
     * `write_rule_status()`), so re-basing it onto the kind list would silently
     * repoint every stored per-rule status. It carries the same warning as
     * `get_rules()`: positional, re-derived on read, never a stable identity.
     *
     * With `['type' => X]` in $filters the result is element-for-element equal
     * to `get_rules(X, ...)` — the equivalence `get_enabled_rules()` relies on
     * and `tests/verify-kind-lists.php` asserts.
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

        $lists = $this->get_kind_lists();

        return self::filter_rules(self::project_kind_rules($lists[$kind]), $filters);
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
     * Read-time projection of one type-keyed array. Extracted from get_rules()
     * so the kind path and the type path can be proven equivalent without
     * WordPress (Gate 1).
     *
     * `id` is the row's POSITION, not its array key. The two are the same for
     * anything the plugin writes — save_rule() appends and delete_rule()
     * reindexes, and Wireframe stores lists — but on a hand-corrupted sparse
     * array they diverge, and the kind list has no keys to preserve (fan_in
     * reindexes), so a key-based id here would silently break the equivalence
     * this method exists to prove. Position is also what the original
     * get_rules() comment already described id as: "the ARRAY INDEX, re-derived
     * on every read". H10 asserts both paths agree on a sparse array.
     *
     * @since 0.8.0
     * @param string $type Legacy rule type key.
     * @param array  $rows Stored rows for that type.
     * @return array
     */
    public static function project_type_rules(string $type, array $rows): array {
        $out   = [];
        $index = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['id'])) {
                $row['id'] = $index;
            }
            $index++;
            $out[] = self::normalize_rule_shape($type, $row);
        }

        return $out;
    }

    /**
     * Refresh the persisted kind lists from the type-keyed arrays.
     *
     * Applied to every write this class owns, so the two shapes in the option
     * never disagree on a save we control. The Wireframe admin writes the
     * option directly and therefore bypasses this — which is why reads derive
     * rather than trusting the persisted copy (see get_kind_rules()).
     *
     * @since 0.8.0
     * @param array $settings Settings about to be written.
     * @return array Settings with both kind lists materialized.
     */
    private static function materialize_kind_lists(array $settings): array {
        foreach (array_keys(self::KIND_TYPES) as $kind) {
            $settings[$kind] = self::authored_kind_list($kind, $settings);
        }

        return $settings;
    }

    /**
     * What the persisted `term_rules` / `format_rules` key SHOULD hold.
     *
     * Two regimes, picked by whether a repeater authors any of the kind's
     * types (see CONFIG_MIGRATED_TYPES):
     *
     * **No migrated types** ⇒ the list is a pure derived duplicate, so it is
     * simply the fan-in. That is #56's regime, unchanged, and it is still
     * `format_rules`' regime until #59 moves `title_slug_rules` in.
     *
     * **Some migrated types** ⇒ the stored list is AUTHORED — the repeater
     * wrote the row order, and cross-type order is exactly what the fan-in
     * cannot reproduce (it groups by type). So the stored list is KEPT as-is
     * whenever it still agrees with the legacy arrays *as sets per type*,
     * which is what `fan_out_types()` decides. Only when they disagree — a
     * CLI `save_rule()`, a seeded fixture, an imported rule set, anything that
     * wrote a legacy array behind the repeater's back — is authored order
     * discarded and the list rebuilt, so the repeater shows the new rules
     * rather than hiding them.
     *
     * Rows of NON-migrated types are excluded from the list either way. They
     * are still authored in their own per-type repeaters, and a row the bound
     * repeater has no subfields for is gutted on the next save
     * (`RepeaterField::sanitize` drops undeclared subfields). Handlers are
     * unaffected: `get_kind_rules()` derives from the legacy arrays and so
     * still sees all six term types (see its docblock).
     *
     * @since 0.8.0
     * @param string $kind     KIND_TERM or KIND_FORMAT.
     * @param array  $settings Settings to derive from, and to read the stored list out of.
     * @return array[] Rows for that kind's persisted key.
     */
    public static function authored_kind_list(string $kind, array $settings): array {
        $migrated = self::migrated_types_for_kind($kind);
        $derived  = self::fan_in($settings);

        if (empty($migrated)) {
            return $derived[$kind] ?? [];
        }

        $stored = $settings[$kind] ?? null;

        if (is_array($stored)) {
            // Partition the stored list three ways, because the three cases
            // mean different things:
            //
            //   migrated type      → the repeater's to author; keep.
            //   non-migrated type  → a leftover from the derived regime. Drop
            //                        it: the rule itself is safe in its own
            //                        type-keyed array, and leaving it here
            //                        would hand the repeater a row it has no
            //                        subfields for.
            //   no/unknown type    → CORRUPTION, not data. It names no array
            //                        to live in, so there is nothing to
            //                        preserve and no honest way to keep it.
            //                        The `type` select is `required`, so the
            //                        admin cannot produce one.
            //
            // The last case forces the rebuild below rather than being quietly
            // filtered out of a list we then declare trustworthy — recomputing
            // from the type-keyed arrays is the only answer that leaves the
            // stored shape and the authoritative one agreeing.
            $authored = [];
            $sound    = true;
            foreach ($stored as $row) {
                $type = is_array($row) ? (string) ($row['type'] ?? '') : '';
                if (in_array($type, $migrated, true)) {
                    $authored[] = $row;
                } elseif (!in_array($type, self::KIND_TYPES[$kind], true)) {
                    $sound = false;
                    break;
                }
            }

            if ($sound
                && self::fan_out_types($authored, $migrated)
                    === self::fan_out_types($derived[$kind] ?? [], $migrated)) {
                return $authored;
            }
        }

        // Rebuild: same rows the fan-in produces, minus the types no repeater
        // authors yet. Order falls back to KIND_TYPES order.
        $rebuilt = [];
        foreach ($derived[$kind] ?? [] as $row) {
            if (is_array($row) && in_array((string) ($row['type'] ?? ''), $migrated, true)) {
                $rebuilt[] = $row;
            }
        }

        return $rebuilt;
    }

    /**
     * Save settings to options
     *
     * @param array $settings Complete settings array
     * @return bool Success
     */
    private function save_all_settings(array $settings): bool {
        $settings = self::materialize_kind_lists($settings);

        // Whatever the outcome below, the derived lists no longer match what
        // this method is about to leave in $cached_settings.
        $this->cached_kind_lists = null;

        $success = update_option(self::OPTION_NAME, $settings);

        // Refresh the request cache to match what's ACTUALLY stored. update_option
        // returns false for TWO reasons: (a) the new value equals the stored value
        // (no write needed) — cache should reflect $settings; (b) a genuine DB
        // failure — cache must NOT adopt $settings, or a later save in the same
        // request (e.g. import_rules looping save_rule) would read the poisoned
        // cache and persist the failed data as the baseline. Distinguish by
        // re-reading: only adopt $settings when it actually round-trips.
        // (PR#24 round 5 #5 + round 6 #4)
        if ($success || $this->cached_settings === $settings) {
            // Success ⇒ adopt. Or false BUT the cache already equals $settings ⇒
            // the false can only mean update_option no-op'd on equality (the
            // stored value also equals $settings), so adopting is correct and no
            // re-read is needed. (PR#24 round 5 #5, round 7 #3, round 8 #5)
            $this->cached_settings = $settings;
        } else {
            // false AND cache differs: distinguish a no-op (stored already ==
            // $settings) from a genuine DB failure by re-reading. Adopt only if
            // it round-trips; otherwise drop the cache so a later save in the
            // same request (e.g. import_rules looping save_rule) doesn't persist
            // failed data as the baseline. (PR#24 round 6 #4)
            $stored = get_option(self::OPTION_NAME, []);
            $this->cached_settings = (is_array($stored) && $stored === $settings)
                ? $settings
                : null;
        }

        return $success;
    }

    /**
     * Apply filters to rules array
     *
     * Static + pure so both read paths (type-keyed and kind-keyed) share one
     * filter implementation and the harness can exercise it without WordPress.
     * Renamed from apply_filters() in 0.8.0 — it never was the WP hook of that
     * name, and the collision read as one.
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

            // Filter by rule type. Only meaningful on a kind list, where rows
            // of every type share one array; on a type-keyed read every row
            // already matches. (0.8.0)
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
                // No id-default here: both callers project first
                // (project_type_rules / project_kind_rules), and each assigns
                // `id` before this runs. A third copy would be dead code
                // claiming to be a safety net. (0.8.0)
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
     */
    public function get_rules(string $type, array $filters = []): array {
        if (!in_array($type, $this->valid_types, true)) {
            return [];
        }

        $all_settings = $this->get_all_settings();
        $rules = $all_settings[$type] ?? [];

        // Add IDs if not present + normalize Wireframe shape changes to legacy shape.
        //
        // WARNING: `id` is the ARRAY INDEX, re-derived on every read and never
        // persisted (save reindexes via array_values; the id key is stripped
        // before write). Wireframe stores rules POSITIONALLY, so reordering or
        // deleting a rule renumbers the rest. NEVER key persistent per-rule state
        // (tracking meta, caches) on this id — use a stable identity (e.g. post
        // id + field name). The ACF-reference handler is declarative and stores
        // nothing keyed on rules, so it is unaffected.
        return self::filter_rules(self::project_type_rules($type, $rules), $filters);
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
     * @param array $rule Normalized-so-far rule (post_type/acf split already done).
     * @return array
     */
    private static function migrate_related_post_terms_shape(array $rule): array {
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

        $settings = get_option(self::OPTION_NAME, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $rows = $settings['related_post_terms_rules'] ?? [];
        $changed = false;

        if (is_array($rows)) {
            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $migrated = self::migrate_related_post_terms_shape($row);
                if ($migrated !== $row) {
                    $rows[$i] = $migrated;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $settings['related_post_terms_rules'] = $rows;
            $this->save_all_settings($settings);

            // Flag iff the migrated rows are ACTUALLY in storage now. This
            // distinguishes the two reasons save_all_settings/update_option can
            // report false: (a) a genuine DB write failure — rows NOT persisted
            // → don't flag, so we retry next load instead of permanently
            // skipping and later corrupting on a raw resave (PR#24 round 2 #3);
            // (b) the new bytes equalled the stored bytes (already migrated by a
            // concurrent writer) — rows ARE persisted → flag, so we don't loop
            // re-entering the migration every admin load (PR#24 round 4 #3).
            // Re-read fresh (bypass the request cache, which we just primed).
            // Return true ONLY when the rewrite actually persisted (flag set) —
            // matches the docblock ("true if a rewrite was performed"). On a
            // write failure the flag stays unset (retry next load) and we report
            // false so a caller doesn't log "migration done". (PR#24 round 8 #3)
            $persisted = get_option(self::OPTION_NAME, []);
            if (is_array($persisted)
                && ($persisted['related_post_terms_rules'] ?? null) === $rows) {
                update_option(self::ACFREF_SCHEMA_FLAG, self::ACFREF_SCHEMA_VERSION);
                return true;
            }
            return false;
        }

        // Nothing to migrate (fresh install / already-new data): no write was
        // needed, so flag unconditionally to skip future scans.
        update_option(self::ACFREF_SCHEMA_FLAG, self::ACFREF_SCHEMA_VERSION);

        return false;
    }

    /**
     * Schema marker recording that the kind-list fan-in has run at least once.
     *
     * Bumped only if the fan-in's OUTPUT shape changes (a kind added, the
     * documented order changed). It is a MARKER, not a gate — see
     * sync_kind_lists() for why gating on it would be wrong.
     *
     * @since 0.8.0
     */
    const KIND_SCHEMA_VERSION = 1;
    const KIND_SCHEMA_FLAG    = 'bws_mc_kind_schema';

    /**
     * Persist the kind-list fan-in (#56, ADR 0003). Runs on admin load, AFTER
     * maybe_migrate_acf_ref_storage() so the persisted lists carry
     * already-key-renamed `related_post_terms` rows rather than freezing a
     * legacy shape into the new one.
     *
     * Persisting matters even though reads derive (see get_kind_rules()): the
     * Wireframe admin reads the settings option RAW, so the ordered repeater
     * (#57) can only bind to `term_rules`/`format_rules` if those keys exist
     * in storage. The write is additive — the type-keyed arrays it derives
     * from are left exactly as they were.
     *
     * **Deliberately NOT flag-gated, though #56 asked for "flag-gated".** The
     * flag was asked for to avoid a write on every admin load; writing only
     * when the recomputed lists differ from the stored ones achieves that and
     * is strictly better, because a one-shot gate would be *wrong* here. Any
     * writer that bypasses the repeater — a CLI `save_rule()`, a seeded
     * fixture, an import — updates a type-keyed array only, and a one-shot
     * gate would refuse to ever show those rules in the repeater again.
     *
     * **What "recompute" means changed in #57.** For a kind whose types the
     * repeater now authors, a plain fan-in would be a *clobber*, not a
     * refresh: the fan-in groups by type and so cannot reproduce the
     * cross-type order the author just dragged into place. `authored_kind_list()`
     * carries that distinction — it keeps the stored list whenever it still
     * agrees with the type-keyed arrays, and rebuilds only when something
     * wrote behind the repeater's back. Idempotent across repeat loads either
     * way: an unchanged option recomputes to an identical result and no write
     * happens.
     *
     * @since 0.8.0
     * @return bool True if a rewrite was performed AND persisted.
     */
    public function sync_kind_lists(): bool {
        // Read through the request cache, so the "ensure all rule types exist"
        // fill applies and a later get_all_settings() in the same request
        // doesn't serve a differently-shaped array than this method wrote.
        $settings = $this->get_all_settings();

        $lists   = [];
        $changed = false;

        foreach (array_keys(self::KIND_TYPES) as $kind) {
            $lists[$kind] = self::authored_kind_list($kind, $settings);

            if (($settings[$kind] ?? null) !== $lists[$kind]) {
                $settings[$kind] = $lists[$kind];
                $changed         = true;
            }
        }

        if (!$changed) {
            // Already in sync (fresh install, an unchanged option, or a
            // storage-layer save that materialized them). Record that the
            // fan-in has run at this schema version.
            update_option(self::KIND_SCHEMA_FLAG, self::KIND_SCHEMA_VERSION);

            return false;
        }

        $this->save_all_settings($settings);

        // Mark the schema version iff the lists are ACTUALLY in storage now —
        // same two-reasons discipline as maybe_migrate_acf_ref_storage():
        // update_option returns false both for a genuine DB failure and for a
        // no-op on equality. Re-read fresh to distinguish, bypassing the
        // request cache we just primed. Unlike the acf-ref flag this one gates
        // nothing, so a missed mark costs only the marker's accuracy — the next
        // load recomputes and repairs regardless.
        $persisted = get_option(self::OPTION_NAME, []);
        if (is_array($persisted)
            && ($persisted[self::KIND_TERM] ?? null) === $lists[self::KIND_TERM]
            && ($persisted[self::KIND_FORMAT] ?? null) === $lists[self::KIND_FORMAT]) {
            update_option(self::KIND_SCHEMA_FLAG, self::KIND_SCHEMA_VERSION);
            return true;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function get_rule(string $type, int $rule_id): ?array {
        if (!in_array($type, $this->valid_types, true)) {
            return null;
        }

        $all_settings = $this->get_all_settings();
        $rules = $all_settings[$type] ?? [];

        if (!isset($rules[$rule_id])) {
            return null;
        }

        $rule = $rules[$rule_id];
        $rule['id'] = $rule_id;

        return self::normalize_rule_shape($type, $rule);
    }

    /**
     * {@inheritDoc}
     */
    public function save_rule(string $type, int $rule_id, array $data): int {
        // Returns the saved rule's zero-based index on success, or -1 on
        // failure. Index 0 is a valid first rule — callers must guard with
        // `>= 0`, not `> 0`.
        if (!in_array($type, $this->valid_types, true)) {
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

        if (!isset($all_settings[$type])) {
            $all_settings[$type] = [];
        }

        // Remove ID from data (it's the array key)
        unset($data['id']);

        // Create new rule (append)
        if ($rule_id === -1) {
            $all_settings[$type][] = $data;
            $new_id = count($all_settings[$type]) - 1;
        } else {
            // Update existing rule
            if (!isset($all_settings[$type][$rule_id])) {
                return -1;
            }
            $all_settings[$type][$rule_id] = $data;
            $new_id = $rule_id;
        }

        $success = $this->save_all_settings($all_settings);

        return $success ? $new_id : -1;
    }

    /**
     * {@inheritDoc}
     */
    public function delete_rule(string $type, int $rule_id): bool {
        if (!in_array($type, $this->valid_types, true)) {
            return false;
        }

        $all_settings = $this->get_all_settings();

        if (!isset($all_settings[$type][$rule_id])) {
            return false;
        }

        // Remove rule and re-index array
        unset($all_settings[$type][$rule_id]);
        $all_settings[$type] = array_values($all_settings[$type]);

        return $this->save_all_settings($all_settings);
    }

    /**
     * {@inheritDoc}
     */
    public function search_rules(string $query, array $filters = []): array {
        $query_lower = strtolower($query);
        $results = [];

        foreach ($this->valid_types as $type) {
            $rules = $this->get_rules($type, $filters);

            foreach ($rules as $rule) {
                $searchable = [
                    $rule['name'] ?? '',
                    $rule['taxonomy'] ?? '',
                    implode(' ', $rule['post_types'] ?? []),
                ];

                $searchable_text = strtolower(implode(' ', $searchable));

                if (strpos($searchable_text, $query_lower) !== false) {
                    $rule['type'] = $type;
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
        return $this->valid_types;
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
        if (!in_array($type, $this->valid_types, true)) {
            return 0;
        }

        $all_settings = $this->get_all_settings();
        $updated_count = 0;

        foreach ($rule_ids as $rule_id) {
            // Only count + mutate rules whose state ACTUALLY changes. Counting
            // no-ops would (a) overstate the toggle count, and (b) when EVERY
            // target is already in the requested state, leave $settings ===
            // stored ⇒ update_option no-ops ⇒ save_all_settings returns false ⇒
            // the failure guard below would wrongly report 0. (PR#24 round 8 #1)
            if (isset($all_settings[$type][$rule_id])
                && (bool) ($all_settings[$type][$rule_id]['enabled'] ?? false) !== $enabled) {
                $all_settings[$type][$rule_id]['enabled'] = $enabled;
                $updated_count++;
            }
        }

        // Report 0 if a real write was needed but didn't persist, so callers
        // don't show success on a genuine DB failure (save_rule/delete_rule
        // already propagate the save result; bulk_toggle must too). With the
        // change-detection above, $updated_count>0 implies $settings differs
        // from stored, so a false here is a true failure, not a no-op.
        // (PR#24 round 6 #3, round 8 #1)
        if ($updated_count > 0 && !$this->save_all_settings($all_settings)) {
            return 0;
        }

        return $updated_count;
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
        $types_to_export = $filters['types'] ?? $this->valid_types;

        foreach ($types_to_export as $type) {
            if (!in_array($type, $this->valid_types, true)) {
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
            if (!in_array($type, $this->valid_types, true)) {
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
        $this->cached_kind_lists = null;

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
