<?php
/**
 * §1d regression guard — HierarchicalHandler double-save in ONE request.
 *
 * Proves the removed `$processed[post:tax]` dedup map (commit 03ee8b4, 0.6.2)
 * no longer silently skips a second legitimate edit of the same post+taxonomy
 * within one PHP request. Unlike every other sweep scenario, this one MUST run
 * as a single eval — two edits in one request is the whole point.
 *
 * ### What #60 changed here, and what it did not
 *
 * The handler no longer applies inside `set_object_terms`; `TermDispatcher`
 * marks the post dirty and the pass runs at the drain. So the two edits now
 * COALESCE into one pass rather than producing one apply each, and the
 * intermediate state this sweep used to read between them no longer exists
 * mid-request. The drain is explicit below because WP-CLI's own `shutdown`
 * lands after the assertions.
 *
 * The invariant is unchanged and still worth guarding: the end state must
 * reflect the SECOND edit, expanded. A dedup map keyed post+taxonomy would
 * still break it — under coalescing it would skip the only pass there is,
 * leaving the raw `[West]` with stale auto-term provenance. Coalescing is not
 * the same thing as dedup: one pass recomputing from live state gives the same
 * answer as two passes, which two SKIPPED applies do not.
 *
 * Run (from the container):
 *   wp eval-file wp-content/plugins/meta-conductor/tools/fixtures/mc-rules/sweep-hierarchical-double-save.php --allow-root
 *
 * Expects the mc-rules fixture seeded. Isolates hierarchical, restores on exit.
 * See handler-fixture-matrix.md §1d.
 */
require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;

mc_isolate( 'hierarchical_rules' );
$id = mc_pid( 'item-solo-a' );
mc_reset_subject( $id );

$harbor  = mc_tid( 'topic-harbor' );
$west    = mc_tid( 'topic-west' );
$region  = mc_tid( 'topic-region' );
$east    = mc_tid( 'topic-east' );
$coastal = mc_tid( 'topic-coastal' );

WP_CLI::log( "id={$id} harbor={$harbor} west={$west} region={$region}" );

$dispatcher = TermDispatcher::instance();
if ( ! $dispatcher ) {
	WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
}

// EDIT 1 (same request): assign Harbor. Marks the post dirty; no apply yet.
wp_set_object_terms( $id, array( $harbor ), 'mc_topic' );
WP_CLI::log( 'after edit1 (pre-drain, raw by design): ' . wp_json_encode( mc_terms( $id ) ) );

// EDIT 2 (SAME request, same post+taxonomy): set only West. Coalesces with
// edit 1 into the single pending pass.
wp_set_object_terms( $id, array( $west ), 'mc_topic' );

// One drain, one pass, computed from live state — which is [West].
$dispatcher->drain();

$after = mc_terms( $id );
$auto  = get_post_meta( $id, '_bws_auto_terms', true );

mc_assert( 'double-save: the pass recomputes from the SECOND edit', $after, array( $region, $west ) );
mc_assert(
	'double-save: no term from the first edit survives',
	array_values( array_intersect( $after, array( $harbor, $east, $coastal ) ) ),
	array()
);
mc_assert( 'double-save: auto-term provenance matches the pass', $auto['mc_topic'] ?? array(), array( $region ) );

mc_restore( array( $id ) );
