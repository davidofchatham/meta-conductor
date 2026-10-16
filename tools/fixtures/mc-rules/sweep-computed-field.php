<?php
/**
 * mc-rules — Computed field rule behavior sweep (FW-4, post-level).
 *
 * Proves on a real WP + ACF what the static gates cannot: that a Computed field
 * row, run by the format dispatcher, stores a pattern's output in an ACF field
 * BY KEY, writes nothing when nothing changed, clears the field when every
 * source is empty, and leaves every other post type alone.
 *
 * SUBJECT: `item-computed` (mc_item). Sources `mc_cf_first` / `mc_cf_last`;
 * targets `mc_cf_display` (text) and `mc_cf_display_html` (wysiwyg). The
 * outside-post-type control is `section-holder` (mc_section), which is given a
 * meta key of the SAME NAME as a source so a rule that ignored its post type
 * would visibly write to it.
 *
 * The rule under test is authored here, not read from the manifest baseline,
 * so every step starts from a known single-rule list.
 *
 * Usage (one step per eval):
 *   wp eval-file .../sweep-computed-field.php text       # text write; field-key reference intact
 *   wp eval-file .../sweep-computed-field.php verbatim   # text mode stores &, quotes, backslashes as typed
 *   wp eval-file .../sweep-computed-field.php html       # html write: literals kept, token values escaped
 *   wp eval-file .../sweep-computed-field.php unchanged  # a second pass writes nothing
 *   wp eval-file .../sweep-computed-field.php empty      # every source empty clears the field to ''
 *   wp eval-file .../sweep-computed-field.php disabled   # a disabled rule writes nothing
 *   wp eval-file .../sweep-computed-field.php other      # a post outside the rule's post types is untouched
 *   wp eval-file .../sweep-computed-field.php multi-type # one rule naming two post types writes both, and only those
 *   wp eval-file .../sweep-computed-field.php no-types   # a rule with no post type checked writes nothing anywhere
 *   wp eval-file .../sweep-computed-field.php save       # an editor-shaped save computes the field
 *   wp eval-file .../sweep-computed-field.php s1         # bare update_field(), let shutdown drain
 *   wp eval-file .../sweep-computed-field.php s2         # assert what s1's shutdown did
 *   wp eval-file .../sweep-computed-field.php two-fields         # different fields both written (ticket 04)
 *   wp eval-file .../sweep-computed-field.php same-field         # same field: later wins; swap swaps
 *   wp eval-file .../sweep-computed-field.php title-after        # title rule after a field rule reads the pending value
 *   wp eval-file .../sweep-computed-field.php title-before       # title rule before it reads the stored value
 *   wp eval-file .../sweep-computed-field.php title-first-match  # two title rules: first wins
 *   wp eval-file .../sweep-computed-field.php self-ref           # own-target token is empty; stable over 3 passes
 *   wp eval-file .../sweep-computed-field.php restore    # baseline rules, clean subject, post_name check
 *
 * REPEATER ROWS (FW-4 06): a row rule targets `line` in every row of the roster
 * repeater `mc_cf_roster` on the subject.
 *   wp eval-file .../sweep-computed-field.php rows           # every row written from its OWN siblings
 *   wp eval-file .../sweep-computed-field.php rows-unchanged # a second pass writes nothing
 *   wp eval-file .../sweep-computed-field.php rows-shape     # add / reorder / remove rows; only the rows that moved are rewritten
 *   wp eval-file .../sweep-computed-field.php rows-tokens    # post-level term/date/title tokens; nested + post meta resolve empty
 *   wp eval-file .../sweep-computed-field.php rows-self-ref  # own-target token is empty; stable over 3 passes
 *   wp eval-file .../sweep-computed-field.php rows-verbatim  # backslashes and quotes stored as typed
 *   wp eval-file .../sweep-computed-field.php rows-preview   # compute() and the Apply preview list the row cells
 *   wp eval-file .../sweep-computed-field.php rows-s1        # bare update_field() of the repeater, let shutdown drain
 *   wp eval-file .../sweep-computed-field.php rows-s2        # assert what rows-s1's shutdown did
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\FormatDispatcher;
use BWS\MetaConductor\Core\TermDispatcher;

$step = $args[0] ?? 'text';

const MCF_TEXT = 'field_mc_cf_display';
const MCF_HTML = 'field_mc_cf_display_html';
const MCF_ROSTER = 'field_mc_cf_roster';
const MCF_LINE   = 'field_mc_cf_roster_line';

/** One Computed field row. */
function mcf_rule( array $over = array() ) {
	return array_merge( array(
		'type'         => 'field_transformation_rules',
		'enabled'      => true,
		'name'         => 'Sweep computed display',
		'post_types'   => array( 'mc_item' ),
		'target_field' => MCF_TEXT,
		'pattern'      => '{meta:mc_cf_first} {meta:mc_cf_last}',
		'output'       => 'text',
	), $over );
}

/** One Computed field row targeting `line` in every roster row. */
function mcf_row_rule( array $over = array() ) {
	return array_merge( array(
		'type'            => 'field_transformation_rules',
		'enabled'         => true,
		'name'            => 'Sweep roster line',
		'post_types'      => array( 'mc_item' ),
		'target_field'    => 'row|' . MCF_ROSTER . '|' . MCF_LINE,
		'pattern'         => '{meta:position} / {meta:height} / {meta:weight}',
		'output'          => 'text',
	), $over );
}

/** Roster source rows as an editor would post them (keyed by subfield key; `line` only when given). */
function mcf_roster_rows( array $rows ) {
	$out = array();
	foreach ( $rows as $r ) {
		$row = array();
		foreach ( $r as $name => $value ) {
			$row[ 'field_mc_cf_roster_' . $name ] = $value;
		}
		$out[] = $row;
	}
	return $out;
}

/** Replace the roster WITHOUT a pass running. */
function mcf_roster_set( $post_id, array $rows ) {
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	// ACF leaves a subfield the post omits as it was; a step starts from no lines.
	for ( $i = 0; $i < 8; $i++ ) {
		delete_post_meta( $post_id, "mc_cf_roster_{$i}_line" );
		delete_post_meta( $post_id, "_mc_cf_roster_{$i}_line" );
	}
	update_field( MCF_ROSTER, wp_slash( mcf_roster_rows( $rows ) ), $post_id );
	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );

	$d = TermDispatcher::instance();
	$r = new ReflectionProperty( $d, 'dirty' );
	$r->setAccessible( true );
	$r->setValue( $d, array() );
}

/** The stored `line` of 1-based roster row $n. */
function mcf_line( $post_id, $n ) {
	return mcf_meta( $post_id, 'mc_cf_roster_' . ( $n - 1 ) . '_line' );
}

/** The first $n lines joined in row order — mc_assert sorts arrays, so an order check needs a string. */
function mcf_lines( $post_id, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = mcf_line( $post_id, $i );
	}
	return implode( ' | ', $out );
}

/** Count `line` writes ACF performs while $fn runs. */
function mcf_count_line_writes( callable $fn ) {
	$writes = 0;
	$hook   = function ( $value, $post_id, $field ) use ( &$writes ) {
		if ( ( $field['key'] ?? '' ) === MCF_LINE ) {
			$writes++;
		}
		return $value;
	};
	add_filter( 'acf/update_value', $hook, 1, 3 );
	$fn();
	remove_filter( 'acf/update_value', $hook, 1 );
	return $writes;
}

/** Make `$rows` the ONLY rule on the site. */
function mcf_setup( array $rows ) {
	mc_isolate( array() );
	mc_write_rule_types( array( 'field_transformation_rules' => $rows ) );
}

/**
 * Set the source values and clear both targets — WITHOUT a pass running.
 * Writes go through ACF by key (as an editor save would), with the passes held
 * down so the arming leaves no mark behind.
 */
function mcf_arm( $post_id, $first, $last ) {
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	// Slashed: update_field() unslashes, so a raw backslash would be lost.
	update_field( 'field_mc_cf_first', wp_slash( $first ), $post_id );
	update_field( 'field_mc_cf_last', wp_slash( $last ), $post_id );
	foreach ( array( 'mc_cf_display', 'mc_cf_display_html' ) as $name ) {
		delete_post_meta( $post_id, $name );
		delete_post_meta( $post_id, '_' . $name );
	}
	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );

	$d = TermDispatcher::instance();
	$r = new ReflectionProperty( $d, 'dirty' );
	$r->setAccessible( true );
	$r->setValue( $d, array() );
}

/** Make `$rows` — Computed field and title/slug rows in the given order — the format list. */
function mcf_setup_list( array $rows ) {
	mc_isolate( array() );
	mc_write_ordered_rules( $rows, \BWS\MetaConductor\Storage\OptionRuleStorage::KIND_FORMAT );
}

/** A title/slug row that sets the title from the computed display field. */
function mcf_title_rule( array $over = array() ) {
	return array_merge( array(
		'type'          => 'title_slug_rules',
		'enabled'       => true,
		'name'          => 'Sweep title from display',
		'post_type'     => 'mc_item',
		'title_pattern' => '{meta:mc_cf_display}',
	), $over );
}

/** Put a value in the stored display field without a pass running. */
function mcf_stored_display( $post_id, $value ) {
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	update_field( MCF_TEXT, $value, $post_id );
	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
}

/**
 * Put the subject's title and slug back. A title rule renames it, and the
 * subject is found by slug — so every step that renames it ends here, or the
 * next step cannot find it (don't 6f(e)).
 */
function mcf_reset_identity( $post_id ) {
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'MC Item Computed', 'post_name' => 'mc-item-computed' ) );
	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
}

/** A stored meta value read past the object cache; null when the key is absent. */
function mcf_meta( $post_id, $name ) {
	wp_cache_delete( $post_id, 'post_meta' );
	return metadata_exists( 'post', $post_id, $name ) ? (string) get_post_meta( $post_id, $name, true ) : null;
}

/** One format pass over the post; returns what it reports changed. */
function mcf_pass( $post_id ) {
	return FormatDispatcher::instance()->run_pass( (int) $post_id );
}

$subject = mc_pid( 'item-computed' );
$control = mc_pid( 'section-holder' );
if ( ! $subject || ! $control ) {
	WP_CLI::error( 'Fixture posts item-computed / section-holder not found — seed mc-rules first.' );
}

switch ( $step ) {

	case 'text':
		mcf_setup( array( mcf_rule() ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		$changed = mcf_pass( $subject );

		mc_assert( '§68a  text write: the field holds the composed value', mcf_meta( $subject, 'mc_cf_display' ), 'Ada Lovelace' );
		mc_assert( '§68b  the pass reports the one field it wrote', $changed, 1 );
		mc_assert( '§68c  the field-key reference row is intact', mcf_meta( $subject, '_mc_cf_display' ), MCF_TEXT );
		mc_assert( '§68d  ACF reads the value back through the field', get_field( MCF_TEXT, $subject ), 'Ada Lovelace' );
		break;

	case 'verbatim':
		mcf_setup( array( mcf_rule() ) );
		mcf_arm( $subject, "Smith & Jones's", 'a\\b "q"' );
		mcf_pass( $subject );

		mc_assert( '§68e  text mode stores values exactly as resolved', mcf_meta( $subject, 'mc_cf_display' ), "Smith & Jones's a\\b \"q\"" );
		break;

	case 'html':
		mcf_setup( array( mcf_rule( array(
			'target_field' => MCF_HTML,
			'pattern'      => '<span class="n">{meta:mc_cf_first}</span> <b>{meta:mc_cf_last}</b>',
			'output'       => 'html',
		) ) ) );
		mcf_arm( $subject, 'A&B <i>', 'Lovelace' );
		mcf_pass( $subject );

		mc_assert(
			'§68f  html write: pattern markup kept, token values escaped',
			mcf_meta( $subject, 'mc_cf_display_html' ),
			'<span class="n">A&amp;B &lt;i&gt;</span> <b>Lovelace</b>'
		);
		mc_assert( '§68g  the wysiwyg field-key reference is intact', mcf_meta( $subject, '_mc_cf_display_html' ), MCF_HTML );
		break;

	case 'unchanged':
		mcf_setup( array( mcf_rule() ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_pass( $subject );

		$writes = 0;
		add_filter( 'acf/update_value', function ( $value, $post_id, $field ) use ( &$writes ) {
			if ( ( $field['key'] ?? '' ) === MCF_TEXT ) {
				$writes++;
			}
			return $value;
		}, 1, 3 );
		$changed = mcf_pass( $subject );

		mc_assert( '§68h  an unchanged value is not written', $writes, 0 );
		mc_assert( '§68i  and the pass reports nothing changed', $changed, 0 );
		mc_assert( '§68j  the value is still there', mcf_meta( $subject, 'mc_cf_display' ), 'Ada Lovelace' );
		break;

	case 'empty':
		mcf_setup( array( mcf_rule() ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_pass( $subject );

		add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
		update_field( 'field_mc_cf_first', '', $subject );
		update_field( 'field_mc_cf_last', '', $subject );
		remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
		$changed = mcf_pass( $subject );

		mc_assert( '§68k  every source empty clears the field to \'\'', mcf_meta( $subject, 'mc_cf_display' ), '' );
		mc_assert( '§68l  the clear counts as one write', $changed, 1 );
		break;

	case 'disabled':
		mcf_setup( array( mcf_rule( array( 'enabled' => false ) ) ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_pass( $subject );

		mc_assert( '§68m  a disabled rule writes nothing', mcf_meta( $subject, 'mc_cf_display' ), null );
		break;

	case 'other':
		mcf_setup( array( mcf_rule() ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		update_post_meta( $control, 'mc_cf_first', 'Grace' );
		delete_post_meta( $control, 'mc_cf_display' );
		mcf_pass( $control );

		mc_assert( '§68n  a post outside the rule\'s post type is untouched', mcf_meta( $control, 'mc_cf_display' ), null );
		delete_post_meta( $control, 'mc_cf_first' );
		break;

	// One rule, two post types: the control (mc_section) is written as well as
	// the subject (mc_item). The control's meta is cleaned up by `restore`.
	case 'multi-type':
		mcf_setup( array( mcf_rule( array( 'post_types' => array( 'mc_item', 'mc_section' ) ) ) ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		update_post_meta( $control, 'mc_cf_first', 'Grace' );
		update_post_meta( $control, 'mc_cf_last', 'Hopper' );
		delete_post_meta( $control, 'mc_cf_display' );
		mcf_pass( $subject );
		mcf_pass( $control );

		mc_assert( '§71a  a rule naming two post types writes the first', mcf_meta( $subject, 'mc_cf_display' ), 'Ada Lovelace' );
		mc_assert( '§71b  ...and the second', mcf_meta( $control, 'mc_cf_display' ), 'Grace Hopper' );

		mcf_setup( array( mcf_rule( array( 'post_types' => array( 'mc_section' ) ) ) ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_pass( $subject );
		mc_assert( '§71c  a post type the rule does not name is untouched', mcf_meta( $subject, 'mc_cf_display' ), null );
		break;

	case 'no-types':
		mcf_setup( array( mcf_rule( array( 'post_types' => array() ) ) ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		update_post_meta( $control, 'mc_cf_first', 'Grace' );
		delete_post_meta( $control, 'mc_cf_display' );
		mcf_pass( $subject );
		mcf_pass( $control );

		mc_assert( '§71d  no post type checked: the subject is untouched', mcf_meta( $subject, 'mc_cf_display' ), null );
		mc_assert( '§71e  no post type checked: no other post type is written either', mcf_meta( $control, 'mc_cf_display' ), null );
		break;

	case 'save':
		mcf_setup( array( mcf_rule() ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		wp_update_post( array( 'ID' => $subject ) );

		mc_assert( '§68o  an editor-shaped save computes the field', mcf_meta( $subject, 'mc_cf_display' ), 'Ada Lovelace' );
		break;

	// A bare update_field() fires neither save_post nor acf/save_post, so the
	// ACF write queue is what notices it and the shutdown drain is what runs
	// the pass. Two evals: s1 writes and returns, s2 reads what shutdown did.
	case 's1':
		mcf_setup( array( mcf_rule() ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		update_field( 'field_mc_cf_first', 'Grace', $subject );
		WP_CLI::log( '[s1] wrote mc_cf_first=Grace; run s2 to read what shutdown did' );
		break;

	case 's2':
		mc_assert( '§68p  shutdown drained the bare write into the computed field', mcf_meta( $subject, 'mc_cf_display' ), 'Grace Lovelace' );
		break;

	// --- Composition in the format list (ticket 04) -------------------------

	case 'two-fields':
		mcf_setup_list( array(
			mcf_rule( array( 'pattern' => '{meta:mc_cf_first}' ) ),
			mcf_rule( array( 'target_field' => MCF_HTML, 'pattern' => '{meta:mc_cf_last}' ) ),
		) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_pass( $subject );

		mc_assert( '§69a  two rules, two fields: the first is written', mcf_meta( $subject, 'mc_cf_display' ), 'Ada' );
		mc_assert( '§69b  two rules, two fields: the second is written', mcf_meta( $subject, 'mc_cf_display_html' ), 'Lovelace' );
		break;

	case 'same-field':
		$first = mcf_rule( array( 'pattern' => '{meta:mc_cf_first}' ) );
		$last  = mcf_rule( array( 'pattern' => '{meta:mc_cf_last}' ) );

		mcf_setup_list( array( $first, $last ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_pass( $subject );
		mc_assert( '§69c  same field: the later rule in the list wins', mcf_meta( $subject, 'mc_cf_display' ), 'Lovelace' );

		mcf_setup_list( array( $last, $first ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_pass( $subject );
		mc_assert( '§69d  swapping the order swaps the winner', mcf_meta( $subject, 'mc_cf_display' ), 'Ada' );
		break;

	// The title rule reads {meta:mc_cf_display}: ordered after the field rule it
	// sees the value computed in this pass; ordered before, the stored one.
	case 'title-after':
		mcf_setup_list( array( mcf_rule(), mcf_title_rule() ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_stored_display( $subject, 'Stored Old' );
		mcf_pass( $subject );

		mc_assert( '§69e  a title rule ordered after the field rule reads the pending value', get_post( $subject )->post_title, 'Ada Lovelace' );
		mc_assert( '§69f  and the field holds it', mcf_meta( $subject, 'mc_cf_display' ), 'Ada Lovelace' );
		mcf_reset_identity( $subject );
		break;

	case 'title-before':
		mcf_setup_list( array( mcf_title_rule(), mcf_rule() ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_stored_display( $subject, 'Stored Old' );
		mcf_pass( $subject );

		mc_assert( '§69g  a title rule ordered before the field rule reads the stored value', get_post( $subject )->post_title, 'Stored Old' );
		mc_assert( '§69h  the field still takes the new value', mcf_meta( $subject, 'mc_cf_display' ), 'Ada Lovelace' );
		mcf_reset_identity( $subject );
		break;

	case 'title-first-match':
		mcf_setup_list( array(
			mcf_title_rule( array( 'title_pattern' => '{meta:mc_cf_first}' ) ),
			mcf_title_rule( array( 'title_pattern' => '{meta:mc_cf_last}' ) ),
		) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_pass( $subject );

		mc_assert( '§69i  two title rules on one post type: the first matches, the second never runs', get_post( $subject )->post_title, 'Ada' );
		mcf_reset_identity( $subject );
		break;

	case 'self-ref':
		mcf_setup_list( array( mcf_rule( array( 'pattern' => '{meta:mc_cf_display} {meta:mc_cf_last}' ) ) ) );
		mcf_arm( $subject, 'Ada', 'Lovelace' );
		mcf_stored_display( $subject, 'Stale' );
		$one = mcf_pass( $subject );
		mc_assert( '§69j  a token naming the rule\'s own target resolves empty', mcf_meta( $subject, 'mc_cf_display' ), 'Lovelace' );
		$two   = mcf_pass( $subject );
		$three = mcf_pass( $subject );
		mc_assert( '§69k  stable across three consecutive passes', mcf_meta( $subject, 'mc_cf_display' ), 'Lovelace' );
		mc_assert( '§69l  and the later passes write nothing', $two + $three, 0 );
		break;

	// --- Repeater-row targets (ticket 06) ------------------------------------

	case 'rows':
		mcf_setup_list( array( mcf_row_rule() ) );
		mcf_roster_set( $subject, array(
			array( 'position' => 'Forward', 'height' => "6'2\"", 'weight' => '180' ),
			array( 'position' => 'Guard', 'height' => "5'11\"", 'weight' => '165' ),
			array( 'position' => 'Center', 'height' => '', 'weight' => '210' ),
		) );
		$changed = mcf_pass( $subject );

		mc_assert( '§70a  row 1 is composed from row 1\'s siblings', mcf_line( $subject, 1 ), "Forward / 6'2\" / 180" );
		mc_assert( '§70b  row 2 is composed from row 2\'s siblings', mcf_line( $subject, 2 ), "Guard / 5'11\" / 165" );
		mc_assert( '§70c  an empty sibling drops with its separator', mcf_line( $subject, 3 ), 'Center / 210' );
		mc_assert( '§70d  the pass reports one write per row', $changed, 3 );
		mc_assert( '§70e  the subfield field-key reference row is intact', mcf_meta( $subject, '_mc_cf_roster_0_line' ), MCF_LINE );
		$rows = get_field( MCF_ROSTER, $subject );
		mc_assert( '§70f  ACF reads the rows back, the repeater intact', count( $rows ) . '|' . $rows[1]['line'] . '|' . $rows[0]['position'], "3|Guard / 5'11\" / 165|Forward" );
		mc_assert( '§70g  the repeater row count is unchanged', mcf_meta( $subject, 'mc_cf_roster' ), '3' );
		break;

	case 'rows-unchanged':
		mcf_setup_list( array( mcf_row_rule() ) );
		mcf_roster_set( $subject, array(
			array( 'position' => 'Forward', 'height' => '6', 'weight' => '180' ),
			array( 'position' => 'Guard', 'height' => '5', 'weight' => '165' ),
		) );
		mcf_pass( $subject );
		$changed = 0;
		$writes  = mcf_count_line_writes( function () use ( $subject, &$changed ) {
			$changed = mcf_pass( $subject );
		} );
		mc_assert( '§70h  an unchanged row is not written', $writes, 0 );
		mc_assert( '§70i  and the pass reports nothing changed', $changed, 0 );
		break;

	// Rows added, reordered and removed. The editor posts every cell it holds,
	// `line` included, so a reorder carries each row's line with it; the pass
	// must leave those alone (unchanged) and compute only what is new or stale.
	case 'rows-shape':
		mcf_setup_list( array( mcf_row_rule() ) );
		$a = array( 'position' => 'Forward', 'height' => '6', 'weight' => '180' );
		$b = array( 'position' => 'Guard', 'height' => '5', 'weight' => '165' );
		$c = array( 'position' => 'Center', 'height' => '7', 'weight' => '210' );
		mcf_roster_set( $subject, array( $a, $b, $c ) );
		mcf_pass( $subject );

		$withline = function ( array $r ) { return $r + array( 'line' => "{$r['position']} / {$r['height']} / {$r['weight']}" ); };

		// Reorder: C, A, B.
		mcf_roster_set( $subject, array( $withline( $c ), $withline( $a ), $withline( $b ) ) );
		$writes = mcf_count_line_writes( function () use ( $subject ) { mcf_pass( $subject ); } );
		mc_assert( '§70j  after a reorder every row\'s line matches its own siblings',
			mcf_lines( $subject, 3 ),
			'Center / 7 / 210 | Forward / 6 / 180 | Guard / 5 / 165' );
		mc_assert( '§70k  and the reorder rewrote no row', $writes, 0 );

		// Add a row at the end.
		$d = array( 'position' => 'Wing', 'height' => '6', 'weight' => '175' );
		mcf_roster_set( $subject, array( $withline( $c ), $withline( $a ), $withline( $b ), $d ) );
		$writes = mcf_count_line_writes( function () use ( $subject ) { mcf_pass( $subject ); } );
		mc_assert( '§70l  an added row gets its line', mcf_line( $subject, 4 ), 'Wing / 6 / 175' );
		mc_assert( '§70m  and only that row was written', $writes, 1 );

		// Remove the first row: A, B, D shift up.
		mcf_roster_set( $subject, array( $withline( $a ), $withline( $b ), $withline( $d ) ) );
		$writes = mcf_count_line_writes( function () use ( $subject ) { mcf_pass( $subject ); } );
		mc_assert( '§70n  after a removal every row\'s line matches its own siblings',
			mcf_lines( $subject, 3 ),
			'Forward / 6 / 180 | Guard / 5 / 165 | Wing / 6 / 175' );
		mc_assert( '§70o  and the removal rewrote no row', $writes, 0 );

		// A stale line (as left by a rule edit) is corrected, and only that row.
		mcf_roster_set( $subject, array( $withline( $a ), array_merge( $withline( $b ), array( 'line' => 'STALE' ) ), $withline( $d ) ) );
		$writes = mcf_count_line_writes( function () use ( $subject ) { mcf_pass( $subject ); } );
		mc_assert( '§70p  a stale line is recomputed from its siblings', mcf_line( $subject, 2 ), 'Guard / 5 / 165' );
		mc_assert( '§70q  and only the stale row was written', $writes, 1 );
		break;

	case 'rows-tokens':
		$year = get_the_date( 'Y', $subject );
		mcf_setup_list( array( mcf_row_rule( array(
			'pattern' => '{meta:position} {date_year:since} {pub_year} {default_title} {meta:grp} {meta:inner} {meta:mc_cf_first}',
		) ) ) );
		mcf_roster_set( $subject, array(
			array( 'position' => 'Forward', 'since' => '20200315', 'grp' => array( 'inner' => 'NESTED' ) ),
		) );
		mcf_pass( $subject );

		mc_assert(
			'§70r  sibling + date read the row; pub/title read the post; nested group, inner and post meta resolve empty',
			mcf_line( $subject, 1 ),
			"Forward 2020 $year MC Item Computed"
		);
		break;

	case 'rows-self-ref':
		mcf_setup_list( array( mcf_row_rule( array( 'pattern' => '{meta:position} {meta:line}' ) ) ) );
		mcf_roster_set( $subject, array(
			array( 'position' => 'Forward', 'line' => 'Stale' ),
			array( 'position' => 'Guard', 'line' => 'Stale' ),
		) );
		mcf_pass( $subject );
		mc_assert( '§70s  a token naming the rule\'s own subfield resolves empty', mcf_lines( $subject, 2 ), 'Forward | Guard' );
		$more = mcf_pass( $subject ) + mcf_pass( $subject );
		mc_assert( '§70t  and the later passes write nothing', $more, 0 );
		break;

	case 'rows-verbatim':
		mcf_setup_list( array( mcf_row_rule( array( 'pattern' => '{meta:position} {meta:height}' ) ) ) );
		mcf_roster_set( $subject, array( array( 'position' => 'A\\B "q"', 'height' => "5'11" ) ) );
		mcf_pass( $subject );
		mc_assert( '§70u  text mode stores a row cell exactly as resolved', mcf_line( $subject, 1 ), "A\\B \"q\" 5'11" );
		break;

	case 'rows-preview':
		mcf_setup_list( array( mcf_row_rule() ) );
		mcf_roster_set( $subject, array(
			array( 'position' => 'Forward', 'height' => '6', 'weight' => '180' ),
			array( 'position' => 'Guard', 'height' => '5', 'weight' => '165' ),
		) );
		$before = mcf_line( $subject, 1 );
		$result = FormatDispatcher::instance()->compute( $subject );
		mc_assert( '§70v  compute() writes nothing', array( $before, mcf_line( $subject, 1 ) ), array( null, null ) );
		mc_assert( '§70w  and carries the row cells', $result['after']['rows'][ MCF_ROSTER ][2][ MCF_LINE ]['value'], 'Guard / 5 / 165' );

		$choice  = \BWS\MetaConductor\Core\RuleChoice::encode(
			\BWS\MetaConductor\Storage\OptionRuleStorage::KIND_FORMAT, 0,
			\BWS\MetaConductor\Storage\StorageFactory::get_instance()->get_kind_rules( \BWS\MetaConductor\Storage\OptionRuleStorage::KIND_FORMAT )[0]
		);
		$preview = \BWS\MetaConductor\Core\ExistingPostsApplier::preview( $choice );
		$sample  = null;
		foreach ( $preview['sample'] as $row ) {
			if ( $row['post_id'] === $subject ) {
				$sample = $row;
			}
		}
		mc_assert( '§70x  the Apply preview lists the row cells before and after',
			$sample['fields'] ?? null,
			array( 'mc_cf_roster[1].line' => array( '', 'Forward / 6 / 180' ), 'mc_cf_roster[2].line' => array( '', 'Guard / 5 / 165' ) ) );

		$run = \BWS\MetaConductor\Core\ExistingPostsApplier::run_batch( $choice );
		mc_assert( '§70y  an Apply run fills the rows', mcf_lines( $subject, 2 ), 'Forward / 6 / 180 | Guard / 5 / 165' );
		$report = null;
		foreach ( $run['rows'] as $row ) {
			if ( $row['post_id'] === $subject ) {
				$report = $row['fields'];
			}
		}
		mc_assert( '§70z  and its report lists them', $report, array( 'mc_cf_roster[1].line' => array( '', 'Forward / 6 / 180' ), 'mc_cf_roster[2].line' => array( '', 'Guard / 5 / 165' ) ) );
		\BWS\MetaConductor\Core\ExistingPostsApplier::start_over( $choice );
		break;

	// A bare update_field() of the repeater fires neither save hook; the write
	// queue's acf/update_value listener notices and shutdown runs the pass.
	case 'rows-s1':
		mcf_setup_list( array( mcf_row_rule() ) );
		mcf_roster_set( $subject, array( array( 'position' => 'Forward', 'height' => '6', 'weight' => '180' ) ) );
		update_field( MCF_ROSTER, wp_slash( mcf_roster_rows( array(
			array( 'position' => 'Guard', 'height' => '5', 'weight' => '165' ),
			array( 'position' => 'Center', 'height' => '7', 'weight' => '210' ),
		) ) ), $subject );
		WP_CLI::log( '[rows-s1] wrote two rows; run rows-s2 to read what shutdown did' );
		break;

	case 'rows-s2':
		mc_assert( '§70aa shutdown drained the bare repeater write into the row lines',
			mcf_lines( $subject, 2 ), 'Guard / 5 / 165 | Center / 7 / 210' );
		break;

	case 'restore':
		mc_restore();
		add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
		update_field( MCF_ROSTER, array(), $subject );
		foreach ( array( 'line', 'position', 'height', 'weight', 'since' ) as $sub ) {
			for ( $i = 0; $i < 6; $i++ ) {
				delete_post_meta( $subject, "mc_cf_roster_{$i}_{$sub}" );
				delete_post_meta( $subject, "_mc_cf_roster_{$i}_{$sub}" );
			}
		}
		update_field( 'field_mc_cf_first', 'Ada', $subject );
		update_field( 'field_mc_cf_last', 'Lovelace', $subject );
		foreach ( array( 'mc_cf_display', 'mc_cf_display_html' ) as $name ) {
			delete_post_meta( $subject, $name );
			delete_post_meta( $subject, '_' . $name );
		}
		foreach ( array( 'mc_cf_first', 'mc_cf_last', 'mc_cf_display', '_mc_cf_display' ) as $name ) {
			delete_post_meta( $control, $name );
		}
		remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );

		// Don't 6f(e): the format pass runs for every entity a drain reaches.
		foreach ( mc_sweep_manifest()['posts'] as $slug => $def ) {
			$id = mc_pid( $slug );
			mc_assert( "restore: $slug keeps its post_name", $id ? get_post( $id )->post_name : null, $def['post_name'] );
		}
		break;

	default:
		WP_CLI::error( "Unknown step '$step'." );
}
