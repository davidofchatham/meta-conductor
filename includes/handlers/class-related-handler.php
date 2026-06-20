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

    /**
     * Track if we're currently processing to prevent infinite loops
     */
    private $processing = false;

    public function get_handler_type(): string {
        return 'related';
    }

    protected function get_rule_type(): string {
        return 'related_rules';
    }

    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        add_action('set_object_terms', array($this, 'on_terms_set'), 10, 6);
        add_action('acf/save_post', array($this, 'on_acf_save_post'), 20);
    }

    // Intentional no-op (not a forgotten implementation). Related rules fire
    // via on_terms_set / on_acf_save_post; the base process_post routes
    // through RuleEngine, which related does not use.
    public function process_post($post_id, $post, $update) {}

    /**
     * Handle terms being set
     */
    public function on_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if ($this->processing) {
            return;
        }
        
        $post = \get_post($object_id);
        if (!$post) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();

        foreach ($enabled_rules as $rule) {
            if (!$this->should_process_post($object_id, $rule)) {
                continue;
            }

            // Check if this taxonomy change should trigger related terms
            if ($this->should_trigger_related_terms($rule, $taxonomy, $tt_ids, $old_tt_ids)) {
                $this->processing = true;
                try {
                    $this->apply_related_terms($object_id, $rule, $tt_ids, $old_tt_ids);
                } finally {
                    // Reset even if a downstream filter/hook throws, so later
                    // rules in this request aren't silently skipped.
                    $this->processing = false;
                }
            }
        }
    }
    
    /**
     * Handle ACF field saves
     */
    public function on_acf_save_post($post_id) {
        if ($this->processing || !is_numeric($post_id)) {
            return;
        }
        
        $post = \get_post($post_id);
        if (!$post) {
            return;
        }
        
        $enabled_rules = $this->get_enabled_rules();

        foreach ($enabled_rules as $rule) {
            if (!$this->should_process_post($post_id, $rule)) {
                continue;
            }

            $this->processing = true;
            try {
                $this->process_acf_related_terms($post_id, $rule);
            } finally {
                $this->processing = false;
            }
        }
    }
    
    /**
     * Process related terms for a post.
     *
     * Only ADDS the target term when the trigger is present.
     * Removal is handled exclusively by apply_related_terms() which has
     * the old/new term-taxonomy-ID diff needed to know the trigger was
     * actually removed — not just absent.
     */
    private function process_related_terms($post_id, $rule) {
        $trigger_terms = $this->get_trigger_terms($post_id, $rule);

        if (!empty($trigger_terms)) {
            $target_term = get_term($rule['target_term_id']);
            if ($target_term && !is_wp_error($target_term)) {
                $this->apply_terms_to_post($post_id, $target_term->taxonomy, array($target_term->term_id), 'merge');

                $this->debug_log(
                    sprintf('Applied related term %d to post %d', $rule['target_term_id'], $post_id),
                    array('trigger_terms' => $trigger_terms)
                );
            }
        }
    }
    
    /**
     * Get trigger terms present on a post (V2, V3).
     *
     * term-type: returns int[] of trigger IDs that are currently on the post
     * (OR — any match counts). taxonomy-type: returns all post terms in the
     * trigger taxonomy.
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
     * Check if a term-set change should trigger this rule (V3).
     *
     * term-type: fires if ANY listed trigger term was added or removed in
     * this taxonomy change (OR semantics). taxonomy-type: fires whenever the
     * trigger taxonomy itself changed.
     */
    private function should_trigger_related_terms($rule, $taxonomy, $new_tt_ids, $old_tt_ids) {
        if ($rule['trigger_type'] === 'term') {
            // V12: hook tt_ids are strings, term_taxonomy_id is int — normalize
            // both to int before strict comparison or the match always fails.
            $new_tt_ids = array_map('intval', (array) $new_tt_ids);
            $old_tt_ids = array_map('intval', (array) ($old_tt_ids ?? []));

            $added   = array_diff($new_tt_ids, $old_tt_ids);
            $removed = array_diff($old_tt_ids, $new_tt_ids);

            $trigger_ids = (array) ($rule['trigger_term_id'] ?? []);
            foreach ($trigger_ids as $tid) {
                $term = \get_term((int) $tid);
                if (!$term || \is_wp_error($term) || $term->taxonomy !== $taxonomy) {
                    continue;
                }
                $ttid = (int) $term->term_taxonomy_id;
                if (in_array($ttid, $added, true)
                    || in_array($ttid, $removed, true)) {
                    return true;
                }
            }
            return false;

        } elseif ($rule['trigger_type'] === 'taxonomy') {
            return $rule['trigger_taxonomy'] === $taxonomy;
        }

        return false;
    }
    
    /**
     * Apply or remove the target term for this rule (V3, V4).
     *
     * term-type apply: ANY listed trigger term added in this change → apply.
     * term-type remove (bidirectional V4): at least one trigger removed in this
     *   change AND NO trigger term remains on the post (queried across every
     *   taxonomy, not just the one that fired) → remove. If another trigger is
     *   still present anywhere the target stays.
     * taxonomy-type: unchanged (any terms present → apply; none → remove).
     */
    private function apply_related_terms($post_id, $rule, $new_tt_ids, $old_tt_ids) {
        $target_term = \get_term($rule['target_term_id']);
        if (!$target_term || \is_wp_error($target_term)) {
            return;
        }

        $should_apply  = false;
        $should_remove = false;

        if ($rule['trigger_type'] === 'term') {
            // V12: normalize both sides to int before strict comparison.
            $new_tt_ids = array_map('intval', (array) $new_tt_ids);
            $old_tt_ids = array_map('intval', (array) ($old_tt_ids ?? []));

            $trigger_ids = (array) ($rule['trigger_term_id'] ?? []);
            $added       = array_diff($new_tt_ids, $old_tt_ids);
            $removed     = array_diff($old_tt_ids, $new_tt_ids);

            $any_trigger_added   = false;
            $any_trigger_removed = false;

            foreach ($trigger_ids as $tid) {
                $term = \get_term((int) $tid);
                if (!$term || \is_wp_error($term)) {
                    continue;
                }
                $ttid = (int) $term->term_taxonomy_id;
                if (in_array($ttid, $added, true)) {
                    $any_trigger_added = true;
                }
                if (in_array($ttid, $removed, true)) {
                    $any_trigger_removed = true;
                }
            }

            // V14: "is any trigger still present" must be answered against the
            // post's ACTUAL terms across ALL taxonomies — not $new_tt_ids, which
            // only holds the single taxonomy this set_object_terms call changed.
            // Triggers may span taxonomies (the picker lists all of them), so a
            // hook-payload check would see a trigger in another taxonomy as
            // absent and wrongly remove the target (violates V4).
            $any_trigger_present = !empty($this->get_trigger_terms($post_id, $rule));

            if ($any_trigger_added) {
                $should_apply = true;
            } elseif ($any_trigger_removed && !$any_trigger_present) {
                // ALL trigger terms gone from the post — safe to remove (V4).
                $should_remove = true;
            }

        } elseif ($rule['trigger_type'] === 'taxonomy') {
            if (!empty($new_tt_ids)) {
                $should_apply = true;
            } elseif (empty($new_tt_ids) && !empty($old_tt_ids)) {
                $should_remove = true;
            }
        }

        if ($should_apply) {
            $this->apply_terms_to_post($post_id, $target_term->taxonomy, array($target_term->term_id), 'merge');
        } elseif ($should_remove && !empty($rule['bidirectional'])) {
            $this->remove_terms_from_post($post_id, $target_term->taxonomy, array($target_term->term_id));
        }
    }
    
    /**
     * Process ACF-field saves for this rule (V5).
     *
     * term-type: trigger taxonomy = union of taxonomies across all trigger
     * term IDs. Fires if any ACF taxonomy field belongs to that set.
     * taxonomy-type: unchanged.
     */
    private function process_acf_related_terms($post_id, $rule) {
        if (!function_exists('get_field_objects')) {
            return;
        }

        $field_objects = get_field_objects($post_id);
        if (!$field_objects) {
            return;
        }

        $trigger_taxonomies = [];

        if ($rule['trigger_type'] === 'taxonomy') {
            if (!empty($rule['trigger_taxonomy'])) {
                $trigger_taxonomies[] = $rule['trigger_taxonomy'];
            }
        } elseif ($rule['trigger_type'] === 'term') {
            $trigger_ids = (array) ($rule['trigger_term_id'] ?? []);
            foreach ($trigger_ids as $tid) {
                $term = \get_term((int) $tid);
                if ($term && !\is_wp_error($term)) {
                    $trigger_taxonomies[] = $term->taxonomy;
                }
            }
            $trigger_taxonomies = array_unique($trigger_taxonomies);
        }

        if (empty($trigger_taxonomies)) {
            return;
        }

        foreach ($field_objects as $field) {
            if ($field['type'] === 'taxonomy'
                && isset($field['taxonomy'])
                && in_array($field['taxonomy'], $trigger_taxonomies, true)) {
                $this->process_related_terms($post_id, $rule);
                break;
            }
        }
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
