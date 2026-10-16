<?php

namespace BWS\MetaConductor\Handlers;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\RuleTypes\Registry;
use BWS\MetaConductor\Tokens\OutputPolicy;
use BWS\MetaConductor\Tokens\PostTokenSource;
use BWS\MetaConductor\Tokens\RowTokenSource;
use BWS\MetaConductor\Tokens\TokenEngine;

if (!defined('ABSPATH')) exit;

/**
 * Composes a pattern into an ACF field. A pure applier on the format seam: it
 * registers nothing and writes nothing.
 *
 * The result rides the seam's `$data` array as `fields[<name>] = <value>`, with
 * the ACF field KEY beside it in `field_keys[<name>]`. `FormatDispatcher::write()`
 * persists the difference through ACF's write-by-key, which is what keeps the
 * field's hidden `_name` reference row intact. Keyed by NAME because tokens
 * name fields by name; the key is what the rule stores and what is written by.
 *
 * A ROW RULE targets one subfield of a repeater and sets one cell per row:
 * `rows[<repeater key>][<1-based row>][<subfield key>] = {name, meta, label,
 * value}`. `write_fields()` persists each changed cell through ACF's subfield
 * write, addressed by those three. Each row composes from its OWN siblings
 * (`RowTokenSource`), read from stored meta with any cell an earlier rule set in
 * this pass laid over it.
 */
class FieldTransformationHandler extends UnifiedHandlerBase {

    protected function validate_rule_internal($rule): bool {
        return !empty($rule['enabled']);
    }

    /** Nothing. The dispatchers own every trigger (don't 6b/7); H13 fails on a registration here. */
    protected function init_hooks(): void {}

    /**
     * @param array $data    Post data as the previous rule left it.
     * @param int   $post_id Entity being passed over.
     * @param array $rule    One enabled Computed field rule.
     * @return array|null Data with the field set, or null when the rule does not
     *                    apply: another post type, a half-authored row, a key
     *                    ACF no longer resolves, or ACF inactive.
     */
    public function apply_to_data(array $data, int $post_id, array $rule): ?array {
        if (!Registry::for_handler(static::class)->available()) {
            return null;
        }

        $pattern = (string) ($rule['pattern'] ?? '');
        // Empty `post_types` is "none", not "all" — see the descriptor.
        if ($pattern === ''
            || !in_array($data['post_type'] ?? '', (array) ($rule['post_types'] ?? []), true)) {
            return null;
        }

        if (Registry::for_handler(static::class)->is_row_rule($rule)) {
            return $this->apply_to_rows($data, $post_id, $rule, $pattern);
        }

        $key = trim((string) ($rule['target_field'] ?? ''));
        if ($key === '') {
            return null;
        }

        $name = ConfigHelpers::acf_field_name($key);
        if ($name === '') {
            return null;
        }

        $data['fields'][$name]     = TokenEngine::render(
            $pattern,
            new PostTokenSource($post_id, $data, $name),
            self::policy($rule),
            ['default_title' => (string) ($data['post_title'] ?? '')]
        );
        $data['field_keys'][$name] = $key;

        return $data;
    }

    /**
     * One subfield, every row. Null when the pair does not resolve — a repeater
     * that is not one, a subfield that is not a writable DIRECT child of it — or
     * the post has no rows.
     */
    private function apply_to_rows(array $data, int $post_id, array $rule, string $pattern): ?array {
        $repeater_key = trim((string) ($rule['target_repeater'] ?? ''));
        $sub_key      = trim((string) ($rule['target_subfield'] ?? ''));
        $repeater     = $repeater_key !== '' ? \acf_get_field($repeater_key) : false;

        if (!is_array($repeater) || ($repeater['type'] ?? '') !== 'repeater') {
            return null;
        }

        // The subfield must be a DIRECT child of the repeater. Read from the
        // repeater's own field list, not the subfield's `parent`: ACF stores a
        // parent KEY for local fields but a post ID for UI-built ones.
        $fields = (array) \acf_get_fields($repeater);
        $sub    = null;
        foreach ($fields as $field) {
            if (($field['key'] ?? '') === $sub_key) {
                $sub = $field;
            }
        }
        if ($sub === null || !in_array($sub['type'] ?? '', ConfigHelpers::COMPUTED_TARGET_TYPES, true)) {
            return null;
        }

        $rows = (int) get_post_meta($post_id, (string) $repeater['name'], true);
        if ($rows < 1) {
            return null;
        }

        // Direct siblings a token may read. A nested repeater or group has no
        // scalar value of its own, so it is left out and resolves empty.
        $siblings = [];
        foreach ($fields as $field) {
            if (!in_array($field['type'] ?? '', ['repeater', 'group', 'flexible_content', 'clone'], true)) {
                $siblings[] = (string) $field['name'];
            }
        }

        $policy = self::policy($rule);
        $vars   = ['default_title' => (string) ($data['post_title'] ?? '')];
        $post   = new PostTokenSource($post_id, $data);

        for ($row = 1; $row <= $rows; $row++) {
            $values = [];
            foreach ($siblings as $name) {
                $values[$name] = get_post_meta($post_id, $repeater['name'] . '_' . ($row - 1) . '_' . $name, true);
            }
            // A cell an earlier rule set in this pass beats the stored value.
            foreach ($data['rows'][$repeater_key][$row] ?? [] as $cell) {
                $values[$cell['name']] = $cell['value'];
            }

            $data['rows'][$repeater_key][$row][$sub_key] = [
                'name'  => (string) $sub['name'],
                'meta'  => $repeater['name'] . '_' . ($row - 1) . '_' . $sub['name'],
                'label' => $repeater['name'] . '[' . $row . '].' . $sub['name'],
                'value' => TokenEngine::render($pattern, new RowTokenSource($post, $values, (string) $sub['name']), $policy, $vars),
            ];
        }

        return $data;
    }

    private static function policy(array $rule): OutputPolicy {
        return ($rule['output'] ?? 'text') === 'html' ? OutputPolicy::html() : OutputPolicy::text();
    }
}
