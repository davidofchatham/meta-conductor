<?php
/**
 * mc-rules blueprint — behavior-sweep helper library.
 *
 * Collapses the boilerplate every per-handler sweep repeated by hand: isolate
 * one handler's rules, read terms/ACF, assert, and restore WITHOUT a full
 * re-seed. Load at the top of a sweep eval:
 *
 *   require_once '<mount>/tools/fixtures/mc-rules/sweep-lib.php';
 *   mc_isolate( 'hierarchical_rules' );
 *   wp_set_object_terms( $id, array( $harbor ), 'mc_topic' );
 *   mc_assert( '§1a', mc_terms( $id ), array( 13, 14, 15, 16 ) );
 *   mc_restore();
 *
 * WHY THIS EXISTS / WHAT IT DOES NOT SOLVE (see handler-fixture-matrix.md):
 *   - Isolation is still required — all 7 handlers hook at boot; emptying a
 *     rule ARRAY (what mc_isolate does) is what silences the others.
 *   - Handler dedup is per-REQUEST. mc_isolate/mc_restore do not change that:
 *     two user-edits of the same post+taxonomy in ONE eval still collapse to
 *     one. Keep one user-edit per wp-cli eval.
 *   - mc_restore() rewrites RULES + resets the subjects you name. It does NOT
 *     recreate deleted/renamed posts — after a delete-holder (§4c) or a
 *     title_slug rename (§7) sweep, run the full seed.php instead (restore the
 *     post_name first for title_slug — see the matrix restore-gotcha note).
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "sweep-lib is for wp-cli eval/eval-file only.\n";
	return;
}

require_once __DIR__ . '/resolve.php';

if ( ! function_exists( 'mc_sweep_manifest' ) ) {
	/** The mc-rules manifest (memoized for the request). */
	function mc_sweep_manifest() {
		static $m = null;
		if ( null === $m ) {
			$m = require __DIR__ . '/manifest.php';
		}
		return $m;
	}
}

if ( ! function_exists( 'mc_sweep_option' ) ) {
	/** Canonical option key. */
	function mc_sweep_option() {
		return 'bws_meta_conductor_settings';
	}
}

if ( ! function_exists( 'mc_sweep_clear_cache' ) ) {
	/**
	 * Clear the StorageFactory request cache so live handlers (which hold an
	 * instance from plugin boot) don't keep serving pre-edit rules. Every rule
	 * write in a sweep must be followed by this. (See seed.php step 3.)
	 */
	function mc_sweep_clear_cache() {
		if ( class_exists( '\\BWS\\MetaConductor\\Storage\\StorageFactory' ) ) {
			$s = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
			if ( method_exists( $s, 'clear_cache' ) ) {
				$s->clear_cache();
			}
		}
	}
}

if ( ! function_exists( 'mc_isolate' ) ) {
	/**
	 * Keep only $keep_types' rules enabled; empty every OTHER seeded rule type.
	 * The remaining rules are token-resolved from the manifest so isolation also
	 * REPAIRS any rule the previous scenario edited (mode, triggers, etc.).
	 *
	 * @param string|string[] $keep_types One or more rule-type keys to keep
	 *                                     (e.g. 'hierarchical_rules'). Others emptied.
	 * @return array The rule types that were kept.
	 */
	function mc_isolate( $keep_types ) {
		$keep     = (array) $keep_types;
		$manifest = mc_sweep_manifest();
		$resolved = mc_resolved_rules( $manifest );

		$opt      = mc_sweep_option();
		$settings = get_option( $opt, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		foreach ( array_keys( $manifest['mc_rules'] ) as $type ) {
			$settings[ $type ] = in_array( $type, $keep, true )
				? ( $resolved[ $type ] ?? array() )
				: array();
		}
		update_option( $opt, $settings );
		mc_sweep_clear_cache();

		WP_CLI::log( '[sweep] isolated → ' . implode( ', ', $keep ) );
		return $keep;
	}
}

if ( ! function_exists( 'mc_restore' ) ) {
	/**
	 * Restore ALL rule baselines from the manifest (token-resolved) and,
	 * optionally, reset the term/ACF state of named subjects — WITHOUT a full
	 * re-seed (no post upserts, no rewrite flush).
	 *
	 * Use this between scenarios and at sweep end for the common case. Fall back
	 * to seed.php only when posts were deleted or renamed (see the header note).
	 *
	 * @param int[] $reset_post_ids Posts whose mc_topic/mc_flag terms + the
	 *                              _bws_auto_terms meta should be cleared.
	 */
	function mc_restore( array $reset_post_ids = array() ) {
		$manifest = mc_sweep_manifest();
		$resolved = mc_resolved_rules( $manifest );

		$opt      = mc_sweep_option();
		$settings = get_option( $opt, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		foreach ( $resolved as $type => $rules ) {
			$settings[ $type ] = $rules;
		}
		update_option( $opt, $settings );
		mc_sweep_clear_cache();

		foreach ( $reset_post_ids as $pid ) {
			mc_reset_subject( (int) $pid );
		}

		WP_CLI::log( '[sweep] rules restored'
			. ( $reset_post_ids ? ' + reset ' . count( $reset_post_ids ) . ' subject(s)' : '' ) );
	}
}

if ( ! function_exists( 'mc_reset_subject' ) ) {
	/**
	 * Clear a subject's taxonomy terms (both mc taxonomies) + the hierarchical
	 * handler's tracking meta, so a scenario starts from a clean slate.
	 *
	 * NOTE: rules should be isolated/empty when you call this, or a live handler
	 * re-populates the terms on the wp_set_object_terms write.
	 *
	 * @param int      $post_id
	 * @param string[] $taxonomies Defaults to mc_topic + mc_flag.
	 */
	function mc_reset_subject( $post_id, array $taxonomies = array( 'mc_topic', 'mc_flag' ) ) {
		$post_id = (int) $post_id;
		foreach ( $taxonomies as $tax ) {
			wp_set_object_terms( $post_id, array(), $tax );
		}
		delete_post_meta( $post_id, '_bws_auto_terms' );
	}
}

if ( ! function_exists( 'mc_pid' ) ) {
	/**
	 * Resolve a fixture post slug (manifest key) → live post ID, draft-safe.
	 *
	 * @param string $fixture_slug Manifest posts[] key (e.g. 'section-draft').
	 * @return int Post ID or 0.
	 */
	function mc_pid( $fixture_slug ) {
		$manifest = mc_sweep_manifest();
		if ( ! isset( $manifest['posts'][ $fixture_slug ] ) ) {
			return 0;
		}
		require_once __DIR__ . '/lookup.php';
		$def = $manifest['posts'][ $fixture_slug ];
		return mc_fixture_find_post( $def['post_name'], $def['post_type'] );
	}
}

if ( ! function_exists( 'mc_tid' ) ) {
	/**
	 * Resolve a fixture term slug (manifest key) → live term ID.
	 *
	 * @param string $fixture_slug Manifest terms[] key (e.g. 'topic-harbor').
	 * @return int Term ID or 0.
	 */
	function mc_tid( $fixture_slug ) {
		static $map = null;
		if ( null === $map ) {
			$map = mc_fixture_term_ids( mc_sweep_manifest() );
		}
		return $map[ $fixture_slug ] ?? 0;
	}
}

if ( ! function_exists( 'mc_terms' ) ) {
	/**
	 * A post's native term IDs in a taxonomy, sorted ascending (stable compare).
	 *
	 * @param int    $post_id
	 * @param string $taxonomy
	 * @return int[]
	 */
	function mc_terms( $post_id, $taxonomy = 'mc_topic' ) {
		$t = wp_get_object_terms( (int) $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $t ) ) {
			return array();
		}
		$t = array_map( 'intval', $t );
		sort( $t );
		return $t;
	}
}

if ( ! function_exists( 'mc_acf' ) ) {
	/**
	 * An ACF taxonomy/relationship field's IDs, sorted ascending.
	 *
	 * @param int    $post_id
	 * @param string $field_key ACF field KEY (e.g. 'field_mc_topics_section').
	 * @return int[]
	 */
	function mc_acf( $post_id, $field_key ) {
		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}
		$v = get_field( $field_key, (int) $post_id );
		if ( ! is_array( $v ) ) {
			return array();
		}
		$ids = array();
		foreach ( $v as $item ) {
			if ( is_object( $item ) && isset( $item->ID ) ) {
				$ids[] = (int) $item->ID;
			} elseif ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			}
		}
		sort( $ids );
		return $ids;
	}
}

if ( ! function_exists( 'mc_assert' ) ) {
	/**
	 * Uniform PASS/FAIL line. Order-insensitive for arrays (both sorted first).
	 *
	 * @param string $label
	 * @param mixed  $got
	 * @param mixed  $want
	 * @return bool True on pass.
	 */
	function mc_assert( $label, $got, $want ) {
		if ( is_array( $got ) ) {
			sort( $got );
		}
		if ( is_array( $want ) ) {
			sort( $want );
		}
		$pass = ( $got === $want );
		WP_CLI::log( sprintf(
			'%s %s  got=%s want=%s',
			$pass ? 'PASS' : 'FAIL',
			$label,
			wp_json_encode( $got ),
			wp_json_encode( $want )
		) );
		return $pass;
	}
}
