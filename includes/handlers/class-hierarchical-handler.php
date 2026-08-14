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

        $current_terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($current_terms)) {
            $current_terms = [];
        }

        $prev_auto = $this->get_auto_terms($post_id, $taxonomy);

        if (empty($current_terms)) {
            $this->set_auto_terms($post_id, $taxonomy, []);
            return;
        }

        [$direction, $expansion] = self::resolve_behavior($rule);
        $depth                   = $rule['inheritance_depth'] ?? 'all';

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
     * The five author-facing outcomes, mapped to the mechanism pair.
     *
     * @since 0.8.0
     * @var array<string,array{0:string,1:string}> outcome => [direction, expansion]
     */
    private const BEHAVIOR_MAP = [
        'ancestors'          => ['child_to_parent', 'never'],
        'descendants_smart'  => ['parent_to_child', 'smart'],
        'descendants_always' => ['parent_to_child', 'merge'],
        'both_smart'         => ['both',            'smart'],
        'both_always'        => ['both',            'merge'],
    ];

    /**
     * Resolve a rule to the (direction, expansion) pair compute_expansion()
     * takes.
     *
     * ### #16, settled in 0.8.0
     *
     * The config used to expose the mechanism directly: `hierarchy_direction`
     * × `expansion_behavior`, nine combinations for six distinct outcomes.
     * Two combinations spelled "ancestors only" (`child_to_parent` + any
     * expansion, and `both` + `never`), and one — `parent_to_child` +
     * `never` — did nothing whatsoever while looking like a configured rule.
     * Authors picked mechanisms and got outcomes they had not predicted.
     *
     * One `inheritance_behavior` selector replaces both, with five options
     * that ARE the five useful outcomes. Nothing downstream changed: this
     * maps straight back onto the pair the expansion code already implements,
     * which is why the collapse is a config change rather than a rewrite.
     *
     * A row saved before the collapse carries only the old pair, so that is
     * the fallback — reading it exactly as before, defaults included. This is
     * the RUNTIME half only, and it covers front-end and cron requests, which
     * never reach the admin boot. The admin half is a real rewrite,
     * `WireframeBootstrap::migrate_inheritance_behavior()`: Wireframe reads
     * the settings option raw, so a row left un-migrated would render with the
     * new select's default and be persisted as such on the next save. A
     * read-time fallback alone would have silently converted rules.
     *
     * @since 0.8.0
     * @param array $rule Rule configuration.
     * @return array{0:string,1:string} [direction, expansion]
     */
    private static function resolve_behavior(array $rule): array {
        $behavior = $rule['inheritance_behavior'] ?? '';

        if (isset(self::BEHAVIOR_MAP[$behavior])) {
            return self::BEHAVIOR_MAP[$behavior];
        }

        // Legacy row (or an unrecognised value): the mechanism pair as stored.
        return [
            $rule['hierarchy_direction'] ?? 'child_to_parent',
            $rule['expansion_behavior'] ?? 'smart',
        ];
    }

    /**
     * The author-facing outcome a rule resolves to, for the row-title
     * snapshot. Legacy rows are named by the outcome their stored mechanism
     * pair produces, so a title never reads "(none)" for a working rule.
     *
     * @since 0.8.0
     * @param array $rule Rule configuration.
     * @return string One of BEHAVIOR_MAP's keys, or '' when the pair is the
     *                degenerate parent_to_child + never (applies nothing).
     */
    public static function behavior_key(array $rule): string {
        [$direction, $expansion] = self::resolve_behavior($rule);

        foreach (self::BEHAVIOR_MAP as $key => $pair) {
            if ($pair === [$direction, $expansion]) {
                return $key;
            }
        }

        // Combinations the five outcomes don't spell exactly: 'always' is a
        // synonym of 'merge' downstream, and child_to_parent ignores its
        // expansion entirely.
        if ($direction === 'child_to_parent') {
            return 'ancestors';
        }
        if ($expansion === 'always') {
            return $direction === 'both' ? 'both_always' : 'descendants_always';
        }
        if ($direction === 'both') {
            return 'ancestors';
        }

        return '';
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

        // `inheritance_behavior` (0.8.0, #16) supersedes the direction/expansion
        // pair; validate whichever the row carries. resolve_behavior() falls
        // back to the pair when the outcome key is absent OR unrecognised, so
        // an unknown outcome must be rejected here rather than quietly running
        // as child_to_parent.
        if (isset($rule['inheritance_behavior']) && $rule['inheritance_behavior'] !== '') {
            return isset(self::BEHAVIOR_MAP[$rule['inheritance_behavior']]);
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
        [$direction, $expansion] = self::resolve_behavior($rule);
        $depth                   = $rule['inheritance_depth'] ?? 'all';

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
