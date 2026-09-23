<?php
/**
 * Existing-posts applier — a rule's pass over the posts that predate it.
 *
 * @since 0.9.0
 */

namespace BWS\MetaConductor\Core;

use BWS\MetaConductor\Admin\CollisionDetector;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs the full ordered pass a save would run over every post in a chosen
 * rule's reach, and reports which posts it changed.
 *
 * A PROVOCATION, NOT A PASS. It names posts to the term dispatcher —
 * `drain_post()` for each, then one `drain()` — and never calls an applier, a
 * dispatcher's `apply()` or a write primitive. That is what makes "bulk and
 * save cannot disagree" true by construction: there is only one pass, and this
 * is one more way of asking for it. H13 group 13 holds the body to it.
 *
 * WHY THE WHOLE LIST, NOT THE CHOSEN RULE. Order is the composition mechanism
 * (CONTEXT.md → Order): a rule's result can depend on what the rows before it
 * wrote, and a later row may consume it. Running one row alone would produce a
 * state no save ever produces. The choice decides WHICH posts are passed (its
 * reach); the pass decides what happens to them.
 *
 * A DISABLED CHOICE is a one-time run: the dispatcher's request-scoped
 * override includes the row at its authored position for this batch only,
 * cleared in `finally` so it cannot leak into a save later in the request. The
 * stored row is never written, so a later save neither re-applies nor undoes
 * what the run did.
 *
 * Takes a rule choice (`RuleChoice` value), not a page request, so the Apply
 * page and any later entry point share it. Knows nothing about Wireframe.
 */
final class ExistingPostsApplier {

    /** Changed posts a report lists; the count covers the rest. */
    public const REPORT_ROWS = 20;

    /**
     * Pass every post in the choice's reach.
     *
     * Refuses — writing nothing — when the choice is malformed or stale, or
     * when either pass's off switch would refuse any post in reach: a batch of
     * no-op passes reported as done is the "lying button" #31 removed.
     *
     * No revision per post: term writes create none, and the format pass
     * suppresses the one its row update would create.
     *
     * @param string $value A `RuleChoice` dropdown value.
     * @return array `{status: 'success'|'error', message}`, plus on success
     *               `processed`, `changed`, `rows` (the first REPORT_ROWS
     *               changes, see diff()) and `note`.
     */
    public static function run_batch(string $value): array {
        $choice = RuleChoice::decode($value);
        if ($choice === null) {
            return self::error(__('Unknown rule choice — reload the page.', 'meta-conductor'));
        }

        $terms  = TermDispatcher::instance();
        $format = FormatDispatcher::instance();
        if ($terms === null) {
            return self::error(__('Rule passes are not running on this request, so nothing was applied.', 'meta-conductor'));
        }

        $storage = StorageFactory::get_instance();
        $row     = null;
        if ($choice['all']) {
            $rows = array_merge(
                RuleChoice::pass_rows($storage->get_kind_rules(OptionRuleStorage::KIND_TERM), null),
                RuleChoice::pass_rows($storage->get_kind_rules(OptionRuleStorage::KIND_FORMAT), null)
            );
            if ($rows === []) {
                return self::error(__('There are no enabled rules to apply.', 'meta-conductor'));
            }
        } else {
            $row = RuleChoice::resolve($choice, $storage->get_kind_rules($choice['kind']));
            if ($row === null) {
                return self::error(__('Rules changed since this page loaded — reload.', 'meta-conductor'));
            }
            $rows = [$row];
        }

        $post_ids = self::reach($rows, RuleChoice::reach_statuses($row));

        foreach ($post_ids as $post_id) {
            if (!$terms->pass_enabled($post_id) || ($format !== null && !$format->pass_enabled($post_id))) {
                return self::error(__('Rule passes are switched off (an import, a filter or the fixture seeder), so nothing was applied.', 'meta-conductor'));
            }
        }

        $changed = 0;
        $report  = [];

        try {
            if ($row !== null) {
                if ($choice['kind'] === FormatDispatcher::KIND) {
                    FormatDispatcher::include_row($choice['fingerprint']);
                } else {
                    TermDispatcher::include_row($choice['fingerprint']);
                }
            }

            foreach ($post_ids as $post_id) {
                $before = self::snapshot($post_id);
                $terms->drain_post($post_id);
                $diff = self::diff($post_id, $before, self::snapshot($post_id));

                if ($diff !== null) {
                    $changed++;
                    if (count($report) < self::REPORT_ROWS) {
                        $report[] = $diff;
                    }
                }
            }

            // Fan-out and capture marks the passes raised, so children and
            // dependents settle before the batch reports.
            $terms->drain();
        } finally {
            TermDispatcher::clear_included_row();
            FormatDispatcher::clear_included_row();
        }

        return [
            'status'    => 'success',
            'message'   => sprintf(
                /* translators: 1: posts processed, 2: posts changed */
                __('%1$d posts processed, %2$d changed.', 'meta-conductor'),
                count($post_ids),
                $changed
            ),
            'processed' => count($post_ids),
            'changed'   => $changed,
            'rows'      => $report,
            'note'      => __('Changes the run made to other posts through fan-out (children, dependents) are not listed.', 'meta-conductor'),
        ];
    }

    /**
     * Post IDs in the reach of these rows, ascending.
     *
     * Post types are each row's `CollisionDetector::written_post_types()` —
     * empty means every public type. Deliberately conservative (CONTEXT.md →
     * Reach): a post the rule's own gate then skips costs a no-op pass.
     *
     * @param array[]  $rows     Projected rows.
     * @param string[] $statuses `RuleChoice::reach_statuses()`.
     * @return int[]
     */
    private static function reach(array $rows, array $statuses): array {
        // [] reaches nothing; handed to get_posts it would read as "publish".
        if ($statuses === []) {
            return [];
        }

        $types = [];
        foreach ($rows as $rule) {
            $written = CollisionDetector::written_post_types((string) ($rule['type'] ?? ''), $rule);
            if ($written === []) {
                $types = get_post_types(['public' => true]);
                break;
            }
            $types = array_merge($types, $written);
        }

        return array_map('intval', get_posts([
            'post_type'   => array_values(array_unique($types)),
            'post_status' => $statuses,
            'numberposts' => -1,
            'fields'      => 'ids',
            'orderby'     => 'ID',
            'order'       => 'ASC',
        ]));
    }

    /**
     * What the report compares: title, slug, and the terms (id => name) the
     * post holds in each of its type's taxonomies.
     *
     * @param int $post_id
     * @return array|null Null when the post is gone.
     */
    private static function snapshot(int $post_id): ?array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return null;
        }

        $terms = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $held             = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'id=>name']);
            $terms[$taxonomy] = is_wp_error($held) ? [] : $held;
        }

        return ['title' => $post->post_title, 'slug' => $post->post_name, 'terms' => $terms];
    }

    /**
     * One report row, or null when nothing changed.
     *
     * @param int        $post_id
     * @param array|null $before snapshot() before the pass.
     * @param array|null $after  snapshot() after it.
     * @return array|null `{post_id, title: [before, after]|null, slug:
     *                    [before, after]|null, terms: {taxonomy: {added:
     *                    names[], removed: names[]}}}`.
     */
    private static function diff(int $post_id, ?array $before, ?array $after): ?array {
        if ($before === null || $after === null) {
            return null;
        }

        $row = ['post_id' => $post_id, 'title' => null, 'slug' => null, 'terms' => []];
        foreach (['title', 'slug'] as $field) {
            if ($before[$field] !== $after[$field]) {
                $row[$field] = [$before[$field], $after[$field]];
            }
        }
        foreach (array_keys($after['terms'] + $before['terms']) as $taxonomy) {
            $old     = $before['terms'][$taxonomy] ?? [];
            $new     = $after['terms'][$taxonomy] ?? [];
            $added   = array_values(array_diff_key($new, $old));
            $removed = array_values(array_diff_key($old, $new));
            if ($added || $removed) {
                $row['terms'][$taxonomy] = ['added' => $added, 'removed' => $removed];
            }
        }

        return $row['title'] === null && $row['slug'] === null && $row['terms'] === [] ? null : $row;
    }

    /**
     * @param string $message
     * @return array
     */
    private static function error(string $message): array {
        return ['status' => 'error', 'message' => $message];
    }
}
