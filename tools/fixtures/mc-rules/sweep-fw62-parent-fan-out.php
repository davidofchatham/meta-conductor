<?php
/**
 * mc-rules — FW-62 ticket 03 end-to-end: the format pass DECLARES a parent's
 * children (`FormatDispatcher::fan_out()`), `TermDispatcher::run_entity()` marks
 * them, and each child gets its own pass.
 *
 * One title/slug rule on `mc_section` (hierarchical), authored through
 * sweep-lib's `mc_write_ordered_rules()`:
 *
 *   title_pattern  `{default_title} - {parent:slug}`
 *   slug_pattern   `{meta:fw62_code}`
 *
 * so a post's SLUG is written by its own pass (from its `fw62_code` meta) and its
 * children's TITLES read that slug. The tree is parent P > child C > grandchild G.
 *
 *   A  fresh tree           C reads P's slug, G reads C's slug, P has no parent.
 *   B  edit the parent      saving only P re-renders C (the value P's OWN pass
 *                           just wrote, same drain); saving only C re-renders G.
 *   C  zero cost            a rule set with no `{parent:…}` makes fan_out() return
 *                           [] and run no query; the `{parent:…}` set names C again.
 *   D  post-type filter     a `page` child of P is not named (the rule covers
 *                           `mc_section` only).
 *   E  both bulk orders     `ExistingPostsApplier::run_batch()` over the tree
 *                           with the parent processed FIRST (IDs P < C < G) and
 *                           with it processed LAST (IDs G < C < P) lands on the
 *                           same result. The meta is made stale with
 *                           `update_post_meta()`, which raises no pass.
 *
 * Every post is deleted and the fixture's rules restored afterward, pass or
 * fail. Check `wp post list --post_type=mc_item --fields=ID,post_name` after
 * (untouched: this sweep only writes `mc_section` and a `page`).
 *
 * Usage:  wp eval-file .../sweep-fw62-parent-fan-out.php run
 * Prereq: mc-rules seeded.
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\ExistingPostsApplier;
use BWS\MetaConductor\Core\FormatDispatcher;
use BWS\MetaConductor\Core\RuleChoice;
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

$step = $args[0] ?? 'run';
if ( 'run' !== $step ) {
	WP_CLI::error( 'Only the "run" step exists.' );
}

/** The one format row, with `{parent:slug}` in the title unless told otherwise. */
function mcfo_rule( $title_pattern = '{default_title} - {parent:slug}' ) {
	return array(
		'type'          => 'title_slug_from_pattern',
		'enabled'       => true,
		'name'          => 'FW-62 03 parent fan-out',
		'post_type'     => 'mc_section',
		'title_pattern' => $title_pattern,
		'slug_pattern'  => '{meta:fw62_code}',
		'slug_mode'     => 'replace', // default is prefix: code-defaultslug
	);
}

function mcfo_author( $title_pattern = '{default_title} - {parent:slug}' ) {
	mc_write_ordered_rules( array( mcfo_rule( $title_pattern ) ), OptionRuleStorage::KIND_FORMAT );
}

/** One `mc_section` with its code meta (present at the save, so its own pass reads it). */
function mcfo_post( $title, $code, $parent = 0 ) {
	return wp_insert_post( array(
		'post_type'   => 'mc_section',
		'post_status' => 'publish',
		'post_title'  => $title,
		'post_name'   => sanitize_title( $title ),
		'post_parent' => $parent,
		'meta_input'  => array( 'fw62_code' => $code ),
	) );
}

/** Re-parent with no hook, so nothing is queued by the edit itself. */
function mcfo_wire( $child, $parent ) {
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_parent' => $parent ), array( 'ID' => $child ) );
	clean_post_cache( $child );
}

function mcfo_drain() {
	TermDispatcher::instance()->drain();
}

/** [title, slug] as stored, fresh from the database. */
function mcfo_read( $id ) {
	clean_post_cache( $id );
	$p = get_post( $id );
	return array( $p->post_title, $p->post_name );
}

function mcfo_batch() {
	$rows   = StorageFactory::get_instance()->get_kind_rules( OptionRuleStorage::KIND_FORMAT );
	$result = ExistingPostsApplier::run_batch( RuleChoice::encode( OptionRuleStorage::KIND_FORMAT, 0, $rows[0] ) );
	WP_CLI::log( '[sweep] bulk: ' . wp_json_encode( array_intersect_key( $result, array_flip( array( 'status', 'processed', 'total', 'changed' ) ) ) ) );
	return $result;
}

mc_isolate( array() );
mcfo_author();

$ids  = array();
$pass = true;

try {
	// --- A. fresh tree ------------------------------------------------------
	$p = mcfo_post( 'FW62 Parent', 'fw62-p' );
	$c = mcfo_post( 'FW62 Child', 'fw62-c', $p );
	$g = mcfo_post( 'FW62 Grand', 'fw62-g', $c );
	$ids = array( $p, $c, $g );
	mcfo_drain();

	$pass = mc_assert( 'A parent: slug from its code, no parent: tokens left', mcfo_read( $p ), array( 'FW62 Parent', 'fw62-p' ) ) && $pass;
	$pass = mc_assert( 'A child reads the parent', mcfo_read( $c ), array( 'FW62 Child - fw62-p', 'fw62-c' ) ) && $pass;
	$pass = mc_assert( 'A grandchild reads the child', mcfo_read( $g ), array( 'FW62 Grand - fw62-c', 'fw62-g' ) ) && $pass;

	// --- B. edit the parent; the child reads what the parent's pass wrote ----
	update_post_meta( $p, 'fw62_code', 'fw62-p2' );
	wp_update_post( array( 'ID' => $p ) );
	mcfo_drain();
	$pass = mc_assert( 'B parent slug written by its own pass', mcfo_read( $p )[1], 'fw62-p2' ) && $pass;
	$pass = mc_assert( 'B child re-rendered from the parent\'s same-drain value', mcfo_read( $c ), array( 'FW62 Child - fw62-p2', 'fw62-c' ) ) && $pass;

	update_post_meta( $c, 'fw62_code', 'fw62-c2' );
	wp_update_post( array( 'ID' => $c ) );
	mcfo_drain();
	$pass = mc_assert( 'B child slug written by its own pass', mcfo_read( $c )[1], 'fw62-c2' ) && $pass;
	$pass = mc_assert( 'B grandchild re-rendered through the child', mcfo_read( $g ), array( 'FW62 Grand - fw62-c2', 'fw62-g' ) ) && $pass;

	// --- C. zero cost without a {parent:…} rule -----------------------------
	$fd = FormatDispatcher::instance();
	mcfo_author( '{default_title}' );
	$fd->fan_out( $p ); // warm the rule read; the second call must be free
	global $wpdb;
	$before = $wpdb->num_queries;
	$pass   = mc_assert( 'C no {parent:…} rule: fan_out() names nothing', $fd->fan_out( $p ), array() ) && $pass;
	$pass   = mc_assert( 'C …and runs no query', $wpdb->num_queries - $before, 0 ) && $pass;
	mcfo_author();
	$pass = mc_assert( 'C {parent:…} rule back: the child is named', $fd->fan_out( $p ), array( $c ) ) && $pass;
	$pass = mc_assert( 'C …and the grandchild through the child', $fd->fan_out( $c ), array( $g ) ) && $pass;

	// --- D. post-type filter ------------------------------------------------
	$page  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'FW62 Page Child', 'post_parent' => $p ) );
	$ids[] = $page;
	$pass  = mc_assert( 'D a child of a type no {parent:…} rule covers is not named', $fd->fan_out( $p ), array( $c ) ) && $pass;
	wp_delete_post( $page, true );
	array_pop( $ids );
	mcfo_drain();

	// --- E. bulk, both orders -----------------------------------------------
	// E1: the parent has the lowest ID, so the bulk run reaches it first.
	update_post_meta( $p, 'fw62_code', 'fw62-p3' );
	update_post_meta( $c, 'fw62_code', 'fw62-c3' );
	mcfo_batch();
	mcfo_drain();
	$pass = mc_assert( 'E1 parent first: parent', mcfo_read( $p ), array( 'FW62 Parent', 'fw62-p3' ) ) && $pass;
	$pass = mc_assert( 'E1 parent first: child', mcfo_read( $c ), array( 'FW62 Child - fw62-p3', 'fw62-c3' ) ) && $pass;
	$pass = mc_assert( 'E1 parent first: grandchild', mcfo_read( $g ), array( 'FW62 Grand - fw62-c3', 'fw62-g' ) ) && $pass;

	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	$ids = array();

	// E2: the grandchild has the lowest ID, so the bulk run reaches the parent LAST.
	$g = mcfo_post( 'FW62 Grand', 'fw62-g' );
	$c = mcfo_post( 'FW62 Child', 'fw62-c' );
	$p = mcfo_post( 'FW62 Parent', 'fw62-p' );
	$ids = array( $p, $c, $g );
	mcfo_wire( $g, $c );
	mcfo_wire( $c, $p );
	update_post_meta( $p, 'fw62_code', 'fw62-p3' );
	update_post_meta( $c, 'fw62_code', 'fw62-c3' );
	mcfo_batch();
	mcfo_drain();
	$pass = mc_assert( 'E2 parent last (IDs G<C<P): ids ascend', $g < $c && $c < $p, true ) && $pass;
	$pass = mc_assert( 'E2 parent last: parent', mcfo_read( $p ), array( 'FW62 Parent', 'fw62-p3' ) ) && $pass;
	$pass = mc_assert( 'E2 parent last: child — same result as E1', mcfo_read( $c ), array( 'FW62 Child - fw62-p3', 'fw62-c3' ) ) && $pass;
	$pass = mc_assert( 'E2 parent last: grandchild — same result as E1', mcfo_read( $g ), array( 'FW62 Grand - fw62-c3', 'fw62-g' ) ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW62-PARENT-FAN-OUT OK' : 'FW62-PARENT-FAN-OUT FAIL' );
exit( $pass ? 0 : 1 );
