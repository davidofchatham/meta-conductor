<?php
/**
 * BWS Taxonomy Manager Time-Based Handler
 * Handles applying terms based on date ranges
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class TimeBasedHandler extends UnifiedHandlerBase {

    public function get_handler_type(): string {
        return 'time_based';
    }

    protected function get_rule_type(): string {
        return 'time_based_rules';
    }

    /**
     * Pure applier: no hooks (#61).
     *
     * `save_post` p20 and `publish_post` p10 lived here until 0.8.0.
     * `TermDispatcher` owns the trigger union now, so registering either would
     * run this handler's rules twice — once out of authored order, once in it.
     *
     * The daily `bws_taxonomy_manager_cleanup` sweep is NOT registered here
     * either, even though it is a provocation rather than an apply: it is wired
     * in `TaxonomyManager::init_handlers()`, next to the dispatcher it
     * provokes. A converted handler registering NOTHING is a bright line a
     * static check can hold (H13); "nothing except the one hook that only
     * enqueues" is not.
     */
    protected function init_hooks() {}

    /**
     * Apply ONE time-based rule to ONE post. The whole of what `on_post_save`
     * and `on_post_publish` used to do for a single rule (#61), and still the
     * bulk-apply primitive it was added as (#31).
     *
     * Idempotent by construction, which is what lets a pass re-run it whether
     * or not anything time-based provoked the pass: both branches are guarded
     * on has-term and on the date range, so in-range+already-tagged and
     * out-of-range+absent are alike no-ops. No re-entrancy boolean is needed or
     * permitted — the pass lock is the only guard (ADR 0003 decision 4).
     *
     * The trailing removal branch is deliberate and load-bearing: it strips the
     * target term from an out-of-range post regardless of who applied it, which
     * is the retroactive ownership ADR 0001 grants this rule type (provenance
     * is rejected). Do not "fix" it into a provenance check.
     *
     * Returns whether the target-term taxonomy changed, so the bulk count is
     * honest (#31).
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        if (!$this->should_process_post($post_id, $rule) || !self::has_window($rule)) {
            return false;
        }
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        // Time-based writes the TARGET term's taxonomy — fingerprint that.
        $taxonomy    = '';
        $target_term = get_term((int) ($rule['target_term_id'] ?? 0));
        if ($target_term && !is_wp_error($target_term)) {
            $taxonomy = $target_term->taxonomy;
        }
        $before = $this->terms_fingerprint($post_id, $taxonomy);
        $this->apply_time_based_rule($post_id, $post, $rule);
        return $this->terms_fingerprint($post_id, $taxonomy) !== $before;
    }

    /**
     * Whether the rule names both ends of its window.
     *
     * A missing date compares as `''`, which is below every Y-m-d, so an empty
     * `end_date` reads as long expired and the removal branch would strip the
     * target from every in-scope post on every pass. The UI marks both dates
     * required, but a hand-authored or seeded row is not held to that.
     */
    private static function has_window(array $rule): bool {
        return ($rule['start_date'] ?? '') !== '' && ($rule['end_date'] ?? '') !== '';
    }

    /**
     * Apply time-based rule to a post
     */
    private function apply_time_based_rule($post_id, $post, $rule) {
        $current_date = current_time('Y-m-d');
        $start_date = $rule['start_date'];
        $end_date = $rule['end_date'];
        
        // Check if current date is within rule date range
        $in_date_range = ($current_date >= $start_date && $current_date <= $end_date);
        
        // Check if post matches filter criteria
        $matches_filter = $this->post_matches_filter($post_id, $rule);
        
        $target_term = get_term($rule['target_term_id']);
        if (!$target_term || is_wp_error($target_term)) {
            return;
        }
        
        $has_target_term = $this->post_has_terms($post_id, $target_term->taxonomy, array($target_term->term_id));
        
        if ($in_date_range && $matches_filter && !$has_target_term) {
            // Apply the term
            $this->apply_terms_to_post($post_id, $target_term->taxonomy, array($target_term->term_id), 'merge');
            
            $this->debug_log(
                sprintf('Applied time-based term %d to post %d', $rule['target_term_id'], $post_id),
                array('rule' => $rule, 'current_date' => $current_date)
            );
            
        } elseif (!$in_date_range && $has_target_term) {
            // Remove the term if outside date range
            $this->remove_terms_from_post($post_id, $target_term->taxonomy, array($target_term->term_id));
            
            $this->debug_log(
                sprintf('Removed expired time-based term %d from post %d', $rule['target_term_id'], $post_id),
                array('rule' => $rule, 'current_date' => $current_date)
            );
        }
    }
    
    /**
     * Check if post matches the filter criteria for a rule
     */
    private function post_matches_filter($post_id, $rule) {
        // Flatten both filters up front. filter_taxonomies is a Wireframe
        // checkboxes {slug:bool} map; filter_terms is a token list. Extracting
        // selected slugs first means an all-unchecked map (non-empty but no
        // selection) correctly reads as "no filter", and the taxonomy loop never
        // binds to boolean values. (0.6.0 review)
        $filter_terms      = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($rule['filter_terms'] ?? []);
        $filter_taxonomies = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($rule['filter_taxonomies'] ?? []);

        // No filter → match all posts.
        if (empty($filter_terms) && empty($filter_taxonomies)) {
            return true;
        }

        // Check filter terms (if specified).
        if (!empty($filter_terms)) {
            foreach ($filter_terms as $filter_term_id) {
                $filter_term = get_term($filter_term_id);
                if ($filter_term && !is_wp_error($filter_term)) {
                    if ($this->post_has_terms($post_id, $filter_term->taxonomy, array($filter_term->term_id))) {
                        return true;
                    }
                }
            }
            return false;
        }

        // Check filter taxonomies (if specified).
        if (!empty($filter_taxonomies)) {
            foreach ($filter_taxonomies as $taxonomy) {
                if ($this->post_has_terms($post_id, $taxonomy)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }
    
    /**
     * The daily sweep. ENQUEUES the posts an expired rule still holds and lets
     * the dispatcher drain them as full ordered passes (#61).
     *
     * Registered by `TaxonomyManager::init_handlers()`, not by this class — see
     * `init_hooks()`.
     *
     * WHY IT NO LONGER REMOVES ANYTHING ITSELF. The removal it used to perform
     * is exactly `apply_time_based_rule()`'s trailing out-of-range branch, so
     * the sweep was a second, private execution path for one rule — running
     * that rule in isolation, outside any pass, in whatever order the handler
     * map happened to be in. A term the sweep stripped at 3am was therefore
     * invisible to every rule that should have reacted to its going, and a
     * later rule that would have re-applied it never got the chance. Selecting
     * posts is the sweep's real job; deciding what happens to them is the
     * pass's (CONTEXT.md → Pass: "a save, an ACF write, a bulk re-apply, a cron
     * sweep ... it is the same pass in every case").
     *
     * The removal RULE is unchanged: for each swept post the pass runs this
     * handler's expired rule, whose out-of-range branch removes the target term
     * whoever applied it (ADR 0001 retroactive ownership).
     *
     * ONE THING DID CHANGE, and it is a correction. The old sweep removed from
     * every post its query returned, consulting no gate at all — so a rule
     * scoped to published posts still stripped its term from drafts on the
     * nightly run, while a save of that same draft left it alone. Going through
     * the pass means going through `should_process_post()`, so cron and save now
     * agree, which is the whole point of routing the sweep here. The selection
     * below is narrowed to match rather than left wide: enqueuing a post the
     * rule cannot touch is not harmless once the sweep provokes a FULL pass on
     * it — every other rule in the list would run on a post nothing had
     * provoked.
     *
     * Drains explicitly rather than leaving it to `shutdown`. A cron request
     * does reach shutdown, but a caller that provokes the sweep and reads terms
     * back in the same request — the mc-rules `verify.php` A7 probe fires
     * `bws_taxonomy_manager_cleanup` by hand — would otherwise read pre-pass
     * state, which is trap (a) of the dispatcher's contract.
     */
    public function cleanup_expired_rules() {
        $dispatcher = \BWS\MetaConductor\Core\TermDispatcher::instance();
        if (!$dispatcher) {
            return;
        }

        $enabled_rules = $this->get_enabled_rules();
        $current_date  = current_time('Y-m-d');
        $swept         = array();

        foreach ($enabled_rules as $rule) {
            // Skip rules that haven't expired yet
            if (!self::has_window($rule) || $current_date <= $rule['end_date']) {
                continue;
            }

            foreach ($this->expired_rule_posts($rule) as $post_id) {
                $swept[$post_id] = true;
                $dispatcher->mark_dirty($post_id);
            }
        }

        if (empty($swept)) {
            return;
        }

        $this->debug_log(
            sprintf('Time-based sweep enqueued %d posts for an ordered pass', count($swept)),
            array('cleanup_date' => $current_date, 'post_ids' => array_keys($swept))
        );

        $dispatcher->drain();
    }

    /**
     * The posts an expired rule still holds its target term on — the sweep's
     * selection, with no effect of its own.
     *
     * @param array $rule One expired, enabled time-based rule.
     * @return int[] Post IDs, possibly empty.
     */
    private function expired_rule_posts($rule): array {
        $target_term = get_term((int) ($rule['target_term_id'] ?? 0));
        if (!$target_term || is_wp_error($target_term)) {
            return array();
        }

        // Resolve the rule's post types for the sweep query. Empty ⇒ all
        // public types (get_posts needs a concrete set; the gate treats empty
        // as "all"). get_posts accepts an array of slugs.
        $post_types = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($rule['post_types'] ?? []);
        if (empty($post_types)) {
            $post_types = array_values(get_post_types(array('public' => true)));
        }

        // Resolve the rule's post STATUSES the same way, so selection matches
        // the gate the pass will apply (see cleanup_expired_rules()). Empty ⇒
        // the historical set, which is what an unscoped rule always swept.
        $post_statuses = \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($rule['post_status'] ?? []);
        if (empty($post_statuses) || $post_statuses[0] === 'any') {
            $post_statuses = array('publish', 'draft', 'private');
        }

        // Find all posts that have this term
        $posts_with_term = get_posts(array(
            'post_type' => $post_types,
            'post_status' => $post_statuses,
            'numberposts' => -1,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => $target_term->taxonomy,
                    'field' => 'term_id',
                    'terms' => $target_term->term_id
                )
            )
        ));

        return array_map('intval', (array) $posts_with_term);
    }

    /**
     * Get active rules for current date
     */
    public function get_active_rules($date = null) {
        if ($date === null) {
            $date = current_time('Y-m-d');
        }
        
        $enabled_rules = $this->get_enabled_rules();
        $active_rules = array();
        
        foreach ($enabled_rules as $rule) {
            if (self::has_window($rule) && $date >= $rule['start_date'] && $date <= $rule['end_date']) {
                $active_rules[] = $rule;
            }
        }
        
        return $active_rules;
    }
    
    /**
     * Get upcoming rules (starting within next 7 days)
     */
    public function get_upcoming_rules($days_ahead = 7) {
        $current_date = current_time('Y-m-d');
        $future_date = date('Y-m-d', strtotime($current_date . " +{$days_ahead} days"));
        
        $enabled_rules = $this->get_enabled_rules();
        $upcoming_rules = array();
        
        foreach ($enabled_rules as $rule) {
            if (self::has_window($rule) && $rule['start_date'] > $current_date && $rule['start_date'] <= $future_date) {
                $upcoming_rules[] = $rule;
            }
        }
        
        return $upcoming_rules;
    }
    
    /**
     * Get rules summary for admin display
     */
    public function get_rules_summary() {
        $enabled_rules = $this->get_enabled_rules();
        $current_date = current_time('Y-m-d');
        
        $summary = array(
            'total_rules' => count($enabled_rules),
            'active_rules' => count($this->get_active_rules($current_date)),
            'upcoming_rules' => count($this->get_upcoming_rules(7)),
            'expired_rules' => 0
        );
        
        foreach ($enabled_rules as $rule) {
            if (self::has_window($rule) && $rule['end_date'] < $current_date) {
                $summary['expired_rules']++;
            }
        }
        
        return $summary;
    }
}
