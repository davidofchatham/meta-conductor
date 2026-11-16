<?php
/**
 * mc-rules — FW-5 ticket 04 end-to-end: *Set a field from a related post*
 * (`field_from_related_post`) over the PARENT relationship, driven by real saves
 * and the format dispatcher's drain.
 *
 * Posts are throwaway `mc_section` rows (hierarchical, so a child has a parent),
 * made here and deleted after; the fixture's own posts are never written. The
 * source is `mc_cp_text` (text) and `mc_cp_list` (relationship, an array-valued
 * field); `mc_cp_display` is the target of a later pattern rule. One rule list
 * is authored per step through sweep-lib's `mc_write_ordered_rules()`.
 *
 *   A  Contributing  a child takes the parent's non-empty value, overwrites its
 *                    own, and is NEVER cleared by an empty parent.
 *   B  Owning        the child equals the parent's value, including empty.
 *   C  Deferring     fills only an empty child; an empty parent writes nothing.
 *   D  status gate   a parent outside the row's `post_status` is absent: the
 *                    child keeps its value even under Owning.
 *   E  no parent     a root post keeps its value; a child detached to root keeps
 *                    the value it had.
 *   F  array value   a relationship list copies intact; the Apply preview shows
 *                    the array as JSON rather than the word "Array".
 *   G  composition   a field-from-pattern rule ordered AFTER the copy reads the
 *                    copied value in the same pass.
 *   H  bulk          Apply to Existing Posts backfills children that existed
 *                    before the rule.
 *   I  Same          a post type whose field groups do not show the field is
 *                    left alone.
 *
 * Every temp post is deleted and the fixture's rules restored afterward, pass or
 * fail. Check `wp post list --post_type=mc_item --fields=ID,post_name` after
 * (untouched: this sweep only writes `mc_section` posts).
 *
 * Usage:  wp eval-file .../sweep-fw5-field-from-parent.php run
 * Prereq: mc-rules seeded (the `group_mc_copy_fields` group loads from schema.php).
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\ExistingPostsApplier;
use BWS\MetaConductor\Core\RuleChoice;
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

$step = $args[0] ?? 'run';
if ( 'run' !== $step ) {
	WP_CLI::error( 'Only the "run" step exists.' );
}

const MCFP_TEXT    = 'field_mc_cp_text';
const MCFP_DISPLAY = 'field_mc_cp_display';
const MCFP_LIST    = 'field_mc_cp_list';

/** The copy row for one claim (`replace` Owning, `merge` Contributing, `skip` Deferring). */
function mcfp_copy( $claim, $source = MCFP_TEXT, array $statuses = array() ) {
	return array(
		'type'              => 'field_from_related_post',
		'enabled'           => true,
		'name'              => 'FW-5 04 copy',
		'post_types'        => array( 'mc_section' ),
		'relationship'      => 'parent',
		'source_field'      => $source,
		'copy_target'       => '',
		'conflict_handling' => $claim,
		'post_status'       => $statuses,
	);
}

/** A pattern rule writing `mc_cp_display` from `mc_cp_text`. */
function mcfp_pattern() {
	return array(
		'type'         => 'field_from_pattern',
		'enabled'      => true,
		'name'         => 'FW-5 04 display',
		'post_types'   => array( 'mc_section' ),
		'target_field' => MCFP_DISPLAY,
		'pattern'      => '{meta:mc_cp_text}',
		'output'       => 'text',
	);
}

function mcfp_author( array $rows ) {
	mc_write_ordered_rules( $rows, OptionRuleStorage::KIND_FORMAT );
}

function mcfp_drain() {
	TermDispatcher::instance()->drain();
}

function mcfp_section( $title, $parent = 0, $status = 'publish' ) {
	$id = wp_insert_post( array(
		'post_type'   => 'mc_section',
		'post_status' => $status,
		'post_title'  => $title,
		'post_parent' => $parent,
	) );
	mcfp_drain();
	return $id;
}

/** Write a field by KEY (what ACF's editor does), then let the pass run. */
function mcfp_set( $id, $key, $value ) {
	update_field( $key, $value, $id );
	mcfp_drain();
}

/** A save with no change to the post, then the pass. */
function mcfp_save( $id ) {
	wp_update_post( array( 'ID' => $id ) );
	mcfp_drain();
}

function mcfp_text( $id ) {
	clean_post_cache( $id );
	return get_post_meta( $id, 'mc_cp_text', true );
}

/** Raw list as stored, IDs as ints. */
function mcfp_list( $id ) {
	$value = get_field( MCFP_LIST, $id, false );
	return is_array( $value ) ? array_map( 'intval', $value ) : $value;
}

mc_isolate( array() );
mcfp_author( array() );

$ids  = array();
$pass = true;

try {
	$p = mcfp_section( 'FW5 Parent' );
	mcfp_set( $p, MCFP_TEXT, 'P1' );
	$c = mcfp_section( 'FW5 Child', $p );
	$ids = array( $p, $c );

	// --- A. Contributing ----------------------------------------------------
	mcfp_author( array( mcfp_copy( 'merge' ) ) );
	mcfp_save( $c );
	$pass = mc_assert( 'A child takes the parent\'s value', mcfp_text( $c ), 'P1' ) && $pass;
	$pass = mc_assert( 'A …by field key (reference row intact)', get_post_meta( $c, '_mc_cp_text', true ), MCFP_TEXT ) && $pass;

	mcfp_set( $c, MCFP_TEXT, 'mine' );
	mcfp_save( $c );
	$pass = mc_assert( 'A Contributing overwrites the child\'s own value', mcfp_text( $c ), 'P1' ) && $pass;

	mcfp_set( $p, MCFP_TEXT, '' );
	mcfp_save( $c );
	$pass = mc_assert( 'A an empty parent never clears the child', mcfp_text( $c ), 'P1' ) && $pass;

	// --- B. Owning ----------------------------------------------------------
	mcfp_author( array( mcfp_copy( 'replace' ) ) );
	mcfp_set( $p, MCFP_TEXT, 'P2' );
	mcfp_save( $c );
	$pass = mc_assert( 'B child equals the parent', mcfp_text( $c ), 'P2' ) && $pass;

	mcfp_set( $p, MCFP_TEXT, '' );
	mcfp_save( $c );
	$pass = mc_assert( 'B an empty parent value empties the child', mcfp_text( $c ), '' ) && $pass;

	// --- C. Deferring -------------------------------------------------------
	mcfp_author( array( mcfp_copy( 'skip' ) ) );
	mcfp_set( $p, MCFP_TEXT, 'P3' );
	mcfp_set( $c, MCFP_TEXT, 'mine' );
	mcfp_save( $c );
	$pass = mc_assert( 'C a filled child is not overwritten', mcfp_text( $c ), 'mine' ) && $pass;

	mcfp_set( $c, MCFP_TEXT, '' );
	mcfp_save( $c );
	$pass = mc_assert( 'C an empty child is filled', mcfp_text( $c ), 'P3' ) && $pass;

	mcfp_set( $p, MCFP_TEXT, '' );
	mcfp_set( $c, MCFP_TEXT, '' );
	mcfp_save( $c );
	$pass = mc_assert( 'C an empty parent writes nothing', mcfp_text( $c ), '' ) && $pass;

	// --- D. status gate -----------------------------------------------------
	mcfp_author( array( mcfp_copy( 'replace', MCFP_TEXT, array( 'publish' ) ) ) );
	mcfp_set( $p, MCFP_TEXT, 'P4' );
	mcfp_save( $c );
	$pass = mc_assert( 'D a published parent is copied', mcfp_text( $c ), 'P4' ) && $pass;

	wp_update_post( array( 'ID' => $p, 'post_status' => 'draft' ) );
	mcfp_drain();
	mcfp_set( $p, MCFP_TEXT, '' );
	mcfp_save( $c );
	$pass = mc_assert( 'D a draft parent is absent: the child keeps its value under Owning', mcfp_text( $c ), 'P4' ) && $pass;
	wp_update_post( array( 'ID' => $p, 'post_status' => 'publish' ) );
	mcfp_drain();

	// --- E. no parent -------------------------------------------------------
	$root = mcfp_section( 'FW5 Root' );
	$ids[] = $root;
	mcfp_set( $root, MCFP_TEXT, 'root' );
	mcfp_save( $root );
	$pass = mc_assert( 'E a root post keeps its value under Owning', mcfp_text( $root ), 'root' ) && $pass;

	wp_update_post( array( 'ID' => $c, 'post_parent' => 0 ) );
	mcfp_drain();
	$pass = mc_assert( 'E a child detached to root keeps the value it had', mcfp_text( $c ), 'P4' ) && $pass;
	wp_update_post( array( 'ID' => $c, 'post_parent' => $p ) );
	mcfp_drain();

	// --- F. array value -----------------------------------------------------
	$a    = mc_pid_strict( 'item-alpha' );
	$b    = mc_pid_strict( 'item-beta' );
	$list = array( $a, $b );
	mcfp_author( array( mcfp_copy( 'merge', MCFP_LIST ) ) );
	mcfp_set( $p, MCFP_LIST, $list );
	mcfp_save( $c );
	$pass = mc_assert( 'F a relationship list copies intact', mcfp_list( $c ), $list ) && $pass;

	mcfp_set( $c, MCFP_LIST, array() );
	$choice  = RuleChoice::encode( OptionRuleStorage::KIND_FORMAT, 0, StorageFactory::get_instance()->get_kind_rules( OptionRuleStorage::KIND_FORMAT )[0] );
	$preview = ExistingPostsApplier::preview( $choice );
	$shown   = null;
	foreach ( (array) ( $preview['sample'] ?? array() ) as $entry ) {
		if ( (int) $entry['post_id'] === (int) $c ) {
			$shown = $entry['fields']['mc_cp_list'][1] ?? null;
		}
	}
	$pass = mc_assert( 'F the preview shows the array as JSON, not "Array"', is_string( $shown ) && str_starts_with( $shown, '[' ) && false === strpos( $shown, 'Array' ), true ) && $pass;
	mcfp_save( $c );
	$pass = mc_assert( 'F the list is back after the pass', mcfp_list( $c ), $list ) && $pass;

	// --- G. composition -----------------------------------------------------
	mcfp_author( array( mcfp_copy( 'replace' ), mcfp_pattern() ) );
	mcfp_set( $p, MCFP_TEXT, 'P7' );
	mcfp_set( $c, MCFP_TEXT, 'stale' );
	mcfp_save( $c );
	$pass = mc_assert( 'G the copy lands', mcfp_text( $c ), 'P7' ) && $pass;
	$pass = mc_assert( 'G a pattern rule after it reads the copied value in the same pass', get_post_meta( $c, 'mc_cp_display', true ), 'P7' ) && $pass;

	// --- H. bulk ------------------------------------------------------------
	mcfp_author( array() );
	$c2 = mcfp_section( 'FW5 Child Two', $p );
	$c3 = mcfp_section( 'FW5 Child Three', $p );
	array_push( $ids, $c2, $c3 );
	mcfp_set( $p, MCFP_TEXT, 'P8' );
	$pass = mc_assert( 'H before the rule the children are empty', array( mcfp_text( $c2 ), mcfp_text( $c3 ) ), array( '', '' ) ) && $pass;

	mcfp_author( array( mcfp_copy( 'merge' ) ) );
	$rows   = StorageFactory::get_instance()->get_kind_rules( OptionRuleStorage::KIND_FORMAT );
	$result = ExistingPostsApplier::run_batch( RuleChoice::encode( OptionRuleStorage::KIND_FORMAT, 0, $rows[0] ) );
	WP_CLI::log( '[sweep] bulk: ' . wp_json_encode( array_intersect_key( $result, array_flip( array( 'status', 'processed', 'total', 'changed' ) ) ) ) );
	mcfp_drain();
	$pass = mc_assert( 'H Apply to Existing Posts backfills the children', array( mcfp_text( $c2 ), mcfp_text( $c3 ) ), array( 'P8', 'P8' ) ) && $pass;

	// --- I. Same is offered only where the field's group is shown -----------
	// `mc_cp_text`'s group is located on mc_section, so a rule over `page` has no
	// such field on the receiving type and writes nothing.
	$page_rule               = mcfp_copy( 'merge' );
	$page_rule['post_types'] = array( 'page' );
	mcfp_author( array( $page_rule ) );
	$pp    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'FW5 Page Parent' ) );
	$pc    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'FW5 Page Child', 'post_parent' => $pp ) );
	array_push( $ids, $pp, $pc );
	update_post_meta( $pp, 'mc_cp_text', 'page-parent' );
	mcfp_save( $pc );
	$pass = mc_assert( 'I a post type that does not show the field is left alone', get_post_meta( $pc, 'mc_cp_text', true ), '' ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW5-FIELD-FROM-PARENT OK' : 'FW5-FIELD-FROM-PARENT FAIL' );
exit( $pass ? 0 : 1 );
