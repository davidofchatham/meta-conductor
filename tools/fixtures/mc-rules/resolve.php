<?php
/**
 * mc-rules blueprint — rule token resolver.
 *
 * Shared by seed.php (writes rules on seed) and sweep-lib.php (rewrites rules on
 * restore) so both resolve manifest tokens identically. The resolvers are pure —
 * no writes, no isolation guard (seed enforces that separately). The one writer
 * is mc_write_post_fields(), shared for the same reason.
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

if ( ! function_exists( 'mc_post_field_ref_names' ) ) {
	/** ACF relationship/post-object fields: manifest values are arrays of fixture post slugs. */
	function mc_post_field_ref_names() {
		return array( 'mc_related_items', 'mc_primary_item', 'mc_parent_section', 'mc_bidi_items', 'mc_bidi_sections' );
	}
}

if ( ! function_exists( 'mc_check_post_field_refs' ) ) {
	/**
	 * Every relationship `post_fields` row needs its own post and every post it
	 * names to resolve. Check BEFORE writing anything: a restore that fails after
	 * emptying the rules leaves the site with none.
	 *
	 * @param array             $manifest The mc-rules manifest.
	 * @param array<string,int> $post_ids fixture post slug => post ID.
	 * @return string[] Problems; empty when every reference resolves.
	 */
	function mc_check_post_field_refs( array $manifest, array $post_ids ) {
		$problems = array();
		foreach ( $manifest['post_fields'] as $slug => $fields ) {
			foreach ( $fields as $name => $value ) {
				if ( ! in_array( $name, mc_post_field_ref_names(), true ) ) {
					continue;
				}
				if ( empty( $post_ids[ $slug ] ) ) {
					$problems[] = "{$slug} (holds {$name})";
					continue;
				}
				foreach ( (array) $value as $ref ) {
					if ( empty( $post_ids[ $ref ] ) ) {
						$problems[] = "{$ref} (named by {$slug}.{$name})";
					}
				}
			}
		}
		return array_values( array_unique( $problems ) );
	}
}

if ( ! function_exists( 'mc_write_post_fields' ) ) {
	/**
	 * Write the manifest's `post_fields` — the one loop seed.php (all fields) and
	 * sweep-lib.php's mc_restore() (relationship fields only) share, so seed-time
	 * and restore-time post data can never diverge.
	 *
	 * Relationship/post-object values are fixture slugs resolved through $post_ids;
	 * an unresolvable slug is an error, never a dropped or `0` entry. Taxonomy ACF
	 * fields take {TERM:slug} tokens. Posts absent from $post_ids are skipped.
	 *
	 * Writes ACF fields, so the caller must have emptied the rule lists first
	 * (seed.php step 3, mc_restore()).
	 *
	 * @param array             $manifest   The mc-rules manifest.
	 * @param array<string,int> $post_ids   fixture post slug => post ID.
	 * @param array<string,int> $term_ids   fixture term slug => term ID.
	 * @param bool              $refs_only  Write only the relationship/post-object
	 *                                      fields (restore leaves term and scalar
	 *                                      fields to the subject reset).
	 * @return int Fields written.
	 */
	function mc_write_post_fields( array $manifest, array $post_ids, array $term_ids, $refs_only = false ) {
		$field_keys = array(
			'mc_item'    => array(
				'mc_topics'         => 'field_mc_topics_item',
				'mc_event_date'     => 'field_mc_event_date',
				'mc_parent_section' => 'field_mc_parent_section',
				'mc_bidi_sections'  => 'field_mc_bidi_sections',
			),
			'mc_section' => array(
				'mc_related_items' => 'field_mc_related_items',
				'mc_primary_item'  => 'field_mc_primary_item',
				'mc_topics'        => 'field_mc_topics_section',
				'mc_bidi_items'    => 'field_mc_bidi_items',
			),
		);
		$ref_fields = mc_post_field_ref_names();
		// Taxonomy ACF fields have save_terms=1, so update_field() ALSO writes native
		// terms — seeding an independent term here keeps the ACF mirror and the native
		// store in agreement, matching a real editor save. A native-only seed leaves
		// the ACF field empty, and propagation's ACF-merge write then clobbers the
		// native-only term. (§5 sweep B1)
		$tax_fields = array( 'mc_topics' );
		$have_acf   = function_exists( 'update_field' );
		$written    = 0;

		$problems = mc_check_post_field_refs( $manifest, $post_ids );
		if ( $problems ) {
			WP_CLI::error( 'post_fields reference fixture posts that do not resolve: ' . implode( ', ', $problems ) );
		}

		// Queue stand-down: the AcfWriteQueue applies handlers on shutdown, after
		// the caller has put the rules back. (#42)
		// seed.php already holds it for the whole run, so only own what we added.
		$owns_filter = false === has_filter( 'meta_conductor_acf_reapply_enabled', '__return_false' );
		if ( $owns_filter ) {
			add_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
		}

		foreach ( $manifest['post_fields'] as $slug => $fields ) {
			if ( ! isset( $post_ids[ $slug ] ) ) {
				continue;
			}
			$pid   = (int) $post_ids[ $slug ];
			$ptype = get_post_type( $pid );
			foreach ( $fields as $name => $value ) {
				$is_ref = in_array( $name, $ref_fields, true );
				if ( $refs_only && ! $is_ref ) {
					continue;
				}
				if ( $is_ref ) {
					$value = array_map( function ( $ref ) use ( $post_ids ) {
						return (int) $post_ids[ $ref ];
					}, (array) $value );
				} elseif ( in_array( $name, $tax_fields, true ) ) {
					$value = array_values( array_filter( array_map(
						function ( $ref ) use ( $term_ids ) {
							if ( is_string( $ref ) && preg_match( '/^\{TERM:([a-z0-9\-]+)\}$/', $ref, $m ) ) {
								return $term_ids[ $m[1] ] ?? 0;
							}
							return is_numeric( $ref ) ? (int) $ref : 0;
						},
						(array) $value
					) ) );
				}
				$key = $field_keys[ $ptype ][ $name ] ?? null;
				if ( $have_acf && $key ) {
					update_field( $key, $value, $pid );
					$written++;
				} elseif ( ! is_array( $value ) ) {
					update_post_meta( $pid, $name, $value );
					$written++;
				}
			}
		}

		if ( $owns_filter ) {
			remove_filter( 'meta_conductor_acf_reapply_enabled', '__return_false', 99 );
		}
		return $written;
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
