<?php
/**
 * Descriptor for `field_from_related_post` rows — Set a field from a related post.
 *
 * @since 0.3.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
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
 * The target is *Same* for now (the source field's own key), so the row stores
 * no target of its own beyond the `copy_target` select's empty value.
 */
final class FieldFromRelatedPostRules extends RuleType {

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
     * The ACF field key the rule reads, trimmed. '' when unfinished.
     */
    public function source_key(array $rule): string {
        return trim((string) ($rule['source_field'] ?? ''));
    }

    /**
     * One field, by its ACF field KEY — the `field|` scheme the pattern rule
     * uses, so two rules writing one field are order-decided and collide. The
     * target is the source's own key while it is *Same* (the only choice offered
     * so far). A row with no post type checked is unfinished: the handler skips it.
     */
    public function target_key(array $rule): ?string {
        if ((array) ($rule['post_types'] ?? []) === []) {
            return null;
        }

        $key = $this->source_key($rule);

        return $key === '' ? null : 'field|' . $key;
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

        $field = ConfigHelpers::acf_field_label($this->source_key($row));

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
     * `relationship` offers the parent only for now. `copy_target` is a select
     * whose one option is *Same*; a later ticket adds the picker.
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
                    'options' => ConfigHelpers::acf_source_field_options(),
                ],
            ],
            [
                'id'          => 'copy_target',
                'type'        => 'select',
                'label'       => __('Copy into', 'meta-conductor'),
                'description' => __('Same writes the field of the same name on the receiving post. It is offered for a field only when the field\'s group is shown on the receiving post type; on any other type the rule does nothing.', 'meta-conductor'),
                'default'     => '',
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        '' => __('Same field', 'meta-conductor'),
                    ],
                ],
            ],
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
}
