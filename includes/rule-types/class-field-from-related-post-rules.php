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
use BWS\MetaConductor\Relationships\ParentRelationship;
use BWS\MetaConductor\Relationships\Relationship;
use BWS\MetaConductor\Storage\OptionRuleStorage;

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
     * The parent graph for a `parent` row; the ACF graph joins it with FW-5 08.
     */
    public function relationship(array $rule): ?Relationship {
        return ($rule['relationship'] ?? '') === 'parent' ? new ParentRelationship() : null;
    }

    /**
     * The ACF field key the rule reads, trimmed. '' when unfinished or when the
     * source is a plain meta key (see `source_meta()`).
     */
    public function source_key(array $rule): string {
        $key = trim((string) ($rule['source_field'] ?? ''));

        return $key === self::META ? '' : $key;
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

        $field = $this->is_meta_source($row)
            ? trim((string) ($row['source_meta'] ?? ''))
            : ConfigHelpers::acf_field_label($this->source_key($row));

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
     * `relationship` offers the parent only for now. `source_meta` / `target_meta`
     * show only while their select picks a plain meta key; the `when` gate
     * drops a stale name on save once the select moves back to an ACF field.
     *
     * No bare `{word}` in a description (H12 enforces); the colon tokens
     * are safe.
     *
     * @return array[]
     */
    public function subfields(): array {
        return [
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
                    ],
                ],
            ],
            [
                'id'          => 'source_field',
                'type'        => 'select',
                'label'       => __('Field to copy', 'meta-conductor'),
                'description' => __('The ACF field copied from the parent post, as the raw stored value — a list of posts, an image or a whole repeater arrives intact. Each option lists the post types its field group is shown on; the copy only runs on a post type that shows the field. The rule remembers the field itself, so renaming it in ACF does not break the rule. Only top-level fields are listed.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => self::with_meta_option(ConfigHelpers::acf_source_field_options()),
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
                'description' => __('Both the receiving post and the parent it copies from must have one of the checked statuses; a parent that does not is ignored and the post keeps its value. Leave all unchecked to apply to every status.', 'meta-conductor'),
            ]),
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
