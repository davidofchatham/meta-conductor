<?php
/**
 * Descriptor for `field_from_related_post` rows — Set a field from a related post.
 *
 * @since 0.12.0
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
 * Authored in the Auto-Set & Restrict list beside *Set terms from a related
 * post* (both mirror a value in from another post) but executed by the format
 * pass: the handler returns the value in the seam's data array and the
 * dispatcher writes it. `kind()` is the list, `pass()` the executor.
 *
 * Source and target are each an ACF field (by key) or a plain meta key. The
 * target is *Same* (the source's own field or meta name) unless the author picks
 * another; `source_field` / `copy_target` hold '' / an ACF key / `META`, and the
 * meta name rides in `source_meta` / `target_meta`.
 *
 * The type is offered whether or not ACF is active: a parent row over plain
 * meta needs only core, and a stored row keeps its type on save (a select drops
 * a value that is not among its options). A row that needs ACF (see
 * `relationship()`) is idle while ACF is off and keeps its settings. Authoring
 * one still needs ACF, because the ACF field pickers are built from it.
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
        return OptionRuleStorage::KIND_TERM;
    }

    /**
     * Runs in the format pass, so it writes through the `$data` seam and the
     * dispatcher's one write site. The Auto-Set list's rows go first in that
     * pass, so a pattern rule reads the value this one just copied.
     */
    public function pass(): string {
        return OptionRuleStorage::KIND_FORMAT;
    }

    public function label(): string {
        return __('Set a field from a related post', 'meta-conductor');
    }

    public function handler_class(): string {
        return FieldFromRelatedPostHandler::class;
    }

    /**
     * The graph a row walks: the parent's, or the ACF relationship field's.
     *
     * Null for a row that cannot run while ACF is off, which is how the handler
     * and the format dispatcher's fan-out both skip it: an `acf` row, or one that
     * names an ACF field or group as source or target. The type itself stays
     * available (see `needs_acf()`), so a stored row keeps its type and its
     * settings on save, and a `parent` row copying plain meta keeps working.
     */
    public function relationship(array $rule): ?Relationship {
        if (!self::acf_loaded() && $this->needs_acf($rule)) {
            return null;
        }

        return match ((string) ($rule['relationship'] ?? '')) {
            'parent' => new ParentRelationship(),
            'acf'    => new AcfRelationship(),
            default  => null,
        };
    }

    /**
     * Whether the row reads or writes through ACF: an `acf` relationship, an ACF
     * field or group as source, or an ACF field as the target. A plain meta key
     * at both ends over the parent needs nothing but core.
     */
    private function needs_acf(array $rule): bool {
        $source = trim((string) ($rule['source_field'] ?? ''));
        $target = trim((string) ($rule['copy_target'] ?? ''));

        return ($rule['relationship'] ?? '') === 'acf'
            || ($source !== '' && $source !== self::META)
            || ($target !== '' && $target !== self::META);
    }

    private static function acf_loaded(): bool {
        return function_exists('acf_get_field') && function_exists('update_field');
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
     * `meta|<name>` for a plain meta key.
     */
    public function target_key(array $rule): ?string {
        $target = $this->target($rule);
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
     * Schema:
     *   {name} — Copy {field_label} {from parent|to related posts|from related posts} ({claim}){ (Post type)}
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

        // The direction an `acf` row copies is its holder role: the holder is the
        // source in a push, and an absent role means pull (`AcfRelationship`).
        $from = match (true) {
            ($row['relationship'] ?? '') !== 'acf'   => __('from parent', 'meta-conductor'),
            ($row['holder_role'] ?? '') === 'source' => __('to related posts', 'meta-conductor'),
            default                                   => __('from related posts', 'meta-conductor'),
        };

        return $name
            . ' — ' . sprintf(
                /* translators: 1: field label, 2: "from parent" / "to related posts" / "from related posts", 3: claim name */
                __('Copy %1$s %2$s (%3$s)', 'meta-conductor'),
                $field !== '' ? $field : __('a field', 'meta-conductor'),
                $from,
                Labels::claim_label($row['conflict_handling'] ?? 'merge')
            )
            . Labels::scope_label((array) ($row['post_types'] ?? []));
    }

    /**
     * The shared scope and claim, plus the note that says what they mean here.
     * The Auto-Set list's scope offers every public post type, attachment
     * included, where the format list drops it; a copy scoped to
     * media is accepted, not refused.
     *
     * `post_types` and `conflict_handling` are the Auto-Set list's shared
     * subfields, and `post_status` its ungated one. The controls are shared; the
     * semantics are this type's, and a note under the claim and the status control states them (`field_copy_claim_note`: the claim is on the field; `field_copy_status_note`: the status is the source's). The scope needs no note: none checked is every public post type but attachment, as on the term rules, and an ACF target is still written only where its group is shown. A type that reads a shared value must be in its gate or the value drops on save (architecture.md → *The ordered rule repeaters*).
     */
    public function reads_shared_fields(): array {
        return [
            'post_types',
            'conflict_handling',
            'field_copy_status_note',
            'field_copy_claim_note',
        ];
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
        $fields = [
            [
                // The author's own label for the row; the row-title snapshot
                // reads it. Own to this type here because the Auto-Set list's
                // shared frame has no `name`.
                'id'       => 'name',
                'type'     => 'text',
                'label'    => __('Rule name', 'meta-conductor'),
                'required' => true,
                'columns'  => 12,
                'args'     => [
                    'placeholder' => __('e.g. Season label from team', 'meta-conductor'),
                ],
            ],
            ...ConfigHelpers::acf_relationship_subfields(),
            [
                // Which related post a copy reads, said once the ACF
                // relationship is chosen. A parent has exactly one source.
                'id'      => 'field_copy_source_note',
                'type'    => 'html',
                'columns' => 12,
                'when'    => ['relationship' => 'acf'],
                'args'    => [
                    'variant' => 'info',
                    'content' => '<p>' . self::copy_note('source') . '</p>',
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
                    'options' => self::with_meta_option(ConfigHelpers::acf_source_field_options()) + ConfigHelpers::acf_group_options() + self::stored_while_acf_off('source_field'),
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
                        self::META => self::meta_option_label(),
                    ] + ConfigHelpers::acf_target_field_options() + self::stored_while_acf_off('copy_target'),
                ],
            ],
            self::meta_name_field('target_meta', 'copy_target', __('Meta key to write', 'meta-conductor'), __('The plain post meta key written on the receiving post. A key starting with an underscore is refused.', 'meta-conductor')),
        ];

        return $fields;
    }

    /**
     * What the Auto-Set list's shared scope, status and claim controls mean for
     * a field copy, one text per control, escaped. Each is shown as an `html`
     * note directly under the control it explains (the term config's
     * `field_copy_*_note`, gated to this type through `reads_shared_fields()`),
     * and `source` under the ACF relationship block (`subfields()`), so the
     * wording lives with the type and the help sits next to the control.
     *
     * No bare `{word}` (H12): `html` content interpolates `{word}` against the
     * row.
     *
     * @param string $which `post_status`, `claim` or `source`.
     */
    public static function copy_note(string $which): string {
        return esc_html(match ($which) {
            'post_status' => __('For this rule type the status limit applies to the source: it must have one of the checked statuses, or it is ignored and the receiving post keeps its value. With a parent relationship, the receiving post must have one of them too.', 'meta-conductor'),
            'claim'       => __('For a field: Owning makes the field always equal the source\'s value, including when that is empty. Contributing copies a value the source holds and never clears. Deferring fills the field only while it is empty. A post with no eligible source is always left alone.', 'meta-conductor'),
            'source'      => __('Only one related post counts: the first that holds a value, taken in the order the field lists its posts (pull), or in the order the reverse field lists them, else by ascending post ID (push).', 'meta-conductor'),
        });
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
        return ['' => $options[''] ?? '', self::META => self::meta_option_label()] + $options;
    }

    /**
     * With ACF off the pickers list no ACF field, and a select drops a value that
     * is not among its options on save, which would gut a stored row that is only
     * idle. So each stored ACF key (or group key) is offered as itself, as the
     * term list does for its relationship fields. Empty while ACF is active.
     *
     * @param string $field `source_field` or `copy_target`.
     * @return array<string,string> stored value => label.
     */
    private static function stored_while_acf_off(string $field): array {
        if (self::acf_loaded()) {
            return [];
        }

        $stored = get_option(OptionRuleStorage::OPTION_NAME, []);
        $out    = [];
        foreach ((array) (is_array($stored) ? ($stored[OptionRuleStorage::KIND_TERM] ?? []) : []) as $row) {
            $value = is_array($row) ? trim((string) ($row[$field] ?? '')) : '';
            if ($value !== '' && $value !== self::META) {
                /* translators: %s: the stored ACF field or group key */
                $out[$value] = sprintf(__('%s (ACF inactive)', 'meta-conductor'), $value);
            }
        }

        return $out;
    }

    /** The label of the plain-meta choice in both the source and the target select. */
    private static function meta_option_label(): string {
        return __('A plain meta key…', 'meta-conductor');
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
