<?php
/**
 * mc-rules — #61 behaviour sweep: time_based + related as pure appliers.
 *
 * #60 proved the dispatcher runs an ordered pass. This proves the two rule
 * types converted onto it still do their jobs from inside one, and that the
 * two entry points which are NOT the dispatcher's own hooks — the daily cron
 * sweep and a publish transition — reach the same pass as an editor save.
 *
 * Five things the static harness (H13) cannot see:
 *
 *   §61a/b  `related` still applies its target on a trigger-term write, on the
 *           native editor path AND through the ACF taxonomy field. H13 only
 *           knows the handler registers nothing; whether the pass then does
 *           the work is a runtime question.
 *   §61c/d  `related`'s removal now recomputes from LIVE STATE rather than
 *           from a set_object_terms delta. (c) is the case the delta model
 *           already handled; (d) is the deliberate behaviour change — a post
 *           holding the target that never held a trigger now loses it.
 *   §61h    A rule whose trigger no longer EXISTS is inert, not unsatisfied —
 *           the floor the live-state reading needs, or one deleted term makes
 *           a bidirectional rule strip its target everywhere, forever.
 *   §61e/f  The cron sweep enqueues and drains a FULL ORDERED PASS: a term a
 *           time rule writes at 3am is consumed by a later rule in the list,
 *           in the same pass. (f) is the order control — move the consumer
 *           above the producer and the consumption stops. Without the pair,
 *           "the sweep ran something" would pass even if the sweep were still
 *           removing terms by hand.
 *   §61g    Publishing a post still provokes the time-based path, which used
 *           to be `publish_post` on the handler itself.
 *
 * WHY THE RULES ARE AUTHORED BY HAND HERE. The seeded time_based set is a
 * deliberate collision pair on one target term (manifest §6, #69), which is the
 * wrong instrument for an ORDER question — two rules that cancel tell you
 * nothing about which ran first. So §61e/f author a three-row list: an expired
 * rule (the sweep's selection basis), an in-range rule (the producer) and a
 * related rule keyed on what the producer writes (the consumer).
 *
 * Usage (one step per eval — handler dedup is per-request):
 *   wp eval-file .../sweep-61-appliers.php related     # §61a-d, §61h
 *   wp eval-file .../sweep-61-appliers.php cron        # §61e producer→consumer
 *   wp eval-file .../sweep-61-appliers.php cron-swap   # §61f order control
 *   wp eval-file .../sweep-61-appliers.php publish     # §61g
 *   wp eval-file .../sweep-61-appliers.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'related';

/** The live dispatcher, or bail loudly — an unregistered one means no passes. */
function mc61_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

/**
 * Author an explicit ordered rule list.
 *
 * The list IS the storage shape since #66, so the order written here is the
 * order a pass executes in — which is what this sweep is asserting. (Before
 * that the type-keyed arrays had to be written alongside it or the order was
 * discarded and rebuilt.)
 *
 * @param array[] $rows Rules in authored order, each carrying a `type` key.
 * @return int Rows written.
 */
function mc61_author( array $rows ) {
	return mc_write_ordered_rules( $rows, OptionRuleStorage::KIND_TERM );
}

/** Empty every rule array so a setup write provokes nothing. */
function mc61_silence() {
	mc61_author( array() );
	mc61_dispatcher()->drain();
}

/** Term slugs of a post's mc_topic terms, sorted — readable assertions. */
function mc61_slugs( $post_id, $taxonomy = 'mc_topic' ) {
	$terms = wp_get_object_terms( (int) $post_id, $taxonomy );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$slugs = wp_list_pluck( $terms, 'slug' );
	sort( $slugs );
	return $slugs;
}

/**
 * The three-row producer→consumer list §61e/f swap between.
 *
 * @param bool $consumer_first Put the related rule ABOVE the in-range time
 *                             rule — the control that should NOT consume.
 * @return array[]
 */
function mc61_cron_rows( $consumer_first = false ) {
	$today = current_time( 'Y-m-d' );

	// The sweep's selection basis: expired, so its target term is what the
	// sweep queries for and what its pass then removes.
	$expired = array(
		'type'           => 'time_based_rules',
		'enabled'        => true,
		'post_types'     => array( 'mc_item' ),
		'start_date'     => gmdate( 'Y-m-d', strtotime( $today . ' -30 days' ) ),
		'end_date'       => gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) ),
		'target_term_id' => mc_tid( 'topic-archived' ),
	);

	// The PRODUCER: in range, so the pass applies Featured.
	$producer = array(
		'type'           => 'time_based_rules',
		'enabled'        => true,
		'post_types'     => array( 'mc_item' ),
		'start_date'     => gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ),
		'end_date'       => gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ),
		'target_term_id' => mc_tid( 'topic-featured' ),
	);

	// The CONSUMER: keyed on the term the producer writes. It has no trigger of
	// its own for that write — the producer's wp_set_object_terms is exactly
	// what the pass lock suppresses — so it can only see it by running later in
	// the same pass.
	$consumer = array(
		'type'            => 'related_rules',
		'enabled'         => true,
		'post_types'      => array( 'mc_item' ),
		'trigger_type'    => 'term',
		'trigger_term_id' => array( mc_tid( 'topic-featured' ) ),
		'target_term_id'  => mc_tid( 'topic-coastal' ),
		'bidirectional'   => false,
	);

	return $consumer_first
		? array( $expired, $consumer, $producer )
		: array( $expired, $producer, $consumer );
}

/** Put a subject in a known state with NO rule live, then re-author. */
function mc61_stage( $post_id, array $topic_slugs ) {
	mc61_silence();
	mc_reset_subject( $post_id );
	$ids = array();
	foreach ( $topic_slugs as $slug ) {
		$ids[] = mc_tid( $slug );
	}
	wp_set_object_terms( $post_id, $ids, 'mc_topic' );
	mc61_dispatcher()->drain();
}

// ---------------------------------------------------------------------------

switch ( $step ) {

	case 'related':
		$a = mc_pid( 'item-solo-a' );
		$b = mc_pid( 'item-solo-b' );

		// §61a — native editor path. The seeded related rule is Coastal ⇒
		// Featured, bidirectional. Isolating it keeps the hierarchical
		// expansion (which would add Coastal's ancestors) out of the read.
		mc61_silence();
		mc_reset_subject( $a );
		mc_isolate( 'related_rules' );
		wp_set_object_terms( $a, array( mc_tid( 'topic-coastal' ) ), 'mc_topic' );
		mc61_dispatcher()->drain();
		mc_assert( '§61a  editor path: trigger term applies the target', mc61_slugs( $a ), array( 'coastal', 'featured' ) );

		// §61c — the removal case the DELTA model also handled: the trigger
		// term goes away, so a bidirectional rule takes the target with it.
		wp_set_object_terms( $a, array(), 'mc_topic' );
		mc61_dispatcher()->drain();
		mc_assert( '§61c  bidirectional: trigger removed takes the target', mc61_slugs( $a ), array() );

		// §61b — ACF path. The mc_topics field is a taxonomy field with
		// save_terms ON, so an ACF write is the editor path an author actually
		// uses for these posts. Written with update_field() rather than a
		// native term write on purpose: that is the call AC v7 and any
		// programmatic ACF edit make, and it fires no save_post at all.
		mc61_silence();
		mc_reset_subject( $b );
		mc_isolate( 'related_rules' );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'mc_topics', array( mc_tid( 'topic-coastal' ) ), $b );
			mc61_dispatcher()->drain();
			mc_assert( '§61b  ACF path: trigger term applies the target', mc61_slugs( $b ), array( 'coastal', 'featured' ) );
		} else {
			WP_CLI::warning( '§61b skipped — ACF not active.' );
		}

		// §61d — THE BEHAVIOUR CHANGE. Solo-b holds the target and has never
		// held a trigger, so the delta model kept it forever: no removal delta
		// ever arrived. A live-state recompute removes it, because that is what
		// "the target mirrors the trigger" means.
		mc61_stage( $b, array( 'topic-featured' ) );
		mc_isolate( 'related_rules' );
		mc61_dispatcher()->run_pass( $b );
		mc_assert( '§61d  bidirectional: target with no trigger EVER is removed', mc61_slugs( $b ), array() );

		// §61h — the floor under §61d. A bidirectional rule whose trigger term
		// no longer EXISTS must be inert, not unsatisfied: "is the trigger on
		// this post" is unanswerable, and reading it as "no" turns one deleted
		// term into a rule that strips its target from every in-scope post on
		// every pass. Same rule as §61d with a nonexistent trigger id.
		mc61_stage( $b, array( 'topic-featured' ) );
		mc61_author( array(
			array(
				'type'            => 'related_rules',
				'enabled'         => true,
				'post_types'      => array( 'mc_item' ),
				'trigger_type'    => 'term',
				'trigger_term_id' => array( 99999999 ),
				'target_term_id'  => mc_tid( 'topic-featured' ),
				'bidirectional'   => true,
			),
		) );
		mc61_dispatcher()->run_pass( $b );
		mc_assert( '§61h  a rule whose trigger no longer exists removes nothing', mc61_slugs( $b ), array( 'featured' ) );
		break;

	case 'cron':
		$a = mc_pid( 'item-solo-a' );

		// Stage: solo-a holds only the expired rule's target, which is what
		// makes the sweep select it.
		mc61_stage( $a, array( 'topic-archived' ) );
		mc61_author( mc61_cron_rows( false ) );

		// Fire the event wp-cron fires. Nothing else touches the post.
		do_action( 'bws_taxonomy_manager_cleanup' );

		$got = mc61_slugs( $a );
		mc_assert( '§61e  cron pass: expired rule removed its own target', in_array( 'archived', $got, true ), false );
		mc_assert( '§61e  cron pass: in-range rule wrote its term', in_array( 'featured', $got, true ), true );
		mc_assert( '§61e  cron pass: a LATER rule consumed that write', $got, array( 'coastal', 'featured' ) );
		break;

	case 'cron-swap':
		$a = mc_pid( 'item-solo-a' );

		// Same three rules, consumer moved ABOVE the producer. If the sweep
		// were still applying rules by hand — or if the pass ran them in any
		// order but the authored one — this would look identical to §61e.
		mc61_stage( $a, array( 'topic-archived' ) );
		mc61_author( mc61_cron_rows( true ) );

		do_action( 'bws_taxonomy_manager_cleanup' );

		mc_assert( '§61f  order control: consumer above producer consumes nothing', mc61_slugs( $a ), array( 'featured' ) );
		break;

	case 'publish':
		$a = mc_pid( 'item-solo-a' );

		// Stage a DRAFT holding one mc_topic term, with no rule live. The
		// seeded in-range time rule filters on the mc_topic taxonomy, so the
		// term is what makes the post a candidate.
		mc61_stage( $a, array( 'topic-inland' ) );
		wp_update_post( array( 'ID' => $a, 'post_status' => 'draft' ) );
		mc61_dispatcher()->drain();

		mc_isolate( 'time_based_rules' );

		// The publish transition, and NO explicit drain: the save path's own
		// drain (wp_after_insert_post p999) is what must run the pass, or an
		// editor would see pre-pass terms until reload.
		wp_update_post( array( 'ID' => $a, 'post_status' => 'publish' ) );

		mc_assert( '§61g  publish provokes the time-based path', in_array( 'featured', mc61_slugs( $a ), true ), true );
		mc_assert( '§61g  the post is published', get_post_status( $a ), 'publish' );
		break;

	case 'restore':
		$a = mc_pid( 'item-solo-a' );
		$b = mc_pid( 'item-solo-b' );

		// Rules first, then the subjects, then drop the authored list — the
		// same order sweep-60's restore uses and for the same reason: clearing
		// terms while the rules are live lets the handlers repopulate them.
		mc61_silence();
		wp_update_post( array( 'ID' => $a, 'post_status' => 'publish' ) );
		mc_reset_subject( $a );
		mc_reset_subject( $b );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'mc_topics', array(), $b );
		}
		mc61_dispatcher()->drain();

		// mc_restore() rewrites the kind lists themselves in the documented
		// KIND_TYPES order (#66 — they ARE the stored shape), so the authored
		// order this sweep installed goes with them.
		mc_restore( array( $a, $b ) );

		WP_CLI::log( '[restore] rules rebuilt from manifest, subjects reset.' );
		break;

	default:
		WP_CLI::error( "Unknown step '{$step}' — use related | cron | cron-swap | publish | restore." );
}
