<?php
/**
 * mc-rules blueprint — rule token resolver.
 *
 * Shared by seed.php (writes rules on seed) and sweep-lib.php (rewrites rules on
 * restore) so both resolve manifest tokens identically. Pure resolution — no
 * writes, no isolation guard (seed enforces that separately).
 *
 * Tokens:
 *   {TERM:fixture-slug} → term_id  (looked up live by the fixture term's slug)
 *   {TODAY±N}           → date Y-m-d offset N days from WP "now"
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) ) {
	exit;
}

if ( ! function_exists( 'mc_fixture_term_ids' ) ) {
	/**
	 * Resolve the manifest's fixture term slugs → live term IDs.
	 *
	 * Keyed by FIXTURE slug (the manifest array key), value = term_id. Reads the
	 * live taxonomy so it works after a seed without threading seed-time state.
	 *
	 * @param array $manifest The mc-rules manifest.
	 * @return array<string,int> fixture-slug => term_id (0 if the term is absent).
	 */
	function mc_fixture_term_ids( array $manifest ) {
		$map = array();
		foreach ( $manifest['terms'] as $fixture_slug => $def ) {
			$term = get_term_by( 'slug', $def['slug'], $def['taxonomy'] );
			$map[ $fixture_slug ] = $term ? (int) $term->term_id : 0;
		}
		return $map;
	}
}

if ( ! function_exists( 'mc_resolve_rule_tokens' ) ) {
	/**
	 * Deep-resolve {TERM:slug} and {TODAY±N} tokens through one rule value.
	 *
	 * @param mixed              $value    Scalar or nested array from a rule.
	 * @param array<string,int>  $term_ids fixture-slug => term_id map.
	 * @return mixed Resolved value (same shape).
	 */
	function mc_resolve_rule_tokens( $value, array $term_ids ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = mc_resolve_rule_tokens( $v, $term_ids );
			}
			return $out;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( preg_match( '/^\{TERM:([a-z0-9\-]+)\}$/', $value, $m ) ) {
			if ( empty( $term_ids[ $m[1] ] ) ) {
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					WP_CLI::error( 'rule token {TERM:' . $m[1] . '} — no such fixture term' );
				}
				return 0;
			}
			return $term_ids[ $m[1] ];
		}
		if ( preg_match( '/^\{TODAY([+-]\d+)?\}$/', $value, $m ) ) {
			$offset = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
			return wp_date( 'Y-m-d', strtotime( $offset . ' days', current_time( 'timestamp' ) ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'mc_resolved_rules' ) ) {
	/**
	 * The manifest's mc_rules, every token resolved, ready to write into
	 * bws_meta_conductor_settings. Same output seed.php produces at step 6.
	 *
	 * @param array $manifest The mc-rules manifest.
	 * @return array<string,array> rule-type => list of resolved rule arrays.
	 */
	function mc_resolved_rules( array $manifest ) {
		$term_ids = mc_fixture_term_ids( $manifest );
		$out      = array();
		foreach ( $manifest['mc_rules'] as $type => $rules ) {
			foreach ( $rules as $rule ) {
				$out[ $type ][] = mc_resolve_rule_tokens( $rule, $term_ids );
			}
		}
		return $out;
	}
}
