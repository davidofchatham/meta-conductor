<?php
/**
 * mc-rules blueprint — post-seed smoke + negative controls.
 *
 * Run:
 *   bin/wp.sh <site> eval-file <mounted-mc-repo>/tools/fixtures/mc-rules/verify.php
 *
 * Two halves:
 *   A. Seeded surface exists and matches the manifest.
 *   B. Negative controls — the core-structures blueprint is untouched. Run
 *      section B again after every behavior sweep (pre-restore) to catch
 *      isolation breaks: an MC rule that leaked onto page/post/staff.
 *
 * NOT a behavior-sweep replacement — asserts state, not handler outcomes.
 * Exits non-zero if any assertion fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

$mc_base     = __DIR__;
$mc_manifest = require $mc_base . '/manifest.php';

$mc_core_path     = dirname( __DIR__, 4 ) . '/bws-gb-dynamic-tags-extensions/tools/fixtures/core-structures/manifest.php';
$mc_core_manifest = file_exists( $mc_core_path ) ? require $mc_core_path : null;

$mc_pass = 0;
$mc_fail = array();

$check = function ( $label, $ok, $detail = '' ) use ( &$mc_pass, &$mc_fail ) {
	if ( $ok ) {
		$mc_pass++;
		return;
	}
	$mc_fail[] = $label . ( $detail ? " — {$detail}" : '' );
	WP_CLI::warning( 'FAIL ' . $label . ( $detail ? " — {$detail}" : '' ) );
};

require_once $mc_base . '/lookup.php';

/** Resolve a fixture post slug → ID (any status, oldest match). */
$mc_post = function ( $fixture_slug ) use ( $mc_manifest ) {
	$def = $mc_manifest['posts'][ $fixture_slug ] ?? null;
	if ( ! $def ) {
		return 0;
	}
	return mc_fixture_find_post( $def['post_name'], $def['post_type'] );
};

/** Resolve a fixture term slug → term_id. */
$mc_term = function ( $fixture_slug ) use ( $mc_manifest ) {
	$def = $mc_manifest['terms'][ $fixture_slug ] ?? null;
	if ( ! $def ) {
		return 0;
	}
	$term = get_term_by( 'slug', $def['slug'], $def['taxonomy'] );
	return $term ? (int) $term->term_id : 0;
};

WP_CLI::log( '── A. Seeded surface ──────────────────────────────────' );

// A1. Types + taxonomies.
$check( 'CPT mc_item registered', post_type_exists( 'mc_item' ) );
$check( 'CPT mc_section registered', post_type_exists( 'mc_section' ) );
$check( 'mc_section is hierarchical', is_post_type_hierarchical( 'mc_section' ) );
$check( 'taxonomy mc_topic registered', taxonomy_exists( 'mc_topic' ) );
$check( 'mc_topic is hierarchical', is_taxonomy_hierarchical( 'mc_topic' ) );
$check( 'taxonomy mc_flag registered', taxonomy_exists( 'mc_flag' ) );

// A2. Term tree depth — harbor's ancestor chain must be coastal→east→region.
$mc_harbor = $mc_term( 'topic-harbor' );
$check( 'term topic-harbor exists', $mc_harbor > 0 );
if ( $mc_harbor ) {
	$mc_expect = array( $mc_term( 'topic-coastal' ), $mc_term( 'topic-east' ), $mc_term( 'topic-region' ) );
	$mc_actual = array_map( 'intval', get_ancestors( $mc_harbor, 'mc_topic', 'taxonomy' ) );
	$check(
		'harbor ancestor chain = coastal→east→region (4-level tree)',
		$mc_actual === $mc_expect,
		'got [' . implode( ',', $mc_actual ) . '] want [' . implode( ',', $mc_expect ) . ']'
	);
}

// A3. Posts resolve; propagation chain wired; draft child is draft.
foreach ( array_keys( $mc_manifest['posts'] ) as $mc_slug ) {
	$check( "post {$mc_slug} exists", $mc_post( $mc_slug ) > 0 );
}

// A3b. Idempotency — exactly ONE post per fixture slug. A blind existence
// check made the seeder re-insert `section-draft` on every run; four copies
// accumulated before the missing-fixture failure gave it away. Assert the
// invariant directly so duplication can't grow silently again.
foreach ( $mc_manifest['posts'] as $mc_slug => $mc_def ) {
	$mc_dupes = mc_fixture_count_posts( $mc_def['post_name'], $mc_def['post_type'] );
	$check(
		"post {$mc_slug}: exactly one copy (seed idempotent)",
		count( $mc_dupes ) === 1,
		count( $mc_dupes ) . ' copies: [' . implode( ',', $mc_dupes ) . ']'
	);
}
$mc_parent = $mc_post( 'section-parent' );
$mc_child  = $mc_post( 'section-child' );
$mc_grand  = $mc_post( 'section-grand' );
$check( 'section-parent child of section-grand', $mc_parent && (int) wp_get_post_parent_id( $mc_parent ) === $mc_grand );
$check( 'section-child child of section-parent', $mc_child && (int) wp_get_post_parent_id( $mc_child ) === $mc_parent );
$mc_draft = $mc_post( 'section-draft' );
$check( 'section-draft has status draft', $mc_draft && 'draft' === get_post_status( $mc_draft ) );

// A4. ACF values.
if ( function_exists( 'get_field' ) ) {
	$mc_alpha = $mc_post( 'item-alpha' );
	$check( 'item-alpha mc_event_date = 20300315', $mc_alpha && '20300315' === (string) get_field( 'mc_event_date', $mc_alpha ) );

	$mc_holder = $mc_post( 'section-holder' );
	$mc_rel    = $mc_holder ? (array) get_field( 'mc_related_items', $mc_holder ) : array();
	$mc_rel    = array_map( 'intval', $mc_rel );
	$mc_want   = array( $mc_post( 'item-alpha' ), $mc_post( 'item-beta' ) );
	$check(
		'section-holder mc_related_items = [alpha, beta]',
		$mc_rel === $mc_want,
		'got [' . implode( ',', $mc_rel ) . ']'
	);
} else {
	WP_CLI::warning( 'ACF absent — skipped A4 field assertions' );
}

// A5. Seeded terms landed (and survived the seed's own save hooks).
$mc_gamma = $mc_post( 'item-gamma' );
if ( $mc_gamma ) {
	$mc_have = array_map( 'intval', wp_get_object_terms( $mc_gamma, 'mc_topic', array( 'fields' => 'ids' ) ) );
	$check( 'item-gamma holds Coastal (time_based filter subject)', in_array( $mc_term( 'topic-coastal' ), $mc_have, true ) );
}

// A6. Rules present through the REAL read path (exercises normalize_rule_shape)
// and every rule is enabled + MC-scoped.
if ( class_exists( '\\BWS\\MetaConductor\\Storage\\StorageFactory' ) ) {
	$mc_storage = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
	$mc_storage->clear_cache();
	$mc_owned = $mc_manifest['defines']['post_types'];

	foreach ( $mc_manifest['mc_rules'] as $mc_type => $mc_seeded ) {
		$mc_read = $mc_storage->get_rules( $mc_type );
		$check(
			"rules {$mc_type}: " . count( $mc_seeded ) . ' present',
			count( $mc_read ) === count( $mc_seeded ),
			'read ' . count( $mc_read )
		);

		foreach ( $mc_read as $mc_i => $mc_rule ) {
			$check( "{$mc_type}[{$mc_i}] enabled", ! empty( $mc_rule['enabled'] ) );

			$mc_scope = array();
			if ( ! empty( $mc_rule['post_types'] ) && is_array( $mc_rule['post_types'] ) ) {
				foreach ( $mc_rule['post_types'] as $mc_k => $mc_v ) {
					if ( is_string( $mc_k ) ) {
						if ( $mc_v ) {
							$mc_scope[] = $mc_k;
						}
					} else {
						$mc_scope[] = $mc_v;
					}
				}
			} elseif ( ! empty( $mc_rule['post_type'] ) ) {
				// title_slug's own field, and — post normalize_rule_shape —
				// related_post_terms' holder type split out of acf_field_name.
				$mc_scope[] = $mc_rule['post_type'];
			}
			$check(
				"{$mc_type}[{$mc_i}] scoped to MC post types only",
				$mc_scope && ! array_diff( $mc_scope, $mc_owned ),
				'scope [' . implode( ',', $mc_scope ) . ']'
			);
		}
	}

	// Token resolution actually happened — no literal {TERM:*} survived.
	$mc_raw = wp_json_encode( get_option( 'bws_meta_conductor_settings', array() ) );
	$check( 'no unresolved {TERM:} / {TODAY} tokens in stored rules', false === strpos( (string) $mc_raw, '{TERM:' ) && false === strpos( (string) $mc_raw, '{TODAY' ) );
} else {
	$check( 'StorageFactory available (plugin active?)', false );
}

// A7. Cron event for time_based cleanup.
$check( 'cron bws_taxonomy_manager_cleanup scheduled', (bool) wp_next_scheduled( 'bws_taxonomy_manager_cleanup' ) );

WP_CLI::log( '── B. Negative controls (core-structures untouched) ────' );

if ( ! $mc_core_manifest ) {
	WP_CLI::warning( 'core-structures manifest not found — skipped section B' );
} else {
	// Resolve a core-structures fixture post slug → ID.
	$mc_core_post = function ( $fixture_slug ) use ( $mc_core_manifest ) {
		$def = $mc_core_manifest['posts'][ $fixture_slug ] ?? null;
		if ( ! $def ) {
			return 0;
		}
		// Same status-blind trap as the MC lookup (see lookup.php). Every
		// core-structures fixture is published today, so `'any'` would work
		// here by luck — use the safe helper anyway so a future non-published
		// core fixture doesn't silently read as missing.
		return mc_fixture_find_post( $def['post_name'], $def['post_type'] );
	};

	// B1. department term assignments still match the core manifest.
	foreach ( $mc_core_manifest['post_terms'] as $mc_slug => $mc_want_slugs ) {
		$mc_pid = $mc_core_post( $mc_slug );
		if ( ! $mc_pid ) {
			$check( "core post {$mc_slug} exists", false );
			continue;
		}
		$mc_want = array();
		foreach ( $mc_want_slugs as $mc_ref ) {
			$mc_def  = $mc_core_manifest['terms'][ $mc_ref ];
			$mc_term_obj = get_term_by( 'slug', $mc_def['slug'], $mc_def['taxonomy'] );
			if ( $mc_term_obj ) {
				$mc_want[] = (int) $mc_term_obj->term_id;
			}
		}
		$mc_got = array_map( 'intval', wp_get_object_terms( $mc_pid, 'department', array( 'fields' => 'ids' ) ) );
		sort( $mc_want );
		sort( $mc_got );
		$check(
			"NEG core {$mc_slug}: department terms unchanged",
			$mc_got === $mc_want,
			'got [' . implode( ',', $mc_got ) . '] want [' . implode( ',', $mc_want ) . ']'
		);
	}

	// B2. staff singles carry no unexpected department terms (core manifest
	// assigns none — an MC leak would show up as additions).
	foreach ( array( 'staff-jane-partner', 'staff-tom-associate' ) as $mc_slug ) {
		$mc_pid = $mc_core_post( $mc_slug );
		if ( ! $mc_pid ) {
			continue;
		}
		$mc_want = $mc_core_manifest['post_terms'][ $mc_slug ] ?? array();
		$mc_got  = wp_get_object_terms( $mc_pid, 'department', array( 'fields' => 'ids' ) );
		$check(
			"NEG core {$mc_slug}: department term count unchanged",
			count( $mc_got ) === count( $mc_want ),
			'got ' . count( $mc_got ) . ' want ' . count( $mc_want )
		);
	}

	// B3. Slugs unchanged (title_slug isolation — a leaked rule renames posts
	// and every GBDTE matrix URL breaks).
	foreach ( $mc_core_manifest['posts'] as $mc_slug => $mc_def ) {
		$mc_pid = $mc_core_post( $mc_slug );
		$check(
			"NEG core {$mc_slug}: post_name still '{$mc_def['post_name']}'",
			$mc_pid && get_post_field( 'post_name', $mc_pid ) === $mc_def['post_name']
		);
	}

	// B4. GBDTE settings baseline untouched.
	$mc_dt = get_option( 'bws_dynamic_tags_settings', array() );
	$check(
		'NEG bws_dynamic_tags_settings phone baseline intact (CC 1, strip off)',
		isset( $mc_dt['phone']['country_code'] ) && '1' === (string) $mc_dt['phone']['country_code'] && empty( $mc_dt['phone']['strip_leading_cc'] )
	);
}

WP_CLI::log( '───────────────────────────────────────────────────────' );
if ( $mc_fail ) {
	WP_CLI::error( sprintf( '%d passed, %d FAILED:%s%s', $mc_pass, count( $mc_fail ), PHP_EOL . ' - ', implode( PHP_EOL . ' - ', $mc_fail ) ) );
}
WP_CLI::success( sprintf( 'mc-rules verify: %d assertions passed.', $mc_pass ) );
