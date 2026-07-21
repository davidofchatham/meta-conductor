<?php
/**
 * mc-rules blueprint — seed applier.
 *
 * Idempotent: reads manifest.php, upserts by fixture slug. Composes on the
 * GBDTE core-structures blueprint (version pin checked in step 0).
 *
 * Run (from the wp-litespeed env; path shown is the container mount):
 *   bin/wp.sh <site> eval-file <mounted-mc-repo>/tools/fixtures/mc-rules/seed.php
 *
 * ORDER IS LOAD-BEARING:
 *   0. compose pin  — core-structures manifest version >= composes_on
 *   1. mu-plugin loader stub (schema survives snapshot restore)
 *   2. schema registered NOW (init/acf already fired in this CLI run)
 *   3. RULES DISABLED — MC rule arrays emptied before any content write, so
 *      a prior seed's rules can't mutate terms while we upsert (every content
 *      write fires save_post / set_object_terms / acf/save_post).
 *   4. terms (parents before children) + posts (parents before children)
 *   5. post_terms + post_fields
 *   6. RULES WRITTEN LAST, after all content exists.
 *
 * Value tokens (manifest stays pure data):
 *   {TERM:fixture-slug} → term_id            (rule configs)
 *   {TODAY±N}           → date Y-m-d offset  (time_based windows)
 *
 * Requires ACF (Pro) for the taxonomy/relationship/date fields; degrades to
 * scalar post meta for non-array values if absent (relationship values skip).
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

define( 'BWS_FIXTURE_SEEDING', true );

$mc_base     = __DIR__;
$mc_manifest = require $mc_base . '/manifest.php';
require_once $mc_base . '/schema.php';

$log = function ( $msg ) {
	WP_CLI::log( '[mc-rules] ' . $msg );
};

require_once $mc_base . '/lookup.php';

// ---------------------------------------------------------------------------
// 0. Compose pin — core-structures must satisfy `composes_on`. Sibling-repo
// path holds on the Windows checkout (D:/Dev/Plugins) and the container
// mount (/plugins) alike.
// ---------------------------------------------------------------------------
$mc_dep_name = $mc_manifest['composes_on']['blueprint'] ?? '';
$mc_min_core = (int) ( $mc_manifest['composes_on']['min_version'] ?? 0 );
if ( ! $mc_dep_name || ! $mc_min_core ) {
	// Guard the two-key shape explicitly. A malformed composes_on would
	// otherwise read as min_version 0 and silently disable the pin.
	WP_CLI::error( "composes_on must be array( 'blueprint' => <name>, 'min_version' => <int> )" );
}

$mc_core_manifest_path = dirname( __DIR__, 4 ) . '/bws-gb-dynamic-tags-extensions/tools/fixtures/' . $mc_dep_name . '/manifest.php';
if ( ! file_exists( $mc_core_manifest_path ) ) {
	WP_CLI::error( $mc_dep_name . ' manifest not found at ' . $mc_core_manifest_path );
}
$mc_core_manifest = require $mc_core_manifest_path;
if ( (int) $mc_core_manifest['version'] < $mc_min_core ) {
	WP_CLI::error( sprintf(
		'%s manifest v%d < pinned min v%d — update the pin or reseed against a newer core.',
		$mc_dep_name, $mc_core_manifest['version'], $mc_min_core
	) );
}
$log( sprintf( 'compose pin OK (%s v%d >= v%d)', $mc_dep_name, $mc_core_manifest['version'], $mc_min_core ) );

// Collision guard — this blueprint must not redefine core-structures keys.
foreach ( array( 'post_types', 'taxonomies', 'acf_groups' ) as $mc_kind ) {
	$mc_overlap = array_intersect(
		$mc_manifest['defines'][ $mc_kind ] ?? array(),
		$mc_core_manifest['defines'][ $mc_kind ] ?? array()
	);
	if ( $mc_overlap ) {
		WP_CLI::error( "composition collision on {$mc_kind}: " . implode( ', ', $mc_overlap ) );
	}
}

// ---------------------------------------------------------------------------
// 1. Mu-plugin loader stub (path computed at seed time, not committed).
// ---------------------------------------------------------------------------
$mc_mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mc_mu_dir ) ) {
	mkdir( $mc_mu_dir, 0755, true );
}
$mc_schema_path = $mc_base . '/schema.php';
$mc_stub        = "<?php\n// Auto-installed by mc-rules seed.php — includes the blueprint schema off the plugin mount.\n"
	. "if ( file_exists( '" . addslashes( $mc_schema_path ) . "' ) ) {\n"
	. "\trequire_once '" . addslashes( $mc_schema_path ) . "';\n"
	. "}\n";
file_put_contents( $mc_mu_dir . '/bws-fixture-mc-rules.php', $mc_stub );
$log( 'mu-plugin loader stub installed → ' . $mc_mu_dir . '/bws-fixture-mc-rules.php' );

// ---------------------------------------------------------------------------
// 2. Register schema NOW.
// ---------------------------------------------------------------------------
bws_fixture_mc_rules_register_types();
bws_fixture_mc_rules_register_acf();
$mc_have_acf = function_exists( 'update_field' );
$log( 'schema registered (ACF ' . ( $mc_have_acf ? 'present' : 'ABSENT — scalar fallback' ) . ')' );

// ---------------------------------------------------------------------------
// 3. Disable MC rules for the content phase.
//
// Every upsert below fires save_post / set_object_terms / acf/save_post. With
// a prior seed's rules live, handlers would rewrite terms mid-seed and the
// resulting state wouldn't match the manifest. Empty the rule arrays first;
// step 6 writes the baselines back.
// ---------------------------------------------------------------------------
$mc_option   = 'bws_meta_conductor_settings';
$mc_settings = get_option( $mc_option, array() );
if ( ! is_array( $mc_settings ) ) {
	$mc_settings = array();
}
foreach ( array_keys( $mc_manifest['mc_rules'] ) as $mc_type ) {
	$mc_settings[ $mc_type ] = array();
}
update_option( $mc_option, $mc_settings );

// The storage layer caches the option per-request; the handlers hold a live
// StorageFactory instance from plugin boot, so a raw update_option leaves a
// stale cache that would still serve the old rules to this run's save hooks.
if ( class_exists( '\\BWS\\MetaConductor\\Storage\\StorageFactory' ) ) {
	$mc_storage = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
	if ( method_exists( $mc_storage, 'clear_cache' ) ) {
		$mc_storage->clear_cache();
	}
}
$log( 'MC rules cleared for the content phase' );

// ---------------------------------------------------------------------------
// 4a. Terms (manifest order puts parents first; 'parent' = fixture slug).
// ---------------------------------------------------------------------------
$mc_term_ids = array();
foreach ( $mc_manifest['terms'] as $mc_slug => $mc_def ) {
	$mc_args = array( 'slug' => $mc_def['slug'] );
	if ( isset( $mc_def['description'] ) ) {
		$mc_args['description'] = $mc_def['description'];
	}
	if ( isset( $mc_def['parent'] ) ) {
		if ( ! isset( $mc_term_ids[ $mc_def['parent'] ] ) ) {
			WP_CLI::warning( "term {$mc_slug}: parent {$mc_def['parent']} not yet seeded — check manifest order" );
			continue;
		}
		$mc_args['parent'] = $mc_term_ids[ $mc_def['parent'] ];
	}

	$mc_existing = get_term_by( 'slug', $mc_def['slug'], $mc_def['taxonomy'] );
	if ( $mc_existing ) {
		$mc_term_ids[ $mc_slug ] = (int) $mc_existing->term_id;
		// wp_insert_term only sets these on create — upsert on re-seed so a
		// manifest edit to parent/description actually lands.
		$mc_update = array();
		if ( isset( $mc_args['parent'] ) && (int) $mc_existing->parent !== (int) $mc_args['parent'] ) {
			$mc_update['parent'] = $mc_args['parent'];
		}
		if ( isset( $mc_args['description'] ) && $mc_existing->description !== $mc_args['description'] ) {
			$mc_update['description'] = $mc_args['description'];
		}
		if ( $mc_update ) {
			wp_update_term( (int) $mc_existing->term_id, $mc_def['taxonomy'], $mc_update );
		}
	} else {
		$mc_res = wp_insert_term( $mc_def['name'], $mc_def['taxonomy'], $mc_args );
		if ( is_wp_error( $mc_res ) ) {
			WP_CLI::warning( "term {$mc_slug}: " . $mc_res->get_error_message() );
			continue;
		}
		$mc_term_ids[ $mc_slug ] = (int) $mc_res['term_id'];
	}
}
$log( 'terms: ' . count( $mc_term_ids ) . ' upserted' );

// ---------------------------------------------------------------------------
// 4b. Posts (manifest order puts parents first; 'parent' = fixture slug).
// ---------------------------------------------------------------------------
$mc_post_ids = array();
foreach ( $mc_manifest['posts'] as $mc_slug => $mc_def ) {
	$mc_args = array(
		'post_type'   => $mc_def['post_type'],
		'post_name'   => $mc_def['post_name'],
		'post_title'  => $mc_def['post_title'],
		'post_status' => $mc_def['post_status'] ?? 'publish',
	);
	if ( isset( $mc_def['parent'] ) ) {
		if ( ! isset( $mc_post_ids[ $mc_def['parent'] ] ) ) {
			WP_CLI::warning( "post {$mc_slug}: parent {$mc_def['parent']} not yet seeded — check manifest order" );
			continue;
		}
		$mc_args['post_parent'] = $mc_post_ids[ $mc_def['parent'] ];
	}

	$mc_existing = mc_fixture_find_post( $mc_def['post_name'], $mc_def['post_type'] );
	if ( $mc_existing ) {
		$mc_args['ID']           = $mc_existing;
		$mc_post_ids[ $mc_slug ] = (int) wp_update_post( $mc_args );
	} else {
		$mc_post_ids[ $mc_slug ] = (int) wp_insert_post( $mc_args );
	}
}
$log( 'posts: ' . count( $mc_post_ids ) . ' upserted' );

// ---------------------------------------------------------------------------
// 5a. Post → term assignments. Grouped by taxonomy so a post carrying terms in
// two taxonomies doesn't have one wp_set_object_terms call wipe the other.
// ---------------------------------------------------------------------------
foreach ( $mc_manifest['post_terms'] as $mc_slug => $mc_terms ) {
	if ( ! isset( $mc_post_ids[ $mc_slug ] ) ) {
		continue;
	}
	$mc_by_tax = array();
	foreach ( $mc_terms as $mc_ref ) {
		if ( ! isset( $mc_term_ids[ $mc_ref ], $mc_manifest['terms'][ $mc_ref ] ) ) {
			continue;
		}
		$mc_by_tax[ $mc_manifest['terms'][ $mc_ref ]['taxonomy'] ][] = $mc_term_ids[ $mc_ref ];
	}
	foreach ( $mc_by_tax as $mc_tax => $mc_ids ) {
		wp_set_object_terms( $mc_post_ids[ $mc_slug ], $mc_ids, $mc_tax );
	}
}
$log( 'post→term assignments applied' );

// ---------------------------------------------------------------------------
// 5b. Post fields (ACF). Relationship/post_object values in the manifest are
// arrays of fixture slugs — resolved to IDs here.
// ---------------------------------------------------------------------------
$mc_field_keys = array(
	'mc_item'    => array(
		'mc_topics'     => 'field_mc_topics_item',
		'mc_event_date' => 'field_mc_event_date',
	),
	'mc_section' => array(
		'mc_related_items' => 'field_mc_related_items',
		'mc_primary_item'  => 'field_mc_primary_item',
		'mc_topics'        => 'field_mc_topics_section',
	),
);
$mc_ref_fields = array( 'mc_related_items', 'mc_primary_item' );

foreach ( $mc_manifest['post_fields'] as $mc_slug => $mc_fields ) {
	if ( ! isset( $mc_post_ids[ $mc_slug ] ) ) {
		continue;
	}
	$mc_pid   = $mc_post_ids[ $mc_slug ];
	$mc_ptype = get_post_type( $mc_pid );
	foreach ( $mc_fields as $mc_name => $mc_value ) {
		if ( in_array( $mc_name, $mc_ref_fields, true ) ) {
			$mc_value = array_values( array_filter( array_map(
				function ( $ref ) use ( $mc_post_ids ) {
					return $mc_post_ids[ $ref ] ?? 0;
				},
				(array) $mc_value
			) ) );
		}
		$mc_key = $mc_field_keys[ $mc_ptype ][ $mc_name ] ?? null;
		if ( $mc_have_acf && $mc_key ) {
			update_field( $mc_key, $mc_value, $mc_pid );
		} elseif ( ! is_array( $mc_value ) ) {
			update_post_meta( $mc_pid, $mc_name, $mc_value );
		}
	}
}
$log( 'post fields applied' );

// ---------------------------------------------------------------------------
// 6. Rules LAST — token-resolved, isolation-guarded, then written.
// ---------------------------------------------------------------------------
$mc_owned_types = $mc_manifest['defines']['post_types'];

/**
 * Resolve {TERM:slug} and {TODAY±N} tokens through a rule array.
 */
$mc_resolve = function ( $value ) use ( &$mc_resolve, $mc_term_ids ) {
	if ( is_array( $value ) ) {
		return array_map( $mc_resolve, $value );
	}
	if ( ! is_string( $value ) ) {
		return $value;
	}
	if ( preg_match( '/^\{TERM:([a-z0-9\-]+)\}$/', $value, $m ) ) {
		if ( ! isset( $mc_term_ids[ $m[1] ] ) ) {
			WP_CLI::error( 'rule token {TERM:' . $m[1] . '} — no such fixture term' );
		}
		return $mc_term_ids[ $m[1] ];
	}
	if ( preg_match( '/^\{TODAY([+-]\d+)?\}$/', $value, $m ) ) {
		$offset = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
		return wp_date( 'Y-m-d', strtotime( $offset . ' days', current_time( 'timestamp' ) ) );
	}
	return $value;
};

$mc_rules_out = array();
foreach ( $mc_manifest['mc_rules'] as $mc_type => $mc_rules ) {
	foreach ( $mc_rules as $mc_i => $mc_rule ) {
		$mc_rule = $mc_resolve( $mc_rule );

		// Isolation invariant (handler-fixture-matrix.md): every seeded rule
		// must be pinned to mc_*-owned post types. An unpinned propagation
		// rule resolves to ALL hierarchical public types — including `page` —
		// and would rewrite the core-structures matrix pages.
		$mc_scope = array();
		if ( isset( $mc_rule['post_types'] ) && is_array( $mc_rule['post_types'] ) ) {
			foreach ( $mc_rule['post_types'] as $mc_k => $mc_v ) {
				// Accepts both the checkbox map {slug:bool} and a flat list.
				if ( is_string( $mc_k ) ) {
					if ( $mc_v ) {
						$mc_scope[] = $mc_k;
					}
				} else {
					$mc_scope[] = $mc_v;
				}
			}
		} elseif ( isset( $mc_rule['post_type'] ) ) {
			$mc_scope[] = $mc_rule['post_type']; // title_slug: single scalar
		} elseif ( isset( $mc_rule['acf_field_name'] ) && false !== strpos( $mc_rule['acf_field_name'], ':' ) ) {
			// related_post_terms has no post_types field — the holder post type
			// is the "post_type:field" prefix (storage splits it at read time).
			$mc_scope[] = strtok( $mc_rule['acf_field_name'], ':' );
		}

		$mc_stray = array_diff( $mc_scope, $mc_owned_types );
		if ( ! $mc_scope || $mc_stray ) {
			WP_CLI::error( sprintf(
				'isolation violation — %s[%d] scope [%s]: %s. Every seeded rule must pin post types to mc_* only.',
				$mc_type,
				$mc_i,
				implode( ',', $mc_scope ),
				$mc_scope ? 'not MC-owned: ' . implode( ',', $mc_stray ) : 'empty scope means ALL post types'
			) );
		}

		$mc_rules_out[ $mc_type ][] = $mc_rule;
	}
}

// Replace each seeded rule-type array wholesale (positional arrays don't merge
// safely); leave every other key in the option — incl. rule types this
// blueprint doesn't seed and globals like enable_logging — untouched.
$mc_settings = get_option( $mc_option, array() );
if ( ! is_array( $mc_settings ) ) {
	$mc_settings = array();
}
foreach ( $mc_rules_out as $mc_type => $mc_rules ) {
	$mc_settings[ $mc_type ] = $mc_rules;
}
update_option( $mc_option, $mc_settings );
if ( isset( $mc_storage ) && method_exists( $mc_storage, 'clear_cache' ) ) {
	$mc_storage->clear_cache();
}
$log( 'rules written: ' . implode( ', ', array_map(
	function ( $t, $r ) {
		return $t . '×' . count( $r );
	},
	array_keys( $mc_rules_out ),
	$mc_rules_out
) ) );

// ---------------------------------------------------------------------------
// 7. Rewrites (new CPT/tax need fresh rules for archive URLs).
// ---------------------------------------------------------------------------
flush_rewrite_rules();
$log( 'rewrite rules flushed' );

$log( 'DONE — blueprint ' . $mc_manifest['blueprint'] . ' v' . $mc_manifest['version'] );
