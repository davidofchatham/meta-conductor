<?php
/**
 * mc-rules — FW-5 ticket 05 end-to-end: a parent edit reaches its children
 * through an enabled `field_from_related_post` row (parent relationship), with no
 * `{parent:…}` pattern anywhere. `FormatDispatcher::fan_out()` DECLARES the
 * dependents, `TermDispatcher::run_entity()` marks them, each gets its own pass.
 *
 * Throwaway `mc_section` posts (hierarchical), made here and deleted after:
 * parent P > child C > grandchild G, plus a `page` child of P. The source is
 * `mc_cp_text`; the row is Owning, so a child always equals its parent.
 *
 *   A  push          saving ONLY the parent updates C and G, through C's own
 *                    pass; a second edit lands the same way.
 *   B  post types    a child of a post type outside the row's `post_types` is
 *                    not named, and its value is not written.
 *   C  zero cost     with no enabled row of the type `fan_out()` names nothing
 *                    and runs no query; a disabled row is the same; the row
 *                    back, C is named again. A row with no post type checked
 *                    names a child of every type, and still writes none whose
 *                    field group is not shown (the `page`).
 *
 * Every temp post is deleted and the fixture's rules restored afterward, pass or
 * fail. Check `wp post list --post_type=mc_item --fields=ID,post_name` after
 * (untouched: this sweep only writes `mc_section` and a `page`).
 *
 * Usage:  wp eval-file .../sweep-fw5-parent-fan-out.php run
 * Prereq: mc-rules seeded (the `group_mc_copy_fields` group loads from schema.php).
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\FormatDispatcher;
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'run';
if ( 'run' !== $step ) {
	WP_CLI::error( 'Only the "run" step exists.' );
}

const MCFF_TEXT = 'field_mc_cp_text';

/** The Owning copy row over `mc_section`; `$overrides` replaces keys. */
function mcff_copy( array $overrides = array() ) {
	return array_merge(
		array(
			'type'              => 'field_from_related_post',
			'enabled'           => true,
			'name'              => 'FW-5 05 copy',
			'post_types'        => array( 'mc_section' ),
			'relationship'      => 'parent',
			'source_field'      => MCFF_TEXT,
			'copy_target'       => '',
			'conflict_handling' => 'replace',
			'post_status'       => array(),
		),
		$overrides
	);
}

function mcff_author( array $rows ) {
	mc_write_split_rules( $rows );
}

function mcff_drain() {
	TermDispatcher::instance()->drain();
}

function mcff_section( $title, $parent = 0 ) {
	$id = wp_insert_post( array(
		'post_type'   => 'mc_section',
		'post_status' => 'publish',
		'post_title'  => $title,
		'post_parent' => $parent,
	) );
	mcff_drain();
	return $id;
}

/** Edit ONLY the parent: write its field, save it, drain. */
function mcff_edit( $id, $value ) {
	update_field( MCFF_TEXT, $value, $id );
	wp_update_post( array( 'ID' => $id ) );
	mcff_drain();
}

function mcff_text( $id ) {
	clean_post_cache( $id );
	return get_post_meta( $id, 'mc_cp_text', true );
}

mc_isolate( array() );
mcff_author( array() );

$ids  = array();
$pass = true;

try {
	$p   = mcff_section( 'FW5 Fan Parent' );
	$c   = mcff_section( 'FW5 Fan Child', $p );
	$g   = mcff_section( 'FW5 Fan Grand', $c );
	$ids = array( $p, $c, $g );

	// --- A. push ------------------------------------------------------------
	mcff_author( array( mcff_copy() ) );
	mcff_edit( $p, 'P1' );
	$pass = mc_assert( 'A saving the parent updates the child', mcff_text( $c ), 'P1' ) && $pass;
	$pass = mc_assert( 'A …and the grandchild, through the child\'s own pass', mcff_text( $g ), 'P1' ) && $pass;

	mcff_edit( $p, 'P2' );
	$pass = mc_assert( 'A a second edit lands on the child', mcff_text( $c ), 'P2' ) && $pass;
	$pass = mc_assert( 'A …and on the grandchild', mcff_text( $g ), 'P2' ) && $pass;

	// --- B. post types ------------------------------------------------------
	$fd    = FormatDispatcher::instance();
	$page  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'FW5 Fan Page', 'post_parent' => $p ) );
	$ids[] = $page;
	update_post_meta( $page, 'mc_cp_text', 'page-own' );
	$pass = mc_assert( 'B a child of a type the row does not write is not named', $fd->fan_out( $p ), array( $c ) ) && $pass;
	mcff_edit( $p, 'P3' );
	$pass = mc_assert( 'B …and its value is not written', mcff_text( $page ), 'page-own' ) && $pass;
	$pass = mc_assert( 'B the section child still follows', mcff_text( $c ), 'P3' ) && $pass;

	// --- C. zero cost -------------------------------------------------------
	global $wpdb;
	foreach ( array(
		'no row'          => array(),
		'a disabled row'  => array( mcff_copy( array( 'enabled' => false ) ) ),
	) as $label => $rows ) {
		mcff_author( $rows );
		$fd->fan_out( $p ); // warm the rule read; the second call must be free
		$before = $wpdb->num_queries;
		$pass   = mc_assert( "C {$label}: fan_out() names nothing", $fd->fan_out( $p ), array() ) && $pass;
		$pass   = mc_assert( "C {$label}: …and runs no query", $wpdb->num_queries - $before, 0 ) && $pass;
	}
	// No post type checked is every type: the page child is named too, and still
	// not written, because its field group is not shown on a page.
	mcff_author( array( mcff_copy( array( 'post_types' => array() ) ) ) );
	$named = $fd->fan_out( $p );
	$want  = array( $c, $page );
	sort( $named );
	sort( $want );
	$pass = mc_assert( 'C no post type checked: a child of every type is named', $named, $want ) && $pass;
	mcff_edit( $p, 'P4' );
	$pass = mc_assert( 'C …and the page, whose group does not show the field, is not written', mcff_text( $page ), 'page-own' ) && $pass;
	$pass = mc_assert( 'C …while the section child follows', mcff_text( $c ), 'P4' ) && $pass;
	mcff_author( array( mcff_copy() ) );
	$pass = mc_assert( 'C the row back: the child is named', $fd->fan_out( $p ), array( $c ) ) && $pass;
	$pass = mc_assert( 'C …and the grandchild through the child', $fd->fan_out( $c ), array( $g ) ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW5-PARENT-FAN-OUT OK' : 'FW5-PARENT-FAN-OUT FAIL' );
exit( $pass ? 0 : 1 );
