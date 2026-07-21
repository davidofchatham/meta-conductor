<?php
/**
 * H7 — mc-rules fixture manifest coherence check.
 *
 * Static (no WordPress, no DB): validates the fixture manifest is internally
 * consistent BEFORE a seed run touches a test site. Catches dangling fixture
 * slugs, bad parent ordering, unknown rule types/tokens, and — the expensive
 * one — any rule whose post-type scope isn't pinned to MC-owned types.
 *
 * That isolation invariant is the whole reason the check exists: an unpinned
 * propagation rule resolves to ALL hierarchical public post types (including
 * `page`) and would rewrite the shared core-structures fixture pages the
 * GBDTE matrices assert against.
 *
 * Run:  php tests/verify-fixture-manifest.php
 * Exit: 0 = coherent, 1 = failures listed.
 *
 * See tools/fixtures/handler-fixture-matrix.md for what each rule baseline is
 * meant to exercise.
 */

$manifest_path = dirname( __DIR__ ) . '/tools/fixtures/mc-rules/manifest.php';
if ( ! file_exists( $manifest_path ) ) {
	fwrite( STDERR, "manifest not found: {$manifest_path}\n" );
	exit( 1 );
}
$m = require $manifest_path;

$fail = array();

$terms       = array_keys( $m['terms'] );
$posts       = array_keys( $m['posts'] );
$owned_types = $m['defines']['post_types'];
$owned_tax   = $m['defines']['taxonomies'];

// The 7 rule-type keys of bws_meta_conductor_settings (OptionRuleStorage).
$valid_types = array(
	'hierarchical_rules',
	'propagation_rules',
	'related_rules',
	'time_based_rules',
	'related_post_terms_rules',
	'hierarchical_level_restriction_rules',
	'title_slug_rules',
);

// ── Terms: parent defined earlier (seed applies in manifest order), MC-owned tax.
$seen = array();
foreach ( $m['terms'] as $slug => $d ) {
	if ( isset( $d['parent'] ) && ! in_array( $d['parent'], $seen, true ) ) {
		$fail[] = "term {$slug}: parent {$d['parent']} not defined before it";
	}
	if ( ! in_array( $d['taxonomy'], $owned_tax, true ) ) {
		$fail[] = "term {$slug}: taxonomy {$d['taxonomy']} not MC-owned";
	}
	$seen[] = $slug;
}

// ── Posts: same ordering rule, MC-owned post types.
$seen = array();
foreach ( $m['posts'] as $slug => $d ) {
	if ( isset( $d['parent'] ) && ! in_array( $d['parent'], $seen, true ) ) {
		$fail[] = "post {$slug}: parent {$d['parent']} not defined before it";
	}
	if ( ! in_array( $d['post_type'], $owned_types, true ) ) {
		$fail[] = "post {$slug}: post_type {$d['post_type']} not MC-owned";
	}
	$seen[] = $slug;
}

// ── Cross-references resolve.
foreach ( $m['post_terms'] as $p => $ts ) {
	if ( ! in_array( $p, $posts, true ) ) {
		$fail[] = "post_terms: unknown post {$p}";
	}
	foreach ( $ts as $t ) {
		if ( ! in_array( $t, $terms, true ) ) {
			$fail[] = "post_terms[{$p}]: unknown term {$t}";
		}
	}
}
foreach ( $m['post_fields'] as $p => $fields ) {
	if ( ! in_array( $p, $posts, true ) ) {
		$fail[] = "post_fields: unknown post {$p}";
	}
	foreach ( $fields as $name => $v ) {
		if ( ! is_array( $v ) ) {
			continue;
		}
		foreach ( $v as $ref ) {
			// Relationship/post_object values are arrays of fixture post slugs.
			if ( is_string( $ref ) && ! in_array( $ref, $posts, true ) ) {
				$fail[] = "post_fields[{$p}][{$name}]: unknown post ref {$ref}";
			}
		}
	}
}

// ── Rules: known type, resolvable tokens, MC-pinned scope.
foreach ( $m['mc_rules'] as $type => $rules ) {
	if ( ! in_array( $type, $valid_types, true ) ) {
		$fail[] = "mc_rules: unknown rule type {$type}";
	}

	foreach ( $rules as $i => $r ) {
		array_walk_recursive(
			$r,
			function ( $v ) use ( &$fail, $terms, $type, $i ) {
				if ( ! is_string( $v ) ) {
					return;
				}
				if ( preg_match( '/^\{TERM:(.+)\}$/', $v, $mm ) && ! in_array( $mm[1], $terms, true ) ) {
					$fail[] = "{$type}[{$i}]: unknown term token {$mm[1]}";
				}
				if ( preg_match( '/\{(?!TERM:|TODAY)([A-Z_]+)/', $v, $mm ) ) {
					$fail[] = "{$type}[{$i}]: unrecognized token {$mm[1]}";
				}
			}
		);

		// Scope resolution mirrors seed.php's guard, including the
		// related_post_terms case where the holder post type is the
		// "post_type:field" prefix rather than a post_types field.
		$scope = array();
		if ( ! empty( $r['post_types'] ) && is_array( $r['post_types'] ) ) {
			foreach ( $r['post_types'] as $k => $v ) {
				if ( is_string( $k ) ) {
					if ( $v ) {
						$scope[] = $k;
					}
				} else {
					$scope[] = $v;
				}
			}
		} elseif ( ! empty( $r['post_type'] ) ) {
			$scope[] = $r['post_type'];
		} elseif ( ! empty( $r['acf_field_name'] ) && false !== strpos( $r['acf_field_name'], ':' ) ) {
			$scope[] = strtok( $r['acf_field_name'], ':' );
		}

		if ( ! $scope ) {
			$fail[] = "{$type}[{$i}]: EMPTY post-type scope (isolation violation — would apply to ALL post types)";
		}
		$stray = array_diff( $scope, $owned_types );
		if ( $stray ) {
			$fail[] = "{$type}[{$i}]: non-MC scope " . implode( ',', $stray );
		}
	}
}

// ── Coverage: a baseline per rule type (matrix §1-§7).
foreach ( $valid_types as $t ) {
	if ( ! isset( $m['mc_rules'][ $t ] ) ) {
		$fail[] = "mc_rules: no baseline for {$t}";
	}
}

if ( $fail ) {
	echo "FAIL:\n - " . implode( "\n - ", $fail ) . "\n";
	exit( 1 );
}

printf(
	"OK — mc-rules manifest v%d coherent (%d terms, %d posts, %d rule types, %d rules)\n",
	$m['version'],
	count( $terms ),
	count( $posts ),
	count( $m['mc_rules'] ),
	array_sum( array_map( 'count', $m['mc_rules'] ) )
);
exit( 0 );
