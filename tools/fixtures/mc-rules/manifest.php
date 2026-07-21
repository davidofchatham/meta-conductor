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
	'version'     => 2, // 2: composes_on normalized to the family two-key shape (blueprint/min_version) so the seed orchestrator can parse the dep graph. 1: initial.
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
		'item-alpha' => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-alpha', 'post_title' => 'MC Item Alpha' ),
		'item-beta'  => array( 'post_type' => 'mc_item', 'post_name' => 'mc-item-beta', 'post_title' => 'MC Item Beta' ),

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
	),

	// ── Seeded term assignments (fixture slugs) ─────────────────────────
	'post_terms' => array(
		'item-gamma'      => array( 'topic-coastal' ),            // time_based filter match
		'section-child'   => array( 'topic-west' ),               // independent term — removal propagation must not strip
		'section-holder'  => array( 'topic-coastal', 'topic-east' ), // push source set
	),

	// ── ACF field values (update_field at seed) ─────────────────────────
	'post_fields' => array(
		'item-alpha'     => array( 'mc_event_date' => '20300315' ), // title_slug {meta:} token
		'item-slug-a'    => array( 'mc_event_date' => '20300401' ),
		'item-slug-b'    => array( 'mc_event_date' => '20300401' ), // same date → slug collision
		// Relationship values: fixture slugs resolved to IDs at seed.
		'section-holder' => array( 'mc_related_items' => array( 'item-alpha', 'item-beta' ) ),
	),

	// ── Rule baselines (merged into bws_meta_conductor_settings LAST) ───
	// Canonical UI-written shape; positional arrays; `id` never persisted.
	// Term/field refs use fixture slugs ({TERM:slug} resolved to term_id at
	// seed). EVERY rule pins post_types to mc_* (isolation invariant).
	'mc_rules' => array(

		// matrix §1 — expand child→parent, all ancestors, smart.
		'hierarchical_rules' => array(
			array(
				'enabled'            => true,
				'taxonomy'           => 'mc_topic',
				'post_types'         => array( 'mc_item' => true ),
				'hierarchy_direction' => 'child_to_parent',
				'inheritance_depth'  => 'all',
				'expansion_behavior' => 'smart',
			),
		),

		// matrix §2 — one_per_level, ancestors off.
		'hierarchical_level_restriction_rules' => array(
			array(
				'enabled'           => true,
				'taxonomy'          => 'mc_topic',
				'post_types'        => array( 'mc_item' => true ),
				'restriction_mode'  => 'one_per_level',
				'include_ancestors' => false,
			),
		),

		// matrix §3 — term trigger (Coastal ⇒ Featured, bidirectional) +
		// taxonomy trigger (any mc_flag ⇒ Featured).
		'related_rules' => array(
			array(
				'enabled'         => true,
				'post_types'      => array( 'mc_item' => true ),
				'trigger_type'    => 'term',
				'trigger_term_id' => array( '{TERM:topic-coastal}' ),
				'target_term_id'  => '{TERM:topic-featured}',
				'bidirectional'   => true,
			),
			array(
				'enabled'          => true,
				'post_types'       => array( 'mc_item' => true ),
				'trigger_type'     => 'taxonomy',
				'trigger_taxonomy' => 'mc_flag',
				'target_term_id'   => '{TERM:topic-featured}',
				'bidirectional'    => false,
			),
		),

		// matrix §4 — push+keep_in_sync via relationship field.
		// (Pull/post_object variant deferred to a sweep-time rule edit —
		// one field family per baseline keeps seeded state predictable.)
		'related_post_terms_rules' => array(
			array(
				'enabled'        => true,
				'acf_field_name' => 'mc_section:mc_related_items',
				'holder_role'    => 'source',
				'taxonomy'       => 'mc_topic',
				'keep_in_sync'   => true,
			),
		),

		// matrix §5 — merge propagation on mc_section chains.
		// post_types MUST be pinned (empty ⇒ all hierarchical incl. page).
		'propagation_rules' => array(
			array(
				'enabled'           => true,
				'taxonomy'          => 'mc_topic',
				'post_types'        => array( 'mc_section' => true ),
				'conflict_handling' => 'merge',
			),
		),

		// matrix §6 — in-range / expired / future windows around seed day.
		'time_based_rules' => array(
			array(
				'enabled'           => true,
				'post_types'        => array( 'mc_item' => true ),
				'start_date'        => '{TODAY-1}',
				'end_date'          => '{TODAY+7}',
				'target_term_id'    => '{TERM:topic-featured}',
				'filter_taxonomies' => array( 'mc_topic' => true ),
			),
			array(
				'enabled'        => true,
				'post_types'     => array( 'mc_item' => true ),
				'start_date'     => '{TODAY-30}',
				'end_date'       => '{TODAY-2}',
				'target_term_id' => '{TERM:topic-archived}',
			),
			array(
				'enabled'        => true,
				'post_types'     => array( 'mc_item' => true ),
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
