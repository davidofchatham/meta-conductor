<?php
/**
 * mc-rules — #67 behaviour sweep: hierarchical vs level_restriction, both
 * list orders (Phase 4 Gate 3, the pair the matrix's "Cross-handler
 * interaction scenarios" section left for "later phase").
 *
 * Both types have been pure appliers since #60 — no priority-5-vs-10 hook
 * race left to demonstrate. The order question is now entirely the authored
 * list position, exactly like #35 (§62c/§62d). This is the SECOND instance
 * of that shape, and it is the pair H14/the collision detector already NAMES
 * as `ancestors_stripped` (#51) — this sweep is the runtime proof the static
 * warning describes a real, order-dependent divergence, not just a
 * hypothetical one.
 *
 * Rules: hierarchical (`ancestors`, depth `all` — purely additive, walks up
 * to every ancestor of whatever is on the post) + level_restriction
 * (`deepest_only`, `include_ancestors` off — keeps only the term(s) at the
 * MAX level present, drops everything shallower).
 *
 * Subject starts holding Coastal + Inland — siblings, both mc_topic level 2,
 * chosen so level_restriction's `deepest_only` step is a genuine NO-OP in
 * isolation (both terms already share the max level, so nothing to prune)
 * and the whole divergence comes from whether hierarchical's ancestor
 * additions survive a LATER deepest_only pass or not.
 *
 *   §67a  level_restriction FIRST, hierarchical SECOND. LR sees [Coastal,
 *         Inland] tied at the max level — no-op. Hierarchical then adds
 *         Region + East. Final: all four terms, full lineage kept.
 *   §67b  Same rules, ORDER SWAPPED. Hierarchical runs first and adds
 *         Region + East (additive, same as §67a). level_restriction then
 *         sees FOUR terms across three levels, keeps only the max level
 *         (Coastal + Inland), and STRIPS Region + East. Final: two terms,
 *         ancestors gone.
 *
 * Same rules, same edit, opposite outcome by list position — the point,
 * same as #35/§62c-d.
 *
 * ⚠️ ACF-mirror trap, found running this: `item-solo-a` carries an ACF
 * `mc_topics` taxonomy mirror (`field_mc_topics_item`) that
 * `HierarchicalLevelRestrictionHandler::process_acf_level_restrictions()`
 * reads BEFORE the native branch. A stale value left by an earlier scenario
 * (native reset alone does not touch it — same trap as propagation's
 * `mc_topics` mirror on `mc_section`, matrix §5/§62) silently swapped which
 * term survived a `one_per_level` prune, with no error — it just looked like
 * the wrong term won. `mc67_clean()` clears BOTH stores for exactly this
 * reason; do not "simplify" it to `mc_reset_subject()` alone.
 *
 * Usage (one step per eval):
 *   wp eval-file .../sweep-67-cross-order.php order        # §67a
 *   wp eval-file .../sweep-67-cross-order.php order-swap   # §67b
 *   wp eval-file .../sweep-67-cross-order.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'order';

/** The live dispatcher, or bail loudly. */
function mc67_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

/** Author an explicit ordered term_rules list. */
function mc67_author( array $rows ) {
	return mc_write_ordered_rules( $rows, OptionRuleStorage::KIND_TERM );
}

function mc67_hierarchical_row() {
	return array(
		'type'                 => 'hierarchical_rules',
		'enabled'              => true,
		'taxonomy'             => 'mc_topic',
		'post_types'           => array( 'mc_item' ),
		'inheritance_behavior' => 'ancestors',
		'inheritance_depth'    => 'all',
	);
}

function mc67_level_restriction_row() {
	return array(
		'type'               => 'hierarchical_level_restriction_rules',
		'enabled'            => true,
		'taxonomy'           => 'mc_topic',
		'post_types'         => array( 'mc_item' ),
		'restriction_mode'   => 'deepest_only',
		'include_ancestors'  => false,
	);
}

/**
 * Clear the subject's rules + BOTH term stores. See the ACF-mirror trap note
 * above — the mirror is what made the first run of this sweep non-deterministic.
 */
function mc67_clean( $post_id ) {
	mc67_author( array() );
	mc_reset_subject( $post_id );
	if ( function_exists( 'update_field' ) ) {
		update_field( 'field_mc_topics_item', array(), $post_id );
	}
	mc67_dispatcher()->drain();
	// The dispatcher's own pass can echo a write back into the ACF mirror on
	// some paths; reset once more so the NEXT step starts from a truly clean
	// slate rather than whatever the empty pass just settled on.
	mc_reset_subject( $post_id );
	if ( function_exists( 'update_field' ) ) {
		update_field( 'field_mc_topics_item', array(), $post_id );
	}
}

function mc67_slugs( $post_id, $taxonomy = 'mc_topic' ) {
	$ids = mc_terms( $post_id, $taxonomy );
	$slugs = array_map( static function ( $id ) use ( $taxonomy ) {
		$t = get_term( $id, $taxonomy );
		return ( $t && ! is_wp_error( $t ) ) ? $t->slug : (string) $id;
	}, $ids );
	sort( $slugs );
	return $slugs;
}

$solo    = mc_pid( 'item-solo-a' );
$coastal = mc_tid( 'topic-coastal' );
$inland  = mc_tid( 'topic-inland' );

if ( ! $solo || ! $coastal || ! $inland ) {
	WP_CLI::error( 'Fixture not found — seed the mc-rules fixture first.' );
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

switch ( $step ) {

	// -------------------------------------------------------------- §67a
	case 'order':
		mc67_clean( $solo );
		mc67_author( array( mc67_level_restriction_row(), mc67_hierarchical_row() ) );

		wp_set_object_terms( $solo, array( $coastal, $inland ), 'mc_topic' );
		mc67_dispatcher()->drain();

		$t( '§67a level_restriction-then-hierarchical: full lineage kept',
			mc67_slugs( $solo ),
			array( 'coastal', 'east', 'inland', 'region' ) );
		break;

	// -------------------------------------------------------------- §67b
	case 'order-swap':
		mc67_clean( $solo );
		mc67_author( array( mc67_hierarchical_row(), mc67_level_restriction_row() ) );

		wp_set_object_terms( $solo, array( $coastal, $inland ), 'mc_topic' );
		mc67_dispatcher()->drain();

		$t( '§67b hierarchical-then-level_restriction: ancestors stripped',
			mc67_slugs( $solo ),
			array( 'coastal', 'inland' ) );
		break;

	// ----------------------------------------------------------- restore
	case 'restore':
		mc67_clean( $solo );
		mc_restore( array( $solo ) );
		mc67_dispatcher()->drain();
		WP_CLI::log( '[sweep] #67 cross-order: subject reset, fixture rules restored' );
		break;

	default:
		WP_CLI::error( "Unknown step '$step' — order|order-swap|restore." );
}

if ( 'restore' !== $step ) {
	WP_CLI::log( sprintf( '[sweep 67-cross-order/%s] %d passed, %d failed', $step, $pass, $fail ) );
	if ( $fail ) {
		WP_CLI::error( sprintf( '#67 cross-order sweep step "%s" FAILED (%d assertions).', $step, $fail ) );
	}
}
