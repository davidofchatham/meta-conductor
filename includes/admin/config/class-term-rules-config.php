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
 * `RuleTypes\Registry` is the single source of truth, read through
 * `OptionRuleStorage::migrated_types_for_kind()` so the set of types this
 * repeater offers and the set storage treats as authored cannot drift. Batch 1 (#57)
 * was the four types not live on any real site; #58 moved the two live ones
 * (`related_rules`, `related_post_terms_rules`) in, completing the collapse.
 * Their rows exist in real data, so two shapes are load-bearing: the ACF
 * field select's combined "post_type:field_name" value round-trips WHOLE
 * (the handler splits at read time; persisting the split would break the
 * select's option keys), and a stored row must render with its own values,
 * never config defaults — which is why no row may be left in a legacy shape.
 *
 * The stored `type` value is the LEGACY TYPE KEY VERBATIM
 * (`hierarchical_rules`, not `hierarchical`) — rule-type renaming stays
 * deferred (ADR 0002/0003), and reusing the key is what lets every handler's
 * `get_enabled_rules()` filter on `get_rule_type()` with no mapping table.
 * The author-facing labels are each descriptor's `label()`; the stored values
 * never change with them.
 *
 * @package BWS_Meta_Manager
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Admin\Config;

use BWS\MetaConductor\Admin\CollisionDetector;
use BWS\MetaConductor\RuleTypes\Registry;
use BWS\MetaConductor\Storage\OptionRuleStorage;

if (!defined('ABSPATH')) {
    exit;
}

class TermRulesConfig {

    /**
     * The rule types this repeater authors, in registry order.
     *
     * @return string[]
     */
    public static function types(): array {
        return OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_TERM);
    }

    /**
     * `type` select options: stored type key => the descriptor's label.
     *
     * Carries a leading empty placeholder so a freshly added row starts
     * untyped, with every type-specific subfield hidden — "pick what this
     * rule does first" — and `required` then rejects the save if it is still
     * untyped, rather than the row being silently dropped at fan-out.
     *
     * @return array<string,string>
     */
    public static function type_options(): array {
        $options = ['' => __('— Select rule type —', 'meta-conductor')];

        foreach (self::types() as $type) {
            $options[$type] = Registry::get($type)->label();
        }

        return $options;
    }

    /**
     * The gate for a shared subfield: every type whose descriptor lists it in
     * `reads_shared_fields()`, in registry order.
     *
     * A type missing from a declaration has that value hidden and DROPPED on
     * save (CLAUDE.md don't 3); H11's hand-written `$expected_visible` is the
     * independent check.
     *
     * @param string $field Shared subfield id.
     */
    private static function readers(string $field): array {
        return ConfigHelpers::type_gate(...array_values(array_filter(
            self::types(),
            static fn(string $type): bool => in_array($field, Registry::get($type)->reads_shared_fields(), true)
        )));
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
     * The row's subfields: what every rule shares, then each descriptor's
     * own `subfields()` gated to its type, then the shared tail.
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
            ConfigHelpers::type_subfields(self::types()),
            [
                // Shared by the related-term rule and the date window, and
                // AFTER the type groups so it reads below each one's trigger
                // or window. Ids are unique per repeater (H11), so it is
                // declared once here rather than in either descriptor.
                [
                    'id'         => 'target_term_id',
                    'type'       => 'select',
                    'label'      => __('Target term to apply', 'meta-conductor'),
                    'default'    => '',
                    'columns'    => 12,
                    'conditions' => self::readers('target_term_id'),
                    'args'       => [
                        'multiple' => true,
                        'max'      => 1,
                        'options'  => ConfigHelpers::all_term_options(),
                    ],
                ],
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
     * are the unified ones. Every gate here is `readers()` — built from the
     * descriptors' `reads_shared_fields()`, never a hand-written type list.
     * H11 pins which subfields each type sees.
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
                'conditions'  => self::readers('taxonomy'),
                'args'        => [
                    'options' => ConfigHelpers::taxonomy_options(),
                ],
            ],
            [
                'id'         => 'hierarchical_taxonomy_note',
                'type'       => 'html',
                'columns'    => 12,
                'conditions' => self::readers('hierarchical_taxonomy_note'),
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
                'conditions' => self::readers('acf_taxonomy_note'),
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
                'conditions' => self::readers('post_types'),
            ]),
            [
                'id'         => 'hierarchical_post_type_note',
                'type'       => 'html',
                'columns'    => 12,
                'conditions' => self::readers('hierarchical_post_type_note'),
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
                'conditions' => self::readers('acf_status_note'),
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
                'conditions'  => self::readers('conflict_handling'),
            ]),
        ];
    }
}
