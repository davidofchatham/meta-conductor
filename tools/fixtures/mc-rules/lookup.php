<?php
/**
 * mc-rules blueprint — fixture post lookup.
 *
 * Shared by seed.php (upsert existence check) and verify.php (assertions) so
 * the two can never disagree about whether a fixture exists.
 *
 * ── Why not get_posts( name=..., post_status='any' ) ────────────────────────
 *
 * That shape silently returns NOTHING for any non-published post when run
 * unauthenticated — which is exactly how WP-CLI runs. It cost us four
 * duplicate `mc-draft-child` posts, one per seed run, before anyone noticed.
 *
 * The cause is NOT the status SQL. Verified against the live testbed: the
 * generated query is
 *
 *     ... post_name='mc-draft-child' AND post_type='mc_section'
 *         AND ((post_status <> 'trash' AND post_status <> 'auto-draft'))
 *
 * which matches the drafts, and `posts_results` confirms the DB hands back all
 * four rows. WP_Query then DISCARDS them after the query, in the
 * single-post permission re-check (wp-includes/class-wp-query.php ~3509-3525):
 *
 *   - `name` sets is_single = true, which arms that block.
 *   - The guard is `! in_array( $status, $q_status, true )`. With
 *     post_status='any', $q_status is the literal array ['any'] — it never
 *     contains 'draft' — so the "specifically requested" escape hatch misses.
 *   - 'draft' is a non-public, protected status, so the block requires a
 *     logged-in user with edit rights. WP-CLI is uid=0 → `$this->posts = []`.
 *
 * Confirmed by flipping ONLY the auth state: identical args returned [] at
 * uid=0 and [115,114,113,103] at uid=1.
 *
 * `post_name__in` does not set is_single, so the permission re-check never
 * arms and the lookup works unauthenticated. Explicit statuses are belt-and-
 * braces (they'd also satisfy the in_array guard).
 *
 * ── Ordering ───────────────────────────────────────────────────────────────
 *
 * orderby=ID/ASC deliberately returns the OLDEST match. Where duplicates
 * already exist, that is the copy the cleanup keeps, so the seeder converges
 * on the surviving post rather than adopting one that is about to be deleted.
 *
 * Do NOT "simplify" this back to get_posts( name=..., 'any' ).
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) ) {
	exit;
}

if ( ! function_exists( 'mc_fixture_post_statuses' ) ) {
	/**
	 * Statuses a fixture post may legitimately hold.
	 *
	 * Deliberately excludes trash/auto-draft: a trashed fixture should be
	 * re-created, not silently revived by an upsert.
	 *
	 * @return string[]
	 */
	function mc_fixture_post_statuses() {
		return array( 'publish', 'draft', 'pending', 'private', 'future' );
	}
}

if ( ! function_exists( 'mc_fixture_find_post' ) ) {
	/**
	 * Find a fixture post by slug + type, regardless of status or login state.
	 *
	 * @param string $post_name Post slug.
	 * @param string $post_type Post type.
	 * @return int Post ID, or 0 if not found. Oldest match wins.
	 */
	function mc_fixture_find_post( $post_name, $post_type ) {
		$q = new \WP_Query(
			array(
				'post_name__in'          => array( $post_name ),
				'post_type'              => $post_type,
				'post_status'            => mc_fixture_post_statuses(),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return $q->posts ? (int) $q->posts[0] : 0;
	}
}

if ( ! function_exists( 'mc_fixture_count_posts' ) ) {
	/**
	 * Count posts sharing a fixture slug — >1 means duplicates accumulated.
	 *
	 * @param string $post_name Post slug.
	 * @param string $post_type Post type.
	 * @return int[] Matching IDs, oldest first.
	 */
	function mc_fixture_count_posts( $post_name, $post_type ) {
		$q = new \WP_Query(
			array(
				'post_name__in'          => array( $post_name ),
				'post_type'              => $post_type,
				'post_status'            => mc_fixture_post_statuses(),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'intval', $q->posts );
	}
}
