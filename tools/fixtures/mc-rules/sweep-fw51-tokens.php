<?php
/**
 * mc-rules — FW-51 ticket 01 end-to-end: `{mod_*}` and `{parent:slug}` through a
 * real title/slug rule on a real parent/child pair.
 *
 * Creates a parent and a child `mc_section` post (hierarchical), under one
 * title/slug rule:
 *
 *   title  `{default_title} {mod_year} {parent:slug}`
 *   slug   `{parent:slug}-y{mod_year}`  (replace mode; the "y" keeps the slug off a bare year, which WP refuses for hierarchical types)
 *
 * Order assumption: the rule also renames the parent (to "y<year>"), and the
 * parent is inserted and passed first, so the child reads that renamed slug.
 *
 * The child must read its parent's slug and its own modified year; the parent
 * (no parent of its own) must drop `{parent:slug}` and its separator. Both posts
 * are deleted and the fixture's rules restored afterward, pass or fail.
 *
 * Usage:  wp eval-file .../sweep-fw51-tokens.php run
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
	'name'          => 'FW-51 01 tokens',
	'post_type'     => 'mc_section',
	'title_pattern' => '{default_title} {mod_year} {parent:slug}',
	'slug_pattern'  => '{parent:slug}-y{mod_year}',
	'slug_mode'     => 'replace',
) ) ) );

$ids  = array();
$pass = true;

try {
	$parent_id = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => 'FW51 Parent', 'post_name' => 'fw51-parent' ) );
	$ids[]     = $parent_id;
	$child_id  = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => 'FW51 Child', 'post_name' => 'fw51-child', 'post_parent' => $parent_id ) );
	$ids[]     = $child_id;
	TermDispatcher::instance()->drain();

	clean_post_cache( $parent_id );
	clean_post_cache( $child_id );
	$parent = get_post( $parent_id );
	$child  = get_post( $child_id );
	$year   = wp_date( 'Y', strtotime( $child->post_modified . ' ' . wp_timezone_string() ) );

	$pass = mc_assert( 'child title', $child->post_title, "FW51 Child $year y$year" ) && $pass;
	$pass = mc_assert( 'child slug', $child->post_name, "y$year-y$year" ) && $pass;
	$pass = mc_assert( 'parent title (no parent: token + separator dropped)', $parent->post_title, "FW51 Parent $year" ) && $pass;
	$pass = mc_assert( 'parent slug', $parent->post_name, "y$year" ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW51-TOKENS OK' : 'FW51-TOKENS FAIL' );
exit( $pass ? 0 : 1 );
