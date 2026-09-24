<?php
/**
 * General Settings tab.
 *
 * Per-taxonomy default claim + global processing toggles.
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
            ],
        ];
    }

    /**
     * Per-taxonomy default claim (CONTEXT.md → Claim, ADR 0004).
     *
     * The stored key stays `conflict_handling` and its values stay
     * merge|replace|skip — the rename to the claim vocabulary is wording
     * only, so nothing migrates. Value ↔ claim: replace = owning-claim,
     * merge = contributing-claim, skip = deferring-claim (the `-claim`
     * qualifier disambiguates `deferring` from defer-as-postpone).
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
            'title'       => __('Default claim per taxonomy', 'meta-conductor'),
            'description' => __('What rules claim in a taxonomy by default: what they do about terms a post already has. Individual rules can override these defaults. Taxonomies without an entry default to "Contributing".', 'meta-conductor'),
            'fields'      => [
                [
                    'id'    => 'conflict_handling_overrides',
                    'type'  => 'repeater',
                    'label' => __('Overrides', 'meta-conductor'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => false,
                        'add_label'      => __('Add taxonomy override', 'meta-conductor'),
                        'empty_message'  => __('No overrides: all taxonomies default to "Contributing".', 'meta-conductor'),
                        'title_template' => '{row_title}',
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
                            ConfigHelpers::claim_field('post', [
                                'id'    => 'mode',
                                'label' => __('Default claim on terms', 'meta-conductor'),
                            ]),
                            // Snapshot row title. Not user-editable; assembled
                            // at save by snapshot_claim_override_labels in
                            // WireframeBootstrap. Declared so {row_title}
                            // resolves. Same pattern as propagation_rules.
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
}
