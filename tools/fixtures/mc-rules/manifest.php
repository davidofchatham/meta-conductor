<?php
/**
 * mc-rules blueprint — manifest (data contract).
 *
 * Pure data. Consumers pin `version`. Composes on GBDTE core-structures
 * (`composes_on`) — never redefine its keys. Requirements provenance:
 * tools/fixtures/handler-fixture-matrix.md (row refs in comments).
 *
 * Value tokens resolved at seed time:
 *   {TODAY±N}  → date('Y-m-d') offset N days (time_based date windows)
 */

return array(
	'blueprint'   => 'mc-rules',
	'version'     => 5, // 5: related_post_terms reverse-field coverage — explicit reverse (mc_parent_section, tier 1) on the existing rule + a native-bidi pair (mc_bidi_items/mc_bidi_sections on section-bidi/item-bidi, mc_flag, tier 2) for the #43 dependent-end sever. 4: section-child independent term moved native→ACF field (both channels agree; propagation ACF-merge no longer clobbers it). 3: item-solo-a/b clobber-free subjects. 2: composes_on two-key shape. 1: initial.
	// Family-standard shape — matches layout-states and view-structures.
	// bin/seed-all.sh --only builds its dependency graph from this.
	'composes_on' => array(
		'blueprint'   => 'core-structures',
		'min_version' => 4,
	),

	'defines' => array(
		'post_types' => array( 'mc_item', 'mc_section' ),
		'taxonomies' => array( 'mc_topic', 'mc_flag' ),
		'acf_groups' => array( 'group_mc_fields', 'group_mc_section_fields' ),
	),

	// ── mc_topic tree (hierarchical, 4 levels) + mc_flag (flat) ──────────
	// 'parent' = fixture slug of parent term; roots omit it.
	'terms' => array(
		'topic-region'   => array( 'taxonomy' => 'mc_topic', 'name' => 'Region', 'slug' => 'region' ),
		'topic-east'     => array( 'taxonomy' => 'mc_topic', 'name' => 'East', 'slug' => 'east', 'parent' => 'topic-region' ),
		'topic-coastal'  => array( 'taxonomy' => 'mc_topic', 'name' => 'Coastal', 'slug' => 'coastal', 'parent' => 'topic-east' ),
		'topic-harbor'   => array( 'taxonomy' => 'mc_topic', 'name' => 'Harbor', 'slug' => 'harbor', 'parent' => 'topic-coastal' ),
		'topic-inland'   => array( 'taxonomy' => 'mc_topic', 'name' => 'Inland', 'slug' => 'inland', 'parent' => 'topic-east' ),
		'topic-west'     => array( 'taxonomy' => 'mc_topic', 'name' => 'West', 'slug' => 'west', 'parent' => 'topic-region' ),
		'topic-status'   => array( 'taxonomy' => 'mc_topic', 'name' => 'Status', 'slug' => 'status' ),
		'topic-featured' => array( 'taxonomy' => 'mc_topic', 'name' => 'Featured', 'slug' => 'featured', 'parent' => 'topic-status' ),
		'topic-archived' => array( 'taxonomy' => 'mc_topic', 'name' => 'Archived', 'slug' => 'archived', 'parent' => 'topic-status' ),
		'flag-priority'  => array( 'taxonomy' => 'mc_flag', 'name' => 'Priority', 'slug' => 'priority' ),
	),

	// ── Posts ────────────────────────────────────────────────────────────
	// mc_item  — flat CPT: hierarchical/level-restriction/related/time-based/
	//            title-slug scenario subjects.
	// mc_section — hierarchical CPT: propagation chains + relationship holder.
	// 'parent' = fixture slug (post_parent), resolved at seed.
	'posts' => array(
		// Clean-slate scenario subjects (no seeded terms — sweeps assign).
		// alpha/beta are referenced by section-holder's relationship field, so a
		// live related_post_terms rule PUSHES the holder's terms onto them on any
		// term edit (keep_in_sync source). Use them for related_post_terms and
		// interaction sweeps, NOT for isolated single-handler sweeps.
		'item-alpha' => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-alpha', 'post_title' => 'MC Item Alpha' ),
		'item-beta'  => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-beta', 'post_title' => 'MC Item Beta' ),

		// Push-free subjects: referenced by NO holder, carry NO seeded terms — so
		// the related_post_terms *push* (holder → referenced item) can't reach
		// them. This removes the one non-obvious, reference-based clobber.
		//
		// It does NOT fully isolate a single handler: related_rules,
		// level_restriction and time_based all also scope mc_item + mc_topic, so
		// they still fire on any mc_topic edit here. Verified: assigning Harbor to
		// solo-a with all rules live yields [Region,Coastal,Harbor,Featured] — the
		// hierarchical expansion, PLUS related adds Featured (Coastal⇒Featured),
		// then one_per_level prunes East (East and Featured are both L2). All
		// three handlers correctly composing.
		//
		// So: use solo-a/b to dodge the confusing holder push; still empty the
		// OTHER mc_item rule arrays when you need a pure single-handler read.
		// (Per-request handler dedup applies regardless — one user-edit per eval.)
		'item-solo-a' => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-solo-a', 'post_title' => 'MC Item Solo A' ),
		'item-solo-b' => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-solo-b', 'post_title' => 'MC Item Solo B' ),

		// time_based filter subjects: gamma matches filter (holds Coastal),
		// delta does not.
		'item-gamma' => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-gamma', 'post_title' => 'MC Item Gamma' ),
		'item-delta' => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-delta', 'post_title' => 'MC Item Delta' ),

		// title_slug collision pair (same tokens → escalation ladder).
		'item-slug-a' => array( 'post_type' => 'mc_item', 'post_name' => 'mc-slug-probe-a', 'post_title' => 'Slug Probe' ),
		'item-slug-b' => array( 'post_type' => 'mc_item', 'post_name' => 'mc-slug-probe-b', 'post_title' => 'Slug Probe' ),

		// Propagation chain: grand → parent → child (+ draft sibling).
		'section-grand'  => array( 'post_type' => 'mc_section', 'post_name' => 'mc-grand', 'post_title' => 'MC Section Grand' ),
		'section-parent' => array( 'post_type' => 'mc_section', 'post_name' => 'mc-parent', 'post_title' => 'MC Section Parent', 'parent' => 'section-grand' ),
		'section-child'  => array( 'post_type' => 'mc_section', 'post_name' => 'mc-child', 'post_title' => 'MC Section Child', 'parent' => 'section-parent' ),
		'section-draft'  => array( 'post_type' => 'mc_section', 'post_name' => 'mc-draft-child', 'post_title' => 'MC Section Draft Child', 'parent' => 'section-parent', 'post_status' => 'draft' ),

		// related_post_terms: holder (relationship field) + second holder
		// (multi-holder pull-union case).
		'section-holder'  => array( 'post_type' => 'mc_section', 'post_name' => 'mc-holder', 'post_title' => 'MC Holder' ),
		'section-holder2' => array( 'post_type' => 'mc_section', 'post_name' => 'mc-holder-two', 'post_title' => 'MC Holder Two' ),

		// related_post_terms tier-2 (ACF native bidirectional) pair. Deliberately
		// its OWN posts + its OWN taxonomy (mc_flag) so it can't perturb any
		// existing subject: an explicit reverse_acf_field_name short-circuits
		// tier 2, so tier 1 and tier 2 cannot share a rule. Known interaction:
		// related_rules §3's taxonomy trigger (any mc_flag ⇒ Featured) fires on
		// item-bidi once it inherits Priority — expected, and contained here.
		'section-bidi' => array( 'post_type' => 'mc_section', 'post_name' => 'mc-bidi-holder', 'post_title' => 'MC Bidi Holder' ),
		'item-bidi'    => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-bidi', 'post_title' => 'MC Item Bidi' ),
	),

	// ── Seeded term assignments (fixture slugs) ─────────────────────────
	'post_terms' => array(
		'item-gamma'      => array( 'topic-coastal' ),            // time_based filter match
		'section-holder'  => array( 'topic-coastal', 'topic-east' ), // push source set
		'section-bidi'    => array( 'flag-priority' ),              // tier-2 push source set
		// section-child's independent term is seeded via post_fields (ACF) so BOTH
		// the native store and the save_terms ACF mirror agree — see below.
	),

	// ── ACF field values (update_field at seed) ─────────────────────────
	'post_fields' => array(
		'item-alpha'     => array(
			'mc_event_date'     => '20300315',              // title_slug {meta:} token
			'mc_parent_section' => array( 'section-holder' ), // tier-1 reverse (see below)
		),
		'item-slug-a'    => array( 'mc_event_date' => '20300401' ),
		'item-slug-b'    => array( 'mc_event_date' => '20300401' ), // same date → slug collision
		// Relationship values: fixture slugs resolved to IDs at seed.
		'section-holder' => array( 'mc_related_items' => array( 'item-alpha', 'item-beta' ) ),
		// Explicit REVERSE side of the same link (tier 1). Must mirror
		// section-holder's forward field: once a rule pins
		// reverse_acf_field_name, source resolution reads THIS field instead of
		// scanning, so an unseeded reverse would make every dependent look
		// source-less. Clearing it on one item is the #43 dependent-end sever.
		'item-beta'      => array( 'mc_parent_section' => array( 'section-holder' ) ),
		// Tier-2 native-bidi pair. Only the holder side is seeded — ACF writes
		// item-bidi's mc_bidi_sections itself, which is the point of the case.
		'section-bidi'   => array( 'mc_bidi_items' => array( 'item-bidi' ) ),
		// section-child's independent term (removal-propagation must not strip it).
		// Seeded via the ACF taxonomy field (save_terms=1 syncs native too) so both
		// channels agree — a native-only seed would let propagation's ACF-merge
		// write clobber it. Taxonomy-field values use {TERM:slug} tokens.
		'section-child'  => array( 'mc_topics' => array( '{TERM:topic-west}' ) ),
	),

	// ── Rule baselines (merged into bws_meta_conductor_settings LAST) ───
	// Canonical UI-written shape; positional arrays; `id` never persisted.
	// Term/field refs use fixture slugs ({TERM:slug} resolved to term_id at
	// seed). EVERY rule pins post_types to mc_* (isolation invariant).
	//
	// `checkboxes` fields (post_types, filter_taxonomies) MUST be a flat list
	// of slugs — array('mc_item') — never the {slug:bool} map. Handlers read
	// both (ConfigHelpers::selected_post_type_slugs, should_process_post), but
	// Wireframe's REST validator only accepts the list: given a map it
	// validates the VALUES, so `true` arrives as "1" and the save 400s with
	// `"1" is not a valid option`. The map shape seeded here previously made
	// every fixture tab unsaveable from the settings page.
	'mc_rules' => array(

		// matrix §1 — apply ancestors of hand-picked terms, all levels.
		// `inheritance_behavior` replaced the hierarchy_direction +
		// expansion_behavior pair in 0.8.0 (#16). The handler still reads the
		// legacy pair when the outcome key is absent, but a fixture is a
		// statement about the CURRENT schema, so it seeds the new key.
		'hierarchical_rules' => array(
			array(
				'enabled'              => true,
				'taxonomy'             => 'mc_topic',
				'post_types'           => array( 'mc_item' ),
				'inheritance_behavior' => 'ancestors',
				'inheritance_depth'    => 'all',
			),
		),

		// matrix §2 — one_per_level, ancestors off.
		'hierarchical_level_restriction_rules' => array(
			array(
				'enabled'           => true,
				'taxonomy'          => 'mc_topic',
				'post_types'        => array( 'mc_item' ),
				'restriction_mode'  => 'one_per_level',
				'include_ancestors' => false,
			),
		),

		// matrix §3 — term trigger (Coastal ⇒ Featured, bidirectional) +
		// taxonomy trigger (any mc_flag ⇒ Featured).
		'related_rules' => array(
			array(
				'enabled'         => true,
				'post_types'      => array( 'mc_item' ),
				'trigger_type'    => 'term',
				'trigger_term_id' => array( '{TERM:topic-coastal}' ),
				'target_term_id'  => '{TERM:topic-featured}',
				'bidirectional'   => true,
			),
			array(
				'enabled'          => true,
				'post_types'       => array( 'mc_item' ),
				'trigger_type'     => 'taxonomy',
				'trigger_taxonomy' => 'mc_flag',
				'target_term_id'   => '{TERM:topic-featured}',
				'bidirectional'    => false,
			),
		),

		// matrix §4 — push+keep_in_sync via relationship field, in BOTH reverse-
		// resolution styles. Two rules, deliberately on different taxonomies and
		// different post pairs, because an explicit reverse_acf_field_name
		// short-circuits the native-bidi tier — one rule can only prove one tier.
		// (Pull/post_object variant deferred to a sweep-time rule edit —
		// one field family per baseline keeps seeded state predictable.)
		'related_post_terms_rules' => array(
			// Tier 1 — explicit reverse field. Deterministic; proves the
			// dependent-end sever branch itself (#43).
			array(
				'enabled'                => true,
				'acf_field_name'         => 'mc_section:mc_related_items',
				'reverse_acf_field_name' => 'mc_item:mc_parent_section',
				'holder_role'            => 'source',
				'taxonomy'               => 'mc_topic',
				'keep_in_sync'           => true,
			),
			// Tier 2 — ACF native bidirectional, no explicit reverse. The shape
			// that failed in production.
			array(
				'enabled'        => true,
				'acf_field_name' => 'mc_section:mc_bidi_items',
				'holder_role'    => 'source',
				'taxonomy'       => 'mc_flag',
				'keep_in_sync'   => true,
			),
		),

		// matrix §5 — merge propagation on mc_section chains.
		// post_types MUST be pinned (empty ⇒ all hierarchical incl. page).
		'propagation_rules' => array(
			array(
				'enabled'           => true,
				'taxonomy'          => 'mc_topic',
				'post_types'        => array( 'mc_section' ),
				'conflict_handling' => 'merge',
			),
		),

		// matrix §6 — in-range / expired / future windows around seed day.
		'time_based_rules' => array(
			array(
				'enabled'           => true,
				'post_types'        => array( 'mc_item' ),
				'start_date'        => '{TODAY-1}',
				'end_date'          => '{TODAY+7}',
				'target_term_id'    => '{TERM:topic-featured}',
				'filter_taxonomies' => array( 'mc_topic' ),
			),
			array(
				'enabled'        => true,
				'post_types'     => array( 'mc_item' ),
				'start_date'     => '{TODAY-30}',
				'end_date'       => '{TODAY-2}',
				'target_term_id' => '{TERM:topic-archived}',
			),
			array(
				'enabled'        => true,
				'post_types'     => array( 'mc_item' ),
				'start_date'     => '{TODAY+10}',
				'end_date'       => '{TODAY+20}',
				'target_term_id' => '{TERM:topic-archived}',
			),
		),

		// matrix §7 — one rule per post type (first-match-wins).
		'title_slug_rules' => array(
			array(
				'enabled'         => true,
				'name'            => 'MC item slug',
				'post_type'       => 'mc_item',
				'slug_pattern'    => '{default_slug}-{date_year:mc_event_date}',
				'slug_mode'       => 'replace',
				'date_escalation' => true,
				'date_field'      => 'mc_event_date',
			),
		),
	),
);
