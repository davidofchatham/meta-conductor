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

    public function target_key(array $rule): ?string {
        return self::taxonomy_target($rule);
    }

    /**
     * The DEPENDENT end, not `post_types` — this type has no such subfield
     * (#58). Under `holder_role = source` (push) the dependents are the related
     * posts, whose type the rule never constrains — so "all", exactly as
     * `dependent_post_type()` reports it.
     */
    public function written_post_types(array $rule): array {
        if ((string) ($rule['holder_role'] ?? 'target') === 'source') {
            return [];
        }
        $post_type = trim((string) ($rule['post_type'] ?? ''));

        return $post_type === '' ? [] : [$post_type];
    }

    /**
     * `post_status` gates the SOURCE here (don't 6e(b)); narrowing the
     * dependents by it would silently drop a draft dependent of a published
     * source from a run.
     */
    public function status_gates_source(): bool {
        return true;
    }

    /**
     * No A→B arrow: same term, same taxonomy, moved across a relationship.
     *
     * Schema: {Copy|Sync} {Taxonomy} terms {to|from} {field_label}{ on {statuses}}
     *   Copy|Sync ← keep_in_sync (off|on)
     *   to|from   ← holder_role (source=to/push | target=from/pull)
     *   field_label ← acf_get_field()['label'] (clean human label), fallback name
     *   on {statuses} ← post_status gate, only when set
     */
    public function row_title(array $row): string {
        $verb = !empty($row['keep_in_sync'])
            ? __('Sync', 'meta-conductor')
            : __('Copy', 'meta-conductor');

        // Default an ABSENT holder_role to 'target', matching the handler
        // (holder_is_source) — NOT 'source'. Defaulting to 'source' here would
        // write a row title that lies about the rule's runtime direction. A
        // new rule always carries an explicit holder_role. (PR#24 round 4 #2)
        $prep = (($row['holder_role'] ?? 'target') === 'source')
            ? __('to', 'meta-conductor')
            : __('from', 'meta-conductor');

        $gate = Labels::status_gate_label($row['post_status'] ?? []);

        // Assemble; tolerate empty parts gracefully.
        $title = trim(sprintf(
            /* translators: 1: Copy/Sync 2: taxonomy 3: to/from 4: field label */
            __('%1$s %2$s terms %3$s %4$s', 'meta-conductor'),
            $verb,
            Labels::taxonomy_label($row['taxonomy'] ?? ''),
            $prep,
            self::acf_field_label($row['acf_field_name'] ?? '', $row['acf_field_key'] ?? '')
        ));

        if ($gate !== '') {
            $title .= ' ' . sprintf(__('on %s', 'meta-conductor'), $gate);
        }

        return $title;
    }

    /**
     * Resolve a projected ACF relationship field (bare name + key) to its clean
     * human label via acf_get_field(). Falls back to the bare field name.
     * (architecture.md → Canonical shape adapter)
     *
     * Resolves by KEY when the row carries one: two separately-created fields
     * can share a bare name, and a row title showing the wrong field's label is
     * how an author would be told the wrong thing about their own rule. (#25)
     *
     * @param string $name Bare field name.
     * @param string $key  Field key; '' for a legacy two-part value.
     * @return string Unescaped label.
     */
    private static function acf_field_label(string $name, string $key): string {
        if ($name === '') {
            return '';
        }

        if (function_exists('acf_get_field')) {
            // Key first; the name is the fallback for a key that no longer
            // resolves, so a stale row still shows a label rather than a blank.
            $field = $key !== '' ? \acf_get_field($key) : null;
            if (!is_array($field)) {
                $field = \acf_get_field($name);
            }
            if (is_array($field) && !empty($field['label'])) {
                return (string) $field['label'];
            }
        }
        return $name;
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
