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
 *   J  target pick   (06) an ACF source copies raw into a DIFFERENT ACF field of
 *                    the same type; a target of another type is left alone.
 *   K  plain meta    a meta source copies into a meta target; a key with several
 *                    rows copies its first value only.
 *   L  ACF-named     a meta target whose name matches an ACF field ends with
 *                    ACF's field-key reference intact.
 *   M  refusal       a `_`-prefixed meta key writes nothing, source or target.
 *   N  group mode    (07) a field group copies every member into the same group,
 *                    a repeater as one value, a member added later without
 *                    re-saving the rule; each member is its own claim slot.
 *   O  collisions    (10) a group copy collides per member; the Apply report lists
 *                    every member it writes.
 *   P  plain meta    a plain-meta target is named in the Apply report and preview.
 *   Q  ACF name      a meta name that matches an ACF field collides with a rule on it.
 *   R  empty list    Owning copies an empty list as a list.
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

use BWS\MetaConductor\Admin\CollisionDetector;
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
	// Republishing the parent now pushes to the child (05), so the parent holds
	// the value the child should keep before it comes back.
	update_field( MCFP_TEXT, 'P4', $p );
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

	// --- J. a picked ACF target ---------------------------------------------
	mcfp_author( array( array_merge( mcfp_copy( 'replace' ), array( 'copy_target' => MCFP_DISPLAY ) ) ) );
	mcfp_set( $p, MCFP_TEXT, 'J1' );
	mcfp_set( $c, MCFP_TEXT, 'own' );
	mcfp_save( $c );
	$pass = mc_assert( 'J same-type ACF target takes the parent\'s source value', get_post_meta( $c, 'mc_cp_display', true ), 'J1' ) && $pass;
	$pass = mc_assert( 'J …and the source field on the child is untouched', mcfp_text( $c ), 'own' ) && $pass;

	mcfp_author( array( array_merge( mcfp_copy( 'replace' ), array( 'copy_target' => MCFP_LIST ) ) ) );
	mcfp_set( $c, MCFP_LIST, array() );
	mcfp_save( $c );
	$pass = mc_assert( 'J a target of another field type is left alone', mcfp_list( $c ), array() ) && $pass;

	// --- K. plain meta → plain meta -----------------------------------------
	$meta = array(
		'source_field' => '@meta',
		'source_meta'  => 'mc_pm_src',
		'copy_target'  => '@meta',
		'target_meta'  => 'mc_pm_dst',
	);
	mcfp_author( array( array_merge( mcfp_copy( 'replace' ), $meta ) ) );
	add_post_meta( $p, 'mc_pm_src', 'first' );
	add_post_meta( $p, 'mc_pm_src', 'second' );
	mcfp_save( $c );
	$pass = mc_assert( 'K plain meta copies, first value only', get_post_meta( $c, 'mc_pm_dst', true ), 'first' ) && $pass;
	$pass = mc_assert( 'K …and writes a single row', count( get_post_meta( $c, 'mc_pm_dst' ) ), 1 ) && $pass;

	// --- L. a meta name that matches an ACF field ---------------------------
	mcfp_author( array( array_merge( mcfp_copy( 'replace' ), $meta, array( 'target_meta' => 'mc_cp_text' ) ) ) );
	delete_post_meta( $c, 'mc_cp_text' );
	delete_post_meta( $c, '_mc_cp_text' );
	mcfp_save( $c );
	$pass = mc_assert( 'L the value lands', mcfp_text( $c ), 'first' ) && $pass;
	$pass = mc_assert( 'L ACF\'s field-key reference is intact', get_post_meta( $c, '_mc_cp_text', true ), MCFP_TEXT ) && $pass;

	// --- M. a `_`-prefixed key is refused -----------------------------------
	add_post_meta( $p, '_mc_pm_private', 'secret' );
	mcfp_author( array( array_merge( mcfp_copy( 'replace' ), $meta, array( 'source_meta' => '_mc_pm_private', 'target_meta' => 'mc_pm_dst2' ) ) ) );
	mcfp_save( $c );
	$pass = mc_assert( 'M a _-prefixed source copies nothing', get_post_meta( $c, 'mc_pm_dst2', true ), '' ) && $pass;
	mcfp_author( array( array_merge( mcfp_copy( 'replace' ), $meta, array( 'target_meta' => '_mc_pm_written' ) ) ) );
	mcfp_save( $c );
	$pass = mc_assert( 'M a _-prefixed target writes nothing', get_post_meta( $c, '_mc_pm_written', true ), '' ) && $pass;
	delete_post_meta( $p, 'mc_pm_src' );
	delete_post_meta( $p, '_mc_pm_private' );

	// --- N. group mode (07) --------------------------------------------------
	$grp_rule = function ( $claim ) {
		return array_merge( mcfp_copy( $claim ), array( 'source_field' => 'group_mc_copy_group', 'copy_target' => '' ) );
	};
	$rows_val = array( array( 'field_mc_gp_cell' => 'r1' ), array( 'field_mc_gp_cell' => 'r2' ) );
	$gp_rows  = function ( $id ) {
		clean_post_cache( $id );
		$v = get_field( 'field_mc_gp_rows', $id, false );
		return is_array( $v ) ? array_values( array_map( function ( $r ) { return $r['field_mc_gp_cell'] ?? null; }, $v ) ) : $v;
	};
	$gp_meta = function ( $id, $name ) {
		clean_post_cache( $id );
		return get_post_meta( $id, $name, true );
	};

	mcfp_author( array( $grp_rule( 'merge' ) ) );
	mcfp_set( $p, 'field_mc_gp_one', 'g1' );
	mcfp_set( $p, 'field_mc_gp_two', 'g2' );
	mcfp_set( $p, 'field_mc_gp_rows', $rows_val );
	mcfp_save( $c );
	$pass = mc_assert( 'N every member copies', array( $gp_meta( $c, 'mc_gp_one' ), $gp_meta( $c, 'mc_gp_two' ) ), array( 'g1', 'g2' ) ) && $pass;
	$pass = mc_assert( 'N a repeater member copies as one value', $gp_rows( $c ), array( 'r1', 'r2' ) ) && $pass;

	// A member added after the rule was saved is copied on the next pass.
	acf_add_local_field( array(
		'key'    => 'field_mc_gp_late',
		'name'   => 'mc_gp_late',
		'label'  => 'GP Late',
		'type'   => 'text',
		'parent' => 'group_mc_copy_group',
	) );
	mcfp_set( $p, 'field_mc_gp_late', 'late' );
	mcfp_save( $c );
	$pass = mc_assert( 'N a member added later is copied without re-saving the rule', $gp_meta( $c, 'mc_gp_late' ), 'late' ) && $pass;

	// Contributing: an empty member is left alone.
	mcfp_set( $p, 'field_mc_gp_two', '' );
	mcfp_save( $c );
	$pass = mc_assert( 'N Contributing leaves an empty member\'s target alone', $gp_meta( $c, 'mc_gp_two' ), 'g2' ) && $pass;
	$pass = mc_assert( 'N …and the other members still copy', $gp_meta( $c, 'mc_gp_one' ), 'g1' ) && $pass;

	// Owning: an empty member clears only that member.
	mcfp_author( array( $grp_rule( 'replace' ) ) );
	mcfp_save( $c );
	$pass = mc_assert( 'N Owning clears the empty member', $gp_meta( $c, 'mc_gp_two' ), '' ) && $pass;
	$pass = mc_assert( 'N …and only that one', array( $gp_meta( $c, 'mc_gp_one' ), $gp_meta( $c, 'mc_gp_late' ) ), array( 'g1', 'late' ) ) && $pass;
	$pass = mc_assert( 'N …the repeater stays', $gp_rows( $c ), array( 'r1', 'r2' ) ) && $pass;

	// --- O. collisions and the Apply report on a group (10) -----------------
	$on_member = array_merge( mcfp_pattern(), array( 'target_field' => 'field_mc_gp_one' ) );
	$found     = CollisionDetector::detect( OptionRuleStorage::KIND_FORMAT, OptionRuleStorage::project_kind_rules( array( $grp_rule( 'merge' ), $on_member ) ) );
	$pass      = mc_assert( 'O a group copy collides with a rule on one member, once', count( $found ), 1 ) && $pass;
	$pass      = mc_assert( 'O the finding names the member', str_contains( CollisionDetector::message( $found[0] ), 'GP One' ), true ) && $pass;
	$soft      = CollisionDetector::detect( OptionRuleStorage::KIND_FORMAT, OptionRuleStorage::project_kind_rules( array( $on_member, $grp_rule( 'skip' ) ) ) );
	$pass      = mc_assert( 'O a Deferring group copy collides at the lower severity', $soft[0]['severity'] ?? '', 'low' ) && $pass;
	$other     = array_merge( mcfp_pattern(), array( 'target_field' => MCFP_DISPLAY ) );
	$pass      = mc_assert( 'O a rule outside the group does not collide', CollisionDetector::detect( OptionRuleStorage::KIND_FORMAT, OptionRuleStorage::project_kind_rules( array( $grp_rule( 'merge' ), $other ) ) ), array() ) && $pass;

	mcfp_author( array( $grp_rule( 'merge' ) ) );
	$names = ( new ReflectionMethod( ExistingPostsApplier::class, 'field_names' ) )->invoke( null, array( 'all' => true, 'kind' => FormatDispatcher::KIND, 'fingerprint' => '' ) );
	$pass  = mc_assert( 'O the Apply report lists every member a group copy writes', $names, array( 'mc_gp_one', 'mc_gp_two', 'mc_gp_rows', 'mc_gp_late' ) ) && $pass;

	// --- P. a plain-meta target shows in the Apply report -------------------
	mcfp_author( array( array_merge( mcfp_copy( 'replace' ), $meta ) ) );
	$names = ( new ReflectionMethod( ExistingPostsApplier::class, 'field_names' ) )->invoke( null, array( 'all' => true, 'kind' => FormatDispatcher::KIND, 'fingerprint' => '' ) );
	$pass  = mc_assert( 'P the Apply report lists a plain-meta target by its name', $names, array( 'mc_pm_dst' ) ) && $pass;

	add_post_meta( $p, 'mc_pm_src', 'report-me' );
	delete_post_meta( $c, 'mc_pm_dst' );
	$choice  = RuleChoice::encode( OptionRuleStorage::KIND_FORMAT, 0, StorageFactory::get_instance()->get_kind_rules( OptionRuleStorage::KIND_FORMAT )[0] );
	$preview = ExistingPostsApplier::preview( $choice );
	$shown   = null;
	foreach ( (array) ( $preview['sample'] ?? array() ) as $entry ) {
		if ( (int) $entry['post_id'] === (int) $c ) {
			$shown = $entry['fields']['mc_pm_dst'] ?? null;
		}
	}
	$pass = mc_assert( 'P the preview shows the plain-meta copy, before and after', $shown, array( '', 'report-me' ) ) && $pass;
	delete_post_meta( $p, 'mc_pm_src' );

	// --- Q. a meta name that matches an ACF field collides with a rule on it -
	$to_acf_name = array_merge( mcfp_copy( 'replace' ), $meta, array( 'target_meta' => 'mc_cp_display' ) );
	$found       = CollisionDetector::detect( OptionRuleStorage::KIND_FORMAT, OptionRuleStorage::project_kind_rules( array( $to_acf_name, mcfp_pattern() ) ) );
	$pass        = mc_assert( 'Q a plain-meta copy into an ACF field\'s name collides with a rule on that field', count( $found ), 1 ) && $pass;
	$plain_name  = array_merge( $to_acf_name, array( 'target_meta' => 'mc_pm_no_such_field' ) );
	$pass        = mc_assert( 'Q a name no ACF field answers to does not', CollisionDetector::detect( OptionRuleStorage::KIND_FORMAT, OptionRuleStorage::project_kind_rules( array( $plain_name, mcfp_pattern() ) ) ), array() ) && $pass;

	// --- R. Owning copies an empty list as a list (the source's value) -------
	mcfp_author( array( mcfp_copy( 'replace', MCFP_LIST ) ) );
	mcfp_set( $p, MCFP_LIST, $list );
	mcfp_save( $c );
	$pass = mc_assert( 'R the child holds the parent\'s list', mcfp_list( $c ), $list ) && $pass;
	mcfp_set( $p, MCFP_LIST, array() );
	mcfp_save( $c );
	$pass = mc_assert( 'R an empty parent list leaves the child an empty list, not a string', mcfp_list( $c ), array() ) && $pass;
} finally {
	acf_remove_local_field( 'field_mc_gp_late' );
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW5-FIELD-FROM-PARENT OK' : 'FW5-FIELD-FROM-PARENT FAIL' );
exit( $pass ? 0 : 1 );
