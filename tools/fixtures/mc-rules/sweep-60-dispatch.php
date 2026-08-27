<?php
/**
 * mc-rules — #60 dispatcher behaviour sweep (Phase 4 Gate 2, testbed half).
 *
 * Proves the two things the static harness cannot: that authored ORDER changes
 * the outcome, and that the outcome does not depend on WHICH trigger provoked
 * the pass.
 *
 * SUBJECT AND WHY THIS TERM PAIR. `item-solo-a` seeded with {Harbor, Inland}.
 * Harbor is L3 under Coastal under East under Region; Inland is a second L2
 * under East. That is the smallest set on which the two converted rule types
 * genuinely disagree:
 *
 *   hierarchical → level_restriction   ancestors are added first, so L2 ends up
 *                                      holding {Coastal, Inland} and one_per_level
 *                                      prunes one of them → FOUR terms.
 *   level_restriction → hierarchical   nothing to prune (one term per level), then
 *                                      ancestors are added on top → FIVE terms.
 *
 * The pair is also the standing contradiction #51 describes — a restricting
 * rule and an expanding rule on one taxonomy. That it now resolves by authored
 * order rather than by hook priority is the point of the ticket, not a defect
 * this sweep is asserting away.
 *
 * STEPS. `s1`/`s2` are a two-eval pair on purpose: `s1` writes and returns, so
 * the pass runs on `shutdown` in ITS OWN request, and `s2` reads the result in
 * the next one. Nothing else can show that the shutdown drain — the only hook
 * guaranteed to fire for a write involving no post save — actually runs. Every
 * other step drains explicitly through the dispatcher, because WP-CLI's own
 * shutdown lands after the assertions in the same eval.
 *
 * Usage (one step per eval):
 *   wp eval-file .../sweep-60-dispatch.php order      # A vs B, order swap
 *   wp eval-file .../sweep-60-dispatch.php provoke    # term write / post save / bulk
 *   wp eval-file .../sweep-60-dispatch.php s1         # write, let shutdown drain
 *   wp eval-file .../sweep-60-dispatch.php s2         # assert what s1's shutdown did
 *   wp eval-file .../sweep-60-dispatch.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'order';

$HIER = 'hierarchical_rules';
$LR   = 'hierarchical_level_restriction_rules';

/**
 * Author the persisted `term_rules` list in an explicit TYPE order.
 *
 * The dispatcher reads `get_authored_kind_rules()`, which keeps the stored list
 * whenever it still agrees with the type-keyed arrays as sets per type. So the
 * rows are built the way `fan_in()` builds them — the row verbatim plus a
 * `type` key — and the type arrays are left exactly as `mc_isolate()` wrote
 * them. Reordering only the kind list is precisely the edit the repeater makes
 * when an author drags a row.
 *
 * @param string[] $type_order Rule types, in the order their rules should run.
 * @return int Rows written.
 */
function mc60_author_order( array $type_order ) {
	$opt      = mc_sweep_option();
	$settings = get_option( $opt, array() );
	$rows     = array();

	foreach ( $type_order as $type ) {
		foreach ( (array) ( $settings[ $type ] ?? array() ) as $rule ) {
			if ( is_array( $rule ) ) {
				$rule['type'] = $type;
				$rows[]       = $rule;
			}
		}
	}

	$settings[ OptionRuleStorage::KIND_TERM ] = $rows;
	update_option( $opt, $settings );
	mc_sweep_clear_cache();

	return count( $rows );
}

/** The live dispatcher, or bail loudly — an unregistered one means no passes. */
function mc60_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

/**
 * Put the subject in the starting state: no terms, no auto-term provenance,
 * then {Harbor, Inland} written WITHOUT letting a pass run.
 *
 * The write has to be invisible to the dispatcher, or the pass would already
 * have happened before the step under test provokes it. Emptying the dirty
 * queue after the write is the honest way to do that — it leaves the term
 * state real and only discards the signal.
 *
 * @param int $post_id Subject.
 * @return int[] The two term IDs written.
 */
function mc60_arm( $post_id ) {
	mc_reset_subject( $post_id );

	$harbor = mc_tid( 'topic-harbor' );
	$inland = mc_tid( 'topic-inland' );
	wp_set_object_terms( $post_id, array( $harbor, $inland ), 'mc_topic' );

	// Swallow the signal that write just raised, without running it.
	$d = mc60_dispatcher();
	$r = new ReflectionProperty( $d, 'dirty' );
	$r->setAccessible( true );
	$r->setValue( $d, array() );

	return array( $harbor, $inland );
}

/** Term slugs on the subject, sorted — reads better in a diff than IDs. */
function mc60_slugs( $post_id ) {
	$slugs = wp_get_object_terms( $post_id, 'mc_topic', array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $slugs ) ) {
		return array();
	}
	sort( $slugs );

	return $slugs;
}

$subject = mc_pid( 'item-solo-a' );
if ( ! $subject ) {
	WP_CLI::error( 'Fixture post item-solo-a not found — seed mc-rules first.' );
}

switch ( $step ) {

	// ---------------------------------------------------------------- order
	case 'order':
		mc_isolate( array( $HIER, $LR ) );

		mc60_author_order( array( $HIER, $LR ) );
		mc60_arm( $subject );
		// run_pass, not drain: arm() deliberately leaves the queue empty, and
		// this step is about what one pass does, not about how it was queued.
		mc60_dispatcher()->run_pass( $subject );
		$a = mc60_slugs( $subject );
		WP_CLI::log( '[A] hierarchical → level_restriction : ' . implode( ', ', $a ) );

		mc60_author_order( array( $LR, $HIER ) );
		mc60_arm( $subject );
		mc60_dispatcher()->run_pass( $subject );
		$b = mc60_slugs( $subject );
		WP_CLI::log( '[B] level_restriction → hierarchical : ' . implode( ', ', $b ) );

		// A prunes an L2 sibling that B never sees, because in B the ancestor
		// that creates the collision is added after the pruning rule has run.
		mc_assert( '§60a  order A = ancestors then prune', $a, array( 'east', 'harbor', 'inland', 'region' ) );
		mc_assert( '§60b  order B = prune then ancestors', $b, array( 'coastal', 'east', 'harbor', 'inland', 'region' ) );
		mc_assert( '§60c  swapping list position changes the result', $a === $b ? 'same' : 'different', 'different' );
		break;

	// -------------------------------------------------------------- provoke
	case 'provoke':
		mc_isolate( array( $HIER, $LR ) );
		mc60_author_order( array( $HIER, $LR ) );

		// (1) programmatic term write → set_object_terms → mark → drain. The
		//     re-write of the same two terms is what raises the signal arm()
		//     deliberately swallowed; the term state is unchanged by it.
		$armed = mc60_arm( $subject );
		wp_set_object_terms( $subject, $armed, 'mc_topic' );
		mc60_dispatcher()->drain();
		$by_terms = mc60_slugs( $subject );

		// (2) editor-shaped save → save_post → mark → drain.
		mc60_arm( $subject );
		wp_update_post( array( 'ID' => $subject ) );
		mc60_dispatcher()->drain();
		$by_save = mc60_slugs( $subject );

		// (3) bulk apply → the handler's own bulk entry point, which asks the
		//     dispatcher for a full pass rather than looping its own rules.
		mc60_arm( $subject );
		$handlers = \BWS\MetaConductor\TaxonomyManager::get_instance();
		$ref      = new ReflectionProperty( $handlers, 'handlers' );
		$ref->setAccessible( true );
		$hier = $ref->getValue( $handlers )['hierarchical'];
		$hier->process_existing_posts( 200, 0 );
		$by_bulk = mc60_slugs( $subject );

		WP_CLI::log( '[term write] ' . implode( ', ', $by_terms ) );
		WP_CLI::log( '[post save ] ' . implode( ', ', $by_save ) );
		WP_CLI::log( '[bulk apply] ' . implode( ', ', $by_bulk ) );

		mc_assert( '§60d  term write', $by_terms, array( 'east', 'harbor', 'inland', 'region' ) );
		mc_assert( '§60e  post save == term write', $by_save, $by_terms );
		mc_assert( '§60f  bulk apply == term write', $by_bulk, $by_terms );
		break;

	// ------------------------------------------------- s1/s2: shutdown drain
	case 's1':
		mc_isolate( array( $HIER, $LR ) );
		mc60_author_order( array( $HIER, $LR ) );
		mc_reset_subject( $subject );
		wp_set_object_terms(
			$subject,
			array( mc_tid( 'topic-harbor' ), mc_tid( 'topic-inland' ) ),
			'mc_topic'
		);
		WP_CLI::log( '[s1] wrote {harbor, inland}; NOT draining — shutdown must. Now run s2.' );
		WP_CLI::log( '[s1] pre-shutdown state: ' . implode( ', ', mc60_slugs( $subject ) ) );
		break;

	case 's2':
		mc_assert(
			'§60g  the shutdown drain ran s1\'s pass',
			mc60_slugs( $subject ),
			array( 'east', 'harbor', 'inland', 'region' )
		);
		break;

	// -------------------------------------------------------------- restore
	case 'restore':
		// Drop the authored list too: mc_restore() rewrites the type arrays,
		// and a stale hand-authored term_rules would be repaired on the next
		// admin load rather than here. Removing it returns the site to the
		// derived regime the seeder leaves behind.
		$opt      = mc_sweep_option();
		$settings = get_option( $opt, array() );
		unset( $settings[ OptionRuleStorage::KIND_TERM ] );
		update_option( $opt, $settings );

		mc_restore( array( $subject ) );
		WP_CLI::log( '[restore] rules rebuilt from manifest, subject reset, authored list dropped.' );
		break;

	default:
		WP_CLI::error( "Unknown step '{$step}'. Use: order | provoke | s1 | s2 | restore" );
}
