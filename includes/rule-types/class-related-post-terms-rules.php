<?php
/**
 * Descriptor for `related_post_terms_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Handlers\RelatedPostTermsHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `related_post_terms_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class RelatedPostTermsRules extends RuleType {

    public function type(): string {
        return 'related_post_terms_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('From referenced post (ACF) — copy terms across a relationship field', 'meta-conductor');
    }

    public function handler_class(): string {
        return RelatedPostTermsHandler::class;
    }

    public function reads_shared_fields(): array {
        return ['taxonomy', 'acf_taxonomy_note', 'acf_status_note'];
    }

    /**
     * Split the combined ACF relationship-field values ("post_type:field_name:
     * field_key") into scalar post_type + bare field name + key (#25). A legacy
     * two-part value yields an empty key, which callers read as "resolve by
     * name", i.e. pre-#25 behavior.
     */
    public function normalize(array $row): array {
        if (!empty($row['acf_field_name'])) {
            [$pt, $bare, $key]     = OptionRuleStorage::split_acf_field_value((string) $row['acf_field_name']);
            $row['acf_field_name'] = $bare;
            $row['acf_field_key']  = $key;
            if ($pt !== null) {
                $row['post_type'] = $pt;
            } elseif (!isset($row['post_type'])) {
                $row['post_type'] = '';
            }
        }

        // Reverse field is stored in the same option format; the handler
        // wants the bare field name and, since #25, the key beside it.
        if (!empty($row['reverse_acf_field_name'])) {
            [, $rbare, $rkey]              = OptionRuleStorage::split_acf_field_value((string) $row['reverse_acf_field_name']);
            $row['reverse_acf_field_name'] = $rbare;
            $row['reverse_acf_field_key']  = $rkey;
        }

        return $row;
    }

    /**
     * From referenced post (ACF) — copy taxonomy terms between a post and the
     * posts it relates to via an ACF relationship / post-object field.
     *
     * LIVE on a real site (#58). The `acf_field_name` select stores the
     * COMBINED "post_type:field_name:field_key" value and must round-trip it
     * whole: the option keys are combined, so persisting the split form would
     * render the select empty and the next save would blank the field. The
     * handler splits at read time (`normalize_rule_shape`). Same for
     * `reverse_acf_field_name`. The trailing field key is what makes two
     * same-named fields distinguishable (#25); a row stored before it existed
     * keeps its two-part value until re-picked and resolves by name meanwhile.
     *
     * `taxonomy` and `post_status` come from the shared frame; the two
     * type-gated notes beside them carry this type's semantics.
     *
     * @since 0.8.0
     * @return array[]
     */
    public function subfields(): array {
        return [
            [
                'id'          => 'acf_field_name',
                'type'        => 'select',
                'label'       => __('Monitored relationship field', 'meta-conductor'),
                'description' => __('The post-object or relationship field connecting the two posts. Watched at both ends — a change to either post re-syncs. The post type that OWNS this field is the "field holder"; "Source" below decides which end\'s terms win. Each option names its field group, so two fields sharing a name can be told apart. ⚠ Only top-level relationship/post-object fields are listed — fields nested inside an ACF Group, Repeater, or Flexible Content container are not shown and are not currently supported.', 'meta-conductor'),
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
                'id'          => 'keep_in_sync',
                'type'        => 'toggle',
                'label'       => __('Keep in sync', 'meta-conductor'),
                'description' => __('Remove copied terms from the target when the source no longer has them. Off = add-only (never removes).', 'meta-conductor'),
                'default'     => true,
                'columns'     => 12,
            ],
        ];
    }
}
