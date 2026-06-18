<?php
/**
 * Hierarchical Level Restriction config.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Level_Restriction_Config {

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
                                    'options' => BWS_Config_Helpers::hierarchical_taxonomy_options(),
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
                                'label'       => __('Include ancestors', 'bws-meta-manager'),
                                'description' => __('Only relevant in "deepest only" mode. Works with existing hierarchical inheritance rules.', 'bws-meta-manager'),
                                'default'     => false,
                                'columns'     => 12,
                            ],
                            [
                                'id'          => 'post_types',
                                'type'        => 'checkboxes',
                                'label'       => __('Post types (optional)', 'bws-meta-manager'),
                                'description' => __('Leave empty to apply to all post types using this taxonomy.', 'bws-meta-manager'),
                                'columns'     => 12,
                                'args'        => [
                                    'options' => self::post_type_options_no_placeholder(),
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
            'id'       => 'level-restriction',
            'title'    => __('Level Restriction', 'bws-meta-manager'),
            'sections' => [self::section()],
        ];
    }

    private static function post_type_options_no_placeholder(): array {
        $options    = [];
        $post_types = get_post_types(['public' => true], 'objects');

        foreach ($post_types as $post_type) {
            $options[$post_type->name] = $post_type->label;
        }

        return $options;
    }
}
