<?php
/**
 * Propagation (parent post → children) config.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class PropagationConfig {

    public static function section(): array {
        return [
            'id'          => 'propagation',
            'title'       => __('From parent post (propagation)', 'meta-conductor'),
            'description' => __('Cascade terms from a parent post to its children when the parent is updated.', 'meta-conductor'),
            'fields'      => [
                [
                    'id'    => 'propagation_rules',
                    'type'  => 'repeater',
                    'label' => __('Propagation rules', 'meta-conductor'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add propagation rule', 'meta-conductor'),
                        'empty_message'  => __('No propagation rules configured.', 'meta-conductor'),
                        'title_template' => '{row_title}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'meta-conductor'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            ConfigHelpers::hierarchical_post_types_field(),
                            [
                                'id'          => 'taxonomy',
                                'type'        => 'select',
                                'label'       => __('Taxonomy', 'meta-conductor'),
                                'description' => __('Terms in this taxonomy cascade from a parent post to its children.', 'meta-conductor'),
                                'default'     => '',
                                'required'    => true,
                                'columns'     => 12,
                                'args'        => [
                                    'options' => ConfigHelpers::taxonomy_options(),
                                ],
                            ],
                            [
                                'id'          => 'conflict_handling',
                                'type'        => 'select',
                                'label'       => __('Conflict handling', 'meta-conductor'),
                                'description' => __('How to resolve when a child already has terms in this taxonomy.', 'meta-conductor'),
                                'default'     => 'merge',
                                'columns'     => 12,
                                'args'        => [
                                    'options' => [
                                        'merge'   => __('Merge with existing terms', 'meta-conductor'),
                                        'replace' => __('Replace existing terms', 'meta-conductor'),
                                        'skip'    => __('Skip if terms exist', 'meta-conductor'),
                                    ],
                                ],
                            ],
                            // Snapshot row title (V11/§I.label). Not user-editable;
                            // assembled at save by snapshot_propagation_labels in
                            // WireframeBootstrap. Declared so {row_title} resolves.
                            [
                                'id'      => 'row_title',
                                'type'    => 'hidden',
                                'default' => '',
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
            'title'    => __('Propagation Rules', 'meta-conductor'),
            'sections' => [self::section()],
        ];
    }
}
