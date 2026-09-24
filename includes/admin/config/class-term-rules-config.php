<?php
/**
 * The ordered term-rule list — one repeater for every rule whose effect is
 * "terms on a post" (ADR 0003 decision 1, spec #53 §2, ticket #57).
 *
 * Replaces the per-type sections that used to be spread across the Auto-Set
 * and Restrict tabs. One list, authored order, each row carrying its own
 * `type`; the type-specific subfields are `conditions`-gated on it. Restrict
 * stops being a tab of its own because a level-restriction rule writes terms
 * like everything else here — putting it in the same ordered list is the
 * point of the model, not a tidy-up.
 *
 * ## The hazard this file is written around
 *
 * Wireframe DROPS a condition-hidden subfield at sanitize
 * (`RepeaterField::sanitize`, and `Validator::validateRepeater` skips it too).
 * A wrong `conditions` clause is therefore SILENT DATA LOSS, not a rendering
 * bug — the field vanishes from the payload and the merge into saved state
 * never restores it. Two consequences run through everything below:
 *
 *   1. Every show/hide combination is verified on the testbed, not reasoned
 *      about. `tests/verify-term-rules-config.php` (H11) locks the static
 *      half — unique ids, a gate on every type-specific subfield, no gate on
 *      the shared ones — but only a real save proves the round-trip.
 *   2. Changing a row's `type` DISCARDS that row's type-specific values, by
 *      the same mechanism. That is correct behaviour (a propagation rule's
 *      claim is meaningless on a date-window rule) but it is silent, so the
 *      `type` select says so in its own description.
 *
 * ## Which types live here
 *
 * `OptionRuleStorage::migrated_types_for_kind()` is the single source of
 * truth, shared with the storage layer so the set of types this repeater
 * offers and the set storage treats as authored cannot drift. Batch 1 (#57)
 * was the four types not live on any real site; #58 moved the two live ones
 * (`related_rules`, `related_post_terms_rules`) in, completing the collapse.
 * Their rows exist in real data, so two shapes are load-bearing: the ACF
 * field select's combined "post_type:field_name" value round-trips WHOLE
 * (the handler splits at read time; persisting the split would break the
 * select's option keys), and a legacy-shaped stored row must render with its
 * own values, never config defaults (WireframeBootstrap::repair_stored_rules).
 *
 * The stored `type` value is the LEGACY TYPE KEY VERBATIM
 * (`hierarchical_rules`, not `hierarchical`) — rule-type renaming stays
 * deferred (ADR 0002/0003), and reusing the key is what lets every handler's
 * `get_enabled_rules()` filter on `get_rule_type()` with no mapping table.
 * The author-facing labels below are this file's business; the stored values
 * are not.
 *
 * @package BWS_Meta_Manager
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Admin\Config;

use BWS\MetaConductor\Admin\CollisionDetector;
use BWS\MetaConductor\Storage\OptionRuleStorage;

if (!defined('ABSPATH')) {
    exit;
}

class TermRulesConfig {

    /**
     * Author-facing label for each rule type the repeater offers.
     *
     * Keyed by the stored legacy type key. Every key in
     * OptionRuleStorage::config_migrated_types() that belongs to the term
     * kind must appear here — H11 asserts the two agree, so adding a type to
     * storage without labelling it fails the harness rather than rendering an
     * unlabelled option.
     *
     * @return array<string,string>
     */
    private static function type_labels(): array {
        return [
            'propagation_rules'                    => __('From parent post — cascade terms to children', 'meta-conductor'),
            'related_post_terms_rules'             => __('From referenced post (ACF) — copy terms across a relationship field', 'meta-conductor'),
            'time_based_rules'                     => __('Date window — apply a term while a date range is current', 'meta-conductor'),
            'related_rules'                        => __('Related term — a trigger term applies a target term', 'meta-conductor'),
            'hierarchical_rules'                   => __('Hierarchy — inherit terms up or down the taxonomy tree', 'meta-conductor'),
            'hierarchical_level_restriction_rules' => __('Level restriction — limit which tree depths may hold terms', 'meta-conductor'),
        ];
    }

    /**
     * The rule types this repeater authors, in the order storage lists them.
     *
     * @return string[]
     */
    public static function types(): array {
        return array_values(array_intersect(
            OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_TERM),
            array_keys(self::type_labels())
        ));
    }

    /**
     * `type` select options: stored legacy key => author-facing label.
     *
     * Carries a leading empty placeholder so a freshly added row starts
     * untyped, with every type-specific subfield hidden — "pick what this
     * rule does first" — and `required` then rejects the save if it is still
     * untyped, rather than the row being silently dropped at fan-out.
     *
     * @return array<string,string>
     */
    public static function type_options(): array {
        $labels  = self::type_labels();
        $options = ['' => __('— Select rule type —', 'meta-conductor')];

        foreach (self::types() as $type) {
            $options[$type] = $labels[$type];
        }

        return $options;
    }

    /**
     * A `conditions` clause restricting a subfield to the given rule types.
     *
     * Always `in` with an explicit list, never `not_in`: a subfield gated by
     * exclusion silently becomes visible on every type added later, and a
     * subfield that is visible where nothing reads it stores junk — whereas a
     * missing `in` entry merely hides a field, which is caught the first time
     * the type is exercised on the testbed.
     *
     * @param string ...$types Legacy type keys the subfield belongs to.
     */
    private static function only(string ...$types): array {
        return [
            'field'    => 'type',
            'operator' => 'in',
            'value'    => $types,
        ];
    }

    /**
     * The Auto-Set & Restrict tab: the ordered term-rule list, complete as of
     * #58 — every term rule type is a row here.
     */
    public static function tab(): array {
        return [
            'id'       => 'auto-set',
            'title'    => __('Auto-Set & Restrict', 'meta-conductor'),
            'sections' => [
                // The collision advisory leads the tab (#65) — a warning about
                // the list is useless below the list. It is its own SECTION,
                // not a field prepended to the rule section, so `section()`
                // stays "the ordered list and nothing else" for H11 and for
                // the reader.
                CollisionDetector::section(OptionRuleStorage::KIND_TERM),
                self::section(),
            ],
        ];
    }

    /**
     * The ordered term-rule list section.
     */
    public static function section(): array {
        return [
            'id'          => 'term_rules',
            'title'       => __('Term rules, in order', 'meta-conductor'),
            'description' => __('Every rule that sets or restricts terms on a post, in the order they run. Drag a rule up to let it act before the ones below it.', 'meta-conductor'),
            'fields'      => [
                [
                    'id'    => OptionRuleStorage::KIND_TERM,
                    'type'  => 'repeater',
                    'label' => __('Term rules', 'meta-conductor'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add term rule', 'meta-conductor'),
                        'empty_message'  => __('No term rules configured.', 'meta-conductor'),
                        'title_template' => '{row_title}',
                        'subfields'      => self::subfields(),
                    ],
                ],
                ConfigHelpers::apply_page_note('term_rules_apply_note'),
            ],
        ];
    }

    /**
     * The row's subfields: what every rule shares, then a gated group per
     * rule type that needs one, then the row-title snapshot.
     *
     * Propagation has no group of its own — every field it ever had is now a
     * shared one, which is the clearest evidence the unification was real
     * rather than a re-shuffle.
     *
     * Order matters to the author, not to storage — `type` leads because
     * nothing else in the row means anything until it is picked.
     *
     * @return array[]
     */
    private static function subfields(): array {
        return array_merge(
            self::shared_subfields(),
            self::time_based_subfields(),
            self::hierarchical_subfields(),
            self::level_restriction_subfields(),
            self::related_subfields(),
            self::related_post_terms_subfields(),
            [
                // Snapshot row title (V11/§I.label). Not user-editable;
                // assembled at save by WireframeBootstrap::snapshot_term_rule_labels.
                // Declared so {row_title} resolves — and declared ONCE for
                // every type, which is what finally gives the four types here
                // a uniform "[Disabled] " prefix (#30's interim follow-up).
                [
                    'id'      => 'row_title',
                    'type'    => 'hidden',
                    'default' => '',
                ],
            ]
        );
    }

    /**
     * The subfields the rule types share, rather than each declaring its own.
     *
     * `enabled`, `taxonomy`, `post_types`, `post_status` and claim are the
     * superset the per-type repeaters had between them, and unifying them is
     * half the point of the collapse: **one meaning, one id, one builder**.
     * That is what "shared" means here — shared *provenance*, not necessarily
     * shared *scope*.
     *
     * Two of them are UNGATED and show on every type (`enabled` and
     * `post_status`, alongside `type` and `row_title`). H11 asserts exactly
     * those carry no `conditions` key, because a subfield that every type
     * reads must never acquire a gate: it would start dropping its value on
     * save for every type outside it.
     *
     * Three are gated, and deliberately, because a rule type that never reads
     * a value should not be offered it — an inert control stores junk and
     * reads as a setting that does something:
     *   - `taxonomy` — the date window's taxonomy is implied by the term it
     *     applies, and the related-term rule names a trigger and a target
     *     instead of one taxonomy.
     *   - `post_types` (#58) — the ACF-reference rule's post type is PINNED
     *     by the relationship field it monitors; its handler never consults
     *     `post_types`. Every other type reads it via should_process_post.
     *     The gate lists all five reader types, so nothing that reads the
     *     value can lose it on save.
     *   - claim (`conflict_handling`) — propagation is the only type here
     *     whose handler reads it. The others have a claim fixed in code,
     *     which #54 is where it gets stated.
     * All still come from the shared builders, and their ids and meanings
     * are the unified ones. H11 pins which subfields each type sees.
     *
     * @return array[]
     */
    private static function shared_subfields(): array {
        return [
            [
                'id'          => 'type',
                'type'        => 'select',
                'label'       => __('Rule type', 'meta-conductor'),
                // The type-change warning belongs here, next to the control
                // that causes it, not only in the section intro.
                'description' => __('What this rule does. Changing it clears the settings that belonged to the old type — they are not kept in the background.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => self::type_options(),
                ],
            ],
            [
                'id'      => 'enabled',
                'type'    => 'toggle',
                'label'   => __('Enabled', 'meta-conductor'),
                'default' => true,
                'columns' => 12,
            ],
            [
                // Gated to the types that scope by ONE taxonomy. The date
                // window's taxonomy is implied by the term it applies, and
                // the related-term rule names a trigger and a target instead.
                // Gating rather than showing an inert select is the whole
                // reason subfield conditions exist — but note the consequence:
                // a row switched to a date window loses its taxonomy, which is
                // correct and is what the `type` description warns about.
                'id'          => 'taxonomy',
                'type'        => 'select',
                'label'       => __('Taxonomy', 'meta-conductor'),
                'description' => __('The taxonomy this rule reads and writes.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'conditions'  => self::only(
                    'propagation_rules',
                    'hierarchical_rules',
                    'hierarchical_level_restriction_rules',
                    'related_post_terms_rules'
                ),
                'args'        => [
                    'options' => ConfigHelpers::taxonomy_options(),
                ],
            ],
            [
                'id'         => 'hierarchical_taxonomy_note',
                'type'       => 'html',
                'columns'    => 12,
                'conditions' => self::only('hierarchical_rules', 'hierarchical_level_restriction_rules'),
                'args'       => [
                    'variant' => 'info',
                    'content' => '<p>' . esc_html__('This rule type only acts on a hierarchical taxonomy — one whose terms have parents. Pointed at a flat taxonomy it does nothing.', 'meta-conductor') . '</p>',
                ],
            ],
            [
                // The one nuance the shared taxonomy select's generic
                // description cannot carry for the ACF-reference rule.
                'id'         => 'acf_taxonomy_note',
                'type'       => 'html',
                'columns'    => 12,
                'conditions' => self::only('related_post_terms_rules'),
                'args'       => [
                    'variant' => 'info',
                    'content' => '<p>' . esc_html__('Terms are copied in this taxonomy, by ID — the same taxonomy on both ends of the relationship.', 'meta-conductor') . '</p>',
                ],
            ],
            // Gated (#58): the ACF-reference rule's post type is pinned by the
            // relationship field it monitors — its handler never reads
            // `post_types`, so offering the checkboxes there would store junk
            // that reads as a working scope. Every type that DOES read the
            // value is inside the gate, so nothing loses its scope on save.
            ConfigHelpers::post_types_field([
                'conditions' => self::only(
                    'propagation_rules',
                    'time_based_rules',
                    'related_rules',
                    'hierarchical_rules',
                    'hierarchical_level_restriction_rules'
                ),
            ]),
            [
                'id'         => 'hierarchical_post_type_note',
                'type'       => 'html',
                'columns'    => 12,
                'conditions' => self::only('propagation_rules'),
                'args'       => [
                    'variant' => 'info',
                    'content' => '<p>' . esc_html__('Propagation needs a parent/child relationship, so it only acts on hierarchical post types. Scoping this rule to a flat post type matches nothing.', 'meta-conductor') . '</p>',
                ],
            ],
            // The config half of #23: every type in this list now gets the
            // publication-status gate that only the ACF-reference rule had.
            // Enforcement is UnifiedHandlerBase::should_process_post, which
            // already reads `post_status`; empty means every status, so no
            // existing rule changes behaviour.
            ConfigHelpers::post_status_field(),
            [
                // The ACF-reference rule enforces the status gate differently
                // — on the SOURCE post during term collection, not on the
                // trigger post (SPEC §V5). The shared field's generic label
                // stays; this note carries the per-type semantics the old
                // per-type label ("Limit to source statuses") stated.
                'id'         => 'acf_status_note',
                'type'       => 'html',
                'columns'    => 12,
                'conditions' => self::only('related_post_terms_rules'),
                'args'       => [
                    'variant' => 'info',
                    'content' => '<p>' . esc_html__('For this rule type the status limit applies to the posts terms are copied FROM: only source posts with these statuses contribute terms.', 'meta-conductor') . '</p>',
                ],
            ],
            // Claim is SUPPLIED BY THE SHARED BUILDER, never re-authored here
            // — the 0.8.0 claim vocabulary (ADR 0004) has to stay one map
            // behind the dropdowns and the row titles alike (#53 §6). The
            // builder deliberately does not force its `id`, because the two
            // existing surfaces store under different keys, so the id is
            // supplied here. Gated to propagation: it is the only type in
            // this batch whose handler reads `conflict_handling`; the others
            // have a claim fixed in code (#54 is where that gets stated).
            ConfigHelpers::claim_field('child', [
                'id'          => 'conflict_handling',
                'description' => __('What this rule does about terms a child post already has. Owning removes them on the next save or re-apply, not at edit time.', 'meta-conductor'),
                'conditions'  => self::only('propagation_rules'),
            ]),
        ];
    }

    /**
     * Date window — apply a term while the current date is inside a range.
     *
     * @return array[]
     */
    private static function time_based_subfields(): array {
        $gate = self::only('time_based_rules');

        return [
            [
                'id'          => 'filter_taxonomies',
                'type'        => 'checkboxes',
                'label'       => __('Filter by taxonomies (optional)', 'meta-conductor'),
                'description' => __('Only apply to posts with terms in these taxonomies. Leave empty for all posts.', 'meta-conductor'),
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'options' => ConfigHelpers::taxonomy_checkbox_options(),
                ],
            ],
            [
                'id'          => 'filter_terms',
                'type'        => 'select',
                'label'       => __('Filter by specific terms (optional)', 'meta-conductor'),
                'description' => __('Only apply to posts with these terms. Leave empty to use taxonomy filter only.', 'meta-conductor'),
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'multiple' => true,
                    'options'  => ConfigHelpers::all_term_options(),
                ],
            ],
            [
                'id'         => 'start_date',
                'type'       => 'date',
                'label'      => __('Start date', 'meta-conductor'),
                'required'   => true,
                'columns'    => 12,
                'conditions' => $gate,
            ],
            [
                'id'         => 'end_date',
                'type'       => 'date',
                'label'      => __('End date', 'meta-conductor'),
                'required'   => true,
                'columns'    => 12,
                'conditions' => $gate,
            ],
            // target_term_id — shared with the related-term rule — is
            // declared once in related_subfields(), gated to both types.
        ];
    }

    /**
     * Hierarchy — inherit terms up or down a hierarchical taxonomy tree.
     *
     * ### #16, settled here
     *
     * `hierarchy_direction` (child_to_parent / parent_to_child / both) and
     * `expansion_behavior` (smart / merge / never) were two interacting
     * mechanism controls with nine combinations and six distinct outcomes —
     * including two spellings of "ancestors only" and one combination
     * (`parent_to_child` + `never`) that did nothing at all. Authors read
     * outcomes, not mechanisms, so they are collapsed into ONE selector,
     * `inheritance_behavior`, whose five options ARE the five useful
     * outcomes. The handler maps it back to the pair it already implements
     * (`HierarchicalHandler::resolve_behavior`), so no expansion code changed.
     *
     * A legacy row carrying only the old pair is handled on BOTH paths, and it
     * needs both: `resolve_behavior()` reads the pair at runtime for front-end
     * and cron requests, while `WireframeBootstrap::migrate_inheritance_behavior()`
     * rewrites it on admin load. The rewrite is not optional — Wireframe reads
     * the option raw, so an un-migrated row would render with this field's
     * `ancestors` default and the next save would persist it, silently
     * converting a `parent_to_child` rule (architecture invariant #1).
     *
     * Taken now because the schema window is free — hierarchical rules are
     * test-site only (CLAUDE.md live-rule-type rule) — and it closes once
     * they are not.
     *
     * `inheritance_depth` is untouched: one level vs all levels is already an
     * outcome, and it is orthogonal to the direction question.
     *
     * @return array[]
     */
    private static function hierarchical_subfields(): array {
        $gate = self::only('hierarchical_rules');

        return [
            [
                'id'          => 'inheritance_behavior',
                'type'        => 'select',
                'label'       => __('What to apply automatically', 'meta-conductor'),
                'description' => __('Ancestors are the terms above the ones picked by hand; descendants are the terms below them.', 'meta-conductor'),
                'default'     => 'ancestors',
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'options' => [
                        'ancestors'          => __('Ancestors of the terms picked by hand', 'meta-conductor'),
                        'descendants_smart'  => __('Descendants, but only when none were picked by hand', 'meta-conductor'),
                        'descendants_always' => __('Descendants, always', 'meta-conductor'),
                        'both_smart'         => __('Ancestors, plus descendants only when none were picked by hand', 'meta-conductor'),
                        'both_always'        => __('Ancestors, plus descendants always', 'meta-conductor'),
                    ],
                ],
            ],
            [
                'id'          => 'inheritance_depth',
                'type'        => 'radio',
                'label'       => __('How far to follow the tree', 'meta-conductor'),
                'description' => __('One level: the direct parent or the direct children only. All levels: every ancestor or descendant.', 'meta-conductor'),
                'default'     => 'all',
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'options' => [
                        'immediate' => __('One level only', 'meta-conductor'),
                        'all'       => __('All levels (entire hierarchy)', 'meta-conductor'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Level restriction — limit which tree depths may hold terms.
     *
     * ### #32, settled here
     *
     * `include_ancestors` used to mean two different things: in
     * `deepest_only` it ADDED each kept term's ancestor chain, and in
     * `one_per_level` it suppressed a pruning pass. The second meaning was
     * also dead — that pruning pass could never fire, because the mode has
     * already reduced the set to one term per level and the pass only removed
     * terms whose level held more than one. One flag, one real behaviour and
     * one no-op dressed up as a second.
     *
     * Redefined to a single consistent meaning: **also keep the ancestors of
     * whatever the mode kept**, applied the same way in all three modes. The
     * dead pruning pass is gone with it. That also removes the flag's own
     * `conditions` gate — it now does something in every mode, so hiding it
     * in one would just be a way to lose its value on save.
     *
     * Free to change because level restriction is test-site only (CLAUDE.md
     * live-rule-type rule); the window closes the moment it is not.
     *
     * @return array[]
     */
    private static function level_restriction_subfields(): array {
        $gate = self::only('hierarchical_level_restriction_rules');

        return [
            [
                'id'          => 'restriction_mode',
                'type'        => 'radio',
                'label'       => __('Which depths may keep terms', 'meta-conductor'),
                'default'     => 'one_per_level',
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'options' => [
                        'one_per_level'   => __('One term per level', 'meta-conductor'),
                        'deepest_only'    => __('Only terms at the deepest level used', 'meta-conductor'),
                        'shallowest_only' => __('Only terms at the shallowest level used', 'meta-conductor'),
                    ],
                ],
            ],
            [
                'id'          => 'include_ancestors',
                'type'        => 'toggle',
                'label'       => __('Keep ancestor terms', 'meta-conductor'),
                'description' => __('Also keep the parent chain of every term the rule keeps, so a post tagged with a deep term still appears under its ancestor archives. Applies in all three modes — including "one term per level", where a kept term\'s ancestors are added back even if that leaves more than one term on a level.', 'meta-conductor'),
                'default'     => false,
                'columns'     => 12,
                'conditions'  => $gate,
            ],
        ];
    }

    /**
     * Related term — when a trigger term is present, apply a target term.
     *
     * LIVE on a real site (#58): the field ids and stored shapes here are the
     * ones the per-type repeater persisted — `trigger_type`,
     * `trigger_term_id` (FormTokenField array), `trigger_taxonomy`,
     * `target_term_id` ([N] single-value array), `bidirectional`. Renaming
     * any of them would orphan live rows' values at sanitize.
     *
     * `target_term_id` is declared here ONCE for both this type and the date
     * window — identical field, identical meaning, and subfield ids must be
     * unique in the repeater (H11), so a second declaration would clobber.
     *
     * @since 0.8.0
     * @return array[]
     */
    private static function related_subfields(): array {
        $gate = self::only('related_rules');

        return [
            [
                'id'      => 'trigger_type',
                'type'    => 'radio',
                'label'   => __('Trigger', 'meta-conductor'),
                'default' => 'term',
                'columns' => 12,
                'conditions' => $gate,
                'args'    => [
                    'options' => [
                        'term'     => __('Specific term', 'meta-conductor'),
                        'taxonomy' => __('Any term from taxonomy', 'meta-conductor'),
                    ],
                ],
            ],
            [
                // Both trigger fields stay visible for the whole type rather
                // than gating on trigger_type: a within-row gate on a sibling
                // radio would DROP the hidden field's value at sanitize, and
                // flipping the trigger back would find the old value gone.
                'id'          => 'trigger_term_id',
                'type'        => 'select',
                'label'       => __('Trigger term', 'meta-conductor'),
                'description' => __('Used when Trigger is "Specific term". Rule fires if post has any of the listed terms.', 'meta-conductor'),
                'default'     => '',
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'multiple' => true,
                    'options'  => ConfigHelpers::all_term_options(),
                ],
            ],
            [
                'id'          => 'trigger_taxonomy',
                'type'        => 'select',
                'label'       => __('Trigger taxonomy', 'meta-conductor'),
                'description' => __('Used when Trigger is "Any term from taxonomy".', 'meta-conductor'),
                'default'     => '',
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'options' => ConfigHelpers::taxonomy_options(),
                ],
            ],
            [
                'id'         => 'target_term_id',
                'type'       => 'select',
                'label'      => __('Target term to apply', 'meta-conductor'),
                'default'    => '',
                'columns'    => 12,
                'conditions' => self::only('related_rules', 'time_based_rules'),
                'args'       => [
                    'multiple' => true,
                    'max'      => 1,
                    'options'  => ConfigHelpers::all_term_options(),
                ],
            ],
            [
                'id'          => 'bidirectional',
                'type'        => 'toggle',
                'label'       => __('Bidirectional', 'meta-conductor'),
                // Wording follows the #61 conversion: the applier recomputes
                // from live state, so the condition is the trigger's ABSENCE,
                // not the moment of its removal. A post that holds the target
                // but has never held a trigger now loses it too.
                'description' => __('Remove the target term whenever no trigger term is present on the post.', 'meta-conductor'),
                'default'     => false,
                'columns'     => 12,
                'conditions'  => $gate,
            ],
        ];
    }

    /**
     * From referenced post (ACF) — copy taxonomy terms between a post and the
     * posts it relates to via an ACF relationship / post-object field.
     *
     * LIVE on a real site (#58). The `acf_field_name` select stores the
     * COMBINED "post_type:field_name:field_key" value and must round-trip it
     * whole: the option keys are combined, so persisting the split form would
     * render the select empty and the next save would blank the field. The
     * handler splits at read time (`normalize_rule_shape`). Same for
     * `reverse_acf_field_name`. The trailing field key is what makes two
     * same-named fields distinguishable (#25); a row stored before it existed
     * keeps its two-part value until re-picked and resolves by name meanwhile.
     *
     * `taxonomy` and `post_status` come from the shared frame; the two
     * type-gated notes beside them carry this type's semantics.
     *
     * @since 0.8.0
     * @return array[]
     */
    private static function related_post_terms_subfields(): array {
        $gate = self::only('related_post_terms_rules');

        return [
            [
                'id'          => 'acf_field_name',
                'type'        => 'select',
                'label'       => __('Monitored relationship field', 'meta-conductor'),
                'description' => __('The post-object or relationship field connecting the two posts. Watched at both ends — a change to either post re-syncs. The post type that OWNS this field is the "field holder"; "Source" below decides which end\'s terms win. Each option names its field group, so two fields sharing a name can be told apart. ⚠ Only top-level relationship/post-object fields are listed — fields nested inside an ACF Group, Repeater, or Flexible Content container are not shown and are not currently supported.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'options' => ConfigHelpers::acf_relationship_field_options(),
                ],
            ],
            [
                'id'          => 'holder_role',
                'type'        => 'radio',
                'label'       => __('Source (terms copied from)', 'meta-conductor'),
                'description' => __('Which end is authoritative — its terms are copied to the other end. The trigger is ambient (a change at either end re-syncs); this decides direction.', 'meta-conductor'),
                'default'     => 'source',
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'options' => [
                        'source' => __('Field holder → copies out to related posts (push)', 'meta-conductor'),
                        'target' => __('Related posts → copies in to the field holder (pull)', 'meta-conductor'),
                    ],
                ],
            ],
            [
                'id'          => 'reverse_acf_field_name',
                'type'        => 'select',
                'label'       => __('Reverse relationship field (optional)', 'meta-conductor'),
                'description' => __('The inverse relationship field on the other end, if any. Speeds the reverse lookup. Leave blank to auto-detect ACF bidirectional fields. ⚠ Only top-level relationship/post-object fields are listed — group/repeater/flexible-content-nested fields are not shown or supported. ⚠ With neither an explicit reverse field nor a detectable bidirectional field, the reverse lookup falls back to an unindexed query on every save — slow on large sites. Set this (or use an ACF bidirectional field) to avoid it.', 'meta-conductor'),
                'default'     => '',
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'options' => ConfigHelpers::acf_relationship_field_options(__('— None / auto —', 'meta-conductor')),
                ],
            ],
            [
                'id'          => 'keep_in_sync',
                'type'        => 'toggle',
                'label'       => __('Keep in sync', 'meta-conductor'),
                'description' => __('Remove copied terms from the target when the source no longer has them. Off = add-only (never removes).', 'meta-conductor'),
                'default'     => true,
                'columns'     => 12,
                'conditions'  => $gate,
            ],
        ];
    }
}
