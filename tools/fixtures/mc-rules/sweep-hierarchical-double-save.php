<?php
/**
 * §1d regression guard — HierarchicalHandler double-save in ONE request.
 *
 * Proves the removed `$processed[post:tax]` dedup map (commit 03ee8b4, 0.6.2)
 * no longer silently skips a second legitimate edit of the same post+taxonomy
 * within one PHP request. Unlike every other sweep scenario, this one MUST run
 * as a single eval — two edits in one request is the whole point.
 *
 * Run (from the container):
 *   wp eval-file wp-content/plugins/meta-conductor/tools/fixtures/mc-rules/sweep-hierarchical-double-save.php --allow-root
 *
 * Expects the mc-rules fixture seeded. Isolates hierarchical, restores on exit.
 * See handler-fixture-matrix.md §1d.
 */
require_once __DIR__ . '/sweep-lib.php';

mc_isolate( 'hierarchical_rules' );
$id = mc_pid( 'item-solo-a' );
mc_reset_subject( $id );

$harbor = mc_tid( 'topic-harbor' );
$west   = mc_tid( 'topic-west' );
$region = mc_tid( 'topic-region' );

WP_CLI::log( "id={$id} harbor={$harbor} west={$west} region={$region}" );

// EDIT 1 (same request): assign Harbor -> child_to_parent expands full chain.
wp_set_object_terms( $id, array( $harbor ), 'mc_topic' );
WP_CLI::log( 'after edit1: ' . wp_json_encode( mc_terms( $id ) ) );

// EDIT 2 (SAME request, same post+taxonomy): set only West.
// OLD $processed map would SKIP apply_rule here -> raw [West], stale auto meta.
// NEW (map removed): apply_rule re-runs -> West expands -> Region+West.
wp_set_object_terms( $id, array( $west ), 'mc_topic' );
$after2 = mc_terms( $id );
$auto2  = get_post_meta( $id, '_bws_auto_terms', true );

mc_assert( 'double-save edit2 recomputes', $after2, array( $region, $west ) );
WP_CLI::log( 'auto after edit2: ' . wp_json_encode( $auto2 ) . '  (expect Region=' . $region . ')' );

mc_restore( array( $id ) );
