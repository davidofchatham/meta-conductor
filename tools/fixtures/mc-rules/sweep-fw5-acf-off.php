<?php
/**
 * mc-rules — FW-5 ACF off: *Set a field from a related post* while ACF is not
 * loaded. A REAL pass, not a stub: run with ACF (and its add-ons) skipped, so
 * `acf_get_field()` genuinely does not exist.
 *
 *   A  acf row        a stored `acf` relationship row runs nothing; the child's own
 *                     value is kept under Owning and the row's settings survive.
 *   B  ACF field      a `parent` row naming an ACF field as source is idle too.
 *   C  plain meta     a `parent` row copying plain meta to plain meta still runs.
 *   D  type kept      the type is offered, so a save keeps a stored row's type (the
 *                     `type` select drops a value that is not among its options).
 *
 * Throwaway `mc_section` posts, deleted after; the fixture's rules are restored.
 *
 * Usage (the skip list is the point):
 *   wp eval-file .../sweep-fw5-acf-off.php run --skip-plugins=advanced-custom-fields-pro,acf-extended,acf-quickedit-fields
 * Prereq: mc-rules seeded. Reactivation is not needed: `--skip-plugins` is per call.
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Admin\Config\TermRulesConfig;
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;
use Wireframe\Framework\Fields\RepeaterField;

if ( function_exists( 'acf_get_field' ) ) {
	WP_CLI::error( 'ACF is loaded. Run with --skip-plugins=advanced-custom-fields-pro,acf-extended,acf-quickedit-fields.' );
}

function mcoff_copy( array $over ) {
	return array_merge( array(
		'type'              => 'field_from_related_post',
		'enabled'           => true,
		'name'              => 'FW-5 ACF off',
		'post_types'        => array( 'mc_section' ),
		'relationship'      => 'parent',
		'source_field'      => '@meta',
		'source_meta'       => 'mc_off_src',
		'copy_target'       => '@meta',
		'target_meta'       => 'mc_off_dst',
		'conflict_handling' => 'replace',
		'post_status'       => array(),
	), $over );
}

function mcoff_save( $id ) {
	wp_update_post( array( 'ID' => $id ) );
	TermDispatcher::instance()->drain();
}

function mcoff_meta( $id, $name ) {
	clean_post_cache( $id );
	return get_post_meta( $id, $name, true );
}

mc_isolate( array() );
mc_write_split_rules( array() );

$ids  = array();
$pass = true;

try {
	$p   = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => 'FW5 Off Parent' ) );
	$c   = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => 'FW5 Off Child', 'post_parent' => $p ) );
	$ids = array( $p, $c );
	update_post_meta( $p, 'mc_off_src', 'from-parent' );
	update_post_meta( $p, 'mc_cp_text', 'acf-source' );
	update_post_meta( $c, 'mc_off_dst', 'own' );
	update_post_meta( $c, 'mc_cp_text', 'own' );

	// --- A. a stored acf row is idle, its settings kept ----------------------
	$acf_row = mcoff_copy( array(
		'relationship'     => 'acf',
		'acf_field_name'   => 'mc_section:mc_cp_list:field_mc_cp_list',
		'holder_role'      => 'target',
		'source_field'     => 'field_mc_cp_text',
		'copy_target'      => '',
	) );
	mc_write_split_rules( array( $acf_row ) );
	mcoff_save( $c );
	mcoff_save( $p );
	$pass = mc_assert( 'A an acf row copies nothing with ACF off (Owning keeps the child\'s value)', mcoff_meta( $c, 'mc_cp_text' ), 'own' ) && $pass;
	$stored = StorageFactory::get_instance()->get_kind_rules( OptionRuleStorage::KIND_TERM )[0] ?? array();
	$pass   = mc_assert( 'A …and the stored row keeps its settings', array( $stored['relationship'] ?? '', $stored['source_field'] ?? '' ), array( 'acf', 'field_mc_cp_text' ) ) && $pass;

	// --- B. a parent row naming an ACF field is idle -------------------------
	mc_write_split_rules( array( mcoff_copy( array( 'source_field' => 'field_mc_cp_text', 'copy_target' => '' ) ) ) );
	mcoff_save( $c );
	$pass = mc_assert( 'B a parent row with an ACF field source copies nothing with ACF off', mcoff_meta( $c, 'mc_cp_text' ), 'own' ) && $pass;

	// --- C. plain meta to plain meta still runs ------------------------------
	mc_write_split_rules( array( mcoff_copy( array() ) ) );
	mcoff_save( $c );
	$pass = mc_assert( 'C a parent row on plain meta copies with ACF off', mcoff_meta( $c, 'mc_off_dst' ), 'from-parent' ) && $pass;
	update_post_meta( $p, 'mc_off_src', 'edited' );
	mcoff_save( $p );
	$pass = mc_assert( 'C …and a parent edit reaches the child', mcoff_meta( $c, 'mc_off_dst' ), 'edited' ) && $pass;

	// --- D. a save keeps the stored row's type -------------------------------
	// The options come from what is stored, so store the rows the save carries.
	$tgt_row = mcoff_copy( array( 'copy_target' => 'field_mc_cp_display' ) );
	mc_write_split_rules( array( $acf_row, $tgt_row ) );
	$field = current( array_filter( TermRulesConfig::section()['fields'], static fn( $f ) => ( $f['id'] ?? '' ) === 'term_rules' ) );
	$out   = RepeaterField::sanitize( array( $acf_row + array( 'row_title' => 'x' ) ), $field['args'] )[0] ?? array();
	$pass  = mc_assert( 'D a stored acf row keeps its type and relationship through a save', array( $out['type'] ?? '(DROPPED)', $out['relationship'] ?? '(DROPPED)' ), array( 'field_from_related_post', 'acf' ) ) && $pass;
	// Every ACF value is kept as itself: the pickers list nothing while ACF is
	// off, so a save would otherwise gut a row that is only idle.
	foreach ( array( 'acf_field_name', 'source_field' ) as $key ) {
		$pass = mc_assert( "D a save with ACF off keeps the stored '$key'", $out[ $key ] ?? '(DROPPED)', $acf_row[ $key ] ) && $pass;
	}
	$pass = mc_assert( 'D …and a stored ACF copy target', RepeaterField::sanitize( array( $tgt_row + array( 'row_title' => 'x' ) ), $field['args'] )[0]['copy_target'] ?? '(DROPPED)', 'field_mc_cp_display' ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}

WP_CLI::log( $pass ? 'FW5-ACF-OFF OK' : 'FW5-ACF-OFF FAIL' );
exit( $pass ? 0 : 1 );
