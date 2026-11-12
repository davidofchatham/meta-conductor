<?php
/**
 * The relationship contract: which post feeds which, for one rule row.
 *
 * @package Meta_Conductor
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Relationships;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A relationship graph between posts, read through one rule row.
 *
 * ENTITY-NEUTRAL IN SHAPE, POST-ONLY IN FACT. Each implementation fixes the
 * entity kind of each end, and both ends are posts for both implementations
 * (`ParentRelationship`: post and its parent post; `AcfRelationship`: post and
 * a post an ACF relationship field names). So the contract carries no kind
 * method, no kind parameter and no term or user code: a future implementation
 * whose ends are not posts adds its own kind knowledge when it arrives, and this
 * interface changes then, not now.
 *
 * A "source" is the end whose terms are read; a "dependent" is the end that is
 * written. The module answers WHO those are and whether a post is eligible to
 * be one. It never writes. The claim, the term write, the no-change
 * short-circuit and the zero-source gate belong to the handler that calls it,
 * because the dispatcher is the only caller of an applier (architecture.md
 * invariant 17).
 */
interface Relationship {

    /**
     * The sources that feed a dependent under this row.
     *
     * @param int   $dependent_id The post being written.
     * @param array $row          Canonical-shape rule row.
     * @return int[] Source post IDs; [] when none resolve.
     */
    public function sources_of(int $dependent_id, array $row): array;

    /**
     * The dependents a source feeds under this row: its declared reach.
     *
     * @param int   $source_id The post whose terms are read.
     * @param array $row       Canonical-shape rule row.
     * @return int[] Dependent post IDs.
     */
    public function dependents_of(int $source_id, array $row): array;

    /**
     * Whether a post can be a SOURCE for this row. Also a cheap pre-filter that
     * keeps a pass from resolving the graph for every entity it passes over.
     *
     * @param \WP_Post $post
     * @param array    $row
     * @return bool
     */
    public function is_eligible_source(\WP_Post $post, array $row): bool;

    /**
     * Whether a post can be a DEPENDENT for this row.
     *
     * @param \WP_Post $post
     * @param array    $row
     * @return bool
     */
    public function is_eligible_dependent(\WP_Post $post, array $row): bool;

    /**
     * The post types this row WRITES. Empty means every post type.
     *
     * @param array $row
     * @return string[]
     */
    public function written_post_types(array $row): array;

    /**
     * Whether the row's `post_status` limit gates the SOURCE (true) or the
     * written post (false).
     *
     * @param array $row
     * @return bool
     */
    public function status_gates_source(array $row): bool;
}
