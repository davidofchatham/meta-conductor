<?php
/**
 * Time-Based (Date Window) rules config.
 *
 * Applies a target term when the current date falls within a configured
 * window, optionally filtered by post terms.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class TimeBasedConfig {

    public static function section(): array {
        return [
            'id'          => 'time_based',
            'title'       => __('Date window', 'meta-conductor'),
            'description' => __('Apply a term when the current date is within a configured window.', 'meta-conductor'),
            'fields'      => [
                [
                    'id'    => 'time_based_rules',
                    'type'  => 'repeater',
                    'label' => __('Date window rules', 'meta-conductor'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add date window rule', 'meta-conductor'),
                        'empty_message'  => __('No date window rules configured.', 'meta-conductor'),
                        'title_template' => '{row_title}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'meta-conductor'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            ConfigHelpers::post_types_field(),
                            [
                                'id'          => 'filter_taxonomies',
                                'type'        => 'checkboxes',
                                'label'       => __('Filter by taxonomies (optional)', 'meta-conductor'),
                                'description' => __('Only apply to posts with terms in these taxonomies. Leave empty for all posts.', 'meta-conductor'),
                                'columns'     => 12,
                                'args'        => [
                                    'options' => self::taxonomy_options_no_placeholder(),
                                ],
                            ],
                            [
                                'id'          => 'filter_terms',
                                'type'        => 'select',
                                'label'       => __('Filter by specific terms (optional)', 'meta-conductor'),
                                'description' => __('Only apply to posts with these terms. Leave empty to use taxonomy filter only.', 'meta-conductor'),
                                'columns'     => 12,
                                'args'        => [
                                    'multiple' => true,
                                    'options'  => ConfigHelpers::all_term_options(),
                                ],
                            ],
                            [
                                'id'       => 'start_date',
                                'type'     => 'date',
                                'label'    => __('Start date', 'meta-conductor'),
                                'required' => true,
                                'columns'  => 12,
                            ],
                            [
                                'id'       => 'end_date',
                                'type'     => 'date',
                                'label'    => __('End date', 'meta-conductor'),
                                'required' => true,
                                'columns'  => 12,
                            ],
                            [
                                'id'      => 'target_term_id',
                                'type'    => 'select',
                                'label'   => __('Target term to apply', 'meta-conductor'),
                                'default' => '',
                                'columns' => 12,
                                'args'    => [
                                    'multiple' => true,
                                    'max'      => 1,
                                    'options'  => ConfigHelpers::all_term_options(),
                                ],
                            ],
                            // Snapshot row title (V11/§I.label). Assembled at save
                            // by snapshot_time_based_labels in WireframeBootstrap.
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

    /**
     * Taxonomy options without leading placeholder entry — for checkboxes.
     */
    private static function taxonomy_options_no_placeholder(): array {
        $options    = [];
        $taxonomies = get_taxonomies(['public' => true], 'objects');

        foreach ($taxonomies as $taxonomy) {
            $options[$taxonomy->name] = $taxonomy->label;
        }

        return $options;
    }
}
