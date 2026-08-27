<?php
/**
 * BWS Taxonomy Manager Related Terms Handler
 * Handles linking terms so applying one automatically applies another
 *
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RelatedHandler extends UnifiedHandlerBase {

    public function get_handler_type(): string {
        return 'related';
    }

    protected function get_rule_type(): string {
        return 'related_rules';
    }

    /**
     * Pure applier: no hooks (#61).
     *
     * `set_object_terms` p10 and `acf/save_post` p20 lived here until 0.8.0.
     * `TermDispatcher` owns the trigger union now, so registering anything here
     * would run this handler's rules twice — once out of authored order, once
     * in it.
     */
    protected function init_hooks() {}

    // Intentional no-op (not a forgotten implementation). apply_to_post() is
    // the applier; the base process_post routes through RuleEngine, which
    // related does not use.
    public function process_post($post_id, $post, $update) {}

    /**
     * Apply ONE related rule to ONE post. The whole of what `on_terms_set` and
     * `on_acf_save_post` used to do, recomputed from live state (#61).
     *
     * WHAT CHANGED, AND WHY IT HAD TO. The old apply was DELTA-driven: removal
     * fired only when `set_object_terms` reported a trigger term leaving in
     * *that* change and no trigger remained. A pass hands over rules, not
     * deltas — and deliberately so, because a rule that consumes an earlier
     * rule's write has no delta of its own for it (ADR 0003 decision 3,
     * CONTEXT.md → Pass). So the condition becomes a question about live state:
     *
     *   trigger present                  → apply the target (merge)
     *   trigger absent AND bidirectional → remove the target
     *
     * ⚠️ BEHAVIOUR CHANGE for `bidirectional` rules, which are LIVE on a real
     * site. Under the delta model a post that held the target term but never
     * held a trigger kept it, because no removal delta ever arrived. It now
     * loses it on the next pass: bidirectional means the target MIRRORS the
     * trigger, which is retroactive ownership of exactly the shape ADR 0001
     * blesses for the temporal rule's trailing removal branch. Rules with
     * `bidirectional` off are unaffected — it is off by default.
     *
     * The ACF path is subsumed rather than dropped. `process_acf_related_terms`
     * only gated "does this post carry an ACF taxonomy field in a trigger
     * taxonomy" before delegating to the same add-only recompute; the terms it
     * then read were native ones either way. A pass recomputes unconditionally,
     * so the gate has nothing left to decide, and `reapply_for_post` (which
     * existed to re-enter the gated ACF path) goes with it — the ACF write
     * queue marks the post dirty for a pass instead.
     *
     * No re-entrancy boolean: the `private $processing` flag is deleted,
     * because the pass lock already suppresses the `set_object_terms` this
     * fires — and with it goes PR #19 review #1, the window `on_terms_set`
     * opened by resetting that flag per rule inside its loop. That window does
     * not exist to reset any more (#61, ADR 0003 decision 4).
     *
     * @param int   $post_id Post to apply to.
     * @param array $rule    One enabled related rule (canonical shape).
     * @return bool Whether the target term's taxonomy actually changed.
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        if (!$this->should_process_post($post_id, $rule)) {
            return false;
        }

        // Related writes the TARGET term's taxonomy, which may differ from any
        // trigger taxonomy — fingerprint that.
        $target_term = \get_term((int) ($rule['target_term_id'] ?? 0));
        if (!$target_term || \is_wp_error($target_term)) {
            return false;
        }

        // A rule whose trigger cannot be RESOLVED is inert, not unsatisfied.
        // This floor is load-bearing only under the live-state reading above.
        // `get_trigger_terms()` answers "[]" both for "the trigger is not on
        // this post" and for "the trigger names a term that no longer exists /
        // a taxonomy that is no longer registered" — a deleted term, a
        // deactivated CPT plugin. The delta model could not tell them apart
        // either, but it did not have to: no delta ever arrived for a trigger
        // that does not exist, so removal simply never fired. Reading absence
        // as "remove" without this check turns one config error into a
        // bidirectional rule stripping its target from EVERY in-scope post on
        // EVERY pass.
        if (!$this->trigger_resolvable($rule)) {
            return false;
        }

        $taxonomy = $target_term->taxonomy;
        $before   = $this->terms_fingerprint($post_id, $taxonomy);

        $trigger_terms = $this->get_trigger_terms($post_id, $rule);

        if (!empty($trigger_terms)) {
            $this->apply_terms_to_post($post_id, $taxonomy, array($target_term->term_id), 'merge');

            $this->debug_log(
                sprintf('Applied related term %d to post %d', $target_term->term_id, $post_id),
                array('trigger_terms' => $trigger_terms)
            );
        } elseif (!empty($rule['bidirectional'])
            && $this->post_has_terms($post_id, $taxonomy, array($target_term->term_id))) {
            $this->remove_terms_from_post($post_id, $taxonomy, array($target_term->term_id));

            $this->debug_log(
                sprintf('Removed related term %d from post %d — no trigger term present', $target_term->term_id, $post_id),
                array('rule' => $rule)
            );
        }

        return $this->terms_fingerprint($post_id, $taxonomy) !== $before;
    }

    /**
     * Whether this rule's trigger names something that still EXISTS (#61).
     *
     * Deliberately weaker than `validate_rule_internal()`, which requires every
     * listed trigger term to resolve. A rule listing three trigger terms of
     * which one has since been deleted still works off the other two — that was
     * true before the conversion and stays true — so the floor is "at least one
     * resolves", not "all of them do". What it rules out is the case where
     * NOTHING the rule points at exists, where an empty answer from
     * `get_trigger_terms()` carries no information about the post at all.
     *
     * @param array $rule One enabled related rule (canonical shape).
     * @return bool
     */
    private function trigger_resolvable($rule): bool {
        $trigger_type = $rule['trigger_type'] ?? '';

        if ($trigger_type === 'term') {
            foreach ((array) ($rule['trigger_term_id'] ?? []) as $tid) {
                $term = \get_term((int) $tid);
                if ($term && !\is_wp_error($term)) {
                    return true;
                }
            }
            return false;
        }

        if ($trigger_type === 'taxonomy') {
            $taxonomy = (string) ($rule['trigger_taxonomy'] ?? '');
            return $taxonomy !== '' && \taxonomy_exists($taxonomy);
        }

        return false;
    }

    /**
     * Get trigger terms present on a post (V2, V3).
     *
     * term-type: returns int[] of trigger IDs that are currently on the post
     * (OR — any match counts), queried across EVERY taxonomy the trigger IDs
     * live in, not just one. Triggers may span taxonomies (the picker lists all
     * of them), so a single-taxonomy answer would read a trigger elsewhere as
     * absent and, for a bidirectional rule, wrongly remove the target (V4/V14).
     * taxonomy-type: returns all post terms in the trigger taxonomy.
     */
    private function get_trigger_terms($post_id, $rule) {
        $trigger_terms = array();

        if ($rule['trigger_type'] === 'term') {
            $trigger_ids = (array) ($rule['trigger_term_id'] ?? []);
            foreach ($trigger_ids as $tid) {
                $tid  = (int) $tid;
                $term = \get_term($tid);
                if (!$term || \is_wp_error($term)) {
                    continue;
                }
                if ($this->post_has_terms($post_id, $term->taxonomy, array($term->term_id))) {
                    $trigger_terms[] = $term->term_id;
                }
            }
        } elseif ($rule['trigger_type'] === 'taxonomy') {
            $post_terms = wp_get_object_terms($post_id, $rule['trigger_taxonomy'], array('fields' => 'ids'));
            if (!is_wp_error($post_terms) && !empty($post_terms)) {
                $trigger_terms = $post_terms;
            }
        }

        return $trigger_terms;
    }

    /**
     * Validate a related rule (V5).
     *
     * No post_type check — multi-PT now, empty post_types ⇒ all (V2).
     * Field sanitization is handled by Wireframe's config-driven Sanitizer;
     * this only enforces the trigger/target term/taxonomy relationships.
     *
     * Does NOT check `enabled` — that's the caller's concern
     * (get_enabled_rules already filters), and conflating "disabled" with
     * "malformed" would mislead any future validation-only caller.
     *
     * @param array $rule Rule configuration
     * @return bool Valid
     */
    protected function validate_rule_internal($rule) {
        $trigger_type = $rule['trigger_type'] ?? '';
        if (!in_array($trigger_type, array('term', 'taxonomy'), true)) {
            return false;
        }

        if ($trigger_type === 'term') {
            // V6: non-empty AND every id resolves.
            $trigger_ids = (array) ($rule['trigger_term_id'] ?? []);
            $trigger_ids = array_filter($trigger_ids);
            if (empty($trigger_ids)) {
                return false;
            }
            foreach ($trigger_ids as $tid) {
                if (!\get_term((int) $tid)) {
                    return false;
                }
            }
        } else { // taxonomy
            if (empty($rule['trigger_taxonomy']) || !\taxonomy_exists($rule['trigger_taxonomy'])) {
                return false;
            }
        }

        if (empty($rule['target_term_id']) || !\get_term($rule['target_term_id'])) {
            return false;
        }

        return true;
    }
}
