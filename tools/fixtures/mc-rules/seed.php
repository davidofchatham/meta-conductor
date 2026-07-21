<?php
/**
 * mc-rules blueprint — idempotent applier (SKELETON — flesh out against
 * core-structures/seed.php patterns before first run).
 *
 * `wp eval-file`-able. Upserts by fixture slug. ORDER IS LOAD-BEARING:
 *   1. schema (register types + ACF so upserts land correctly)
 *   2. terms (parents before children — manifest order already does this)
 *   3. posts (parents before children) + post_terms + post_fields
 *   4. mu-plugin loader stub (schema survives snapshot restore)
 *   5. RULES LAST — merged into bws_meta_conductor_settings only after all
 *      content exists. Rules fire on save hooks; seeding posts after rules
 *      would let handlers mutate the manifest state mid-seed.
 *
 * Token resolution:
 *   {TERM:fixture-slug} → term_id (after step 2)
 *   {TODAY±N}           → date('Y-m-d', strtotime("±N days"))
 *
 * Rule merge semantics: REPLACE each mc-seeded rule-type array wholesale
 * (positional arrays don't merge safely), PRESERVE any rule-type arrays and
 * global keys (enable_logging etc.) this blueprint doesn't seed. On a
 * dedicated testbed the option is effectively blueprint-owned; wholesale
 * per-type replace keeps reseeds deterministic.
 */

if ( ! defined( 'WP_CLI' ) && ! defined( 'ABSPATH' ) ) {
	exit;
}

$mc_base     = __DIR__;
$mc_manifest = require $mc_base . '/manifest.php';
require_once $mc_base . '/schema.php';

// 0. Compose pin — core-structures must satisfy `composes_on`. Sibling-repo
// path holds on the Windows checkout (D:/Dev/Plugins) and the container
// mount (/plugins) alike.
$mc_core_manifest_path = dirname( __DIR__, 4 ) . '/bws-gb-dynamic-tags-extensions/tools/fixtures/core-structures/manifest.php';
if ( ! file_exists( $mc_core_manifest_path ) ) {
	WP_CLI::error( 'core-structures manifest not found at ' . $mc_core_manifest_path );
}
$mc_core_manifest = require $mc_core_manifest_path;
$mc_min_core      = (int) ( $mc_manifest['composes_on']['core-structures'] ?? 0 );
if ( (int) $mc_core_manifest['version'] < $mc_min_core ) {
	WP_CLI::error( sprintf(
		'core-structures manifest v%d < pinned min v%d — update the pin or reseed against a newer core.',
		$mc_core_manifest['version'], $mc_min_core
	) );
}

// 1. Schema now (seed-time direct call).
bws_fixture_mc_rules_register_types();
bws_fixture_mc_rules_register_acf();

// TODO: port from core-structures/seed.php —
//  - term upsert loop (resolve 'parent' fixture slugs; store slug→term_id map)
//  - post upsert loop (resolve 'parent' slugs; store slug→post_id map;
//    honor post_status, default 'publish')
//  - post_terms: wp_set_object_terms with resolved IDs
//  - post_fields: update_field(); resolve relationship value slug-arrays to IDs
//  - mu-plugin stub install: mu-plugins/bws-fixture-mc-rules.php requiring
//    this schema.php (path computed at seed time, hooks init + acf/init)

// 5. Rules last.
// TODO:
//  - $settings = get_option( 'bws_meta_conductor_settings', array() );
//  - foreach $mc_manifest['mc_rules'] as $type => $rules:
//      resolve {TERM:*} + {TODAY±N} tokens; $settings[$type] = $rules;
//  - update_option( 'bws_meta_conductor_settings', $settings );
//  - GUARD: assert every seeded rule has non-empty mc_*-only post_types
//    (isolation invariant — fail loud, do not write, if violated).

WP_CLI::success( 'mc-rules seed complete (skeleton — implement TODOs).' );
