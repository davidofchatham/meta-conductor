<?php
/**
 * Related Term Mapping (formerly Related Rules) config.
 *
 * Trigger: post saved with a specific term or any term in a taxonomy.
 * Action: apply a target term in another taxonomy.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class RelatedConfig {

    public static function section(): array {
        return [
            'id'          => 'related',
            'title'       => __('Related term mapping', 'bws-meta-manager'),
            'description' => __('When a post has a trigger term (or any term in a trigger taxonomy), apply a target term.', 'bws-meta-manager'),
            'fields'      => [
                [
                    'id'    => 'related_rules',
                    'type'  => 'repeater',
                    'label' => __('Related term rules', 'bws-meta-manager'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add related term rule', 'bws-meta-manager'),
                        'empty_message'  => __('No related term rules configured.', 'bws-meta-manager'),
                        'title_template' => '{trigger_label} → {target_label}{scope_label}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'bws-meta-manager'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            ConfigHelpers::post_types_field(),
                            [
                                'id'      => 'trigger_type',
                                'type'    => 'radio',
                                'label'   => __('Trigger', 'bws-meta-manager'),
                                'default' => 'term',
                                'columns' => 12,
                                'args'    => [
                                    'options' => [
                                        'term'     => __('Specific term', 'bws-meta-manager'),
                                        'taxonomy' => __('Any term from taxonomy', 'bws-meta-manager'),
                                    ],
                                ],
                            ],
                            [
                                'id'          => 'trigger_term_id',
                                'type'        => 'select',
                                'label'       => __('Trigger term', 'bws-meta-manager'),
                                'description' => __('Used when Trigger is "Specific term".', 'bws-meta-manager'),
                                'default'     => '',
                                'columns'     => 12,
                                'args'        => [
                                    'multiple' => true,
                                    'max'      => 1,
                                    'options'  => ConfigHelpers::all_term_options(),
                                ],
                            ],
                            [
                                'id'          => 'trigger_taxonomy',
                                'type'        => 'select',
                                'label'       => __('Trigger taxonomy', 'bws-meta-manager'),
                                'description' => __('Used when Trigger is "Any term from taxonomy".', 'bws-meta-manager'),
                                'default'     => '',
                                'columns'     => 12,
                                'args'        => [
                                    'options' => ConfigHelpers::taxonomy_options(),
                                ],
                            ],
                            [
                                'id'      => 'target_term_id',
                                'type'    => 'select',
                                'label'   => __('Target term to apply', 'bws-meta-manager'),
                                'default' => '',
                                'columns' => 12,
                                'args'    => [
                                    'multiple' => true,
                                    'max'      => 1,
                                    'options'  => ConfigHelpers::all_term_options(),
                                ],
                            ],
                            [
                                'id'          => 'bidirectional',
                                'type'        => 'toggle',
                                'label'       => __('Bidirectional', 'bws-meta-manager'),
                                'description' => __('Remove the target term when the trigger term is removed.', 'bws-meta-manager'),
                                'default'     => false,
                                'columns'     => 12,
                            ],
                            // Snapshot labels for the row title (V11). Not user-editable;
                            // populated at save by the wp-wireframe/save/payload filter
                            // in WireframeBootstrap. Declared so title_template tokens
                            // resolve and the row shape is documented.
                            [
                                'id'      => 'trigger_label',
                                'type'    => 'hidden',
                                'default' => '',
                            ],
                            [
                                'id'      => 'target_label',
                                'type'    => 'hidden',
                                'default' => '',
                            ],
                            [
                                'id'      => 'scope_label',
                                'type'    => 'hidden',
                                'default' => '',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
