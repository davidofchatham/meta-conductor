<?php
/**
 * mc-rules blueprint — fixture post lookup.
 *
 * Shared by seed.php (upsert existence check) and verify.php (assertions) so
 * the two can never disagree about whether a fixture exists.
 *
 * ── Two status sets, and why ───────────────────────────────────────────────
 *
 * `mc_fixture_post_statuses()` is the UPSERT set: what an MC-owned fixture may
 * legitimately hold. It excludes trash on purpose. `mc_fixture_readable_statuses()`
 * adds trash and is for READING posts owned by a blueprint we compose on —
 * core-structures owns a deliberately trashed fixture, and treating a policy
 * about our own posts as a census of everyone's is what made verify.php report
 * a slug change that had not happened.
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
	 * Statuses an MC-OWNED fixture post may legitimately hold — the UPSERT set.
	 *
	 * Deliberately excludes trash/auto-draft: a trashed MC fixture should be
	 * re-created by the next seed, not silently revived by an upsert. That is
	 * a statement about the posts this blueprint OWNS, and it stays.
	 *
	 * It is NOT the right set for reading a post someone else owns — see
	 * mc_fixture_readable_statuses().
	 *
	 * @return string[]
	 */
	function mc_fixture_post_statuses() {
		return array( 'publish', 'draft', 'pending', 'private', 'future' );
	}
}

if ( ! function_exists( 'mc_fixture_readable_statuses' ) ) {
	/**
	 * Every status a fixture post can be FOUND in — the upsert set plus trash.
	 *
	 * Exists because the upsert set is a policy, not a census, and the two
	 * diverged the moment a blueprint we compose on started owning a
	 * deliberately trashed fixture. core-structures v14 added
	 * `staff-gate-trashed` (post_status `trash`, on purpose — it is the one
	 * status where a post EXISTS but is visible to no viewer, which is what
	 * that fixture exists to test), and its own seeder lists `trash` for
	 * exactly this reason. Ours did not follow, so verify.php's section-B
	 * negative controls — which iterate the LIVE core manifest, not a pinned
	 * copy — read it as missing and reported a slug change that had not
	 * happened.
	 *
	 * Use this for READS of posts this blueprint does not own. Never for the
	 * upsert: reviving a trashed MC fixture is the thing the other list
	 * refuses to do.
	 *
	 * `auto-draft` stays out of both — it is an editor artifact, not a fixture.
	 *
	 * @return string[]
	 */
	function mc_fixture_readable_statuses() {
		return array_merge( mc_fixture_post_statuses(), array( 'trash' ) );
	}
}

if ( ! defined( 'MC_FIXTURE_STAMP_KEY' ) ) {
	/** Post meta seed.php stamps on every manifest post: the manifest key. Survives a rename. */
	define( 'MC_FIXTURE_STAMP_KEY', '_mc_fixture_slug' );
}
if ( ! function_exists( 'mc_fixture_stamped_ids' ) ) {
	/**
	 * IDs of the posts stamped with a manifest key, oldest first.
	 *
	 * Same unauthenticated-safe shape as the name lookup: `meta_query` never
	 * sets `is_single`, so the permission re-check in the header note cannot
	 * discard drafts.
	 *
	 * @param string        $fixture_slug Manifest posts[] key.
	 * @param string        $post_type    Post type.
	 * @param string[]|null $statuses     Statuses to search, or null for the upsert set.
	 * @return int[]
	 */
	function mc_fixture_stamped_ids( $fixture_slug, $post_type, $statuses = null ) {
		$q = new \WP_Query(
			array(
				'meta_key'               => MC_FIXTURE_STAMP_KEY,
				'meta_value'             => $fixture_slug,
				'post_type'              => $post_type,
				'post_status'            => $statuses ? $statuses : mc_fixture_post_statuses(),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', $q->posts );
	}
}
if ( ! function_exists( 'mc_fixture_find_post' ) ) {
	/**
	 * Find a fixture post by slug + type, regardless of login state.
	 *
	 * Defaults to the UPSERT status set, because seed.php is the caller that
	 * must not adopt a trashed post. A reader that wants any status passes
	 * mc_fixture_readable_statuses() explicitly, so the widening is visible at
	 * the call site rather than being a default everything inherits.
	 *
	 * Pass `$fixture_slug` (the manifest key) for an MC-owned post: the stamp
	 * seed.php writes is tried first, so a rename (the fixture's own title_slug
	 * rule renames `item-alpha`) cannot lose the post. `post_name` is the
	 * fallback for a post seeded before the stamp existed. Posts another
	 * blueprint owns have no stamp; omit it and the lookup is by name alone.
	 *
	 * @param string        $post_name    Post slug.
	 * @param string        $post_type    Post type.
	 * @param string[]|null $statuses     Statuses to search, or null for the upsert set.
	 * @param string        $fixture_slug Manifest posts[] key, or '' for a by-name lookup.
	 * @return int Post ID, or 0 if not found. Oldest match wins.
	 */
	function mc_fixture_find_post( $post_name, $post_type, $statuses = null, $fixture_slug = '' ) {
		if ( '' !== $fixture_slug ) {
			$stamped = mc_fixture_stamped_ids( $fixture_slug, $post_type, $statuses );
			if ( $stamped ) {
				return $stamped[0];
			}
		}
		$q = new \WP_Query(
			array(
				'post_name__in'          => array( $post_name ),
				'post_type'              => $post_type,
				'post_status'            => $statuses ? $statuses : mc_fixture_post_statuses(),
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
	 * With `$fixture_slug`, the census is the union of the stamped posts and the
	 * posts holding the name, so a renamed stamped twin and an unstamped copy a
	 * pre-stamp seed left both show up.
	 *
	 * @param string $post_name    Post slug.
	 * @param string $post_type    Post type.
	 * @param string $fixture_slug Manifest posts[] key, or '' to count by name alone.
	 * @return int[] Matching IDs, oldest first.
	 */
	function mc_fixture_count_posts( $post_name, $post_type, $fixture_slug = '' ) {
		$stamped = '' !== $fixture_slug ? mc_fixture_stamped_ids( $fixture_slug, $post_type ) : array();
		$q       = new \WP_Query(
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

		$ids = array_unique( array_merge( $stamped, array_map( 'intval', $q->posts ) ) );
		sort( $ids );
		return $ids;
	}
}
