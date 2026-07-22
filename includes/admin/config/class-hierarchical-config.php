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
            'title'       => __('Hierarchical taxonomy inheritance', 'meta-conductor'),
            'description' => __('Inherit terms up or down a hierarchical taxonomy tree.', 'meta-conductor'),
            'fields'      => [
                        [
                            'id'    => 'hierarchical_rules',
                            'type'  => 'repeater',
                            'label' => __('Hierarchical rules', 'meta-conductor'),
                            'args'  => [
                                'sortable'       => true,
                                'collapsible'    => true,
                                'collapsed'      => true,
                                'duplicate_row'  => true,
                                'add_label'      => __('Add hierarchical rule', 'meta-conductor'),
                                'empty_message'  => __('No hierarchical rules configured.', 'meta-conductor'),
                                'title_template' => '{taxonomy}',
                                'subfields'      => [
                                    [
                                        'id'      => 'enabled',
                                        'type'    => 'toggle',
                                        'label'   => __('Enabled', 'meta-conductor'),
                                        'default' => true,
                                        'columns' => 12,
                                    ],
                                    [
                                        'id'       => 'taxonomy',
                                        'type'     => 'select',
                                        'label'    => __('Taxonomy', 'meta-conductor'),
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
                                        'label'       => __('Hierarchy direction', 'meta-conductor'),
                                        'description' => __('Whether to apply parent terms to children, child terms to parents, or both.', 'meta-conductor'),
                                        'default'     => 'child_to_parent',
                                        'columns'     => 12,
                                        'args'        => [
                                            'options' => [
                                                'child_to_parent' => __('Child to Parent (Apply ancestor terms)', 'meta-conductor'),
                                                'parent_to_child' => __('Parent to Child (Apply child terms)', 'meta-conductor'),
                                                'both'            => __('Both Directions', 'meta-conductor'),
                                            ],
                                        ],
                                    ],
                                    [
                                        'id'          => 'inheritance_depth',
                                        'type'        => 'radio',
                                        'label'       => __('Hierarchy depth', 'meta-conductor'),
                                        'description' => __('One level: direct parent or children only. All levels: every ancestor or descendant.', 'meta-conductor'),
                                        'default'     => 'all',
                                        'columns'     => 12,
                                        'args'        => [
                                            'options' => [
                                                'immediate' => __('One level only', 'meta-conductor'),
                                                'all'       => __('All levels (entire hierarchy)', 'meta-conductor'),
                                            ],
                                        ],
                                    ],
                                    [
                                        'id'          => 'expansion_behavior',
                                        'type'        => 'select',
                                        'label'       => __('Child expansion behavior', 'meta-conductor'),
                                        'description' => __('Applies when direction includes parent-to-child.', 'meta-conductor'),
                                        'default'     => 'smart',
                                        'columns'     => 12,
                                        'args'        => [
                                            'options' => [
                                                'smart' => __('Smart — Only if none selected', 'meta-conductor'),
                                                'merge' => __('Always — Merge with manual selections', 'meta-conductor'),
                                                'never' => __('Manual only — No auto-expansion', 'meta-conductor'),
                                            ],
                                        ],
                                    ],
                                    [
                                        'id'      => 'expansion_behavior_help',
                                        'type'    => 'html',
                                        'columns' => 12,
                                        'args'    => [
                                            'variant' => 'info',
                                            'content' => '<p><strong>' . esc_html__('Smart (recommended):', 'meta-conductor') . '</strong> ' . esc_html__('Adds all child terms only if no children are manually selected. Prevents overriding specific picks.', 'meta-conductor') . '</p>'
                                                       . '<p><strong>' . esc_html__('Always:', 'meta-conductor') . '</strong> ' . esc_html__('Adds all child terms even when some are manually selected. New children merge with existing picks.', 'meta-conductor') . '</p>'
                                                       . '<p><strong>' . esc_html__('Manual only:', 'meta-conductor') . '</strong> ' . esc_html__('Never auto-adds child terms. Useful with "Both" direction to get ancestors but not descendants.', 'meta-conductor') . '</p>',
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
            'title'    => __('Hierarchical Rules', 'meta-conductor'),
            'sections' => [self::section()],
        ];
    }

    /**
     * Hierarchical public taxonomies as id => label, with empty placeholder first.
     */
    private static function get_taxonomy_options_with_placeholder(): array {
        $options    = ['' => __('— Select taxonomy —', 'meta-conductor')];
        $taxonomies = get_taxonomies(['public' => true, 'hierarchical' => true], 'objects');

        foreach ($taxonomies as $taxonomy) {
            $options[$taxonomy->name] = $taxonomy->label;
        }

        return $options;
    }
}
