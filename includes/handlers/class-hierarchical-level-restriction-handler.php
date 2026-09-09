<?php
/**
 * BWS Taxonomy Manager Hierarchical Level Restriction Handler
 * Restricts taxonomy terms to one per hierarchical level, removing siblings when new terms are applied
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class HierarchicalLevelRestrictionHandler extends UnifiedHandlerBase {

    /**
     * Cache for term level calculations
     */
    private $term_level_cache = array();

    public function get_handler_type(): string {
        return 'hierarchical_level_restriction';
    }

    protected function get_rule_type(): string {
        return 'hierarchical_level_restriction_rules';
    }

    /**
     * Pure applier: no hooks (#60).
     *
     * Two registrations lived here until 0.8.0 — `set_object_terms` p5 and
     * `acf/save_post` p15 — and the p5 was itself an ordering device, chosen to
     * land before the hierarchical handler's p10. That is the ordering the
     * author now expresses in the rule list, so encoding it in a priority as
     * well would make it unchangeable. `TermDispatcher` owns the trigger union.
     */
    protected function init_hooks() {}

    // Not the applier — apply_to_post() is. The base process_post routes
    // through RuleEngine, which this handler does not use.
    public function process_post($post_id, $post, $update) {}

    /**
     * Apply ONE level-restriction rule to ONE post. The whole of what both
     * hooks used to do.
     *
     * ORDER MATTERS INSIDE HERE. The ACF pass runs first and touches only the
     * FIELD; the native pass then restricts the post's live terms, which by
     * then include anything ACF's own sync wrote off the back of that field.
     * Reversing them would restrict the native side against a pre-ACF set.
     * Neither half replaces the taxonomy wholesale — see
     * process_acf_level_restrictions() for why that mattered enough to change.
     *
     * Recomputes from live state (`apply_level_restrictions` reads the post's
     * current terms), which is what makes it correct to run whether or not a
     * term write provoked this pass. The old `set_object_terms` path narrowed
     * on the ADDED terms and returned early when nothing was added — right for
     * a hook, wrong for a pass: an earlier rule's write is exactly the case
     * this must catch, and it arrives with no "added" delta of its own.
     *
     * No re-entrancy boolean: the deleted `private $processing` guarded the
     * handler against its own `wp_set_object_terms`, which the pass lock now
     * absorbs (#60, ADR 0003 decision 4).
     *
     * @param int   $post_id Post to apply to.
     * @param array $rule    One enabled level-restriction rule.
     * @return bool Whether the post's terms actually changed.
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        if (!$this->should_process_post($post_id, $rule)) {
            return false;
        }
        $taxonomy = $rule['taxonomy'] ?? '';
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return false;
        }
        if (!get_post($post_id)) {
            return false;
        }

        $before = $this->terms_fingerprint($post_id, $taxonomy);

        $this->process_acf_level_restrictions($post_id, $taxonomy, $rule);
        $this->apply_level_restrictions($post_id, $taxonomy, $rule);

        return $this->terms_fingerprint($post_id, $taxonomy) !== $before;
    }

    /**
     * Restrict the post's ACF taxonomy FIELDS in one taxonomy.
     *
     * ### It no longer writes native terms (#60)
     *
     * It used to finish with `wp_set_object_terms($post_id, $restricted_terms,
     * $taxonomy)` — a REPLACE of the whole taxonomy with whatever survived
     * restricting the ACF field's value. That was defensible while this only
     * ran from `acf/save_post`, where the field WAS the authoritative source
     * for the save in hand. Inside an ordered pass it is not: this method now
     * runs on every pass, so that write would replace terms an earlier rule
     * had just added — destroying them rather than pruning them, and doing it
     * on a rule whose claim is *restricting*, which prunes what violates a
     * constraint and nothing else.
     *
     * The native side is not lost, because `apply_level_restrictions()` runs
     * straight after this and restricts the post's LIVE terms under the same
     * rule. A term the field dropped either syncs natively through ACF, or is
     * pruned there on its own merits, or is a term this rule has no complaint
     * about — and in the last case the old write removed it anyway.
     *
     * @param int    $post_id  Post to restrict.
     * @param string $taxonomy Taxonomy slug.
     * @param array  $rule     Rule configuration.
     */
    private function process_acf_level_restrictions($post_id, $taxonomy, $rule) {
        // Value-independent discovery (0.6.x ACF B-sweep, #41): resolve fields
        // from field-group LOCATION rules, not stored meta, so an attached-but-
        // empty ACF taxonomy field is still found. Read by name, write by key.
        $fields = $this->get_acf_taxonomy_fields($post_id, $taxonomy);

        foreach ($fields as $field) {
            $current_terms = $this->get_acf_taxonomy_value($post_id, $field['name'], $taxonomy);

            if (empty($current_terms)) {
                continue;
            }

            // Apply level restrictions
            $restricted_terms = $this->calculate_restricted_terms($current_terms, $taxonomy, $rule);

            if ($restricted_terms !== $current_terms) {
                // Update ACF field — write by KEY so a first-write registers the
                // ACF reference row (see set_acf_taxonomy_value docblock).
                $this->set_acf_taxonomy_value($post_id, $field['key'], $restricted_terms);

                $this->debug_log(
                    sprintf('Applied ACF level restrictions to post %d for taxonomy %s', $post_id, $taxonomy),
                    array('field' => $field['name'], 'restricted_terms' => $restricted_terms)
                );
            }
        }
    }
    
    /**
     * Calculate restricted terms based on hierarchical levels.
     *
     * Two steps, in this order: the mode decides which terms survive, then
     * `include_ancestors` — if set — adds back the parent chain of whatever
     * survived.
     *
     * ### `include_ancestors` has ONE meaning as of 0.8.0 (#32)
     *
     * It used to branch on the mode and mean two different things:
     * additive in `deepest_only`, and in `one_per_level` a suppression of the
     * `remove_conflicting_ancestors()` pass. That second meaning was a no-op
     * in every case — `one_per_level` has already reduced the set to at most
     * one term per level by the time the pass runs, and the pass only dropped
     * a term whose ancestor sat on a level holding MORE than one term, which
     * by then can never be true. So the flag did nothing at all in that mode
     * while the UI claimed otherwise.
     *
     * Redefined to the additive meaning uniformly, which is the one that was
     * real, and applied in all three modes. `shallowest_only` therefore gains
     * behaviour it did not have: asking to keep ancestors of terms selected
     * for being shallowest does pull in still-shallower terms, and that is
     * what the author asked for.
     *
     * The change was free rather than breaking: level restriction runs on the
     * test site only (CLAUDE.md live-rule-type rule), so no stored rule
     * needed migrating. Config side: TermRulesConfig::level_restriction_subfields().
     *
     * @param int[]  $term_ids Term IDs currently on the post.
     * @param string $taxonomy Taxonomy slug.
     * @param array  $rule     Rule configuration.
     * @return int[] Terms that survive the restriction.
     */
    private function calculate_restricted_terms($term_ids, $taxonomy, $rule) {
        if (empty($term_ids)) {
            return $term_ids;
        }

        $restriction_mode = $rule['restriction_mode'] ?? 'one_per_level';
        $include_ancestors = !empty($rule['include_ancestors']);

        // Group terms by their hierarchical level
        $terms_by_level = $this->group_terms_by_level($term_ids, $taxonomy);

        $final_terms = array();

        if ($restriction_mode === 'one_per_level') {
            // Keep only one term per level (prefer the last one added/most specific)
            foreach ($terms_by_level as $level => $level_terms) {
                // Sort by term order or keep the last one
                $final_terms[] = end($level_terms);
            }
        } elseif ($restriction_mode === 'deepest_only') {
            // Keep only terms from the deepest level
            $max_level = max(array_keys($terms_by_level));
            $final_terms = $terms_by_level[$max_level];
        } elseif ($restriction_mode === 'shallowest_only') {
            // Keep only terms from the shallowest level
            $min_level = min(array_keys($terms_by_level));
            $final_terms = $terms_by_level[$min_level];
        }

        // One meaning, every mode: keep the lineage of whatever survived.
        // Iterate a snapshot — the ancestors being added are not themselves
        // walked again (get_ancestors already returns the full chain).
        if ($include_ancestors) {
            foreach (array_values($final_terms) as $term_id) {
                $final_terms = array_merge($final_terms, get_ancestors($term_id, $taxonomy));
            }
        }

        return array_values(array_unique($final_terms));
    }
    
    /**
     * Group terms by their hierarchical level
     */
    private function group_terms_by_level($term_ids, $taxonomy) {
        $terms_by_level = array();
        
        foreach ($term_ids as $term_id) {
            $level = $this->get_term_level($term_id, $taxonomy);
            
            if (!isset($terms_by_level[$level])) {
                $terms_by_level[$level] = array();
            }
            
            $terms_by_level[$level][] = $term_id;
        }
        
        return $terms_by_level;
    }
    
    /**
     * Get the hierarchical level of a term (0 = root level)
     */
    private function get_term_level($term_id, $taxonomy) {
        $cache_key = $taxonomy . '_' . $term_id;
        
        if (isset($this->term_level_cache[$cache_key])) {
            return $this->term_level_cache[$cache_key];
        }
        
        $level = 0;
        $current_term = get_term($term_id, $taxonomy);
        
        while ($current_term && !is_wp_error($current_term) && $current_term->parent > 0) {
            $level++;
            $current_term = get_term($current_term->parent, $taxonomy);
            
            // Prevent infinite loops
            if ($level > 20) {
                break;
            }
        }
        
        $this->term_level_cache[$cache_key] = $level;
        
        return $level;
    }
    
    // remove_conflicting_ancestors() lived here until 0.8.0. It ran only on
    // the `one_per_level` + !include_ancestors path and could not remove
    // anything on it: the mode had already reduced the set to one term per
    // level, and the method only dropped a term whose ancestor sat on a level
    // holding more than one. Deleted with the flag's second meaning (#32) —
    // see calculate_restricted_terms().

    // convert_tt_ids_to_term_ids() lived here until 0.8.0. Its only caller was
    // process_term_level_restrictions(), which existed to turn the
    // `set_object_terms` hook's term_taxonomy_ids into term IDs and diff them
    // against the old set. A pass carries no such delta — it recomputes from
    // live state — so both went with the hook. (#60)

    /**
     * Restrict the post's NATIVE terms in one taxonomy to what the rule allows.
     *
     * Reads the post's current terms rather than any delta, which is what makes
     * it correct at any point in a pass, including after another rule wrote.
     */
    private function apply_level_restrictions($post_id, $taxonomy, $rule) {
        $current_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        
        if (is_wp_error($current_terms) || empty($current_terms)) {
            return;
        }
        
        $restricted_terms = $this->calculate_restricted_terms($current_terms, $taxonomy, $rule);
        
        if ($restricted_terms !== $current_terms) {
            wp_set_object_terms($post_id, $restricted_terms, $taxonomy);
            
            $this->debug_log(
                sprintf('Applied level restrictions to existing post %d', $post_id),
                array(
                    'original_terms' => $current_terms,
                    'restricted_terms' => $restricted_terms
                )
            );
        }
    }
    
    /**
     * Get rules summary for admin display
     */
    public function get_rules_summary() {
        $enabled_rules = $this->get_enabled_rules();
        
        $summary = array(
            'total_rules' => count($enabled_rules),
            'taxonomies' => array(),
            'restriction_modes' => array()
        );
        
        foreach ($enabled_rules as $rule) {
            $summary['taxonomies'][] = $rule['taxonomy'];
            $summary['restriction_modes'][] = $rule['restriction_mode'] ?? 'one_per_level';
        }
        
        $summary['taxonomies'] = array_unique($summary['taxonomies']);
        $summary['restriction_modes'] = array_unique($summary['restriction_modes']);
        
        return $summary;
    }
}
