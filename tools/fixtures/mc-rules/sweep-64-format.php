<?php
/**
 * mc-rules — #64 format-dispatcher behaviour sweep (Phase 4 Gate 2, testbed half).
 *
 * Proves the one thing the static harness cannot: that the DERIVED cross-kind
 * order is real at runtime — a title pattern reading `{term:mc_topic}` picks up
 * a term a TERM rule wrote in the SAME pass, not the value the taxonomy held
 * before the save.
 *
 * WHY THIS SUBJECT AND THIS PAIR. `item-solo-a` with `{Harbor}` set by hand and
 * the fixture's `ancestors`/`all` hierarchy rule live. `{term:TAX}` returns the
 * first term by NAME, so the two orders give visibly different titles:
 *
 *   term pass first (correct)  Harbor's ancestors land → {Coastal, East,
 *                              Harbor, Region} → first by name is **Coastal**.
 *   format pass first (the old ordering accident, and what a coalesced term
 *                              pass would silently have produced) → only
 *                              **Harbor** is on the post → "…Harbor".
 *
 * Both titles are perfectly plausible, which is the whole reason this needs a
 * sweep: nothing about the wrong one looks wrong.
 *
 * THE RULE UNDER TEST is written by the sweep, not seeded: the manifest's
 * title/slug rule is slug-only, and the cross-kind read is a TITLE token. The
 * pattern is `{default_title} {term:mc_topic}` with no slug pattern, so the
 * slug derives from the computed title — which also exercises the derived-slug
 * branch and the idempotency meta in one rule.
 *
 * ⚠️ THIS SWEEP RENAMES POSTS. `post_name` is what `mc_pid()` looks a fixture
 * post up by, so the subject's ID is cached in an option the moment it is first
 * resolved (`mc64_subject()`); every later step reads that. `restore` puts the
 * title and slug back from the manifest and drops the cache. The `bulk` step
 * additionally snapshots and repairs every OTHER mc_item, because bulk apply
 * legitimately reaches all of them.
 *
 * Usage (one step per eval):
 *   wp eval-file .../sweep-64-format.php terms       # the same-pass term read
 *   wp eval-file .../sweep-64-format.php provoke     # editor save / ACF save
 *   wp eval-file .../sweep-64-format.php bulk        # bulk apply
 *   wp eval-file .../sweep-64-format.php idempotent  # a second pass changes nothing
 *   wp eval-file .../sweep-64-format.php s1          # write, let shutdown drain
 *   wp eval-file .../sweep-64-format.php s2          # assert what s1's shutdown did
 *   wp eval-file .../sweep-64-format.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'terms';

const MC64_SUBJECT_OPTION = 'mc64_subject_id';
const MC64_BASE_TITLE     = 'MC Item Solo A';
const MC64_BASE_SLUG      = 'mc-item-solo-a';

/** The live term dispatcher — it owns the drain for BOTH kinds (#64). */
function mc64_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

/**
 * The subject, by ID.
 *
 * `mc_pid()` resolves by `post_name`, and this sweep rewrites `post_name` — so
 * the ID is cached on first resolution and every later eval reads the cache.
 * Without this, step 2 onwards cannot find the post it just renamed.
 *
 * @return int
 */
function mc64_subject() {
	$id = mc_pid( 'item-solo-a' );
	if ( $id ) {
		update_option( MC64_SUBJECT_OPTION, $id, false );
		return $id;
	}
	$id = (int) get_option( MC64_SUBJECT_OPTION, 0 );
	if ( ! $id || ! get_post( $id ) ) {
		WP_CLI::error( 'Fixture post item-solo-a not found — seed mc-rules first.' );
	}
	return $id;
}

/**
 * Isolate the rule types this step needs and install the title/slug rule.
 *
 * Written through mc_write_rule_types(), which lands the rules in the ordered
 * kind lists in the documented KIND_TYPES order — no step here depends on
 * within-kind order.
 *
 * @param string[] $keep_term_types Term rule types to leave enabled.
 * @param array[]  $ts_rules        Rules to write as the format list.
 */
function mc64_setup( array $keep_term_types, array $ts_rules ) {
	mc_isolate( array_merge( $keep_term_types, array( 'title_slug_rules' ) ) );
	mc_write_rule_types( array( 'title_slug_rules' => $ts_rules ) );
}

/** The rule under test: title from the post's first topic, slug derived. */
function mc64_title_rule() {
	return array(
		array(
			'enabled'       => true,
			'name'          => 'Sweep 64 topic title',
			'post_type'     => 'mc_item',
			'title_pattern' => '{default_title} {term:mc_topic}',
		),
	);
}

/**
 * Put the subject back to base title, base slug, no terms, no idempotency meta
 * — WITHOUT letting a pass run.
 *
 * The arming writes are real post saves, and `wp_update_post()` fires
 * `wp_after_insert_post`, on which the dispatcher drains. So the established
 * off switch is held down for the duration: that is the documented way to make
 * a write invisible to the pass (it is what the fixture seeder uses), and it
 * leaves the post state real while discarding only the signal.
 *
 * @param int   $post_id Subject.
 * @param int[] $terms   Term IDs to set, un-passed.
 */
function mc64_arm( $post_id, array $terms = array() ) {
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );

	mc_reset_subject( $post_id );
	delete_post_meta( $post_id, '_bws_raw_title' );
	delete_post_meta( $post_id, '_bws_applied_title' );
	wp_update_post( array(
		'ID'         => $post_id,
		'post_title' => MC64_BASE_TITLE,
		'post_name'  => MC64_BASE_SLUG,
	) );
	if ( $terms ) {
		wp_set_object_terms( $post_id, $terms, 'mc_topic' );
	}

	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );

	// Drop whatever the arming writes queued, without running any of it.
	$d = mc64_dispatcher();
	$r = new ReflectionProperty( $d, 'dirty' );
	$r->setAccessible( true );
	$r->setValue( $d, array() );
}

/** The subject's stored title, read past the object cache. */
function mc64_title( $post_id ) {
	clean_post_cache( $post_id );
	$p = get_post( $post_id );
	return $p ? $p->post_title : '';
}

/** The subject's stored slug, read past the object cache. */
function mc64_slug( $post_id ) {
	clean_post_cache( $post_id );
	$p = get_post( $post_id );
	return $p ? $p->post_name : '';
}

/** Restore title + slug from the manifest, with passes stood down. */
function mc64_rename_back( $post_id, $title, $slug ) {
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	wp_update_post( array( 'ID' => $post_id, 'post_title' => $title, 'post_name' => $slug ) );
	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
}

$subject = mc64_subject();
$HIER    = 'hierarchical_rules';

switch ( $step ) {

	// ---------------------------------------------------------------- terms
	// The headline: the format pass reads terms the TERM pass wrote, in the
	// same pass. Both halves are asserted, because a title that reads the
	// pre-pass terms is a perfectly plausible title.
	case 'terms':
		// (1) No term rule. The title reads exactly what the author set.
		mc64_setup( array(), mc64_title_rule() );
		mc64_arm( $subject );
		wp_set_object_terms( $subject, array( mc_tid( 'topic-harbor' ) ), 'mc_topic' );
		mc64_dispatcher()->drain();
		$raw_terms = mc64_title( $subject );
		WP_CLI::log( '[no term rule ] ' . $raw_terms . '  terms=' . implode( ',', wp_get_object_terms( $subject, 'mc_topic', array( 'fields' => 'slugs' ) ) ) );

		// (2) Hierarchy live. Harbor's ancestors land in the term pass, so the
		//     first topic by NAME becomes Coastal — a value that exists only
		//     because the term pass ran first.
		mc64_setup( array( $HIER ), mc64_title_rule() );
		mc64_arm( $subject );
		wp_set_object_terms( $subject, array( mc_tid( 'topic-harbor' ) ), 'mc_topic' );
		mc64_dispatcher()->drain();
		$with_terms = mc64_title( $subject );
		WP_CLI::log( '[hierarchy on ] ' . $with_terms . '  terms=' . implode( ',', wp_get_object_terms( $subject, 'mc_topic', array( 'fields' => 'slugs' ) ) ) );

		mc_assert( '§64a  the title reads the post\'s own term', $raw_terms, MC64_BASE_TITLE . ' Harbor' );
		mc_assert( '§64b  the title reads a term the TERM pass wrote in this pass', $with_terms, MC64_BASE_TITLE . ' Coastal' );
		mc_assert( '§64c  the term pass ran first', $raw_terms === $with_terms ? 'same' : 'different', 'different' );
		mc_assert( '§64d  the slug derives from the computed title', mc64_slug( $subject ), 'mc-item-solo-a-coastal' );
		break;

	// -------------------------------------------------------------- provoke
	// The format pass is reached from the shared drain, so it must not care
	// WHICH trigger raised the mark. Three provocations, one expected result.
	case 'provoke':
		mc64_setup( array( $HIER ), mc64_title_rule() );
		$harbor = mc_tid( 'topic-harbor' );

		// (1) editor-shaped save → save_post / wp_after_insert_post.
		mc64_arm( $subject, array( $harbor ) );
		wp_update_post( array( 'ID' => $subject ) );
		mc64_dispatcher()->drain();
		$by_save = mc64_title( $subject );

		// (2) ACF save → acf/save_post. Fired directly so the step does not
		//     need an ACF form; the dispatcher's trigger is the same either way.
		mc64_arm( $subject, array( $harbor ) );
		do_action( 'acf/save_post', $subject );
		mc64_dispatcher()->drain();
		$by_acf = mc64_title( $subject );

		// (3) term write → set_object_terms.
		mc64_arm( $subject );
		wp_set_object_terms( $subject, array( $harbor ), 'mc_topic' );
		mc64_dispatcher()->drain();
		$by_terms = mc64_title( $subject );

		WP_CLI::log( '[post save] ' . $by_save );
		WP_CLI::log( '[acf save ] ' . $by_acf );
		WP_CLI::log( '[term set ] ' . $by_terms );

		mc_assert( '§64e  editor save applies the format rule', $by_save, MC64_BASE_TITLE . ' Coastal' );
		mc_assert( '§64f  ACF save == editor save', $by_acf, $by_save );
		mc_assert( '§64g  term write == editor save', $by_terms, $by_save );
		break;

	// ----------------------------------------------------------------- bulk
	// Bulk apply provokes a full ordered FORMAT pass per post rather than
	// applying the handler's rules itself, so a bulk run and a save over the
	// same rule set cannot diverge WITHIN the kind. It is deliberately the
	// format pass ALONE — the title/slug button reconciles titles, the term
	// list's own button reconciles terms — so the subject is armed with the
	// term state already settled, and the cross-kind order is left to the
	// `terms` step where it belongs.
	//
	// Bulk legitimately reaches every mc_item, so the others are snapshotted
	// and put back.
	case 'bulk':
		mc64_setup( array( $HIER ), mc64_title_rule() );
		mc64_arm( $subject, array(
			mc_tid( 'topic-harbor' ),
			mc_tid( 'topic-coastal' ),
			mc_tid( 'topic-east' ),
			mc_tid( 'topic-region' ),
		) );

		$others = array();
		foreach ( get_posts( array(
			'post_type'      => 'mc_item',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
		) ) as $p ) {
			if ( (int) $p->ID !== (int) $subject ) {
				$others[ (int) $p->ID ] = array( $p->post_title, $p->post_name );
			}
		}

		$handler = \BWS\MetaConductor\TaxonomyManager::get_instance()->get_handler( 'title_slug' );
		$handler->process_existing_posts( 200, 0 );
		$by_bulk = mc64_title( $subject );

		foreach ( $others as $id => $pair ) {
			mc64_rename_back( $id, $pair[0], $pair[1] );
		}

		WP_CLI::log( '[bulk apply] ' . $by_bulk . '  (' . count( $others ) . ' other mc_item restored)' );
		mc_assert( '§64h  bulk apply runs the format pass', $by_bulk, MC64_BASE_TITLE . ' Coastal' );
		break;

	// ------------------------------------------------------------ idempotent
	// A pass runs every rule whether or not its own trigger fired, so the
	// applier has to be idempotent — and the idempotency state has to survive
	// a re-pass. The failure mode is compounding: "…Coastal Coastal".
	case 'idempotent':
		mc64_setup( array( $HIER ), mc64_title_rule() );
		mc64_arm( $subject, array( mc_tid( 'topic-harbor' ) ) );
		wp_update_post( array( 'ID' => $subject ) );
		mc64_dispatcher()->drain();
		$first = mc64_title( $subject );

		mc64_dispatcher()->mark_dirty( $subject );
		mc64_dispatcher()->drain();
		$second = mc64_title( $subject );

		WP_CLI::log( '[pass 1] ' . $first );
		WP_CLI::log( '[pass 2] ' . $second );

		mc_assert( '§64i  a second pass changes nothing', $second, $first );
		mc_assert(
			'§64j  the stored base title is the base, not the applied title',
			(string) get_post_meta( $subject, '_bws_raw_title', true ),
			MC64_BASE_TITLE
		);
		mc_assert(
			'§64k  the applied title is recorded',
			(string) get_post_meta( $subject, '_bws_applied_title', true ),
			MC64_BASE_TITLE . ' Coastal'
		);
		break;

	// ------------------------------------------------- s1/s2: shutdown drain
	// The format pass has no hook of its own, so the only thing that runs it
	// on a write involving no explicit drain is the term dispatcher's shutdown
	// drain. Two evals: s1 writes and returns, s2 reads what shutdown did.
	case 's1':
		mc64_setup( array( $HIER ), mc64_title_rule() );
		mc64_arm( $subject );
		wp_set_object_terms( $subject, array( mc_tid( 'topic-harbor' ) ), 'mc_topic' );
		WP_CLI::log( '[s1] wrote {harbor}; NOT draining — shutdown must. Now run s2.' );
		WP_CLI::log( '[s1] pre-shutdown title: ' . mc64_title( $subject ) );
		break;

	case 's2':
		mc_assert( '§64l  the shutdown drain ran s1\'s format pass', mc64_title( $subject ), MC64_BASE_TITLE . ' Coastal' );
		mc_assert( '§64m  …and its slug', mc64_slug( $subject ), 'mc-item-solo-a-coastal' );
		break;

	// -------------------------------------------------------------- restore
	case 'restore':
		mc64_rename_back( $subject, MC64_BASE_TITLE, MC64_BASE_SLUG );
		delete_post_meta( $subject, '_bws_raw_title' );
		delete_post_meta( $subject, '_bws_applied_title' );
		delete_option( MC64_SUBJECT_OPTION );

		// mc_restore() rewrites the kind lists themselves in the documented
		// KIND_TYPES order (#66 — they ARE the stored shape), so this sweep's
		// hand-built lists go with them.
		mc_restore( array( $subject ) );
		WP_CLI::log( '[restore] rules rebuilt from manifest, subject renamed back + reset.' );
		break;

	default:
		WP_CLI::error( "Unknown step '{$step}'. Use: terms | provoke | bulk | idempotent | s1 | s2 | restore" );
}
