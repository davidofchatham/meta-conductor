<?php
/**
 * Term Operations Trait
 *
 * Native WP taxonomy-term primitives shared by handlers on UnifiedHandlerBase:
 * apply / remove terms honoring conflict handling, membership test, and a
 * change-detection fingerprint. Stateless — every method operates purely on its
 * arguments, WP core functions and its trait siblings (no handler member state),
 * so composition into the base is behavior-identical to inline declaration.
 *
 * Composed into UnifiedHandlerBase (see its `use`). Kept as a trait so the base
 * file stays agent-navigable; call sites resolve unchanged via $this->.
 *
 * @package BWS_Meta_Manager
 * @since 0.6.3
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

trait TermOperations {

    /**
     * Apply terms to a post honoring conflict handling.
     *
     * Ported from legacy HandlerBase (V10) so handlers migrated onto this
     * base inherit it. The merge/replace/skip semantics live in
     * compute_end_state() below — this is the write half of that pair (#38).
     *
     * @param int    $post_id           Post ID
     * @param string $taxonomy          Taxonomy
     * @param array  $terms             Term IDs, objects, or arrays
     * @param string $conflict_handling 'merge' | 'replace' | 'skip'
     * @return array|false|\WP_Error wp_set_object_terms result, or false
     */
    protected function apply_terms_to_post(int $post_id, string $taxonomy, array $terms, string $conflict_handling = 'merge'): array|false|\WP_Error {
        if (empty($terms)) {
            return false;
        }

        $term_ids = $this->normalize_term_ids($terms);

        if (empty($term_ids)) {
            return false;
        }

        $existing = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (\is_wp_error($existing)) {
            $existing = array();
        }
        $current = $this->normalize_term_ids($existing);
        $target  = $this->compute_end_state($current, $term_ids, $conflict_handling);

        // Equality is the no-op test for EVERY mode, which is what makes `skip`
        // stop being a special case: on an occupied taxonomy its end state IS
        // the current set, so it falls out of the same comparison that skips a
        // redundant merge or replace. Both sides come out of
        // normalize_term_ids/compute_end_state sorted, so === is a set test.
        if ($target === $current) {
            return false;
        }

        return \wp_set_object_terms($post_id, $target, $taxonomy);
    }

    /**
     * The term set a post would END UP with, given what it holds now, what is
     * being applied, and the rule's claim.
     *
     * THE single source for the claim semantics (#38 cluster 2). It used to be
     * encoded twice — once as the switch inside `apply_terms_to_post` above,
     * once as `PropagationHandler::write_would_change_terms`'s parallel switch
     * deciding whether that write was worth making. Two coupled switches with
     * no shared source: a change to one silently made the other's answer wrong,
     * and the failure is invisible in both directions (a skipped write that
     * WOULD have changed terms looks like propagation quietly not happening; a
     * write that changes nothing looks like nothing at all). Predicate and
     * apply path now ask the same function, so "would this write change
     * anything" is `compute_end_state(...) !== $current` by construction rather
     * than by maintenance.
     *
     * The `default` arm returns the current set — i.e. an unrecognised claim
     * writes nothing, matching the old switch's `default: return false`.
     *
     * @param array  $current           Term IDs the post holds now.
     * @param array  $incoming          Term IDs being applied.
     * @param string $conflict_handling merge|replace|skip.
     * @return int[] Sorted, unique term IDs.
     */
    protected function compute_end_state(array $current, array $incoming, string $conflict_handling): array {
        $current  = $this->normalize_term_ids($current);
        $incoming = $this->normalize_term_ids($incoming);

        switch ($conflict_handling) {
            case 'replace':
                return $incoming;

            case 'merge':
                $merged = array_unique(array_merge($current, $incoming));
                sort($merged);
                return array_values($merged);

            case 'skip':
                // Deferring, not contributing (ADR 0004): writes only into an
                // empty taxonomy.
                return empty($current) ? $incoming : $current;

            default:
                return $current;
        }
    }

    /**
     * Coerce a mixed term list (IDs, `WP_Term`s, term arrays) to sorted unique
     * positive term IDs, so every comparison in this trait is a set comparison.
     *
     * @param array $terms Term IDs, objects, or arrays.
     * @return int[]
     */
    protected function normalize_term_ids(array $terms): array {
        $ids = array();
        foreach ($terms as $term) {
            if (is_object($term)) {
                $ids[] = (int) $term->term_id;
            } elseif (is_array($term)) {
                $ids[] = (int) ($term['term_id'] ?? 0);
            } else {
                $ids[] = absint($term);
            }
        }

        $ids = array_unique(array_filter($ids));
        sort($ids);

        return array_values($ids);
    }

    /**
     * Remove terms from a post.
     *
     * Ported from legacy HandlerBase (V10).
     *
     * @param int    $post_id  Post ID
     * @param string $taxonomy Taxonomy
     * @param array  $terms    Term IDs, objects, or arrays to remove
     * @return array|false|\WP_Error wp_set_object_terms result, or false
     */
    protected function remove_terms_from_post(int $post_id, string $taxonomy, array $terms): array|false|\WP_Error {
        if (empty($terms)) {
            return false;
        }

        $existing_terms = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (\is_wp_error($existing_terms)) {
            return false;
        }

        $term_ids_to_remove = $this->normalize_term_ids($terms);

        $remaining_terms = array_diff($this->normalize_term_ids($existing_terms), $term_ids_to_remove);

        return \wp_set_object_terms($post_id, $remaining_terms, $taxonomy);
    }

    /**
     * Check if a post has specific terms in a taxonomy.
     *
     * Ported from legacy HandlerBase (V10). Null $term_ids ⇒ "has any term".
     *
     * @param int        $post_id  Post ID
     * @param string     $taxonomy Taxonomy
     * @param array|int|null $term_ids Term IDs to check, or null for any
     * @return bool
     */
    protected function post_has_terms(int $post_id, string $taxonomy, array|int|null $term_ids = null): bool {
        $post_terms = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));

        if (\is_wp_error($post_terms)) {
            return false;
        }

        if ($term_ids === null) {
            return !empty($post_terms);
        }

        if (!is_array($term_ids)) {
            $term_ids = array($term_ids);
        }

        return !empty(array_intersect($post_terms, $term_ids));
    }

    /**
     * Stable fingerprint of a post's native term IDs in one taxonomy, for
     * before/after change detection in apply_to_post (#31). Empty taxonomy or a
     * WP_Error reads as the empty set — a subsequent real write then differs.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return string Sorted comma-joined term IDs (e.g. "13,14,15").
     */
    protected function terms_fingerprint(int $post_id, string $taxonomy): string {
        if ($taxonomy === '') {
            return '';
        }
        $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($ids)) {
            return '';
        }
        $ids = array_map('intval', $ids);
        sort($ids);
        return implode(',', $ids);
    }
}
