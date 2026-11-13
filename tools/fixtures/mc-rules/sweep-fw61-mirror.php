<?php
/**
 * mc-rules — FW-61 06 behavior sweep: ACF taxonomy mirror fields on an `acf`
 * row of terms_from_related_post.
 *
 * Before this change an `acf` row read a source's NATIVE terms and wrote the
 * dependent's NATIVE terms only. A `parent` row has always read native ∪ mirror
 * and written both stores. The two now agree, and this sweep is the proof of the
 * one deliberate behavior change besides the claim mapping.
 *
 *   matrix    The three claims (Owning = replace, Contributing = merge,
 *             Deferring = skip) crossed with where the SOURCE holds its terms:
 *             only in the mirror field, only natively, in both. Every case
 *             asserts BOTH stores of the dependent.
 *   first     An attached-but-empty mirror field on the dependent (no saved
 *             meta at all) receives its first write.
 *   status    The row's status limit gates the SOURCE for the mirror store as
 *             it does for native: a gated-out source contributes nothing from
 *             either store, and an Owning dependent is emptied in both.
 *   sever-a / sever-b   A sever under Owning empties the mirror store as well
 *             as the native one. Own evals: the capture reads the rule list
 *             through a request-lifetime memo that staging fills with the empty
 *             set (sweep-63 header).
 *   restore   Put the subject/holder mirrors and the holder status back.
 *
 * WHY THE SOURCE-HELD-IN-ONE-STORE CASES ARE BUILT BY HAND. The fixture's
 * mc_topics fields have Load Terms OFF and Save Terms ON, so `update_field()`
 * writes both stores and `wp_set_object_terms()` writes only the native one.
 * Mirror-only is therefore update_field() THEN a native clear; native-only is
 * update_field([]) THEN a native set. The order is the whole trick.
 *
 * WHY THE DEPENDENT'S OWN TERMS GO IN THROUGH update_field() TOO. Save Terms ON
 * means a native-only term sits opposite an empty mirror, and the mirror write
 * (which runs first, as on the parent path) syncs its result over the native
 * store. A dependent staged native-only would read as Contributing behaving like
 * Owning. See sweep-62's `mc62_set_own_terms()`.
 *
 * Usage (one step per eval for the sever pair):
 *   wp eval-file .../sweep-fw61-mirror.php matrix
 *   wp eval-file .../sweep-fw61-mirror.php first
 *   wp eval-file .../sweep-fw61-mirror.php status
 *   wp eval-file .../sweep-fw61-mirror.php sever-a
 *   wp eval-file .../sweep-fw61-mirror.php sever-b
 *   wp eval-file .../sweep-fw61-mirror.php restore
 * Then `sweep-63-acf-reference.php restore` to put the graph back.
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'matrix';

function mcm_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

function mcm_author( array $rows ) {
	return mc_write_ordered_rules( $rows, OptionRuleStorage::KIND_TERM );
}

function mcm_silence() {
	mcm_author( array() );
	mcm_dispatcher()->drain();
}

function mcm_pass( $post_id ) {
	$d = mcm_dispatcher();
	$d->mark_dirty( (int) $post_id );
	$d->drain();
}

/** Term slugs for a list of term IDs, sorted. */
function mcm_slugs_of( array $term_ids ) {
	$slugs = array();
	foreach ( $term_ids as $id ) {
		$term = get_term( (int) $id, 'mc_topic' );
		if ( $term && ! is_wp_error( $term ) ) {
			$slugs[] = $term->slug;
		}
	}
	sort( $slugs );
	return $slugs;
}

/** The post's NATIVE mc_topic slugs. */
function mcm_native( $post_id ) {
	$ids = wp_get_object_terms( (int) $post_id, 'mc_topic', array( 'fields' => 'ids' ) );
	return mcm_slugs_of( is_wp_error( $ids ) ? array() : $ids );
}

/** The post's MIRROR (ACF `mc_topics`) slugs; Load Terms is off, so this is the saved meta. */
function mcm_mirror( $post_id ) {
	$value = get_field( 'mc_topics', (int) $post_id );
	return mcm_slugs_of( empty( $value ) ? array() : array_map( 'intval', (array) $value ) );
}

/** Put terms in BOTH stores (Save Terms syncs native). */
function mcm_set_both( $post_id, array $term_ids ) {
	update_field( 'mc_topics', $term_ids, (int) $post_id );
	wp_set_object_terms( (int) $post_id, $term_ids, 'mc_topic' );
}

/** Clear both stores, mirror first (update_field also clears native). */
function mcm_clear( $post_id ) {
	update_field( 'mc_topics', array(), (int) $post_id );
	wp_set_object_terms( (int) $post_id, array(), 'mc_topic' );
}

/** Put the SOURCE's terms in one store, the other empty. $where = mirror|native|both. */
function mcm_set_source( $post_id, $where, array $term_ids ) {
	mcm_clear( $post_id );
	if ( 'both' === $where ) {
		mcm_set_both( $post_id, $term_ids );
	} elseif ( 'mirror' === $where ) {
		update_field( 'mc_topics', $term_ids, (int) $post_id );
		wp_set_object_terms( (int) $post_id, array(), 'mc_topic' );
	} else {
		wp_set_object_terms( (int) $post_id, $term_ids, 'mc_topic' );
	}
}

/** Push row from the holder (tier-1 reverse field) onto the subject. */
function mcm_rule( $claim, array $extra = array() ) {
	return array_merge( array(
		'type'                   => 'terms_from_related_post',
		'relationship'           => 'acf',
		'enabled'                => true,
		'acf_field_name'         => 'mc_section:mc_related_items:field_mc_related_items',
		'reverse_acf_field_name' => 'mc_item:mc_parent_section:field_mc_parent_section',
		'holder_role'            => 'source',
		'taxonomy'               => 'mc_topic',
		'conflict_handling'      => $claim,
	), $extra );
}

/** Wire holder → subject with no rule live; leave both stores of both posts empty. */
function mcm_stage_graph() {
	$subject = mc_pid( 'item-solo-a' );
	$holder  = mc_pid( 'section-holder' );
	if ( ! $subject || ! $holder ) {
		WP_CLI::error( 'Fixture posts missing — seed mc-rules first.' );
	}
	mcm_silence();
	mcm_clear( $subject );
	mcm_clear( $holder );
	update_field( 'mc_related_items', array( mc_pid( 'item-alpha' ), mc_pid( 'item-beta' ), $subject ), $holder );
	update_field( 'mc_parent_section', array( $holder ), $subject );
	mcm_dispatcher()->drain();
	return array( $subject, $holder );
}

$ok    = 0;
$fail  = 0;
$check = function ( $label, $got, $want ) use ( &$ok, &$fail ) {
	if ( mc_assert( $label, $got, $want ) ) {
		++$ok;
	} else {
		++$fail;
	}
};

$coastal = mc_tid( 'topic-coastal' );
$east    = mc_tid( 'topic-east' );

switch ( $step ) {

	case 'matrix':
		WP_CLI::log( 'matrix — claim × where the source holds its terms; both dependent stores asserted' );
		// claim => [ dependent's own terms before, expected after ] (slugs).
		$claims = array(
			'replace' => array( array( $east ), array( 'coastal' ) ),
			'merge'   => array( array( $east ), array( 'coastal', 'east' ) ),
			'skip'    => array( array(), array( 'coastal' ) ),
		);
		foreach ( $claims as $claim => list( $own, $want ) ) {
			foreach ( array( 'mirror', 'native', 'both' ) as $where ) {
				list( $subject, $holder ) = mcm_stage_graph();
				mcm_set_source( $holder, $where, array( $coastal ) );
				if ( $own ) {
					mcm_set_both( $subject, $own );
				}
				mcm_author( array( mcm_rule( $claim ) ) );
				mcm_pass( $subject );
				$check( "$claim / source $where: native", mcm_native( $subject ), $want );
				$check( "$claim / source $where: mirror", mcm_mirror( $subject ), $want );
			}
		}

		// Deferring leaves an occupied dependent alone in both stores.
		list( $subject, $holder ) = mcm_stage_graph();
		mcm_set_source( $holder, 'mirror', array( $coastal ) );
		mcm_set_both( $subject, array( $east ) );
		mcm_author( array( mcm_rule( 'skip' ) ) );
		mcm_pass( $subject );
		$check( 'skip / occupied: native untouched', mcm_native( $subject ), array( 'east' ) );
		$check( 'skip / occupied: mirror untouched', mcm_mirror( $subject ), array( 'east' ) );
		break;

	case 'first':
		WP_CLI::log( 'first — attached-but-empty mirror field gets its first write' );
		list( $subject, $holder ) = mcm_stage_graph();
		mcm_set_source( $holder, 'native', array( $coastal ) );
		// The dependent has native terms and a mirror field that has never been saved.
		delete_post_meta( $subject, 'mc_topics' );
		delete_post_meta( $subject, '_mc_topics' );
		wp_set_object_terms( $subject, array( $east ), 'mc_topic' );
		$check( 'first: mirror starts with no saved value', get_post_meta( $subject, 'mc_topics', true ), '' );
		mcm_author( array( mcm_rule( 'replace' ) ) );
		mcm_pass( $subject );
		$check( 'first: mirror written', mcm_mirror( $subject ), array( 'coastal' ) );
		$check( 'first: native written', mcm_native( $subject ), array( 'coastal' ) );
		break;

	case 'status':
		WP_CLI::log( 'status — a gated-out source contributes from NEITHER store; Owning empties both' );
		global $wpdb;
		list( $subject, $holder ) = mcm_stage_graph();
		mcm_set_source( $holder, 'mirror', array( $coastal ) );
		mcm_set_both( $subject, array( $east ) );
		$row = mcm_rule( 'replace', array( 'post_status' => array( 'publish' ) ) );
		mcm_author( array( $row ) );
		mcm_pass( $subject );
		$check( 'status / published source: mirror', mcm_mirror( $subject ), array( 'coastal' ) );

		$was = get_post_status( $holder );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $holder ) );
		clean_post_cache( $holder );
		mcm_pass( $subject );
		$wpdb->update( $wpdb->posts, array( 'post_status' => $was ), array( 'ID' => $holder ) );
		clean_post_cache( $holder );
		$check( 'status / draft source: native emptied', mcm_native( $subject ), array() );
		$check( 'status / draft source: mirror emptied', mcm_mirror( $subject ), array() );
		break;

	case 'sever-a':
		WP_CLI::log( 'sever-a — stage Owning, source in the mirror only (sever runs in sever-b)' );
		list( $subject, $holder ) = mcm_stage_graph();
		mcm_set_source( $holder, 'mirror', array( $coastal ) );
		mcm_author( array( mcm_rule( 'replace' ) ) );
		mcm_pass( $subject );
		$check( 'sever-a baseline: native', mcm_native( $subject ), array( 'coastal' ) );
		$check( 'sever-a baseline: mirror', mcm_mirror( $subject ), array( 'coastal' ) );
		break;

	case 'sever-b':
		WP_CLI::log( 'sever-b — Owning sever empties the mirror store too' );
		$subject = mc_pid( 'item-solo-a' );
		update_field( 'mc_parent_section', array(), $subject );
		do_action( 'acf/save_post', $subject );
		wp_update_post( array( 'ID' => $subject ) );
		mcm_dispatcher()->drain();
		$check( 'sever-b: native emptied', mcm_native( $subject ), array() );
		$check( 'sever-b: mirror emptied', mcm_mirror( $subject ), array() );
		break;

	case 'restore':
		mcm_silence();
		foreach ( array( mc_pid( 'item-solo-a' ), mc_pid( 'section-holder' ) ) as $pid ) {
			mcm_clear( $pid );
		}
		mc_restore();
		WP_CLI::log( '[restore] rules rebuilt, subject + holder cleared in both stores. Now run sweep-63-acf-reference restore.' );
		break;

	default:
		WP_CLI::error( "Unknown step '{$step}' — use matrix | first | status | sever-a | sever-b | restore." );
}

if ( 'restore' !== $step ) {
	if ( $fail ) {
		WP_CLI::error( sprintf( '%s: %d passed, %d FAILED', $step, $ok, $fail ) );
	}
	WP_CLI::success( sprintf( '%s: %d assertions passed', $step, $ok ) );
}
