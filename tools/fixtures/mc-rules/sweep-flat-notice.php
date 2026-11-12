<?php
/**
 * mc-rules — FW-51 ticket 06 behavior sweep: duplicate-name notice.
 *
 * Proves the notice on the Format & Transform tab: it lists same-name posts of a
 * flat type under different parents, goes away on a rename, is absent without a
 * flat row, and is not built outside the settings screen.
 *
 * SUBJECT: `mc_flat` (manifest v9). The duplicate pair (`mc-flat-notice-dup`)
 * is created and deleted HERE; fixture posts are never renamed.
 *
 * `FormatRulesConfig::section()` is called directly; the screen gate is
 * `$_GET['page']` + `is_admin()`, so the sweep sets the first and `set_current_screen`
 * the second.
 *
 * Usage (one run, cleans up after itself):
 *   wp eval-file .../sweep-flat-notice.php
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Admin\Config\FormatRulesConfig;

/** The notice field's HTML, or null when the section carries none. */
function mcnote_html() {
	foreach ( FormatRulesConfig::section()['fields'] as $f ) {
		if ( 'flat_duplicate_names' === $f['id'] ) {
			return $f['args']['content'];
		}
	}
	return null;
}

function mcnote_author( $flat ) {
	mc_write_rule_types( array(
		'title_slug_from_pattern' => array( array(
			'type' => 'title_slug_from_pattern', 'enabled' => true, 'name' => 'MC flat',
			'post_type' => 'mc_flat', 'flat_urls' => (bool) $flat,
		) ),
	) );
}

$parent  = mc_pid( 'flat-parent' );
$parent2 = mc_pid( 'flat-parent-2' );
if ( ! $parent2 ) {
	WP_CLI::error( 'mc_flat fixture posts missing — seed.php first (manifest v9).' );
}

$make = static fn( $under ) => wp_insert_post( array( 'post_type' => 'mc_flat', 'post_name' => 'mc-flat-notice-dup', 'post_title' => 'Notice dup', 'post_status' => 'publish', 'post_parent' => $under ) );
$a    = $make( $parent );
$b    = $make( $parent2 );

mcnote_author( true );
unset( $_GET['page'] );
mc_assert( '1 off the settings screen: not built', mcnote_html(), null );

wp_set_current_user( 1 ); // get_edit_post_link() is empty for a visitor who cannot edit.
$_GET['page'] = 'meta-conductor';
set_current_screen( 'toplevel_page_meta-conductor' );
$html = mcnote_html();
mc_assert( '2a notice renders for a flat type with a duplicate', is_string( $html ) && str_contains( $html, 'mc_flat/mc-flat-notice-dup' ), true );
mc_assert( '2b lists both posts with an edit link', substr_count( (string) $html, 'post=' ), 2 );
mc_assert( '2c names both parents', array( str_contains( (string) $html, get_the_title( $parent ) ), str_contains( (string) $html, get_the_title( $parent2 ) ) ), array( true, true ) );
mc_assert( '2d only the duplicate is listed (no fixture name)', str_contains( (string) $html, 'mc-flat-child' ), false );

wp_update_post( array( 'ID' => $b, 'post_name' => 'mc-flat-notice-renamed' ) );
mc_assert( '3 renaming one clears the notice', mcnote_html(), null );
wp_update_post( array( 'ID' => $b, 'post_name' => 'mc-flat-notice-dup' ) );

mcnote_author( false );
mc_assert( '4 no flat row: no notice', mcnote_html(), null );

wp_delete_post( $a, true );
wp_delete_post( $b, true );
mcnote_author( true );
mc_assert( '5 no duplicates: no notice', mcnote_html(), null );

mc_restore();
mc_assert( '6 no leftover', (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_name LIKE 'mc-flat-notice-%'" ), 0 );
WP_CLI::log( 'post names: ' . implode( ', ', array_map( static fn( $s ) => get_post_field( 'post_name', mc_pid( $s ) ), array( 'flat-parent', 'flat-child', 'flat-parent-2', 'flat-child-2' ) ) ) );
