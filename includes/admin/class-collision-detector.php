<?php
/**
 * Collision detection — the advisory half of the composition model (#65).
 *
 * Two rules **contend** when they write the same effect target on posts that
 * can be the same posts. The plugin says so and does nothing else: advisory at
 * authoring time, never resolved at runtime, never blocking a save. A collision
 * is not an error — it means the combined result depends on ORDER, which the
 * author now controls (ADR 0002 decision 2, ADR 0003 decision 3).
 *
 * ## What "the same thing" means here
 *
 * The acceptance criteria say *shared taxonomy plus overlapping post types*,
 * and four of the six term types can be read that way. Two cannot:
 * `time_based_rules` and `related_rules` have **no `taxonomy` subfield at all**
 * (H11's expected-visible map is the proof) — they are **term-pairing** types
 * that identify their write target by `target_term_id` alone. So the detector
 * resolves each rule's **effect target** rather than reading one field name:
 *
 *   - a whole taxonomy, for the four types that declare one;
 *   - one term, for the two term-pairing types;
 *   - the post's own title/slug fields, for the format kind.
 *
 * Keying the term-pairing types on the TERM rather than on the term's taxonomy
 * is deliberate. Two date-window rules in one taxonomy with different targets
 * need not contend, and taxonomy-level keying would warn on every pair of them
 * — an advisory that fires that often gets ignored, which is worse than none.
 *
 * ## What it deliberately does NOT do
 *
 * Targets must be **equal**, not merely nested: a taxonomy-wide rule is not
 * paired with a term rule whose term lives in that taxonomy. That pairing is
 * real (a restricting rule does prune a term another rule applied) but it is a
 * **reach** question, and reach is defined once, for every effect kind, when
 * the non-term kinds are real — with ADR 0004's second conjunct attached
 * (reaches intersect AND jurisdictions overlap). Nothing here should be read as
 * "shared reach alone implies contention".
 *
 * The claim axis is not consulted either, for the same reason: two purely
 * contributing rules cannot in fact fight, but establishing that per type is
 * the jurisdiction half of the deferred detector, not this one.
 *
 * ## Two surfaces, one detector
 *
 *   - `refresh()` on `settings_saved` recomputes from storage and PERSISTS, so
 *     the notice is there on the next load for an author who never thinks to
 *     check;
 *   - the ActionField button re-runs `detect()` over the IN-FLIGHT form values
 *     and returns straight to the open UI. That path deliberately persists
 *     nothing — the rows it read were never saved.
 *
 * @package Meta_Conductor
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Admin;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Handlers\HierarchicalHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

if (!defined('ABSPATH')) {
    exit;
}

class CollisionDetector {

    /**
     * Where the computed findings live.
     *
     * Its OWN option, not a key inside `bws_meta_conductor_settings`. The
     * settings option is what Wireframe reads raw and what the save payload
     * rewrites wholesale on every save; a derived, non-authored value sitting
     * in there would be one more thing those paths have to agree not to touch.
     * Not autoloaded — only the settings page and its save hook read it.
     */
    const OPTION_NAME = 'bws_mc_collision_warnings';

    /**
     * Rule types whose effect target is a WHOLE taxonomy, named by `taxonomy`.
     *
     * @var string[]
     */
    private const TAXONOMY_TARGET_TYPES = [
        'propagation_rules',
        'hierarchical_rules',
        'hierarchical_level_restriction_rules',
        'related_post_terms_rules',
    ];

    /**
     * Term-pairing types: the effect target is ONE term, named by
     * `target_term_id`, and no `taxonomy` subfield exists to read.
     *
     * @var string[]
     */
    private const TERM_TARGET_TYPES = [
        'time_based_rules',
        'related_rules',
    ];

    /**
     * Format types: type => the fields it writes. Every `title_slug` rule
     * writes the same two fields, so the target key is a constant per type and
     * the whole predicate reduces to post-type overlap — which is exactly the
     * handler's first-match-wins lookup, stated as a collision.
     *
     * @var array<string,string>
     */
    private const FIELD_TARGET_TYPES = [
        'title_slug_rules' => 'title_slug',
    ];

    /**
     * Register the two surfaces.
     *
     * Called from `WireframeBootstrap::init()`. The action filter names follow
     * Wireframe's `{prefix}/action/{pageId}/{fieldId}/{actionId}` shape, in
     * single-button sugar mode where the action id is the literal `run`.
     */
    public static function init(): void {
        add_action('bws-meta-conductor/settings_saved', [self::class, 'on_settings_saved'], 10, 0);

        // Reset wipes the rules the stored findings name. Without this the
        // notice keeps describing rules that no longer exist until some
        // unrelated save happens to recompute it — the one state where the
        // advisory is not merely stale but talking about nothing.
        add_action('bws-meta-conductor/settings_reset', [self::class, 'on_settings_saved'], 10, 0);

        foreach (self::kinds() as $kind) {
            add_filter(
                'bws-meta-conductor/action/settings/' . $kind . '_collision_check/run',
                function ($unhandled, $values = [], $request = null) use ($kind) {
                    return self::recheck($kind, is_array($values) ? $values : []);
                },
                10,
                3
            );
        }
    }

    /**
     * The effect kinds the detector scans, in tab order.
     *
     * @return string[]
     */
    public static function kinds(): array {
        return [OptionRuleStorage::KIND_TERM, OptionRuleStorage::KIND_FORMAT];
    }

    // ---------------------------------------------------------------------
    // The detector
    // ---------------------------------------------------------------------

    /**
     * Pairwise scan of one kind's list.
     *
     * Takes rows already through `OptionRuleStorage::project_kind_rules()` —
     * the ACF-reference row's combined `post_type:field` value has to be split
     * before its written post type can be read, and both callers project so
     * neither can drift from the other.
     *
     * Disabled rows are skipped: a rule that does not run cannot contend, and
     * warning about one would train the author to ignore the notice. A row with
     * no `enabled` key counts as enabled, matching `filter_rules()`.
     *
     * @since 0.8.0
     * @param string $kind KIND_TERM or KIND_FORMAT.
     * @param array  $rows Projected kind-list rows, in authored order.
     * @return array[] Findings, in list order.
     */
    public static function detect(string $kind, array $rows): array {
        $facts = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row) || !($row['enabled'] ?? true)) {
                continue;
            }

            $type = (string) ($row['type'] ?? '');
            $key  = self::target_key($type, $row);

            // No resolvable target — an untyped row, or one whose taxonomy or
            // target term has not been picked yet. Half-authored rules are not
            // collisions, they are unfinished.
            if ($key === null) {
                continue;
            }

            $facts[] = [
                'index'      => $index,
                'type'       => $type,
                'row'        => $row,
                'key'        => $key,
                'post_types' => self::written_post_types($type, $row),
            ];
        }

        $found = [];
        $count = count($facts);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $facts[$i];
                $b = $facts[$j];

                if ($a['key'] !== $b['key']) {
                    continue;
                }
                if (!self::post_types_overlap($a['post_types'], $b['post_types'])) {
                    continue;
                }

                $found[] = self::finding($kind, $a, $b);
            }
        }

        return $found;
    }

    /**
     * The effect target a rule writes, as a comparable key.
     *
     * Null ⇒ nothing to compare, so the row takes no part in the scan.
     *
     * @since 0.8.0
     * @param string $type Legacy rule-type key.
     * @param array  $rule Projected row.
     * @return string|null
     */
    public static function target_key(string $type, array $rule): ?string {
        if (in_array($type, self::TAXONOMY_TARGET_TYPES, true)) {
            $taxonomy = trim((string) ($rule['taxonomy'] ?? ''));

            return $taxonomy === '' ? null : 'taxonomy|' . $taxonomy;
        }

        if (in_array($type, self::TERM_TARGET_TYPES, true)) {
            $stored = $rule['target_term_id'] ?? 0;
            // Canonical shape is a scalar int, but the on-demand path sees the
            // form's own value before `normalize_rule_shape()` has touched it,
            // and the control is a FormTokenField that posts [N].
            $term_id = is_array($stored) ? (int) (reset($stored) ?: 0) : (int) $stored;

            return $term_id <= 0 ? null : 'term|' . $term_id;
        }

        if (isset(self::FIELD_TARGET_TYPES[$type])) {
            // A format rule with no post type picked writes NOTHING —
            // `TitleSlugHandler::rule_matches()` requires the key to be
            // non-empty. Without this the target key is a constant, so the
            // half-authored skip above could never fire for this kind and a
            // blank new row would be reported as colliding with every other
            // format row, in "the lower one never runs" wording.
            return trim((string) ($rule['post_type'] ?? '')) === ''
                ? null
                : 'fields|' . self::FIELD_TARGET_TYPES[$type];
        }

        return null;
    }

    /**
     * The post types a rule WRITES — not the ones it looks at.
     *
     * Empty ⇒ every post type, which is what makes the overlap test permissive
     * in the right direction (over-report, never under-report).
     *
     * Two types do not answer this with `post_types`:
     *   - the format types name one post type in a scalar `post_type`;
     *   - the ACF-reference rule has no `post_types` subfield at all (#58) and
     *     writes its DEPENDENT end. Under `holder_role = source` (push) the
     *     dependents are the related posts, whose type the rule never
     *     constrains — so "all", exactly as `dependent_post_type()` reports it.
     *
     * @since 0.8.0
     * @param string $type Legacy rule-type key.
     * @param array  $rule Projected row.
     * @return string[] Post-type slugs; [] = every post type.
     */
    public static function written_post_types(string $type, array $rule): array {
        if (isset(self::FIELD_TARGET_TYPES[$type])) {
            // Never [] here: an empty `post_type` resolves no target at all
            // (see target_key), so a row that reaches this line has one. If one
            // ever did not, `['']` intersects nothing — the safe failure —
            // where [] would claim every post type.
            return [trim((string) ($rule['post_type'] ?? ''))];
        }

        if ($type === 'related_post_terms_rules') {
            if ((string) ($rule['holder_role'] ?? 'target') === 'source') {
                return [];
            }
            $post_type = trim((string) ($rule['post_type'] ?? ''));

            return $post_type === '' ? [] : [$post_type];
        }

        $slugs = ConfigHelpers::selected_checkbox_slugs($rule['post_types'] ?? []);

        // `any` is should_process_post's sentinel for "don't gate", so it has to
        // read as every post type here too, not as a post type named "any".
        if ($slugs === [] || ($slugs[0] ?? '') === 'any') {
            return [];
        }

        return array_values($slugs);
    }

    /**
     * Post-type overlap, where empty means "every post type".
     *
     * @since 0.8.0
     * @param string[] $a
     * @param string[] $b
     * @return bool
     */
    public static function post_types_overlap(array $a, array $b): bool {
        if ($a === [] || $b === []) {
            return true;
        }

        return array_intersect($a, $b) !== [];
    }

    // ---------------------------------------------------------------------
    // Diagnosis
    // ---------------------------------------------------------------------

    /**
     * Assemble one finding, resolving the labels while the terms and taxonomies
     * are in front of us. Labels are SNAPSHOT into the finding for the same
     * reason row titles are (architecture.md → The ordered rule repeaters):
     * the notice is rendered from persisted
     * data on a later request, and re-resolving there would let the notice and
     * the row title disagree about what a term is called.
     *
     * @param string $kind
     * @param array  $a Left fact.
     * @param array  $b Right fact.
     * @return array
     */
    private static function finding(string $kind, array $a, array $b): array {
        [$code, $first, $second] = self::diagnose($a, $b);

        return [
            'kind'   => $kind,
            'code'   => $code,
            'a'      => self::rule_ref($first),
            'b'      => self::rule_ref($second),
            'target' => self::target_label($a['key']),
            'scope'  => self::scope_label($a['post_types'], $b['post_types']),
        ];
    }

    /**
     * Which contradiction, if the pair is precisely diagnosable (#51's second
     * task) — and the two rules in the order the MESSAGE needs them.
     *
     * The order matters because the diagnosable templates are asymmetric:
     * `%1$s` is the rule that ADDS a lineage and `%2$s` the one that does not
     * keep it. `a`/`b` are therefore ordered by ROLE for those codes, not by
     * list position — with the restriction row authored above the hierarchy
     * row, list order would make the notice say the restriction rule adds
     * ancestors, which is exactly backwards. The list order is not lost: each
     * side carries its own `index`, the snapshot title leads with that same
     * position, and the "lower in the list acts last" sentence refers to it.
     *
     * Everything else falls back to `shared_target`, which is still a true
     * statement — just a less useful one — and keeps list order, where the two
     * sides are interchangeable.
     *
     * @param array $a
     * @param array $b
     * @return array{0:string,1:array,2:array}
     */
    private static function diagnose(array $a, array $b): array {
        $pair = self::as_pair($a, $b, 'hierarchical_rules', 'hierarchical_level_restriction_rules');

        if ($pair !== null) {
            [$adds, $restricts] = $pair;

            // #51: one adds a lineage, the other does not undertake to keep it.
            if (self::adds_ancestors($adds['row']) && !self::keeps_ancestors($restricts['row'])) {
                return ['ancestors_stripped', $adds, $restricts];
            }
            if (self::adds_descendants($adds['row']) && !self::keeps_descendants($restricts['row'])) {
                return ['descendants_stripped', $adds, $restricts];
            }
        }

        // #69 / ADR 0001: two rules over one term, each of which removes that
        // term when its own condition stops holding — including one the other
        // just applied. Mutual annihilation, not merely order-dependence.
        if (str_starts_with($a['key'], 'term|') && self::removes($a) && self::removes($b)) {
            return ['shared_term_cancels', $a, $b];
        }

        // The format pass stops at the FIRST rule of a type matching the post's
        // type, so the lower rule is not merely outranked — it never runs.
        if (str_starts_with($a['key'], 'fields|')) {
            return ['first_match_wins', $a, $b];
        }

        return ['shared_target', $a, $b];
    }

    /**
     * Order a pair by rule type, or null when the pair is not that pair.
     *
     * @param array  $a
     * @param array  $b
     * @param string $first_type
     * @param string $second_type
     * @return array{0:array,1:array}|null
     */
    private static function as_pair(array $a, array $b, string $first_type, string $second_type): ?array {
        if ($a['type'] === $first_type && $b['type'] === $second_type) {
            return [$a, $b];
        }
        if ($b['type'] === $first_type && $a['type'] === $second_type) {
            return [$b, $a];
        }

        return null;
    }

    /**
     * The hierarchy rule's outcome — delegated, never re-derived.
     *
     * `HierarchicalHandler::behavior_key()` is the single answer to "what does
     * this row actually do", legacy `hierarchy_direction`/`expansion_behavior`
     * pair included, and it is already what the row-title snapshot reads. A
     * second switch here is the drift CLAUDE.md don't 6d(c) warns about, and it
     * would be wrong in two places at once: on a legacy `both` row (which adds
     * descendants as well) and on the degenerate `parent_to_child` + `never`
     * pair, which applies nothing at all and must therefore contradict nothing.
     * `behavior_key()` returns '' for that one, so both predicates below say no.
     *
     * @param array $row
     * @return string One of the five outcome keys, or '' for a rule that
     *                applies nothing.
     */
    private static function inheritance_behavior(array $row): string {
        return HierarchicalHandler::behavior_key($row);
    }

    private static function adds_ancestors(array $row): bool {
        return in_array(
            self::inheritance_behavior($row),
            ['ancestors', 'both_smart', 'both_always'],
            true
        );
    }

    private static function adds_descendants(array $row): bool {
        return in_array(
            self::inheritance_behavior($row),
            ['descendants_smart', 'descendants_always', 'both_smart', 'both_always'],
            true
        );
    }

    /**
     * Does the level-restriction rule undertake to keep ancestor terms?
     *
     * `include_ancestors` says so outright in every mode (#32). Failing that,
     * only `shallowest_only` keeps them as a by-product — it prunes the deep
     * end. `one_per_level` keeps an ancestor only while nothing else occupies
     * its level, which is not an undertaking.
     *
     * @param array $row
     * @return bool
     */
    private static function keeps_ancestors(array $row): bool {
        if (!empty($row['include_ancestors'])) {
            return true;
        }

        return (string) ($row['restriction_mode'] ?? 'one_per_level') === 'shallowest_only';
    }

    /**
     * Does the level-restriction rule undertake to keep descendant terms?
     *
     * Only `deepest_only` does. `include_ancestors` is irrelevant here — it
     * adds a lineage upward, never downward.
     *
     * @param array $row
     * @return bool
     */
    private static function keeps_descendants(array $row): bool {
        return (string) ($row['restriction_mode'] ?? 'one_per_level') === 'deepest_only';
    }

    /**
     * Can this rule REMOVE its target term?
     *
     * A date-window rule always can — its out-of-range branch strips the target
     * whoever applied it, which is the retroactive ownership ADR 0001 buys
     * deliberately. A related-term rule only can when `bidirectional` is on;
     * add-only rules compose by union and cannot cancel anything.
     *
     * @param array $fact
     * @return bool
     */
    private static function removes(array $fact): bool {
        if ($fact['type'] === 'related_rules') {
            return !empty($fact['row']['bidirectional']);
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Labels
    // ---------------------------------------------------------------------

    /**
     * @param array $fact
     * @return array{index:int,type:string,title:string}
     */
    private static function rule_ref(array $fact): array {
        return [
            'index' => $fact['index'],
            'type'  => $fact['type'],
            'title' => self::rule_title($fact),
        ];
    }

    /**
     * The row's own snapshot title, or its position when it has none — a row
     * added in the UI and never saved has no `row_title` yet, and the
     * on-demand check is exactly the surface that sees those.
     *
     * The snapshot title already LEADS with the row's position ("#3 …"), which
     * is why `message()` no longer appends one: the sentence would have read
     * "#6 Foo" (#6), and for a title-less row "Rule 6" (#6). The bare "#6" here
     * is exactly what Wireframe renders for such a row, so both fallbacks
     * agree with the list.
     *
     * DECODED, because `row_title` is stored ALREADY `esc_html()`-ed: the
     * repeater's `title_template` renders raw, so the snapshot escapes on the
     * way in. Every other label here is plain text, and `message()` is a
     * plain-text API that one caller escapes — so a stored title carried
     * through as-is would reach the page double-escaped and a rule called
     * "Q&A" would read "Q&amp;A".
     *
     * @param array $fact
     * @return string Unescaped.
     */
    private static function rule_title(array $fact): string {
        $title = trim(wp_specialchars_decode(
            (string) ($fact['row']['row_title'] ?? ''),
            ENT_QUOTES
        ));

        if ($title !== '') {
            return $title;
        }

        /* translators: %d: the rule's position in the ordered list. */
        return sprintf(__('#%d', 'meta-conductor'), $fact['index'] + 1);
    }

    /**
     * Human name for an effect target key.
     *
     * @param string $key Target key from target_key().
     * @return string Unescaped.
     */
    private static function target_label(string $key): string {
        [$scheme, $value] = array_pad(explode('|', $key, 2), 2, '');

        if ($scheme === 'taxonomy') {
            $taxonomy = get_taxonomy($value);

            return ($taxonomy && $taxonomy->label) ? $taxonomy->label : $value;
        }

        if ($scheme === 'term') {
            $term = get_term((int) $value);

            if (!$term || is_wp_error($term)) {
                /* translators: %d: term ID of a term that no longer exists. */
                return sprintf(__('term #%d', 'meta-conductor'), (int) $value);
            }

            $taxonomy = get_taxonomy($term->taxonomy);
            $prefix   = ($taxonomy && $taxonomy->label) ? $taxonomy->label . ': ' : '';

            return $prefix . $term->name;
        }

        return __('the title and slug', 'meta-conductor');
    }

    /**
     * Human name for the post types the two rules share.
     *
     * An empty list on either side means "every post type", so the shared scope
     * is then the OTHER side's list — and '' only when neither side narrows.
     *
     * An unresolvable slug falls back to the SLUG rather than being dropped, as
     * `target_label()` does for a taxonomy. Dropping it would turn a narrow
     * scope into '' and `message()` renders '' as "every post type" — the
     * broadest possible claim from the narrowest possible rule. It is reachable
     * without any post type being unregistered: `maybe_prime()` runs inside the
     * `init` priority 10 boot, so a CPT registered later in `init` is not there
     * yet, and the finding it mislabelled would then be persisted indefinitely.
     *
     * @param string[] $a
     * @param string[] $b
     * @return string Unescaped; '' means every post type.
     */
    private static function scope_label(array $a, array $b): string {
        $shared = ($a === [] || $b === [])
            ? array_unique(array_merge($a, $b))
            : array_intersect($a, $b);

        $labels = [];
        foreach ($shared as $slug) {
            $object   = get_post_type_object((string) $slug);
            $labels[] = $object ? $object->label : (string) $slug;
        }

        return implode(', ', $labels);
    }

    /**
     * One finding as a sentence.
     *
     * Composed at RENDER time, not baked into the stored finding, so neither the
     * wording nor its translation is frozen by a save. The labels it
     * interpolates are the snapshot ones.
     *
     * @since 0.8.0
     * @param array $finding
     * @return string Unescaped.
     */
    public static function message(array $finding): string {
        $a_title = (string) ($finding['a']['title'] ?? '');
        $b_title = (string) ($finding['b']['title'] ?? '');
        $target  = (string) ($finding['target'] ?? '');
        $scope   = (string) ($finding['scope'] ?? '');
        $scope   = $scope === '' ? __('every post type', 'meta-conductor') : $scope;
        $order   = __('The rule lower in the list acts last, so it decides the result.', 'meta-conductor');

        switch ((string) ($finding['code'] ?? '')) {
            case 'ancestors_stripped':
                return sprintf(
                    /* translators: 1: rule name, 2: rule name, 3: taxonomy, 4: post types, 5: order sentence. */
                    __('“%1$s” adds ancestor terms in %3$s and “%2$s” does not keep them, on %4$s. The two want opposite results. %5$s', 'meta-conductor'),
                    $a_title, $b_title, $target, $scope, $order
                );

            case 'descendants_stripped':
                return sprintf(
                    /* translators: 1: rule name, 2: rule name, 3: taxonomy, 4: post types, 5: order sentence. */
                    __('“%1$s” adds descendant terms in %3$s and “%2$s” does not keep them, on %4$s. The two want opposite results. %5$s', 'meta-conductor'),
                    $a_title, $b_title, $target, $scope, $order
                );

            case 'shared_term_cancels':
                return sprintf(
                    /* translators: 1: rule name, 2: rule name, 3: term, 4: post types, 5: order sentence. */
                    __('“%1$s” and “%2$s” both apply and remove %3$s on %4$s. Either rule removes that term whoever applied it, so the two can cancel each other out. %5$s', 'meta-conductor'),
                    $a_title, $b_title, $target, $scope, $order
                );

            case 'first_match_wins':
                return sprintf(
                    /* translators: 1: rule name, 2: rule name, 3: what is written, 4: post types. */
                    __('“%1$s” and “%2$s” both write %3$s on %4$s. Only the higher rule runs — the lower one never does.', 'meta-conductor'),
                    $a_title, $b_title, $target, $scope
                );
        }

        return sprintf(
            /* translators: 1: rule name, 2: rule name, 3: taxonomy or term, 4: post types, 5: order sentence. */
            __('“%1$s” and “%2$s” both write %3$s on %4$s. %5$s', 'meta-conductor'),
            $a_title, $b_title, $target, $scope, $order
        );
    }

    // ---------------------------------------------------------------------
    // Surface 1 — computed at save, persisted, rendered on next load
    // ---------------------------------------------------------------------

    /**
     * Every stored finding, keyed by effect kind.
     *
     * @since 0.8.0
     * @return array<string,array[]>
     */
    public static function stored(): array {
        $stored = get_option(self::OPTION_NAME, null);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @since 0.8.0
     * @param string $kind
     * @return array[]
     */
    public static function stored_for(string $kind): array {
        $stored = self::stored();

        return is_array($stored[$kind] ?? null) ? $stored[$kind] : [];
    }

    /**
     * Recompute both kinds from storage and persist.
     *
     * The rules are re-read rather than taken from the save payload: a save
     * from the General tab carries no rule list at all, and the findings must
     * still describe the whole rule set afterwards.
     *
     * @since 0.8.0
     * @return array<string,array[]>
     */
    public static function refresh(): array {
        $storage = StorageFactory::get_instance();

        // The settings option was written moments ago by the save this runs at
        // the end of; the instance is still holding the pre-save copy.
        $storage->clear_cache();

        $found = [];
        foreach (self::kinds() as $kind) {
            $found[$kind] = self::detect($kind, $storage->get_kind_rules($kind));
        }

        update_option(self::OPTION_NAME, $found, false);

        return $found;
    }

    /**
     * Recompute on save. Advisory only — the save has already happened and
     * nothing here can undo it.
     *
     * @since 0.8.0
     * @return void
     */
    public static function on_settings_saved(): void {
        self::refresh();
    }

    /**
     * First computation for a rule set that has never been saved through this
     * page — an upgrade, a seeded fixture, an import.
     *
     * Self-limiting: the option exists from the first run, so this is at most
     * one extra write per site, not one per admin load.
     *
     * @since 0.8.0
     * @return void
     */
    public static function maybe_prime(): void {
        if (is_array(get_option(self::OPTION_NAME, null))) {
            return;
        }

        self::refresh();
    }

    // ---------------------------------------------------------------------
    // Surface 2 — the on-demand re-check
    // ---------------------------------------------------------------------

    /**
     * Run the detector over the IN-FLIGHT form values and answer the open UI.
     *
     * Persists nothing: the rows it just read were never saved, so writing them
     * into the notice would make the passive surface describe a rule set that
     * does not exist. Those rows are also unsnapshotted, so titles are baked
     * here through the same snapshot the save path uses.
     *
     * @since 0.8.0
     * @param string $kind
     * @param array  $values Sanitized in-flight values for the whole page.
     * @return array Wireframe action response.
     */
    public static function recheck(string $kind, array $values): array {
        if (is_array($values[$kind] ?? null)) {
            $rows = $kind === OptionRuleStorage::KIND_FORMAT
                ? WireframeBootstrap::snapshot_format_rule_labels([$kind => $values[$kind]])[$kind]
                : WireframeBootstrap::snapshot_term_rule_labels([$kind => $values[$kind]])[$kind];
            $rows = OptionRuleStorage::project_kind_rules($rows);
        } else {
            // The list was not posted. Fall back to what is stored rather than
            // reporting "no collisions" about rules we were never shown.
            $rows = StorageFactory::get_instance()->get_kind_rules($kind);
        }

        $found = self::detect($kind, $rows);

        if ($found === []) {
            return [
                'status'  => 'success',
                'message' => __('No collisions in this list.', 'meta-conductor'),
            ];
        }

        return [
            'status'  => 'warning',
            'message' => sprintf(
                /* translators: %d: number of rule collisions found. */
                _n('%d collision found.', '%d collisions found.', count($found), 'meta-conductor'),
                count($found)
            ),
            'html'    => self::findings_html($found),
        ];
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /**
     * The collision section for one rule tab: the persisted notice (when there
     * is one) and the re-check button.
     *
     * One builder for both tabs — "one detector" is a claim about the rendering
     * too, and a second copy of this is where the two tabs' wording would start
     * to drift.
     *
     * @since 0.8.0
     * @param string $kind
     * @return array Wireframe section.
     */
    public static function section(string $kind): array {
        $found  = self::stored_for($kind);
        $fields = [];

        if ($found !== []) {
            $fields[] = [
                'id'      => $kind . '_collision_notice',
                'type'    => 'html',
                'columns' => 12,
                'args'    => [
                    'variant' => 'warning',
                    'content' => self::notice_html($found),
                ],
            ];
        }

        $fields[] = [
            'id'      => $kind . '_collision_check',
            'type'    => 'action',
            'columns' => 12,
            // Explicit empty label, not an omitted one: the client normalizes
            // `label ?? id` and would render the field id as a heading
            // ("TERM_RULES_COLLISION_CHECK"). '' passes the ?? and the control
            // then renders no label at all — the button names itself.
            'label'   => '',
            'args'    => [
                'button_label' => __('Check for collisions', 'meta-conductor'),
                'variant'      => 'secondary',
            ],
        ];

        return [
            'id'          => $kind . '_collisions',
            'title'       => __('Rule collisions', 'meta-conductor'),
            'description' => __('Two rules collide when they write the same thing on posts that can be the same posts. That is not an error — it means their combined result depends on the order below, which you control. Nothing here blocks a save or changes what the rules do.', 'meta-conductor'),
            'fields'      => $fields,
        ];
    }

    /**
     * The persisted notice's body.
     *
     * @param array[] $found
     * @return string Escaped HTML.
     */
    private static function notice_html(array $found): string {
        $count = count($found);

        return '<p><strong>' . esc_html(sprintf(
            /* translators: %d: number of rule collisions found. */
            _n('%d rule collision', '%d rule collisions', $count, 'meta-conductor'),
            $count
        )) . '</strong> ' . esc_html__('found when these settings were last saved.', 'meta-conductor') . '</p>'
            . self::findings_html($found);
    }

    /**
     * Findings as a list. Everything interpolated is author- or term-derived,
     * so it is escaped here — the client renders returned `html` raw.
     *
     * @param array[] $found
     * @return string Escaped HTML.
     */
    private static function findings_html(array $found): string {
        $items = '';

        foreach ($found as $finding) {
            $items .= '<li>' . esc_html(self::message($finding)) . '</li>';
        }

        return '<ul>' . $items . '</ul>';
    }
}
