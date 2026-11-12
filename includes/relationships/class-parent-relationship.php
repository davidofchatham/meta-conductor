<?php
/**
 * The post-parent relationship: a post's source is its parent post.
 *
 * @package Meta_Conductor
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Relationships;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Source = the post's parent; dependents = its immediate children.
 *
 * There is no sever capture here: a parent link carries no history, and the
 * handler's lost-terms capture is about TERMS, not about the link, so it stays
 * with the handler.
 *
 * Eligibility is the rule's `post_types` AND `post_status` gate, the same test
 * `UnifiedHandlerBase::should_process_post()` applies, for source and dependent
 * alike. Kept as it was; ticket 03 changes the post-types half deliberately.
 */
final class ParentRelationship implements Relationship {

    /**
     * Post statuses a children query considers a child at all.
     *
     * The reach the recursive descendant walk had before #62. NOT the rule's
     * own `post_status` gate, which is applied by eligibility inside the
     * child's own apply: enqueuing one post too many costs a no-op pass,
     * missing one loses the propagation.
     *
     * @var string[]
     */
    private const CHILD_STATUSES = ['publish', 'draft', 'private'];

    /**
     * Immediate children by `"post_id|post_types"`, for the request.
     *
     * The fan-out is asked once per RULE per pass, so two rows over the same
     * post types would otherwise run the same `post_parent` query twice per
     * entity, on every entity in a chain. Nothing here writes `post_parent`, so
     * the children of one post do not change within a request.
     *
     * @var array<string,int[]>
     */
    private array $child_cache = [];

    public function sources_of(int $dependent_id, array $row): array {
        $post = \get_post($dependent_id);
        if (!$post || (int) $post->post_parent <= 0) {
            return [];
        }

        return [(int) $post->post_parent];
    }

    /**
     * Immediate children only. Each child's own pass fans out to ITS children,
     * so a chain of any depth is walked one level per pass by the queue, with
     * the drain's one-pass-per-entity bound as the termination argument.
     */
    public function dependents_of(int $source_id, array $row): array {
        $post_types = $this->child_post_types($row);
        if (empty($post_types)) {
            return [];
        }

        $key = $source_id . '|' . implode(',', $post_types);
        if (isset($this->child_cache[$key])) {
            return $this->child_cache[$key];
        }

        $children = \get_posts([
            'post_type'        => $post_types,
            'post_parent'      => $source_id,
            'post_status'      => self::CHILD_STATUSES,
            'numberposts'      => -1,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ]);

        return $this->child_cache[$key] = array_map('intval', (array) $children);
    }

    public function is_eligible_source(\WP_Post $post, array $row): bool {
        return $this->passes_gate($post, $row);
    }

    public function is_eligible_dependent(\WP_Post $post, array $row): bool {
        return $this->passes_gate($post, $row);
    }

    /**
     * Reads `post_types`; `[]` or `any` (`should_process_post()`'s "don't gate"
     * sentinel) means every post type.
     */
    public function written_post_types(array $row): array {
        $slugs = $row['post_types'] ?? [];

        if ($slugs === [] || ($slugs[0] ?? '') === 'any') {
            return [];
        }

        return $slugs;
    }

    /** `post_status` gates the written post here, and the parent with it. */
    public function status_gates_source(array $row): bool {
        return false;
    }

    /**
     * The rule's `post_types` and `post_status` slug lists; empty = ungated,
     * `any` = ungated. Same test as `UnifiedHandlerBase::should_process_post()`.
     */
    private function passes_gate(\WP_Post $post, array $row): bool {
        $post_types = $row['post_types'] ?? [];
        if (!empty($post_types)) {
            if ($post_types[0] !== 'any' && !in_array($post->post_type, $post_types)) {
                return false;
            }
        }

        $post_statuses = $row['post_status'] ?? [];
        if (!empty($post_statuses)) {
            if ($post_statuses[0] !== 'any' && !in_array($post->post_status, $post_statuses)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the rule's `post_types` checkboxes to a concrete slug list for the
     * children query. The gate treats empty as "all"; `get_posts` needs real
     * post types, so empty means every HIERARCHICAL public post type (a parent
     * link only exists on a tree).
     *
     * @return string[]
     */
    private function child_post_types(array $row): array {
        $slugs = $row['post_types'] ?? [];

        if (empty($slugs)) {
            $slugs = array_values(\get_post_types(['public' => true, 'hierarchical' => true]));
        }

        return $slugs;
    }
}
