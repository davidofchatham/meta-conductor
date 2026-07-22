<?php
/**
 * Related Post Terms (ACF Reference) config.
 *
 * Copies taxonomy terms between a post and the posts it relates to via an ACF
 * relationship / post-object field. The selected ACF field PINS the holder post
 * type; `holder_role` says which end is authoritative (source = push out;
 * target = receive in). Single taxonomy both ends (cross-taxonomy copy by ID
 * never worked). Declarative source-authoritative sync — see handler. (SPEC §V1)
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class RelatedPostTermsConfig {

    public static function section(): array {
        return [
            'id'          => 'related_post_terms',
            'title'       => __('From referenced post (ACF)', 'meta-conductor'),
            'description' => __('Copy taxonomy terms between a post and the posts it relates to via an ACF relationship or post-object field.', 'meta-conductor'),
            'fields'      => [
                [
                    'id'    => 'related_post_terms_rules',
                    'type'  => 'repeater',
                    'label' => __('ACF reference rules', 'meta-conductor'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add ACF reference rule', 'meta-conductor'),
                        'empty_message'  => __('No ACF reference rules configured.', 'meta-conductor'),
                        // Title assembled at save into `row_title` by the
                        // snapshot callback (SPEC §V10) — title_template reads it.
                        'title_template' => '{row_title}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'meta-conductor'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            [
                                'id'          => 'acf_field_name',
                                'type'        => 'select',
                                'label'       => __('Monitored relationship field', 'meta-conductor'),
                                'description' => __('The post-object or relationship field connecting the two posts. Watched at both ends — a change to either post re-syncs. The post type that OWNS this field is the "field holder"; "Source" below decides which end\'s terms win. ⚠ Only top-level relationship/post-object fields are listed — fields nested inside an ACF Group, Repeater, or Flexible Content container are not shown and are not currently supported. ⚠ If two DIFFERENT field groups define separate relationship fields with the SAME field name, reverse-lookup and post-type detection may resolve the wrong one — give same-named fields distinct names, or set an explicit Reverse relationship field below.', 'meta-conductor'),
                                'default'     => '',
                                'required'    => true,
                                'columns'     => 12,
                                'args'        => [
                                    'options' => ConfigHelpers::acf_relationship_field_options(),
                                ],
                            ],
                            [
                                'id'          => 'holder_role',
                                'type'        => 'radio',
                                'label'       => __('Source (terms copied from)', 'meta-conductor'),
                                'description' => __('Which end is authoritative — its terms are copied to the other end. The trigger is ambient (a change at either end re-syncs); this decides direction.', 'meta-conductor'),
                                'default'     => 'source',
                                'columns'     => 12,
                                'args'        => [
                                    'options' => [
                                        'source' => __('Field holder → copies out to related posts (push)', 'meta-conductor'),
                                        'target' => __('Related posts → copies in to the field holder (pull)', 'meta-conductor'),
                                    ],
                                ],
                            ],
                            ConfigHelpers::post_status_field([
                                'label'       => __('Limit to source statuses', 'meta-conductor'),
                                'description' => __('Only copy terms FROM source posts with these statuses. Leave all unchecked to allow any status.', 'meta-conductor'),
                            ]),
                            [
                                'id'          => 'reverse_acf_field_name',
                                'type'        => 'select',
                                'label'       => __('Reverse relationship field (optional)', 'meta-conductor'),
                                'description' => __('The inverse relationship field on the other end, if any. Speeds the reverse lookup. Leave blank to auto-detect ACF bidirectional fields. ⚠ Only top-level relationship/post-object fields are listed — group/repeater/flexible-content-nested fields are not shown or supported. ⚠ With neither an explicit reverse field nor a detectable bidirectional field, the reverse lookup falls back to an unindexed query on every save — slow on large sites. Set this (or use an ACF bidirectional field) to avoid it.', 'meta-conductor'),
                                'default'     => '',
                                'columns'     => 12,
                                'args'        => [
                                    'options' => ConfigHelpers::acf_relationship_field_options(__('— None / auto —', 'meta-conductor')),
                                ],
                            ],
                            [
                                'id'          => 'taxonomy',
                                'type'        => 'select',
                                'label'       => __('Taxonomy', 'meta-conductor'),
                                'description' => __('The taxonomy to copy terms in. Same taxonomy on both ends — terms are copied by ID.', 'meta-conductor'),
                                'default'     => '',
                                'required'    => true,
                                'columns'     => 12,
                                'args'        => [
                                    'options' => ConfigHelpers::taxonomy_options(),
                                ],
                            ],
                            [
                                'id'          => 'keep_in_sync',
                                'type'        => 'toggle',
                                'label'       => __('Keep in sync', 'meta-conductor'),
                                'description' => __('Remove copied terms from the target when the source no longer has them. Off = add-only (never removes).', 'meta-conductor'),
                                'default'     => true,
                                'columns'     => 12,
                            ],
                            // Snapshot label for the row title (SPEC §V10). Not
                            // user-editable; populated at save by the
                            // snapshot_acf_reference_labels payload filter.
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
