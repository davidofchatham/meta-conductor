<?php
/**
 * Hierarchical Rules tab config.
 *
 * @package BWS_Meta_Manager
 * @since 0.3.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class HierarchicalConfig {

    /**
     * Section definition for inclusion in the Auto-set Terms tab.
     */
    public static function section(): array {
        return [
            'id'          => 'hierarchical',
            'title'       => __('Hierarchical taxonomy inheritance', 'bws-meta-manager'),
            'description' => __('Inherit terms up or down a hierarchical taxonomy tree.', 'bws-meta-manager'),
            'fields'      => [
                        [
                            'id'    => 'hierarchical_rules',
                            'type'  => 'repeater',
                            'label' => __('Hierarchical rules', 'bws-meta-manager'),
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
                                        'type'     => 'select',
                                        'label'    => __('Taxonomy', 'bws-meta-manager'),
                                        'default'  => '',
                                        'required' => true,
                                        'columns'  => 12,
                                        'args'     => [
                                            'options' => self::get_taxonomy_options_with_placeholder(),
                                        ],
                                    ],
                                    ConfigHelpers::post_types_field(),
                                    [
                                        'id'          => 'hierarchy_direction',
                                        'type'        => 'select',
                                        'label'       => __('Hierarchy direction', 'bws-meta-manager'),
                                        'description' => __('Whether to apply parent terms to children, child terms to parents, or both.', 'bws-meta-manager'),
                                        'default'     => 'child_to_parent',
                                        'columns'     => 12,
                                        'args'        => [
                                            'options' => [
                                                'child_to_parent' => __('Child to Parent (Apply ancestor terms)', 'bws-meta-manager'),
                                                'parent_to_child' => __('Parent to Child (Apply child terms)', 'bws-meta-manager'),
                                                'both'            => __('Both Directions', 'bws-meta-manager'),
                                            ],
                                        ],
                                    ],
                                    [
                                        'id'          => 'inheritance_depth',
                                        'type'        => 'radio',
                                        'label'       => __('Hierarchy depth', 'bws-meta-manager'),
                                        'description' => __('One level: direct parent or children only. All levels: every ancestor or descendant.', 'bws-meta-manager'),
                                        'default'     => 'all',
                                        'columns'     => 12,
                                        'args'        => [
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
                                    ],
                                    [
                                        'id'      => 'expansion_behavior_help',
                                        'type'    => 'html',
                                        'columns' => 12,
                                        'args'    => [
                                            'variant' => 'info',
                                            'content' => '<p><strong>' . esc_html__('Smart (recommended):', 'bws-meta-manager') . '</strong> ' . esc_html__('Adds all child terms only if no children are manually selected. Prevents overriding specific picks.', 'bws-meta-manager') . '</p>'
                                                       . '<p><strong>' . esc_html__('Always:', 'bws-meta-manager') . '</strong> ' . esc_html__('Adds all child terms even when some are manually selected. New children merge with existing picks.', 'bws-meta-manager') . '</p>'
                                                       . '<p><strong>' . esc_html__('Manual only:', 'bws-meta-manager') . '</strong> ' . esc_html__('Never auto-adds child terms. Useful with "Both" direction to get ancestors but not descendants.', 'bws-meta-manager') . '</p>',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
        ];
    }

    /**
     * Wrap the section in a standalone tab (legacy use).
     */
    public static function tab(): array {
        return [
            'id'       => 'hierarchical',
            'title'    => __('Hierarchical Rules', 'bws-meta-manager'),
            'sections' => [self::section()],
        ];
    }

    /**
     * Hierarchical public taxonomies as id => label, with empty placeholder first.
     */
    private static function get_taxonomy_options_with_placeholder(): array {
        $options    = ['' => __('— Select taxonomy —', 'bws-meta-manager')];
        $taxonomies = get_taxonomies(['public' => true, 'hierarchical' => true], 'objects');

        foreach ($taxonomies as $taxonomy) {
            $options[$taxonomy->name] = $taxonomy->label;
        }

        return $options;
    }
}
