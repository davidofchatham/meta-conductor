<?php
/**
 * Descriptor for `terms_from_related_post` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Handlers\TermsFromRelatedPostHandler;
use BWS\MetaConductor\Relationships\AcfRelationship;
use BWS\MetaConductor\Relationships\ParentRelationship;
use BWS\MetaConductor\Relationships\Relationship;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Set terms from a related post (FW-61): a post takes terms from a post it is
 * related to, by a `relationship` the row names. `parent` is the post's
 * `post_parent` (what propagation did); `acf` is a post an ACF relationship or
 * post-object field names (what Related Post Terms did).
 *
 * Everything that differs by relationship is answered by the relationship
 * module (`written_post_types()`, `status_gates_source()`), so the collision
 * detector and the Apply page never re-derive it.
 */
final class TermsFromRelatedPostRules extends RuleType {

    public function type(): string {
        return 'terms_from_related_post';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Set terms from a related post', 'meta-conductor');
    }

    public function handler_class(): string {
        return TermsFromRelatedPostHandler::class;
    }

    public function target_key(array $rule): ?string {
        return self::taxonomy_target($rule);
    }

    /**
     * Schema, by relationship:
     *   parent: {Scope: }Copy {Taxonomy} terms to children ({claim})
     *           e.g. "Pages: Copy Breakers terms to children (owning)"
     *   acf:    Copy {Taxonomy} terms {to|from} {field label}{ on {statuses}} ({claim})
     *           e.g. "Copy Categories terms from Related teams on Published (owning)"
     * No arrow in either — same term, same taxonomy, moved across a relationship.
     */
    public function row_title(array $row): string {
        $claim = Labels::claim_label($row['conflict_handling'] ?? 'merge');

        if (($row['relationship'] ?? '') === 'acf') {
            return $this->acf_row_title($row, $claim);
        }

        return Labels::scope_prefix($row['post_types'] ?? []) . sprintf(
            /* translators: 1: taxonomy label 2: claim */
            __('Copy %1$s terms to children (%2$s)', 'meta-conductor'),
            Labels::taxonomy_label($row['taxonomy'] ?? ''),
            $claim
        );
    }

    /**
     * `to|from` ← holder_role (source = push, target = pull); an ABSENT
     * holder_role defaults to `target`, matching the relationship
     * (`holder_is_source`), because defaulting to `source` here would title a
     * rule with a direction it does not run in. The field label is
     * acf_get_field()'s clean label, falling back to the field name.
     */
    private function acf_row_title(array $row, string $claim): string {
        $prep = (($row['holder_role'] ?? 'target') === 'source')
            ? __('to', 'meta-conductor')
            : __('from', 'meta-conductor');

        $title = trim(sprintf(
            /* translators: 1: taxonomy 2: to/from 3: field label */
            __('Copy %1$s terms %2$s %3$s', 'meta-conductor'),
            Labels::taxonomy_label($row['taxonomy'] ?? ''),
            $prep,
            self::acf_field_label($row['acf_field_name'] ?? '', $row['acf_field_key'] ?? '')
        ));

        $gate = Labels::status_gate_label($row['post_status'] ?? []);
        if ($gate !== '') {
            $title .= ' ' . sprintf(__('on %s', 'meta-conductor'), $gate);
        }

        return $title . ' (' . $claim . ')';
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
        return [
            'taxonomy',
            'post_types',
            'hierarchical_post_type_note',
            'acf_taxonomy_note',
            'acf_status_note',
            'conflict_handling',
        ];
    }

    /**
     * Which `relationship` each note belongs to; every other shared field is
     * read under both. Each note is `html` (carries no value), so the gate is
     * about not offering text that describes the other relationship.
     */
    public function shared_field_when(string $field): array {
        return match ($field) {
            'hierarchical_post_type_note'         => ['relationship' => 'parent'],
            'acf_taxonomy_note', 'acf_status_note' => ['relationship' => 'acf'],
            default                               => [],
        };
    }

    /**
     * Split the combined ACF relationship-field values ("post_type:field_name:
     * field_key") into scalar post_type + bare field name + key (#25). A legacy
     * two-part value yields an empty key, which callers read as "resolve by
     * name", i.e. pre-#25 behavior. Only an `acf` row carries them.
     */
    public function normalize(array $row): array {
        if (($row['relationship'] ?? '') !== 'acf') {
            return $row;
        }

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

        // Reverse field is stored in the same option format; the relationship
        // wants the bare field name and, since #25, the key beside it.
        if (!empty($row['reverse_acf_field_name'])) {
            [, $rbare, $rkey]              = OptionRuleStorage::split_acf_field_value((string) $row['reverse_acf_field_name']);
            $row['reverse_acf_field_name'] = $rbare;
            $row['reverse_acf_field_key']  = $rkey;
        }

        return $row;
    }

    /**
     * The `relationship` select, then the ACF-only controls, each carrying a
     * `when` that `ConfigHelpers::type_subfields()` turns into a second gate
     * beside the type: they show, and persist, on an `acf` row alone. Flipping a
     * row to `parent` therefore drops them on save, the same trade changing
     * `type` already makes.
     *
     * Both relationship options stay in the list whatever is installed. An
     * option that vanished would drop a stored `acf` value on the next save, so
     * an unavailable one is only labeled.
     *
     * The ACF field select stores the COMBINED "post_type:field_name:field_key"
     * value and must round-trip it whole: the option keys are combined, so
     * persisting the split form would render the select empty and the next save
     * would blank the field. `normalize()` splits at read time. Same for
     * `reverse_acf_field_name`. The trailing field key is what makes two
     * same-named fields distinguishable (#25); a row stored before it existed
     * keeps its two-part value until re-picked and resolves by name meanwhile.
     *
     * `taxonomy`, `post_types`, `post_status` and the claim come from the shared
     * frame.
     *
     * @since 0.8.0
     * @return array[]
     */
    public function subfields(): array {
        $acf = ['relationship' => 'acf'];

        $fields = [
            [
                'id'          => 'relationship',
                'type'        => 'select',
                'label'       => __('Relationship', 'meta-conductor'),
                'description' => __('Where the source post comes from. Changing it clears the settings that belonged to the other relationship.', 'meta-conductor'),
                'default'     => 'parent',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        'parent' => __('Parent post — a post takes terms from its parent', 'meta-conductor'),
                        'acf'    => AcfRelationship::is_available()
                            ? __('ACF relationship field — posts joined by a relationship or post-object field', 'meta-conductor')
                            : __('ACF relationship field — requires ACF, which is not active', 'meta-conductor'),
                    ],
                ],
            ],
        ];

        // The notice a stored ACF row shows while ACF is off. Declared only
        // then: with ACF active it would be a permanent inert note.
        if (!AcfRelationship::is_available()) {
            $fields[] = [
                'id'      => 'acf_required_note',
                'type'    => 'html',
                'columns' => 12,
                'when'    => $acf,
                'args'    => [
                    'variant' => 'warning',
                    'content' => '<p>' . esc_html__('This rule requires ACF, which is not active. It does nothing until ACF is active again; its settings are kept.', 'meta-conductor') . '</p>',
                ],
            ];
        }

        return array_merge($fields, [
            [
                'id'          => 'acf_field_name',
                'type'        => 'select',
                'label'       => __('Monitored relationship field', 'meta-conductor'),
                'description' => __('The post-object or relationship field connecting the two posts. Watched at both ends — a change to either post re-syncs. The post type that OWNS this field is the "field holder"; "Source" below decides which end\'s terms win. Each option names its field group, so two fields sharing a name can be told apart. ⚠ Only top-level relationship/post-object fields are listed — fields nested inside an ACF Group, Repeater, or Flexible Content container are not shown and are not currently supported.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'when'        => $acf,
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
                'when'        => $acf,
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
                'when'        => $acf,
                'args'        => [
                    'options' => ConfigHelpers::acf_relationship_field_options(__('— None / auto —', 'meta-conductor')),
                ],
            ],
        ]);
    }

    /**
     * The post types this row writes, as its relationship reports them.
     */
    public function written_post_types(array $rule): array {
        return $this->relationship($rule)?->written_post_types($rule) ?? [];
    }

    /**
     * Whether `post_status` gates the SOURCE (true) or the written post, as
     * the row's relationship states it. For `parent` it gates the written post
     * and the parent with it; for `acf` the source alone.
     */
    public function status_gates_source(array $rule): bool {
        return $this->relationship($rule)?->status_gates_source($rule) ?? false;
    }

    /**
     * The union of both old types' captures, each reading state the write is
     * about to destroy.
     *
     * `deleted_term_relationships` is the parent's: the pull applier cannot tell
     * a term the parent HELD and lost from one the child holds independently, so
     * the removal is captured as it happens (it is the only hook that sees it,
     * including inside `wp_set_object_terms`, #62).
     *
     * The other three are the ACF relationship's. The two `acf/update_value`
     * filters run at priority 5, before ACF replaces the value, and
     * `before_delete_post` is the last moment a dying post's relationships can
     * be read (arch invariant #5). What they capture is a SEVER: unrecoverable
     * afterwards, because a link just cut and a link that never existed are the
     * same absence (#63).
     */
    public function capture_hooks(): array {
        return [
            'deleted_term_relationships',
            'acf/update_value/type=relationship',
            'acf/update_value/type=post_object',
            'before_delete_post',
        ];
    }

    /**
     * A sever names the one entity nothing points at any more — the link that
     * named it is what the write destroyed — so no fan-out can reach it. (The
     * parent's lost-terms capture concerns a parent whose children its own
     * `fan_out()` already reaches, and drains nothing.)
     */
    public function drains_captures(): bool {
        return true;
    }

    /**
     * A throwaway instance, enough for the pure facts above. The handler keeps
     * its own, because their request memos belong to it.
     */
    private function relationship(array $rule): ?Relationship {
        return match ((string) ($rule['relationship'] ?? '')) {
            'parent' => new ParentRelationship(),
            'acf'    => new AcfRelationship(),
            default  => null,
        };
    }
}
