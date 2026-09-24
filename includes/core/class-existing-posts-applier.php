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
 * A RUN SPANS REQUESTS. Each batch stops at a post boundary once the time box
 * has elapsed, and its state (cursor, counts, report sample, limit) waits in a
 * transient keyed by user + choice value for the next Continue. The cursor is
 * the last post ID passed, never an offset, so posts created, trashed or
 * edited between batches neither shift nor duplicate the sequence. The choice
 * value carries the row's fingerprint, so an edited rule starts a fresh run.
 *
 * A PREVIEW WRITES NOTHING. It never drains; a format rule's result comes from
 * `FormatDispatcher::compute()`, the pass minus its write.
 *
 * Takes a rule choice (`RuleChoice` value), not a page request, so the Apply
 * page and any later entry point share it. Knows nothing about Wireframe.
 */
final class ExistingPostsApplier {

    /** Changed posts a report lists; the count covers the rest. */
    public const REPORT_ROWS = 20;

    /** Seconds a batch runs before it stops at the next post boundary. */
    public const TIME_BOX = 20;

    /** Posts a format preview computes. */
    public const PREVIEW_SAMPLE = 5;

    /** Posts a term preview lists. */
    public const PREVIEW_POSTS = 10;

    /**
     * Pass the next batch of posts in the choice's reach.
     *
     * Starts a run when none is in progress for this user and choice, else
     * resumes the one that is. Refuses — writing nothing — when the choice is
     * malformed or stale, or when either pass's off switch would refuse any
     * post left in the run: a batch of no-op passes reported as done is the
     * "lying button" #31 removed.
     *
     * No revision per post: term writes create none, and the format pass
     * suppresses the one its row update would create.
     *
     * @param string $value A `RuleChoice` dropdown value.
     * @param int    $limit Posts the run stops after, 0 for all. Read when a
     *                      run starts; a Continue keeps the run's own limit.
     * @return array `{status: 'success'|'error', message}`, plus on success
     *               the run's `processed` / `total`, `done`, `changed`, `rows`
     *               (its first REPORT_ROWS changes, see diff()) and `note`.
     */
    public static function run_batch(string $value, int $limit = 0): array {
        $chosen = self::choose($value);
        if (isset($chosen['status'])) {
            return $chosen;
        }
        ['choice' => $choice, 'row' => $row, 'rows' => $rows] = $chosen;

        $terms  = TermDispatcher::instance();
        $format = FormatDispatcher::instance();
        if ($terms === null) {
            return self::error(__('Rule passes are not running on this request, so nothing was applied.', 'meta-conductor'));
        }

        $key   = self::state_key($value);
        $state = get_transient($key);
        $fresh = !is_array($state);
        if ($fresh) {
            $state = ['cursor' => 0, 'processed' => 0, 'total' => 0, 'changed' => 0, 'rows' => [], 'limit' => max(0, $limit)];
        }

        // A stored run is always short of its limit: reaching it completes
        // the run, and completion deletes the state.
        $post_ids = self::reach(
            $rows,
            RuleChoice::reach_statuses($row),
            $state['cursor'],
            $state['limit'] > 0 ? $state['limit'] - $state['processed'] : 0
        );
        if ($fresh) {
            $state['total'] = count($post_ids);
        }

        // ponytail: gates the run's whole remaining tail, outside the time box
        // (one ID fetch + one filter call per post). Chunk the fetch if a
        // reach ever grows large enough for that to matter.
        foreach ($post_ids as $post_id) {
            if (!$terms->pass_enabled($post_id) || ($format !== null && !$format->pass_enabled($post_id))) {
                return self::error(__('Rule passes are switched off (an import, a filter or the fixture seeder), so nothing was applied.', 'meta-conductor'));
            }
        }

        $deadline = microtime(true) + (float) apply_filters('meta_conductor_apply_time_box', self::TIME_BOX);
        $done     = true;

        try {
            if ($row !== null) {
                if ($choice['kind'] === FormatDispatcher::KIND) {
                    FormatDispatcher::include_row($choice['fingerprint']);
                } else {
                    TermDispatcher::include_row($choice['fingerprint']);
                }
            }

            foreach ($post_ids as $i => $post_id) {
                // Between posts only, and never before the first: a batch
                // always moves the run on.
                if ($i > 0 && microtime(true) >= $deadline) {
                    $done = false;
                    break;
                }

                $before = self::snapshot($post_id);
                $terms->drain_post($post_id);
                $diff = self::diff($post_id, $before, self::snapshot($post_id));

                $state['cursor'] = $post_id;
                $state['processed']++;
                if ($diff !== null) {
                    $state['changed']++;
                    if (count($state['rows']) < self::REPORT_ROWS) {
                        $state['rows'][] = $diff;
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

        if ($done) {
            delete_transient($key);
        } else {
            set_transient($key, $state, DAY_IN_SECONDS);
        }

        return [
            'status'    => 'success',
            'message'   => sprintf(
                /* translators: 1: posts processed so far, 2: posts in the run, 3: posts changed */
                __('%1$d / %2$d posts processed, %3$d changed.', 'meta-conductor'),
                $state['processed'],
                $state['total'],
                $state['changed']
            ),
            'processed' => $state['processed'],
            'total'     => $state['total'],
            'done'      => $done,
            'changed'   => $state['changed'],
            'rows'      => $state['rows'],
            'note'      => __('Changes the run made to other posts through fan-out (children, dependents) are not listed.', 'meta-conductor'),
        ];
    }

    /**
     * What a run on this choice would touch, writing nothing.
     *
     * A format rule's result is shown, not described: `FormatDispatcher::compute()`
     * over the newest PREVIEW_SAMPLE posts in reach, with a disabled chosen row
     * included exactly as a run includes it — so a disabled title/slug row
     * ordered before an enabled one wins first match here as it would in the
     * run. A term rule has no dry run (FW-32), so it gets the size of the run
     * and the posts it would start from. "All enabled rules" gets the size,
     * plus the format sample when any format rule is enabled.
     *
     * @param string $value A `RuleChoice` dropdown value.
     * @return array `{status: 'success'|'error', message}`, plus on success
     *               `total` (posts in reach), `posts` (term choice: the newest
     *               PREVIEW_POSTS as `{post_id, title, edit_link}`), `sample`
     *               (format: `{post_id, title: [current, result], slug:
     *               [current, result]}`) and `note` ('' without a sample).
     */
    public static function preview(string $value): array {
        $chosen = self::choose($value);
        if (isset($chosen['status'])) {
            return $chosen;
        }
        ['choice' => $choice, 'row' => $row, 'rows' => $rows] = $chosen;

        $statuses = RuleChoice::reach_statuses($row);
        $post_ids = self::reach($rows, $statuses, 0, 0);
        $result   = [
            'status'  => 'success',
            'message' => sprintf(
                /* translators: %d: posts a run would pass */
                _n('%d post in reach.', '%d posts in reach.', count($post_ids), 'meta-conductor'),
                count($post_ids)
            ),
            'total'   => count($post_ids),
            'posts'   => [],
            'sample'  => [],
            'note'    => '',
        ];

        if ($row !== null && $choice['kind'] === OptionRuleStorage::KIND_TERM) {
            foreach (array_slice(array_reverse($post_ids), 0, self::PREVIEW_POSTS) as $post_id) {
                $result['posts'][] = [
                    'post_id'   => $post_id,
                    'title'     => get_the_title($post_id),
                    'edit_link' => (string) get_edit_post_link($post_id, 'raw'),
                ];
            }
            return $result;
        }

        $format_rows = array_values(array_filter($rows, fn(array $rule): bool => FormatDispatcher::owns((string) ($rule['type'] ?? ''))));
        if ($format_rows === []) {
            return $result;
        }
        $format = FormatDispatcher::instance();
        if ($format === null) {
            return self::error(__('Rule passes are not running on this request, so nothing can be previewed.', 'meta-conductor'));
        }

        try {
            if ($row !== null) {
                FormatDispatcher::include_row($choice['fingerprint']);
            }

            // For "All enabled rules" the sample is the format rules' own
            // reach: a post only a term rule reaches would show no change.
            $sample_ids = $row !== null ? $post_ids : self::reach($format_rows, $statuses, 0, 0);
            foreach (array_slice(array_reverse($sample_ids), 0, self::PREVIEW_SAMPLE) as $post_id) {
                $computed = $format->compute($post_id);
                if ($computed === null) {
                    continue;
                }
                $result['sample'][] = [
                    'post_id' => $post_id,
                    'title'   => [$computed['before']['post_title'], $computed['after']['post_title']],
                    'slug'    => [$computed['before']['post_name'], $computed['after']['post_name']],
                ];
            }
        } finally {
            FormatDispatcher::clear_included_row();
        }

        if ($result['sample'] === []) {
            return $result;
        }
        $result['note'] = __('Slugs are checked against posts as stored now, not against each other, so a run may number a slug differently. Terms are read as the posts hold them now; a run passes term rules first, and a pattern that reads terms sees their result.', 'meta-conductor');

        return $result;
    }

    /**
     * Decode a choice and re-read the rows it names.
     *
     * @param string $value A `RuleChoice` dropdown value.
     * @return array `{choice, row, rows}` — `row` the chosen row, null for "All
     *               enabled rules"; `rows` those whose reach the choice covers —
     *               or an error result when the choice is malformed or stale.
     */
    private static function choose(string $value): array {
        $choice = RuleChoice::decode($value);
        if ($choice === null) {
            return self::error(__('Unknown rule choice — reload the page.', 'meta-conductor'));
        }

        $storage = StorageFactory::get_instance();
        if ($choice['all']) {
            $rows = array_merge(
                RuleChoice::pass_rows($storage->get_kind_rules(OptionRuleStorage::KIND_TERM), null),
                RuleChoice::pass_rows($storage->get_kind_rules(OptionRuleStorage::KIND_FORMAT), null)
            );
            if ($rows === []) {
                return self::error(__('There are no enabled rules.', 'meta-conductor'));
            }
            return ['choice' => $choice, 'row' => null, 'rows' => $rows];
        }

        $row = RuleChoice::resolve($choice, $storage->get_kind_rules($choice['kind']));
        if ($row === null) {
            return self::error(__('Rules changed since this page loaded — reload the page and choose the rule again.', 'meta-conductor'));
        }

        return ['choice' => $choice, 'row' => $row, 'rows' => [$row]];
    }

    /**
     * Discard this user's run of a choice, so the next batch starts from the
     * first post.
     *
     * @param string $value A `RuleChoice` dropdown value.
     * @return void
     */
    public static function start_over(string $value): void {
        delete_transient(self::state_key($value));
    }

    /**
     * Whether this user has a run of a choice waiting for its next batch.
     *
     * @param string $value A `RuleChoice` dropdown value.
     * @return bool
     */
    public static function in_progress(string $value): bool {
        return is_array(get_transient(self::state_key($value)));
    }

    /**
     * @param string $value A `RuleChoice` dropdown value.
     * @return string Transient name for this user's run of it.
     */
    private static function state_key(string $value): string {
        return 'mc_apply_run_' . md5(get_current_user_id() . '|' . $value);
    }

    /**
     * Post IDs in the reach of these rows, ascending from the cursor.
     *
     * Post types are each row's `CollisionDetector::written_post_types()` —
     * empty means every public type. Deliberately conservative (CONTEXT.md →
     * Reach): a post the rule's own gate then skips costs a no-op pass.
     *
     * @param array[]  $rows     Projected rows.
     * @param string[] $statuses `RuleChoice::reach_statuses()`.
     * @param int      $after    Cursor: only IDs above it.
     * @param int      $limit    At most this many, 0 for all.
     * @return int[]
     */
    private static function reach(array $rows, array $statuses, int $after, int $limit): array {
        // [] reaches nothing (and `IN ()` is not SQL).
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

        global $wpdb;
        $types = array_values(array_unique($types));
        $sql   = sprintf(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s) AND post_status IN (%s) AND ID > %%d ORDER BY ID ASC",
            implode(',', array_fill(0, count($types), '%s')),
            implode(',', array_fill(0, count($statuses), '%s'))
        );
        $args = array_merge($types, $statuses, [$after]);
        if ($limit > 0) {
            $sql   .= ' LIMIT %d';
            $args[] = $limit;
        }

        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $args)));
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
