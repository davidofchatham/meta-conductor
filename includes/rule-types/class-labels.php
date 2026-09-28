<?php
/**
 * Label helpers the rule-type row titles share.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Human labels for the ids a stored row holds (terms, taxonomies, post
 * types, statuses, claims), for `RuleType::row_title()` and the General-tab
 * claim-override snapshot.
 *
 * Every helper returns UNESCAPED text. `WireframeBootstrap::snapshot_row_titles()`
 * escapes each title once, so escaping here would double it.
 */
final class Labels {

    /**
     * Leading marker for a disabled rule's collapsed row title, '' when enabled.
     * Shared by every rule type whose repeater carries an `enabled` toggle.
     *
     * @param array $rule Clean rule values (the `enabled` subfield).
     * @return string Unescaped marker (already-safe literal).
     */
    public static function disabled_prefix(array $rule): string {
        // `enabled` defaults true; a rule missing the key (legacy) is treated
        // as enabled, matching the config default and the handler gate.
        $enabled = !array_key_exists('enabled', $rule) || !empty($rule['enabled']);
        return $enabled ? '' : \esc_html__('[Disabled] ', 'meta-conductor');
    }

    /**
     * Claim label for a stored conflict_handling value.
     *
     * Shared by both surfaces that print a claim: the propagation row title
     * and the General-tab override row title.
     *
     * The mapping itself lives on ConfigHelpers::CLAIM_NAMES, which is also
     * what builds the two config dropdowns — so a claim rename touches one
     * line and cannot leave a surface stale (replace = owning-claim,
     * merge = contributing-claim, skip = deferring-claim; the `-claim`
     * qualifier disambiguates `deferring` from defer-as-postpone).
     * See CONTEXT.md → Claim and ADR 0004.
     *
     * @param string $value merge|replace|skip.
     * @return string Unescaped label.
     */
    public static function claim_label($value): string {
        return ConfigHelpers::claim_name(is_string($value) ? $value : 'merge');
    }

    /**
     * Comma-joined human labels for a post_status gate. '' when no gate set.
     *
     * @param string[] $slugs
     * @return string Unescaped.
     */
    public static function status_gate_label(array $slugs): string {
        if (empty($slugs)) {
            return '';
        }

        $labels = [];
        foreach ($slugs as $slug) {
            $obj = \get_post_status_object((string) $slug);
            $labels[] = $obj ? $obj->label : (string) $slug;
        }
        return implode(', ', $labels);
    }

    /**
     * Resolve a single term ID to "<taxonomy label>: <term name>".
     *
     * Returns '' when unresolvable. Used for target_term_id (single) and as a
     * primitive for trigger_terms_label (multi).
     *
     * @param int $id Term ID.
     * @return string Unescaped label.
     */
    public static function term_label(int $id): string {
        if ($id <= 0) {
            return '';
        }

        $term = \get_term($id);
        if (!$term || \is_wp_error($term)) {
            return '';
        }

        $tax_label = self::taxonomy_label($term->taxonomy);

        return $tax_label !== '' ? $tax_label . ': ' . $term->name : $term->name;
    }

    /**
     * Comma-joined term labels for a list of term ids.
     *
     * @param int[] $ids
     * @return string Unescaped, comma-joined label; '' if nothing resolves.
     */
    public static function trigger_terms_label(array $ids): string {
        $labels = [];
        foreach ($ids as $id) {
            $label = self::term_label($id);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return implode(', ', $labels);
    }

    /**
     * Resolve a post-type slug list to a flat array of human post-type
     * labels. Unresolvable slugs (a type unregistered after save) are
     * dropped. Single source for the row-title scope formatters.
     *
     * @param string[] $post_types
     * @return string[] Post-type labels.
     */
    public static function post_type_labels(array $post_types): array {
        $labels = [];
        foreach ($post_types as $slug) {
            $obj = \get_post_type_object((string) $slug);
            if ($obj) {
                $labels[] = $obj->label;
            }
        }
        return $labels;
    }

    /**
     * Leading "Post type: " prefix for a row title, shown ONLY when the rule
     * is restricted to specific post types. Empty (= applies to all) ⇒ '' so
     * the title reads as a plain sentence.
     *
     * @param string[] $post_types Slug list.
     * @return string Trailing ": " when present.
     */
    public static function scope_prefix(array $post_types): string {
        $labels = self::post_type_labels($post_types);
        return empty($labels) ? '' : implode(', ', $labels) . ': ';
    }

    /**
     * Post-type scope SUFFIX " (Label, Label)" for a row title, '' when the rule
     * applies to all post types.
     *
     * The delimiters are deliberately BAKED INTO the stored snapshot value, not
     * applied at render: Wireframe's `title_template` can only interpolate, so
     * there is nowhere else to format. Consequence — if the row-title format
     * ever changes, already-persisted titles keep the old shape until each
     * rule is re-saved. Accepted rather than fixed, since storing raw slugs
     * would require render-time formatting the template cannot do.
     *
     * @param string[] $post_types
     * @return string
     */
    public static function scope_label(array $post_types): string {
        $labels = self::post_type_labels($post_types);
        return empty($labels) ? '' : ' (' . implode(', ', $labels) . ')';
    }

    /**
     * Post-type scope SUFFIX " (Label)" for a rule that names ONE post type,
     * '' when it names none.
     *
     * The scalar counterpart of scope_label(), which reads a checkboxes value.
     * They are not one function taking either shape on purpose: a scalar
     * `post_type` and a `post_types` gate mean different things — a lookup key
     * versus a scope — and a helper taking either shape would have to guess
     * which one it was handed.
     *
     * @param mixed $post_type Single post-type slug.
     * @return string
     */
    public static function post_type_scope_label($post_type): string {
        $slug = is_string($post_type) ? $post_type : '';
        if ($slug === '') {
            return '';
        }

        $obj = \get_post_type_object($slug);

        return ' (' . ($obj ? $obj->label : $slug) . ')';
    }

    /**
     * Resolve a taxonomy slug to its label.
     *
     * Uses the (plural) `label` to match the "Tax: Term" shape produced by
     * ConfigHelpers::all_term_options() and the user-facing examples
     * (e.g. "Shakers").
     *
     * @param string $slug
     * @return string
     */
    public static function taxonomy_label(string $slug): string {
        if ($slug === '') {
            return '';
        }

        $tax = \get_taxonomy($slug);
        if (!$tax) {
            return '';
        }

        return $tax->label ?: $slug;
    }
}
