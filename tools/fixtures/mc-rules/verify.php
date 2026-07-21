<?php
/**
 * mc-rules blueprint — post-seed smoke + negative controls (SKELETON).
 *
 * `wp eval-file`-able. NOT a behavior-sweep replacement — asserts the seeded
 * surface exists and the base blueprint is untouched. Behavior sweeps
 * (handler-fixture-matrix.md scenarios) come later as separate eval scripts.
 *
 * Planned assertions:
 *
 * A. Seeded surface
 *  1. post_type_exists('mc_item'), 'mc_section'; taxonomy_exists('mc_topic'),
 *     'mc_flag'; mc_topic is_taxonomy_hierarchical.
 *  2. Term tree shape: harbor's ancestor chain === [coastal, east, region].
 *  3. All manifest posts resolve by slug+type; section chain post_parent
 *     links correct; section-draft status draft.
 *  4. ACF: get_field('mc_event_date', item-alpha) === '20300315';
 *     get_field('mc_related_items', section-holder) === [alpha, beta] IDs.
 *  5. Rules present: StorageFactory::get_instance()->get_rules($type) count
 *     per manifest for all 7 types; every rule enabled; every rule's
 *     post_types ⊆ {mc_item, mc_section} (isolation invariant re-checked
 *     through the real read path incl. normalize_rule_shape).
 *  6. Cron event bws_taxonomy_manager_cleanup scheduled.
 *
 * B. Negative controls (base blueprint untouched — run after seed AND after
 *    every behavior sweep, pre-restore, to catch isolation breaks)
 *  1. department terms on page-matrix-post-meta / -terms-valid / -terms-mixed
 *     / -terms-junk === core-structures manifest post_terms.
 *  2. staff jane-partner / tom-associate: department terms unchanged.
 *  3. Matrix page post_name values unchanged (title_slug isolation).
 *  4. bws_dynamic_tags_settings deep-equals its pre-sweep snapshot.
 *
 * Exit non-zero on any failure (WP_CLI::error collects; summary at end).
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

WP_CLI::warning( 'mc-rules verify: skeleton only — implement assertions above.' );
