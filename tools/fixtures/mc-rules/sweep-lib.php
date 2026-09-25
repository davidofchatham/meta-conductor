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

if ( ! function_exists( 'mc_fan_in' ) ) {
	/**
	 * Regroup TYPE-KEYED rules into the two ordered kind lists, KIND_TYPES
	 * order, each row tagged with its `type`.
	 *
	 * Fixture code: the manifest is authored by type, storage reads only kind
	 * lists. The owning key names the type, so a stale `type` on a row is
	 * overwritten. Non-array rows are dropped.
	 *
	 * @param array<string,array[]> $by_type Rules keyed by rule type.
	 * @return array<string,array[]> Both kind keys, always present.
	 */
	function mc_fan_in( array $by_type ) {
		$storage = \BWS\MetaConductor\Storage\OptionRuleStorage::class;
		$lists   = array(
			$storage::KIND_TERM   => array(),
			$storage::KIND_FORMAT => array(),
		);
		// all_types() is KIND_TYPES flattened in order.
		foreach ( $storage::all_types() as $type ) {
			$kind = ( new $storage() )->get_kind_for_type( $type );
			foreach ( (array) ( $by_type[ $type ] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$row['type']      = $type;
					$lists[ $kind ][] = $row;
				}
			}
		}
		return $lists;
	}
}

if ( ! function_exists( 'mc_write_rule_types' ) ) {
	/**
	 * Write a TYPE-KEYED map of rules into the ordered kind lists — the only
	 * shape storage reads since #66.
	 *
	 * Sweeps and the seeder author by rule TYPE because that is how the
	 * manifest is written, but `term_rules` / `format_rules` are what handlers
	 * and the dispatcher read. This translates one into the other through
	 * mc_fan_in(), so the row order it produces is the documented KIND_TYPES
	 * order. A sweep that asserts a specific CROSS-TYPE
	 * order must author the list itself (see mc_write_ordered_rules()).
	 *
	 * The types NOT named are carried through from what is already stored, and
	 * the legacy type-keyed keys are pruned so a stale copy can never be
	 * mistaken for the live shape.
	 *
	 * @param array<string,array[]> $by_type Rules keyed by rule type.
	 * @return void
	 */
	function mc_write_rule_types( array $by_type ) {
		$opt      = mc_sweep_option();
		$settings = get_option( $opt, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$storage = \BWS\MetaConductor\Storage\OptionRuleStorage::class;

		// The type-keyed VIEW of what is stored, so unnamed types survive.
		$current = array();
		foreach ( array( $storage::KIND_TERM, $storage::KIND_FORMAT ) as $kind ) {
			foreach ( (array) ( $settings[ $kind ] ?? array() ) as $row ) {
				if ( is_array( $row ) && isset( $row['type'] ) ) {
					$current[ $row['type'] ][] = $row;
				}
			}
		}

		foreach ( $by_type as $type => $rules ) {
			$current[ $type ] = array_values( (array) $rules );
		}

		$lists = mc_fan_in( $current );

		foreach ( $storage::all_types() as $type ) {
			unset( $settings[ $type ] );
		}
		$settings[ $storage::KIND_TERM ]   = $lists[ $storage::KIND_TERM ];
		$settings[ $storage::KIND_FORMAT ] = $lists[ $storage::KIND_FORMAT ];

		update_option( $opt, $settings );
		mc_sweep_clear_cache();
	}
}

if ( ! function_exists( 'mc_write_ordered_rules' ) ) {
	/**
	 * Write one kind list VERBATIM — for a sweep whose subject is cross-type
	 * order, which mc_write_rule_types() cannot express (it groups by type).
	 *
	 * Every other rule of that kind is replaced, so a step that authors an
	 * ordered list is also isolating itself.
	 *
	 * @param array[] $rows Rules in authored order, each carrying a `type` key.
	 * @param string  $kind Kind list key. Required — a default would read as the
	 *                      format list being the exception, and it is not.
	 * @return int Rows written.
	 */
	function mc_write_ordered_rules( array $rows, $kind ) {
		$storage = \BWS\MetaConductor\Storage\OptionRuleStorage::class;

		$opt      = mc_sweep_option();
		$settings = get_option( $opt, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// The legacy type-keyed arrays are not a shape storage reads any more
		// (#66); drop any left behind so nothing can read them by accident.
		foreach ( array_keys( mc_sweep_manifest()['mc_rules'] ) as $type ) {
			unset( $settings[ $type ] );
		}

		$settings[ $kind ] = array_values( $rows );

		update_option( $opt, $settings );
		mc_sweep_clear_cache();

		return count( $rows );
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

		$by_type = array();
		foreach ( array_keys( $manifest['mc_rules'] ) as $type ) {
			$by_type[ $type ] = in_array( $type, $keep, true )
				? ( $resolved[ $type ] ?? array() )
				: array();
		}
		mc_write_rule_types( $by_type );

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

		mc_write_rule_types( $resolved );

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
