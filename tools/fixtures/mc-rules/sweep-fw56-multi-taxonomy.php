<?php
/**
 * mc-rules — FW-56 behavior sweep: one row naming several taxonomies.
 *
 * A row of a type that reads the shared `taxonomy` field may hold a LIST of
 * them. `OptionRuleStorage::project_kind_rules()` expands it to one scalar row
 * per taxonomy on read, so the claim to prove is EQUIVALENCE: one row naming
 * [mc_topic, mc_flag] does exactly what two rows, one per taxonomy, do. H-level
 * harnesses prove the expansion; only a pass over real terms proves nothing
 * downstream of it can tell the difference.
 *
 * Term count limit is the type used: it needs no parent chain, `mc_item` has
 * both taxonomies, and the no-delta survivors (deepest, then lowest ID) are a
 * deterministic answer to compare.
 *
 *   equivalence  Stage over-cap terms in BOTH taxonomies with no rule live, then
 *                run the same pass twice — authored as two scalar rows, then as
 *                one row naming both. The two end states must be identical, and
 *                each the expected survivors. Also: the read gives two rows
 *                from the one stored row, and a legacy string `taxonomy` still
 *                reads and runs.
 *   apply        The real bulk path (`ExistingPostsApplier::run_batch()`): the
 *                Apply page's choice names the ONE stored row (stored position,
 *                stored fingerprint), the run reaches BOTH taxonomies, and a
 *                second run changes nothing. Editing the list makes the choice
 *                stale.
 *   restore      Deletes the temp terms, restores the baselines, checks every
 *                `mc_item` `post_name` (the §7 gotcha).
 *
 * ⚠️ `item-solo-a` carries an ACF `mc_topics` mirror of mc_topic: staging and
 * cleaning clear BOTH stores or a stale field value changes which term survives.
 *
 * Usage (one step per eval):
 *   wp eval-file .../sweep-fw56-multi-taxonomy.php equivalence
 *   wp eval-file .../sweep-fw56-multi-taxonomy.php apply
 *   wp eval-file .../sweep-fw56-multi-taxonomy.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\ExistingPostsApplier;
use BWS\MetaConductor\Core\RuleChoice;
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

$step = $args[0] ?? 'equivalence';

function mc56_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

/** One term-count row, cap 1 over the whole taxonomy, scoped to mc_item. */
function mc56_row( $taxonomy ) {
	return array(
		'type'       => 'restrict_term_count',
		'enabled'    => true,
		'taxonomy'   => $taxonomy,
		'post_types' => array( 'mc_item' ),
		'max_terms'  => 1,
		'per_level'  => false,
	);
}

/** Author the term list. */
function mc56_author( array $rows ) {
	mc_write_ordered_rules( $rows, OptionRuleStorage::KIND_TERM );
}

/** Silence every rule and clear BOTH term stores on the subject. */
function mc56_clean( $post_id ) {
	mc56_author( array() );
	mc56_dispatcher()->drain();
	foreach ( array( 1, 2 ) as $unused ) {
		mc_reset_subject( $post_id );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'field_mc_topics_item', array(), $post_id );
		}
		mc56_dispatcher()->drain();
	}
}

/** Three temp mc_flag terms, in ascending ID order. */
function mc56_flags() {
	$ids = array();
	foreach ( array( 'mc56-a', 'mc56-b', 'mc56-c' ) as $slug ) {
		$existing = term_exists( $slug, 'mc_flag' );
		if ( ! $existing ) {
			$existing = wp_insert_term( $slug, 'mc_flag', array( 'slug' => $slug ) );
		}
		$ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
	}
	return $ids;
}

/** Stage over-cap terms in both taxonomies with no rule live. */
function mc56_stage( $post_id, array $topics, array $flags ) {
	mc56_clean( $post_id );
	wp_set_object_terms( $post_id, $topics, 'mc_topic' );
	wp_set_object_terms( $post_id, $flags, 'mc_flag' );
	mc56_dispatcher()->drain();
}

$solo = mc_pid( 'item-solo-a' );
$east = mc_tid( 'topic-east' );
$west = mc_tid( 'topic-west' );

if ( ! $solo || ! $east || ! $west ) {
	WP_CLI::error( 'Fixture not found — seed the mc-rules fixture first.' );
}

$pass = 0;
$fail = 0;
$chk  = function ( $label, $got, $want ) use ( &$pass, &$fail ) {
	if ( mc_assert( $label, $got, $want ) ) {
		$pass++;
	} else {
		$fail++;
	}
};

list( $fa, $fb, $fc ) = mc56_flags();
// Same depth: the lower ID survives a tie.
$topic_survivor = min( $east, $west );

switch ( $step ) {

	case 'equivalence':
		// A. Two scalar rows — the shape every site has today.
		mc56_stage( $solo, array( $east, $west ), array( $fc, $fa, $fb ) );
		mc56_author( array( mc56_row( 'mc_topic' ), mc56_row( 'mc_flag' ) ) );
		mc56_dispatcher()->drain_post( $solo );
		mc56_dispatcher()->drain();
		$scalar = array( mc_terms( $solo, 'mc_topic' ), mc_terms( $solo, 'mc_flag' ) );
		$chk( 'two scalar rows: one survivor in each taxonomy', $scalar, array( array( $topic_survivor ), array( $fa ) ) );

		// B. ONE row naming both.
		mc56_stage( $solo, array( $east, $west ), array( $fc, $fa, $fb ) );
		mc56_author( array( mc56_row( array( 'mc_topic', 'mc_flag' ) ) ) );

		$read = StorageFactory::get_instance()->get_kind_rules( OptionRuleStorage::KIND_TERM );
		$chk( 'the one stored row reads as one row per taxonomy', array_column( $read, 'taxonomy' ), array( 'mc_topic', 'mc_flag' ) );

		mc56_dispatcher()->drain_post( $solo );
		mc56_dispatcher()->drain();
		$listed = array( mc_terms( $solo, 'mc_topic' ), mc_terms( $solo, 'mc_flag' ) );
		$chk( 'one row naming both taxonomies: identical end state to two rows', $listed, $scalar );

		// C. A second pass is a no-op, as it is for scalar rows.
		$chk( 'a second pass changes nothing', mc56_dispatcher()->drain_post( $solo ), 0 );
		mc56_dispatcher()->drain();

		// D. A legacy string still reads and runs.
		mc56_stage( $solo, array( $east, $west ), array( $fc, $fa, $fb ) );
		mc56_author( array( mc56_row( 'mc_flag' ) ) );
		mc56_dispatcher()->drain_post( $solo );
		mc56_dispatcher()->drain();
		$chk( 'a legacy string taxonomy: its own taxonomy is capped', mc_terms( $solo, 'mc_flag' ), array( $fa ) );
		$chk( '... and the taxonomy it does not name is untouched', mc_terms( $solo, 'mc_topic' ), array( min( $east, $west ), max( $east, $west ) ) );
		break;

	case 'apply':
		mc56_stage( $solo, array( $east, $west ), array( $fc, $fa, $fb ) );
		mc_write_ordered_rules( array(), OptionRuleStorage::KIND_FORMAT );
		mc56_author( array( mc56_row( array( 'mc_topic', 'mc_flag' ) ) ) );

		$storage = StorageFactory::get_instance();
		$stored  = $storage->get_stored_kind_rules( OptionRuleStorage::KIND_TERM );
		$chk( 'the Apply page sees ONE stored row', count( $stored ), 1 );

		$choice = RuleChoice::encode( OptionRuleStorage::KIND_TERM, 0, $stored[0] );

		$first = ExistingPostsApplier::run_batch( $choice );
		$chk( 'the run completed', $first['done'] ?? false, true );
		$chk( 'the run reached BOTH taxonomies: topic', mc_terms( $solo, 'mc_topic' ), array( $topic_survivor ) );
		$chk( 'the run reached BOTH taxonomies: flag', mc_terms( $solo, 'mc_flag' ), array( $fa ) );

		$second = ExistingPostsApplier::run_batch( $choice );
		$chk( 'a second run changes nothing', $second['changed'] ?? -1, 0 );

		// Editing the list makes the choice stale.
		mc56_author( array( mc56_row( array( 'mc_topic' ) ) ) );
		$stale = ExistingPostsApplier::run_batch( $choice );
		$chk( 'a choice over a list that has since changed is refused', $stale['status'] ?? '', 'error' );
		break;

	case 'restore':
		mc56_clean( $solo );
		foreach ( array( 'mc56-a', 'mc56-b', 'mc56-c' ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'mc_flag' );
			if ( $term ) {
				wp_delete_term( $term->term_id, 'mc_flag' );
			}
		}
		mc_restore( array( $solo ) );
		mc56_dispatcher()->drain();

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
		WP_CLI::error( "Unknown step '$step' — equivalence|apply|restore." );
}

WP_CLI::log( sprintf( '[sweep fw56/%s] %d passed, %d failed', $step, $pass, $fail ) );
if ( $fail ) {
	WP_CLI::error( sprintf( 'fw56 sweep step "%s" FAILED (%d assertions).', $step, $fail ) );
}
