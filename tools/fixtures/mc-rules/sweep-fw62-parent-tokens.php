<?php
/**
 * mc-rules — FW-62 ticket 01 end-to-end: `{parent:<inner>}` through a real
 * title/slug rule on a real parent/child pair.
 *
 * Creates a parent and a child `mc_section` post (hierarchical) under one title
 * rule:
 *
 *   `{default_title} - {parent:meta:fw62_addr} - {parent:term:mc_topic} - {parent:pub_year} {parent:parent:meta:fw62_addr}{parent:meta:fw62_arr}{slug}`
 *
 * The child must carry the parent's stored meta, first term and publish year.
 * Everything after the year must resolve empty and leave no separator or literal
 * token behind: a nested prefix, a non-scalar parent value, and a bare `{slug}`
 * on the post's own pass. The parent (no parent of its own) drops every
 * `{parent:…}` token and its separators.
 *
 * Reads are STORED values: the parent's meta and term are written before the
 * child is inserted, and the child's title is asserted after `drain()`.
 *
 * Both posts and the term are deleted and the fixture's rules restored
 * afterward, pass or fail. Check `wp post list --post_type=mc_item
 * --fields=ID,post_name` after (it is untouched: this sweep only writes
 * `mc_section`).
 *
 * Usage:  wp eval-file .../sweep-fw62-parent-tokens.php run
 * Prereq: mc-rules seeded.
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;

$step = $args[0] ?? 'run';
if ( 'run' !== $step ) {
	WP_CLI::error( 'Only the "run" step exists.' );
}

mc_isolate( array( 'title_slug_from_pattern' ) );
mc_write_rule_types( array( 'title_slug_from_pattern' => array( array(
	'enabled'       => true,
	'name'          => 'FW-62 01 parent tokens',
	'post_type'     => 'mc_section',
	'title_pattern' => '{default_title} - {parent:meta:fw62_addr} - {parent:term:mc_topic} - {parent:pub_year} {parent:parent:meta:fw62_addr}{parent:meta:fw62_arr}{slug}',
) ) ) );

$ids     = array();
$term_id = 0;
$pass    = true;

try {
	$term    = wp_insert_term( 'FW62 Harbor', 'mc_topic' );
	$term_id = (int) $term['term_id'];

	$parent_id = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => 'FW62 Parent', 'post_name' => 'fw62-parent' ) );
	$ids[]     = $parent_id;
	update_post_meta( $parent_id, 'fw62_addr', '12 Quay St' );
	update_post_meta( $parent_id, 'fw62_arr', array( 'a', 'b' ) );
	wp_set_object_terms( $parent_id, array( $term_id ), 'mc_topic' );
	$parent_year = substr( get_post( $parent_id )->post_date, 0, 4 );

	$child_id = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => 'FW62 Child', 'post_name' => 'fw62-child', 'post_parent' => $parent_id ) );
	$ids[]    = $child_id;
	TermDispatcher::instance()->drain();

	clean_post_cache( $parent_id );
	clean_post_cache( $child_id );

	$pass = mc_assert( 'child title reads the parent', get_post( $child_id )->post_title, "FW62 Child - 12 Quay St - FW62 Harbor - $parent_year" ) && $pass;
	$pass = mc_assert( 'parent title (no parent: tokens and separators dropped)', get_post( $parent_id )->post_title, 'FW62 Parent' ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $term_id ) {
		wp_delete_term( $term_id, 'mc_topic' );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW62-PARENT-TOKENS OK' : 'FW62-PARENT-TOKENS FAIL' );
exit( $pass ? 0 : 1 );
