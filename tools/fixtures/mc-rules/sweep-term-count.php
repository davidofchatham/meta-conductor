<?php
/**
 * mc-rules — Term count limit behavior sweep (term-count-limit ticket 02).
 *
 * `restrict_term_count` caps how many terms a post holds in one taxonomy. With no
 * edit to go on (no delta) the survivors are the deepest terms, then the lowest
 * term IDs (`Core\TermCountSurvivors`). The first block of steps (flat … scope)
 * is therefore NO-DELTA: terms are staged with the rules silenced, the rule is
 * authored, and `drain_post()` provokes the pass the way bulk apply and the
 * cron do. The ticket-03 block (newest … acf-field-only) writes terms AFTER the
 * rule is live, outside any pass, so the capture records them as the edit.
 * One scenario per eval: the capture memo lives for the request.
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
 *   wp eval-file .../sweep-term-count.php newest          # editor's added term beats an older lower-ID one; 2nd pass; no-term save
 *   wp eval-file .../sweep-term-count.php newest-multi    # several added terms all preferred; ties by lowest ID
 *   wp eval-file .../sweep-term-count.php rule-output     # another rule's pass output is not the edit
 *   wp eval-file .../sweep-term-count.php hier-after      # child_to_parent after the count rule, stable over 2 passes
 *   wp eval-file .../sweep-term-count.php fallback        # silent staging is no edit: deepest-then-lowest, stable rerun
 *   wp eval-file .../sweep-term-count.php acf-delta       # ACF half uses the same memo
 *   wp eval-file .../sweep-term-count.php acf-field-only  # raw field write, no native sync: fallback
 *   wp eval-file .../sweep-term-count.php bulk            # real ExistingPostsApplier run: fallback, stable rerun
 *   wp eval-file .../sweep-term-count.php cron            # real daily sweep (time-based expiry + cap), stable rerun
 *   wp eval-file .../sweep-term-count.php restore    # deletes temp terms, restores rules, checks mc_item slugs
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\ExistingPostsApplier;
use BWS\MetaConductor\Core\RuleChoice;
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

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
				'type'       => 'restrict_term_count',
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

	// ---- ticket 03: newest-wins via delta capture --------------------------
	// Each step is ONE eval: the capture memo lives for the request, so a
	// scenario that must see an empty delta cannot share a request with one that
	// recorded a delta for the same post and taxonomy.

	case 'newest':
		// Old term a (lowest ID, the fallback survivor); the editor adds c.
		list( $a, $b, $c ) = mctc_flat_terms();
		mctc_clean( $solo );
		wp_set_object_terms( $solo, array( $a ), 'mc_flag' );
		mctc_dispatcher()->drain();
		mctc_author( 'mc_flag', 1, true );
		wp_set_object_terms( $solo, array( $a, $c ), 'mc_flag' ); // outside write: adds c
		mctc_dispatcher()->drain_post( $solo );
		mctc_dispatcher()->drain();
		$chk( 'cap 1: the term the editor just added survives over the older, lower-ID one', mc_terms( $solo, 'mc_flag' ), array( $c ) );

		$again = mctc_dispatcher()->drain_post( $solo );
		$chk( 'a second pass in the same request changes nothing', $again, 0 );
		$chk( '... and keeps the same survivor', mc_terms( $solo, 'mc_flag' ), array( $c ) );

		mctc_dispatcher()->mark_dirty( $solo ); // a save that touched no terms
		mctc_dispatcher()->drain();
		$chk( 'a save that touches no terms leaves the survivor alone', mc_terms( $solo, 'mc_flag' ), array( $c ) );
		break;

	case 'newest-multi':
		// Old term a; the editor adds b AND c in one write.
		list( $a, $b, $c ) = mctc_flat_terms();
		mctc_clean( $solo );
		wp_set_object_terms( $solo, array( $a ), 'mc_flag' );
		mctc_dispatcher()->drain();
		mctc_author( 'mc_flag', 2, true );
		wp_set_object_terms( $solo, array( $a, $b, $c ), 'mc_flag' );
		mctc_dispatcher()->drain_post( $solo );
		mctc_dispatcher()->drain();
		$chk( 'cap 2: both added terms are preferred over the older one', mc_terms( $solo, 'mc_flag' ), array( $b, $c ) );

		mctc_author( 'mc_flag', 1, true );
		mctc_dispatcher()->drain_post( $solo );
		mctc_dispatcher()->drain();
		$chk( 'cap 1 over two added terms: the lowest ID breaks the tie', mc_terms( $solo, 'mc_flag' ), array( $b ) );
		break;

	case 'rule-output':
		// [ancestors rule, count rule 2/total]. The editor adds West to an old
		// Harbor; the hierarchy rule then adds ancestors during the pass. Those
		// are rule output: counted as the edit, the deeper ancestors would beat
		// Harbor. Only West (the edit) and Harbor (deepest) may survive.
		mctc_clean( $solo );
		wp_set_object_terms( $solo, array( $t['harbor'] ), 'mc_topic' );
		mctc_dispatcher()->drain();
		mc_write_ordered_rules(
			array(
				array(
					'type'                 => 'terms_from_term_hierarchy',
					'enabled'              => true,
					'taxonomy'             => 'mc_topic',
					'post_types'           => array( 'mc_item' ),
					'inheritance_behavior' => 'ancestors',
					'inheritance_depth'    => 'all',
				),
				array(
					'type'       => 'restrict_term_count',
					'enabled'    => true,
					'taxonomy'   => 'mc_topic',
					'post_types' => array( 'mc_item' ),
					'max_terms'  => 2,
					'per_level'  => false,
				),
			),
			OptionRuleStorage::KIND_TERM
		);
		wp_set_object_terms( $solo, array( $t['harbor'], $t['west'] ), 'mc_topic' ); // outside write: adds West
		mctc_dispatcher()->drain_post( $solo );
		$want = array( $t['harbor'], $t['west'] );
		sort( $want );
		$chk( 'a term another rule added in the pass is not the edit: West + Harbor survive', mc_terms( $solo ), $want );

		mctc_dispatcher()->drain_post( $solo );
		$chk( 'a second pass gives the same survivors', mc_terms( $solo ), $want );
		break;

	case 'hier-after':
		// [count rule, child_to_parent rule]: stable across two passes whether
		// the cap is per level or total.
		$chain = array_merge( array( $t['harbor'] ), array_map( 'intval', get_ancestors( $t['harbor'], 'mc_topic', 'taxonomy' ) ) );
		sort( $chain );
		foreach ( array( true, false ) as $per_level ) {
			$tag = $per_level ? 'per level ON' : 'per level OFF';
			mctc_clean( $solo );
			mc_write_ordered_rules(
				array(
					array(
						'type'       => 'restrict_term_count',
						'enabled'    => true,
						'taxonomy'   => 'mc_topic',
						'post_types' => array( 'mc_item' ),
						'max_terms'  => 1,
						'per_level'  => $per_level,
					),
					array(
						'type'                 => 'terms_from_term_hierarchy',
						'enabled'              => true,
						'taxonomy'             => 'mc_topic',
						'post_types'           => array( 'mc_item' ),
						'inheritance_behavior' => 'ancestors',
						'inheritance_depth'    => 'all',
					),
				),
				OptionRuleStorage::KIND_TERM
			);
			wp_set_object_terms( $solo, array( $t['harbor'] ), 'mc_topic' );
			mctc_dispatcher()->drain_post( $solo );
			$one = mc_terms( $solo );
			mctc_dispatcher()->drain_post( $solo );
			$chk( "count then child_to_parent, $tag: pass 1 holds Harbor and its ancestors", $one, $chain );
			$chk( "count then child_to_parent, $tag: pass 2 changes nothing", mc_terms( $solo ), $one );
		}
		break;

	case 'fallback':
		// Terms staged with the rule SILENT are not an edit: c arrives after a,
		// but nothing recorded it, so the lowest ID wins. Bulk apply and the
		// cron reach the queue the same way (no outside write in the request).
		list( $a, $b, $c ) = mctc_flat_terms();
		mctc_clean( $solo );
		wp_set_object_terms( $solo, array( $a ), 'mc_flag' );
		wp_set_object_terms( $solo, array( $a, $c ), 'mc_flag' );
		mctc_dispatcher()->drain();
		mctc_author( 'mc_flag', 1, true );
		mctc_dispatcher()->drain_post( $solo );
		$chk( 'no recorded edit: the lowest ID survives', mc_terms( $solo, 'mc_flag' ), array( $a ) );
		$chk( 'a second run changes nothing', mctc_dispatcher()->drain_post( $solo ), 0 );
		break;

	case 'acf-delta':
		// Both halves use the one memo: the edit is the HIGHER-ID depth-2 term,
		// so the fallback would have kept the other.
		if ( ! function_exists( 'update_field' ) ) {
			WP_CLI::error( 'ACF is not active.' );
		}
		$high2 = max( $t['coastal'], $t['inland'] );
		mctc_clean( $solo );
		update_field( 'field_mc_topics_item', array( $low2 ), $solo );
		wp_set_object_terms( $solo, array( $low2 ), 'mc_topic' );
		mctc_dispatcher()->drain();
		mctc_author( 'mc_topic', 1, true );
		update_field( 'field_mc_topics_item', array( $low2, $high2 ), $solo );
		wp_set_object_terms( $solo, array( $low2, $high2 ), 'mc_topic' ); // outside write: adds $high2
		mctc_dispatcher()->drain_post( $solo );
		mctc_dispatcher()->drain();
		$chk( 'ACF field keeps the term the editor added',
			implode( ',', array_map( 'intval', (array) get_field( 'mc_topics', $solo, false ) ) ), (string) $high2 );
		$chk( 'native terms agree', mc_terms( $solo ), array( $high2 ) );
		break;

	case 'acf-field-only':
		// A raw meta write fires no term write: nothing recorded, so the field
		// half takes the fallback (lowest ID).
		if ( ! function_exists( 'update_field' ) ) {
			WP_CLI::error( 'ACF is not active.' );
		}
		$high2 = max( $t['coastal'], $t['inland'] );
		mctc_clean( $solo );
		mctc_author( 'mc_topic', 1, true );
		update_post_meta( $solo, 'mc_topics', array( $low2, $high2 ) );
		mctc_dispatcher()->drain_post( $solo );
		$chk( 'field-only write, no native sync: the lowest ID survives',
			implode( ',', array_map( 'intval', (array) get_field( 'mc_topics', $solo, false ) ) ), (string) $low2 );
		break;

	case 'bulk':
		// The real bulk path (ExistingPostsApplier), over EVERY mc_item: terms are
		// staged with the rule silent, so there is no edit and the lowest ID wins.
		// Other fixture posts may hold several mc_flag terms, so they are put back.
		list( $a, $b, $c ) = mctc_flat_terms();
		$flags = array();
		foreach ( get_posts( array( 'post_type' => 'mc_item', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			$flags[ $id ] = mc_terms( $id, 'mc_flag' );
		}
		mctc_clean( $solo );
		wp_set_object_terms( $solo, array( $a ), 'mc_flag' );
		wp_set_object_terms( $solo, array( $a, $c ), 'mc_flag' );
		mctc_dispatcher()->drain();
		mc_write_ordered_rules( array(), OptionRuleStorage::KIND_FORMAT );
		mctc_author( 'mc_flag', 1, true );
		$rows   = StorageFactory::get_instance()->get_kind_rules( OptionRuleStorage::KIND_TERM );
		$choice = RuleChoice::encode( OptionRuleStorage::KIND_TERM, 0, $rows[0] );

		$first = ExistingPostsApplier::run_batch( $choice );
		$chk( 'bulk run completed', $first['done'] ?? false, true );
		$chk( 'bulk apply with no edit: the lowest ID survives', mc_terms( $solo, 'mc_flag' ), array( $a ) );

		$second = ExistingPostsApplier::run_batch( $choice );
		$chk( 'a second bulk run changes nothing', $second['changed'] ?? -1, 0 );
		$chk( '... and the survivor is the same', mc_terms( $solo, 'mc_flag' ), array( $a ) );

		mc_write_ordered_rules( array(), OptionRuleStorage::KIND_TERM );
		foreach ( $flags as $id => $ids ) {
			wp_set_object_terms( $id, $ids, 'mc_flag' );
		}
		mctc_dispatcher()->drain();
		break;

	case 'cron':
		// The daily sweep: an expired time-based rule takes target term c off the
		// post, and the same ordered pass then caps what is left. No edit, so the
		// lowest ID survives; a second sweep finds nothing left to do.
		list( $a, $b, $c ) = mctc_flat_terms();
		mctc_clean( $solo );
		wp_set_object_terms( $solo, array( $a, $b, $c ), 'mc_flag' );
		mctc_dispatcher()->drain();
		mc_write_ordered_rules( array(), OptionRuleStorage::KIND_FORMAT );
		mc_write_ordered_rules(
			array(
				array(
					'type'           => 'terms_from_date_window',
					'enabled'        => true,
					'post_types'     => array( 'mc_item' ),
					'start_date'     => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
					'end_date'       => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
					'target_term_id' => array( $c ),
				),
				array(
					'type'       => 'restrict_term_count',
					'enabled'    => true,
					'taxonomy'   => 'mc_flag',
					'post_types' => array( 'mc_item' ),
					'max_terms'  => 1,
					'per_level'  => true,
				),
			),
			OptionRuleStorage::KIND_TERM
		);
		do_action( 'bws_taxonomy_manager_cleanup' );
		$chk( 'cron sweep: the expired term is gone and the cap takes the lowest ID', mc_terms( $solo, 'mc_flag' ), array( $a ) );

		do_action( 'bws_taxonomy_manager_cleanup' );
		$chk( 'a second sweep changes nothing', mc_terms( $solo, 'mc_flag' ), array( $a ) );
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
		WP_CLI::error( "Unknown step '$step' — flat|tree-on|tree-off|stable|acf|scope|newest|newest-multi|rule-output|hier-after|fallback|acf-delta|acf-field-only|bulk|cron|restore." );
}

WP_CLI::log( sprintf( '[sweep term-count/%s] %d passed, %d failed', $step, $pass, $fail ) );
if ( $fail ) {
	WP_CLI::error( sprintf( 'term-count sweep step "%s" FAILED (%d assertions).', $step, $fail ) );
}
