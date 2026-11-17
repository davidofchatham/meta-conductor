<?php
/**
 * mc-rules — FW-5 ticket 01/02: the zero-source gate on an Owning `acf` row of
 * terms_from_related_post.
 *
 * ONE subject (`item-solo-a`) with TWO holders (`section-holder` = coastal,
 * `section-holder2` = west) wired on both ends of the relationship. One Owning
 * (`replace`) row limited to `post_status = publish`. Three states:
 *
 *   both   both holders published       subject = coastal + west
 *   mixed  one holder a draft           subject = the PUBLISHED holder's terms only
 *   draft  every holder a draft         subject = see $DRAFT_WANT
 *
 * `$DRAFT_WANT` is the one assertion ticket 02 flips. Ticket 01 recorded the
 * baseline: an Owning row whose sources are ALL gated out EMPTIES the dependent.
 * Ticket 02's shared zero-source gate makes it keep its terms: the sweep primes
 * the subject with coastal + west before the draft pass and wants them back.
 *
 * Each holder's status is changed with a direct $wpdb update so no save hook
 * fires, and put back afterward, pass or fail.
 *
 * Usage:  wp eval-file .../sweep-fw5-zero-source.php run
 * Prereq: mc-rules seeded. Mutates term state; the sweep restores the fixture.
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Ticket 01 baseline was array() (emptied). Ticket 02's shared gate keeps them.
$DRAFT_WANT = array( 'coastal', 'west' );

$d = TermDispatcher::instance();
if ( ! $d ) {
	WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
}

$subject = mc_pid_strict( 'item-solo-a' );
$holders = array( mc_pid_strict( 'section-holder' ), mc_pid_strict( 'section-holder2' ) );
if ( ! $subject || in_array( 0, $holders, true ) ) {
	WP_CLI::error( 'Fixture posts missing — seed mc-rules first.' );
}
$terms = array( mc_tid( 'topic-coastal' ), mc_tid( 'topic-west' ) );

$slugs  = function ( $ids ) {
	$out = array();
	foreach ( $ids as $id ) {
		$t = get_term( (int) $id, 'mc_topic' );
		if ( $t && ! is_wp_error( $t ) ) {
			$out[] = $t->slug;
		}
	}
	sort( $out );
	return $out;
};
$native = function ( $id ) use ( $slugs ) {
	$ids = wp_get_object_terms( (int) $id, 'mc_topic', array( 'fields' => 'ids' ) );
	return $slugs( is_wp_error( $ids ) ? array() : $ids );
};
$mirror = function ( $id ) use ( $slugs ) {
	$v = get_field( 'mc_topics', (int) $id );
	return $slugs( empty( $v ) ? array() : array_map( 'intval', (array) $v ) );
};
$clear  = function ( $id ) {
	update_field( 'mc_topics', array(), (int) $id );
	wp_set_object_terms( (int) $id, array(), 'mc_topic' );
};
$status = function ( $id, $to ) {
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_status' => $to ), array( 'ID' => $id ) );
	clean_post_cache( $id );
};
$pass   = function () use ( $d, $subject ) {
	$d->mark_dirty( $subject );
	$d->drain();
};

$was = array_map( 'get_post_status', $holders );

// Snapshot every post this sweep touches (both stores), and put them back at the
// end, so a later sweep sees the fixture as it was (the sever sweep needs the
// second holder's own term).
$snap = array();
foreach ( array_merge( array( $subject ), $holders ) as $id ) {
	$ids         = wp_get_object_terms( $id, 'mc_topic', array( 'fields' => 'ids' ) );
	$snap[ $id ] = array(
		'native' => is_wp_error( $ids ) ? array() : array_map( 'intval', $ids ),
		'mirror' => (array) get_field( 'mc_topics', $id, false ),
	);
}
$ok  = 0;
$bad = 0;
$chk = function ( $label, $got, $want ) use ( &$ok, &$bad ) {
	mc_assert( $label, $got, $want ) ? ++$ok : ++$bad;
};

// Stage with no rule live: both holders hold their terms, the graph is wired on both ends.
mc_write_ordered_rules( array(), OptionRuleStorage::KIND_TERM );
$d->drain();
$clear( $subject );
foreach ( $holders as $i => $h ) {
	$clear( $h );
	update_field( 'mc_topics', array( $terms[ $i ] ), $h );
	wp_set_object_terms( $h, array( $terms[ $i ] ), 'mc_topic' );
	update_field( 'mc_related_items', array( $subject ), $h );
}
update_field( 'mc_parent_section', $holders, $subject );
$d->drain();

mc_write_ordered_rules( array( array(
	'type'                   => 'terms_from_related_post',
	'relationship'           => 'acf',
	'enabled'                => true,
	'acf_field_name'         => 'mc_section:mc_related_items:field_mc_related_items',
	'reverse_acf_field_name' => 'mc_item:mc_parent_section:field_mc_parent_section',
	'holder_role'            => 'source',
	'taxonomy'               => 'mc_topic',
	'conflict_handling'      => 'replace',
	'post_status'            => array( 'publish' ),
) ), OptionRuleStorage::KIND_TERM );

try {
	foreach ( array(
		'both'  => array( array( 'publish', 'publish' ), array( 'coastal', 'west' ) ),
		'mixed' => array( array( 'draft', 'publish' ), array( 'west' ) ),
		'draft' => array( array( 'draft', 'draft' ), $DRAFT_WANT ),
	) as $name => list( $statuses, $want ) ) {
		foreach ( $holders as $i => $h ) {
			$status( $h, $statuses[ $i ] );
		}
		if ( 'draft' === $name ) {
			// The states run in sequence, so put back what the subject held at
			// the start; the gate must leave exactly this alone.
			update_field( 'mc_topics', $terms, $subject );
			wp_set_object_terms( $subject, $terms, 'mc_topic' );
		}
		$pass();
		WP_CLI::log( sprintf( '%s: native=[%s] mirror=[%s]', $name, implode( ',', $native( $subject ) ), implode( ',', $mirror( $subject ) ) ) );
		$chk( "$name: native", $native( $subject ), $want );
		$chk( "$name: mirror", $mirror( $subject ), $want );
	}
} finally {
	foreach ( $holders as $i => $h ) {
		$status( $h, $was[ $i ] );
	}
	mc_write_ordered_rules( array(), OptionRuleStorage::KIND_TERM );
	$d->drain();
	foreach ( $snap as $id => $was_terms ) {
		$clear( $id );
		update_field( 'mc_topics', $was_terms['mirror'], $id );
		wp_set_object_terms( $id, $was_terms['native'], 'mc_topic' );
	}
	mc_restore();
}

if ( $bad ) {
	WP_CLI::error( sprintf( 'run: %d passed, %d FAILED', $ok, $bad ) );
}
WP_CLI::success( sprintf( 'run: %d assertions passed', $ok ) );
