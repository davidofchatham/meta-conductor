<?php
/**
 * Descriptor for `hierarchical_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\HierarchicalHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `hierarchical_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class HierarchicalRules extends RuleType {

    public function type(): string {
        return 'hierarchical_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Hierarchy — inherit terms up or down the taxonomy tree', 'meta-conductor');
    }

    public function handler_class(): string {
        return HierarchicalHandler::class;
    }

    public function reads_shared_fields(): array {
        return ['taxonomy', 'hierarchical_taxonomy_note', 'post_types'];
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
     * A legacy row carrying only the old pair still reads correctly at
     * runtime (`resolve_behavior()` falls back to it). The admin-load rewrite
     * that once converted such rows was deleted with FW-39: no stored row
     * still carries the pair.
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
    public function subfields(): array {
        return [
            [
                'id'          => 'inheritance_behavior',
                'type'        => 'select',
                'label'       => __('What to apply automatically', 'meta-conductor'),
                'description' => __('Ancestors are the terms above the ones picked by hand; descendants are the terms below them.', 'meta-conductor'),
                'default'     => 'ancestors',
                'columns'     => 12,
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
                'args'        => [
                    'options' => [
                        'immediate' => __('One level only', 'meta-conductor'),
                        'all'       => __('All levels (entire hierarchy)', 'meta-conductor'),
                    ],
                ],
            ],
        ];
    }
}
