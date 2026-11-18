<?php
/**
 * mc-rules — FW-5 ticket 09: the edges of the ACF relationship for *Set a field
 * from a related post* — removals, deletion, a bidirectional same-type cycle.
 *
 * One step per invocation, because the handler memoizes the enabled `acf` rows
 * for the capture path for the life of a request: a step that changed the rules
 * after its first capture would assert against stale rows.
 *
 *   sever-replace | sever-merge | sever-skip
 *                  push: a section drops an item from its list. Owning clears
 *                  the item's copy; Contributing and Deferring keep it.
 *   delete         deleting the only source clears an Owning receiver.
 *   other-row      a sever on one row does not let a different row clear.
 *   cycle          two same-type posts sourcing each other terminate.
 *
 * Every temp post is deleted and the fixture's rules restored afterward, pass or
 * fail. Check `wp post list --post_type=mc_item --fields=ID,post_name` after.
 *
 * Usage:  wp eval-file .../sweep-fw5-field-from-acf-sever.php <step>
 * Prereq: mc-rules seeded (v16+).
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? '';

const MCFS_TEXT    = 'field_mc_cp_text';
const MCFS_DISPLAY = 'field_mc_cf_display';
const MCFS_FORWARD = 'field_mc_related_items';
const MCFS_REVERSE = 'field_mc_parent_section';
const MCFS_PEER    = 'field_mc_peer_sections';

/** Push row: the section holds the field, its items receive. No reverse field, so tier 3 reads the live forward lists. */
function mcfs_push( $claim ) {
	return array(
		'type'              => 'field_from_related_post',
		'enabled'           => true,
		'name'              => 'FW-5 09 push',
		'post_types'        => array( 'mc_item' ),
		'relationship'      => 'acf',
		'acf_field_name'    => 'mc_section:mc_related_items:' . MCFS_FORWARD,
		'holder_role'       => 'source',
		'source_field'      => MCFS_TEXT,
		'copy_target'       => MCFS_DISPLAY,
		'conflict_handling' => $claim,
	);
}

function mcfs_drain() {
	TermDispatcher::instance()->drain();
}

function mcfs_post( $type, $title ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ) );
	mcfs_drain();
	return $id;
}

function mcfs_set( $id, $key, $value ) {
	update_field( $key, $value, $id );
	mcfs_drain();
}

/** A bare update_field() reaches a pass only at shutdown; a save of the source gets it there now. */
function mcfs_save( $id ) {
	wp_update_post( array( 'ID' => $id ) );
	mcfs_drain();
}

function mcfs_shown( $item ) {
	clean_post_cache( $item );
	return get_post_meta( $item, 'mc_cf_display', true );
}

mc_isolate( array() );
mc_write_split_rules( array() );

$ids  = array();
$pass = true;

try {
	switch ( $step ) {
		case 'sever-replace':
		case 'sever-merge':
		case 'sever-skip':
			$claim = array( 'sever-replace' => 'replace', 'sever-merge' => 'merge', 'sever-skip' => 'skip' )[ $step ];
			mc_write_split_rules( array( mcfs_push( $claim ) ) );
			$s    = mcfs_post( 'mc_section', 'FW5 sever S' );
			$item = mcfs_post( 'mc_item', 'FW5 sever Item' );
			$ids  = array( $s, $item );
			mcfs_set( $s, MCFS_TEXT, 'one' );
			mcfs_set( $s, MCFS_FORWARD, array( $item ) );
			mcfs_save( $s );
			$pass = mc_assert( "$claim: the item received the copy", mcfs_shown( $item ), 'one' ) && $pass;
			mcfs_set( $s, MCFS_FORWARD, array() );
			$pass = mc_assert( "$claim: after the item is dropped from the list", mcfs_shown( $item ), 'replace' === $claim ? '' : 'one' ) && $pass;
			break;

		case 'delete':
			mc_write_split_rules( array( mcfs_push( 'replace' ) ) );
			$s    = mcfs_post( 'mc_section', 'FW5 delete S' );
			$item = mcfs_post( 'mc_item', 'FW5 delete Item' );
			$ids  = array( $item );
			mcfs_set( $s, MCFS_TEXT, 'one' );
			mcfs_set( $s, MCFS_FORWARD, array( $item ) );
			mcfs_save( $s );
			$pass = mc_assert( 'delete: the item received the copy', mcfs_shown( $item ), 'one' ) && $pass;
			wp_delete_post( $s, true );
			mcfs_drain();
			$pass = mc_assert( 'delete: the only source is gone, Owning clears', mcfs_shown( $item ), '' ) && $pass;
			break;

		case 'other-row':
			// Row A (push, severed). Row B (pull on the item's own field, no
			// source left in its list, so it manages nothing): B must not clear.
			$b                   = mcfs_push( 'replace' );
			$b['name']           = 'FW-5 09 other';
			$b['acf_field_name'] = 'mc_item:mc_parent_section:' . MCFS_REVERSE;
			$b['holder_role']    = 'target';
			$b['copy_target']    = '@meta';
			$b['target_meta']    = 'mc_fw5_other';
			mc_write_split_rules( array( mcfs_push( 'replace' ), $b ) );
			$s    = mcfs_post( 'mc_section', 'FW5 other S' );
			$item = mcfs_post( 'mc_item', 'FW5 other Item' );
			$ids  = array( $s, $item );
			mcfs_set( $s, MCFS_TEXT, 'one' );
			mcfs_set( $s, MCFS_FORWARD, array( $item ) );
			mcfs_save( $s );
			update_post_meta( $item, 'mc_fw5_other', 'keep' );
			mcfs_set( $s, MCFS_FORWARD, array() );
			$pass = mc_assert( 'other-row: the severed row clears its copy', mcfs_shown( $item ), '' ) && $pass;
			$pass = mc_assert( 'other-row: the other row keeps its value', get_post_meta( $item, 'mc_fw5_other', true ), 'keep' ) && $pass;
			break;

		case 'cycle':
			$row = array(
				'type'              => 'field_from_related_post',
				'enabled'           => true,
				'name'              => 'FW-5 09 cycle',
				'post_types'        => array( 'mc_section' ),
				'relationship'      => 'acf',
				'acf_field_name'    => 'mc_section:mc_peer_sections:' . MCFS_PEER,
				'holder_role'       => 'source',
				'source_field'      => MCFS_TEXT,
				'copy_target'       => '@meta',
				'target_meta'       => 'mc_fw5_cycle',
				'conflict_handling' => 'replace',
			);
			mc_write_split_rules( array( $row ) );
			$a   = mcfs_post( 'mc_section', 'FW5 cycle A' );
			$b2  = mcfs_post( 'mc_section', 'FW5 cycle B' );
			$ids = array( $a, $b2 );
			mcfs_set( $a, MCFS_TEXT, 'one' );
			mcfs_set( $b2, MCFS_TEXT, 'two' );
			$start = microtime( true );
			mcfs_set( $a, MCFS_PEER, array( $b2 ) ); // ACF writes the other end too.
			mcfs_save( $a );
			$pass = mc_assert( 'cycle: the drain returned', microtime( true ) - $start < 30, true ) && $pass;
			$pass = mc_assert( 'cycle: each end holds the other value', array( get_post_meta( $a, 'mc_fw5_cycle', true ), get_post_meta( $b2, 'mc_fw5_cycle', true ) ), array( 'one', 'two' ) ) && $pass;
			break;

		default:
			WP_CLI::error( 'Steps: sever-replace, sever-merge, sever-skip, delete, other-row, cycle.' );
	}
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}

WP_CLI::log( $pass ? "FW5-SEVER $step OK" : "FW5-SEVER $step FAIL" );
exit( $pass ? 0 : 1 );
