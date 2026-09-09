<?php
/**
 * mc-rules — #67 behaviour sweep: the post_status gate, closing #23.
 *
 * #23's own resolution comment says the gate landed as a side effect of
 * Phase 4's config collapse (#57/#58 put the shared subfield on every term
 * type at once) and enforcement was checked type-by-type as each handler
 * converted to an applier (#61/#62/#63). What has never run is a scenario
 * that actually sets a restrictive `post_status` and shows a rule SKIP a
 * post outside it, then fire once the post matches — this sweep is that
 * proof, not new handler code.
 *
 * Two enforcement shapes exist, and this sweep covers both:
 *
 *   §67c  The standard shape — `UnifiedHandlerBase::should_process_post()`
 *         gates the post being WRITTEN. `HierarchicalHandler` is the live
 *         subject; `HierarchicalLevelRestrictionHandler`, `PropagationHandler`,
 *         `TimeBasedHandler` and `RelatedHandler` call the identical base
 *         method at the top of their own `apply_to_post()` (grep-confirmed,
 *         same call shape, same early return) — one live scenario stands for
 *         all five rather than repeating an identical code path four more
 *         times on the testbed.
 *   §67d  The exception — `RelatedPostTermsHandler` deliberately does NOT
 *         call `should_process_post()`. Its `post_status` gates the SOURCE
 *         post being read FROM, not the dependent being written TO (§V5;
 *         the class docblock explains why: gating the dependent would stop a
 *         draft dependent being kept in sync by a published source). A
 *         draft holder must be excluded from copying; publishing it must
 *         turn the copy on.
 *
 * `title_slug_rules` (the one FORMAT-kind type) is OUT OF SCOPE here on
 * purpose: `FormatRulesConfig` never gained a `post_status` subfield in
 * #59, and #67's acceptance criterion is scoped to "every TERM type" — #23's
 * original text named title_slug too, but nothing in Phase 4 wired it, and
 * closing #23 in the term-repeater's terms is what actually landed. Left as
 * a documented gap, not silently fixed here (that would be new format-config
 * feature work, out of scope for a gate-verification ticket) — see
 * docs/future-features.md if it should happen later.
 *
 * Usage (one step per eval):
 *   wp eval-file .../sweep-67-post-status.php standard    # §67c
 *   wp eval-file .../sweep-67-post-status.php source-gate # §67d
 *   wp eval-file .../sweep-67-post-status.php restore
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'standard';

function mc67ps_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

function mc67ps_author( array $rows ) {
	return mc_write_ordered_rules( $rows, OptionRuleStorage::KIND_TERM );
}

switch ( $step ) {

	// -------------------------------------------------------------- §67c
	case 'standard':
		$solo   = mc_pid( 'item-solo-a' );
		$harbor = mc_tid( 'topic-harbor' );

		mc67ps_author( array() );
		mc_reset_subject( $solo );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'field_mc_topics_item', array(), $solo );
		}
		mc67ps_dispatcher()->drain();
		wp_update_post( array( 'ID' => $solo, 'post_status' => 'draft' ) );

		$pass = 0; $fail = 0;
		$t = function ( $label, $got, $want ) use ( &$pass, &$fail ) {
			if ( mc_assert( $label, $got, $want ) ) { $pass++; } else { $fail++; }
		};

		// Gate excludes the subject's own status: expect the write skipped —
		// only Harbor lands, no ancestor expansion.
		mc67ps_author( array( array(
			'type'                 => 'hierarchical_rules',
			'enabled'              => true,
			'taxonomy'             => 'mc_topic',
			'post_types'           => array( 'mc_item' ),
			'post_status'          => array( 'publish' ),
			'inheritance_behavior' => 'ancestors',
			'inheritance_depth'    => 'all',
		) ) );
		wp_set_object_terms( $solo, array( $harbor ), 'mc_topic' );
		mc67ps_dispatcher()->drain();
		$t( '§67c gated OUT: draft subject, rule wants publish — no expansion',
			mc_terms( $solo ), array( $harbor ) );

		// Same edit, gate now includes the subject's status: expect the
		// applier to run and expand ancestors.
		mc67ps_author( array( array(
			'type'                 => 'hierarchical_rules',
			'enabled'              => true,
			'taxonomy'             => 'mc_topic',
			'post_types'           => array( 'mc_item' ),
			'post_status'          => array( 'draft' ),
			'inheritance_behavior' => 'ancestors',
			'inheritance_depth'    => 'all',
		) ) );
		wp_set_object_terms( $solo, array( $harbor ), 'mc_topic' );
		mc67ps_dispatcher()->drain();
		$t( '§67c gated IN: draft subject, rule now wants draft — expands',
			mc_terms( $solo ),
			array( mc_tid( 'topic-region' ), mc_tid( 'topic-east' ), mc_tid( 'topic-coastal' ), $harbor ) );

		WP_CLI::log( sprintf( '[sweep 67-post-status/standard] %d passed, %d failed', $pass, $fail ) );
		if ( $fail ) {
			WP_CLI::error( sprintf( '#67 post-status sweep step "standard" FAILED (%d assertions).', $fail ) );
		}
		break;

	// -------------------------------------------------------------- §67d
	case 'source-gate':
		$holder  = mc_pid( 'section-holder' );
		$solo    = mc_pid( 'item-solo-a' );
		$coastal = mc_tid( 'topic-coastal' );

		mc67ps_author( array() );
		mc_reset_subject( $solo );
		mc_reset_subject( $holder );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'field_mc_related_items', array(), $holder );
		}
		wp_update_post( array( 'ID' => $holder, 'post_status' => 'draft' ) );
		wp_set_object_terms( $holder, array( $coastal ), 'mc_topic' );
		mc67ps_dispatcher()->drain();

		$pass = 0; $fail = 0;
		$t = function ( $label, $got, $want ) use ( &$pass, &$fail ) {
			if ( mc_assert( $label, $got, $want ) ) { $pass++; } else { $fail++; }
		};

		mc67ps_author( array( array(
			'type'                 => 'related_post_terms_rules',
			'enabled'              => true,
			'acf_field_name'       => 'mc_section:mc_related_items',
			'holder_role'          => 'source',
			'taxonomy'             => 'mc_topic',
			'keep_in_sync'         => true,
			'post_status'          => array( 'publish' ),
		) ) );
		update_field( 'field_mc_related_items', array( $solo ), $holder );
		mc67ps_dispatcher()->drain();
		$t( '§67d gated OUT: draft SOURCE, rule wants publish — dependent untouched',
			mc_terms( $solo ), array() );

		wp_update_post( array( 'ID' => $holder, 'post_status' => 'publish' ) );
		mc67ps_dispatcher()->drain();
		$t( '§67d gated IN: source published — dependent now synced',
			mc_terms( $solo ), array( $coastal ) );

		WP_CLI::log( sprintf( '[sweep 67-post-status/source-gate] %d passed, %d failed', $pass, $fail ) );
		if ( $fail ) {
			WP_CLI::error( sprintf( '#67 post-status sweep step "source-gate" FAILED (%d assertions).', $fail ) );
		}
		break;

	// ----------------------------------------------------------- restore
	case 'restore':
		$solo   = mc_pid( 'item-solo-a' );
		$holder = mc_pid( 'section-holder' );

		mc67ps_author( array() );
		wp_update_post( array( 'ID' => $solo, 'post_status' => 'publish' ) );
		wp_update_post( array( 'ID' => $holder, 'post_status' => 'publish' ) );
		mc_reset_subject( $solo );
		mc_reset_subject( $holder );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'field_mc_topics_item', array(), $solo );
		}
		mc67ps_dispatcher()->drain();
		mc_restore( array( $solo, $holder ) );
		mc67ps_dispatcher()->drain();

		// mc_restore() rewrites RULES and resets named subjects' TERMS — it
		// does not touch POST FIELDS, and §67d emptied section-holder's
		// mc_related_items relationship. That is post data, not rule data,
		// so only a full seed.php repairs it (same as the §63 restore gotcha
		// in the matrix: this step, like that one, is not self-sufficient).
		require __DIR__ . '/seed.php';

		WP_CLI::log( '[sweep] #67 post-status: subjects reset, fixture rules + section-holder relationship repaired via seed.php' );
		break;

	default:
		WP_CLI::error( "Unknown step '$step' — standard|source-gate|restore." );
}
