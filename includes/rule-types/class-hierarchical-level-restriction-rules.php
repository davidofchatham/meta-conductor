<?php
/**
 * Descriptor for `hierarchical_level_restriction_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\HierarchicalLevelRestrictionHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `hierarchical_level_restriction_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class HierarchicalLevelRestrictionRules extends RuleType {

    public function type(): string {
        return 'hierarchical_level_restriction_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Level restriction — limit which tree depths may hold terms', 'meta-conductor');
    }

    public function handler_class(): string {
        return HierarchicalLevelRestrictionHandler::class;
    }

    /**
     * Schema:
     *   {Scope: }Restrict {Taxonomy} to {mode}{, keeping ancestors}
     *   e.g. "Restrict Shakers to one term per level"
     *        "Pages: Restrict Shakers to the deepest level, keeping ancestors"
     */
    public function row_title(array $row): string {
        $modes = [
            'one_per_level'   => __('one term per level', 'meta-conductor'),
            'deepest_only'    => __('the deepest level', 'meta-conductor'),
            'shallowest_only' => __('the shallowest level', 'meta-conductor'),
        ];

        $mode = $modes[(string) ($row['restriction_mode'] ?? 'one_per_level')]
            ?? $modes['one_per_level'];

        $title = Labels::scope_prefix($row['post_types'] ?? []) . sprintf(
            /* translators: 1: taxonomy label 2: which depths may keep terms */
            __('Restrict %1$s to %2$s', 'meta-conductor'),
            Labels::taxonomy_label($row['taxonomy'] ?? ''),
            $mode
        );

        // One meaning in every mode as of 0.8.0 (#32), so the clause is shown
        // whenever the flag is set rather than only in some modes.
        if (!empty($row['include_ancestors'])) {
            $title .= __(', keeping ancestors', 'meta-conductor');
        }

        return $title;
    }

    public function reads_shared_fields(): array {
        return ['taxonomy', 'hierarchical_taxonomy_note', 'post_types'];
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
     * terms whose level held more than one. One flag, one real behavior and
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
    public function subfields(): array {
        return [
            [
                'id'          => 'restriction_mode',
                'type'        => 'radio',
                'label'       => __('Which depths may keep terms', 'meta-conductor'),
                'default'     => 'one_per_level',
                'columns'     => 12,
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
            ],
        ];
    }
}
