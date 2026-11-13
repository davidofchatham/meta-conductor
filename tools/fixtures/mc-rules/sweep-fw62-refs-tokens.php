<?php
/**
 * mc-rules — FW-62 ticket 02 end-to-end: `{refs:<field>:<inner>}` through a real
 * title rule on an `mc_section` whose ACF relationship field names `mc_item` posts.
 *
 * Receiver rule (the field `mc_related_items` is the positional second segment):
 *
 *   `{default_title} - {refs:mc_related_items:meta:fw62_addr} - {refs:mc_related_items:meta:fw62_city}
 *    - {refs:mc_related_items:term:mc_topic} - {refs:mc_related_items:slug}
 *    {refs:mc_related_items:meta:fw62_arr}{refs:mc_related_items:refs:mc_related_items:slug}{refs:nope:meta:fw62_addr}`
 *
 * Item A has a city and no address; item B has an address, a non-scalar meta and a
 * topic term. In the field's order [A, B]: the address and the term fall through to B,
 * the city and the slug come from A, and the trailing three tokens (non-scalar value,
 * nested prefix, unknown field) resolve empty and leave no separator.
 *
 * Then, in order: the source edit leaves the receiver STALE until the receiver is
 * saved; Apply to Existing Posts (`drain_post()` + `drain()`) recomputes it; a trashed
 * and then a deleted first item fall through; an empty field and a Post Object field
 * resolve as the ticket says.
 *
 * Both items, the receiver and the term are deleted and the fixture's rules restored
 * afterward, pass or fail. Check `wp post list --post_type=mc_item --fields=ID,post_name`
 * after (the seeded items are untouched: this sweep creates its own).
 *
 * Usage:  wp eval-file .../sweep-fw62-refs-tokens.php run
 * Prereq: mc-rules seeded (schema.php registers the ACF fields).
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;

$step = $args[0] ?? 'run';
if ( 'run' !== $step ) {
	WP_CLI::error( 'Only the "run" step exists.' );
}

$f = 'mc_related_items';
mc_isolate( array( 'title_slug_from_pattern' ) );
mc_write_rule_types( array( 'title_slug_from_pattern' => array( array(
	'enabled'       => true,
	'name'          => 'FW-62 02 refs tokens',
	'post_type'     => 'mc_section',
	'title_pattern' => "{default_title} - {refs:$f:meta:fw62_addr} - {refs:$f:meta:fw62_city} - {refs:$f:term:mc_topic} - {refs:$f:slug} {refs:$f:meta:fw62_arr}{refs:$f:refs:$f:slug}{refs:nope:meta:fw62_addr}",
) ) ) );

$ids     = array();
$term_id = 0;
$pass    = true;

// A save of the receiver: what the editor's Update does.
$save = static function ( $id ) {
	wp_update_post( array( 'ID' => $id ) );
	TermDispatcher::instance()->drain();
	clean_post_cache( $id );
	return get_post( $id )->post_title;
};

try {
	$term    = wp_insert_term( 'FW62 Harbor', 'mc_topic' );
	$term_id = (int) $term['term_id'];

	$a = wp_insert_post( array( 'post_type' => 'mc_item', 'post_status' => 'publish', 'post_title' => 'FW62 Item A', 'post_name' => 'fw62-item-a' ) );
	$b = wp_insert_post( array( 'post_type' => 'mc_item', 'post_status' => 'publish', 'post_title' => 'FW62 Item B', 'post_name' => 'fw62-item-b' ) );
	$r = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => 'FW62 Receiver', 'post_name' => 'fw62-receiver' ) );
	$ids = array( $a, $b, $r );

	update_post_meta( $a, 'fw62_city', 'Kingston' );
	update_post_meta( $b, 'fw62_addr', '9 Quay St' );
	update_post_meta( $b, 'fw62_arr', array( 'x', 'y' ) );
	wp_set_object_terms( $b, array( $term_id ), 'mc_topic' );
	update_field( $f, array( $a, $b ), $r );

	$pass = mc_assert( 'first-non-empty, in the field order',
		$save( $r ), 'FW62 Receiver - 9 Quay St - Kingston - FW62 Harbor - fw62-item-a' ) && $pass;

	// Pull-only: a source edit does not reach the receiver.
	update_post_meta( $b, 'fw62_addr', '1 New Rd' );
	TermDispatcher::instance()->drain();
	clean_post_cache( $r );
	$pass = mc_assert( 'source edit leaves the receiver stale', get_post( $r )->post_title, 'FW62 Receiver - 9 Quay St - Kingston - FW62 Harbor - fw62-item-a' ) && $pass;
	$pass = mc_assert( 'a receiver save recomputes it', $save( $r ), 'FW62 Receiver - 1 New Rd - Kingston - FW62 Harbor - fw62-item-a' ) && $pass;

	// Apply to Existing Posts: names the receiver and drains, never writes itself.
	update_post_meta( $b, 'fw62_addr', '2 Other Ave' );
	TermDispatcher::instance()->drain_post( $r );
	TermDispatcher::instance()->drain();
	clean_post_cache( $r );
	$pass = mc_assert( 'Apply to Existing Posts recomputes it', get_post( $r )->post_title, 'FW62 Receiver - 2 Other Ave - Kingston - FW62 Harbor - fw62-item-a' ) && $pass;

	// A trashed first item falls through; the city (A only) and A's slug go with it.
	wp_trash_post( $a );
	$pass = mc_assert( 'a trashed first item is skipped', $save( $r ), 'FW62 Receiver - 2 Other Ave - FW62 Harbor - fw62-item-b' ) && $pass;
	wp_delete_post( $a, true );
	$ids  = array( $b, $r );
	$pass = mc_assert( 'a deleted first item is skipped', $save( $r ), 'FW62 Receiver - 2 Other Ave - FW62 Harbor - fw62-item-b' ) && $pass;

	// An empty field resolves every refs token empty, no stray separators.
	update_field( $f, array(), $r );
	$pass = mc_assert( 'an empty field drops every token and separator', $save( $r ), 'FW62 Receiver' ) && $pass;

	// A Post Object field names one post, the same way.
	$po = mc_section_pattern_probe( $r, $b );
	$pass = mc_assert( 'a Post Object field reads its one post', $po, 'FW62 Receiver - 2 Other Ave' ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $term_id ) {
		wp_delete_term( $term_id, 'mc_topic' );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW62-REFS-TOKENS OK' : 'FW62-REFS-TOKENS FAIL' );
exit( $pass ? 0 : 1 );

/**
 * The receiver re-titled by a pattern that reads the Post Object field, with
 * `mc_primary_item` pointing at `$item`. Swaps the rule, saves, restores nothing
 * (the sweep's `finally` rewrites every rule).
 */
function mc_section_pattern_probe( $receiver, $item ) {
	mc_write_rule_types( array( 'title_slug_from_pattern' => array( array(
		'enabled'       => true,
		'name'          => 'FW-62 02 refs post object',
		'post_type'     => 'mc_section',
		'title_pattern' => '{default_title} - {refs:mc_primary_item:meta:fw62_addr}',
	) ) ) );
	update_field( 'mc_primary_item', $item, $receiver );
	wp_update_post( array( 'ID' => $receiver, 'post_title' => 'FW62 Receiver' ) );
	TermDispatcher::instance()->drain();
	clean_post_cache( $receiver );
	return get_post( $receiver )->post_title;
}
