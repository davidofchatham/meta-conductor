<?php
/**
 * The ordered term-rule list — one repeater for every rule whose effect is
 * "terms on a post" (architecture.md → The ordered rule repeaters, spec #53 §2, ticket #57).
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
 *      the same mechanism. That is correct behavior (a parent-relationship rule's
 *      claim is meaningless on a date-window rule) but it is silent, so the
 *      `type` select says so in its own description.
 *
 * ## Which types live here
 *
 * `RuleTypes\Registry` is the single source of truth, read through
 * `OptionRuleStorage::migrated_types_for_kind()` so the set of types this
 * repeater offers and the set storage treats as authored cannot drift. Batch 1 (#57)
 * was the four types not live on any real site; #58 moved the two live ones
 * (`terms_from_trigger_term` and the ACF-reference rule, now the `acf`
 * relationship of `terms_from_related_post`) in, completing the collapse.
 * Their rows exist in real data, so two shapes are load-bearing: the ACF
 * field select's combined "post_type:field_name" value round-trips WHOLE
 * (the handler splits at read time; persisting the split would break the
 * select's option keys), and a stored row must render with its own values,
 * never config defaults — which is why no row may be left in a legacy shape.
 *
 * The stored `type` value is the descriptor's id VERBATIM
 * (`terms_from_term_hierarchy`) — that is what lets every handler's
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
     * save (architecture.md → The ordered rule repeaters); H11's hand-written
     * `$expected_visible` is the independent check.
     *
     * A reader may add a second condition (`shared_field_when()`): the types
     * with none keep ONE `in` gate, and each type with one gets its own `all`
     * clause, the lot joined by `any`. With no extras this is exactly the flat
     * `type in [...]` gate it always was.
     *
     * @param string $field Shared subfield id.
     */
    private static function readers(string $field): array {
        $plain   = [];
        $clauses = [];
        foreach (self::types() as $type) {
            $descriptor = Registry::get($type);
            if (!in_array($field, $descriptor->reads_shared_fields(), true)) {
                continue;
            }
            $when = $descriptor->shared_field_when($field);
            if ($when) {
                $clauses[] = ConfigHelpers::gate([$type], $when);
            } else {
                $plain[] = $type;
            }
        }

        if (!$clauses) {
            return ConfigHelpers::type_gate(...$plain);
        }
        if ($plain) {
            array_unshift($clauses, ConfigHelpers::type_gate(...$plain));
        }

        return count($clauses) === 1 ? $clauses[0] : ['any' => $clauses];
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
     * The parent relationship has no group of its own — every field propagation
     * ever had is a shared one, which is the clearest evidence the unification
     * was real rather than a re-shuffle. The merged type owns only its
     * `relationship` select.
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
     *   - claim (`conflict_handling`) — the related-post type is the only type here
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
                //
                // A LIST (FW-56): pick several and the rule applies to each,
                // exactly as if the row were repeated once per taxonomy —
                // OptionRuleStorage::project_kind_rules() does that expansion
                // on read, so no handler ever sees more than one. `multiple`
                // renders a token field with typeahead, which stays usable on a
                // site with dozens of taxonomies where a checkbox list would not.
                'id'          => 'taxonomy',
                'type'        => 'select',
                'label'       => __('Taxonomies', 'meta-conductor'),
                'description' => __('The taxonomies this rule reads and writes. Pick several to apply the same rule to each of them.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'conditions'  => self::readers('taxonomy'),
                'args'        => [
                    'multiple' => true,
                    'options'  => ConfigHelpers::taxonomy_checkbox_options(),
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
                    'content' => '<p>' . esc_html__('Terms are copied within each chosen taxonomy, by ID — the same taxonomy on both ends of the relationship.', 'meta-conductor') . '</p>',
                ],
            ],
            // Gated to the types that read it: every type here since FW-61 04,
            // where the ACF relationship took it as an optional narrowing of the
            // receiving post (empty = no extra narrowing). Every type that
            // reads the value is inside the gate, so nothing loses its scope.
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
                    'content' => '<p>' . esc_html__('A parent relationship needs a parent/child tree, so it only acts on hierarchical post types. Scoping this rule to a flat post type matches nothing.', 'meta-conductor') . '</p>',
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
            // — the 0.8.0 claim vocabulary (CONTEXT.md → Claim) has to stay one map
            // behind the dropdowns and the row titles alike (#53 §6). The
            // builder deliberately does not force its `id`, because the two
            // existing surfaces store under different keys, so the id is
            // supplied here. Gated to the related-post type: it is the only type in
            // this batch whose handler reads `conflict_handling`; the others
            // have a claim fixed in code (#54 is where that gets stated).
            ConfigHelpers::claim_field('child', [
                'id'          => 'conflict_handling',
                'description' => __('What this rule does about terms the receiving post already has. Owning removes them on the next save or re-apply, not at edit time. Contributing only adds, except on a parent relationship, where it also withdraws a term the parent just lost.', 'meta-conductor'),
                'conditions'  => self::readers('conflict_handling'),
            ]),
        ];
    }
}
