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
 *   wp eval-file .../sweep-computed-field.php other      # a post outside the rule's post type is untouched
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
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\FormatDispatcher;
use BWS\MetaConductor\Core\TermDispatcher;

$step = $args[0] ?? 'text';

const MCF_TEXT = 'field_mc_cf_display';
const MCF_HTML = 'field_mc_cf_display_html';

/** One Computed field row. */
function mcf_rule( array $over = array() ) {
	return array_merge( array(
		'type'         => 'field_transformation_rules',
		'enabled'      => true,
		'name'         => 'Sweep computed display',
		'post_type'    => 'mc_item',
		'target_field' => MCF_TEXT,
		'pattern'      => '{meta:mc_cf_first} {meta:mc_cf_last}',
		'output'       => 'text',
	), $over );
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

	case 'restore':
		mc_restore();
		add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
		update_field( 'field_mc_cf_first', 'Ada', $subject );
		update_field( 'field_mc_cf_last', 'Lovelace', $subject );
		foreach ( array( 'mc_cf_display', 'mc_cf_display_html' ) as $name ) {
			delete_post_meta( $subject, $name );
			delete_post_meta( $subject, '_' . $name );
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
