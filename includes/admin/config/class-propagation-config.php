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
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add propagation rule', 'bws-meta-manager'),
                        'empty_message'  => __('No propagation rules configured.', 'bws-meta-manager'),
                        'title_template' => '{scope_label} → {tax_label} ({conflict_label})',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'bws-meta-manager'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            ConfigHelpers::hierarchical_post_types_field(),
                            [
                                'id'          => 'taxonomy',
                                'type'        => 'select',
                                'label'       => __('Taxonomy', 'bws-meta-manager'),
                                'description' => __('Terms in this taxonomy cascade from a parent post to its children.', 'bws-meta-manager'),
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
                            // Snapshot labels for the row title (V11/§I.label). Not
                            // user-editable; populated at save by snapshot_propagation_labels
                            // in WireframeBootstrap. Declared so title_template tokens
                            // resolve and the row shape is documented.
                            [
                                'id'      => 'scope_label',
                                'type'    => 'hidden',
                                'default' => '',
                            ],
                            [
                                'id'      => 'tax_label',
                                'type'    => 'hidden',
                                'default' => '',
                            ],
                            [
                                'id'      => 'conflict_label',
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
            'title'    => __('Propagation Rules', 'bws-meta-manager'),
            'sections' => [self::section()],
        ];
    }
}
