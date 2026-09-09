<?php
/**
 * mc-rules — #62 behaviour sweep: propagation as pull + declared fan-out.
 *
 * H13 can see that PropagationHandler registers only its capture hook, that the
 * dispatcher collects a fan-out, and that `fan_out()` writes nothing. It cannot
 * see any of the following, all of which are runtime questions:
 *
 *   §62a  A parent's terms still reach a MULTI-LEVEL descendant chain, now by
 *         the child pulling rather than the parent pushing — and each level is
 *         reconciled EXACTLY ONCE, by its own full ordered pass. The pass
 *         counter is the point: under push there was one pass (the parent's)
 *         and N out-of-band writes; under pull there are N+1 passes and no
 *         out-of-band writes, and a fan-out that failed to terminate would show
 *         up here as a count above one.
 *   §62b  `deleted_term_relationships` on a parent still reaches descendants.
 *         This is the case live state alone CANNOT answer — a term the parent
 *         lost is indistinguishable from a term a child holds independently —
 *         so it exercises the capture hook and the subtraction it feeds, down
 *         two levels.
 *   §62e  The claim, end to end, and the one case the pull rewrite could get
 *         catastrophically wrong: a parent holding NOTHING in the taxonomy. Both
 *         pre-#62 push paths bailed on an empty parent set; under `owning`
 *         (`replace`) a pull that does not would compute an end state of [] and
 *         strip the child's own terms. Also asserts `contributing` (`merge`)
 *         keeps a child's independent term, which is §5d's statement re-made
 *         against the applier.
 *   §62c  The #35 scenario, with propagation ABOVE hierarchical: the child gets
 *         its parent's terms and exactly ONE level of expansion, from the
 *         hierarchical rule running once, in list order, on the child's own
 *         pass.
 *   §62d  The order control. Move hierarchical ABOVE propagation and the same
 *         edit produces a DIFFERENT, documented result — the child inherits
 *         without the expansion, because hierarchical ran before the terms
 *         arrived. Without this pair, §62c would pass just as well if order
 *         were still an accident of handler construction.
 *
 * Usage (one step per eval — a step assumes the previous one's end state only
 * where it says so):
 *   wp eval-file .../sweep-62-propagation.php pull        # §62a
 *   wp eval-file .../sweep-62-propagation.php remove      # §62b (after pull)
 *   wp eval-file .../sweep-62-propagation.php claim       # §62e
 *   wp eval-file .../sweep-62-propagation.php order       # §62c
 *   wp eval-file .../sweep-62-propagation.php order-swap  # §62d
 *   wp eval-file .../sweep-62-propagation.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'pull';

/** The live dispatcher, or bail loudly — an unregistered one means no passes. */
function mc62_dispatcher() {
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
 * order a pass executes in — which is what §62c/d assert. (Before that the
 * type-keyed arrays had to be written alongside it or the order was discarded
 * and rebuilt in KIND_TYPES order.)
 *
 * @param array[] $rows Rules in authored order, each carrying a `type` key.
 * @return int Rows written.
 */
function mc62_author( array $rows ) {
	return mc_write_ordered_rules( $rows, OptionRuleStorage::KIND_TERM );
}

/**
 * Count passes per entity, by observing the pass's own enable gate.
 *
 * `pass_enabled()` is consulted exactly once per `run_pass()`, on the entity
 * the pass is about, which makes its filter the one place a sweep can watch
 * dispatch without instrumenting the plugin. Returns a reader that hands back
 * the counts accumulated so far and resets, so a step can attribute passes to
 * ONE drain rather than to the whole request (the shutdown drain lands after
 * every assertion here).
 *
 * @return callable(): array<int,int> post_id => passes since the last read.
 */
function mc62_pass_counter() {
	$counts = new ArrayObject( array() );

	add_filter(
		'meta_conductor_term_pass_enabled',
		function ( $enabled, $post_id ) use ( $counts ) {
			$counts[ (int) $post_id ] = ( $counts[ (int) $post_id ] ?? 0 ) + 1;
			return $enabled;
		},
		10,
		2
	);

	return function () use ( $counts ) {
		$snapshot = (array) $counts;
		foreach ( array_keys( $snapshot ) as $k ) {
			unset( $counts[ $k ] );
		}
		ksort( $snapshot );
		return $snapshot;
	};
}

/**
 * Render a pass-count map against the chain as `name=n` strings.
 *
 * As a STRING list, not the raw map: mc_assert() sorts arrays, which discards
 * the keys, so comparing the map directly would assert "four entities got one
 * pass each" without saying WHICH four. An id outside the chain renders as
 * `#<id>` and fails the comparison loudly.
 *
 * @param array<int,int> $counts post_id => passes.
 * @param array<string,int> $chain name => post_id.
 * @return string[]
 */
function mc62_named_counts( array $counts, array $chain ) {
	$names = array_flip( array_map( 'intval', $chain ) );
	$out   = array();
	foreach ( $counts as $id => $n ) {
		$out[] = ( $names[ (int) $id ] ?? '#' . $id ) . '=' . $n;
	}
	sort( $out );
	return $out;
}

/** Term slugs of a post's mc_topic terms, sorted — readable assertions. */
function mc62_slugs( $post_id, $taxonomy = 'mc_topic' ) {
	$terms = wp_get_object_terms( (int) $post_id, $taxonomy );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$slugs = wp_list_pluck( $terms, 'slug' );
	sort( $slugs );
	return $slugs;
}

/**
 * The propagation row §62a/b run on: merge, mc_section, mc_topic.
 *
 * @param string $claim Stored claim key — merge|replace|skip.
 */
function mc62_propagation_row( $claim = 'merge' ) {
	return array(
		'type'              => 'propagation_rules',
		'enabled'           => true,
		'taxonomy'          => 'mc_topic',
		'post_types'        => array( 'mc_section' ),
		'conflict_handling' => $claim,
	);
}

/**
 * The hierarchical row §62c/d pair propagation with: one level DOWN, merged.
 *
 * `descendants_always` + `immediate` is the #35 configuration, rescoped from
 * the fixture's mc_item to the mc_section chain propagation runs on.
 */
function mc62_hierarchical_row() {
	return array(
		'type'                 => 'hierarchical_rules',
		'enabled'              => true,
		'taxonomy'             => 'mc_topic',
		'post_types'           => array( 'mc_section' ),
		'inheritance_behavior' => 'descendants_always',
		'inheritance_depth'    => 'immediate',
	);
}

/**
 * Give a chain post a term of its OWN, in BOTH stores.
 *
 * Through the ACF field, not `wp_set_object_terms`, and that is the same trap
 * the fixture itself hit (matrix, "Fixture fix v3→v4"). The mc_section posts
 * carry an `mc_topics` mirror with Load/Save Terms ON, so a native-only term
 * sits opposite an EMPTY mirror — and propagation writes the mirror before it
 * reads the native set, merging the source into that empty value and letting
 * the save_terms sync overwrite the native store with the result. The
 * independent term is destroyed before the claim ever sees it, which reads as
 * `merge` behaving like `replace`. Writing the field makes both channels agree,
 * which is the state a real edit leaves behind.
 *
 * @param int   $post_id
 * @param int[] $term_ids
 */
function mc62_set_own_terms( $post_id, array $term_ids ) {
	if ( function_exists( 'update_field' ) ) {
		update_field( 'field_mc_topics_section', $term_ids, (int) $post_id );
	}
	wp_set_object_terms( (int) $post_id, $term_ids, 'mc_topic' );
}

/** The chain: grand → parent → child, plus the draft sibling of child. */
function mc62_chain() {
	return array(
		'grand'  => mc_pid( 'section-grand' ),
		'parent' => mc_pid( 'section-parent' ),
		'child'  => mc_pid( 'section-child' ),
		'draft'  => mc_pid( 'section-draft' ),
	);
}

/**
 * Clear every chain member's terms with no rule live, so a step starts clean.
 *
 * BOTH STORES. `mc_reset_subject()` clears native terms only, and the mc_section
 * posts carry an `mc_topics` ACF taxonomy mirror with Load/Save Terms ON. A
 * stale value left in that field is not inert: propagation writes the mirror
 * before it reads the native set (deliberately — the two stores drift and the
 * mirror self-heals), and the save_terms sync then puts the stale term straight
 * back into the native store, where the claim keeps it. So a step that reset
 * only the native side would inherit the previous step's terms through ACF and
 * read as a rule misbehaving.
 */
function mc62_clean_chain( array $chain ) {
	mc62_author( array() );
	foreach ( $chain as $pid ) {
		mc_reset_subject( (int) $pid );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'field_mc_topics_section', array(), (int) $pid );
		}
	}
	mc62_dispatcher()->drain();
	foreach ( $chain as $pid ) {
		mc_reset_subject( (int) $pid );
	}
	mc62_dispatcher()->drain();
}

$chain = mc62_chain();
foreach ( $chain as $name => $pid ) {
	if ( ! $pid ) {
		WP_CLI::error( "Fixture post 'section-$name' not found — seed the mc-rules fixture first." );
	}
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

	// ---------------------------------------------------------------- §62a
	case 'pull':
		mc62_clean_chain( $chain );
		mc62_author( array( mc62_propagation_row() ) );

		$read = mc62_pass_counter();
		$west = mc_tid( 'topic-west' );

		// The ONLY write this step makes. Everything below is the pass.
		wp_set_object_terms( $chain['grand'], array( $west ), 'mc_topic' );
		mc62_dispatcher()->drain();

		$counts = $read();

		$t( '§62a grand keeps its own term', mc62_slugs( $chain['grand'] ), array( 'west' ) );
		$t( '§62a child of grand pulled it', mc62_slugs( $chain['parent'] ), array( 'west' ) );
		$t( '§62a grandchild pulled it too', mc62_slugs( $chain['child'] ), array( 'west' ) );
		$t( '§62a draft sibling pulled it', mc62_slugs( $chain['draft'] ), array( 'west' ) );

		// Termination + no double work: one pass per entity, and the entities
		// are exactly the chain. A fan-out that returned the whole subtree
		// instead of the immediate children would still end here, but a
		// non-terminating one could not.
		$t( '§62a one pass per chain member, and only chain members',
			mc62_named_counts( $counts, $chain ),
			array( 'child=1', 'draft=1', 'grand=1', 'parent=1' )
		);
		break;

	// ---------------------------------------------------------------- §62b
	case 'remove':
		// Assumes `pull` left the whole chain holding West.
		mc62_author( array( mc62_propagation_row() ) );

		$west = mc_tid( 'topic-west' );
		$t( '§62b precondition: chain holds West', mc62_slugs( $chain['child'] ), array( 'west' ) );

		$read = mc62_pass_counter();

		// wp_remove_object_terms fires deleted_term_relationships and NOTHING
		// else — the entry point propagation used to hook privately, and the
		// one live state cannot reconstruct afterwards.
		wp_remove_object_terms( $chain['grand'], array( $west ), 'mc_topic' );
		mc62_dispatcher()->drain();

		$counts = $read();

		$t( '§62b grand lost the term', mc62_slugs( $chain['grand'] ), array() );
		$t( '§62b removal reached the child', mc62_slugs( $chain['parent'] ), array() );
		$t( '§62b removal reached the grandchild', mc62_slugs( $chain['child'] ), array() );
		$t( '§62b removal reached the draft sibling', mc62_slugs( $chain['draft'] ), array() );
		$t( '§62b still one pass per chain member',
			mc62_named_counts( $counts, $chain ),
			array( 'child=1', 'draft=1', 'grand=1', 'parent=1' )
		);
		break;

	// ---------------------------------------------------------------- §62e
	case 'claim':
		mc62_clean_chain( $chain );

		// The child holds a term of its OWN that the parent has never had, and
		// the parent holds nothing at all in this taxonomy.
		mc_reset_subject( $chain['child'] );
		mc62_set_own_terms( $chain['child'], array( mc_tid( 'topic-west' ) ) );

		mc62_author( array( mc62_propagation_row( 'replace' ) ) );
		mc62_dispatcher()->drain();

		$t( '§62e owning + a term-less parent leaves the child alone',
			mc62_slugs( $chain['child'] ), array( 'west' ) );

		// Now give the parent something to say, still under `replace`.
		mc62_author( array( mc62_propagation_row( 'replace' ) ) );
		wp_set_object_terms( $chain['grand'], array( mc_tid( 'topic-featured' ) ), 'mc_topic' );
		mc62_dispatcher()->drain();

		$t( '§62e owning replaces the whole taxonomy once it has a source',
			mc62_slugs( $chain['child'] ), array( 'featured' ) );

		// Contributing keeps what the child brought, which is §5d's claim
		// matrix restated against the applier.
		mc62_clean_chain( $chain );
		mc62_set_own_terms( $chain['child'], array( mc_tid( 'topic-west' ) ) );
		mc62_author( array( mc62_propagation_row( 'merge' ) ) );
		wp_set_object_terms( $chain['grand'], array( mc_tid( 'topic-featured' ) ), 'mc_topic' );
		mc62_dispatcher()->drain();

		$t( '§62e contributing keeps the child independent term',
			mc62_slugs( $chain['child'] ), array( 'featured', 'west' ) );
		break;

	// ---------------------------------------------------------------- §62c
	case 'order':
		mc62_clean_chain( $chain );
		mc62_author( array( mc62_propagation_row(), mc62_hierarchical_row() ) );

		$east = mc_tid( 'topic-east' );

		wp_set_object_terms( $chain['grand'], array( $east ), 'mc_topic' );
		mc62_dispatcher()->drain();

		// Grand's own pass expands East one level down: Coastal + Inland.
		$t( '§62c grand expands one level',
			mc62_slugs( $chain['grand'] ),
			array( 'coastal', 'east', 'inland' ) );

		// The child pulls that set, THEN expands it one level — Coastal's child
		// Harbor. Exactly ONE extra level, because the child got ONE ordered
		// pass rather than a propagation write plus an out-of-band expansion.
		$t( '§62c child inherits + exactly one expansion level',
			mc62_slugs( $chain['parent'] ),
			array( 'coastal', 'east', 'harbor', 'inland' ) );

		// Harbor is a leaf, so the next level down adds nothing: the chain
		// settles instead of gaining a level per generation.
		$t( '§62c grandchild settles at the same set',
			mc62_slugs( $chain['child'] ),
			array( 'coastal', 'east', 'harbor', 'inland' ) );
		break;

	// ---------------------------------------------------------------- §62d
	case 'order-swap':
		mc62_clean_chain( $chain );
		mc62_author( array( mc62_hierarchical_row(), mc62_propagation_row() ) );

		$east = mc_tid( 'topic-east' );

		wp_set_object_terms( $chain['grand'], array( $east ), 'mc_topic' );
		mc62_dispatcher()->drain();

		// Same rules, same edit, different ORDER. Grand is unaffected — its own
		// terms are present before either rule runs.
		$t( '§62d grand still expands one level',
			mc62_slugs( $chain['grand'] ),
			array( 'coastal', 'east', 'inland' ) );

		// On the child, hierarchical runs FIRST, against an empty taxonomy, and
		// expands nothing; propagation then delivers the parent's set, which
		// nothing expands this pass. So the child inherits WITHOUT Harbor —
		// the documented, different result.
		$t( '§62d child inherits with NO expansion',
			mc62_slugs( $chain['parent'] ),
			array( 'coastal', 'east', 'inland' ) );
		$t( '§62d grandchild likewise',
			mc62_slugs( $chain['child'] ),
			array( 'coastal', 'east', 'inland' ) );
		break;

	// ------------------------------------------------------------- restore
	case 'restore':
		// Clear BOTH stores first (see mc62_clean_chain) — mc_restore() resets
		// native terms only, and a stale `mc_topics` mirror would be merged
		// back into the native store by the first pass after the re-seed.
		mc62_clean_chain( $chain );
		mc_restore( array_values( $chain ) );
		mc62_dispatcher()->drain();
		WP_CLI::log( '[sweep] #62 chain reset, fixture rules restored' );
		break;

	default:
		WP_CLI::error( "Unknown step '$step' — pull|remove|claim|order|order-swap|restore." );
}

if ( 'restore' !== $step ) {
	WP_CLI::log( sprintf( '[sweep 62/%s] %d passed, %d failed', $step, $pass, $fail ) );
	if ( $fail ) {
		WP_CLI::error( sprintf( '#62 sweep step "%s" FAILED (%d assertions).', $step, $fail ) );
	}
}
