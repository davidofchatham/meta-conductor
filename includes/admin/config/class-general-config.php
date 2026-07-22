<?php
/**
 * General Settings tab.
 *
 * Per-taxonomy default conflict handling + global processing toggles.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class GeneralConfig {

    public static function tab(): array {
        return [
            'id'       => 'general',
            'title'    => __('General', 'meta-conductor'),
            'sections' => [
                self::conflict_handling_section(),
                self::processing_section(),
            ],
        ];
    }

    /**
     * Per-taxonomy default conflict handling.
     *
     * Wireframe doesn't sanitize dot-notation field IDs (its Sanitizer
     * explicitly skips them, src/Framework/Sanitizer.php). So the per-
     * taxonomy overrides ride on a repeater whose rows hold {taxonomy,
     * mode} pairs. The storage adapter coerces back to the canonical
     * {taxonomy_slug: mode} dict that handlers consume.
     */
    private static function conflict_handling_section(): array {
        return [
            'id'          => 'conflict_handling',
            'title'       => __('Global conflict handling', 'meta-conductor'),
            'description' => __('Default behavior when an existing post already has terms in a taxonomy and a rule wants to apply more. Individual rules can override these defaults. Taxonomies without an entry default to "Merge".', 'meta-conductor'),
            'fields'      => [
                [
                    'id'    => 'conflict_handling_overrides',
                    'type'  => 'repeater',
                    'label' => __('Conflict handling per taxonomy', 'meta-conductor'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => false,
                        'add_label'      => __('Add taxonomy override', 'meta-conductor'),
                        'empty_message'  => __('No overrides — all taxonomies default to "Merge".', 'meta-conductor'),
                        'title_template' => '{taxonomy}: {mode}',
                        'subfields'      => [
                            [
                                'id'       => 'taxonomy',
                                'type'     => 'select',
                                'label'    => __('Taxonomy', 'meta-conductor'),
                                'default'  => '',
                                'required' => true,
                                'columns'  => 12,
                                'args'     => [
                                    'options' => ConfigHelpers::taxonomy_options(),
                                ],
                            ],
                            [
                                'id'      => 'mode',
                                'type'    => 'select',
                                'label'   => __('Conflict handling mode', 'meta-conductor'),
                                'default' => 'merge',
                                'columns' => 12,
                                'args'    => [
                                    'options' => [
                                        'merge'   => __('Merge with existing terms', 'meta-conductor'),
                                        'replace' => __('Replace existing terms', 'meta-conductor'),
                                        'skip'    => __('Skip if terms exist', 'meta-conductor'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Global processing toggles.
     */
    private static function processing_section(): array {
        return [
            'id'          => 'processing',
            'title'       => __('Processing options', 'meta-conductor'),
            'description' => __('Bulk-operation safeguards.', 'meta-conductor'),
            'fields'      => [
                [
                    'id'          => 'manual_processing_enabled',
                    'type'        => 'toggle',
                    'label'       => __('Enable bulk "Apply to Existing Posts" actions', 'meta-conductor'),
                    'description' => __('When off, bulk-apply buttons are hidden — useful on production sites to prevent accidental sweeping changes.', 'meta-conductor'),
                    'default'     => true,
                    'columns'     => 12,
                ],
            ],
        ];
    }
}
