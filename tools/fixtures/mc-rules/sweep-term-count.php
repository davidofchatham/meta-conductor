<?php
/**
 * mc-rules — Term count limit behaviour sweep (term-count-limit ticket 02).
 *
 * `term_count_rules` caps how many terms a post holds in one taxonomy. With no
 * edit to go on (no delta — ticket 03 adds newest-wins) the survivors are the
 * deepest terms, then the lowest term IDs (`Core\TermCountSurvivors`). Every
 * case here is therefore a NO-DELTA case: the terms are staged with the rules
 * silenced, the rule is authored, and `drain_post()` provokes the pass the way
 * bulk apply and the cron do.
 *
 *   flat       mc_flag (flat), three temp terms: cap 1 and cap 2, per-level on
 *              and off give identical answers; a cap above the set trims nothing.
 *   tree-on    mc_topic, per level ON: N terms at each depth.
 *   tree-off   mc_topic, per level OFF: N terms in total, deepest first.
 *   stable     a second pass changes nothing (returns 0 changed rules).
 *   acf        the ACF taxonomy field is pruned too, written only when its SET
 *              changes and keeping the field's own order, so a rerun leaves the
 *              stored meta byte-identical.
 *   scope      a rule scoped to another post type, or to draft posts, leaves a
 *              published mc_item alone.
 *
 * ⚠️ Same trap as sweep-67: `item-solo-a` carries an ACF `mc_topics` mirror of
 * mc_topic. Staging and cleaning clear BOTH stores or a stale field value
 * silently changes which term survives.
 *
 * Usage (one step per eval):
 *   wp eval-file .../sweep-term-count.php flat
 *   wp eval-file .../sweep-term-count.php tree-on
 *   wp eval-file .../sweep-term-count.php tree-off
 *   wp eval-file .../sweep-term-count.php stable
 *   wp eval-file .../sweep-term-count.php acf
 *   wp eval-file .../sweep-term-count.php scope
 *   wp eval-file .../sweep-term-count.php restore     # deletes temp terms, restores rules, checks mc_item slugs
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'flat';

function mctc_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

/** One count rule, authored as the only rule in the term list. */
function mctc_author( $taxonomy, $max, $per_level, array $over = array() ) {
	mc_write_ordered_rules(
		array(
			$over + array(
				'type'       => 'term_count_rules',
				'enabled'    => true,
				'taxonomy'   => $taxonomy,
				'post_types' => array( 'mc_item' ),
				'max_terms'  => $max,
				'per_level'  => $per_level,
			),
		),
		OptionRuleStorage::KIND_TERM
	);
}

/** Silence every rule, clear BOTH term stores on the subject. */
function mctc_clean( $post_id ) {
	mc_write_ordered_rules( array(), OptionRuleStorage::KIND_TERM );
	mctc_dispatcher()->drain();
	mc_reset_subject( $post_id );
	if ( function_exists( 'update_field' ) ) {
		update_field( 'field_mc_topics_item', array(), $post_id );
	}
	mctc_dispatcher()->drain();
	mc_reset_subject( $post_id );
	if ( function_exists( 'update_field' ) ) {
		update_field( 'field_mc_topics_item', array(), $post_id );
	}
}

/** The three temp mc_flag terms, created on demand, in ascending ID order. */
function mctc_flat_terms() {
	$ids = array();
	foreach ( array( 'mctc-a', 'mctc-b', 'mctc-c' ) as $slug ) {
		$existing = term_exists( $slug, 'mc_flag' );
		if ( ! $existing ) {
			$existing = wp_insert_term( $slug, 'mc_flag', array( 'slug' => $slug ) );
		}
		$ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
	}
	return $ids;
}

/** Stage terms with no rule live, then provoke ONE no-delta pass. Returns rules changed. */
function mctc_stage_and_pass( $post_id, $taxonomy, array $term_ids, $tax_rule_args ) {
	mctc_clean( $post_id );
	wp_set_object_terms( $post_id, $term_ids, $taxonomy );
	mctc_dispatcher()->drain();
	mctc_author( $taxonomy, $tax_rule_args[0], $tax_rule_args[1] );
	$changed = mctc_dispatcher()->drain_post( $post_id );
	mctc_dispatcher()->drain();
	return $changed;
}

$solo = mc_pid( 'item-solo-a' );
$t    = array(
	'region'  => mc_tid( 'topic-region' ),
	'east'    => mc_tid( 'topic-east' ),
	'west'    => mc_tid( 'topic-west' ),
	'coastal' => mc_tid( 'topic-coastal' ),
	'inland'  => mc_tid( 'topic-inland' ),
	'harbor'  => mc_tid( 'topic-harbor' ),
);

if ( ! $solo || in_array( 0, $t, true ) ) {
	WP_CLI::error( 'Fixture not found — seed the mc-rules fixture first.' );
}

// Depth-2 pair: the lower ID survives a tie.
$low2 = min( $t['coastal'], $t['inland'] );
// Every tree-staged set: all six terms across depths 0-3 (Harbor is depth 3).
$tree = array_values( $t );

$pass = 0;
$fail = 0;
$chk  = function ( $label, $got, $want ) use ( &$pass, &$fail ) {
	if ( mc_assert( $label, $got, $want ) ) {
		$pass++;
	} else {
		$fail++;
	}
};

switch ( $step ) {

	case 'flat':
		list( $a, $b, $c ) = mctc_flat_terms();
		foreach ( array( true, false ) as $per_level ) {
			$tag = $per_level ? 'per-level on' : 'per-level off';
			mctc_stage_and_pass( $solo, 'mc_flag', array( $c, $a, $b ), array( 1, $per_level ) );
			$chk( "flat cap 1, $tag: lowest ID survives", mc_terms( $solo, 'mc_flag' ), array( $a ) );

			mctc_stage_and_pass( $solo, 'mc_flag', array( $c, $a, $b ), array( 2, $per_level ) );
			$chk( "flat cap 2, $tag: two lowest IDs survive", mc_terms( $solo, 'mc_flag' ), array( $a, $b ) );

			mctc_stage_and_pass( $solo, 'mc_flag', array( $c, $a, $b ), array( 5, $per_level ) );
			$chk( "flat cap 5 over 3 terms, $tag: nothing trimmed", mc_terms( $solo, 'mc_flag' ), array( $a, $b, $c ) );
		}
		break;

	case 'tree-on':
		mctc_stage_and_pass( $solo, 'mc_topic', $tree, array( 1, true ) );
		$chk( 'tree cap 1, per level ON: one term at each depth (East<West, Coastal<Inland by ID)',
			mc_terms( $solo ), array( $t['region'], min( $t['east'], $t['west'] ), $low2, $t['harbor'] ) );

		mctc_stage_and_pass( $solo, 'mc_topic', $tree, array( 2, true ) );
		$chk( 'tree cap 2, per level ON: no depth holds more than two, so nothing is trimmed',
			mc_terms( $solo ), $tree );
		break;

	case 'tree-off':
		mctc_stage_and_pass( $solo, 'mc_topic', $tree, array( 1, false ) );
		$chk( 'tree cap 1, per level OFF: the deepest term alone', mc_terms( $solo ), array( $t['harbor'] ) );

		mctc_stage_and_pass( $solo, 'mc_topic', $tree, array( 2, false ) );
		$chk( 'tree cap 2, per level OFF: deepest first, then the lowest ID of the next depth',
			mc_terms( $solo ), array( $t['harbor'], $low2 ) );
		break;

	case 'stable':
		$first = mctc_stage_and_pass( $solo, 'mc_topic', $tree, array( 2, false ) );
		$after = mc_terms( $solo );
		$again = mctc_dispatcher()->drain_post( $solo );
		$chk( 'first pass changed the post', $first > 0, true );
		$chk( 'second pass changes no rule', $again, 0 );
		$chk( 'and the terms are the same', mc_terms( $solo ), $after );
		break;

	case 'acf':
		if ( ! function_exists( 'update_field' ) ) {
			WP_CLI::error( 'ACF is not active.' );
		}
		mctc_clean( $solo );
		// Field order is deliberately NOT ID order, and the survivors (Harbor,
		// Coastal) keep it: Harbor, Inland, Coastal.
		update_field( 'field_mc_topics_item', array( $t['harbor'], $t['inland'], $t['coastal'] ), $solo );
		mctc_dispatcher()->drain();
		mctc_author( 'mc_topic', 2, false );
		mctc_dispatcher()->drain_post( $solo );
		mctc_dispatcher()->drain();

		$field_ids = array_map( 'intval', (array) get_field( 'mc_topics', $solo, false ) );
		$order     = array_values( array_filter(
			array( $t['harbor'], $t['inland'], $t['coastal'] ),
			static fn( $id ) => in_array( $id, array( $t['harbor'], $low2 ), true )
		) );
		$chk( 'ACF field pruned to the two survivors, in the field\'s own order',
			implode( ',', $field_ids ), implode( ',', $order ) );
		$chk( 'native terms agree', mc_terms( $solo ), array( $t['harbor'], $low2 ) );

		$meta_before = get_post_meta( $solo, 'mc_topics', true );
		mctc_dispatcher()->drain_post( $solo );
		mctc_dispatcher()->drain();
		$chk( 'a rerun leaves the stored ACF value byte-identical',
			wp_json_encode( get_post_meta( $solo, 'mc_topics', true ) ), wp_json_encode( $meta_before ) );
		break;

	case 'scope':
		mctc_clean( $solo );
		wp_set_object_terms( $solo, $tree, 'mc_topic' );
		mctc_dispatcher()->drain();

		mctc_author( 'mc_topic', 1, false, array( 'post_types' => array( 'mc_section' ) ) );
		mctc_dispatcher()->drain_post( $solo );
		$chk( 'a rule scoped to another post type leaves the post alone', mc_terms( $solo ), $tree );

		mctc_author( 'mc_topic', 1, false, array( 'post_status' => array( 'draft' ) ) );
		mctc_dispatcher()->drain_post( $solo );
		$chk( 'a rule scoped to draft leaves a published post alone', mc_terms( $solo ), $tree );

		mctc_author( 'mc_topic', 1, false, array( 'post_status' => array( 'publish' ) ) );
		mctc_dispatcher()->drain_post( $solo );
		$chk( 'the same rule scoped to publish trims it', mc_terms( $solo ), array( $t['harbor'] ) );
		break;

	case 'restore':
		mctc_clean( $solo );
		foreach ( array( 'mctc-a', 'mctc-b', 'mctc-c' ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'mc_flag' );
			if ( $term ) {
				wp_delete_term( $term->term_id, 'mc_flag' );
			}
		}
		mc_restore( array( $solo ) );
		mctc_dispatcher()->drain();

		// The format pass runs for every entity a drain reaches (§7 gotcha):
		// every fixture mc_item must still carry its manifest post_name.
		$manifest = mc_sweep_manifest();
		foreach ( $manifest['posts'] as $key => $def ) {
			if ( 'mc_item' !== $def['post_type'] ) {
				continue;
			}
			$pid = mc_pid( $key );
			$chk( "post_name intact: $key", $pid ? get_post_field( 'post_name', $pid ) : '(missing)', $def['post_name'] );
		}
		break;

	default:
		WP_CLI::error( "Unknown step '$step' — flat|tree-on|tree-off|stable|acf|scope|restore." );
}

WP_CLI::log( sprintf( '[sweep term-count/%s] %d passed, %d failed', $step, $pass, $fail ) );
if ( $fail ) {
	WP_CLI::error( sprintf( 'term-count sweep step "%s" FAILED (%d assertions).', $step, $fail ) );
}
