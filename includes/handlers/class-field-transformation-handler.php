<?php

namespace BWS\MetaConductor\Handlers;

use BWS\MetaConductor\RuleTypes\Registry;
use BWS\MetaConductor\Tokens\OutputPolicy;
use BWS\MetaConductor\Tokens\PostTokenSource;
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

        $key     = trim((string) ($rule['target_field'] ?? ''));
        $pattern = (string) ($rule['pattern'] ?? '');
        if ($key === '' || $pattern === ''
            || empty($rule['post_type']) || $rule['post_type'] !== ($data['post_type'] ?? '')) {
            return null;
        }

        $field = \acf_get_field($key);
        $name  = is_array($field) ? (string) ($field['name'] ?? '') : '';
        if ($name === '') {
            return null;
        }

        $data['fields'][$name]     = TokenEngine::render(
            $pattern,
            new PostTokenSource($post_id, $data),
            ($rule['output'] ?? 'text') === 'html' ? OutputPolicy::html() : OutputPolicy::text(),
            ['default_title' => (string) ($data['post_title'] ?? '')]
        );
        $data['field_keys'][$name] = $key;

        return $data;
    }
}
