<?php
/**
 * Hierarchical Rules tab config.
 *
 * @package BWS_Meta_Manager
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Hierarchical_Config {

    /**
     * Build the hierarchical_rules tab definition for Wireframe.
     *
     * @return array
     */
    public static function tab(): array {
        return [
            'id'       => 'hierarchical',
            'title'    => __('Hierarchical Rules', 'bws-meta-manager'),
            'sections' => [
                [
                    'id'          => 'hierarchical_main',
                    'title'       => __('Hierarchical Rules', 'bws-meta-manager'),
                    'description' => __('Inherit terms across hierarchical taxonomy structures.', 'bws-meta-manager'),
                    'fields'      => [
                        [
                            'id'    => 'hierarchical_rules',
                            'type'  => 'repeater',
                            'label' => __('Rules', 'bws-meta-manager'),
                            'args'  => [
                                'sortable'       => true,
                                'collapsible'    => true,
                                'duplicate_row'  => true,
                                'add_label'      => __('Add hierarchical rule', 'bws-meta-manager'),
                                'empty_message'  => __('No hierarchical rules configured.', 'bws-meta-manager'),
                                'title_template' => '{taxonomy}',
                                'subfields'      => [
                                    [
                                        'id'      => 'enabled',
                                        'type'    => 'toggle',
                                        'label'   => __('Enabled', 'bws-meta-manager'),
                                        'default' => true,
                                        'columns' => 12,
                                    ],
                                    [
                                        'id'       => 'taxonomy',
                                        'type'     => 'bws_wp_select',
                                        'label'    => __('Taxonomy', 'bws-meta-manager'),
                                        'required' => true,
                                        'columns'  => 6,
                                        'args'     => [
                                            'source' => 'taxonomies',
                                            'filter' => 'hierarchical_only',
                                        ],
                                    ],
                                    [
                                        'id'      => 'hierarchy_direction',
                                        'type'    => 'select',
                                        'label'   => __('Hierarchy direction', 'bws-meta-manager'),
                                        'default' => 'child_to_parent',
                                        'columns' => 6,
                                        'args'    => [
                                            'options' => [
                                                'child_to_parent' => __('Child to Parent (Apply ancestor terms)', 'bws-meta-manager'),
                                                'parent_to_child' => __('Parent to Child (Apply child terms)', 'bws-meta-manager'),
                                                'both'            => __('Both Directions', 'bws-meta-manager'),
                                            ],
                                        ],
                                    ],
                                    [
                                        'id'      => 'inheritance_depth',
                                        'type'    => 'radio',
                                        'label'   => __('Hierarchy depth', 'bws-meta-manager'),
                                        'default' => 'all',
                                        'columns' => 12,
                                        'args'    => [
                                            'options' => [
                                                'immediate' => __('One level only', 'bws-meta-manager'),
                                                'all'       => __('All levels (entire hierarchy)', 'bws-meta-manager'),
                                            ],
                                        ],
                                    ],
                                    [
                                        'id'          => 'expansion_behavior',
                                        'type'        => 'select',
                                        'label'       => __('Child expansion behavior', 'bws-meta-manager'),
                                        'description' => __('Applies when direction includes parent-to-child.', 'bws-meta-manager'),
                                        'default'     => 'smart',
                                        'columns'     => 12,
                                        'args'        => [
                                            'options' => [
                                                'smart' => __('Smart — Only if none selected', 'bws-meta-manager'),
                                                'merge' => __('Always — Merge with manual selections', 'bws-meta-manager'),
                                                'never' => __('Manual only — No auto-expansion', 'bws-meta-manager'),
                                            ],
                                        ],
                                        'conditions' => [
                                            'any' => [
                                                ['field' => 'hierarchy_direction', 'operator' => 'equals', 'value' => 'parent_to_child'],
                                                ['field' => 'hierarchy_direction', 'operator' => 'equals', 'value' => 'both'],
                                            ],
                                        ],
                                    ],
                                    [
                                        'id'          => 'post_types',
                                        'type'        => 'bws_wp_select',
                                        'label'       => __('Post types (optional)', 'bws-meta-manager'),
                                        'description' => __('Leave empty to apply to all post types using this taxonomy.', 'bws-meta-manager'),
                                        'columns'     => 12,
                                        'args'        => [
                                            'source'   => 'post_types',
                                            'multiple' => true,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
