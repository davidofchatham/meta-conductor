<?php
/**
 * mc-rules — FW-51 ticket 04 behavior sweep: flat URLs for a hierarchical type.
 *
 * Proves what the static gates cannot: that a title/slug row with `flat_urls`
 * on rewrites the permalink and its read-time surfaces, resolves `/base/child/`
 * and its paged form, 301s the old nested URL only on an exact chain, and picks
 * a deterministic post when two share a name.
 *
 * SUBJECT: `mc_flat` (manifest v9) — hierarchical, no taxonomies, no seeded
 * rules. The sweep authors its one row; the same-name twin pair is created and
 * deleted HERE, because a seeded twin would make `mc_fixture_find_post()` (oldest
 * match) upsert both fixtures onto one post.
 *
 * The request filter is driven directly with the vars `WP::parse_request()`
 * builds, and the 301 is caught through the `wp_redirect` filter (which throws,
 * so the handler's `exit` is never reached). The end-to-end half — a real HTTP
 * request — is the `curl` recipe in handler-fixture-matrix.md §8.
 *
 * Core note, not ours: a junk chain falls through to core, which 404s and then
 * `redirect_guess_404_permalink()` guesses the post by name and 301s it to the
 * flat link (`x-redirect-by: WordPress`). That is core's guess, not this filter;
 * the assertion here is that the FILTER leaves a junk chain untouched.
 *
 * ⚠️ Saves posts on a type with no rule that rewrites names, but check anyway:
 * the last step lists `post_name` for every fixture post (§7 restore gotcha).
 *
 * Usage (one step per eval):
 *   wp eval-file .../sweep-flat-urls.php run
 *   wp eval-file .../sweep-flat-urls.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\TaxonomyManager;

$step = $args[0] ?? 'run';

/** The live title/slug handler. */
function mcflat_handler() {
	return TaxonomyManager::get_instance()->get_handler( 'title_slug_rules' );
}

/** Author the one flat row (or an off one). */
function mcflat_author( $flat, array $extra = array() ) {
	mc_write_rule_types( array(
		'title_slug_rules' => array_merge(
			array(
				array(
					'type'      => 'title_slug_rules',
					'enabled'   => true,
					'name'      => 'MC flat',
					'post_type' => 'mc_flat',
					'flat_urls' => (bool) $flat,
				),
			),
			$extra
		),
	) );
}

/**
 * Run the request filter; return ['vars' => …] or ['redirect' => [url, status]].
 *
 * @param array $vars Query vars as parse_request builds them.
 */
function mcflat_request( array $vars ) {
	$catch = static function ( $location, $status ) {
		throw new \RuntimeException( wp_json_encode( array( $location, $status ) ) );
	};
	add_filter( 'wp_redirect', $catch, 1, 2 );
	try {
		return array( 'vars' => mcflat_handler()->resolve_flat_request( $vars ) );
	} catch ( \RuntimeException $e ) {
		return array( 'redirect' => json_decode( $e->getMessage(), true ) );
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
	}
}

/** Vars as the `(.+?)(?:/([0-9]+))?/?$` rewrite delivers a path. */
function mcflat_vars( $name, $page = '' ) {
	return array( 'mc_flat' => $name, 'post_type' => 'mc_flat', 'name' => $name, 'page' => $page );
}

/** Delete every same-name twin (raw SQL: a logged-out WP_Query hides drafts). */
function mcflat_twins_delete() {
	global $wpdb;
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='mc_flat' AND post_name='mc-flat-twin'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

/** Every fixture post's name against the manifest (§7 gotcha). */
function mcflat_names_ok() {
	$bad = array();
	foreach ( mc_sweep_manifest()['posts'] as $slug => $def ) {
		$id = mc_pid( $slug );
		if ( ! $id || get_post_field( 'post_name', $id ) !== $def['post_name'] ) {
			$bad[] = $slug;
		}
	}
	return $bad;
}

$parent  = mc_pid( 'flat-parent' );
$child   = mc_pid( 'flat-child' );
$parent2 = mc_pid( 'flat-parent-2' );
$child2  = mc_pid( 'flat-child-2' );
if ( ! $child ) {
	WP_CLI::error( 'mc_flat fixture posts missing — seed.php first (manifest v9).' );
}

if ( 'restore' === $step ) {
	mcflat_twins_delete();
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_parent' => $child, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any' ) ) as $a ) {
		wp_delete_attachment( $a, true );
	}
	mc_restore();
	mc_assert( 'restore: every fixture post_name matches the manifest', mcflat_names_ok(), array() );
	mc_assert( 'restore: no twin left', (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_name='mc-flat-twin'" ), 0 );
	WP_CLI::log( 'post names: ' . implode( ', ', array_map( static fn( $s ) => get_post_field( 'post_name', mc_pid( $s ) ), array( 'flat-parent', 'flat-child', 'flat-parent-2', 'flat-child-2' ) ) ) );
	return;
}

$home = untrailingslashit( home_url() );

// ── 1. Permalinks ──────────────────────────────────────────────────────────
mcflat_author( true );
mc_assert( '1a child permalink is flat', get_permalink( $child ), "$home/mc-flat/mc-flat-child/" );
mc_assert( '1b parent permalink untouched', get_permalink( $parent ), "$home/mc-flat/mc-flat-parent/" );
mc_assert( '1c other pair flat too', get_permalink( $child2 ), "$home/mc-flat/mc-flat-other-child/" );
mc_assert( '1d non-flat type untouched (mc_section child stays nested)', get_permalink( mc_pid( 'section-child' ) ), $home . '/mc_section/mc-grand/mc-parent/mc-child/' );
mc_assert( '1e non-hierarchical mc_item untouched', get_permalink( mc_pid( 'item-alpha' ) ), $home . '/mc_item/mc-item-alpha/' );
mc_assert( '1f flat_post_types', mcflat_handler()->flat_post_types(), array( 'mc_flat' ) );

// flat_urls on a NON-hierarchical type is ignored.
mcflat_author( true, array( array( 'type' => 'title_slug_rules', 'enabled' => true, 'name' => 'item', 'post_type' => 'mc_item', 'flat_urls' => true ) ) );
mc_assert( '1g flat_urls on a flat (non-hierarchical) type is ignored', mcflat_handler()->flat_post_types(), array( 'mc_flat' ) );
// A second row on the same type does not flatten what the winning row did not.
mcflat_author( false, array( array( 'type' => 'title_slug_rules', 'enabled' => true, 'name' => 'dup', 'post_type' => 'mc_flat', 'flat_urls' => true ) ) );
mc_assert( '1h first row per type decides (off row above an on row = off)', mcflat_handler()->flat_post_types(), array() );
mc_assert( '1i off reverts the permalink to nested', get_permalink( $child ), "$home/mc-flat/mc-flat-parent/mc-flat-child/" );
mcflat_author( true );

// ── 2. REST, sitemap, attachment ───────────────────────────────────────────
$rest = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/mc_flat/$child" ) )->get_data();
mc_assert( '2a REST link is flat', $rest['link'] ?? '', "$home/mc-flat/mc-flat-child/" );

$provider = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
$locs     = array_column( $provider->get_url_list( 1, 'mc_flat' ), 'loc' );
mc_assert( '2b sitemap lists the flat child', in_array( "$home/mc-flat/mc-flat-child/", $locs, true ), true );
mc_assert( '2c sitemap lists no nested child', in_array( "$home/mc-flat/mc-flat-parent/mc-flat-child/", $locs, true ), false );

$att = wp_insert_attachment( array( 'post_title' => 'mc flat att', 'post_name' => 'mc-flat-att', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ), '', $child );
mc_assert( '2d attachment link follows the flat parent', str_starts_with( get_attachment_link( $att ), "$home/mc-flat/mc-flat-child/" ), true );
wp_delete_attachment( $att, true );

// ── 3. Resolver ────────────────────────────────────────────────────────────
$r = mcflat_request( mcflat_vars( 'mc-flat-child' ) );
mc_assert( '3a flat path resolves to p=ID', $r['vars']['p'] ?? null, $child );
mc_assert( '3b …and drops name + the type query var', isset( $r['vars']['name'] ) || isset( $r['vars']['mc_flat'] ), false );
mc_assert( '3c …and keeps post_type', $r['vars']['post_type'] ?? '', 'mc_flat' );

$r = mcflat_request( mcflat_vars( 'mc-flat-child', '2' ) );
mc_assert( '3d paged flat path keeps page', array( $r['vars']['p'] ?? null, $r['vars']['page'] ?? null ), array( $child, '2' ) );

$r = mcflat_request( mcflat_vars( 'mc-flat-parent' ) );
mc_assert( '3e top-level post resolves too', $r['vars']['p'] ?? null, $parent );

$unknown = mcflat_vars( 'mc-flat-nope' );
mc_assert( '3f no match falls through untouched', mcflat_request( $unknown )['vars'] ?? null, $unknown );

$other = array( 'post_type' => 'mc_section', 'name' => 'mc-child' );
mc_assert( '3g another type is untouched', mcflat_request( $other )['vars'] ?? null, $other );

// ── 4. Nested → flat 301 ───────────────────────────────────────────────────
$r = mcflat_request( mcflat_vars( 'mc-flat-parent/mc-flat-child' ) );
mc_assert( '4a exact nested chain 301s to the flat link', $r['redirect'] ?? null, array( "$home/mc-flat/mc-flat-child/", 301 ) );

$r = mcflat_request( mcflat_vars( 'mc-flat-parent/mc-flat-child', '2' ) );
mc_assert( '4b paging suffix is preserved', $r['redirect'][0] ?? '', "$home/mc-flat/mc-flat-child/2/" );

$junk = mcflat_vars( 'junk/mc-flat-child' );
mc_assert( '4c junk chain: the filter leaves it to core', mcflat_request( $junk )['vars'] ?? null, $junk );

$wrong = mcflat_vars( 'mc-flat-other-parent/mc-flat-child' );
mc_assert( '4d another parent\'s chain is stale, not a match', mcflat_request( $wrong )['vars'] ?? null, $wrong );

$partial = mcflat_vars( 'mc-flat-parent/mc-flat-parent/mc-flat-child' );
mc_assert( '4e a chain with an extra segment is not a match', mcflat_request( $partial )['vars'] ?? null, $partial );

$feed = mcflat_vars( 'mc-flat-parent/mc-flat-child' ) + array( 'feed' => 'feed' );
mc_assert( '4f a nested path carrying a feed is not guessed at', mcflat_request( $feed )['vars'] ?? null, $feed );

// ── 5. Duplicate names: published, then lowest ID, then any status ─────────
mcflat_twins_delete();
$draft = wp_insert_post( array( 'post_type' => 'mc_flat', 'post_name' => 'mc-flat-twin', 'post_title' => 'Twin', 'post_status' => 'draft', 'post_parent' => $parent2 ) );
$pub   = wp_insert_post( array( 'post_type' => 'mc_flat', 'post_name' => 'mc-flat-twin', 'post_title' => 'Twin', 'post_status' => 'publish', 'post_parent' => $parent ) );
mc_assert( '5a setup: both twins kept the shared name (draft ID is lower)', array( get_post_field( 'post_name', $draft ), get_post_field( 'post_name', $pub ), $draft < $pub ), array( 'mc-flat-twin', 'mc-flat-twin', true ) );

$r = mcflat_request( mcflat_vars( 'mc-flat-twin' ) );
mc_assert( '5b published twin wins over the lower-ID draft', $r['vars']['p'] ?? null, $pub );

$r = mcflat_request( mcflat_vars( 'mc-flat-parent/mc-flat-twin' ) );
mc_assert( '5c nested chain of the published twin 301s', $r['redirect'][0] ?? '', "$home/mc-flat/mc-flat-twin/" );

$dn = mcflat_vars( 'mc-flat-other-parent/mc-flat-twin' );
mc_assert( '5d nested chain of the DRAFT twin does not redirect (no draft URL leaked)', mcflat_request( $dn )['vars'] ?? null, $dn );

$pub2 = wp_insert_post( array( 'post_type' => 'mc_flat', 'post_name' => 'mc-flat-twin', 'post_title' => 'Twin 2', 'post_status' => 'publish', 'post_parent' => $parent2 ) );
$r = mcflat_request( mcflat_vars( 'mc-flat-twin' ) );
mc_assert( '5e two published twins: lowest ID', array( $r['vars']['p'] ?? null, $pub < $pub2 ), array( $pub, true ) );
wp_delete_post( $pub2, true );

wp_update_post( array( 'ID' => $pub, 'post_status' => 'draft' ) );
$r = mcflat_request( mcflat_vars( 'mc-flat-twin' ) );
mc_assert( '5f no published twin: lowest ID of any status', $r['vars']['p'] ?? null, $draft );
mcflat_twins_delete();

// ── 6. Off again: nothing is served flat ───────────────────────────────────
mcflat_author( false );
$flat = mcflat_vars( 'mc-flat-child' );
mc_assert( '6a flat off: request filter is inert', mcflat_request( $flat )['vars'] ?? null, $flat );
mc_assert( '6b flat off: no hierarchical type is flat', mcflat_handler()->flat_post_types(), array() );

mcflat_author( true );
mc_assert( '7 post names unchanged by the sweep', mcflat_names_ok(), array() );
WP_CLI::log( 'Run `restore` when done.' );
