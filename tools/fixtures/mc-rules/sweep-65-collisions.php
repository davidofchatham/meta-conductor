<?php
/**
 * mc-rules — #65 behaviour sweep: the collision advisory, both surfaces.
 *
 * H14 proves the predicate against hand-built rows. What it cannot see is
 * everything between the predicate and the author:
 *
 *   §65a  The detector run over the REAL seeded rule set, read the way the
 *         save path reads it — `get_authored_kind_rules()` through
 *         `authored_kind_list()`, whose reconciliation decides the list ORDER
 *         the warning's position numbers refer to. A harness that builds its
 *         own rows can agree with a detector that disagrees with storage.
 *   §65b  The fixture's two ready-made cases actually land: the #51 pair
 *         (hierarchy `ancestors` + level restriction `one_per_level`,
 *         ancestors off, one taxonomy, overlapping post types) is flagged with
 *         its contradiction NAMED, and the #69 pair (time_based[1] and [2],
 *         both on topic-archived) is flagged as mutual annihilation.
 *   §65c  The #39 configuration — propagation and level restriction on one
 *         taxonomy — warns, and moving one to a disjoint post type clears it.
 *   §65d  Order is the only thing a collision is about, not currency: sliding
 *         a date rule's window does not clear its warning, and re-targeting it
 *         does.
 *   §65e  The PASSIVE surface: the real `bws-meta-conductor/settings_saved`
 *         action recomputes and PERSISTS, and the section the tab renders on
 *         the next load carries a notice built from what was persisted. This
 *         is the half that catches an author who never presses the button, and
 *         nothing static can prove the hook is actually wired to it.
 *   §65f  The ON-DEMAND surface: the real Wireframe action filter name
 *         (`…/action/settings/{kind}_collision_check/run`) is answered, over
 *         IN-FLIGHT rows, and answering it persists NOTHING — an unsaved row
 *         must not end up in the notice describing the saved rule set.
 *   §65g  A rule set with no collisions leaves no notice behind, so the notice
 *         is not sticky.
 *
 * The advisory is advisory: nothing here writes a term, and no step needs a
 * dispatcher pass. That is itself the point of §65h.
 *
 * Usage (each step is independent; they share no request state):
 *   wp eval-file .../sweep-65-collisions.php seeded    # §65a/b, §65h
 *   wp eval-file .../sweep-65-collisions.php config    # §65c/d
 *   wp eval-file .../sweep-65-collisions.php surfaces  # §65e/f/g
 *   wp eval-file .../sweep-65-collisions.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Admin\CollisionDetector;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

$step = $args[0] ?? 'seeded';
$pass = 0;
$fail = 0;

/** mc_assert, tallying. */
$a = function ( $label, $got, $want ) use ( &$pass, &$fail ) {
	if ( mc_assert( $label, $got, $want ) ) {
		$pass++;
	} else {
		$fail++;
	}
};

/**
 * Write an explicit ordered rule list — type arrays AND kind list together.
 *
 * Both halves, for the reason `mc61_author()` gives: `authored_kind_list()`
 * keeps a stored list only while it still agrees, per type, with the type-keyed
 * arrays, so writing the list alone is discarded and rebuilt in KIND_TYPES
 * order — silently defeating the order the position numbers refer to.
 *
 * @param array[] $rows Rules in authored order, each carrying a `type` key.
 * @return void
 */
function mc65_author( array $rows ) {
	$opt      = mc_sweep_option();
	$manifest = mc_sweep_manifest();
	$settings = get_option( $opt, array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	foreach ( array_keys( $manifest['mc_rules'] ) as $type ) {
		$settings[ $type ] = array();
	}
	foreach ( $rows as $row ) {
		$settings[ $row['type'] ][] = $row;
	}

	$settings[ OptionRuleStorage::KIND_TERM ] = $rows;
	update_option( $opt, $settings );
	mc_sweep_clear_cache();
}

/** The term-kind findings for whatever is in storage right now. */
function mc65_scan( $kind = null ) {
	$kind = $kind ?: OptionRuleStorage::KIND_TERM;
	mc_sweep_clear_cache();

	return CollisionDetector::detect(
		$kind,
		StorageFactory::get_instance()->get_authored_kind_rules( $kind )
	);
}

/** A post's mc_topic term slugs, sorted. */
function mc65_slugs( $post_id, $taxonomy = 'mc_topic' ) {
	$terms = wp_get_object_terms( (int) $post_id, $taxonomy );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$slugs = wp_list_pluck( $terms, 'slug' );
	sort( $slugs );

	return $slugs;
}

/** Findings reduced to "position-position:code" — readable and order-fixed. */
function mc65_summary( array $found ) {
	$out = array();
	foreach ( $found as $f ) {
		$out[] = sprintf( '%d-%d:%s', $f['a']['index'], $f['b']['index'], $f['code'] );
	}

	return $out;
}

// ---------------------------------------------------------------------------

switch ( $step ) {

	// -----------------------------------------------------------------------
	// §65a/b/h — the real seeded rule set.
	// -----------------------------------------------------------------------
	case 'seeded':
		mc_restore();

		// §65h's baseline, taken BEFORE the scan.
		$solo   = mc_pid( 'item-solo-a' );
		$before = mc65_slugs( $solo );

		$found = mc65_scan();

		// The fixture is a deliberate torture set, so the expected list is
		// spelled out in full rather than counted. Positions are the authored
		// kind-list order (KIND_TYPES order after the restore's rebuild):
		//   0 propagation(mc_topic, mc_section)   5 time_based[2](archived)
		//   1 acf-ref[0](mc_topic, any)           6 related[0](featured, bidi)
		//   2 acf-ref[1](mc_flag,  any)           7 related[1](featured)
		//   3 time_based[0](featured)             8 hierarchical(mc_topic)
		//   4 time_based[1](archived)             9 level-restriction(mc_topic)
		$a(
			'§65a seeded term-rule collisions, in full',
			mc65_summary( $found ),
			array(
				'0-1:shared_target',        // propagation × acf-ref, mc_topic
				'1-8:shared_target',        // acf-ref × hierarchy, mc_topic
				'1-9:shared_target',        // acf-ref × level restriction
				'3-6:shared_term_cancels',  // date window × bidirectional related
				'3-7:shared_target',        // date window × add-only related
				'4-5:shared_term_cancels',  // #69's pair
				'6-7:shared_target',        // the two related rules, one target
				'8-9:ancestors_stripped',   // #51's pair
			)
		);

		// #51, named. The whole point of the second acceptance criterion: a
		// generic "these two collide" here would be a regression even though
		// the pair is still reported.
		$ancestors = array_values( array_filter(
			$found,
			function ( $f ) {
				return 'ancestors_stripped' === $f['code'];
			}
		) );
		$a( '§65b #51 pair is flagged exactly once', count( $ancestors ), 1 );
		$a(
			'§65b #51 names the hierarchy rule and the restriction rule',
			array( $ancestors[0]['a']['type'], $ancestors[0]['b']['type'] ),
			array( 'hierarchical_rules', 'hierarchical_level_restriction_rules' )
		);
		$a( '§65b #51 names the shared taxonomy', $ancestors[0]['target'], 'MC Topics' );
		$msg = CollisionDetector::message( $ancestors[0] );
		$a(
			'§65b #51 states the contradiction, not a generic message',
			(bool) ( false !== strpos( $msg, 'adds ancestor terms' )
				&& false !== strpos( $msg, 'does not keep them' ) ),
			true
		);
		$a(
			'§65b #51 says the result depends on list order',
			(bool) ( false !== strpos( $msg, 'lower in the list acts last' ) ),
			true
		);

		// #69: the fixture's ready-made time_based pair.
		$cancels = array_values( array_filter(
			$found,
			function ( $f ) {
				return 'shared_term_cancels' === $f['code']
					&& 'time_based_rules' === $f['a']['type']
					&& 'time_based_rules' === $f['b']['type'];
			}
		) );
		$a( '§65b #69 pair is flagged', count( $cancels ), 1 );
		$a( '§65b #69 names the shared TERM, not its taxonomy',
			$cancels[0]['target'], 'MC Topics: Archived' );
		$a(
			'§65b #69 message says either rule removes what the other applied',
			(bool) ( false !== strpos( CollisionDetector::message( $cancels[0] ), 'whoever applied it' ) ),
			true
		);

		// The format kind: one seeded rule, so nothing to contend with.
		$a( '§65a the one seeded format rule collides with nothing',
			mc65_summary( mc65_scan( OptionRuleStorage::KIND_FORMAT ) ), array() );

		// §65h — the advisory is advisory: detecting a collision provokes no
		// pass and moves no term. A subject is left exactly as it was.
		$a( '§65h scanning writes no terms', mc65_slugs( $solo ), $before );
		break;

	// -----------------------------------------------------------------------
	// §65c/d — the #39 configuration, and what does and does not clear it.
	// -----------------------------------------------------------------------
	case 'config':
		$propagation = array(
			'type'              => 'propagation_rules',
			'enabled'           => true,
			'taxonomy'          => 'mc_topic',
			'post_types'        => array( 'mc_section' ),
			'conflict_handling' => 'merge',
			'row_title'         => 'Cascade Topics',
		);
		$restriction = array(
			'type'              => 'hierarchical_level_restriction_rules',
			'enabled'           => true,
			'taxonomy'          => 'mc_topic',
			'post_types'        => array( 'mc_section' ),
			'restriction_mode'  => 'one_per_level',
			'include_ancestors' => false,
			'row_title'         => 'One per level',
		);

		// §65c — #39's configuration, on one taxonomy with overlapping types.
		mc65_author( array( $propagation, $restriction ) );
		$found = mc65_scan();
		$a( '§65c #39 configuration warns', mc65_summary( $found ), array( '0-1:shared_target' ) );
		$a(
			'§65c the warning names both rules',
			array( $found[0]['a']['title'], $found[0]['b']['title'] ),
			array( 'Cascade Topics', 'One per level' )
		);
		$a( '§65c and the post types they share', $found[0]['scope'], 'MC Sections' );

		// §65c — moving one to a disjoint post type clears it.
		$moved               = $restriction;
		$moved['post_types'] = array( 'mc_item' );
		mc65_author( array( $propagation, $moved ) );
		$a( '§65c a disjoint post type clears the warning', mc65_summary( mc65_scan() ), array() );

		// …and so does a disjoint taxonomy, the predicate's other conjunct.
		$other_tax             = $restriction;
		$other_tax['taxonomy'] = 'mc_flag';
		mc65_author( array( $propagation, $other_tax ) );
		$a( '§65c a disjoint taxonomy clears it too', mc65_summary( mc65_scan() ), array() );

		// §65d — a collision is about contention, not about currency. Two date
		// windows over one term, neither currently open.
		$today   = current_time( 'Y-m-d' );
		$expired = array(
			'type'           => 'time_based_rules',
			'enabled'        => true,
			'post_types'     => array( 'mc_item' ),
			'start_date'     => gmdate( 'Y-m-d', strtotime( $today . ' -30 days' ) ),
			'end_date'       => gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) ),
			'target_term_id' => mc_tid( 'topic-archived' ),
			'row_title'      => 'Archive window (expired)',
		);
		$future = array(
			'type'           => 'time_based_rules',
			'enabled'        => true,
			'post_types'     => array( 'mc_item' ),
			'start_date'     => gmdate( 'Y-m-d', strtotime( $today . ' +10 days' ) ),
			'end_date'       => gmdate( 'Y-m-d', strtotime( $today . ' +20 days' ) ),
			'target_term_id' => mc_tid( 'topic-archived' ),
			'row_title'      => 'Archive window (future)',
		);

		mc65_author( array( $expired, $future ) );
		$a( '§65d two date windows over one term warn',
			mc65_summary( mc65_scan() ), array( '0-1:shared_term_cancels' ) );

		$slid               = $future;
		$slid['start_date'] = gmdate( 'Y-m-d', strtotime( $today . ' +40 days' ) );
		$slid['end_date']   = gmdate( 'Y-m-d', strtotime( $today . ' +50 days' ) );
		mc65_author( array( $expired, $slid ) );
		$a( '§65d sliding the window does NOT clear it',
			mc65_summary( mc65_scan() ), array( '0-1:shared_term_cancels' ) );

		$retargeted                   = $future;
		$retargeted['target_term_id'] = mc_tid( 'topic-featured' );
		mc65_author( array( $expired, $retargeted ) );
		$a( '§65d re-targeting one rule DOES clear it', mc65_summary( mc65_scan() ), array() );

		// A disabled rule contends with nothing, which is what keeps the notice
		// from describing rules the author has already switched off.
		$off            = $future;
		$off['enabled'] = false;
		mc65_author( array( $expired, $off ) );
		$a( '§65d disabling one clears it', mc65_summary( mc65_scan() ), array() );

		mc_restore();
		break;

	// -----------------------------------------------------------------------
	// §65e/f/g — the two surfaces, through their real hooks.
	// -----------------------------------------------------------------------
	case 'surfaces':
		mc_restore();
		delete_option( CollisionDetector::OPTION_NAME );

		// §65e — the passive surface. Fire the REAL action Wireframe fires at
		// the end of a save; nothing else may be needed to get a notice.
		$a( '§65e nothing is persisted before a save',
			get_option( CollisionDetector::OPTION_NAME, null ), null );

		do_action( 'bws-meta-conductor/settings_saved', array(), 'settings' );

		$stored = get_option( CollisionDetector::OPTION_NAME, null );
		$a( '§65e the save hook persists findings for both kinds',
			is_array( $stored ) ? array_keys( $stored ) : null,
			array( OptionRuleStorage::KIND_TERM, OptionRuleStorage::KIND_FORMAT ) );
		$a( '§65e and they are the seeded set\'s findings',
			count( $stored[ OptionRuleStorage::KIND_TERM ] ), 8 );

		// …and the section the tab renders on the next load is built from them.
		// Joined, not compared as arrays: mc_assert sorts arrays before
		// comparing, and ORDER is the assertion — a notice under the button is
		// a notice the author has already scrolled past.
		$section   = CollisionDetector::section( OptionRuleStorage::KIND_TERM );
		$field_ids = implode( ',', wp_list_pluck( $section['fields'], 'id' ) );
		$a( '§65e the notice leads the section, the button follows',
			$field_ids,
			'term_rules_collision_notice,term_rules_collision_check' );
		$a( '§65e the notice is a warning, not an error',
			$section['fields'][0]['args']['variant'], 'warning' );
		$a(
			'§65e and its body names the #51 contradiction',
			(bool) ( false !== strpos( $section['fields'][0]['args']['content'], 'adds ancestor terms' ) ),
			true
		);

		// §65f — the on-demand surface, through the real filter name, over rows
		// that are NOT what is in storage.
		$in_flight = array(
			OptionRuleStorage::KIND_TERM => array(
				array(
					'type'            => 'time_based_rules',
					'enabled'         => true,
					'post_types'      => array( 'mc_item' => true ),
					'target_term_id'  => array( mc_tid( 'topic-featured' ) ),
					'start_date'      => '2020-01-01',
					'end_date'        => '2020-12-31',
				),
				array(
					'type'            => 'time_based_rules',
					'enabled'         => true,
					'post_types'      => array( 'mc_item' => true ),
					'target_term_id'  => array( mc_tid( 'topic-featured' ) ),
					'start_date'      => '2099-01-01',
					'end_date'        => '2099-12-31',
				),
			),
		);

		$response = apply_filters(
			'bws-meta-conductor/action/settings/term_rules_collision_check/run',
			null,
			$in_flight,
			null
		);

		$a( '§65f the action filter is answered', is_array( $response ), true );
		$a( '§65f with a warning status', $response['status'] ?? null, 'warning' );
		$a( '§65f over the IN-FLIGHT rows, not the stored ones',
			$response['message'] ?? null, '1 collision found.' );
		// Unsaved rows carry no `row_title` — the snapshot has not run on them
		// — so the on-demand path bakes one, or the warning would name two
		// rules by position and nothing else.
		$a(
			'§65f unsaved rows are named through the save path own snapshot',
			(bool) ( false !== strpos( $response['html'], 'Apply MC Topics: Featured to MC Items' ) ),
			true
		);
		// The critical one: the re-check must not overwrite the passive
		// surface with a rule set that was never saved.
		$a( '§65f the re-check persists nothing',
			count( get_option( CollisionDetector::OPTION_NAME )[ OptionRuleStorage::KIND_TERM ] ), 8 );

		// A clean list answers success, and still writes nothing.
		$clean = apply_filters(
			'bws-meta-conductor/action/settings/format_rules_collision_check/run',
			null,
			array( OptionRuleStorage::KIND_FORMAT => array() ),
			null
		);
		$a( '§65f a clean list answers success', $clean['status'] ?? null, 'success' );
		$a( '§65f with no inline panel', isset( $clean['html'] ), false );

		// §65g — the notice is not sticky. Author a collision-free list, fire
		// the save hook, and the section must come back with the button alone.
		mc65_author( array(
			array(
				'type'       => 'propagation_rules',
				'enabled'    => true,
				'taxonomy'   => 'mc_topic',
				'post_types' => array( 'mc_section' ),
				'row_title'  => 'Cascade Topics',
			),
		) );
		do_action( 'bws-meta-conductor/settings_saved', array(), 'settings' );

		$a( '§65g resolving the collisions empties the stored findings',
			get_option( CollisionDetector::OPTION_NAME )[ OptionRuleStorage::KIND_TERM ], array() );
		$a( '§65g and the notice is gone from the section',
			implode( ',', wp_list_pluck( CollisionDetector::section( OptionRuleStorage::KIND_TERM )['fields'], 'id' ) ),
			'term_rules_collision_check' );

		mc_restore();
		do_action( 'bws-meta-conductor/settings_saved', array(), 'settings' );
		break;

	case 'restore':
		mc_restore();
		delete_option( CollisionDetector::OPTION_NAME );
		WP_CLI::log( '[sweep] baseline restored, findings cleared' );
		break;

	default:
		WP_CLI::error( "Unknown step '$step' — use seeded | config | surfaces | restore." );
}

WP_CLI::log( sprintf( '--- #65 %s: %d passed, %d failed', $step, $pass, $fail ) );
if ( $fail ) {
	WP_CLI::error( 'sweep-65 FAILED' );
}
