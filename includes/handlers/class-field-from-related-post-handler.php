<?php
/**
 * Set a field from a related post — the format applier for `field_from_related_post`.
 *
 * Copies one ACF field's RAW value (unformatted, so an array, a post ID list or
 * a whole repeater arrives intact) from the receiving post's parent onto the
 * receiving post. The applier returns the value in the seam's `fields` /
 * `field_keys` and the format dispatcher writes it once, so a rule ordered
 * after this one reads the copied value in the same pass.
 *
 * Which parent counts is not decided here: `Relationships\SourceGate` is the
 * one encoding of "eligible sources, and none means leave the post alone".
 * A draft parent, a root post and a post detached to root all resolve to no
 * eligible source, so the post keeps its value under every claim.
 *
 * @since 0.3.0
 */

namespace BWS\MetaConductor\Handlers;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Relationships\ParentRelationship;
use BWS\MetaConductor\Relationships\SourceGate;
use BWS\MetaConductor\RuleTypes\Registry;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FieldFromRelatedPostHandler extends UnifiedHandlerBase {

    protected function validate_rule_internal($rule): bool {
        return !empty($rule['enabled']);
    }

    /**
     * No hooks of its own: the format pass owns the trigger and calls
     * `apply_to_data()` for every enabled row.
     */
    protected function init_hooks(): void {}

    /**
     * One rule over one post. Idempotent, and the handler's whole apply — a pass
     * runs every row whether or not anything changed.
     *
     * Claim, per field: Owning (`replace`) writes the source's value, including
     * empty; Contributing (`merge`) writes a non-empty value and never clears;
     * Deferring (`skip`) writes only into an empty target. "Empty" is null, '',
     * [] or false.
     *
     * @param array $data    Post data as the previous rule left it.
     * @param int   $post_id Receiving post.
     * @param array $rule    One enabled rule (canonical shape).
     * @return array|null Data with the field set, or null when the rule does not
     *                    apply here (which is not "data unchanged").
     */
    public function apply_to_data(array $data, int $post_id, array $rule): ?array {
        $descriptor = Registry::for_handler(static::class);
        if (!$descriptor->available() || ($rule['relationship'] ?? '') !== 'parent') {
            return null;
        }

        $source_key  = $descriptor->source_key($rule);
        $source_meta = $descriptor->source_meta($rule);
        $target      = $descriptor->target($rule);
        $post_type   = (string) ($data['post_type'] ?? '');
        // Empty `post_types` is "none", not "all" — see the descriptor.
        if (($source_key === '' && $source_meta === '') || $target === null
            || !in_array($post_type, (array) ($rule['post_types'] ?? []), true)) {
            return null;
        }

        $post         = get_post($post_id);
        $relationship = new ParentRelationship();
        if (!$post instanceof \WP_Post || !$relationship->is_eligible_dependent($post, $rule)) {
            return null;
        }

        $eligible = SourceGate::resolve($relationship->sources_of($post_id, $rule), $rule);
        if ($eligible === null) {
            return null;
        }

        // An ACF target must exist, be shown on the receiving type (this is what
        // *Same* needs too) and, when it is not the source itself, have the
        // source's field type. A meta target is its name, written by MetaWriter.
        $key  = $target['key'];
        $name = $target['name'];
        if ($key !== '') {
            $field = \acf_get_field($key);
            if (!is_array($field) || ($name = (string) ($field['name'] ?? '')) === ''
                || !ConfigHelpers::acf_field_shown_on($key, $post_type)) {
                return null;
            }
            if ($source_key !== '' && $key !== $source_key) {
                $source = \acf_get_field($source_key);
                if (!is_array($source) || ($source['type'] ?? null) !== ($field['type'] ?? '')) {
                    return null;
                }
            }
        }

        $value = $source_key !== ''
            ? \get_field($source_key, $eligible[0], false)
            : \get_post_meta($eligible[0], $source_meta, true);
        $empty = self::is_empty($value);
        $claim = (string) ($rule['conflict_handling'] ?? 'merge');

        if ($claim === 'merge' && $empty) {
            return null;
        }
        if ($claim === 'skip') {
            // A cell an earlier rule set in this pass beats the stored value.
            $current = array_key_exists($name, $data['fields'])
                ? $data['fields'][$name]
                : ($key !== '' ? \get_field($key, $post_id, false) : \get_post_meta($post_id, $name, true));
            if ($empty || !self::is_empty($current)) {
                return null;
            }
        }

        $data['fields'][$name] = $empty ? '' : $value;
        if ($key !== '') {
            $data['field_keys'][$name] = $key;
        }

        return $data;
    }

    private static function is_empty(mixed $value): bool {
        return $value === null || $value === '' || $value === [] || $value === false;
    }
}
