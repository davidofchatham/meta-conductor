<?php
/**
 * Hierarchical Handler
 *
 * Applies parent/ancestor terms when children are selected (child_to_parent),
 * child terms when parent is selected (parent_to_child), or both.
 *
 * Works directly with flat Wireframe-stored rule fields — no engine translation.
 *
 * Tracks auto-added terms in post meta (_bws_auto_terms) so the handler can
 * distinguish user-selected terms from those it previously added. A term that
 * was auto-added but is kept by the user after its source is removed gets
 * promoted to a user term and becomes eligible for expansion.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Handlers;

if (!defined('ABSPATH')) {
    exit;
}

class HierarchicalHandler extends UnifiedHandlerBase {

    private const AUTO_TERMS_META = '_bws_auto_terms';

    private bool $processing = false;
    private array $processed = [];

    protected function init_hooks() {
        add_action('set_object_terms', array($this, 'on_terms_set'), 10, 6);
    }

    public function get_handler_type() {
        return 'hierarchical';
    }

    protected function get_rule_type() {
        return 'hierarchical_rules';
    }

    // Hierarchical rules use on_terms_set exclusively. Base class process_post
    // routes through RuleEngine which expects action/source_type keys.
    public function process_post($post_id, $post, $update) {}

    public function on_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if ($this->processing) {
            return;
        }

        foreach ($this->get_rules_for_taxonomy($taxonomy) as $rule) {
            if ($this->should_process_post($object_id, $rule)) {
                $this->apply_rule((int) $object_id, $taxonomy, $rule);
            }
        }
    }

    /**
     * Core rule logic.
     *
     * 1. Read current terms and previous auto-added set from meta.
     * 2. Tentative user terms = current minus prev_auto.
     * 3. Compute expansion from tentative user terms.
     * 4. Any term in current that was prev_auto but is NOT in the new expansion
     *    is promoted to user term (the user kept it intentionally). Recompute.
     * 5. Final set = user terms + auto terms. Update meta + post terms.
     */
    protected function apply_rule(int $post_id, string $taxonomy, array $rule): void {
        if (!taxonomy_exists($taxonomy)) {
            return;
        }
        $tax_obj = get_taxonomy($taxonomy);
        if (!$tax_obj || !$tax_obj->hierarchical) {
            return;
        }

        $key = "{$post_id}:{$taxonomy}";
        if (isset($this->processed[$key])) {
            return;
        }

        $current_terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($current_terms)) {
            $current_terms = [];
        }

        $prev_auto = $this->get_auto_terms($post_id, $taxonomy);

        if (empty($current_terms)) {
            $this->set_auto_terms($post_id, $taxonomy, []);
            return;
        }

        $direction = $rule['hierarchy_direction'] ?? 'child_to_parent';
        $depth     = $rule['inheritance_depth'] ?? 'all';
        $expansion = $rule['expansion_behavior'] ?? 'smart';

        // Tentative user terms: what's on the post minus what we auto-added before.
        $user_terms = array_values(array_diff($current_terms, $prev_auto));

        // Compute expansion from tentative user terms.
        $auto_terms = $this->compute_expansion($user_terms, $taxonomy, $direction, $depth, $expansion);

        // Promote: any term that was prev_auto AND is still on the post BUT is
        // not in the new expansion must have been intentionally kept by the user.
        $kept_auto = array_intersect($prev_auto, $current_terms);
        $promoted  = array_diff($kept_auto, $auto_terms);

        if (!empty($promoted)) {
            $user_terms = array_values(array_unique(array_merge($user_terms, $promoted)));
            $auto_terms = $this->compute_expansion($user_terms, $taxonomy, $direction, $depth, $expansion);
        }

        $this->set_auto_terms($post_id, $taxonomy, $auto_terms);
        $this->processed[$key] = true;

        $final = array_values(array_unique(array_merge($user_terms, $auto_terms)));
        sort($final);

        $sorted_current = $current_terms;
        sort($sorted_current);

        if ($final === $sorted_current) {
            return;
        }

        $this->processing = true;
        wp_set_object_terms($post_id, $final, $taxonomy);
        $this->processing = false;
    }

    /**
     * Compute which terms should be auto-added for a set of user terms.
     */
    private function compute_expansion(array $user_terms, string $taxonomy, string $direction, string $depth, string $expansion): array {
        if (empty($user_terms)) {
            return [];
        }

        $auto = [];

        if ($direction === 'child_to_parent' || $direction === 'both') {
            $auto = array_merge($auto, $this->get_ancestor_term_ids($user_terms, $taxonomy, $depth));
        }

        if ($direction === 'parent_to_child' || $direction === 'both') {
            $auto = array_merge($auto, $this->get_child_term_ids($user_terms, $taxonomy, $depth, $expansion));
        }

        $auto = array_unique($auto);
        return array_values(array_diff($auto, $user_terms));
    }

    private function get_auto_terms(int $post_id, string $taxonomy): array {
        $all = get_post_meta($post_id, self::AUTO_TERMS_META, true);
        if (!is_array($all)) {
            return [];
        }
        return array_map('intval', $all[$taxonomy] ?? []);
    }

    private function set_auto_terms(int $post_id, string $taxonomy, array $term_ids): void {
        $all = get_post_meta($post_id, self::AUTO_TERMS_META, true);
        if (!is_array($all)) {
            $all = [];
        }
        if (empty($term_ids)) {
            unset($all[$taxonomy]);
        } else {
            $all[$taxonomy] = array_values(array_map('intval', $term_ids));
        }
        if (empty($all)) {
            delete_post_meta($post_id, self::AUTO_TERMS_META);
        } else {
            update_post_meta($post_id, self::AUTO_TERMS_META, $all);
        }
    }

    private function get_ancestor_term_ids(array $term_ids, string $taxonomy, string $depth): array {
        $ancestors = [];

        foreach ($term_ids as $term_id) {
            if ($depth === 'immediate') {
                $term = get_term($term_id, $taxonomy);
                if ($term && !is_wp_error($term) && $term->parent) {
                    $ancestors[] = $term->parent;
                }
            } else {
                $ancestors = array_merge($ancestors, get_ancestors($term_id, $taxonomy, 'taxonomy'));
            }
        }

        return array_unique($ancestors);
    }

    private function get_child_term_ids(array $current_term_ids, string $taxonomy, string $depth, string $expansion): array {
        $children = [];

        foreach ($current_term_ids as $term_id) {
            $term_children = get_term_children($term_id, $taxonomy);
            if (is_wp_error($term_children)) {
                continue;
            }

            if ($depth === 'immediate') {
                foreach ($term_children as $child_id) {
                    $child = get_term($child_id, $taxonomy);
                    if ($child && !is_wp_error($child) && $child->parent == $term_id) {
                        $children[] = $child_id;
                    }
                }
            } else {
                $children = array_merge($children, $term_children);
            }
        }

        $children = array_unique($children);

        switch ($expansion) {
            case 'smart':
                if (!empty(array_intersect($children, $current_term_ids))) {
                    return [];
                }
                return array_diff($children, $current_term_ids);

            case 'merge':
            case 'always':
                return array_diff($children, $current_term_ids);

            case 'never':
            default:
                return [];
        }
    }

    protected function get_rules_for_taxonomy(string $taxonomy): array {
        $matching = [];

        foreach ($this->get_enabled_rules() as $rule_id => $rule) {
            if (($rule['taxonomy'] ?? '') === $taxonomy) {
                $rule['id'] = $rule_id;
                $matching[] = $rule;
            }
        }

        return $matching;
    }

    protected function validate_rule_internal($rule) {
        if (empty($rule['enabled'])) {
            return false;
        }

        $taxonomy = $rule['taxonomy'] ?? '';
        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return false;
        }

        $tax_obj = get_taxonomy($taxonomy);
        if (!$tax_obj->hierarchical) {
            return false;
        }

        $valid_directions = ['child_to_parent', 'parent_to_child', 'both'];
        if (isset($rule['hierarchy_direction']) && !in_array($rule['hierarchy_direction'], $valid_directions)) {
            return false;
        }

        return true;
    }

    public function preview_changes(int $post_id, string $rule_id): array {
        $rule = $this->get_rule($rule_id);
        if (!$rule) {
            return ['error' => 'Rule not found'];
        }

        $taxonomy = $rule['taxonomy'] ?? '';
        if (!$taxonomy) {
            return ['error' => 'No taxonomy specified'];
        }

        $current_ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($current_ids)) {
            $current_ids = [];
        }

        $prev_auto  = $this->get_auto_terms($post_id, $taxonomy);
        $user_terms = array_values(array_diff($current_ids, $prev_auto));
        $direction  = $rule['hierarchy_direction'] ?? 'child_to_parent';
        $depth      = $rule['inheritance_depth'] ?? 'all';
        $expansion  = $rule['expansion_behavior'] ?? 'smart';

        $auto = $this->compute_expansion($user_terms, $taxonomy, $direction, $depth, $expansion);

        $kept_auto = array_intersect($prev_auto, $current_ids);
        $promoted  = array_diff($kept_auto, $auto);
        if (!empty($promoted)) {
            $user_terms = array_values(array_unique(array_merge($user_terms, $promoted)));
            $auto = $this->compute_expansion($user_terms, $taxonomy, $direction, $depth, $expansion);
        }

        return [
            'user_term_ids' => $user_terms,
            'terms_to_add'  => $auto,
            'final_term_ids' => array_values(array_unique(array_merge($user_terms, $auto))),
            'direction'     => $direction,
        ];
    }
}
