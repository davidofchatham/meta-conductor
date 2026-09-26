<?php
/**
 * Pre-0.8.0 guard sweep (FW-39).
 *
 * The migration off the type-keyed rule arrays is deleted, so a site that
 * jumps straight from a pre-0.8.0 version runs no rules; the admin notice in
 * meta-conductor.php is what tells its author. This drives the REAL
 * `admin_notices` callback against three stored shapes:
 *
 *   §guard-a  legacy ROWS, no kind lists      → notice
 *   §guard-b  EMPTY legacy arrays, no lists   → no notice (nothing to lose)
 *   §guard-c  the current fixture             → no notice
 *
 * The settings option is snapshotted first and written back in `finally`, so
 * the fixture is untouched whatever happens.
 *
 * Run: wp eval-file <mount>/tools/fixtures/mc-rules/sweep-pre08-guard.php --allow-root
 *
 * @package Meta_Conductor
 */

use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

$opt      = OptionRuleStorage::OPTION_NAME;
$snapshot = get_option( $opt );
$fail     = 0;

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admin[0] ?? 0 ) );

/** Render every admin notice against a stored option; true if ours shows. */
$notice_for = static function ( array $stored ) use ( $opt ): bool {
	update_option( $opt, $stored );
	StorageFactory::get_instance()->clear_cache();
	ob_start();
	do_action( 'admin_notices' );
	return str_contains( (string) ob_get_clean(), 'older than 0.8.0' );
};

$check = static function ( string $name, bool $cond ) use ( &$fail ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$fail += $cond ? 0 : 1;
};

try {
	$check( 'the sweep runs as a user who may see the notice', current_user_can( 'manage_options' ) );

	$check( '§guard-a legacy rows with no kind list raise the notice', $notice_for( array(
		'hierarchical_rules' => array( array( 'taxonomy' => 'category', 'enabled' => true ) ),
		'time_based_rules'   => array(),
	) ) );

	$check( '§guard-b empty legacy arrays with no kind list stay quiet', ! $notice_for( array(
		'hierarchical_rules' => array(),
		'time_based_rules'   => array(),
		'title_slug_rules'   => array(),
	) ) );

	$check( '§guard-c the current fixture stays quiet', ! $notice_for( is_array( $snapshot ) ? $snapshot : array() ) );
} finally {
	update_option( $opt, $snapshot );
	StorageFactory::get_instance()->clear_cache();
}

$check( 'settings option restored to its pre-sweep state', get_option( $opt ) === $snapshot );

echo $fail ? "\nSWEEP-PRE08-GUARD FAIL ($fail)\n" : "\nSWEEP-PRE08-GUARD OK\n";
