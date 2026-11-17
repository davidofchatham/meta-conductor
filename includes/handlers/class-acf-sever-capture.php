<?php
/**
 * ACF sever capture trait
 *
 * The capture half of an `acf` related-post rule, shared by the two handlers
 * that read a relationship an ACF write is about to destroy:
 * `TermsFromRelatedPostHandler` and `FieldFromRelatedPostHandler`. It holds the
 * `AcfRelationship` (so its request memos live as long as the handler), the
 * per-request memo of the enabled `acf` rows, and the three entry points the
 * handler's capture hooks and the dispatcher call. The diff, the direction
 * logic and the sever key are `AcfRelationship`'s: this trait only feeds it the
 * rows and hands back what it marked.
 *
 * Hook registration stays in each handler's `init_hooks()`: H13 checks it
 * against the descriptor's `capture_hooks()` allow-list, which this trait
 * must not hide. Capture only; a sever reaches the queue through
 * `drain_captures()`, never through an apply (architecture.md invariant 17).
 *
 * The using class constructs `$acf_relationship` before calling the parent
 * constructor, and extends `UnifiedHandlerBase` (for `get_enabled_rules()`).
 *
 * @package Meta_Conductor
 * @since 0.12.0
 */

namespace BWS\MetaConductor\Handlers;

use BWS\MetaConductor\Relationships\AcfRelationship;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

trait AcfSeverCapture {

    /**
     * The ACF relationship graph and its sever capture. The handler keeps the
     * claim and the write; the zero-source gate is `SourceGate`'s.
     */
    private AcfRelationship $acf_relationship;

    /**
     * Per-REQUEST memo of the enabled `acf` rows, for the CAPTURE path only: the
     * applier is handed its rule by the pass and never reads the list.
     *
     * The `acf/update_value` capture fires once per relationship field saved
     * site-wide, and each call would re-run get_enabled_rules over every rule.
     * Rules are only mutated by the admin REST save, a separate request, so a
     * request-lifetime memo is safe. (PR#24 round 8 #4)
     *
     * @var array<int,array>|null
     */
    private ?array $acf_rules_memo = null;

    /**
     * Entities whose CAPTURED state needs a pass. Consumed.
     *
     * The dispatcher calls this at drain start, so a severed dependent reaches
     * a full ordered pass by the same route a saved post does. It is a separate
     * channel from `fan_out()` because the entity a sever affects may be
     * reachable from no post that gets one: the holder was deleted, or the link
     * that named it is gone.
     *
     * @return int[] Entity IDs to mark dirty.
     */
    public function drain_captures(): array {
        return $this->acf_relationship->drain_marks();
    }

    /**
     * CAPTURE hook (not an apply): `acf/update_value` at priority 5, before the
     * value is written. Records which dependents the removal orphans; the diff
     * and the direction logic are `AcfRelationship::capture_removed_dependents()`.
     *
     * @param mixed $value   New field value (returned unchanged).
     * @param int   $post_id Post being saved (a source or a holder).
     * @param array $field   ACF field array.
     * @return mixed
     */
    public function capture_removed_dependents($value, $post_id, $field) {
        return $this->acf_relationship->capture_removed_dependents($this->acf_rules(), $value, $post_id, $field);
    }

    /**
     * CAPTURE hook (not an apply): a post is about to be permanently deleted;
     * record the dependents it feeds while its relationships are still readable.
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
}
