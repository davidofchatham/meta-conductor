<?php
/**
 * Real-data confirmation for #42 / #43 — production staging clone.
 *
 * Subject: schedule #77740 (3 games), dependent #77745 (single source). The
 * ids are the clone's; titles are deliberately not recorded here.
 *
 * Rules live here are PUSH + keep_in_sync on athletics_schedule:schedule_games,
 * explicit reverse athletics_events:game_team_schedule_cpt, 3 taxonomies
 * (sport / teams / school_year). Both fields are ALSO ACF-bidirectional, so ACF
 * keeps the two sides consistent by itself.
 *
 * Steps: state | a | a2 | b1 | b2 | c | d1 | d2 | e | e2 | z  (z = restore)
 * Split across processes on purpose — the #42 flush runs on `shutdown`, so a
 * bare update_field() can only be asserted in a LATER request.
 *
 * Usage (from the container, in the clone's webroot):
 *   wp eval-file <path>/athletics-confirm-4243.php <step> --allow-root
 *
 * Step `c` needs an ADMIN context: AC Pro returns early on !is_admin() before
 * defining ACP_VERSION, so under a plain WP-CLI run the hook never registers
 * and the step fails misleadingly. Run it as:
 *   wp --exec="define('WP_ADMIN', true);" eval-file ... c --allow-root
 *
 * ALWAYS finish with step `z`. These steps mutate real cloned data.
 */

define( 'MC_CONFIRM_SCHEDULE', 77740 );
define( 'MC_CONFIRM_GAME', 77745 );

$TAXES = array( 'sport', 'teams', 'school_year' );
$step  = $args[0] ?? 'state';

$ok     = 0;
$failed = array();
$assert = function ( $cond, $msg ) use ( &$ok, &$failed ) {
	if ( $cond ) {
		++$ok;
		WP_CLI::log( '  PASS ' . $msg );
	} else {
		$failed[] = $msg;
		WP_CLI::warning( 'FAIL ' . $msg );
	}
};
$ids = function ( $v ) {
	return array_values( array_map( function ( $p ) {
		return is_object( $p ) ? (int) $p->ID : (int) $p;
	}, (array) $v ) );
};
$terms = function ( $id ) use ( $TAXES ) {
	$out = array();
	foreach ( $TAXES as $t ) {
		$n = wp_get_object_terms( $id, $t, array( 'fields' => 'names' ) );
		sort( $n );
		$out[ $t ] = is_array( $n ) ? $n : array();
	}
	return $out;
};
$dump = function ( $label ) use ( $terms, $ids ) {
	$s = MC_CONFIRM_SCHEDULE;
	$g = MC_CONFIRM_GAME;
	WP_CLI::log( sprintf( '  %s', $label ) );
	WP_CLI::log( sprintf( '    schedule #%d games=%s', $s, wp_json_encode( $ids( get_field( 'schedule_games', $s ) ) ) ) );
	WP_CLI::log( sprintf( '    game     #%d rev=%s', $g, wp_json_encode( $ids( get_field( 'game_team_schedule_cpt', $g ) ) ) ) );
	foreach ( $terms( $g ) as $tax => $names ) {
		WP_CLI::log( sprintf( '      %-12s [%s]', $tax, implode( ', ', $names ) ) );
	}
};
// Strip the dependent's terms in a way the plugin cannot immediately undo.
// A plain wp_set_object_terms( id, [] ) fires set_object_terms, and a handler
// cascade puts the terms straight back — which would make every "did the write
// queue reapply?" assertion below vacuous. Detach the term listeners for the
// clear only; the flush under test writes terms directly and is unaffected.
$hard_clear = function () use ( $TAXES ) {
	remove_all_actions( 'set_object_terms' );
	remove_all_actions( 'edited_term_taxonomy' );
	foreach ( $TAXES as $t ) {
		wp_set_object_terms( MC_CONFIRM_GAME, array(), $t );
	}
	clean_object_term_cache( MC_CONFIRM_GAME, 'athletics_events' );
	$left = array();
	foreach ( $TAXES as $t ) {
		$left = array_merge( $left, (array) wp_get_object_terms( MC_CONFIRM_GAME, $t, array( 'fields' => 'names' ) ) );
	}
	if ( $left ) {
		WP_CLI::error( 'hard_clear failed — terms still present: ' . wp_json_encode( $left ) );
	}
	WP_CLI::log( '    hard_clear OK — dependent has no terms in any of the 3 taxonomies' );
};

$editor_save = function ( $field, $value, $id ) {
	update_field( $field, $value, $id );
	do_action( 'acf/save_post', $id );
	wp_update_post( array( 'ID' => $id ) );
};

switch ( $step ) {

	// ---------------------------------------------------------------
	case 'state':
		$dump( 'current' );
		break;

	// ---- A. #43 dependent-end sever, real data ---------------------
	case 'a':
		WP_CLI::log( 'A — game drops its schedule from the reverse field (#43)' );
		$dump( 'before' );
		$before = $terms( MC_CONFIRM_GAME );
		$assert( ! empty( $before['sport'] ), 'game had sport terms before' );
		$assert( ! empty( $before['teams'] ), 'game had teams terms before' );

		$editor_save( 'game_team_schedule_cpt', array(), MC_CONFIRM_GAME );

		$dump( 'after' );
		$after = $terms( MC_CONFIRM_GAME );
		$assert( empty( $after['sport'] ), 'sport withdrawn' );
		$assert( empty( $after['teams'] ), 'teams withdrawn' );
		$assert( empty( $after['school_year'] ), 'school_year withdrawn' );

		// ACF bidi should have pulled the game out of the schedule too.
		$assert(
			! in_array( MC_CONFIRM_GAME, $ids( get_field( 'schedule_games', MC_CONFIRM_SCHEDULE ) ), true ),
			'ACF bidirectional removed the game from the schedule side'
		);
		break;

	// ---- A2. restore the link, terms come back ---------------------
	case 'a2':
		WP_CLI::log( 'A2 — restore the link from the game end' );
		$editor_save( 'game_team_schedule_cpt', array( MC_CONFIRM_SCHEDULE ), MC_CONFIRM_GAME );
		$dump( 'after restore' );
		$after = $terms( MC_CONFIRM_GAME );
		$assert( ! empty( $after['sport'] ), 'sport reapplied on re-link' );
		$assert( ! empty( $after['teams'] ), 'teams reapplied on re-link' );
		$assert(
			in_array( MC_CONFIRM_GAME, $ids( get_field( 'schedule_games', MC_CONFIRM_SCHEDULE ) ), true ),
			'game back on the schedule side'
		);
		break;

	// ---- B. #42 bare update_field, no post save --------------------
	// Strip the game's terms WITHOUT the plugin (direct term write, no ACF
	// field change), then touch the holder with a bare update_field only.
	case 'b1':
		WP_CLI::log( 'B1 — clear game terms directly, then bare update_field on the holder' );
		$hard_clear();
		// Bare write, same value: NO wp_update_post, NO acf/save_post.
		$games = $ids( get_field( 'schedule_games', MC_CONFIRM_SCHEDULE ) );
		update_field( 'schedule_games', $games, MC_CONFIRM_SCHEDULE );
		WP_CLI::log( '    bare update_field done — queue should flush on shutdown; assert in b2' );
		break;

	case 'b2':
		WP_CLI::log( 'B2 — assert the shutdown flush reapplied' );
		$dump( 'now' );
		$after = $terms( MC_CONFIRM_GAME );
		$assert( ! empty( $after['sport'] ), 'sport reapplied by the shutdown flush (#42)' );
		$assert( ! empty( $after['teams'] ), 'teams reapplied by the shutdown flush (#42)' );
		$assert( ! empty( $after['school_year'] ), 'school_year reapplied by the shutdown flush (#42)' );
		break;

	// ---- C. Admin Columns v7 path ----------------------------------
	case 'c':
		WP_CLI::log( 'C — Admin Columns v7 hook' );
		$assert( defined( 'ACP_VERSION' ), 'ACP_VERSION defined (AC Pro active): ' . ( defined( 'ACP_VERSION' ) ? ACP_VERSION : 'n/a' ) );
		$assert( has_action( 'ac/editing/saved' ) !== false, 'ac/editing/saved listener registered' );
		$assert( class_exists( '\AC\Column\CustomFieldContext' ), 'AC\Column\CustomFieldContext exists' );

		// Clear terms, then drive the AC path: update_field (as AC's storage
		// does) followed by AC's own saved signal, which must flush IMMEDIATELY
		// — before shutdown — so the inline-edit response is accurate.
		$hard_clear();

		$games = $ids( get_field( 'schedule_games', MC_CONFIRM_SCHEDULE ) );
		update_field( 'schedule_games', $games, MC_CONFIRM_SCHEDULE );

		$column = new class() extends \AC\Column\CustomFieldContext {
			public function __construct() {}
		};
		do_action( 'ac/editing/saved', $column, MC_CONFIRM_SCHEDULE, $games, null );

		$after = $terms( MC_CONFIRM_GAME );
		WP_CLI::log( '    terms right after the AC signal (NOT at shutdown): ' . wp_json_encode( $after ) );
		$assert( ! empty( $after['sport'] ), 'AC signal flushed immediately, same request (#37 timing preserved)' );
		break;

	// ---- D. REST write ---------------------------------------------
	case 'd1':
		WP_CLI::log( 'D1 — REST write to the ACF field' );
		$hard_clear();

		$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) ( $admin[0] ?? 1 ) );

		$games = $ids( get_field( 'schedule_games', MC_CONFIRM_SCHEDULE ) );
		$req   = new WP_REST_Request( 'POST', '/wp/v2/athletics_schedule/' . MC_CONFIRM_SCHEDULE );
		$req->set_body_params( array( 'acf' => array( 'schedule_games' => $games ) ) );
		$res = rest_do_request( $req );
		WP_CLI::log( '    REST status ' . $res->get_status() );
		$assert( ! $res->is_error(), 'REST request succeeded' );
		WP_CLI::log( '    terms in-request (expected still empty — flush is on shutdown): ' . wp_json_encode( $terms( MC_CONFIRM_GAME ) ) );
		break;

	case 'd2':
		WP_CLI::log( 'D2 — assert the REST write applied after the request ended' );
		$after = $terms( MC_CONFIRM_GAME );
		WP_CLI::log( '    ' . wp_json_encode( $after ) );
		$assert( ! empty( $after['sport'] ), 'REST ACF write applied terms (#42)' );
		break;

	// ---- E. import suppression -------------------------------------
	case 'e':
		WP_CLI::log( 'E — WP_IMPORTING suppression' );
		$hard_clear();

		define( 'WP_IMPORTING', true );
		$games = $ids( get_field( 'schedule_games', MC_CONFIRM_SCHEDULE ) );
		update_field( 'schedule_games', $games, MC_CONFIRM_SCHEDULE );
		WP_CLI::log( '    bare update_field under WP_IMPORTING — assert in e2 that NOTHING applied' );
		break;

	case 'e2':
		$after = $terms( MC_CONFIRM_GAME );
		WP_CLI::log( '    ' . wp_json_encode( $after ) );
		$assert( empty( $after['sport'] ), 'import write did NOT trigger a recompute (#42 suppression)' );
		break;

	// ---- Z. restore -------------------------------------------------
	case 'z':
		WP_CLI::log( 'Z — restore the subject to its baseline' );
		$editor_save( 'game_team_schedule_cpt', array( MC_CONFIRM_SCHEDULE ), MC_CONFIRM_GAME );
		$editor_save( 'schedule_games', array( 77741, 77743, 77745 ), MC_CONFIRM_SCHEDULE );
		$dump( 'restored' );
		$after = $terms( MC_CONFIRM_GAME );
		$assert( ! empty( $after['sport'] ), 'sport restored' );
		$assert( ! empty( $after['teams'] ), 'teams restored' );
		$assert( ! empty( $after['school_year'] ), 'school_year restored' );
		$assert( $ids( get_field( 'schedule_games', MC_CONFIRM_SCHEDULE ) ) === array( 77741, 77743, 77745 ), 'schedule games restored' );
		break;
}

if ( $failed ) {
	WP_CLI::error( sprintf( '%s: %d passed, %d FAILED', $step, $ok, count( $failed ) ) );
}
WP_CLI::success( sprintf( '%s: %d assertions passed', $step, $ok ) );
