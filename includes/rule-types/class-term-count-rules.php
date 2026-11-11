<?php
/**
 * Descriptor for `term_count_rules` rows.
 *
 * @since 0.11.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\TermCountHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Term count limit — a RESTRICTING claim over a whole taxonomy: a post holds at
 * most N of its terms. Which terms survive is `Core\TermCountSurvivors`.
 *
 * It does not read `hierarchical_taxonomy_note`: a cap means the same thing on
 * a flat taxonomy, where every term is depth 0 and `per_level` changes nothing.
 * It has no *Keep ancestor terms* switch; a cap is a hard cap, and ancestors
 * come from an inheritance rule placed in the list.
 */
final class TermCountRules extends RuleType {

    public function type(): string {
        return 'term_count_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Restrict how many terms a post holds', 'meta-conductor');
    }

    public function handler_class(): string {
        return TermCountHandler::class;
    }

    public function target_key(array $rule): ?string {
        return self::taxonomy_target($rule);
    }

    /**
     * Schema:
     *   {Scope: }Limit {Taxonomy} to {N} term(s){ per level}
     *   e.g. "Limit Shakers to 2 terms per level"
     *        "Pages: Limit Shakers to 1 term"
     */
    public function row_title(array $row): string {
        $max = max(1, (int) ($row['max_terms'] ?? 1));

        $terms = sprintf(
            /* translators: %d: the cap */
            _n('%d term', '%d terms', $max, 'meta-conductor'),
            $max
        );

        return Labels::scope_prefix($row['post_types'] ?? []) . sprintf(
            self::per_level($row)
                /* translators: 1: taxonomy label 2: the cap, e.g. "2 terms" */
                ? __('Limit %1$s to %2$s per level', 'meta-conductor')
                /* translators: 1: taxonomy label 2: the cap, e.g. "2 terms" */
                : __('Limit %1$s to %2$s', 'meta-conductor'),
            Labels::taxonomy_label($row['taxonomy'] ?? ''),
            $terms
        );
    }

    /**
     * Whether the cap applies per tree depth. Absent reads as the default (on),
     * like the config; an explicit false — the stored form of a switched-off
     * toggle — does not.
     */
    public static function per_level(array $row): bool {
        return !array_key_exists('per_level', $row) || !empty($row['per_level']);
    }

    /**
     * The terms an outside write just added are what survive an over-cap post,
     * and `set_object_terms` is the last moment "added" can be told from "was
     * already there". The capture records into a request-lifetime memo and
     * applies nothing; the pass reads it.
     */
    public function capture_hooks(): array {
        return ['set_object_terms'];
    }

    public function reads_shared_fields(): array {
        return ['taxonomy', 'post_types'];
    }

    /**
     * @return array[]
     */
    public function subfields(): array {
        return [
            [
                'id'          => 'max_terms',
                'type'        => 'number',
                'label'       => __('Maximum terms', 'meta-conductor'),
                'description' => __('When a post holds more, the extra terms are removed. The terms you just added are kept first, then the deepest terms, then the lowest term ID.', 'meta-conductor'),
                'default'     => 1,
                'columns'     => 6,
                'args'        => ['integer' => true, 'min' => 1],
            ],
            [
                'id'          => 'per_level',
                'type'        => 'toggle',
                'label'       => __('Apply the cap to each level', 'meta-conductor'),
                'description' => __('Only matters for a hierarchical taxonomy: on, each tree depth may hold the maximum; off, the maximum counts every depth together. A flat taxonomy has one level, so it makes no difference.', 'meta-conductor'),
                'default'     => true,
                'columns'     => 6,
            ],
        ];
    }
}
