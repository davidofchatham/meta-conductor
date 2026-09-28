<?php
/**
 * Descriptor for `related_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Handlers\RelatedHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `related_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class RelatedRules extends RuleType {

    public function type(): string {
        return 'related_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Related term — a trigger term applies a target term', 'meta-conductor');
    }

    public function handler_class(): string {
        return RelatedHandler::class;
    }

    public function target_key(array $rule): ?string {
        return self::term_target($rule);
    }

    /**
     * Schema:
     *   {trigger} → {target}{ (post types)}
     *   e.g. "Categories: Term A → Tags: Term B (Pages)"
     * Trigger is the taxonomy label when trigger_type=taxonomy, else the
     * comma-joined trigger term labels.
     */
    public function row_title(array $row): string {
        $trigger = (($row['trigger_type'] ?? 'term') === 'taxonomy')
            ? Labels::taxonomy_label($row['trigger_taxonomy'] ?? '')
            : Labels::trigger_terms_label($row['trigger_term_id'] ?? []);

        return $trigger
            . ' ' . "\xE2\x86\x92" . ' '
            . Labels::term_label($row['target_term_id'] ?? 0)
            . Labels::scope_label($row['post_types'] ?? []);
    }

    public function reads_shared_fields(): array {
        return ['post_types', 'target_term_id'];
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
     * `target_term_id` is shared with the date window — identical field,
     * identical meaning, and subfield ids must be unique in the repeater
     * (H11), so it is declared ONCE, in `TermRulesConfig`'s shared frame, and
     * listed in `reads_shared_fields()` by both.
     *
     * @since 0.8.0
     * @return array[]
     */
    public function subfields(): array {
        return [
            [
                'id'      => 'trigger_type',
                'type'    => 'radio',
                'label'   => __('Trigger', 'meta-conductor'),
                'default' => 'term',
                'columns' => 12,
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
                'args'        => [
                    'options' => ConfigHelpers::taxonomy_options(),
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
            ],
        ];
    }
}
