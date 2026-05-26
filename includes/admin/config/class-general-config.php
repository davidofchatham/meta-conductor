<?php
/**
 * General Settings tab.
 *
 * Per-taxonomy default conflict handling + global processing toggles.
 *
 * @package BWS_Meta_Manager
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_General_Config {

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
     * Each public taxonomy gets a select field stored at
     * `conflict_handling.{taxonomy_slug}`.
     */
    private static function conflict_handling_section(): array {
        $fields     = [];
        $taxonomies = get_taxonomies(['public' => true], 'objects');

        $options = [
            'merge'   => __('Merge with existing terms', 'bws-meta-manager'),
            'replace' => __('Replace existing terms', 'bws-meta-manager'),
            'skip'    => __('Skip if terms exist', 'bws-meta-manager'),
        ];

        foreach ($taxonomies as $taxonomy) {
            $fields[] = [
                'id'      => 'conflict_handling.' . $taxonomy->name,
                'type'    => 'select',
                'label'   => sprintf('%s (%s)', $taxonomy->label, $taxonomy->name),
                'default' => 'merge',
                'columns' => 12,
                'args'    => ['options' => $options],
            ];
        }

        return [
            'id'          => 'conflict_handling',
            'title'       => __('Global conflict handling', 'bws-meta-manager'),
            'description' => __('Default behavior when an existing post already has terms in a taxonomy and a rule wants to apply more. Individual rules can override these defaults.', 'bws-meta-manager'),
            'fields'      => $fields,
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
