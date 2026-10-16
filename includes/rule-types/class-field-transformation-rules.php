<?php
/**
 * Descriptor for `field_transformation_rules` rows — the Computed field rule.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Handlers\FieldTransformationHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Tokens\TokenEngine;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `field_transformation_rules`, not a domain name —
 * FW-14 renames `label()` only. The editor calls it a Computed field.
 */
final class FieldTransformationRules extends RuleType {

    public function type(): string {
        return 'field_transformation_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_FORMAT;
    }

    public function label(): string {
        return __('Computed field — store a pattern\'s output in an ACF field', 'meta-conductor');
    }

    public function handler_class(): string {
        return FieldTransformationHandler::class;
    }

    /**
     * The rule writes through ACF, so without it there is nothing to write to:
     * the type is not offered and the handler skips any row already stored.
     */
    public function available(): bool {
        return function_exists('acf_get_field') && function_exists('update_field');
    }

    /**
     * Prefix of a `target_field` value that names a repeater subfield. One select
     * offers both kinds of target, so the option key carries the pair
     * (`row|<repeater key>|<subfield key>`); a post-level field is its bare key.
     */
    public const ROW_PREFIX = 'row|';

    /**
     * Whether the row targets a repeater's subfield rather than a post-level
     * field.
     */
    public function is_row_rule(array $rule): bool {
        return str_starts_with(trim((string) ($rule['target_field'] ?? '')), self::ROW_PREFIX);
    }

    /**
     * The repeater and subfield keys of a row rule, or null for a post-level
     * rule or a value that does not carry both.
     *
     * @return array{repeater:string,sub:string}|null
     */
    public function row_target(array $rule): ?array {
        if (!$this->is_row_rule($rule)) {
            return null;
        }

        $parts = explode('|', trim((string) $rule['target_field']));

        return count($parts) === 3 && $parts[1] !== '' && $parts[2] !== '' ? ['repeater' => $parts[1], 'sub' => $parts[2]] : null;
    }

    /**
     * The ACF key of the field the rule writes: the subfield for a row rule,
     * else the post-level field. '' when unfinished.
     */
    private function leaf_key(array $rule): string {
        if ($this->is_row_rule($rule)) {
            return $this->row_target($rule)['sub'] ?? '';
        }

        return trim((string) ($rule['target_field'] ?? ''));
    }

    /**
     * One field, keyed by its ACF field KEY — the only identity that survives a
     * rename. The scheme is `field|`, not the `fields|` post-field scheme the
     * title/slug rule owns: that one is first-match-wins by post type, whereas
     * two rules on one field are order-decided (the later write wins). A row
     * rule's target is one subfield of one repeater, `row|<repeater>|<subfield>`;
     * a repeater with no subfield picked yet is unfinished, not a target. So is
     * a row with no post type checked: the handler skips it, and without the
     * null two such rows on one field would be reported as colliding (their
     * written types are both `['']`, which overlap).
     */
    public function target_key(array $rule): ?string {
        if ((array) ($rule['post_types'] ?? []) === []) {
            return null;
        }

        if ($this->is_row_rule($rule)) {
            $row = $this->row_target($rule);

            // Already the `row|<repeater>|<subfield>` pair, validated whole.
            return $row === null ? null : trim((string) $rule['target_field']);
        }

        $key = $this->leaf_key($rule);

        return $key === '' ? null : 'field|' . $key;
    }

    /**
     * The checked `post_types`. A row with none checked applies to nothing (the
     * handler skips it), so it yields `['']` — which intersects nothing — rather
     * than [], which would claim every post type; see
     * `TitleSlugRules::written_post_types()`.
     */
    public function written_post_types(array $rule): array {
        $types = array_values(array_filter(array_map('strval', (array) ($rule['post_types'] ?? []))));

        return $types ?: [''];
    }

    /**
     * Whether the pattern reads the rule's own target field. The handler
     * resolves such a token empty (a pattern cannot compound across passes), so
     * the row is almost certainly not what its author meant — the row title
     * says so, which is the advisory on the rules page. False when ACF cannot
     * name the field.
     */
    public function self_references(array $rule): bool {
        $name = ConfigHelpers::acf_field_name($this->leaf_key($rule));

        return $name !== '' && in_array($name, TokenEngine::field_names((string) ($rule['pattern'] ?? '')), true);
    }

    /**
     * Schema:
     *   {name} — {field_label | repeater › subfield}{ (Post type)}{ — ⚠ pattern names its own target field}
     *   e.g. "Display line — Display name (MC Items)"
     *
     * The author names the row (`name` is required on the shared frame); the
     * snapshot adds what a long list needs to be scanned — which field it
     * writes, on which post type.
     */
    public function row_title(array $row): string {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            $name = __('Untitled computed field rule', 'meta-conductor');
        }

        $field  = ConfigHelpers::acf_field_label($this->leaf_key($row));
        $target = $this->row_target($row);
        if ($field !== '' && $target !== null) {
            $repeater = ConfigHelpers::acf_field_label($target['repeater']);
            $field    = ($repeater !== '' ? $repeater . ' › ' : '') . $field;
        }

        return $name
            . ($field !== '' ? ' — ' . $field : '')
            . Labels::scope_label((array) ($row['post_types'] ?? []))
            . ($this->self_references($row) ? ' — ⚠ ' . __('pattern names its own target field', 'meta-conductor') : '');
    }

    /**
     * Every field here is gated to this type (the kind config stamps it). The
     * shared format frame — type, enabled, name, row_title — is read by this
     * handler too and stays ungated.
     *
     * `post_types` is this type's own scope, checkboxes like the term list's:
     * these rules compose in list order, so unlike title & slug (first match
     * per post type) one rule may cover several types. Unlike the term list,
     * none checked means NOTHING, not every type — a field write on a type
     * whose ACF group lacks the field would store orphan meta. The attachment
     * type is not offered (see `ConfigHelpers::format_post_type_options()`).
     *
     * No bare `{word}` in a description (CLAUDE.md don't 4b); the colon tokens
     * are safe.
     *
     * @return array[]
     */
    public function subfields(): array {
        return [
            ConfigHelpers::post_types_field([
                'label'       => __('Post types', 'meta-conductor'),
                'description' => __('The post types this rule writes to. Check at least one — a rule with none checked does nothing.', 'meta-conductor'),
                'args'        => [
                    'options' => ConfigHelpers::format_post_type_options(),
                ],
            ]),
            [
                'id'          => 'target_field',
                'type'        => 'select',
                'label'       => __('Target field', 'meta-conductor'),
                'description' => __('The ACF field that holds the computed value. Only text, text area, editor, number, email and URL fields are listed. Pick a "Repeater › Subfield" entry to write that subfield in every row of the repeater; inside a row, field and date tokens read the other subfields of that row, while term and publish-date tokens read the post. A subfield inside a nested repeater or group resolves empty. The rule remembers the field itself, so renaming it in ACF does not break the rule.', 'meta-conductor'),
                'default'     => '',
                'columns'     => 12,
                'args'        => [
                    'options' => ConfigHelpers::acf_computed_target_options(),
                ],
            ],
            [
                'id'          => 'pattern',
                'type'        => 'text',
                'label'       => __('Pattern', 'meta-conductor'),
                'description' => __('Built from the same tokens as a title & slug pattern, such as {meta:first_name}, {term:taxonomy} or {date_year:field}. If every token is empty the field is cleared.', 'meta-conductor'),
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'placeholder' => __('e.g. {meta:first_name} {meta:last_name}', 'meta-conductor'),
                ],
            ],
            [
                'id'          => 'output',
                'type'        => 'select',
                'label'       => __('Output', 'meta-conductor'),
                'description' => __('Text stores values exactly as they resolve. HTML keeps the markup you write in the pattern and escapes the values placed into it.', 'meta-conductor'),
                'default'     => 'text',
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        'text' => __('Text — values stored as they are', 'meta-conductor'),
                        'html' => __('HTML — pattern markup kept, values escaped', 'meta-conductor'),
                    ],
                ],
            ],
        ];
    }
}
