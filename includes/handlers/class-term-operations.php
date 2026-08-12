<?php
/**
 * Term Operations Trait
 *
 * Native WP taxonomy-term primitives shared by handlers on UnifiedHandlerBase:
 * apply / remove terms honoring conflict handling, membership test, and a
 * change-detection fingerprint. Stateless — every method operates purely on its
 * arguments plus WP core functions (no $this member access), so composition
 * into the base is behavior-identical to inline declaration.
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
     * base inherit it. Behavior identical: merge/replace/skip.
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

        // Ensure terms are term IDs
        $term_ids = array();
        foreach ($terms as $term) {
            if (is_object($term)) {
                $term_ids[] = $term->term_id;
            } elseif (is_array($term)) {
                $term_ids[] = $term['term_id'];
            } else {
                $term_ids[] = absint($term);
            }
        }

        $term_ids = array_unique(array_filter($term_ids));

        if (empty($term_ids)) {
            return false;
        }

        switch ($conflict_handling) {
            case 'replace':
                return \wp_set_object_terms($post_id, $term_ids, $taxonomy);

            case 'merge':
                $existing_terms = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
                if (\is_wp_error($existing_terms)) {
                    $existing_terms = array();
                }
                $merged_terms = array_unique(array_merge($existing_terms, $term_ids));
                return \wp_set_object_terms($post_id, $merged_terms, $taxonomy);

            case 'skip':
                $existing_terms = \wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
                if (\is_wp_error($existing_terms)) {
                    $existing_terms = array();
                }

                // Only apply if no existing terms
                if (empty($existing_terms)) {
                    return \wp_set_object_terms($post_id, $term_ids, $taxonomy);
                }
                return false;

            default:
                return false;
        }
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

        // Ensure terms are term IDs
        $term_ids_to_remove = array();
        foreach ($terms as $term) {
            if (is_object($term)) {
                $term_ids_to_remove[] = $term->term_id;
            } elseif (is_array($term)) {
                $term_ids_to_remove[] = $term['term_id'];
            } else {
                $term_ids_to_remove[] = absint($term);
            }
        }

        $remaining_terms = array_diff($existing_terms, $term_ids_to_remove);

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
