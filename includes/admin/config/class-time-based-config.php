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
            'title'       => __('Date window', 'bws-meta-manager'),
            'description' => __('Apply a term when the current date is within a configured window.', 'bws-meta-manager'),
            'fields'      => [
                [
                    'id'    => 'time_based_rules',
                    'type'  => 'repeater',
                    'label' => __('Date window rules', 'bws-meta-manager'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add date window rule', 'bws-meta-manager'),
                        'empty_message'  => __('No date window rules configured.', 'bws-meta-manager'),
                        'title_template' => '{start_date} → {end_date}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'bws-meta-manager'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            [
                                'id'       => 'post_type',
                                'type'     => 'select',
                                'label'    => __('Post type', 'bws-meta-manager'),
                                'default'  => '',
                                'required' => true,
                                'columns'  => 12,
                                'args'     => [
                                    'options' => ConfigHelpers::post_type_options(),
                                ],
                            ],
                            [
                                'id'          => 'filter_taxonomies',
                                'type'        => 'checkboxes',
                                'label'       => __('Filter by taxonomies (optional)', 'bws-meta-manager'),
                                'description' => __('Only apply to posts with terms in these taxonomies. Leave empty for all posts.', 'bws-meta-manager'),
                                'columns'     => 12,
                                'args'        => [
                                    'options' => self::taxonomy_options_no_placeholder(),
                                ],
                            ],
                            [
                                'id'          => 'filter_terms',
                                'type'        => 'select',
                                'label'       => __('Filter by specific terms (optional)', 'bws-meta-manager'),
                                'description' => __('Only apply to posts with these terms. Leave empty to use taxonomy filter only.', 'bws-meta-manager'),
                                'columns'     => 12,
                                'args'        => [
                                    'multiple' => true,
                                    'options'  => ConfigHelpers::all_term_options(),
                                ],
                            ],
                            [
                                'id'       => 'start_date',
                                'type'     => 'date',
                                'label'    => __('Start date', 'bws-meta-manager'),
                                'required' => true,
                                'columns'  => 12,
                            ],
                            [
                                'id'       => 'end_date',
                                'type'     => 'date',
                                'label'    => __('End date', 'bws-meta-manager'),
                                'required' => true,
                                'columns'  => 12,
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
