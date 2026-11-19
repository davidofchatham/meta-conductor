<?php
/**
 * Run several steps of ONE sweep file from a single WordPress bootstrap.
 *
 *   wp eval-file .../run-sweep.php sweep-term-count.php flat tree-on bulk restore --allow-root
 *
 * WP-CLI spends about three seconds loading the site before a sweep step runs a
 * line, and most steps take less than one. Booting once and FORKING per step keeps
 * what a separate `wp eval-file` gives each step: its own request. Memos, the
 * dispatcher queue, the object cache and the `shutdown` flush start from the
 * bootstrapped state and die with the child. Nothing is shared between steps but
 * the database, exactly as before. Do not run the steps in-process: several
 * sweeps say "one scenario per eval" for that reason.
 *
 * Each child opens its own database connection and leaves through
 * pcntl_exec(), never a normal exit. A normal exit would close the connection
 * the parent still holds (same socket) and break the next step.
 *
 * A sweep file with no steps takes `-` in place of a step name.
 *
 * Exit status is nonzero if any step failed or died, so it drops into the same
 * checks as a single eval-file.
 *
 * @package Meta_Conductor
 */

if ( ! function_exists( 'pcntl_fork' ) ) {
	WP_CLI::error( 'run-sweep.php needs the pcntl extension; run the steps one eval-file at a time.' );
}

/** Records whether the step called WP_CLI::error(), which exits without a return value. */
class Mc_Run_Sweep_Logger extends \WP_CLI\Loggers\Regular {
	public static $errored = false;

	public function error( $message, $label = 'Error' ) {
		self::$errored = true;
		parent::error( $message, $label );
	}
}

$file  = $args[0] ?? '';
$steps = array_slice( $args, 1 );
$path  = __DIR__ . '/' . basename( $file );
if ( '' === $file || ! is_file( $path ) || ! $steps ) {
	WP_CLI::error( 'Usage: run-sweep.php <sweep-file.php> <step> [<step>...]' );
}

$failed = array();
foreach ( $steps as $step ) {
	WP_CLI::log( "== {$file} [{$step}]" );
	$mark = sys_get_temp_dir() . '/mc-run-sweep-' . getmypid() . '-' . $step;
	@unlink( $mark );

	$pid = pcntl_fork();
	if ( -1 === $pid ) {
		WP_CLI::error( 'fork failed' );
	}

	if ( 0 === $pid ) {
		global $wpdb;
		// Keep the inherited handle alive (destroying it would close the parent's
		// socket) and take a connection of our own.
		$GLOBALS['mc_run_sweep_inherited'] = $wpdb->dbh;
		$wpdb->dbh                         = null;
		$wpdb->ready                       = false;
		$wpdb->db_connect( false );

		// The parent booted before the earlier steps wrote their rules and fields,
		// so what it cached (autoloaded options above all) is stale for this step.
		wp_cache_flush();

		WP_CLI::set_logger( new Mc_Run_Sweep_Logger( false ) );
		register_shutdown_function(
			function () use ( $mark ) {
				file_put_contents( $mark, Mc_Run_Sweep_Logger::$errored ? 'fail' : 'ok' );
				pcntl_exec( '/bin/true' );
			}
		);

		$run = function ( $sweep_path, $sweep_args ) {
			$args = $sweep_args;
			include $sweep_path;
		};
		$run( $path, '-' === $step ? array() : array( $step ) );
		exit( 0 );
	}

	pcntl_waitpid( $pid, $status );
	$result = is_file( $mark ) ? trim( (string) file_get_contents( $mark ) ) : 'died';
	@unlink( $mark );
	if ( 'ok' !== $result ) {
		$failed[] = "{$step} ({$result})";
	}
}

if ( $failed ) {
	WP_CLI::error( 'Failed: ' . implode( ', ', $failed ) );
}
WP_CLI::success( sprintf( '%s: %d step(s) passed', $file, count( $steps ) ) );
