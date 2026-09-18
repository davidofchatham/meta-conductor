<?php
/**
 * mc-rules blueprint — schema.
 *
 * CPT + taxonomy registration and ACF groups for the MC fixture surface.
 * Loaded two ways (same pattern as core-structures):
 *  - at runtime by the mu-plugin loader stub seed.php installs
 *  - directly by seed.php during seeding
 *
 * Composes on core-structures: registers ONLY mc_* keys, touches nothing
 * from the base blueprint. Data lives in manifest.php.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) ) {
	exit;
}

/**
 * CPTs + taxonomies.
 *
 * mc_item    — flat scenario subject (hierarchical/level-restriction/related/
 *              time-based/title-slug rules).
 * mc_section — hierarchical (propagation post_parent chains; relationship holder).
 * mc_topic   — hierarchical taxonomy, 4-level tree (see manifest).
 * mc_flag    — flat taxonomy (related_rules taxonomy-trigger fixture).
 */
function bws_fixture_mc_rules_register_types() {
	register_post_type(
		'mc_item',
		array(
			'label'        => 'MC Items',
			'public'       => true,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'custom-fields' ),
		)
	);

	register_post_type(
		'mc_section',
		array(
			'label'        => 'MC Sections',
			'public'       => true,
			'show_in_rest' => true,
			'hierarchical' => true, // propagation requires hierarchical post type
			'supports'     => array( 'title', 'editor', 'custom-fields', 'page-attributes' ),
		)
	);

	register_taxonomy(
		'mc_topic',
		array( 'mc_item', 'mc_section', 'staff' ), // staff = future shared-reuse variant (matrix §3/§4, deferred)
		array(
			'label'        => 'MC Topics',
			'public'       => true,
			'show_in_rest' => true,
			'hierarchical' => true,
		)
	);

	register_taxonomy(
		'mc_flag',
		array( 'mc_item' ),
		array(
			'label'        => 'MC Flags',
			'public'       => true,
			'show_in_rest' => true,
			'hierarchical' => false,
		)
	);
}

/**
 * ACF groups (local field groups, group_mc_* keys only).
 *
 * group_mc_fields (mc_item):
 *   mc_topics     — taxonomy field, mc_topic, multi (checkbox), save_terms ON
 *                   (level-restriction + related ACF branches discover by
 *                   type==taxonomy && taxonomy==mc_topic).
 *   mc_event_date — date_picker Ymd (title_slug {meta:}/{date_*:} tokens +
 *                   escalation date_field).
 *
 * group_mc_section_fields (mc_section):
 *   mc_related_items — relationship → mc_item (related_post_terms holder field).
 *   mc_primary_item  — post_object → mc_item (pull/post_object variant, sweep-time).
 *   mc_topics        — taxonomy field, mc_topic (propagation ACF write path).
 */
function bws_fixture_mc_rules_register_acf() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group(
		array(
			'key'      => 'group_mc_fields',
			'title'    => 'MC Item Fields',
			'location' => array(
				array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'mc_item' ) ),
			),
			'fields'   => array(
				array(
					'key'           => 'field_mc_topics_item',
					'name'          => 'mc_topics',
					'label'         => 'MC Topics',
					'type'          => 'taxonomy',
					'taxonomy'      => 'mc_topic',
					'field_type'    => 'checkbox',
					'save_terms'    => 1,
					'load_terms'    => 0,
					'return_format' => 'id',
				),
				array(
					'key'            => 'field_mc_event_date',
					'name'           => 'mc_event_date',
					'label'          => 'MC Event Date',
					'type'           => 'date_picker',
					'return_format'  => 'Ymd',
					'display_format' => 'Y-m-d',
				),
				// REVERSE field, tier 1 (explicit). The item end of
				// mc_section:mc_related_items — the rule pins it via
				// `reverse_acf_field_name`, so reverse resolution never
				// reaches the tier-3 meta_query scan. This is the field an
				// editor clears to say "this item is no longer in that
				// section", i.e. the dependent-end sever case (#43).
				// NOT ACF-bidirectional: the link is kept consistent by the
				// fixture/sweep, so the branch under test is the plugin's own
				// explicit-reverse path, deterministically.
				array(
					'key'           => 'field_mc_parent_section',
					'name'          => 'mc_parent_section',
					'label'         => 'Parent MC Section',
					'type'          => 'relationship',
					'post_type'     => array( 'mc_section' ),
					'return_format' => 'id',
				),
				// REVERSE field, tier 2 (ACF NATIVE bidirectional). Partner of
				// field_mc_bidi_items on mc_section. ACF itself writes the
				// other side on save, so this exercises the shape that actually
				// failed in production — the one whose hook-firing the #43
				// report questions. Kept on its own field pair + its own rule
				// (mc_flag, not mc_topic) so tier 1 and tier 2 never interact:
				// an explicit reverse_acf_field_name SHORT-CIRCUITS tier 2, so
				// one rule can only prove one tier.
				array(
					'key'                  => 'field_mc_bidi_sections',
					'name'                 => 'mc_bidi_sections',
					'label'                => 'Bidi MC Sections',
					'type'                 => 'relationship',
					'post_type'            => array( 'mc_section' ),
					'return_format'        => 'id',
					'bidirectional'        => 1,
					'bidirectional_target' => array( 'field_mc_bidi_items' ),
				),
			),
		)
	);

	acf_add_local_field_group(
		array(
			'key'      => 'group_mc_section_fields',
			'title'    => 'MC Section Fields',
			'location' => array(
				array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'mc_section' ) ),
			),
			'fields'   => array(
				array(
					'key'           => 'field_mc_related_items',
					'name'          => 'mc_related_items',
					'label'         => 'Related MC Items',
					'type'          => 'relationship',
					'post_type'     => array( 'mc_item' ),
					'return_format' => 'id',
				),
				array(
					'key'           => 'field_mc_primary_item',
					'name'          => 'mc_primary_item',
					'label'         => 'Primary MC Item',
					'type'          => 'post_object',
					'post_type'     => array( 'mc_item' ),
					'return_format' => 'id',
					'allow_null'    => 1,
				),
				// Holder side of the tier-2 native-bidi pair. Partner:
				// field_mc_bidi_sections on mc_item.
				array(
					'key'                  => 'field_mc_bidi_items',
					'name'                 => 'mc_bidi_items',
					'label'                => 'Bidi MC Items',
					'type'                 => 'relationship',
					'post_type'            => array( 'mc_item' ),
					'return_format'        => 'id',
					'bidirectional'        => 1,
					'bidirectional_target' => array( 'field_mc_bidi_sections' ),
				),
				array(
					'key'           => 'field_mc_topics_section',
					'name'          => 'mc_topics',
					'label'         => 'MC Topics',
					'type'          => 'taxonomy',
					'taxonomy'      => 'mc_topic',
					'field_type'    => 'checkbox',
					'save_terms'    => 1,
					'load_terms'    => 0,
					'return_format' => 'id',
				),
			),
		)
	);

	// DECOY group (#25). A SECOND relationship field named `mc_related_items`,
	// on the SAME post type as the real one, in a different group, pointing at
	// a DIFFERENT target post type. This is the shape found on a real site: a
	// bare-name `acf_get_field('mc_related_items')` returns ONE arbitrary match,
	// so post-type qualification cannot separate the two — only the field key
	// can. Registered LAST so the bare-name lookup resolves to the decoy, which
	// makes the pre-fix failure deterministic rather than order-dependent.
	//
	// Never populated by the fixture: its only job is to exist and collide.
	acf_add_local_field_group(
		array(
			'key'      => 'group_mc_decoy_fields',
			'title'    => 'MC Decoy Fields',
			'location' => array(
				array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'mc_section' ) ),
			),
			'fields'   => array(
				array(
					'key'           => 'field_mc_decoy_related_items',
					'name'          => 'mc_related_items',
					'label'         => 'Related MC Items (decoy)',
					'type'          => 'relationship',
					'post_type'     => array( 'mc_section' ),
					'return_format' => 'id',
				),
			),
		)
	);
}

// Runtime registration (mu-plugin stub path). During seeding the applier calls
// these directly — init/acf/init have already fired in the CLI run.
if ( function_exists( 'add_action' ) && ! defined( 'BWS_FIXTURE_SEEDING' ) ) {
	add_action( 'init', 'bws_fixture_mc_rules_register_types', 5 );
	add_action( 'acf/init', 'bws_fixture_mc_rules_register_acf', 5 );
}
