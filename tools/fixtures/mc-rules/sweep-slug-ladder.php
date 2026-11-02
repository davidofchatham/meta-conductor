<?php
/**
 * mc-rules — FW-51 02 behavior sweep: the slug disambiguation ladder.
 *
 * The walk itself is pinned on host PHP (`tests/verify-slug-ladder.php`). What
 * only the testbed can prove is the handler's half: that each rung token is
 * resolved against the real post (published date, meta date, modified date,
 * parent, term), that the uniqueness lookup sees real rows, and that the format
 * pass hands the result to the post row.
 *
 * Each case writes a one-row title/slug rule (`slug_pattern` = the default
 * slug, mode replace), creates TWO temporary `mc_item` posts with the same
 * title — the first takes the base slug, the second collides — runs the format
 * pass over each, asserts the second's `post_name`, and deletes both. Temporary
 * posts carry throw-away names (`mcl-tmp-N`), so no fixture post is ever
 * renamed. `restore` is run implicitly at the end and the sweep asserts no
 * temporary post survives and every fixture `mc_item` still has its manifest
 * name (matrix §7 restore gotcha).
 *
 * Usage: wp eval-file .../sweep-slug-ladder.php --allow-root
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;

if ( ! TermDispatcher::instance() ) {
	WP_CLI::error( 'No registered TermDispatcher.' );
}

// eval-file runs inside a function, so shared state rides $GLOBALS.
$GLOBALS['mcl_created'] = array();

/** Create a post with the reapply switch held down (no pass runs on insert). */
function mcl_make( $title, array $extra = array() ) {
	static $n = 0;
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	$id = wp_insert_post( array_merge( array(
		'post_type'   => 'mc_item',
		'post_status' => 'publish',
		'post_title'  => $title,
		'post_name'   => 'mcl-tmp-' . ( ++$n ),
	), $extra ), true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	if ( isset( $extra['terms'] ) ) { // wp_insert_post's tax_input needs a capable user.
		wp_set_object_terms( $id, $extra['terms'], 'mc_topic' );
	}
	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	$GLOBALS['mcl_created'][] = $id;
	return $id;
}

function mcl_cleanup() {
	foreach ( $GLOBALS['mcl_created'] as $id ) {
		wp_delete_post( $id, true );
	}
	$GLOBALS['mcl_created'] = array();
}

function mcl_slug( $id ) {
	clean_post_cache( $id );
	return (string) get_post_field( 'post_name', $id );
}

/**
 * One case: the first post takes the base slug, the second collides.
 *
 * @param array $rule_extra Fields of the ladder rule under test.
 * @param array $b_extra    wp_insert_post args for the colliding post.
 */
function mcl_case( $label, $title, array $rule_extra, array $b_extra, $want_b, array $a_extra = array(), $want_a = null ) {
	$dispatcher = TermDispatcher::instance();

	mc_write_rule_types( array( 'title_slug_rules' => array( $rule_extra + array(
		'enabled'              => true,
		'name'                 => 'Ladder sweep',
		'post_type'            => 'mc_item',
		'slug_pattern'         => '{default_slug}',
		'slug_mode'            => 'replace',
		'escalation_placement' => 'suffix',
	) ) ) );

	$a = mcl_make( $title, $a_extra );
	$dispatcher->drain_post( $a );
	mc_assert( "$label — first post keeps the base slug", mcl_slug( $a ), $want_a ?? sanitize_title( $title ) );

	$b = mcl_make( $title, $b_extra );
	$dispatcher->drain_post( $b );
	mc_assert( "$label — second post takes the ladder slug", mcl_slug( $b ), $want_b );

	mcl_cleanup();
}

// Only the title/slug rule live: a hierarchy rule would add Inland's ancestors and change which term is first.
mc_isolate( array( 'title_slug_rules' ) );

$year = wp_date( 'Y' );
$pub  = array( 'post_date' => '2026-03-14 09:05:00' );

// Nothing at all: numbering, the pre-existing behavior.
mcl_case( 'off (blank)', 'Ladder Off', array( 'slug_escalation' => '' ), $pub, 'ladder-off-2' );

// Published date, anchorless — the Site E row 2 shape: no date token in the pattern.
mcl_case( 'published date, anchorless', 'Ladder Pub', array( 'slug_escalation' => '{pub_year}' ), $pub, 'ladder-pub-2026' );
mcl_case( 'published date, prefix', 'Ladder Pub Pre', array( 'slug_escalation' => '{pub_year}', 'escalation_placement' => 'prefix' ), $pub, '2026-ladder-pub-pre' );

// Meta date, anchored: the slug already shows the year, the month splices after it.
$meta = array( 'meta_input' => array( 'mc_event_date' => '20290705' ) );
mcl_case( 'meta date, anchored', 'Ladder Meta', array(
	'slug_pattern'    => '{default_slug}-{date_year:mc_event_date}',
	'slug_escalation' => '{date_year:mc_event_date}',
), $meta, 'ladder-meta-2029-07', $meta, 'ladder-meta-2029' );
mcl_case( 'meta date, skip to day', 'Ladder Meta Day', array(
	'slug_pattern'    => '{default_slug}-{date_year:mc_event_date}',
	'slug_escalation' => '{date_day:mc_event_date}',
), $meta, 'ladder-meta-day-2029-07-05', $meta, 'ladder-meta-day-2029' );
mcl_case( 'meta date, anchorless', 'Ladder Meta Free', array( 'slug_escalation' => '{date_year:mc_event_date}' ), $meta, 'ladder-meta-free-2029' );

// Modified date: the post was just written, so its year is this year.
mcl_case( 'modified date', 'Ladder Mod', array( 'slug_escalation' => '{mod_year}' ), array(), "ladder-mod-$year" );

// Parent.
$parent = mcl_make( 'Ladder Parent Holder', array() );
$GLOBALS['mcl_created'] = array(); // kept across both parent cases, deleted below
add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
wp_update_post( array( 'ID' => $parent, 'post_name' => 'ladder-holder' ) );
remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
mcl_case( 'parent, suffix', 'Ladder Par', array( 'slug_escalation' => '{parent:slug}' ), array( 'post_parent' => $parent ), 'ladder-par-ladder-holder' );
mcl_case( 'parent, prefix', 'Ladder Par Pre', array( 'slug_escalation' => '{parent:slug}', 'escalation_placement' => 'prefix' ), array( 'post_parent' => $parent ), 'ladder-holder-ladder-par-pre' );
wp_delete_post( $parent, true );

// Term.
mcl_case( 'term', 'Ladder Term', array( 'slug_escalation' => '{term:mc_topic}' ), array( 'terms' => array( mc_tid( 'topic-inland' ) ) ), 'ladder-term-inland' );

// Cumulative: the first rung empty (no parent), so the second is used; then both.
mcl_case( 'empty rung skipped', 'Ladder Skip', array( 'slug_escalation' => '{parent:slug}{term:mc_topic}' ), array( 'terms' => array( mc_tid( 'topic-inland' ) ) ), 'ladder-skip-inland' );

// Ladder exhausted: nothing resolves, core numbers.
mcl_case( 'exhausted falls through to numbering', 'Ladder Gone', array( 'slug_escalation' => '{parent:slug}' ), array(), 'ladder-gone-2' );

// ── Restore + leftovers ────────────────────────────────────────────────────
mc_restore();
global $wpdb;
mc_assert( 'no temporary post survives', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name LIKE 'mcl-tmp-%' OR post_name LIKE 'ladder-%'" ), 0 );
$bad = array();
foreach ( mc_sweep_manifest()['posts'] as $slug => $def ) {
	if ( 'mc_item' !== $def['post_type'] ) {
		continue;
	}
	$id = mc_pid( $slug );
	if ( ! $id || get_post_field( 'post_name', $id ) !== $def['post_name'] ) {
		$bad[] = $slug;
	}
}
mc_assert( 'every fixture mc_item still has its manifest post_name', $bad, array() );
WP_CLI::log( 'post names: ' . implode( ', ', $wpdb->get_col( "SELECT post_name FROM {$wpdb->posts} WHERE post_type='mc_item' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID" ) ) );
