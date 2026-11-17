<?php
/**
 * Descriptor for `field_from_related_post` rows — Set a field from a related post.
 *
 * @since 0.3.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Core\MetaWriter;
use BWS\MetaConductor\Handlers\FieldFromRelatedPostHandler;
use BWS\MetaConductor\Relationships\AcfRelationship;
use BWS\MetaConductor\Relationships\ParentRelationship;
use BWS\MetaConductor\Relationships\Relationship;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copies one ACF field's raw value from a related post onto the receiving post.
 * A format-kind row beside the pattern rules: the handler returns the value in
 * the seam's data array and the dispatcher writes it.
 *
 * Source and target are each an ACF field (by key) or a plain meta key. The
 * target is *Same* (the source's own field or meta name) unless the author picks
 * another; `source_field` / `copy_target` hold '' / an ACF key / `META`, and the
 * meta name rides in `source_meta` / `target_meta`.
 */
final class FieldFromRelatedPostRules extends RuleType {

    /** The `source_field` / `copy_target` value that means "a plain meta key". */
    public const META = '@meta';

    /** ACF's own prefix on a field group key; a `source_field` starting with it names a group. */
    private const GROUP_PREFIX = 'group_';

    public function type(): string {
        return 'field_from_related_post';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_FORMAT;
    }

    public function label(): string {
        return __('Set a field from a related post', 'meta-conductor');
    }

    public function handler_class(): string {
        return FieldFromRelatedPostHandler::class;
    }

    /**
     * The rule reads and writes through ACF, so without it there is nothing to
     * copy: the type is not offered and the handler skips any row already stored.
     */
    public function available(): bool {
        return function_exists('acf_get_field') && function_exists('update_field');
    }

    /**
     * The graph a row walks: the parent's, or the ACF relationship field's.
     */
    public function relationship(array $rule): ?Relationship {
        return match ((string) ($rule['relationship'] ?? '')) {
            'parent' => new ParentRelationship(),
            'acf'    => new AcfRelationship(),
            default  => null,
        };
    }

    /**
     * The ACF reference controls sit on an `acf` row, so the pass needs the
     * three captures a sever reads, and the queue must be told what they name.
     */
    public function capture_hooks(): array {
        return [
            'acf/update_value/type=relationship',
            'acf/update_value/type=post_object',
            'before_delete_post',
        ];
    }

    public function drains_captures(): bool {
        return true;
    }

    /**
     * Splits an `acf` row's combined field values, the same shape the term rule reads.
     */
    public function normalize(array $row): array {
        return AcfRelationship::normalize_row($row);
    }

    /**
     * Whether `post_status` gates the SOURCE, as the row's relationship states it.
     */
    public function status_gates_source(array $rule): bool {
        return $this->relationship($rule)?->status_gates_source($rule) ?? false;
    }

    /**
     * The ACF field key the rule reads, trimmed. '' when unfinished or when the
     * source is a plain meta key (see `source_meta()`).
     */
    public function source_key(array $rule): string {
        $key = trim((string) ($rule['source_field'] ?? ''));

        return $key === self::META || str_starts_with($key, self::GROUP_PREFIX) ? '' : $key;
    }

    /**
     * The ACF field GROUP key when the source is a whole group (group mode), else
     * ''. ACF prefixes every group key `group_` and every field key `field_`, so
     * the one `source_field` select holds both without a marker of our own.
     */
    public function source_group(array $rule): string {
        $key = trim((string) ($rule['source_field'] ?? ''));

        return str_starts_with($key, self::GROUP_PREFIX) ? $key : '';
    }

    public function is_meta_source(array $rule): bool {
        return trim((string) ($rule['source_field'] ?? '')) === self::META;
    }

    /**
     * The plain meta key the rule reads; '' when the source is not a meta key
     * or the name is refused (`MetaWriter::acceptable()`).
     */
    public function source_meta(array $rule): string {
        $name = trim((string) ($rule['source_meta'] ?? ''));

        return $this->is_meta_source($rule) && MetaWriter::acceptable($name) ? $name : '';
    }

    /**
     * Where the rule writes: `['key' => <ACF field key>, 'name' => '']` for an
     * ACF target, `['key' => '', 'name' => <meta name>]` for a plain meta key
     * (which `MetaWriter` still routes through ACF when the name matches a
     * field). Null while unfinished. *Same* is the source's own field or name.
     *
     * @return array{key:string,name:string}|null
     */
    public function target(array $rule): ?array {
        // Group mode has no single target: every member goes to the same group.
        if ($this->source_group($rule) !== '') {
            return null;
        }

        $pick = trim((string) ($rule['copy_target'] ?? ''));

        if ($pick === '') {
            $key  = $this->source_key($rule);
            $name = $this->source_meta($rule);
        } elseif ($pick === self::META) {
            $key  = '';
            $name = trim((string) ($rule['target_meta'] ?? ''));
            $name = MetaWriter::acceptable($name) ? $name : '';
        } else {
            $key  = $pick;
            $name = '';
        }

        return $key === '' && $name === '' ? null : ['key' => $key, 'name' => $name];
    }

    /**
     * One field, by its ACF field KEY — the `field|` scheme the pattern rule
     * uses, so two rules writing one field are order-decided and collide — or
     * `meta|<name>` for a plain meta key. A row with no post type checked is
     * unfinished: the handler skips it.
     */
    public function target_key(array $rule): ?string {
        $target = (array) ($rule['post_types'] ?? []) === [] ? null : $this->target($rule);
        if ($target === null) {
            return null;
        }

        return $target['key'] !== '' ? 'field|' . $target['key'] : 'meta|' . $target['name'];
    }

    /**
     * A group copy writes every member of the group, so it names each by the same
     * `field|<key>` a single-field rule does; any other row, its one target. The
     * members are read live (ACF may have added one since the rule was saved),
     * through the same `group_members()` the handler copies from, so the
     * collision scan and the Apply report agree with what the pass writes.
     */
    public function target_keys(array $rule): array {
        $group = $this->source_group($rule);
        if ($group === '') {
            return parent::target_keys($rule);
        }
        if ((array) ($rule['post_types'] ?? []) === []) {
            return [];
        }

        return array_map(static fn(string $key): string => 'field|' . $key, $this->group_members($group));
    }

    /**
     * The keys of a group's top-level value-holding fields, read now.
     *
     * @return string[] Empty when ACF cannot resolve the group.
     */
    public function group_members(string $group_key): array {
        if (!function_exists('acf_get_fields')) {
            return [];
        }
        $keys = [];
        foreach ((array) \acf_get_fields($group_key) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key !== '' && !in_array($field['type'] ?? '', ConfigHelpers::NON_VALUE_TYPES, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The checked `post_types`; `['']` when none, which intersects nothing,
     * rather than [], which would claim every post type — see
     * `FieldFromPatternRules::written_post_types()`.
     */
    public function written_post_types(array $rule): array {
        $types = array_values(array_filter(array_map('strval', (array) ($rule['post_types'] ?? []))));

        return $types ?: [''];
    }

    /**
     * Schema:
     *   {name} — Copy {field_label} from parent ({claim}){ (Post type)}
     *   e.g. "Venue address — Copy Address from parent (contributing) (MC Items)"
     */
    public function row_title(array $row): string {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            $name = __('Untitled copy-from-related-post rule', 'meta-conductor');
        }

        $group = $this->source_group($row);
        $field = $this->is_meta_source($row)
            ? trim((string) ($row['source_meta'] ?? ''))
            : ($group !== ''
                ? ConfigHelpers::acf_group_title($group)
                : ConfigHelpers::acf_field_label($this->source_key($row)));

        return $name
            . ' — ' . sprintf(
                /* translators: 1: field label, 2: claim name */
                __('Copy %1$s from parent (%2$s)', 'meta-conductor'),
                $field !== '' ? $field : __('a field', 'meta-conductor'),
                Labels::claim_label($row['conflict_handling'] ?? 'merge')
            )
            . Labels::scope_label((array) ($row['post_types'] ?? []));
    }

    /**
     * `post_types` is the format list's shared scope (see
     * `FieldFromPatternRules::reads_shared_fields()`).
     */
    public function reads_shared_fields(): array {
        return ['post_types'];
    }

    /**
     * Every field here is gated to this type (the kind config stamps it).
     *
     * The ACF controls show, and persist, on an `acf` row alone (the
     * `relationship` gate), and store the combined "post_type:field_name:
     * field_key" value whole, as the term rule does. `source_meta` / `target_meta`
     * show only while their select picks a plain meta key; the `when` gate
     * drops a stale name on save once the select moves back to an ACF field.
     *
     * No bare `{word}` in a description (H12 enforces); the colon tokens
     * are safe.
     *
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
                        'parent' => __('Parent post — a post takes the field from its parent', 'meta-conductor'),
                        'acf'    => AcfRelationship::is_available()
                            ? __('ACF relationship field — posts joined by a relationship or post-object field', 'meta-conductor')
                            : __('ACF relationship field — requires ACF, which is not active', 'meta-conductor'),
                    ],
                ],
            ],
            [
                'id'          => 'acf_field_name',
                'type'        => 'select',
                'label'       => __('Monitored relationship field', 'meta-conductor'),
                'description' => __('The post-object or relationship field connecting the two posts. The post type that OWNS this field is the "field holder"; "Source" below decides which end the value is copied from. Only one related post counts as the source: the first one that holds a value, taken in the order the field lists its posts (pull), or in the order the reverse field lists them, else by ascending post ID (push). Each option names its field group, so two fields sharing a name can be told apart. ⚠ Only top-level relationship/post-object fields are listed.', 'meta-conductor'),
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
                'label'       => __('Source (field copied from)', 'meta-conductor'),
                'description' => __('Which end is authoritative — its value is copied to the other end. The trigger is ambient (a change at either end re-syncs); this decides direction.', 'meta-conductor'),
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
                'description' => __('The inverse relationship field on the other end, if any. Speeds the reverse lookup and, in a push, orders the sources. Leave blank to auto-detect ACF bidirectional fields. ⚠ With neither an explicit reverse field nor a detectable bidirectional field, the reverse lookup falls back to an unindexed query on every save — slow on large sites. Set this (or use an ACF bidirectional field) to avoid it.', 'meta-conductor'),
                'default'     => '',
                'columns'     => 12,
                'when'        => $acf,
                'args'        => [
                    'options' => ConfigHelpers::acf_relationship_field_options(__('— None / auto —', 'meta-conductor')),
                ],
            ],
            [
                'id'          => 'source_field',
                'type'        => 'select',
                'label'       => __('Field to copy', 'meta-conductor'),
                'description' => __('The ACF field copied from the parent post, as the raw stored value — a list of posts, an image or a whole repeater arrives intact. Each option lists the post types its field group is shown on; the copy only runs on a post type that shows the field. The rule remembers the field itself, so renaming it in ACF does not break the rule. Only top-level fields are listed. Choosing a field group instead copies every top-level field of the group into the same group on the receiving post, including fields added to the group later; Copy into is then ignored.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => self::with_meta_option(ConfigHelpers::acf_source_field_options()) + ConfigHelpers::acf_group_options(),
                ],
            ],
            self::meta_name_field('source_meta', 'source_field', __('Meta key to copy', 'meta-conductor'), __('The plain post meta key read from the parent. When the key has several values only the first is copied. A key starting with an underscore is refused.', 'meta-conductor')),
            [
                'id'          => 'copy_target',
                'type'        => 'select',
                'label'       => __('Copy into', 'meta-conductor'),
                'description' => __('Same writes the field of the same name on the receiving post. It is offered for a field only when the field\'s group is shown on the receiving post type; on any other type the rule does nothing. Another ACF field must have the same field type as the source (the type is in the label); a target of a different type is left alone. A meta key that matches an ACF field is written through ACF.', 'meta-conductor'),
                'default'     => '',
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        ''         => __('Same field', 'meta-conductor'),
                        self::META => __('A plain meta key…', 'meta-conductor'),
                    ] + ConfigHelpers::acf_target_field_options(),
                ],
            ],
            self::meta_name_field('target_meta', 'copy_target', __('Meta key to write', 'meta-conductor'), __('The plain post meta key written on the receiving post. A key starting with an underscore is refused.', 'meta-conductor')),
            ConfigHelpers::claim_field('post', [
                'id'          => 'conflict_handling',
                'label'       => __('Claim on the field', 'meta-conductor'),
                'description' => __('Owning makes the field always equal the parent\'s, including empty. Contributing writes a value the parent holds and never clears. Deferring fills the field only while it is empty. A post with no eligible parent is always left alone.', 'meta-conductor'),
                'args'        => [
                    'options' => [
                        'replace' => __('Owning: the field always equals the parent\'s, including when the parent\'s is empty', 'meta-conductor'),
                        'merge'   => __('Contributing: copy the parent\'s value when it has one, never clear', 'meta-conductor'),
                        'skip'    => __('Deferring: copy only into a field that is empty', 'meta-conductor'),
                    ],
                ],
            ]),
            ConfigHelpers::post_status_field([
                'description' => __('The status the source must have: a parent (or, with an ACF field, a related post) that does not have one of the checked statuses is ignored and the receiving post keeps its value. With the parent, the receiving post must have one of them too. Leave all unchecked to apply to every status.', 'meta-conductor'),
            ]),
        ];

        // The notice a stored ACF row shows while ACF is off, right under the
        // relationship select. Declared only then: with ACF active it would be
        // a permanent inert note.
        if (!AcfRelationship::is_available()) {
            array_splice($fields, 1, 0, [[
                'id'      => 'acf_required_note',
                'type'    => 'html',
                'columns' => 12,
                'when'    => $acf,
                'args'    => [
                    'variant' => 'warning',
                    'content' => '<p>' . esc_html__('This rule requires ACF, which is not active. It does nothing until ACF is active again; its settings are kept.', 'meta-conductor') . '</p>',
                ],
            ]]);
        }

        return $fields;
    }

    /**
     * Page-level advisory, null when there is nothing to say: an enabled `acf`
     * row whose field is bidirectional and links posts of one type, where two
     * posts may source each other. The cycle is allowed and ends at
     * `TermDispatcher::drain()`'s per-entity bound; this only says so. Built
     * from the stored rows, as `DuplicateNameNotice` is.
     */
    public static function loop_notice(): ?array {
        if (!AcfRelationship::is_available()) {
            return null;
        }

        $graph = new AcfRelationship();
        $names = [];
        foreach (StorageFactory::get_instance()->get_rules('field_from_related_post', ['enabled' => true]) as $row) {
            if (($row['relationship'] ?? '') === 'acf' && $graph->loops_on_one_type($row)) {
                $names[] = (string) ($row['name'] ?? '') !== '' ? (string) $row['name'] : __('(unnamed rule)', 'meta-conductor');
            }
        }
        if ($names === []) {
            return null;
        }

        // A rule name is author text and `html` content interpolates `{word}`.
        $list = implode(', ', array_map(static fn(string $n): string => str_replace(['{', '}'], ['&#123;', '&#125;'], esc_html($n)), $names));

        return [
            'id'      => 'field_from_related_post_loop',
            'type'    => 'html',
            'columns' => 12,
            'args'    => [
                'variant' => 'warning',
                'content' => '<p>' . esc_html__('A bidirectional ACF field links posts of one post type, so two posts may source each other. The copy still ends, but the value one ends up with depends on which post is saved last. Rules:', 'meta-conductor') . ' ' . $list . '</p>',
            ],
        ];
    }

    /**
     * The select's leading placeholder, then the plain-meta choice, then the ACF fields.
     *
     * @param array<string,string> $options `ConfigHelpers::acf_source_field_options()`.
     * @return array<string,string>
     */
    private static function with_meta_option(array $options): array {
        return ['' => $options[''] ?? '', self::META => __('A plain meta key…', 'meta-conductor')] + $options;
    }

    /**
     * A meta-name text subfield shown while `$select` picks the plain-meta
     * choice. A leading underscore fails the save (Rakit `regex`, run by
     * Wireframe's validator before anything is stored); the same bar is
     * `MetaWriter::acceptable()`, which the handler asks again at run time.
     */
    private static function meta_name_field(string $id, string $select, string $label, string $description): array {
        return [
            'id'          => $id,
            'type'        => 'text',
            'label'       => $label,
            'description' => $description,
            'default'     => '',
            'required'    => true,
            'columns'     => 12,
            'when'        => [$select => self::META],
            'validation'  => 'regex:/^\s*[^_\s]/',
        ];
    }
}
