<?php
/**
 * Set a field from a related post — the format applier for `field_from_related_post`.
 *
 * Copies one ACF field's RAW value (unformatted, so an array, a post ID list or
 * a whole repeater arrives intact) from a related post onto the receiving post:
 * its parent, or a post an ACF relationship field joins to it (push or pull).
 * Only one source counts: the first eligible one holding a value, in the order
 * the relationship resolves them. The applier returns the value in the seam's `fields` /
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
use BWS\MetaConductor\Relationships\AcfRelationship;
use BWS\MetaConductor\Relationships\SourceGate;
use BWS\MetaConductor\RuleTypes\Registry;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FieldFromRelatedPostHandler extends UnifiedHandlerBase {

    /**
     * The ACF relationship graph and its sever capture, held here so the
     * request memos live as long as the handler.
     */
    private AcfRelationship $acf_relationship;

    /** Per-request memo of the enabled `acf` rows, for the capture path only. */
    private ?array $acf_rules_memo = null;

    public function __construct() {
        $this->acf_relationship = new AcfRelationship();
        parent::__construct();
    }

    protected function validate_rule_internal($rule): bool {
        return !empty($rule['enabled']);
    }

    /**
     * Capture only, the descriptor's `capture_hooks()`: the format pass owns
     * the trigger and calls `apply_to_data()` for every enabled row. Each
     * capture reads a relationship the write is about to destroy and records a
     * sever; it applies nothing.
     */
    protected function init_hooks(): void {
        add_filter('acf/update_value/type=relationship', array($this, 'capture_removed_dependents'), 5, 3);
        add_filter('acf/update_value/type=post_object', array($this, 'capture_removed_dependents'), 5, 3);
        add_action('before_delete_post', array($this, 'capture_deleted_post'), 10, 1);
    }

    /**
     * Posts a captured sever says need a pass. Consumed.
     *
     * @return int[]
     */
    public function drain_captures(): array {
        return $this->acf_relationship->drain_marks();
    }

    /**
     * CAPTURE hook (not an apply): see `AcfRelationship::capture_removed_dependents()`.
     *
     * @param mixed $value   New field value (returned unchanged).
     * @param int   $post_id Post being saved.
     * @param array $field   ACF field array.
     * @return mixed
     */
    public function capture_removed_dependents($value, $post_id, $field) {
        return $this->acf_relationship->capture_removed_dependents($this->acf_rules(), $value, $post_id, $field);
    }

    /**
     * CAPTURE hook (not an apply): a post is about to be deleted; record the
     * dependents it feeds while its relationships are still readable.
     *
     * @param int $post_id Post being deleted.
     */
    public function capture_deleted_post($post_id): void {
        $this->acf_relationship->capture_deleted_post($this->acf_rules(), $post_id);
    }

    /**
     * The enabled `acf` rows, for the capture path.
     *
     * @return array[]
     */
    private function acf_rules(): array {
        if ($this->acf_rules_memo === null) {
            $this->acf_rules_memo = array_values(array_filter(
                $this->get_enabled_rules(),
                static fn(array $rule): bool => ($rule['relationship'] ?? '') === 'acf'
            ));
        }

        return $this->acf_rules_memo;
    }

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
        $relationship = $descriptor->relationship($rule);
        if ($relationship instanceof AcfRelationship) {
            $relationship = $this->acf_relationship;
        }
        if (!$descriptor->available() || $relationship === null) {
            return null;
        }
        // An ACF row names its field; without ACF's field API or a field it is idle.
        if ($relationship instanceof AcfRelationship
            && (!AcfRelationship::is_available() || (string) ($rule['acf_field_name'] ?? '') === '')) {
            return null;
        }

        $group       = $descriptor->source_group($rule);
        $source_key  = $descriptor->source_key($rule);
        $source_meta = $descriptor->source_meta($rule);
        $target      = $descriptor->target($rule);
        $post_type   = (string) ($data['post_type'] ?? '');
        // Empty `post_types` is "none", not "all" — see the descriptor.
        if (($group === '' && $source_key === '' && $source_meta === '')
            || ($group === '' && $target === null)
            || !in_array($post_type, (array) ($rule['post_types'] ?? []), true)) {
            return null;
        }

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || !$relationship->is_eligible_dependent($post, $rule)) {
            return null;
        }

        // Only an Owning row clears on a sever, and only THIS row's own sever
        // counts. Contributing and Deferring keep the value.
        $orphaned = $relationship instanceof AcfRelationship
            && ($rule['conflict_handling'] ?? 'merge') === 'replace'
            && $this->acf_relationship->is_severed($post_id, $rule);
        $eligible = SourceGate::resolve($relationship->sources_of($post_id, $rule), $rule, $orphaned);
        if ($eligible === null) {
            return null;
        }

        // Group mode: every top-level member of the group, read live, copied
        // into the same field on the receiving post. Each member is its own
        // claim slot, so an empty one decides nothing about the others.
        if ($group !== '') {
            $applied = false;
            foreach ($descriptor->group_members($group) as $member) {
                $next = $this->copy_slot($data, $post_id, $rule, $post_type, $eligible, $member, '', ['key' => $member, 'name' => '']);
                if ($next !== null) {
                    $data    = $next;
                    $applied = true;
                }
            }

            return $applied ? $data : null;
        }

        return $this->copy_slot($data, $post_id, $rule, $post_type, $eligible, $source_key, $source_meta, $target);
    }

    /**
     * One field slot: read the source, apply the claim, set the cell.
     *
     * @param int[]                              $sources Eligible source posts, in the relationship's order.
     * @param array{key:string,name:string}|null $target
     * @return array|null `$data` with the cell set, or null when the slot does not apply.
     */
    private function copy_slot(array $data, int $post_id, array $rule, string $post_type, array $sources, string $source_key, string $source_meta, ?array $target): ?array {
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

        // One source counts: the first, in the relationship's order, that holds
        // a value; when none does, the empty one.
        $value = null;
        foreach ($sources as $source_post) {
            $value = $source_key !== ''
                ? \get_field($source_key, $source_post, false)
                : \get_post_meta($source_post, $source_meta, true);
            if (!self::is_empty($value)) {
                break;
            }
        }
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
