<?php
/**
 * Related Post Terms (ACF Reference) config.
 *
 * Pulls terms from a post referenced via an ACF relationship/post-object
 * field, applies them to the current post.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Related_Post_Terms_Config {

    public static function section(): array {
        return [
            'id'          => 'related_post_terms',
            'title'       => __('From referenced post (ACF)', 'bws-meta-manager'),
            'description' => __('Copy terms from a post referenced via an ACF relationship or post-object field.', 'bws-meta-manager'),
            'fields'      => [
                [
                    'id'    => 'related_post_terms_rules',
                    'type'  => 'repeater',
                    'label' => __('ACF reference rules', 'bws-meta-manager'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add ACF reference rule', 'bws-meta-manager'),
                        'empty_message'  => __('No ACF reference rules configured.', 'bws-meta-manager'),
                        'title_template' => '{acf_field_name} → {target_taxonomy}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'bws-meta-manager'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            [
                                'id'          => 'acf_field_name',
                                'type'        => 'select',
                                'label'       => __('ACF relationship field', 'bws-meta-manager'),
                                'description' => __('The post-object or relationship field on the source post. Stored as "post_type:field_name".', 'bws-meta-manager'),
                                'default'     => '',
                                'required'    => true,
                                'columns'     => 12,
                                'args'        => [
                                    'options' => BWS_Config_Helpers::acf_relationship_field_options(),
                                ],
                            ],
                            [
                                'id'          => 'source_taxonomy',
                                'type'        => 'select',
                                'label'       => __('Source taxonomy', 'bws-meta-manager'),
                                'description' => __('Taxonomy on the related posts to copy terms from.', 'bws-meta-manager'),
                                'default'     => '',
                                'required'    => true,
                                'columns'     => 12,
                                'args'        => [
                                    'options' => BWS_Config_Helpers::taxonomy_options(),
                                ],
                            ],
                            [
                                'id'          => 'target_taxonomy',
                                'type'        => 'select',
                                'label'       => __('Target taxonomy', 'bws-meta-manager'),
                                'description' => __('Taxonomy on this post to apply terms to.', 'bws-meta-manager'),
                                'default'     => '',
                                'required'    => true,
                                'columns'     => 12,
                                'args'        => [
                                    'options' => BWS_Config_Helpers::taxonomy_options(),
                                ],
                            ],
                            [
                                'id'      => 'conflict_handling',
                                'type'    => 'select',
                                'label'   => __('Conflict handling', 'bws-meta-manager'),
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
                            [
                                'id'          => 'bidirectional',
                                'type'        => 'toggle',
                                'label'       => __('Bidirectional', 'bws-meta-manager'),
                                'description' => __('Remove target terms when no related posts have source terms.', 'bws-meta-manager'),
                                'default'     => false,
                                'columns'     => 12,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
