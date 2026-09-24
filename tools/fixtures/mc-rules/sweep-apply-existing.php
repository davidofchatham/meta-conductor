<?php
/**
 * mc-rules — FW-16 04 existing-posts applier sweep.
 *
 * Proves on real posts what H13 group 13 can only read off the source: that
 * `ExistingPostsApplier::run_batch()` is a save's pass, provoked in bulk.
 *
 * ISOLATION. Every step authors BOTH kind lists itself through
 * `mc_write_ordered_rules()` (the format list empty unless the step needs it —
 * the manifest's title/slug rule would otherwise rename every mc_item the run
 * reaches, don't 6f(e)). A run reaches every post of its rule's post types, not
 * just the subject, so each step snapshots every mc_item / mc_section post
 * (terms, the raw `mc_topics` mirror meta, status, title, slug) before arming
 * and puts it all back after its assertions.
 *
 * STANDING DOWN AT THE END OF EACH EVAL. The run's handlers write ACF mirrors,
 * and `AcfWriteQueue` flushes those at shutdown — after the put-back, with this
 * step's rules still installed — which would re-pass the restored posts. So
 * every step ends with the pass off switch held down for the rest of its
 * request. The same reason arming and put-back write raw meta, not
 * `update_field()`.
 *
 * Usage (one step per eval, don't 6e):
 *   wp eval-file .../sweep-apply-existing.php enabled   # = re-save end state
 *   wp eval-file .../sweep-apply-existing.php disabled  # one-time run
 *   wp eval-file .../sweep-apply-existing.php stale     # refused, nothing written
 *   wp eval-file .../sweep-apply-existing.php off       # pass off → error
 *   wp eval-file .../sweep-apply-existing.php fanout    # propagation children
 *   wp eval-file .../sweep-apply-existing.php rpt       # draft dependent reached
 *   wp eval-file .../sweep-apply-existing.php revisions # a format run leaves none
 *   wp eval-file .../sweep-apply-existing.php limit     # limit N = exactly N, across batches
 *   wp eval-file .../sweep-apply-existing.php continue  # resumes by ID; below/above-cursor insert
 *   wp eval-file .../sweep-apply-existing.php restart   # start over; per-user run state
 *   wp eval-file .../sweep-apply-existing.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\ExistingPostsApplier;
use BWS\MetaConductor\Core\RuleChoice;
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

$step = $args[0] ?? 'enabled';

/** The live dispatcher, or bail — an unregistered one means no passes. */
function mcae_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

/** Author the term list verbatim and empty the format list. */
function mcae_author( array $term_rows, array $format_rows = array() ) {
	mc_write_ordered_rules( $term_rows, OptionRuleStorage::KIND_TERM );
	mc_write_ordered_rules( $format_rows, OptionRuleStorage::KIND_FORMAT );
}

/** A `RuleChoice` value for the row at $pos, built as the page would build it. */
function mcae_choice( $kind, $pos ) {
	$rows = StorageFactory::get_instance()->get_kind_rules( $kind );
	return RuleChoice::encode( $kind, $pos, $rows[ $pos ] );
}

function mcae_hier_row( $enabled = true ) {
	return array(
		'type'                 => 'hierarchical_rules',
		'enabled'              => $enabled,
		'taxonomy'             => 'mc_topic',
		'post_types'           => array( 'mc_item' ),
		'inheritance_behavior' => 'ancestors',
		'inheritance_depth'    => 'all',
	);
}

/** Every fixture-type post, by ID. */
function mcae_fixture_posts() {
	return get_posts( array(
		'post_type'   => array( 'mc_item', 'mc_section' ),
		'post_status' => array( 'publish', 'draft', 'private', 'future', 'pending' ),
		'numberposts' => -1,
		'fields'      => 'ids',
	) );
}

/** Run $fn with passes stood down, then drop whatever it queued unrun. */
function mcae_quiet( callable $fn ) {
	add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
	$fn();
	remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );

	$d = mcae_dispatcher();
	$r = new ReflectionProperty( $d, 'dirty' );
	$r->setAccessible( true );
	$r->setValue( $d, array() );
}

/** Snapshot what a run can touch on every fixture-type post. */
function mcae_snapshot() {
	$snap = array();
	foreach ( mcae_fixture_posts() as $id ) {
		$p           = get_post( $id );
		$snap[ $id ] = array(
			'status'   => $p->post_status,
			'title'    => $p->post_title,
			'slug'     => $p->post_name,
			'mc_topic' => mc_terms( $id, 'mc_topic' ),
			'mc_flag'  => mc_terms( $id, 'mc_flag' ),
			'mirror'   => get_post_meta( $id, 'mc_topics', true ),
			'raw'      => get_post_meta( $id, '_bws_raw_title', true ),
			'applied'  => get_post_meta( $id, '_bws_applied_title', true ),
		);
	}
	return $snap;
}

/** Put a snapshot back, passes stood down. */
function mcae_put_back( array $snap ) {
	mcae_quiet( function () use ( $snap ) {
		foreach ( $snap as $id => $s ) {
			wp_update_post( array(
				'ID'          => $id,
				'post_status' => $s['status'],
				'post_title'  => $s['title'],
				'post_name'   => $s['slug'],
			) );
			wp_set_object_terms( $id, $s['mc_topic'], 'mc_topic' );
			wp_set_object_terms( $id, $s['mc_flag'], 'mc_flag' );
			update_post_meta( $id, 'mc_topics', $s['mirror'] );
			foreach ( array( '_bws_raw_title' => 'raw', '_bws_applied_title' => 'applied' ) as $key => $field ) {
				'' === $s[ $field ] ? delete_post_meta( $id, $key ) : update_post_meta( $id, $key, $s[ $field ] );
			}
		}
	} );
}

/** Set a post's own mc_topic terms in BOTH stores (native + raw mirror). */
function mcae_set_topics( $id, array $term_ids ) {
	wp_set_object_terms( $id, $term_ids, 'mc_topic' );
	update_post_meta( $id, 'mc_topics', array_map( 'strval', $term_ids ) );
}

/** Term slugs, sorted. */
function mcae_slugs( $id, $taxonomy = 'mc_topic' ) {
	$s = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $s ) ) {
		return array();
	}
	sort( $s );
	return $s;
}

/** Post IDs a result's report lists. */
function mcae_reported( array $result ) {
	$ids = array_map( 'intval', array_column( $result['rows'] ?? array(), 'post_id' ) );
	sort( $ids );
	return $ids;
}

/**
 * Arm a batching step: the hierarchical rule over mc_item, one post per batch
 * (a zero time box still passes one post), and every in-reach mc_item holding
 * Harbor alone, so "was this post passed" reads straight off its terms.
 *
 * @return int[] The rule's reach, ascending.
 */
function mcae_arm_batching( $harbor ) {
	mcae_author( array( mcae_hier_row() ) );
	add_filter( 'meta_conductor_apply_time_box', '__return_zero' );
	$reach = array_map( 'intval', get_posts( array(
		'post_type'   => 'mc_item',
		'post_status' => RuleChoice::DEFAULT_STATUSES,
		'numberposts' => -1,
		'fields'      => 'ids',
		'orderby'     => 'ID',
		'order'       => 'ASC',
	) ) );
	mcae_quiet( function () use ( $reach, $harbor ) {
		foreach ( $reach as $id ) {
			mcae_set_topics( $id, array( $harbor ) );
		}
	} );
	return $reach;
}

/** Reach posts the run has passed (Harbor expanded), ascending. */
function mcae_passed( array $ids ) {
	return array_values( array_filter( $ids, fn( $id ) => mcae_slugs( $id ) !== array( 'harbor' ) ) );
}

$pass = 0;
$fail = 0;
$t    = function ( $label, $got, $want ) use ( &$pass, &$fail ) {
	if ( mc_assert( $label, $got, $want ) ) {
		$pass++;
	} else {
		$fail++;
	}
};

$solo     = mc_pid( 'item-solo-a' );
$harbor   = mc_tid( 'topic-harbor' );
$expected = array( 'coastal', 'east', 'harbor', 'region' );
if ( ! $solo || ! $harbor ) {
	WP_CLI::error( 'Fixture item-solo-a / topic-harbor not found — seed mc-rules first.' );
}

switch ( $step ) {

	// ------------------------------------------------------------- enabled
	// The headline: an enabled rule applied to a post that predates it ends in
	// the state a re-save of that post would.
	case 'enabled':
		$snap = mcae_snapshot();
		mcae_author( array( mcae_hier_row() ) );

		mcae_quiet( function () use ( $solo, $harbor ) {
			mc_reset_subject( $solo );
			mcae_set_topics( $solo, array( $harbor ) );
		} );
		$result  = ExistingPostsApplier::run_batch( mcae_choice( OptionRuleStorage::KIND_TERM, 0 ) );
		$by_bulk = mcae_slugs( $solo );
		WP_CLI::log( '[bulk] ' . $result['message'] );

		mcae_put_back( $snap );
		mcae_quiet( function () use ( $solo, $harbor ) {
			mc_reset_subject( $solo );
			mcae_set_topics( $solo, array( $harbor ) );
		} );
		wp_update_post( array( 'ID' => $solo ) );
		mcae_dispatcher()->drain();
		$by_save = mcae_slugs( $solo );

		$t( '§ae1 run reports success', $result['status'], 'success' );
		$t( '§ae1 bulk applies the rule', $by_bulk, $expected );
		$t( '§ae1 bulk == re-save', $by_bulk, $by_save );
		$t( '§ae1 the subject is in the report', in_array( $solo, mcae_reported( $result ), true ), true );

		$row = current( array_filter( $result['rows'], fn( $r ) => (int) $r['post_id'] === (int) $solo ) );
		$t( '§ae1 report lists the terms added', $row['terms']['mc_topic']['added'] ?? array(), array( 'Coastal', 'East', 'Region' ) );

		mcae_put_back( $snap );
		break;

	// ------------------------------------------------------------ disabled
	// A one-time run: a disabled row runs for the batch only. Storage never
	// changes, and a later save neither maintains nor undoes the effect.
	case 'disabled':
		$snap = mcae_snapshot();
		mcae_author( array( mcae_hier_row( false ) ) );
		$stored = get_option( mc_sweep_option() );

		mcae_quiet( function () use ( $solo, $harbor ) {
			mc_reset_subject( $solo );
			mcae_set_topics( $solo, array( $harbor ) );
		} );
		$result = ExistingPostsApplier::run_batch( mcae_choice( OptionRuleStorage::KIND_TERM, 0 ) );

		$included = new ReflectionProperty( TermDispatcher::class, 'included' );
		$included->setAccessible( true );

		$t( '§ae2 the disabled row ran', mcae_slugs( $solo ), $expected );
		$t( '§ae2 storage untouched', get_option( mc_sweep_option() ) === $stored, true );
		$t( '§ae2 override cleared after the batch', $included->getValue(), null );

		// A later save does not undo it…
		wp_update_post( array( 'ID' => $solo ) );
		mcae_dispatcher()->drain();
		$t( '§ae2 a later save does not undo it', mcae_slugs( $solo ), $expected );

		// …and a later term edit is not re-expanded.
		wp_set_object_terms( $solo, array( mc_tid( 'topic-inland' ) ), 'mc_topic' );
		mcae_dispatcher()->drain();
		$t( '§ae2 a later edit is not re-applied', mcae_slugs( $solo ), array( 'inland' ) );

		mcae_put_back( $snap );
		break;

	// --------------------------------------------------------------- stale
	case 'stale':
		$snap = mcae_snapshot();
		mcae_author( array( mcae_hier_row() ) );
		$choice = mcae_choice( OptionRuleStorage::KIND_TERM, 0 );

		// The row is edited after the page built the dropdown.
		$edited                      = mcae_hier_row();
		$edited['inheritance_depth'] = 'immediate';
		mcae_author( array( $edited ) );

		mcae_quiet( function () use ( $solo, $harbor ) {
			mc_reset_subject( $solo );
			mcae_set_topics( $solo, array( $harbor ) );
		} );
		$result = ExistingPostsApplier::run_batch( $choice );

		$t( '§ae3 a stale choice is refused', $result['status'], 'error' );
		$t( '§ae3 …asking for a reload', false !== strpos( $result['message'], 'reload' ), true );
		$t( '§ae3 …and nothing is written', mcae_slugs( $solo ), array( 'harbor' ) );

		mcae_put_back( $snap );
		break;

	// ----------------------------------------------------------------- off
	case 'off':
		$snap = mcae_snapshot();
		mcae_author( array( mcae_hier_row() ) );

		mcae_quiet( function () use ( $solo, $harbor ) {
			mc_reset_subject( $solo );
			mcae_set_topics( $solo, array( $harbor ) );
		} );
		add_filter( 'meta_conductor_term_pass_enabled', '__return_false' );
		$result = ExistingPostsApplier::run_batch( mcae_choice( OptionRuleStorage::KIND_TERM, 0 ) );
		remove_filter( 'meta_conductor_term_pass_enabled', '__return_false' );

		WP_CLI::log( '[off] ' . $result['message'] );
		$t( '§ae4 a switched-off pass is an error, not "done"', $result['status'], 'error' );
		$t( '§ae4 …and nothing is written', mcae_slugs( $solo ), array( 'harbor' ) );

		mcae_put_back( $snap );
		break;

	// -------------------------------------------------------------- fanout
	// The chosen rule (related, publish-only) reaches the published grand; the
	// draft chain below it is outside the reach and is reconciled by
	// propagation's fan-out, drained in the same batch — and not reported.
	case 'fanout':
		$chain = array(
			'grand'  => mc_pid( 'section-grand' ),
			'parent' => mc_pid( 'section-parent' ),
			'child'  => mc_pid( 'section-child' ),
			'draft'  => mc_pid( 'section-draft' ),
		);
		$west  = mc_tid( 'topic-west' );
		$snap  = mcae_snapshot();

		mcae_author( array(
			array(
				'type'              => 'propagation_rules',
				'enabled'           => true,
				'taxonomy'          => 'mc_topic',
				'post_types'        => array( 'mc_section' ),
				'conflict_handling' => 'merge',
			),
			array(
				'type'            => 'related_rules',
				'enabled'         => true,
				'post_types'      => array( 'mc_section' ),
				'post_status'     => array( 'publish' ),
				'trigger_type'    => 'term',
				'trigger_term_id' => array( $west ),
				'target_term_id'  => mc_tid( 'topic-featured' ),
				'bidirectional'   => false,
			),
		) );

		mcae_quiet( function () use ( $chain, $west ) {
			foreach ( $chain as $id ) {
				mcae_set_topics( $id, array() );
			}
			mcae_set_topics( $chain['grand'], array( $west ) );
			wp_update_post( array( 'ID' => $chain['parent'], 'post_status' => 'draft' ) );
			wp_update_post( array( 'ID' => $chain['child'], 'post_status' => 'draft' ) );
		} );

		$result = ExistingPostsApplier::run_batch( mcae_choice( OptionRuleStorage::KIND_TERM, 1 ) );
		WP_CLI::log( '[fanout] ' . $result['message'] );

		foreach ( $chain as $name => $id ) {
			$t( "§ae5 $name holds West + Featured", mcae_slugs( $id ), array( 'featured', 'west' ) );
		}
		// Other published mc_section posts may legitimately change too (any
		// that already hold West), so assert the chain, not the whole report.
		$t( '§ae5 the grand is reported', in_array( (int) $chain['grand'], mcae_reported( $result ), true ), true );
		$t( '§ae5 the fan-out-only chain is not',
			array_values( array_intersect( array( $chain['parent'], $chain['child'], $chain['draft'] ), mcae_reported( $result ) ) ),
			array()
		);
		$t( '§ae5 the report says fan-out is not listed', false !== strpos( $result['note'] ?? '', 'fan-out' ), true );

		mcae_put_back( $snap );
		break;

	// ----------------------------------------------------------------- rpt
	// related_post_terms' post_status gates the SOURCE, so a draft dependent
	// of a published source is inside the reach (don't 6e(b)).
	case 'rpt':
		$beta   = mc_pid( 'item-beta' );
		$holder = mc_pid( 'section-holder' );
		$snap   = mcae_snapshot();

		mcae_author( array(
			array(
				'type'                   => 'related_post_terms_rules',
				'enabled'                => true,
				'acf_field_name'         => 'mc_section:mc_related_items:field_mc_related_items',
				'reverse_acf_field_name' => 'mc_item:mc_parent_section:field_mc_parent_section',
				'holder_role'            => 'source',
				'taxonomy'               => 'mc_topic',
				'keep_in_sync'           => true,
				'post_status'            => array( 'publish' ),
			),
		) );

		mcae_quiet( function () use ( $beta ) {
			mcae_set_topics( $beta, array() );
			wp_update_post( array( 'ID' => $beta, 'post_status' => 'draft' ) );
		} );
		$t( '§ae6 precondition: source published', get_post_status( $holder ), 'publish' );

		$result = ExistingPostsApplier::run_batch( mcae_choice( OptionRuleStorage::KIND_TERM, 0 ) );
		WP_CLI::log( '[rpt] ' . $result['message'] );

		$t( '§ae6 draft dependent takes the source terms', mcae_slugs( $beta ), mcae_slugs( $holder ) );
		$t( '§ae6 …and is reported', in_array( (int) $beta, mcae_reported( $result ), true ), true );

		mcae_put_back( $snap );
		break;

	// ----------------------------------------------------------- revisions
	// A format run rewrites post rows, and wp_update_post() would leave one
	// revision per post. The fixture types do not support revisions, so the
	// step turns support on for this request — otherwise the check is vacuous.
	case 'revisions':
		add_post_type_support( 'mc_item', 'revisions' );
		$snap   = mcae_snapshot();
		$status = get_option( 'bws_title_slug_rule_status' );
		$count  = function () {
			$n = 0;
			foreach ( get_posts( array( 'post_type' => 'mc_item', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
				$n += count( wp_get_post_revisions( $id, array( 'fields' => 'ids' ) ) );
			}
			return $n;
		};

		mcae_author( array(), array(
			array(
				'type'          => 'title_slug_rules',
				'enabled'       => true,
				'name'          => 'Sweep apply-existing title',
				'post_type'     => 'mc_item',
				'title_pattern' => '{default_title} AE',
			),
		) );

		$before = $count();
		$result = ExistingPostsApplier::run_batch( mcae_choice( OptionRuleStorage::KIND_FORMAT, 0 ) );
		WP_CLI::log( '[revisions] ' . $result['message'] );

		$t( '§ae7 the format run changed posts', $result['changed'] > 0, true );
		$t( '§ae7 …and created no revisions', $count(), $before );

		mcae_put_back( $snap );
		false === $status ? delete_option( 'bws_title_slug_rule_status' ) : update_option( 'bws_title_slug_rule_status', $status, false );
		foreach ( array_keys( $snap ) as $id ) {
			foreach ( wp_get_post_revisions( $id, array( 'fields' => 'ids' ) ) as $rev ) {
				wp_delete_post_revision( $rev );
			}
		}
		break;

	// --------------------------------------------------------------- limit
	// A limit caps the run at exactly N posts in total, across batches.
	case 'limit':
		$snap   = mcae_snapshot();
		$reach  = mcae_arm_batching( $harbor );
		$choice = mcae_choice( OptionRuleStorage::KIND_TERM, 0 );
		ExistingPostsApplier::start_over( $choice );

		$first  = ExistingPostsApplier::run_batch( $choice, 3 );
		$second = ExistingPostsApplier::run_batch( $choice, 99 ); // a Continue: the run's own limit holds
		$third  = ExistingPostsApplier::run_batch( $choice );
		WP_CLI::log( '[limit] ' . $third['message'] );

		$t( '§ae8 batch 1 passes one post', array( $first['processed'], $first['done'] ), array( 1, false ) );
		$t( '§ae8 total is the limit', $first['total'], 3 );
		$t( '§ae8 batch 2 continues', array( $second['processed'], $second['done'] ), array( 2, false ) );
		$t( '§ae8 batch 3 completes at N', array( $third['processed'], $third['done'] ), array( 3, true ) );
		$t( '§ae8 exactly the first N posts were passed', mcae_passed( $reach ), array_slice( $reach, 0, 3 ) );
		$t( '§ae8 changed accumulates across batches', $third['changed'], 3 );
		$t( '§ae8 the sample fills across batches', mcae_reported( $third ), array_slice( $reach, 0, 3 ) );

		// Completion deleted the run state: the next batch starts a fresh run.
		mcae_quiet( function () use ( $reach, $harbor ) {
			mcae_set_topics( $reach[0], array( $harbor ) );
		} );
		$fresh = ExistingPostsApplier::run_batch( $choice );
		$t( '§ae8 a completed run starts afresh', array( $fresh['processed'], $fresh['total'] ), array( 1, count( $reach ) ) );
		ExistingPostsApplier::start_over( $choice );

		mcae_put_back( $snap );
		break;

	// ------------------------------------------------------------ continue
	// Continue resumes by ID: a post created between batches below the cursor
	// is never passed, one above it is, and total stays what it was at start.
	case 'continue':
		$snap   = mcae_snapshot();
		$reach  = mcae_arm_batching( $harbor );
		$choice = mcae_choice( OptionRuleStorage::KIND_TERM, 0 );
		ExistingPostsApplier::start_over( $choice );

		$first = ExistingPostsApplier::run_batch( $choice );
		$t( '§ae9 batch 1 passes the lowest ID only', mcae_passed( $reach ), array( $reach[0] ) );
		$second = ExistingPostsApplier::run_batch( $choice );
		$t( '§ae9 batch 2 resumes at the next ID', mcae_passed( $reach ), array_slice( $reach, 0, 2 ) );

		// The highest free ID inside the reach's span; run on until the
		// cursor is past it.
		$below = 0;
		for ( $id = end( $reach ) - 1; $id > $reach[0]; $id-- ) {
			if ( ! get_post( $id ) ) {
				$below = $id;
				break;
			}
		}
		$t( '§ae9 precondition: a free ID inside the reach', $below > 0, true );
		$last = $second;
		while ( ! $last['done'] && max( mcae_passed( $reach ) ) < $below ) {
			$last = ExistingPostsApplier::run_batch( $choice );
		}
		$t( '§ae9 precondition: the run is mid-way', $last['done'], false );

		$added = array();
		mcae_quiet( function () use ( $below, $harbor, &$added ) {
			foreach ( array( 'below' => $below, 'above' => 0 ) as $name => $import ) {
				$added[ $name ] = wp_insert_post( array(
					'post_type'   => 'mc_item',
					'post_status' => 'publish',
					'post_title'  => "Sweep AE $name",
					'import_id'   => $import,
				) );
				mcae_set_topics( $added[ $name ], array( $harbor ) );
			}
		} );
		$t( '§ae9 the below post landed below the cursor', $added['below'], $below );

		for ( $i = 0; $i < 50 && ! $last['done']; $i++ ) {
			$last = ExistingPostsApplier::run_batch( $choice );
		}
		WP_CLI::log( '[continue] ' . $last['message'] );

		$t( '§ae9 batch 2 counted two', $second['processed'], 2 );
		$t( '§ae9 total counted once, at the start', array( $first['total'], $last['total'] ), array( count( $reach ), count( $reach ) ) );
		$t( '§ae9 every original post passed', mcae_passed( $reach ), $reach );
		$t( '§ae9 the post below the cursor was not passed', mcae_slugs( $added['below'] ), array( 'harbor' ) );
		$t( '§ae9 the post above it was', mcae_slugs( $added['above'] ), $expected );
		$t( '§ae9 processed counts the run, not the batch', $last['processed'], count( $reach ) + 1 );
		$t( '§ae9 changed accumulates across batches', $last['changed'], count( $reach ) + 1 );
		$t( '§ae9 the sample fills to the cap across batches', count( $last['rows'] ), min( ExistingPostsApplier::REPORT_ROWS, count( $reach ) + 1 ) );

		foreach ( $added as $id ) {
			wp_delete_post( $id, true );
		}
		mcae_put_back( $snap );
		break;

	// ------------------------------------------------------------ restart
	// Start over discards progress: the next batch passes the first post again.
	case 'restart':
		$snap   = mcae_snapshot();
		$reach  = mcae_arm_batching( $harbor );
		$choice = mcae_choice( OptionRuleStorage::KIND_TERM, 0 );
		ExistingPostsApplier::start_over( $choice );

		ExistingPostsApplier::run_batch( $choice );
		mcae_quiet( function () use ( $reach, $harbor ) {
			mcae_set_topics( $reach[0], array( $harbor ) );
		} );

		ExistingPostsApplier::start_over( $choice );
		$again = ExistingPostsApplier::run_batch( $choice );

		$t( '§ae10 start over restarts from the first post', mcae_passed( $reach ), array( $reach[0] ) );
		$t( '§ae10 …with fresh counts', array( $again['processed'], $again['changed'] ), array( 1, 1 ) );

		// Another user's run of the same choice is its own.
		$user = wp_get_current_user()->ID;
		wp_set_current_user( $user + 1 );
		$other = ExistingPostsApplier::run_batch( $choice );
		ExistingPostsApplier::start_over( $choice );
		wp_set_current_user( $user );
		$t( '§ae10 another user gets separate run state', $other['processed'], 1 );

		ExistingPostsApplier::start_over( $choice );
		mcae_put_back( $snap );
		break;

	// ------------------------------------------------------------- restore
	case 'restore':
		mcae_quiet( function () {
			foreach ( array( 'section-parent', 'section-child', 'item-beta' ) as $slug ) {
				wp_update_post( array( 'ID' => mc_pid( $slug ), 'post_status' => 'publish' ) );
			}
		} );
		mc_restore();
		foreach ( get_posts( array( 'post_type' => 'mc_item', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
			WP_CLI::log( sprintf( '[restore] mc_item %d %s', $p->ID, $p->post_name ) );
		}
		break;

	default:
		WP_CLI::error( "Unknown step '$step' — enabled|disabled|stale|off|fanout|rpt|revisions|limit|continue|restart|restore." );
}

// Hold passes down for the rest of this request: the ACF write queue flushes at
// shutdown, after the put-back, with this step's rules still installed.
add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );

if ( 'restore' !== $step ) {
	WP_CLI::log( sprintf( '[sweep apply-existing/%s] %d passed, %d failed', $step, $pass, $fail ) );
	if ( $fail ) {
		WP_CLI::error( sprintf( 'apply-existing sweep step "%s" FAILED (%d assertions).', $step, $fail ) );
	}
}
