<?php
/**
 * Term count limit handler — caps how many terms a post holds in a taxonomy.
 *
 * @since 0.11.0
 */

namespace BWS\MetaConductor\Handlers;

use BWS\MetaConductor\Core\TermCountSurvivors;
use BWS\MetaConductor\RuleTypes\TermCountRules;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure applier: registers no hooks and keeps no re-entrancy flag
 * (architecture.md invariant 17). `TermDispatcher` calls `apply_to_post()` once
 * per rule per pass, whether or not a term write provoked the pass, so the
 * apply recomputes from live state and is idempotent.
 *
 * WHICH terms survive is not decided here: `Core\TermCountSurvivors::select()`
 * is the one place that ordering lives. This class only reads the post's terms
 * with their depths, hands them over, and removes what did not survive.
 */
class TermCountHandler extends UnifiedHandlerBase {

    /**
     * Term depth by "{taxonomy}_{id}". A flat taxonomy has no ancestors, so
     * every term is depth 0 and `per_level` cannot change the answer.
     *
     * @var array<string,int>
     */
    private array $depth_cache = [];

    protected function init_hooks() {}

    /**
     * Apply ONE count rule to ONE post.
     *
     * ORDER: the ACF taxonomy field first, then the native terms — the order
     * level restriction uses. The field half calls only `update_field()`, never
     * `wp_set_object_terms()`, so this handler never replaces the post's native
     * terms wholesale; ACF's own `save_terms` sync may mirror the pruned field
     * natively. The native half below prunes the live set on its own merits.
     *
     * @param int   $post_id Post to apply to.
     * @param array $rule    One enabled count rule.
     * @return bool Whether the post's native terms changed.
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        if (!$this->should_process_post($post_id, $rule)) {
            return false;
        }
        $taxonomy = (string) ($rule['taxonomy'] ?? '');
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return false;
        }

        $before = $this->terms_fingerprint($post_id, $taxonomy);

        $this->apply_to_acf_fields($post_id, $taxonomy, $rule);
        $this->apply_to_native_terms($post_id, $taxonomy, $rule);

        return $this->terms_fingerprint($post_id, $taxonomy) !== $before;
    }

    /**
     * Prune the post's ACF taxonomy fields. Writes a field only when its SET
     * changes, and keeps the survivors in the field's own order, so a rerun
     * never reorders what it stored.
     */
    private function apply_to_acf_fields(int $post_id, string $taxonomy, array $rule): void {
        // Value-independent discovery (#41): by field-group location, read by name, write by key.
        foreach ($this->get_acf_taxonomy_fields($post_id, $taxonomy) as $field) {
            $current = array_map('intval', $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy));
            if ($current === []) {
                continue;
            }

            $kept = $this->survivors($current, $taxonomy, $rule);
            if (count($kept) === count(array_unique($current))) {
                continue;
            }

            $this->set_acf_taxonomy_value(
                $post_id,
                $field['key'],
                array_values(array_filter($current, static fn(int $id): bool => in_array($id, $kept, true)))
            );
        }
    }

    /**
     * Prune the post's live native terms.
     */
    private function apply_to_native_terms(int $post_id, string $taxonomy, array $rule): void {
        $current = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($current) || $current === []) {
            return;
        }

        $current = array_map('intval', $current);
        $kept    = $this->survivors($current, $taxonomy, $rule);

        if (count($kept) < count($current)) {
            wp_set_object_terms($post_id, $kept, $taxonomy);
        }
    }

    /**
     * The term IDs that stay.
     *
     * @param int[] $term_ids
     * @return int[]
     */
    private function survivors(array $term_ids, string $taxonomy, array $rule): array {
        $terms = array_map(fn(int $id): array => ['id' => $id, 'depth' => $this->depth($id, $taxonomy)], $term_ids);

        $kept = TermCountSurvivors::select($terms, [], (int) ($rule['max_terms'] ?? 1), TermCountRules::per_level($rule));

        return array_column($kept, 'id');
    }

    private function depth(int $term_id, string $taxonomy): int {
        return $this->depth_cache[$taxonomy . '_' . $term_id]
            ??= count(get_ancestors($term_id, $taxonomy, 'taxonomy'));
    }
}
