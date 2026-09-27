<?php
/**
 * Descriptor for `time_based_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Handlers\TimeBasedHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `time_based_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class TimeBasedRules extends RuleType {

    public function type(): string {
        return 'time_based_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Date window — apply a term while a date range is current', 'meta-conductor');
    }

    public function handler_class(): string {
        return TimeBasedHandler::class;
    }

    /**
     * Date-first — the window is the most salient part of a manually
     * configured date rule — then a sentence:
     *   {start}–{end}: Apply {target} to {scope}{ with {filter}}
     *   - dates joined by an en dash, no surrounding spaces.
     *   - scope = "posts" (all types) or the post-type labels (when restricted).
     *   - filter clause only when set: specific terms → "with {Term, …}";
     *     else taxonomies → "with any {Taxonomy} term"; neither → omitted.
     *   e.g. "2026-05-26–2026-05-27: Apply Shakers: Grandchild ii to posts"
     *        "2026-05-26–2026-05-27: Apply … to Pages with Breakers: Term A"
     */
    public function row_title(array $row): string {
        $start  = (string) ($row['start_date'] ?? '');
        $end    = (string) ($row['end_date'] ?? '');
        $target = Labels::term_label($row['target_term_id'] ?? 0);

        // en dash, no surrounding spaces.
        $window = ($start !== '' || $end !== '') ? $start . "\xE2\x80\x93" . $end . ': ' : '';

        $sentence = sprintf(
            /* translators: 1: target term 2: post-type scope phrase */
            __('Apply %1$s to %2$s', 'meta-conductor'),
            $target !== '' ? $target : __('(no term)', 'meta-conductor'),
            self::scope_phrase($row['post_types'] ?? [])
        );

        return $window . $sentence . self::filter_clause($row);
    }

    /**
     * Scope phrase for the title's "to …" clause: "posts" when the rule
     * applies to all post types (empty post_types), else the human post-type
     * labels ("Pages", "Posts, Pages"). Unescaped.
     *
     * @param string[] $post_types Slug list.
     * @return string
     */
    private static function scope_phrase(array $post_types): string {
        $labels = Labels::post_type_labels($post_types);
        return empty($labels) ? __('posts', 'meta-conductor') : implode(', ', $labels);
    }

    /**
     * Filter clause for the title: " with {specific terms}" when filter_terms
     * is set; else " with any {taxonomy} term" when filter_taxonomies is set;
     * else '' (no filter). Unescaped.
     *
     * @param array $row
     * @return string
     */
    private static function filter_clause(array $row): string {
        $terms = Labels::trigger_terms_label($row['filter_terms'] ?? []);
        if ($terms !== '') {
            return ' ' . sprintf(__('with %s', 'meta-conductor'), $terms);
        }

        $labels = [];
        foreach ($row['filter_taxonomies'] ?? [] as $slug) {
            $label = Labels::taxonomy_label((string) $slug);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        if (!empty($labels)) {
            return ' ' . sprintf(__('with any %s term', 'meta-conductor'), implode(', ', $labels));
        }

        return '';
    }

    public function reads_shared_fields(): array {
        return ['post_types', 'target_term_id'];
    }

    /**
     * Date window — apply a term while the current date is inside a range.
     *
     * @return array[]
     */
    public function subfields(): array {
        return [
            [
                'id'          => 'filter_taxonomies',
                'type'        => 'checkboxes',
                'label'       => __('Filter by taxonomies (optional)', 'meta-conductor'),
                'description' => __('Only apply to posts with terms in these taxonomies. Leave empty for all posts.', 'meta-conductor'),
                'columns'     => 12,
                'args'        => [
                    'options' => ConfigHelpers::taxonomy_checkbox_options(),
                ],
            ],
            [
                'id'          => 'filter_terms',
                'type'        => 'select',
                'label'       => __('Filter by specific terms (optional)', 'meta-conductor'),
                'description' => __('Only apply to posts with these terms. Leave empty to use taxonomy filter only.', 'meta-conductor'),
                'columns'     => 12,
                'args'        => [
                    'multiple' => true,
                    'options'  => ConfigHelpers::all_term_options(),
                ],
            ],
            [
                'id'         => 'start_date',
                'type'       => 'date',
                'label'      => __('Start date', 'meta-conductor'),
                'required'   => true,
                'columns'    => 12,
            ],
            [
                'id'         => 'end_date',
                'type'       => 'date',
                'label'      => __('End date', 'meta-conductor'),
                'required'   => true,
                'columns'    => 12,
            ],
            // target_term_id — shared with the related-term rule — is a
            // shared subfield, listed in reads_shared_fields().
        ];
    }
}
