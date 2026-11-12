<?php
/**
 * mc-rules — FW-51 ticket 05 behavior sweep: per-type slug uniqueness for a flat
 * hierarchical type.
 *
 * The numbering loop and the reserved list are pinned on host PHP
 * (`tests/verify-flat-slug.php`). What only the testbed proves is the handler's
 * half: that the lookup sees real rows across parents, that the ladder runs
 * first and numbering last, that a reserved name is bumped, and that a type
 * WITHOUT `flat_urls` keeps core's per-parent behavior.
 *
 * SUBJECT: `mc_flat` (manifest v9). Each case writes a one-row title/slug rule
 * (`slug_pattern` = the default slug, mode replace), creates temporary posts
 * under the two seeded parents (`mcf-tmp-N` throw-away names, so no fixture post
 * is ever renamed), runs the format pass over each in order, asserts the
 * resulting `post_name`, and deletes them. The last step asserts no temporary
 * post survives and every fixture post still has its manifest name (matrix §7).
 *
 * Usage: wp eval-file .../sweep-flat-slugs.php --allow-root
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;

if ( ! TermDispatcher::instance() ) {
	WP_CLI::error( 'No registered TermDispatcher.' );
}

// eval-file runs inside a function, so shared state rides $GLOBALS.
$GLOBALS['mcf_created'] = array();

/** Create a post with the reapply switch held down (no pass runs on insert). */
function mcf_make( $title, $parent = 0 ) {
	static $n = 0;
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	$id = wp_insert_post( array(
		'post_type'   => 'mc_flat',
		'post_status' => 'publish',
		'post_title'  => $title,
		'post_name'   => 'mcf-tmp-' . ( ++$n ),
		'post_parent' => $parent,
	), true );
	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	$GLOBALS['mcf_created'][] = $id;
	return $id;
}

function mcf_cleanup() {
	foreach ( $GLOBALS['mcf_created'] as $id ) {
		wp_delete_post( $id, true );
	}
	$GLOBALS['mcf_created'] = array();
}

/** Run the pass over a new post and return the name it ended with. */
function mcf_slug_of( $title, $parent ) {
	$id = mcf_make( $title, $parent );
	TermDispatcher::instance()->drain_post( $id );
	clean_post_cache( $id );
	return (string) get_post_field( 'post_name', $id );
}

/**
 * Author the one rule, then create one post per `[title, parent]` pair in order
 * and compare the names they end with.
 */
function mcf_case( $label, array $rule_extra, array $posts, array $want ) {
	mc_write_rule_types( array( 'title_slug_from_pattern' => array( $rule_extra + array(
		'type'                 => 'title_slug_from_pattern',
		'enabled'              => true,
		'name'                 => 'Flat slug sweep',
		'post_type'            => 'mc_flat',
		'slug_pattern'         => '{default_slug}',
		'slug_mode'            => 'replace',
		'flat_urls'            => true,
		'slug_escalation'      => '',
		'escalation_placement' => 'suffix',
	) ) ) );

	$got = array();
	foreach ( $posts as $p ) {
		$got[] = mcf_slug_of( $p[0], $p[1] );
	}
	// Joined, not an array: mc_assert() sorts arrays, and the ORDER is what is under test.
	mc_assert( $label, implode( ' | ', $got ), implode( ' | ', $want ) );
	mcf_cleanup();
}

mc_isolate( array( 'title_slug_from_pattern' ) );

$p1 = mc_pid( 'flat-parent' );
$p2 = mc_pid( 'flat-parent-2' );
$s2 = get_post_field( 'post_name', $p2 ); // mc-flat-other-parent

// ── 1. Same name under different parents ──────────────────────────────────
mcf_case( '1a flat, no ladder: the second parent child is numbered per TYPE',
	array(), array( array( 'Shared Name', $p1 ), array( 'Shared Name', $p2 ) ), array( 'shared-name', 'shared-name-2' ) );

mcf_case( '1b flat, parent rung: the ladder runs first',
	array( 'slug_escalation' => '{parent:slug}' ), array( array( 'Shared Name', $p1 ), array( 'Shared Name', $p2 ) ), array( 'shared-name', "shared-name-$s2" ) );

mcf_case( '1c flat, parent rung, prefix placement',
	array( 'slug_escalation' => '{parent:slug}', 'escalation_placement' => 'prefix' ), array( array( 'Shared Name', $p1 ), array( 'Shared Name', $p2 ) ), array( 'shared-name', "$s2-shared-name" ) );

mcf_case( '1d ladder exhausted: numbering is the last resort',
	array( 'slug_escalation' => '{parent:slug}' ),
	array( array( 'Shared Name', $p1 ), array( 'Shared Name', $p2 ), array( 'Shared Name', $p2 ) ),
	array( 'shared-name', "shared-name-$s2", "shared-name-$s2-2" ) );

mcf_case( '1e a rung that resolves empty (top-level post) is skipped, numbering follows',
	array( 'slug_escalation' => '{parent:slug}' ), array( array( 'Shared Name', $p1 ), array( 'Shared Name', 0 ) ), array( 'shared-name', 'shared-name-2' ) );

// ── 2. Reserved names ─────────────────────────────────────────────────────
mcf_case( '2a reserved names are numbered',
	array(), array( array( 'Page', $p1 ), array( 'Feed', $p1 ), array( 'Embed', $p1 ), array( 'Attachment', $p1 ), array( '2026', $p1 ), array( 'Page2', $p1 ) ),
	array( 'page-2', 'feed-2', 'embed-2', 'attachment-2', '2026-2', 'page2-2' ) );

mcf_case( '2b a reserved name skips the ladder: numbering, not a parent rung',
	array( 'slug_escalation' => '{parent:slug}' ), array( array( 'Page', $p2 ) ), array( 'page-2' ) );

mcf_case( '2c near-misses are not reserved',
	array(), array( array( 'Pages', $p1 ), array( 'Feedback', $p1 ), array( 'Page 2', $p1 ) ), array( 'pages', 'feedback', 'page-2' ) );

// ── 3. A type without flat_urls keeps today's per-parent behavior ─────────
mcf_case( '3a flat off: the same name under different parents is allowed (core, per parent)',
	array( 'flat_urls' => false ), array( array( 'Shared Name', $p1 ), array( 'Shared Name', $p2 ) ), array( 'shared-name', 'shared-name' ) );

mcf_case( '3b flat off: a same-parent clash is numbered by core',
	array( 'flat_urls' => false ), array( array( 'Shared Name', $p1 ), array( 'Shared Name', $p1 ) ), array( 'shared-name', 'shared-name-2' ) );

mcf_case( '3c flat off: page and attachment are not in core\'s list, so they stay',
	array( 'flat_urls' => false ), array( array( 'Page', $p1 ), array( 'Attachment', $p1 ) ), array( 'page', 'attachment' ) );

// ── 4. Fixture posts untouched ────────────────────────────────────────────
mcf_cleanup();
$bad = array();
foreach ( mc_sweep_manifest()['posts'] as $slug => $def ) {
	$id = mc_pid( $slug );
	if ( ! $id || get_post_field( 'post_name', $id ) !== $def['post_name'] ) {
		$bad[] = $slug;
	}
}
mc_assert( '4a every fixture post_name matches the manifest', $bad, array() );
mc_assert( '4b no temporary post survives', (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_name LIKE 'mcf-tmp-%' OR post_name LIKE 'shared-name%'" ), 0 );

mc_restore();
