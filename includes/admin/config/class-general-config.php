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
            'title'    => __('General', 'bws-meta-manager'),
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
            'title'       => __('Global conflict handling', 'bws-meta-manager'),
            'description' => __('Default behavior when an existing post already has terms in a taxonomy and a rule wants to apply more. Individual rules can override these defaults. Taxonomies without an entry default to "Merge".', 'bws-meta-manager'),
            'fields'      => [
                [
                    'id'    => 'conflict_handling_overrides',
                    'type'  => 'repeater',
                    'label' => __('Conflict handling per taxonomy', 'bws-meta-manager'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'duplicate_row'  => false,
                        'add_label'      => __('Add taxonomy override', 'bws-meta-manager'),
                        'empty_message'  => __('No overrides — all taxonomies default to "Merge".', 'bws-meta-manager'),
                        'title_template' => '{taxonomy}: {mode}',
                        'subfields'      => [
                            [
                                'id'       => 'taxonomy',
                                'type'     => 'select',
                                'label'    => __('Taxonomy', 'bws-meta-manager'),
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
                                'label'   => __('Conflict handling mode', 'bws-meta-manager'),
                                'default' => 'merge',
                                'columns' => 12,
                                'args'    => [
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

    /**
     * Global processing toggles.
     */
    private static function processing_section(): array {
        return [
            'id'          => 'processing',
            'title'       => __('Processing options', 'bws-meta-manager'),
            'description' => __('Bulk-operation safeguards.', 'bws-meta-manager'),
            'fields'      => [
                [
                    'id'          => 'manual_processing_enabled',
                    'type'        => 'toggle',
                    'label'       => __('Enable bulk "Apply to Existing Posts" actions', 'bws-meta-manager'),
                    'description' => __('When off, bulk-apply buttons are hidden — useful on production sites to prevent accidental sweeping changes.', 'bws-meta-manager'),
                    'default'     => true,
                    'columns'     => 12,
                ],
            ],
        ];
    }
}
