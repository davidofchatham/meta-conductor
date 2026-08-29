<?php
/**
 * mc-rules — #63 behaviour sweep: related_post_terms as a per-rule applier.
 *
 * The last conversion, and the only one where the rule TYPE was previously one
 * slot in the order however many rows it held. Four things the static harness
 * (H13) cannot see:
 *
 *   §63a/b  TWO related_post_terms ROWS STRADDLING ANOTHER TYPE'S RULE compose
 *           by authored order. This is the headline: before #63 the type ran
 *           all its rules at once from its own hooks, so `A → other → C` and
 *           `C → A → other` were the same arrangement. They are now different
 *           results, and the middle rule sees whichever ACF-reference row ran
 *           before it.
 *   §63c    A SEVER still withdraws the gone source's terms — and does it from
 *           inside a full ordered pass, so the rule after it in the list runs
 *           against the withdrawn state rather than against a private write.
 *   §63d/e  A BARE `update_field()` sever (no post save, no ACF form) now
 *           reconciles. This was a documented KNOWN LIMIT before #63: the
 *           capture fired but nothing drained it, so the dependent kept a term
 *           whose source was gone until somebody re-saved the post. Split
 *           across two evals because the drain runs on `shutdown`.
 *   §63f    The PULL direction (holder_role=target) plus a tier-3 reverse
 *           lookup: editing the SOURCE re-syncs the holder, which is the
 *           declared fan-out doing what the old push write did.
 *   §63g    A sever licenses ONLY THE ROW WHOSE LINK WAS CUT to bypass the
 *           zero-source gate. The record is the one thing that lets a rule
 *           empty a post it resolves no source for, so a taxonomy-wide record
 *           would let an unrelated row wipe what an earlier row had just
 *           legitimately written — a destructive write with no evidence the
 *           rule manages the post at all (arch invariant #2). Three rows are
 *           needed to see it: one whose link is cut, one that writes, and one
 *           after it that resolves nothing and must therefore do nothing.
 *
 * WHY THE RULES ARE AUTHORED BY HAND. The seeded pair is two rows in two
 * DIFFERENT taxonomies (mc_topic tier-1, mc_flag tier-2), which is the right
 * shape for the sever matrix and the wrong one for an ORDER question — rows
 * that cannot contend cannot demonstrate precedence. So §63a/b author two rows
 * in ONE taxonomy over ONE dependent, fed by two different holders, with a
 * `related` rule between them.
 *
 * SUBJECT is `item-solo-a`, not `item-alpha`: alpha carries mc_event_date and
 * is renamed by the title_slug rule on any real save (README §7). solo-a is
 * referenced by no holder at seed, so this sweep ADDS it to both holders'
 * relationship fields and `restore` takes it back out.
 *
 * WHY EVERY SEVER NEEDS ITS OWN EVAL, SEPARATE FROM THE STAGING ONE. The
 * capture path reads the rule set through a REQUEST-LIFETIME memo
 * (`RelatedPostTermsHandler::enabled_rules()`), which is correct on a live site
 * — rules are only mutated by the admin REST save, a different request — and a
 * trap here: `mc63_stage()` writes relationship fields while the rules are
 * SILENCED, which populates that memo with the empty set, so a sever later in
 * the same eval is captured against no rules at all and silently does nothing.
 * The staging steps therefore only stage; the sever steps run in a fresh
 * request that rebuilds the memo from the authored rules.
 *
 * Usage (one step per eval — a capture and its drain must not share a request
 * where the point is that they don't):
 *   wp eval-file .../sweep-63-acf-reference.php order        # §63a
 *   wp eval-file .../sweep-63-acf-reference.php order-swap   # §63b
 *   wp eval-file .../sweep-63-acf-reference.php sever-a      # §63c (stage)
 *   wp eval-file .../sweep-63-acf-reference.php sever-b      # §63c (sever)
 *   wp eval-file .../sweep-63-acf-reference.php bare-a       # §63d (stage)
 *   wp eval-file .../sweep-63-acf-reference.php bare-b       # §63d (bare write)
 *   wp eval-file .../sweep-63-acf-reference.php bare-c       # §63e (assert)
 *   wp eval-file .../sweep-63-acf-reference.php pull         # §63f
 *   wp eval-file .../sweep-63-acf-reference.php cross-a      # §63g (stage)
 *   wp eval-file .../sweep-63-acf-reference.php cross-b      # §63g (sever)
 *   wp eval-file .../sweep-63-acf-reference.php restore
 *
 * `restore` puts both holders' relationship fields back to their manifest
 * values, clears solo-a's reverse fields and drops the authored list. Saves no
 * post, so the §7 rename trap does not apply.
 *
 * @package Meta_Conductor
 */

require_once __DIR__ . '/sweep-lib.php';

use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$step = $args[0] ?? 'order';

/** The live dispatcher, or bail loudly — an unregistered one means no passes. */
function mc63_dispatcher() {
	$d = TermDispatcher::instance();
	if ( ! $d ) {
		WP_CLI::error( 'No registered TermDispatcher — the plugin did not boot one.' );
	}
	return $d;
}

/**
 * Author an explicit ordered rule list.
 *
 * The list IS the storage shape since #66, so the order written here is the
 * order a pass executes in — which is what this sweep is asserting. (Before
 * that the type-keyed arrays had to be written alongside it or the order was
 * discarded and rebuilt.) (Same helper as #61/#62.)
 *
 * @param array[] $rows Rules in authored order, each carrying a `type` key.
 * @return int Rows written.
 */
function mc63_author( array $rows ) {
	return mc_write_ordered_rules( $rows, OptionRuleStorage::KIND_TERM );
}

/** Empty every rule array so a setup write provokes nothing. */
function mc63_silence() {
	mc63_author( array() );
	mc63_dispatcher()->drain();
}

/** Term slugs of a post's terms, sorted — readable assertions. */
function mc63_slugs( $post_id, $taxonomy = 'mc_topic' ) {
	$terms = wp_get_object_terms( (int) $post_id, $taxonomy );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$slugs = wp_list_pluck( $terms, 'slug' );
	sort( $slugs );
	return $slugs;
}

/** Row A — push from `mc-holder` over the TIER 1 explicit reverse field. */
function mc63_rule_a() {
	return array(
		'type'                   => 'related_post_terms_rules',
		'enabled'                => true,
		'acf_field_name'         => 'mc_section:mc_related_items',
		'reverse_acf_field_name' => 'mc_item:mc_parent_section',
		'holder_role'            => 'source',
		'taxonomy'               => 'mc_topic',
		'keep_in_sync'           => true,
	);
}

/** Row C — push from `mc-bidi-holder` over the TIER 2 native-bidi partner. */
function mc63_rule_c() {
	return array(
		'type'           => 'related_post_terms_rules',
		'enabled'        => true,
		'acf_field_name' => 'mc_section:mc_bidi_items',
		'holder_role'    => 'source',
		'taxonomy'       => 'mc_topic',
		'keep_in_sync'   => true,
	);
}

/**
 * Row S — the CONTROL row for §63g. Same taxonomy, same subject, same
 * keep-in-sync claim as the row whose link gets cut, but no sever of its own.
 *
 * A PULL row over the item's own `mc_parent_section` field: the subject IS the
 * holder, and its sources are whatever that field lists. Cutting that field
 * therefore leaves S with nothing to resolve — which is the point, because a
 * rule that resolves nothing must decline to write rather than empty the
 * taxonomy — while the capture does NOT record a sever for it: the pull branch
 * matches only a REVERSE field, and `mc_parent_section` has no reverse (it is
 * not an ACF bidirectional field, and no rule here names it as one).
 *
 * A row over the tier-2 bidi pair does NOT work as this control, which is worth
 * recording: ACF writes both sides of a bidirectional field, so clearing the
 * item's `mc_bidi_sections` also fires `acf/update_value` for the holder's
 * `mc_bidi_items` — and a pull row over one side has the other side as its
 * reverse field, so it is severed too. Correctly: they are two views of one
 * link.
 */
function mc63_rule_s() {
	return array(
		'type'           => 'related_post_terms_rules',
		'enabled'        => true,
		'acf_field_name' => 'mc_item:mc_parent_section',
		'holder_role'    => 'target',
		'taxonomy'       => 'mc_topic',
		'keep_in_sync'   => true,
	);
}

/**
 * Row B — the OTHER type's rule that the two ACF-reference rows straddle.
 *
 * Keyed on Coastal, which is row A's source term and NOT row C's, so which of
 * the two ran before it is legible in the result rather than merely inferred.
 */
function mc63_rule_b() {
	return array(
		'type'            => 'related_rules',
		'enabled'         => true,
		'post_types'      => array( 'mc_item' ),
		'trigger_type'    => 'term',
		'trigger_term_id' => array( mc_tid( 'topic-coastal' ) ),
		'target_term_id'  => mc_tid( 'topic-inland' ),
		'bidirectional'   => false,
	);
}

/**
 * Put the whole graph in a known state with NO rule live, then author $rows.
 *
 * Both holders reference the subject, the subject carries both reverse sides
 * (tier 1 explicitly, tier 2 written by ACF itself), and the two holders hold
 * DIFFERENT terms so a replace by either is visible.
 *
 * @param array[] $rows Rules to author afterwards, in order.
 * @return int[] [subject, tier-1 holder, tier-2 holder]
 */
function mc63_stage( array $rows ) {
	$subject = mc_pid( 'item-solo-a' );
	$holder  = mc_pid( 'section-holder' );
	$bidi    = mc_pid( 'section-bidi' );
	if ( ! $subject || ! $holder || ! $bidi ) {
		WP_CLI::error( 'Fixture posts missing — seed mc-rules first.' );
	}

	mc63_silence();

	mc_reset_subject( $subject );
	mc_reset_subject( $holder );
	mc_reset_subject( $bidi );

	// Relationships, written while nothing is live. Both holders keep their
	// seeded dependents and gain the subject, so nothing else in the fixture
	// loses a source.
	update_field( 'mc_related_items', array( mc_pid( 'item-alpha' ), mc_pid( 'item-beta' ), $subject ), $holder );
	update_field( 'mc_parent_section', array( $holder ), $subject );
	update_field( 'mc_bidi_items', array( mc_pid( 'item-bidi' ), $subject ), $bidi );

	// Distinct source term sets — Coastal from the tier-1 holder, West from the
	// tier-2 one. Row B keys on Coastal.
	wp_set_object_terms( $holder, array( mc_tid( 'topic-coastal' ) ), 'mc_topic' );
	wp_set_object_terms( $bidi, array( mc_tid( 'topic-west' ) ), 'mc_topic' );

	mc63_dispatcher()->drain();

	mc63_author( $rows );

	return array( $subject, $holder, $bidi );
}

/** Mark the subject dirty and drain — the pass, provoked as a caller. */
function mc63_pass( $subject ) {
	$d = mc63_dispatcher();
	$d->mark_dirty( $subject );
	$d->drain();
}

$ok   = 0;
$fail = 0;
$check = function ( $label, $got, $want ) use ( &$ok, &$fail ) {
	if ( mc_assert( $label, $got, $want ) ) {
		++$ok;
	} else {
		++$fail;
	}
};

switch ( $step ) {

	// ---- §63a: A → B → C. The LAST owning row wins; B saw A's write. -------
	case 'order':
		WP_CLI::log( '§63a — rows [acf-ref A, related B, acf-ref C]' );
		list( $subject ) = mc63_stage( array( mc63_rule_a(), mc63_rule_b(), mc63_rule_c() ) );
		mc63_pass( $subject );

		// A replaces with {coastal}; B sees coastal and adds inland; C replaces
		// with {west}, which is what an owning row ordered last means.
		$check( '§63a subject after [A,B,C]', mc63_slugs( $subject ), array( 'west' ) );
		break;

	// ---- §63b: C → A → B. Same three rows, different result. ---------------
	case 'order-swap':
		WP_CLI::log( '§63b — rows [acf-ref C, acf-ref A, related B]' );
		list( $subject ) = mc63_stage( array( mc63_rule_c(), mc63_rule_a(), mc63_rule_b() ) );
		mc63_pass( $subject );

		// C replaces with {west}; A replaces with {coastal}; B now DOES see
		// coastal and adds inland. Different from §63a in both halves — which
		// of the two ACF-reference rows won, and whether the middle rule fired.
		$check( '§63b subject after [C,A,B]', mc63_slugs( $subject ), array( 'coastal', 'inland' ) );
		break;

	// ---- §63c: sever, inside an ordered pass (arm) -------------------------
	case 'sever-a':
		WP_CLI::log( '§63c — stage the graph and the two rows (sever runs in sever-b)' );
		list( $subject ) = mc63_stage( array( mc63_rule_a(), mc63_rule_b() ) );
		mc63_pass( $subject );
		$check( '§63c baseline (A then B)', mc63_slugs( $subject ), array( 'coastal', 'inland' ) );
		break;

	// ---- §63c: the sever itself, in its own request ------------------------
	case 'sever-b':
		WP_CLI::log( '§63c — dependent-end sever with a consumer rule after it' );
		$subject = mc_pid( 'item-solo-a' );

		// The #43 end: the DEPENDENT drops its own source. acf/update_value
		// captures it; the save marks the post; the drain runs the pass.
		update_field( 'mc_parent_section', array(), $subject );
		update_field( 'mc_bidi_sections', array(), $subject );
		do_action( 'acf/save_post', $subject );
		wp_update_post( array( 'ID' => $subject ) );
		mc63_dispatcher()->drain();

		// A withdraws (no sources left, and it was severed, so the zero-source
		// skip does not apply). B then runs against the WITHDRAWN state and
		// finds no Coastal, so Inland is not re-added — which is the difference
		// between a pass and a private write.
		$check( '§63c subject after sever', mc63_slugs( $subject ), array() );
		break;

	// ---- §63d: bare update_field(), nothing else. Stage. -------------------
	case 'bare-a':
		WP_CLI::log( '§63d — stage for the bare-update_field sever' );
		list( $subject ) = mc63_stage( array( mc63_rule_a() ) );
		mc63_pass( $subject );
		$check( '§63d baseline', mc63_slugs( $subject ), array( 'coastal' ) );
		break;

	// ---- §63d: the bare write, in its own request. Arm only. ---------------
	case 'bare-b':
		WP_CLI::log( '§63d — bare update_field() sever, NO post save' );
		$subject = mc_pid( 'item-solo-a' );

		// No do_action('acf/save_post'), no wp_update_post, no explicit drain.
		// The capture fires on acf/update_value; AcfWriteQueue::flush marks the
		// post at shutdown p10 and the dispatcher drains at p20. Before #63
		// this combination was a documented dead end — the sever was recorded
		// under a post that never drained.
		update_field( 'mc_parent_section', array(), $subject );
		WP_CLI::log( '  (assert in a LATER request — run bare-c)' );
		break;

	// ---- §63e: the later request ------------------------------------------
	case 'bare-c':
		WP_CLI::log( '§63e — assert the shutdown drain applied the bare sever' );
		$subject = mc_pid( 'item-solo-a' );
		$check( '§63e subject after bare-update_field sever', mc63_slugs( $subject ), array() );
		$check( '§63e reverse field really is empty', (array) get_field( 'mc_parent_section', $subject ), array() );
		break;

	// ---- §63f: PULL direction + tier-3 reverse lookup ----------------------
	case 'pull':
		WP_CLI::log( '§63f — holder_role=target (pull), tier-3 reverse lookup' );
		$pull = array(
			'type'           => 'related_post_terms_rules',
			'enabled'        => true,
			// The holder is the ITEM here, and the field it owns is the one
			// that used to be row A's reverse. No reverse_acf_field_name and no
			// native bidi on it ⇒ resolve_reverse falls to tier 3, which is the
			// path the fan-out needs to find the holder from the source.
			'acf_field_name' => 'mc_item:mc_parent_section',
			'holder_role'    => 'target',
			'taxonomy'       => 'mc_topic',
			'keep_in_sync'   => true,
		);
		list( $subject, $holder ) = mc63_stage( array( $pull ) );
		mc63_pass( $subject );
		$check( '§63f pull onto the holder', mc63_slugs( $subject ), array( 'coastal' ) );

		// Now edit the SOURCE. Nothing marks the subject dirty — it is reached
		// only by the rule DECLARING it through fan_out(), which for a pull rule
		// is the tier-3 reverse lookup.
		wp_set_object_terms( $holder, array( mc_tid( 'topic-west' ) ), 'mc_topic' );
		mc63_dispatcher()->drain();

		$check( '§63f fan-out re-synced the holder', mc63_slugs( $subject ), array( 'west' ) );
		break;

	// ---- §63g: a sever is the CUT ROW's licence, nobody else's (stage) -----
	case 'cross-a':
		WP_CLI::log( '§63g — stage [A, C, S]: tier-1 push, bidi push, and a pull control row' );
		list( $subject ) = mc63_stage( array( mc63_rule_a(), mc63_rule_c(), mc63_rule_s() ) );
		mc63_pass( $subject );

		// A replaces with {coastal}; C replaces with {west}; S pulls the tier-1
		// holder back in and replaces with {coastal}. Order alone decides it.
		$check( '§63g baseline [A,C,S]', mc63_slugs( $subject ), array( 'coastal' ) );
		break;

	// ---- §63g: cut A's link only. C must write, S must stay out. ----------
	case 'cross-b':
		WP_CLI::log( '§63g — cut the tier-1 link (row A only) and re-pass' );
		$subject = mc_pid( 'item-solo-a' );

		// Dependent-end sever of the TIER 1 pair. It leaves BOTH A and S
		// resolving nothing — A because its explicit reverse field is this one,
		// S because this is its forward field — but only A is RECORDED as
		// severed. S's forward field is what is being written, and a forward
		// field is nobody's reverse here.
		update_field( 'mc_parent_section', array(), $subject );
		do_action( 'acf/save_post', $subject );
		wp_update_post( array( 'ID' => $subject ) );
		mc63_dispatcher()->drain();

		// A: no sources AND severed ⇒ empties. C: bidi link intact ⇒ {west}.
		// S: no sources and NOT severed ⇒ declines to write at all. A
		// taxonomy-keyed sever record would make S take the orphan branch here
		// and empty-replace C's write, leaving [].
		$check( '§63g only the cut row bypassed its zero-source gate', mc63_slugs( $subject ), array( 'west' ) );
		break;

	case 'restore':
		$subject = mc_pid( 'item-solo-a' );
		$holder  = mc_pid( 'section-holder' );
		$bidi    = mc_pid( 'section-bidi' );

		// Rules first, then the graph, then the subjects — the same order the
		// #61/#62 restores use: clearing terms while rules are live lets a
		// handler repopulate them.
		mc63_silence();

		update_field( 'mc_related_items', array( mc_pid( 'item-alpha' ), mc_pid( 'item-beta' ) ), $holder );
		update_field( 'mc_bidi_items', array( mc_pid( 'item-bidi' ) ), $bidi );
		update_field( 'mc_parent_section', array( $holder ), mc_pid( 'item-alpha' ) );
		update_field( 'mc_parent_section', array(), $subject );

		mc_reset_subject( $subject );
		mc_reset_subject( $bidi );
		wp_set_object_terms( $holder, array( mc_tid( 'topic-coastal' ), mc_tid( 'topic-east' ) ), 'mc_topic' );
		wp_set_object_terms( $bidi, array( mc_tid( 'flag-priority' ) ), 'mc_flag' );
		mc63_dispatcher()->drain();

		// mc_restore() rewrites the kind lists from the manifest, in the
		// documented KIND_TYPES order — which since #66 IS the stored shape, so
		// the sweep's hand-authored order is gone with it. (It used to need an
		// extra unset of the authored list to force that rebuild.)
		mc_restore();

		WP_CLI::log( '[restore] rules rebuilt from manifest, relationship fields + holder terms reset.' );
		break;

	default:
		WP_CLI::error( "Unknown step '{$step}' — use order | order-swap | sever-a | sever-b | bare-a | bare-b | bare-c | pull | cross-a | cross-b | restore." );
}

if ( 'restore' !== $step && 'bare-b' !== $step ) {
	if ( $fail ) {
		WP_CLI::error( sprintf( '%s: %d passed, %d FAILED', $step, $ok, $fail ) );
	}
	WP_CLI::success( sprintf( '%s: %d assertions passed', $step, $ok ) );
}
