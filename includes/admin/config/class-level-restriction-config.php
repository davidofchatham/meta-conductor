<?php
/**
 * Hierarchical Level Restriction config.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class LevelRestrictionConfig {

    public static function section(): array {
        return [
            'id'          => 'level_restriction',
            'title'       => __('Level restrictions', 'bws-meta-manager'),
            'description' => __('Limit which hierarchical-taxonomy depths can have terms applied. Useful when a post should only carry terms from one level of a tree.', 'bws-meta-manager'),
            'fields'      => [
                [
                    'id'    => 'hierarchical_level_restriction_rules',
                    'type'  => 'repeater',
                    'label' => __('Level restriction rules', 'bws-meta-manager'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add level restriction rule', 'bws-meta-manager'),
                        'empty_message'  => __('No level restriction rules configured.', 'bws-meta-manager'),
                        'title_template' => '{taxonomy} ({restriction_mode})',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'bws-meta-manager'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            [
                                'id'          => 'taxonomy',
                                'type'        => 'select',
                                'label'       => __('Taxonomy', 'bws-meta-manager'),
                                'description' => __('Must be a hierarchical taxonomy.', 'bws-meta-manager'),
                                'default'     => '',
                                'required'    => true,
                                'columns'     => 12,
                                'args'        => [
                                    'options' => ConfigHelpers::hierarchical_taxonomy_options(),
                                ],
                            ],
                            [
                                'id'      => 'restriction_mode',
                                'type'    => 'radio',
                                'label'   => __('Restriction mode', 'bws-meta-manager'),
                                'default' => 'one_per_level',
                                'columns' => 12,
                                'args'    => [
                                    'options' => [
                                        'one_per_level'   => __('One term per hierarchical level', 'bws-meta-manager'),
                                        'deepest_only'    => __('Only deepest level terms', 'bws-meta-manager'),
                                        'shallowest_only' => __('Only shallowest level terms', 'bws-meta-manager'),
                                    ],
                                ],
                            ],
                            [
                                'id'          => 'include_ancestors',
                                'type'        => 'toggle',
                                'label'       => __('Keep ancestor terms', 'bws-meta-manager'),
                                'description' => __('In "deepest only" mode: also keep each kept term\'s parent chain (so e.g. a post tagged with a leaf term still appears under its ancestor archives). In "one per level" mode: keep ancestor terms instead of pruning ones that conflict with a deeper pick. No effect in "shallowest only" mode.', 'bws-meta-manager'),
                                'default'     => false,
                                'columns'     => 12,
                                // Subfield condition (Wireframe 1.0.6, #13): only show
                                // where the flag actually changes behavior. Hidden ⇒ the
                                // value drops from the payload (RepeaterField), which is
                                // the desired "falsy in shallowest_only" outcome.
                                'conditions'  => [
                                    'field'    => 'restriction_mode',
                                    'operator' => 'in',
                                    'value'    => ['deepest_only', 'one_per_level'],
                                ],
                            ],
                            ConfigHelpers::post_types_field(),
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function tab(): array {
        return [
            'id'       => 'level-restriction',
            'title'    => __('Level Restriction', 'bws-meta-manager'),
            'sections' => [self::section()],
        ];
    }
}
