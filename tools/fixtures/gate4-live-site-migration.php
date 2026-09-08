<?php
/**
 * Phase 4 Gate 4 (#67) — real-data migration verification.
 *
 * Subject: the production staging clone, which stores REAL rules in the
 * pre-#56 type-keyed shape with no kind list. It is therefore the exact
 * migration case #66's `upgrade_legacy_shape()` exists for, on the two rule
 * types that only exist in real data (`related`, `related_post_terms`).
 *
 * Site-agnostic: every subject — rules, terms, posts — is discovered from
 * what is stored. Nothing here names a site, a taxonomy or a post.
 *
 * Usage (from the container, in the clone's webroot):
 *   wp eval-file <path>/gate4-live-site-migration.php <step> --allow-root
 *
 * Steps, in order:
 *   impact     read-only. Which posts a bidirectional `related` rule would
 *              STRIP its target from under #61's live-state reading. Runs on
 *              either shape, so run it BEFORE the code swap and after.
 *   preflight  back the option up to `bws_mc_gate4_backup`; assert the legacy
 *              shape is what we think it is. MUST run on the OLD code — it
 *              references no plugin class.
 *   -- swap the plugin directory to the branch build here --
 *   shape      read-time migration only: the kind list storage SERVES, with
 *              nothing yet written. Front-end/cron correctness.
 *   persist    admin-load half: maybe_migrate_kind_lists() + the row repairs.
 *              Asserts what landed in the DB and that no rule-identity field
 *              moved.
 *   fire       `related` still fires on real data: apply, bidirectional
 *              removal, re-apply. Self-restoring.
 *   restore    put the backed-up option back verbatim.
 *
 * `related_post_terms` firing is NOT re-implemented here — the #42/#43
 * real-data confirmation fixture already drives it against this clone's
 * subjects. Run its `a`, `a2`, `z` steps after `persist` for that half of the
 * gate.
 *
 * `fire` mutates one real post's terms and puts them back. `restore` rewrites
 * the rules option. Nothing else writes.
 */

$step = $args[0] ?? 'impact';

$ok     = 0;
$failed = array();
$assert = function ( $cond, $msg ) use ( &$ok, &$failed ) {
	if ( $cond ) {
		++$ok;
		WP_CLI::log( '  PASS ' . $msg );
	} else {
		$failed[] = $msg;
		WP_CLI::warning( 'FAIL ' . $msg );
	}
};

define( 'G4_OPTION', 'bws_meta_conductor_settings' );
define( 'G4_BACKUP', 'bws_mc_gate4_backup' );

/** Every rule type, in the documented kind order. Duplicated on purpose: the
 * preflight step runs on a build where OptionRuleStorage does not have it. */
$G4_TERM_TYPES = array(
	'propagation_rules',
	'related_post_terms_rules',
	'time_based_rules',
	'related_rules',
	'hierarchical_rules',
	'hierarchical_level_restriction_rules',
);
$G4_FORMAT_TYPES = array( 'title_slug_rules' );

/** Keys the migration is ALLOWED to add, drop or rewrite. Everything else on a
 * row is rule identity and must survive byte-identical. */
$G4_VOLATILE = array( 'type', 'row_title', 'trigger_label', 'target_label', 'scope_label' );

$identity = function ( array $row ) use ( $G4_VOLATILE ) {
	foreach ( $G4_VOLATILE as $k ) {
		unset( $row[ $k ] );
	}
	ksort( $row );
	return $row;
};

/** Rules by type, read from whichever shape is stored. */
$rules_by_type = function () use ( $G4_TERM_TYPES, $G4_FORMAT_TYPES ) {
	$s   = get_option( G4_OPTION, array() );
	$out = array();
	foreach ( array_merge( $G4_TERM_TYPES, $G4_FORMAT_TYPES ) as $t ) {
		$out[ $t ] = array();
	}
	foreach ( array( 'term_rules', 'format_rules' ) as $kind ) {
		foreach ( (array) ( $s[ $kind ] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $out[ $row['type'] ?? '' ] ) ) {
				$out[ $row['type'] ][] = $row;
			}
		}
	}
	foreach ( $out as $t => $rows ) {
		if ( empty( $rows ) && is_array( $s[ $t ] ?? null ) ) {
			$out[ $t ] = $s[ $t ];
		}
	}
	return $out;
};

$clear_cache = function () {
	if ( class_exists( '\\BWS\\MetaConductor\\Storage\\StorageFactory' ) ) {
		$s = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
		if ( method_exists( $s, 'clear_cache' ) ) {
			$s->clear_cache();
		}
	}
};

/** First id of a possibly-array term field. */
$one = function ( $v ) {
	$v = is_array( $v ) ? reset( $v ) : $v;
	return (int) $v;
};

switch ( $step ) {

	// ---------------------------------------------------------------
	// #61 live-state impact: bidirectional `related` removes its target from
	// any in-scope post that does not hold a trigger term. Read-only.
	case 'impact':
		$rules = $rules_by_type()['related_rules'];
		WP_CLI::log( sprintf( '%d related rules', count( $rules ) ) );

		$targets = array();
		foreach ( $rules as $r ) {
			$targets[] = $one( $r['target_term_id'] ?? 0 );
		}
		$shared = array_keys( array_filter( array_count_values( array_filter( $targets ) ), function ( $n ) {
			return $n > 1;
		} ) );

		$total_strip = 0;
		foreach ( $rules as $i => $r ) {
			if ( empty( $r['enabled'] ) ) {
				continue;
			}
			$target = $one( $r['target_term_id'] ?? 0 );
			$term   = get_term( $target );
			if ( ! $term || is_wp_error( $term ) ) {
				WP_CLI::warning( sprintf( 'rule %d: target term %d does not resolve', $i, $target ) );
				continue;
			}

			$trigger_ids = array_map( 'intval', (array) ( $r['trigger_term_id'] ?? array() ) );
			$resolvable  = array_filter( $trigger_ids, function ( $t ) {
				$x = get_term( $t );
				return $x && ! is_wp_error( $x );
			} );

			$holders = get_objects_in_term( array( $target ), $term->taxonomy );
			$holders = is_wp_error( $holders ) ? array() : array_map( 'intval', $holders );

			$trig_tax = array();
			foreach ( $resolvable as $t ) {
				$x = get_term( $t );
				$trig_tax[ $x->taxonomy ][] = (int) $t;
			}
			$with_trigger = array();
			foreach ( $trig_tax as $tax => $ids ) {
				$in = get_objects_in_term( $ids, $tax );
				if ( ! is_wp_error( $in ) ) {
					$with_trigger = array_merge( $with_trigger, array_map( 'intval', $in ) );
				}
			}

			$strip = ( ! empty( $r['bidirectional'] ) && $resolvable )
				? array_values( array_diff( $holders, $with_trigger ) )
				: array();
			$total_strip += count( $strip );

			WP_CLI::log( sprintf(
				'  rule %-2d %-9s target=%-5d(%s) triggers=%d/%d holders=%-4d with_trigger=%-4d WOULD_STRIP=%d%s%s',
				$i,
				! empty( $r['bidirectional'] ) ? 'BIDI' : 'add-only',
				$target,
				$term->taxonomy,
				count( $resolvable ),
				count( $trigger_ids ),
				count( $holders ),
				count( array_intersect( $holders, $with_trigger ) ),
				count( $strip ),
				in_array( $target, $shared, true ) ? '  [SHARED TARGET]' : '',
				$strip ? '  ids=' . implode( ',', array_slice( $strip, 0, 8 ) ) : ''
			) );
		}

		if ( $shared ) {
			WP_CLI::warning( sprintf(
				'targets written by more than one rule: %s — under live-state removal the LAST such rule in the list decides, so an earlier rule that adds it is cancelled.',
				implode( ',', $shared )
			) );
		}
		WP_CLI::log( sprintf( 'total posts that would lose a target term on the next pass: %d', $total_strip ) );
		break;

	// ---------------------------------------------------------------
	case 'preflight':
		$stored = get_option( G4_OPTION, array() );
		$assert( is_array( $stored ) && $stored, 'rules option present' );

		if ( get_option( G4_BACKUP, null ) === null ) {
			add_option( G4_BACKUP, $stored, '', false );
			WP_CLI::log( '  backed up to ' . G4_BACKUP );
		} else {
			WP_CLI::log( '  ' . G4_BACKUP . ' already exists — left alone' );
		}
		$assert( get_option( G4_BACKUP ) === $stored, 'backup matches the live option' );

		$assert( ! isset( $stored['term_rules'] ), 'no term_rules kind list yet (pre-#56 shape)' );
		$assert( ! isset( $stored['format_rules'] ), 'no format_rules kind list yet' );
		$assert( get_option( 'bws_mc_kind_schema', null ) === null, 'kind schema flag unset' );

		$counts = array();
		foreach ( array_merge( $G4_TERM_TYPES, $G4_FORMAT_TYPES ) as $t ) {
			$counts[ $t ] = count( (array) ( $stored[ $t ] ?? array() ) );
		}
		WP_CLI::log( '  legacy counts: ' . wp_json_encode( $counts ) );
		$assert( array_sum( $counts ) > 0, 'legacy arrays hold rules' );
		break;

	// ---------------------------------------------------------------
	// Read-time migration: what a front-end or cron request sees, before any
	// admin load has written anything.
	case 'shape':
		$backup = get_option( G4_BACKUP, null );
		if ( ! is_array( $backup ) ) {
			WP_CLI::error( 'no backup — run `preflight` on the OLD build first' );
		}

		$storage = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
		$view    = $storage->get_raw_settings();

		$expected = array();
		foreach ( $G4_TERM_TYPES as $t ) {
			foreach ( (array) ( $backup[ $t ] ?? array() ) as $row ) {
				$expected[] = array( $t, $row );
			}
		}

		$rows = $view['term_rules'] ?? null;
		$assert( is_array( $rows ), 'term_rules present in the served settings' );
		$assert( count( (array) $rows ) === count( $expected ), sprintf(
			'term_rules holds %d rows (legacy total %d)', count( (array) $rows ), count( $expected )
		) );

		$order_ok  = true;
		$fields_ok = true;
		foreach ( $expected as $i => $pair ) {
			list( $type, $row ) = $pair;
			$got = $rows[ $i ] ?? array();
			if ( ( $got['type'] ?? '' ) !== $type ) {
				$order_ok = false;
				WP_CLI::warning( sprintf( '  row %d: type %s, expected %s', $i, $got['type'] ?? '?', $type ) );
			}
			if ( $identity( $got ) !== $identity( $row ) ) {
				$fields_ok = false;
				WP_CLI::warning( sprintf( '  row %d (%s): identity fields differ', $i, $type ) );
			}
		}
		$assert( $order_ok, 'rows land in KIND_TYPES order, grouped by type' );
		$assert( $fields_ok, 'every legacy row carried across verbatim' );

		$assert( ( $view['format_rules'] ?? null ) === array(), 'format_rules present and empty' );

		$legacy_left = array();
		foreach ( array_merge( $G4_TERM_TYPES, $G4_FORMAT_TYPES ) as $t ) {
			if ( isset( $view[ $t ] ) ) {
				$legacy_left[] = $t;
			}
		}
		$assert( ! $legacy_left, 'legacy type keys pruned from the served view: ' . wp_json_encode( $legacy_left ) );

		$raw = get_option( G4_OPTION, array() );
		$assert( ! isset( $raw['term_rules'] ), 'read migrated nothing to the DB (a read must not write)' );

		// The rules the handlers will actually act on.
		foreach ( array( 'related_rules', 'related_post_terms_rules' ) as $t ) {
			$n = count( $storage->get_rules( $t ) );
			$assert( $n === count( (array) ( $backup[ $t ] ?? array() ) ), sprintf( 'get_rules(%s) = %d', $t, $n ) );
		}
		break;

	// ---------------------------------------------------------------
	case 'persist':
		$backup = get_option( G4_BACKUP, null );
		if ( ! is_array( $backup ) ) {
			WP_CLI::error( 'no backup — run `preflight` on the OLD build first' );
		}

		$storage = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
		$storage->clear_cache();

		$wrote = $storage->maybe_migrate_kind_lists();
		WP_CLI::log( '  maybe_migrate_kind_lists() => ' . var_export( $wrote, true ) );

		$raw = get_option( G4_OPTION, array() );
		$assert( isset( $raw['term_rules'] ) && is_array( $raw['term_rules'] ), 'term_rules persisted' );
		$assert( isset( $raw['format_rules'] ), 'format_rules persisted' );
		$assert( (int) get_option( 'bws_mc_kind_schema' ) === 1, 'kind schema flag set to 1' );

		$still = array();
		foreach ( array_merge( $G4_TERM_TYPES, $G4_FORMAT_TYPES ) as $t ) {
			if ( isset( $raw[ $t ] ) ) {
				$still[] = $t;
			}
		}
		$assert( ! $still, 'legacy type keys pruned from the DB: ' . wp_json_encode( $still ) );

		// Idempotence — a second call must not rewrite.
		$storage->clear_cache();
		$assert( $storage->maybe_migrate_kind_lists() === false, 'second call is a no-op' );

		// The admin-load row repairs (labels shed, row_title baked). Driving the
		// real boot needs an admin request; call the same seam the settings page
		// reaches through a save instead.
		$repaired = \BWS\MetaConductor\Admin\WireframeBootstrap::snapshot_term_rule_labels(
			array( 'term_rules' => $raw['term_rules'] )
		);
		$titled = 0;
		foreach ( $repaired['term_rules'] as $row ) {
			if ( ! empty( $row['row_title'] ) ) {
				++$titled;
			}
		}
		$assert( $titled === count( $raw['term_rules'] ), sprintf( 'every row titles ( %d/%d )', $titled, count( $raw['term_rules'] ) ) );

		// Fidelity against the pre-migration rules, ignoring only the volatile keys.
		$expected = array();
		foreach ( $G4_TERM_TYPES as $t ) {
			foreach ( (array) ( $backup[ $t ] ?? array() ) as $row ) {
				$expected[] = $identity( $row );
			}
		}
		$got = array_map( $identity, $raw['term_rules'] );
		$assert( $got === $expected, 'no rule-identity field changed across the migration' );

		foreach ( array( 'related_rules', 'related_post_terms_rules' ) as $t ) {
			$n = count( $storage->get_rules( $t, array( 'enabled' => true ) ) );
			WP_CLI::log( sprintf( '  enabled %s: %d', $t, $n ) );
			$assert( $n > 0, sprintf( '%s still has enabled rules', $t ) );
		}
		break;

	// ---------------------------------------------------------------
	// `related` still fires on real data. Picks a bidirectional rule whose
	// target no other rule writes, so nothing else can mask the result.
	case 'fire':
		$storage = \BWS\MetaConductor\Storage\StorageFactory::get_instance();
		$rules   = $storage->get_rules( 'related_rules', array( 'enabled' => true ) );

		$targets = array();
		foreach ( $rules as $r ) {
			$targets[] = $one( $r['target_term_id'] ?? 0 );
		}
		$counts = array_count_values( array_filter( $targets ) );

		$subject_rule = null;
		foreach ( $rules as $r ) {
			$t = $one( $r['target_term_id'] ?? 0 );
			if ( ! empty( $r['bidirectional'] ) && ( $counts[ $t ] ?? 0 ) === 1 ) {
				$subject_rule = $r;
				break;
			}
		}
		if ( ! $subject_rule ) {
			WP_CLI::error( 'no bidirectional related rule with an unshared target — pick a subject by hand' );
		}

		$target      = $one( $subject_rule['target_term_id'] );
		$target_term = get_term( $target );
		$trigger     = 0;
		foreach ( (array) $subject_rule['trigger_term_id'] as $t ) {
			$x = get_term( (int) $t );
			if ( $x && ! is_wp_error( $x ) ) {
				$trigger = (int) $t;
				break;
			}
		}
		$trigger_term = get_term( $trigger );

		$holders = get_objects_in_term( array( $trigger ), $trigger_term->taxonomy );
		$holders = is_wp_error( $holders ) ? array() : array_map( 'intval', $holders );
		$post_id = 0;
		foreach ( $holders as $h ) {
			if ( has_term( $target, $target_term->taxonomy, $h ) ) {
				$post_id = $h;
				break;
			}
		}
		if ( ! $post_id ) {
			WP_CLI::error( 'no post holds both trigger and target — nothing to exercise' );
		}

		WP_CLI::log( sprintf(
			'  subject post %d (%s) — trigger %d "%s" [%s] -> target %d "%s" [%s]',
			$post_id, get_post_type( $post_id ),
			$trigger, $trigger_term->name, $trigger_term->taxonomy,
			$target, $target_term->name, $target_term->taxonomy
		) );

		$dispatcher = \BWS\MetaConductor\Core\TermDispatcher::instance();
		if ( ! $dispatcher ) {
			WP_CLI::error( 'no TermDispatcher instance — is the plugin active?' );
		}

		// 1. A pass over an already-correct post leaves the target in place.
		$dispatcher->drain_post( $post_id );
		$assert( has_term( $target, $target_term->taxonomy, $post_id ), '1. pass keeps the target while the trigger is held' );

		// 2. Drop the trigger without letting a cascade answer for us, then pass:
		//    bidirectional live-state removal must take the target away.
		remove_all_actions( 'set_object_terms' );
		remove_all_actions( 'deleted_term_relationships' );
		wp_remove_object_terms( $post_id, array( $trigger ), $trigger_term->taxonomy );
		clean_object_term_cache( $post_id, get_post_type( $post_id ) );
		$assert( ! has_term( $trigger, $trigger_term->taxonomy, $post_id ), '2a. trigger removed' );

		$dispatcher->drain_post( $post_id );
		$assert( ! has_term( $target, $target_term->taxonomy, $post_id ), '2b. bidirectional pass withdrew the target (#61 live state)' );

		// 3. Put the trigger back; the pass re-applies the target.
		wp_set_object_terms( $post_id, array( $trigger ), $trigger_term->taxonomy, true );
		clean_object_term_cache( $post_id, get_post_type( $post_id ) );
		$dispatcher->drain_post( $post_id );
		$assert( has_term( $target, $target_term->taxonomy, $post_id ), '3. pass re-applied the target' );
		$assert( has_term( $trigger, $trigger_term->taxonomy, $post_id ), '3. trigger restored' );
		break;

	// ---------------------------------------------------------------
	case 'restore':
		$backup = get_option( G4_BACKUP, null );
		if ( ! is_array( $backup ) ) {
			WP_CLI::error( 'nothing backed up' );
		}
		update_option( G4_OPTION, $backup );
		delete_option( 'bws_mc_kind_schema' );
		$clear_cache();
		$assert( get_option( G4_OPTION ) === $backup, 'rules option restored to the pre-migration bytes' );
		WP_CLI::log( '  ' . G4_BACKUP . ' left in place — delete it by hand when the gate is signed off' );
		break;

	default:
		WP_CLI::error( 'unknown step: ' . $step );
}

if ( $failed ) {
	WP_CLI::error( sprintf( '%s: %d passed, %d FAILED', $step, $ok, count( $failed ) ) );
}
WP_CLI::success( sprintf( '%s: %d assertions passed', $step, $ok ) );
