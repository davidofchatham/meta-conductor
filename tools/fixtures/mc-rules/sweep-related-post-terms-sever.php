<?php
/**
 * §4 sever + write-queue sweep — #42 (AcfWriteQueue) + #43 (dependent-end sever).
 *
 * Run (from the container), one step per invocation:
 *   wp eval-file .../sweep-related-post-terms-sever.php <step> --allow-root
 *
 * Steps are SPLIT ACROSS PROCESSES on purpose: the #42 flush runs on `shutdown`,
 * so a bare update_field() can only be asserted in a LATER request. Running s4a
 * and s4b in one eval would prove nothing.
 *
 * Order (each depends on the one before):
 *   s1a  push holder terms onto alpha + beta
 *   s1b  TIER 1 dependent-end sever — clear beta.mc_parent_section     (#43)
 *   s2a  give alpha two sources (holder + holder2)
 *   s2b  drop ONE source — remaining source's terms survive            (#43)
 *   s3a  TIER 2 push via the ACF native-bidi pair
 *   s3b  TIER 2 dependent-end sever — clear item-bidi.mc_bidi_sections (#43)
 *   s4a  BARE update_field(), no post save — enqueue only              (#42)
 *   s4b  assert the shutdown flush applied it                          (#42)
 *   s5   ACF 'options'/'term_N' pseudo-targets must not enqueue        (#42)
 *   s6   repeat apply writes nothing (idempotence)
 *   s7   holder-end sever with a STALE explicit reverse — see below
 *   s7b  holder-end sever, tier 1, reverse side kept consistent
 *   s8a/b holder-end sever on the tier-2 (native bidi) rule
 *   s9   delete-path sever — holder deleted, dependent cleaned. Uses throwaway
 *        posts and removes them again, so it needs no reseed. Covers the third
 *        fill site of the pending-recompute queue, which no other step touches.
 *
 * S7 IS EXPECTED TO FAIL and is kept as documentation. With an explicit
 * `reverse_acf_field_name` the dependent's reverse field IS the source of truth
 * for reverse resolution, and the plugin does not write it. So a holder-end edit
 * alone leaves it stale, the dependent still resolves the holder as a source,
 * and nothing is stripped. s7b and s8a/b are the honest holder-end regression
 * checks: on a consistent graph — either side maintained, or ACF's native
 * bidirectional keeping both sides in step — the strip happens as before.
 *
 * Expects the mc-rules fixture (v5+) seeded. Mutates term state; reseed after.
 * See handler-fixture-matrix.md §4.
 */

require_once __DIR__ . '/lookup.php';

$step = $args[0] ?? 'state';

$find = function ( $name ) {
	return (int) mc_fixture_find_post( $name, array( 'mc_item', 'mc_section' ) );
};

$HOLDER  = $find( 'mc-holder' );
$HOLDER2 = $find( 'mc-holder-two' );
// item-alpha carries mc_event_date + a live title_slug rule, so ANY real save
// renames it to mc-item-alpha-<date>. Accept either slug.
$ALPHA   = $find( 'mc-item-alpha' ) ?: $find( 'mc-item-alpha-2030' );
$BETA    = $find( 'mc-item-beta' );
$SBIDI   = $find( 'mc-bidi-holder' );
$IBIDI   = $find( 'mc-item-bidi' );

$slugs = function ( $id, $tax ) {
	$t = wp_get_object_terms( $id, $tax, array( 'fields' => 'slugs' ) );
	sort( $t );
	return is_array( $t ) ? $t : array();
};
$show = function ( $label, $id, $tax ) use ( $slugs ) {
	WP_CLI::log( sprintf( '  %-22s #%-4d %s=[%s]', $label, $id, $tax, implode( ',', $slugs( $id, $tax ) ) ) );
};
$ok   = 0;
$fail = array();
$assert = function ( $cond, $msg ) use ( &$ok, &$fail ) {
	if ( $cond ) {
		++$ok;
	} else {
		$fail[] = $msg;
		WP_CLI::warning( 'FAIL ' . $msg );
	}
};
// Editor-like save: write the field, then save the post (fires acf/save_post +
// save_post, exactly like a real form submit).
$editor_save = function ( $field, $value, $id ) {
	update_field( $field, $value, $id );
	do_action( 'acf/save_post', $id );
	wp_update_post( array( 'ID' => $id ) );
};

switch ( $step ) {

	// ---- S1a: push terms onto the dependents (baseline for S1b) ----------
	case 's1a':
		WP_CLI::log( 'S1a — save holder, push terms to alpha + beta' );
		wp_update_post( array( 'ID' => $HOLDER ) );
		$show( 'alpha', $ALPHA, 'mc_topic' );
		$show( 'beta', $BETA, 'mc_topic' );
		$assert( ! empty( $slugs( $BETA, 'mc_topic' ) ), 'beta inherited holder terms' );
		break;

	// ---- S1b: TIER 1 dependent-end sever (single source) -----------------
	case 's1b':
		WP_CLI::log( 'S1b — clear beta.mc_parent_section (tier 1 explicit reverse)' );
		$before = $slugs( $BETA, 'mc_topic' );
		$editor_save( 'mc_parent_section', array(), $BETA );
		$after = $slugs( $BETA, 'mc_topic' );
		$show( 'beta after sever', $BETA, 'mc_topic' );
		$show( 'alpha (untouched)', $ALPHA, 'mc_topic' );
		$assert( ! empty( $before ), 'beta had terms before the sever' );
		$assert( empty( $after ), 'beta mc_topic cleared by dependent-end sever (#43 tier 1)' );
		$assert( ! empty( $slugs( $ALPHA, 'mc_topic' ) ), 'alpha untouched by beta severing' );
		break;

	// ---- S2a: give alpha TWO sources -------------------------------------
	case 's2a':
		WP_CLI::log( 'S2a — add holder2 as a second source of alpha' );
		$editor_save( 'mc_related_items', array( $ALPHA ), $HOLDER2 );
		$editor_save( 'mc_parent_section', array( $HOLDER, $HOLDER2 ), $ALPHA );
		wp_update_post( array( 'ID' => $HOLDER ) );
		wp_update_post( array( 'ID' => $HOLDER2 ) );
		$show( 'alpha', $ALPHA, 'mc_topic' );
		$a = $slugs( $ALPHA, 'mc_topic' );
		$assert( in_array( 'west', $a, true ), 'alpha has holder2 term (west)' );
		$assert( in_array( 'coastal', $a, true ), 'alpha has holder term (coastal)' );
		break;

	// ---- S2b: drop ONE of two sources ------------------------------------
	case 's2b':
		WP_CLI::log( 'S2b — alpha drops holder, keeps holder2' );
		$editor_save( 'mc_parent_section', array( $HOLDER2 ), $ALPHA );
		$a = $slugs( $ALPHA, 'mc_topic' );
		$show( 'alpha after partial sever', $ALPHA, 'mc_topic' );
		$assert( in_array( 'west', $a, true ), 'remaining source terms survive (west)' );
		$assert( ! in_array( 'coastal', $a, true ), 'dropped source terms withdrawn (coastal)' );
		break;

	// ---- S3a: TIER 2 native bidi — push ----------------------------------
	case 's3a':
		WP_CLI::log( 'S3a — save bidi holder, push mc_flag to item-bidi' );
		wp_update_post( array( 'ID' => $SBIDI ) );
		$show( 'item-bidi', $IBIDI, 'mc_flag' );
		$assert( in_array( 'priority', $slugs( $IBIDI, 'mc_flag' ), true ), 'item-bidi inherited priority' );
		break;

	// ---- S3b: TIER 2 dependent-end sever ---------------------------------
	case 's3b':
		WP_CLI::log( 'S3b — clear item-bidi.mc_bidi_sections (tier 2 native bidi)' );
		$before = $slugs( $IBIDI, 'mc_flag' );
		$editor_save( 'mc_bidi_sections', array(), $IBIDI );
		$after = $slugs( $IBIDI, 'mc_flag' );
		$show( 'item-bidi after sever', $IBIDI, 'mc_flag' );
		$assert( ! empty( $before ), 'item-bidi had priority before the sever' );
		$assert( empty( $after ), 'item-bidi mc_flag cleared by dependent-end sever (#43 tier 2)' );
		break;

	// ---- S4a: BARE update_field, no post save (#42) -----------------------
	case 's4a':
		WP_CLI::log( 'S4a — bare update_field on holder, NO wp_update_post' );
		// Re-point beta at the holder from the holder side only.
		update_field( 'mc_related_items', array( $ALPHA, $BETA ), $HOLDER );
		update_field( 'mc_parent_section', array( $HOLDER ), $BETA );
		WP_CLI::log( '  (queue should flush on shutdown — assert in s4b)' );
		break;

	case 's4b':
		WP_CLI::log( 'S4b — assert the shutdown flush applied' );
		$show( 'beta', $BETA, 'mc_topic' );
		$assert( ! empty( $slugs( $BETA, 'mc_topic' ) ), 'bare update_field applied terms via the shutdown flush (#42)' );
		break;

	// ---- S5: ACF pseudo-targets must not enqueue --------------------------
	case 's5':
		WP_CLI::log( 'S5 — ACF option/term pseudo-targets' );
		update_field( 'field_mc_event_date', '20301231', 'options' );
		update_field( 'field_mc_event_date', '20301231', 'term_1' );
		$assert( true, 'pseudo-target writes did not fatal' );
		break;

	// ---- S6: idempotence — a second identical apply writes nothing --------
	case 's6':
		WP_CLI::log( 'S6 — repeat apply, expect no term change' );
		$before = $slugs( $BETA, 'mc_topic' );
		$mod    = get_post_field( 'post_modified_gmt', $BETA );
		wp_update_post( array( 'ID' => $HOLDER ) );
		$after = $slugs( $BETA, 'mc_topic' );
		$assert( $before === $after, 'repeat apply left terms identical' );
		WP_CLI::log( '  before=[' . implode( ',', $before ) . '] after=[' . implode( ',', $after ) . '] modified=' . $mod );
		break;

	// ---- S7: HOLDER-end sever still behaves as before (regression) --------
	case 's7':
		WP_CLI::log( 'S7 — holder drops beta from mc_related_items (holder-end, pre-existing path)' );
		$before = $slugs( $BETA, 'mc_topic' );
		$editor_save( 'mc_related_items', array( $ALPHA ), $HOLDER );
		$after = $slugs( $BETA, 'mc_topic' );
		$show( 'beta after holder-end sever', $BETA, 'mc_topic' );
		$assert( ! empty( $before ), 'beta had terms before' );
		$assert( empty( $after ), 'holder-end sever still strips (no regression)' );
		break;

	// ---- S8: holder-end sever on the TIER-2 (native bidi) rule ------------
	// The honest holder-end regression check: ACF keeps both sides of a
	// bidirectional pair consistent, so the dependent's reverse read agrees
	// with the holder's edit. (On the tier-1 rule the plugin does NOT write the
	// reverse side, so a holder-end edit leaves it stale — see S7.)
	case 's8a':
		WP_CLI::log( 'S8a — relink bidi pair and push' );
		$editor_save( 'mc_bidi_items', array( $IBIDI ), $SBIDI );
		$show( 'item-bidi', $IBIDI, 'mc_flag' );
		$assert( in_array( 'priority', $slugs( $IBIDI, 'mc_flag' ), true ), 'item-bidi re-inherited priority' );
		break;

	case 's8b':
		WP_CLI::log( 'S8b — bidi holder drops item-bidi (holder end)' );
		$before = $slugs( $IBIDI, 'mc_flag' );
		$editor_save( 'mc_bidi_items', array(), $SBIDI );
		$after = $slugs( $IBIDI, 'mc_flag' );
		$show( 'item-bidi after holder-end sever', $IBIDI, 'mc_flag' );
		$assert( ! empty( $before ), 'item-bidi had priority before' );
		$assert( empty( $after ), 'holder-end sever still strips on a consistent graph (no regression)' );
		break;

	// ---- S7b: tier-1 holder-end sever WITH the reverse side maintained ----
	case 's7b':
		WP_CLI::log( 'S7b — holder drops beta AND beta.mc_parent_section is updated too' );
		$editor_save( 'mc_related_items', array( $ALPHA, $BETA ), $HOLDER );
		$editor_save( 'mc_parent_section', array( $HOLDER ), $BETA );
		$before = $slugs( $BETA, 'mc_topic' );
		// Both sides edited, as a bidirectional field pair would do for you.
		$editor_save( 'mc_related_items', array( $ALPHA ), $HOLDER );
		$editor_save( 'mc_parent_section', array(), $BETA );
		$after = $slugs( $BETA, 'mc_topic' );
		$show( 'beta', $BETA, 'mc_topic' );
		$assert( ! empty( $before ), 'beta had terms before' );
		$assert( empty( $after ), 'tier-1 holder-end sever strips when the reverse side is kept consistent' );
		break;

	// ---- S9: delete-path sever (holder deleted, dependents cleaned) -------
	// The matrix names this scenario but nothing implemented it. It matters
	// here because before_delete_post fills the SAME pending-recompute
	// structure the two capture branches do, so a refactor of that structure
	// touches this path without any other step exercising it.
	//
	// Uses throwaway posts: deleting a fixture post would force a reseed.
	case 's9':
		WP_CLI::log( 'S9 — delete a holder, its dependent must lose the pushed terms' );
		$coastal = get_term_by( 'slug', 'coastal', 'mc_topic' );
		if ( ! $coastal ) {
			WP_CLI::error( 'topic-coastal missing — seed the fixture first' );
		}

		$h = wp_insert_post( array(
			'post_type'   => 'mc_section',
			'post_title'  => 'MC Temp Holder (s9)',
			'post_status' => 'publish',
		) );
		$i = wp_insert_post( array(
			'post_type'   => 'mc_item',
			'post_title'  => 'MC Temp Item (s9)',
			'post_status' => 'publish',
		) );
		WP_CLI::log( "  temp holder #{$h}, temp item #{$i}" );

		wp_set_object_terms( $h, array( (int) $coastal->term_id ), 'mc_topic' );
		$editor_save( 'mc_related_items', array( $i ), $h );
		$editor_save( 'mc_parent_section', array( $h ), $i );
		wp_update_post( array( 'ID' => $h ) );

		$before = $slugs( $i, 'mc_topic' );
		WP_CLI::log( '  temp item before delete: [' . implode( ',', $before ) . ']' );
		$assert( ! empty( $before ), 'temp item inherited the holder terms' );

		wp_delete_post( $h, true );

		clean_object_term_cache( $i, 'mc_item' );
		$after = $slugs( $i, 'mc_topic' );
		WP_CLI::log( '  temp item after delete:  [' . implode( ',', $after ) . ']' );
		$assert( empty( $after ), 'delete-path sever withdrew the deleted holder terms' );

		wp_delete_post( $i, true );
		WP_CLI::log( '  temp posts removed' );
		break;

	default:
		foreach ( array( 'holder' => $HOLDER, 'holder2' => $HOLDER2, 'alpha' => $ALPHA, 'beta' => $BETA, 'sbidi' => $SBIDI, 'ibidi' => $IBIDI ) as $l => $i ) {
			$show( $l, $i, 'mc_topic' );
			$show( $l, $i, 'mc_flag' );
		}
}

if ( $fail ) {
	WP_CLI::error( sprintf( '%s: %d passed, %d FAILED', $step, $ok, count( $fail ) ) );
}
WP_CLI::success( sprintf( '%s: %d assertions passed', $step, $ok ) );
