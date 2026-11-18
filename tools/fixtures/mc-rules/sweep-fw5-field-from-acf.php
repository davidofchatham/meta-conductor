<?php
/**
 * mc-rules — FW-5 ticket 08 end-to-end: *Set a field from a related post*
 * (`field_from_related_post`) over the ACF relationship, pull and push, driven by
 * real saves and the format dispatcher's drain.
 *
 * Posts are throwaway `mc_section` (the field holder of `mc_related_items`) and
 * `mc_item` (holder of the reverse field `mc_parent_section`) rows, made here and
 * deleted after. The source is `mc_cp_text` on the section; it is copied into
 * `mc_cf_display` on the item (both text). Both link fields are kept consistent by
 * hand (neither is ACF-bidirectional), so what the sweep exercises is the
 * plugin's own explicit-reverse path (tier 1) and its tier-3 fallback.
 *
 *   A  pull, order   an item lists two sections; the first holding a value wins,
 *                    in the item's own field order; reordering changes the winner;
 *                    an empty first section is passed over.
 *   B  push, tier 1  two sections list one item; the winner follows the item's
 *                    reverse field order.
 *   C  push, tier 3  no reverse field: the lowest post ID wins.
 *   D  status gate   a draft source is absent; all-draft sources leave the item
 *                    alone, under every claim.
 *   E  source edit   editing a section updates its items without saving them, in
 *                    both directions.
 *
 * Every temp post is deleted and the fixture's rules restored afterward, pass or
 * fail. Check `wp post list --post_type=mc_item --fields=ID,post_name` after.
 *
 * Usage:  wp eval-file .../sweep-fw5-field-from-acf.php run
 * Prereq: mc-rules seeded.
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'run';
if ( 'run' !== $step ) {
	WP_CLI::error( 'Only the "run" step exists.' );
}

const MCFA_TEXT    = 'field_mc_cp_text';
const MCFA_DISPLAY = 'field_mc_cf_display';
const MCFA_FORWARD = 'field_mc_related_items';
const MCFA_REVERSE = 'field_mc_parent_section';

/**
 * The copy row. `$pull` = the item holds the field and reads its sections; push =
 * the section holds the field and its listed items receive.
 */
function mcfa_row( $claim, $pull, $tier1 = true, array $statuses = array() ) {
	return array(
		'type'                   => 'field_from_related_post',
		'enabled'                => true,
		'name'                   => 'FW-5 08 copy',
		'post_types'             => array( 'mc_item' ),
		'relationship'           => 'acf',
		'acf_field_name'         => $pull ? 'mc_item:mc_parent_section:' . MCFA_REVERSE : 'mc_section:mc_related_items:' . MCFA_FORWARD,
		'holder_role'            => $pull ? 'target' : 'source',
		'reverse_acf_field_name' => $tier1 ? ( $pull ? 'mc_section:mc_related_items:' . MCFA_FORWARD : 'mc_item:mc_parent_section:' . MCFA_REVERSE ) : '',
		'source_field'           => MCFA_TEXT,
		'copy_target'            => MCFA_DISPLAY,
		'conflict_handling'      => $claim,
		'post_status'            => $statuses,
	);
}

function mcfa_author( array $rows ) {
	mc_write_split_rules( $rows );
}

function mcfa_drain() {
	TermDispatcher::instance()->drain();
}

function mcfa_post( $type, $title, $status = 'publish' ) {
	$id = wp_insert_post( array(
		'post_type'   => $type,
		'post_status' => $status,
		'post_title'  => $title,
	) );
	mcfa_drain();
	return $id;
}

function mcfa_set( $id, $key, $value ) {
	update_field( $key, $value, $id );
	mcfa_drain();
}

function mcfa_save( $id ) {
	wp_update_post( array( 'ID' => $id ) );
	mcfa_drain();
}

function mcfa_shown( $item ) {
	clean_post_cache( $item );
	return get_post_meta( $item, 'mc_cf_display', true );
}

mc_isolate( array() );
mcfa_author( array() );

$ids  = array();
$pass = true;

try {
	$s1   = mcfa_post( 'mc_section', 'FW5 ACF S1' );
	$s2   = mcfa_post( 'mc_section', 'FW5 ACF S2' );
	$item = mcfa_post( 'mc_item', 'FW5 ACF Item' );
	$ids  = array( $s1, $s2, $item );
	mcfa_set( $s1, MCFA_TEXT, 'one' );
	mcfa_set( $s2, MCFA_TEXT, 'two' );

	// --- A. pull, field order ----------------------------------------------
	mcfa_author( array( mcfa_row( 'replace', true ) ) );
	mcfa_set( $s1, MCFA_FORWARD, array( $item ) );
	mcfa_set( $s2, MCFA_FORWARD, array( $item ) );
	mcfa_set( $item, MCFA_REVERSE, array( $s1, $s2 ) );
	mcfa_save( $item );
	$pass = mc_assert( 'A the first listed section wins', mcfa_shown( $item ), 'one' ) && $pass;

	mcfa_set( $item, MCFA_REVERSE, array( $s2, $s1 ) );
	mcfa_save( $item );
	$pass = mc_assert( 'A reordering the field changes the winner', mcfa_shown( $item ), 'two' ) && $pass;

	mcfa_set( $s2, MCFA_TEXT, '' );
	mcfa_save( $item );
	$pass = mc_assert( 'A an empty first source is passed over for one holding a value', mcfa_shown( $item ), 'one' ) && $pass;
	mcfa_set( $s2, MCFA_TEXT, 'two' );

	// --- B. push, reverse field order (tier 1) -------------------------------
	mcfa_author( array( mcfa_row( 'replace', false ) ) );
	mcfa_set( $item, MCFA_REVERSE, array( $s2, $s1 ) );
	mcfa_save( $item );
	$pass = mc_assert( 'B the reverse field\'s order picks the winner', mcfa_shown( $item ), 'two' ) && $pass;
	mcfa_set( $item, MCFA_REVERSE, array( $s1, $s2 ) );
	mcfa_save( $item );
	$pass = mc_assert( 'B …and changes with it', mcfa_shown( $item ), 'one' ) && $pass;

	// --- C. push, tier 3: lowest post ID --------------------------------------
	mcfa_author( array( mcfa_row( 'replace', false, false ) ) );
	mcfa_set( $item, MCFA_REVERSE, array( $s2, $s1 ) );
	mcfa_save( $item );
	$low  = min( $s1, $s2 );
	$pass = mc_assert( 'C with no reverse field the lowest post ID wins', mcfa_shown( $item ), $low === $s1 ? 'one' : 'two' ) && $pass;

	// --- D. status gate ---------------------------------------------------------
	mcfa_author( array( mcfa_row( 'replace', false, true, array( 'publish' ) ) ) );
	mcfa_set( $item, MCFA_REVERSE, array( $s1, $s2 ) );
	wp_update_post( array( 'ID' => $s1, 'post_status' => 'draft' ) );
	mcfa_drain();
	mcfa_save( $item );
	$pass = mc_assert( 'D a draft source is absent: the next one wins', mcfa_shown( $item ), 'two' ) && $pass;

	wp_update_post( array( 'ID' => $s2, 'post_status' => 'draft' ) );
	mcfa_drain();
	foreach ( array( 'replace', 'merge', 'skip' ) as $claim ) {
		mcfa_author( array( mcfa_row( $claim, false, true, array( 'publish' ) ) ) );
		update_field( MCFA_DISPLAY, 'keep', $item );
		mcfa_save( $item );
		$pass = mc_assert( "D all sources draft: the item is left alone ($claim)", mcfa_shown( $item ), 'keep' ) && $pass;
	}
	wp_update_post( array( 'ID' => $s1, 'post_status' => 'publish' ) );
	wp_update_post( array( 'ID' => $s2, 'post_status' => 'publish' ) );
	mcfa_drain();

	// --- E. a source edit reaches the item without saving it ---------------------
	mcfa_author( array( mcfa_row( 'replace', false ) ) );
	mcfa_set( $item, MCFA_REVERSE, array( $s1 ) );
	mcfa_set( $s2, MCFA_FORWARD, array() );
	mcfa_save( $item );
	update_field( MCFA_TEXT, 'edited-push', $s1 );
	mcfa_save( $s1 ); // a source edit is a save of the source; the item is never saved.
	$pass = mc_assert( 'E push: editing the section updates its item', mcfa_shown( $item ), 'edited-push' ) && $pass;

	mcfa_author( array( mcfa_row( 'replace', true ) ) );
	mcfa_save( $item );
	update_field( MCFA_TEXT, 'edited-pull', $s1 );
	mcfa_save( $s1 );
	$pass = mc_assert( 'E pull: editing the section updates the item holding the field', mcfa_shown( $item ), 'edited-pull' ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW5-FIELD-FROM-ACF OK' : 'FW5-FIELD-FROM-ACF FAIL' );
exit( $pass ? 0 : 1 );
