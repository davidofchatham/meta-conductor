<?php
/**
 * Propagation (parent post → children) config.
 *
 * @package BWS_Meta_Manager
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Propagation_Config {

    public static function section(): array {
        return [
            'id'          => 'propagation',
            'title'       => __('From parent post (propagation)', 'bws-meta-manager'),
            'description' => __('Cascade terms from a parent post to its children when the parent is updated.', 'bws-meta-manager'),
            'fields'      => [
                [
                    'id'    => 'propagation_rules',
                    'type'  => 'repeater',
                    'label' => __('Propagation rules', 'bws-meta-manager'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add propagation rule', 'bws-meta-manager'),
                        'empty_message'  => __('No propagation rules configured.', 'bws-meta-manager'),
                        'title_template' => '{post_type} → {taxonomy}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'bws-meta-manager'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            [
                                'id'          => 'post_type',
                                'type'        => 'select',
                                'label'       => __('Post type', 'bws-meta-manager'),
                                'description' => __('Only hierarchical post types appear — propagation requires a parent/child relationship.', 'bws-meta-manager'),
                                'default'     => '',
                                'required'    => true,
                                'columns'     => 12,
                                'args'        => [
                                    'options' => BWS_Config_Helpers::hierarchical_post_type_options(),
                                ],
                            ],
                            [
                                'id'       => 'taxonomy',
                                'type'     => 'select',
                                'label'    => __('Taxonomy', 'bws-meta-manager'),
                                'default'  => '',
                                'required' => true,
                                'columns'  => 12,
                                'args'     => [
                                    'options' => BWS_Config_Helpers::taxonomy_options(),
                                ],
                            ],
                            [
                                'id'          => 'conflict_handling',
                                'type'        => 'select',
                                'label'       => __('Conflict handling', 'bws-meta-manager'),
                                'description' => __('How to resolve when a child already has terms in this taxonomy.', 'bws-meta-manager'),
                                'default'     => 'merge',
                                'columns'     => 12,
                                'args'        => [
                                    'options' => [
                                        'merge'   => __('Merge with existing terms', 'bws-meta-manager'),
                                        'replace' => __('Replace existing terms', 'bws-meta-manager'),
                                        'skip'    => __('Skip if terms exist', 'bws-meta-manager'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function tab(): array {
        return [
            'id'       => 'propagation',
            'title'    => __('Propagation Rules', 'bws-meta-manager'),
            'sections' => [self::section()],
        ];
    }
}
