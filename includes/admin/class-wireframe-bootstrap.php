<?php
/**
 * WP Wireframe Bootstrap
 *
 * Registers custom field types, builds the settings config, and boots
 * Wireframe\App for the Meta Conductor admin page.
 *
 * @package BWS_Meta_Manager
 * @since 0.3.0
 */

namespace BWS\MetaConductor\Admin;

use BWS\MetaConductor\TaxonomyManager;
use BWS\MetaConductor\Conversion\ConversionUi;
use BWS\MetaConductor\Handlers\HierarchicalHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\RuleStorage;

if (!defined('ABSPATH')) {
    exit;
}

class WireframeBootstrap {

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_action('init', [self::class, 'boot'], 10);
        add_action('admin_menu', [self::class, 'register_subpages'], 11);

        // Snapshot every row title in the ordered term-rule list
        // (architecture.md → The ordered rule repeaters).
        // One hook for the whole repeater, dispatching on each row's `type` —
        // the per-type propagation and time-based hooks rescoped onto it when
        // the config collapsed (#57), then related + ACF-reference (#58), and
        // hierarchical + level-restriction gained titles for the first time,
        // which is what finally makes the "[Disabled] " prefix uniform across
        // the list (#30). Wireframe's title_template does raw value
        // substitution only — it cannot resolve a stored term ID to a name —
        // so the titles must be persisted.
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_term_rule_labels'], 10, 1);

        // The same, for the ordered format-rule list (#59). A separate hook
        // rather than one generic snapshot over both kinds: the two lists
        // dispatch on disjoint type sets and share only the mechanics
        // `snapshot_row_titles()` holds, so merging them would buy a parameter
        // and cost the ability to read either one on its own.
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_format_rule_labels'], 10, 1);

        // Snapshot the General-tab claim-override row title. Without it the
        // repeater interpolates the raw stored value ("category: replace"),
        // the one surface the claim vocabulary would miss (ADR 0004). This is
        // the one snapshot that did NOT rescope onto a repeater — it belongs
        // to the per-taxonomy default, not to a rule (#53 §2).
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_claim_override_labels'], 10, 1);

        // The collision advisory's two surfaces (#65): recompute-and-persist on
        // `settings_saved`, and the on-demand ActionField re-check. Registered
        // from the detector itself rather than here — they are its hooks, and
        // both fire on requests that never reach `boot()`'s admin gate.
        CollisionDetector::init();
    }

    /**
     * Related-term title. Schema (the shape the old three-token
     * title_template rendered, now baked into one snapshot):
     *   {trigger} → {target}{ (post types)}
     *   e.g. "Categories: Term A → Tags: Term B (Pages)"
     * Trigger is the taxonomy label when trigger_type=taxonomy, else the
     * comma-joined trigger term labels.
     *
     * @since 0.8.0 Replaces snapshot_related_labels (#58).
     * @param array $rule
     * @return string Unescaped.
     */
    private static function related_title(array $rule): string {
        $trigger = (($rule['trigger_type'] ?? 'term') === 'taxonomy')
            ? self::taxonomy_label($rule['trigger_taxonomy'] ?? '')
            : self::trigger_terms_label($rule['trigger_term_id'] ?? null);

        return $trigger
            . ' ' . "\xE2\x86\x92" . ' '
            . self::term_label($rule['target_term_id'] ?? null)
            . self::scope_label($rule['post_types'] ?? []);
    }

    /**
     * Leading marker for a disabled rule's collapsed row title, '' when enabled.
     * Prepended to the first title_template token by each snapshot. Shared by
     * every rule type whose repeater carries an `enabled` toggle.
     *
     * @param array $rule Clean rule values (the `enabled` subfield).
     * @return string Unescaped marker (already-safe literal).
     */
    private static function disabled_prefix(array $rule): string {
        // `enabled` defaults true; a rule missing the key (legacy) is treated
        // as enabled, matching the config default and the handler gate.
        $enabled = !array_key_exists('enabled', $rule) || !empty($rule['enabled']);
        return $enabled ? '' : \esc_html__('[Disabled] ', 'meta-conductor');
    }

    /**
     * ACF-reference title (architecture.md → The ordered rule repeaters).
     * Runs PRE-storage — `acf_field_name` is still the raw
     * "post_type:field_name" option value (before the storage adapter splits
     * it). No A→B arrow: same term, same taxonomy, moved across a
     * relationship.
     *
     * Schema: {Copy|Sync} {Taxonomy} terms {to|from} {field_label}{ on {statuses}}
     *   Copy|Sync ← keep_in_sync (off|on)
     *   to|from   ← holder_role (source=to/push | target=from/pull)
     *   field_label ← acf_get_field()['label'] (clean human label), fallback name
     *   on {statuses} ← post_status gate, only when set
     *
     * @since 0.8.0 Replaces snapshot_acf_reference_labels (#58).
     * @param array $rule
     * @return string Unescaped.
     */
    private static function acf_reference_title(array $rule): string {
        $verb = !empty($rule['keep_in_sync'])
            ? __('Sync', 'meta-conductor')
            : __('Copy', 'meta-conductor');

        // Default an ABSENT holder_role to 'target', matching the handler
        // (holder_is_source) and the storage migration — NOT 'source'. The
        // key is absent only for a legacy raw rule re-saved before the
        // migration flag is set; defaulting to 'source' here would write a
        // row title that lies about the rule's runtime direction. A new rule
        // always carries an explicit holder_role. (PR#24 round 4 #2)
        $prep = (($rule['holder_role'] ?? 'target') === 'source')
            ? __('to', 'meta-conductor')
            : __('from', 'meta-conductor');

        $gate = self::status_gate_label($rule['post_status'] ?? []);

        // Assemble; tolerate empty parts gracefully.
        $title = trim(sprintf(
            /* translators: 1: Copy/Sync 2: taxonomy 3: to/from 4: field label */
            __('%1$s %2$s terms %3$s %4$s', 'meta-conductor'),
            $verb,
            self::taxonomy_label($rule['taxonomy'] ?? ''),
            $prep,
            self::acf_field_label($rule['acf_field_name'] ?? '')
        ));

        if ($gate !== '') {
            $title .= ' ' . sprintf(__('on %s', 'meta-conductor'), $gate);
        }

        return $title;
    }

    /**
     * Bake `row_title` onto every row of one kind list.
     *
     * The mechanics both kinds share, stated once: the position number, the
     * disabled marker, the single escape, and leaving a payload that carries
     * no list of this kind untouched. What differs is the per-type title
     * schema, which is the `$title` builder each caller passes.
     *
     * Runs on `wp-wireframe/save/payload`, which fires AFTER the Sanitizer (so
     * `row_title` survives despite not being an editable subfield) and before
     * the merge into saved state.
     *
     * Each per-type builder returns UNESCAPED text and is escaped here, once —
     * the same discipline the term/taxonomy label helpers already follow.
     *
     * The title LEADS with the row's list position ("#3 "). Wireframe only
     * numbers a row whose `title_template` renders empty, so a titled list
     * showed no positions at all — and position is what decides a collision,
     * what the collision advisory names, and what the author reorders. Baked
     * rather than interpolated because `title_template` substitutes row values
     * and has no index token. The same save that renumbers the rows recomputes
     * the findings, and the on-demand check re-snapshots in-flight rows before
     * reading their titles, so the two never disagree (#65 UX follow-up).
     *
     * The position counts EVERY row, including one that is not an array and
     * carries no title: a skipped row still occupies a place in the list the
     * repeater renders, so numbering past it would misname every row below.
     *
     * @since 0.8.0
     * @param array    $clean_values Sanitized top-level field map.
     * @param string   $key          Kind-list key.
     * @param callable $title        fn(array $rule): string — unescaped title.
     * @return array
     */
    private static function snapshot_row_titles(array $clean_values, string $key, callable $title): array {
        if (empty($clean_values[$key]) || !is_array($clean_values[$key])) {
            return $clean_values;
        }

        $position = 0;

        foreach ($clean_values[$key] as &$rule) {
            $position++;

            if (!is_array($rule)) {
                continue;
            }

            $rule['row_title'] = '#' . $position . ' '
                . self::disabled_prefix($rule)
                . \esc_html($title($rule));
        }
        unset($rule);

        return $clean_values;
    }

    /**
     * Assemble the row title for every rule in the ordered term-rule list.
     *
     * One hook for the whole repeater, dispatching on each row's `type`, so a
     * rule type's title schema stays one function while the disabled marker,
     * the escaping and the "which key holds the rules" question are answered
     * once for all of them.
     *
     * A separate entry point from the format twin rather than one generic
     * snapshot over both kinds: the two lists dispatch on disjoint type sets
     * and share nothing but the mechanics `snapshot_row_titles()` now holds,
     * so merging them would buy a parameter and cost the ability to read
     * either one on its own.
     *
     * See [docs/architecture.md → The ordered rule repeaters](../../docs/architecture.md#the-ordered-rule-repeaters).
     *
     * @since 0.8.0 Replaces snapshot_propagation_labels + snapshot_time_based_labels.
     * @param array $clean_values Sanitized top-level field map.
     * @return array
     */
    public static function snapshot_term_rule_labels(array $clean_values): array {
        return self::snapshot_row_titles(
            $clean_values,
            OptionRuleStorage::KIND_TERM,
            [self::class, 'term_rule_title']
        );
    }

    /**
     * Assemble the row title for every rule in the ordered format-rule list
     * (#59). The format-kind twin of snapshot_term_rule_labels, hooked on the
     * same `wp-wireframe/save/payload` seam at the same priority.
     *
     * The list this replaces interpolated `{name}` live, which needed no
     * snapshot at all. Baking one now is what lets the title carry the
     * disabled marker and the post-type scope — and, when
     * `field_transformation` joins, a per-type schema — none of which
     * Wireframe's substitution-only `title_template` can produce.
     *
     * @since 0.8.0
     * @param array $clean_values Sanitized top-level field map.
     * @return array
     */
    public static function snapshot_format_rule_labels(array $clean_values): array {
        return self::snapshot_row_titles(
            $clean_values,
            OptionRuleStorage::KIND_FORMAT,
            [self::class, 'format_rule_title']
        );
    }

    /**
     * The unescaped row title for one format rule, by type.
     *
     * One `case` today, and a `switch` anyway: the dispatch IS the shape #59
     * exists to establish, and collapsing it to a single expression would have
     * to be undone by the ticket that adds the second type.
     *
     * @param array $rule Clean rule values.
     * @return string Unescaped.
     */
    private static function format_rule_title(array $rule): string {
        switch ((string) ($rule['type'] ?? '')) {
            case 'title_slug_rules':
                return self::title_slug_title($rule);
        }

        return __('(no rule type chosen)', 'meta-conductor');
    }

    /**
     * Title & slug rule title. Schema:
     *   {name}{ (Post type)}
     *   e.g. "MC item slug (MC Items)"
     *
     * The author names these rules themselves (`name` is required), so unlike
     * the term titles there is nothing to assemble from the mechanics — the
     * snapshot's job here is the scope suffix and the disabled marker. A row
     * that reached storage without a name (a fixture, an import) is NAMED
     * rather than left blank: it is still selectable in a collapsed,
     * reorderable list and has to stay findable.
     *
     * @param array $rule
     * @return string Unescaped.
     */
    private static function title_slug_title(array $rule): string {
        $name = trim((string) ($rule['name'] ?? ''));

        if ($name === '') {
            $name = __('Untitled title/slug rule', 'meta-conductor');
        }

        return $name . self::post_type_scope_label($rule['post_type'] ?? '');
    }

    /**
     * Post-type scope SUFFIX " (Label)" for a rule that names ONE post type,
     * '' when it names none.
     *
     * The scalar counterpart of scope_label(), which reads a checkboxes value.
     * They are not one function taking either shape on purpose: a scalar
     * `post_type` and a `post_types` gate mean different things — a lookup key
     * versus a scope — and selected_checkbox_slugs() silently returns [] for a
     * string, so a shared helper would render an empty scope for every
     * title/slug rule rather than failing visibly.
     *
     * @param mixed $post_type Single post-type slug.
     * @return string
     */
    private static function post_type_scope_label($post_type): string {
        $slug = is_string($post_type) ? $post_type : '';
        if ($slug === '') {
            return '';
        }

        $obj = \get_post_type_object($slug);

        return ' (' . ($obj ? $obj->label : $slug) . ')';
    }

    /**
     * Bring stored rules up to what the ordered repeater expects, before
     * Wireframe reads the settings option raw.
     *
     * Two repairs, one write. Both exist because **Wireframe reads the option
     * directly** — it does not go through the storage layer's read-time
     * adapters — so anything a handler tolerates on read but the config does
     * not declare gets rewritten by the first save of the settings page
     * (`RepeaterField::sanitize` rebuilds each row from declared subfields
     * only, filling defaults). That makes a read-time-only adaptation unsafe
     * for the admin path, which is exactly what architecture invariant #1
     * warns about.
     *
     * 1. **`inheritance_behavior` (#16).** A hierarchical row saved before the
     *    outcome selector carries only `hierarchy_direction` +
     *    `expansion_behavior`. Left alone, the form would bind the absent new
     *    key, show its `ancestors` default, and the next save would persist
     *    that — silently turning a `parent_to_child` rule into an ancestors
     *    one. `HierarchicalHandler::resolve_behavior()` still reads the legacy
     *    pair, which covers front-end and cron requests that never reach this
     *    boot; this is the admin half.
     * 2. **`row_title`.** A save-time snapshot, so a rule that reached storage
     *    some other way — a seeded fixture, `save_rule()` from WP-CLI, an
     *    import — has none, and `title_template` renders its collapsed row
     *    blank. The per-type repeaters mostly hid this by interpolating a live
     *    token (`{taxonomy}`, `{name}`) instead; one shared template means one
     *    shared fix.
     *
     * Since #59 this runs over BOTH kind lists. Only the title backfill
     * applies to `format_rules` — `title_slug_rules`' stored shape is
     * unchanged by the move, which is the ticket's "existing rules survive
     * intact" criterion restated as an absence of migration code — but the
     * blank-collapsed-row failure is identical, and it is newly reachable
     * there because the format list stopped interpolating `{name}` live.
     *
     * Self-limiting — once every row is migrated and titled there is nothing
     * to do, so this is at most one write per rule set, not one per admin load.
     * Both kinds are repaired in ONE write for the same reason: two
     * update_option calls would leave a window where the two lists disagree
     * about which admin load they belong to.
     *
     * @since 0.8.0
     * @param RuleStorage $storage The instance handlers hold this request.
     * @return void
     */
    private static function repair_stored_rules(RuleStorage $storage): void {
        $settings = $storage->get_raw_settings();
        $changed  = [];

        foreach ([
            OptionRuleStorage::KIND_TERM   => 'repair_term_rows',
            OptionRuleStorage::KIND_FORMAT => 'repair_format_rows',
        ] as $key => $repairer) {
            $rows = $settings[$key] ?? [];

            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $repaired = self::$repairer($rows);

            if ($repaired !== $rows) {
                $changed[$key] = $repaired;
            }
        }

        if (empty($changed)) {
            return;
        }

        $settings = array_merge($settings, $changed);

        // update_option returns false for a genuine failure AND for a no-op on
        // equality; here the values provably differ, so false can only mean the
        // write failed. Leaving the request cache pointing at the repaired rows
        // in that case would have handlers act on rules that are not in the
        // database. (architecture.md invariant #7)
        if (!update_option(OptionRuleStorage::OPTION_NAME, $settings)) {
            return;
        }

        // Drop the cache so nothing in this request keeps serving the
        // pre-repair rows.
        $storage->clear_cache();
    }

    /**
     * The term list's row repairs: three shape migrations, then the title
     * backfill. Pure — takes rows, returns rows, so the caller decides whether
     * anything is worth writing.
     *
     * @since 0.8.0
     * @param array $rows Stored `term_rules` rows.
     * @return array
     */
    private static function repair_term_rows(array $rows): array {
        $repaired = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $row = self::migrate_inheritance_behavior($row);
                $row = self::migrate_related_term_shape($row);
                // The acf-ref key-rename migration is one-shot flag-gated
                // (maybe_migrate_acf_ref_storage), so a legacy-shaped row
                // written AFTER the flag was set — CLI, import — would reach
                // the repeater raw and render with config defaults (absent
                // holder_role = the radio's `source`, reversing a live rule's
                // direction on resave). Re-applying here is idempotent and
                // closes that window for the admin path.
                if (($row['type'] ?? '') === 'related_post_terms_rules') {
                    $row = OptionRuleStorage::migrate_related_post_terms_shape($row);
                }
            }
            $repaired[] = $row;
        }

        $key = OptionRuleStorage::KIND_TERM;

        return self::snapshot_term_rule_labels([$key => $repaired])[$key];
    }

    /**
     * The format list's row repairs: the title backfill, and nothing else.
     *
     * No shape migration, deliberately. `title_slug_rules` moved into the
     * ordered repeater (#59) with its stored keys unchanged, so there is no
     * legacy shape to translate — and an empty migration is the honest way to
     * say so. If a future format type needs one it goes here, beside the term
     * list's three.
     *
     * @since 0.8.0
     * @param array $rows Stored `format_rules` rows.
     * @return array
     */
    private static function repair_format_rows(array $rows): array {
        $key = OptionRuleStorage::KIND_FORMAT;

        return self::snapshot_format_rule_labels([$key => $rows])[$key];
    }

    /**
     * Rewrite a hierarchical row's legacy direction/expansion pair into the
     * `inheritance_behavior` outcome the config now authors (#16).
     *
     * Only ever fills in a MISSING key — a row that already names an outcome
     * is returned untouched, and so is a row of any other type. The legacy
     * keys are dropped once translated: `RepeaterField::sanitize` would drop
     * them on the next save anyway (they are no longer declared subfields), so
     * carrying them would only make the stored shape lie about what is read.
     *
     * @since 0.8.0
     * @param array $row One term-rule row.
     * @return array
     */
    private static function migrate_inheritance_behavior(array $row): array {
        if (($row['type'] ?? '') !== 'hierarchical_rules'
            || (string) ($row['inheritance_behavior'] ?? '') !== '') {
            return $row;
        }

        if (!isset($row['hierarchy_direction']) && !isset($row['expansion_behavior'])) {
            return $row;
        }

        // behavior_key() resolves the pair through the same map the handler
        // runs on, so the migrated row behaves as the legacy one did.
        $outcome = HierarchicalHandler::behavior_key($row);

        // The one pair that names no outcome is parent_to_child + never, which
        // applies nothing at all. Preserve that as a disabled rule rather than
        // inventing a behaviour it never had.
        if ($outcome === '') {
            $row['enabled'] = false;
            $outcome        = 'descendants_smart';
        }

        $row['inheritance_behavior'] = $outcome;
        unset($row['hierarchy_direction'], $row['expansion_behavior']);

        return $row;
    }

    /**
     * Rewrite a related-term row's legacy scalar term ids into the array
     * shapes the repeater's selects render (#58).
     *
     * The admin reads the option raw, so a pre-Wireframe row storing
     * `trigger_term_id => "12"` or `target_term_id => 9` would render its
     * select EMPTY — the FormTokenField binds an array — and the next save
     * would persist that emptiness, silently disarming a live rule. The
     * handlers already tolerate both shapes at read time
     * (`normalize_rule_shape`); this is the admin half, same split as the
     * inheritance-behavior migration above.
     *
     * Rows of any other type, and rows already in array shape, are untouched.
     * The stale per-token label keys the old three-token title carried
     * (`trigger_label` / `target_label` / `scope_label`) are shed here for
     * the same reason the hierarchical migration sheds its legacy pair:
     * sanitize would drop them on the next save anyway.
     *
     * @since 0.8.0
     * @param array $row One term-rule row.
     * @return array
     */
    private static function migrate_related_term_shape(array $row): array {
        if (($row['type'] ?? '') !== 'related_rules') {
            return $row;
        }

        foreach (['trigger_term_id', 'target_term_id'] as $key) {
            if (isset($row[$key]) && !is_array($row[$key])) {
                $id        = (int) $row[$key];
                $row[$key] = $id > 0 ? [$id] : [];
            }
        }

        unset($row['trigger_label'], $row['target_label'], $row['scope_label']);

        return $row;
    }

    /**
     * The unescaped row title for one term rule, by type.
     *
     * An unrecognised or absent `type` is named rather than left blank: the
     * `type` select is `required`, so this should be unreachable through the
     * admin, but a row that somehow lacks one still has to stay findable in a
     * collapsed list.
     *
     * @param array $rule Clean rule values.
     * @return string Unescaped.
     */
    private static function term_rule_title(array $rule): string {
        switch ((string) ($rule['type'] ?? '')) {
            case 'propagation_rules':
                return self::propagation_title($rule);
            case 'time_based_rules':
                return self::time_based_title($rule);
            case 'hierarchical_rules':
                return self::hierarchical_title($rule);
            case 'hierarchical_level_restriction_rules':
                return self::level_restriction_title($rule);
            case 'related_rules':
                return self::related_title($rule);
            case 'related_post_terms_rules':
                return self::acf_reference_title($rule);
        }

        return __('(no rule type chosen)', 'meta-conductor');
    }

    /**
     * Propagation title. Schema:
     *   {Scope: }Copy {Taxonomy} terms to children ({claim})
     *   e.g. "Pages: Copy Breakers terms to children (owning)"
     *        "Copy Categories terms to children (contributing)"
     * No arrow — direction is stated in words ("to children").
     *
     * @param array $rule
     * @return string Unescaped.
     */
    private static function propagation_title(array $rule): string {
        return self::scope_prefix($rule['post_types'] ?? []) . sprintf(
            /* translators: 1: taxonomy label 2: claim */
            __('Copy %1$s terms to children (%2$s)', 'meta-conductor'),
            self::taxonomy_label($rule['taxonomy'] ?? ''),
            self::claim_label($rule['conflict_handling'] ?? 'merge')
        );
    }

    /**
     * Hierarchical inheritance title. Schema:
     *   {Scope: }Inherit {Taxonomy}: {outcome} ({depth})
     *   e.g. "Inherit Categories: ancestors (all levels)"
     *        "Pages: Inherit Shakers: ancestors and descendants (one level)"
     *
     * The outcome phrase is derived through HierarchicalHandler::behavior_key()
     * rather than read off the row, so a legacy row storing only the old
     * direction/expansion pair still gets the title its behaviour deserves
     * (#16).
     *
     * @since 0.8.0
     * @param array $rule
     * @return string Unescaped.
     */
    private static function hierarchical_title(array $rule): string {
        $outcomes = [
            'ancestors'          => __('ancestors', 'meta-conductor'),
            'descendants_smart'  => __('descendants when none picked', 'meta-conductor'),
            'descendants_always' => __('descendants', 'meta-conductor'),
            'both_smart'         => __('ancestors, and descendants when none picked', 'meta-conductor'),
            'both_always'        => __('ancestors and descendants', 'meta-conductor'),
        ];

        $key     = HierarchicalHandler::behavior_key($rule);
        $outcome = $outcomes[$key] ?? __('nothing', 'meta-conductor');

        $depth = (($rule['inheritance_depth'] ?? 'all') === 'immediate')
            ? __('one level', 'meta-conductor')
            : __('all levels', 'meta-conductor');

        return self::scope_prefix($rule['post_types'] ?? []) . sprintf(
            /* translators: 1: taxonomy label 2: what is applied 3: how far up/down the tree */
            __('Inherit %1$s: %2$s (%3$s)', 'meta-conductor'),
            self::taxonomy_label($rule['taxonomy'] ?? ''),
            $outcome,
            $depth
        );
    }

    /**
     * Level-restriction title. Schema:
     *   {Scope: }Restrict {Taxonomy} to {mode}{, keeping ancestors}
     *   e.g. "Restrict Shakers to one term per level"
     *        "Pages: Restrict Shakers to the deepest level, keeping ancestors"
     *
     * @since 0.8.0
     * @param array $rule
     * @return string Unescaped.
     */
    private static function level_restriction_title(array $rule): string {
        $modes = [
            'one_per_level'   => __('one term per level', 'meta-conductor'),
            'deepest_only'    => __('the deepest level', 'meta-conductor'),
            'shallowest_only' => __('the shallowest level', 'meta-conductor'),
        ];

        $mode = $modes[(string) ($rule['restriction_mode'] ?? 'one_per_level')]
            ?? $modes['one_per_level'];

        $title = self::scope_prefix($rule['post_types'] ?? []) . sprintf(
            /* translators: 1: taxonomy label 2: which depths may keep terms */
            __('Restrict %1$s to %2$s', 'meta-conductor'),
            self::taxonomy_label($rule['taxonomy'] ?? ''),
            $mode
        );

        // One meaning in every mode as of 0.8.0 (#32), so the clause is shown
        // whenever the flag is set rather than only in some modes.
        if (!empty($rule['include_ancestors'])) {
            $title .= __(', keeping ancestors', 'meta-conductor');
        }

        return $title;
    }

    /**
     * Leading "Post type: " prefix for a row title, shown ONLY when the rule
     * is restricted to specific post types. Empty (= applies to all) ⇒ '' so
     * the title reads as a plain sentence.
     *
     * Unescaped — every caller feeds its result through the single esc_html()
     * in snapshot_term_rule_labels().
     *
     * @param mixed $post_types Checkbox {slug:bool} map or list of slugs.
     * @return string Trailing ": " when present.
     */
    private static function scope_prefix($post_types): string {
        $labels = self::post_type_labels($post_types);
        return empty($labels) ? '' : implode(', ', $labels) . ': ';
    }

    /**
     * Assemble each General-tab claim-override row title.
     *
     * Hooked on `wp-wireframe/save/payload`. Schema:
     *   {Taxonomy}: {claim}
     *   e.g. "Categories: owning"
     *
     * Without this the repeater's `title_template` interpolates the raw
     * stored value (`category: replace`), which is the one place the claim
     * vocabulary would not reach — see CONTEXT.md → Claim, ADR 0004.
     * An unresolvable taxonomy falls back to its slug rather than an empty
     * title, since the row is still selectable and must stay identifiable.
     *
     * @param array $clean_values
     * @return array
     */
    public static function snapshot_claim_override_labels(array $clean_values): array {
        if (empty($clean_values['conflict_handling_overrides'])
            || !is_array($clean_values['conflict_handling_overrides'])) {
            return $clean_values;
        }

        foreach ($clean_values['conflict_handling_overrides'] as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $slug  = (string) ($row['taxonomy'] ?? '');
            $tax   = self::taxonomy_label($slug);
            $claim = self::claim_label($row['mode'] ?? 'merge');

            // ': ' as a literal, matching the time-based title — a
            // placeholders-and-punctuation-only string is not worth translating.
            $row['row_title'] = \esc_html(($tax !== '' ? $tax : $slug) . ': ' . $claim);
        }
        unset($row);

        return $clean_values;
    }

    /**
     * Claim label for a stored conflict_handling value.
     *
     * Shared by both surfaces that print a claim: the propagation row title
     * and the General-tab override row title.
     *
     * The mapping itself lives on ConfigHelpers::CLAIM_NAMES, which is also
     * what builds the two config dropdowns — so a claim rename touches one
     * line and cannot leave a surface stale. This method exists only to keep
     * the snapshot helpers reading a local name (replace = owning-claim,
     * merge = contributing-claim, skip = deferring-claim; the `-claim`
     * qualifier disambiguates `deferring` from defer-as-postpone).
     * See CONTEXT.md → Claim and ADR 0004.
     *
     * @param string $value merge|replace|skip.
     * @return string Unescaped label.
     */
    private static function claim_label($value): string {
        return Config\ConfigHelpers::claim_name(is_string($value) ? $value : 'merge');
    }

    /**
     * Time-based (date window) title. Date-first — the window is the most
     * salient part of a manually configured date rule — then a sentence:
     *   {start}–{end}: Apply {target} to {scope}{ with {filter}}
     *   - dates joined by an en dash, no surrounding spaces.
     *   - scope = "posts" (all types) or the post-type labels (when restricted).
     *   - filter clause only when set: specific terms → "with {Term, …}";
     *     else taxonomies → "with any {Taxonomy} term"; neither → omitted.
     *   e.g. "2026-05-26–2026-05-27: Apply Shakers: Grandchild ii to posts"
     *        "2026-05-26–2026-05-27: Apply … to Pages with Breakers: Term A"
     *
     * @param array $rule
     * @return string Unescaped.
     */
    private static function time_based_title(array $rule): string {
        $start  = (string) ($rule['start_date'] ?? '');
        $end    = (string) ($rule['end_date'] ?? '');
        $target = self::term_label($rule['target_term_id'] ?? null);

        // en dash, no surrounding spaces.
        $window = ($start !== '' || $end !== '') ? $start . "\xE2\x80\x93" . $end . ': ' : '';

        $sentence = sprintf(
            /* translators: 1: target term 2: post-type scope phrase */
            __('Apply %1$s to %2$s', 'meta-conductor'),
            $target !== '' ? $target : __('(no term)', 'meta-conductor'),
            self::time_based_scope_phrase($rule['post_types'] ?? [])
        );

        return $window . $sentence . self::time_based_filter_clause($rule);
    }

    /**
     * Scope phrase for the time-based title's "to …" clause: "posts" when the
     * rule applies to all post types (empty post_types), else the human
     * post-type labels ("Pages", "Posts, Pages"). Unescaped.
     *
     * @param mixed $post_types Checkbox {slug:bool} map or list of slugs.
     * @return string
     */
    private static function time_based_scope_phrase($post_types): string {
        $labels = self::post_type_labels($post_types);
        return empty($labels) ? __('posts', 'meta-conductor') : implode(', ', $labels);
    }

    /**
     * Filter clause for the time-based title: " with {specific terms}" when
     * filter_terms is set; else " with any {taxonomy} term" when
     * filter_taxonomies is set; else '' (no filter). Unescaped.
     *
     * @param array $rule
     * @return string
     */
    private static function time_based_filter_clause(array $rule): string {
        $terms = self::trigger_terms_label($rule['filter_terms'] ?? null);
        if ($terms !== '') {
            return ' ' . sprintf(__('with %s', 'meta-conductor'), $terms);
        }

        $taxonomies = Config\ConfigHelpers::selected_checkbox_slugs($rule['filter_taxonomies'] ?? []);
        $labels = [];
        foreach ($taxonomies as $slug) {
            $label = self::taxonomy_label((string) $slug);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        if (!empty($labels)) {
            return ' ' . sprintf(__('with any %s term', 'meta-conductor'), implode(', ', $labels));
        }

        return '';
    }

    /**
     * Resolve a raw "post_type:field_name" (or bare name) ACF relationship
     * field to its clean human label via acf_get_field(). Falls back to the
     * bare field name. (architecture.md → Canonical shape adapter)
     *
     * @param string $stored Raw option value.
     * @return string Unescaped label.
     */
    private static function acf_field_label($stored): string {
        $raw = (string) $stored;
        if ($raw === '') {
            return '';
        }
        // Strip the "post_type:" prefix the option value carries.
        $name = \str_contains($raw, ':') ? explode(':', $raw, 2)[1] : $raw;

        if (function_exists('acf_get_field')) {
            $field = \acf_get_field($name);
            if (is_array($field) && !empty($field['label'])) {
                return (string) $field['label'];
            }
        }
        return $name;
    }

    /**
     * Comma-joined human labels for a post_status gate (Wireframe {slug:bool}
     * map or list). '' when no gate set.
     *
     * @param mixed $post_status
     * @return string Unescaped.
     */
    private static function status_gate_label($post_status): string {
        $slugs = Config\ConfigHelpers::selected_checkbox_slugs($post_status);
        if (empty($slugs)) {
            return '';
        }

        $labels = [];
        foreach ($slugs as $slug) {
            $obj = \get_post_status_object((string) $slug);
            $labels[] = $obj ? $obj->label : (string) $slug;
        }
        return implode(', ', $labels);
    }

    /**
     * Resolve a single stored term ID to "<taxonomy label>: <term name>".
     *
     * Accepts a bare scalar or a single-element array. Returns '' when
     * unresolvable. Used for target_term_id (single) and as a primitive
     * for trigger_terms_label (multi).
     *
     * @param mixed $stored Term ID or single-element array.
     * @return string Unescaped label.
     */
    private static function term_label($stored): string {
        $id = is_array($stored) ? ($stored[0] ?? 0) : $stored;
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        $term = \get_term($id);
        if (!$term || \is_wp_error($term)) {
            return '';
        }

        $tax_label = self::taxonomy_label($term->taxonomy);

        return $tax_label !== '' ? $tax_label . ': ' . $term->name : $term->name;
    }

    /**
     * Build the trigger_label for a term-type rule (V7).
     *
     * Maps the int[] trigger_term_id array to individual term labels and joins
     * with ", ". Scalar and single-element-array stored values are also
     * accepted (legacy shape; normalizer converts on read but the save-payload
     * hook runs before normalize). Returns UNESCAPED text — the caller escapes
     * once at injection, matching term_label/taxonomy_label/scope_label.
     *
     * @param mixed $stored int[], scalar, or null.
     * @return string Unescaped, comma-joined label; '' if nothing resolves.
     */
    private static function trigger_terms_label($stored): string {
        $ids = is_array($stored) ? $stored : [$stored];
        $labels = [];
        foreach ($ids as $id) {
            $label = self::term_label($id);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return implode(', ', $labels);
    }

    /**
     * Resolve a Wireframe post-type checkbox value ({slug:bool} map or slug
     * list) to a flat array of human post-type labels. Unresolvable slugs (a
     * type unregistered after save) are dropped. Single source for the three
     * row-title scope formatters below. (0.6.0 review — was triplicated.)
     *
     * @param mixed $post_types
     * @return string[] Post-type labels.
     */
    private static function post_type_labels($post_types): array {
        $labels = [];
        foreach (Config\ConfigHelpers::selected_checkbox_slugs($post_types) as $slug) {
            $obj = \get_post_type_object((string) $slug);
            if ($obj) {
                $labels[] = $obj->label;
            }
        }
        return $labels;
    }

    /**
     * Post-type scope SUFFIX " (Label, Label)" for a row title, '' when the rule
     * applies to all post types. The " (" / ")" decoration lives here, not in
     * the template.
     *
     * The delimiters are deliberately BAKED INTO the stored snapshot value, not
     * applied at render: Wireframe's `title_template` can only interpolate, so
     * there is nowhere else to format. Consequence — if the row-title format
     * ever changes, already-persisted `scope_label` values keep the old shape
     * until each rule is re-saved. Same snapshot-staleness class as the term
     * labels (V11); accepted rather than fixed, since storing raw slugs would
     * require render-time formatting the template cannot do. (PR #19 review #4.)
     *
     * @param mixed $post_types
     * @return string
     */
    private static function scope_label($post_types): string {
        $labels = self::post_type_labels($post_types);
        return empty($labels) ? '' : ' (' . implode(', ', $labels) . ')';
    }

    /**
     * Resolve a taxonomy slug to its label.
     *
     * Uses the (plural) `label` to match the "Tax: Term" shape produced by
     * ConfigHelpers::all_term_options() and the user-facing examples
     * (e.g. "Shakers").
     *
     * @param string $slug
     * @return string
     */
    private static function taxonomy_label(string $slug): string {
        if ($slug === '') {
            return '';
        }

        $tax = \get_taxonomy($slug);
        if (!$tax) {
            return '';
        }

        return $tax->label ?: $slug;
    }

    /**
     * Register subpages hanging off the meta-conductor top-level menu.
     *
     * Wireframe registers the parent via add_menu_page() at admin_menu
     * priority 10; subpages hook at 11 so the parent exists.
     */
    public static function register_subpages(): void {
        if (!function_exists('acf_get_field_groups')) {
            // Conversion needs ACF; skip submenu when unavailable.
            return;
        }

        add_submenu_page(
            'meta-conductor',
            __('Data Conversion', 'meta-conductor'),
            __('Data Conversion', 'meta-conductor'),
            'manage_options',
            'meta-conductor-conversion',
            [self::class, 'render_conversion_page']
        );
    }

    /**
     * Render callback for the Data Conversion subpage.
     */
    public static function render_conversion_page(): void {
        if (!class_exists(ConversionUi::class) || !class_exists(TaxonomyManager::class)) {
            wp_die(esc_html__('Conversion components unavailable.', 'meta-conductor'));
        }

        $plugin             = TaxonomyManager::get_instance();
        $conversion_manager = method_exists($plugin, 'get_conversion_manager') ? $plugin->get_conversion_manager() : null;

        if (!$conversion_manager) {
            echo '<div class="wrap"><h1>' . esc_html__('Data Conversion', 'meta-conductor') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Conversion manager not initialized.', 'meta-conductor') . '</p></div>';
            echo '</div>';
            return;
        }

        $conversion_ui = new ConversionUi(
            $conversion_manager->get_field_mapper(),
            $conversion_manager->get_data_processor(),
            $conversion_manager->get_preview_system()
        );

        $conversion_ui->render_page();
    }

    /**
     * Boot Wireframe\App with the assembled config.
     *
     * Gated to admin + REST contexts. Front-end requests skip boot entirely —
     * BWS_Config_Helpers::all_term_options() does a full get_terms() scan
     * across every public taxonomy, which is wasted work outside the
     * settings UI and its save endpoint.
     */
    public static function boot(): void {
        if (!class_exists(\Wireframe\App::class)) {
            return;
        }

        // `REST_REQUEST` constant isn't defined until `parse_request` (well
        // after our `init` priority 10 boot), so detect REST via the URL
        // prefix instead. Both forms of permalinks supported.
        $rest_prefix = trailingslashit(rest_get_url_prefix());
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $is_rest     = str_contains($request_uri, '/' . $rest_prefix) || str_contains($request_uri, '?rest_route=');

        if (!is_admin() && !$is_rest) {
            return;
        }

        // One-time persist of the related_post_terms_rules read-time migration,
        // BEFORE Wireframe reads the option raw (it bypasses normalize_rule_shape,
        // so the form would otherwise render legacy rows with config defaults and
        // a resave would corrupt them). Flag-gated → at most one write.
        // (architecture.md → Canonical shape adapter, key-renaming caveat)
        //
        // It reads through the storage layer, which upgrades a pre-#56 option to
        // the kind-list shape on the way in — so the rewrite lands on the rows
        // the repeater is about to render, whichever shape the site arrived in.
        $storage = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
        if (method_exists($storage, 'maybe_migrate_acf_ref_storage')) {
            $storage->maybe_migrate_acf_ref_storage();
        }

        // Persist the kind-list shape (ADR 0003, #56 → #66). Runs AFTER the
        // acf-ref rewrite above so the stored lists carry already-key-renamed
        // related_post_terms rows. Writes only when the stored option differs
        // from the upgraded shape, so it is at most one write per site.
        //
        // Reads apply the same upgrade themselves, so front-end and cron
        // requests — which never reach this boot — see the same rules whether
        // or not this write has happened. The ADMIN is what needs the write:
        // it reads the option RAW, and the ordered repeater can only bind to
        // `term_rules` / `format_rules` if those keys are in storage.
        if (method_exists($storage, 'maybe_migrate_kind_lists')) {
            $storage->maybe_migrate_kind_lists();
        }

        // Bring the persisted list up to what the repeater expects to render,
        // BEFORE Wireframe reads the option raw. Runs after the sync so it
        // works on the list the admin is about to see. (#57)
        self::repair_stored_rules($storage);

        // First collision scan for a rule set that never passed through a save
        // on this page — an upgrade, a seeded fixture, a CLI import. Runs AFTER
        // the repair so it reads the shapes the repeater is about to render,
        // and BEFORE the config is built, which is what reads the findings.
        // At most one write per site (#65).
        CollisionDetector::maybe_prime();

        // WireframeConfig autoloads via PSR-4 (autoload.php) — no manual require (Phase 2a).

        // Multi-page mode with one page. The single-page menu_slug bug
        // (wp-wireframe#5) is fixed as of 1.0.6, but the `pages[]` form stays
        // — it's the natural shape for adding more Wireframe pages later.
        \Wireframe\App::boot([
            'prefix'     => 'bws-meta-conductor',
            'capability' => 'manage_options',
            'version'    => defined('META_CONDUCTOR_VERSION') ? META_CONDUCTOR_VERSION : '0.3.0',
            // Symlinked installs (local dev) resolve the package via realpath()
            // to a path outside WP_PLUGIN_DIR, so Wireframe's assetsUrl() prefix
            // match fails and emits a broken asset base. plugins_url() keyed off
            // the symlinked main-file path derives the correct URL. Wireframe
            // appends src/assets/ internally.
            'assets_url' => \plugins_url(
                'vendor/tdrayson/wp-wireframe/src/assets/',
                dirname(__DIR__, 2) . '/meta-conductor.php'
            ),
            'pages'      => [
                [
                    'id'            => 'settings',
                    'option_key'    => 'bws_meta_conductor_settings',
                    'page_title'    => __('Meta Conductor', 'meta-conductor'),
                    'menu_title'    => __('Meta Conductor', 'meta-conductor'),
                    'menu_slug'     => 'meta-conductor',
                    'menu_icon'     => 'dashicons-category',
                    'menu_position' => 80,
                    'config'        => \BWS\MetaConductor\Admin\Config\WireframeConfig::build(),
                ],
            ],
        ]);
    }
}
