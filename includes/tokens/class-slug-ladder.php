<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * The disambiguation ladder: given a slug that is already taken, the first free
 * candidate built by adding the rule's rungs one at a time.
 *
 * PURE. No database, no WordPress, no token engine — the handler resolves the
 * rung values and owns the uniqueness lookup, and hands both in. That is what
 * lets `tests/verify-slug-ladder.php` pin ordering, skipping and placement on
 * host PHP.
 *
 * Candidate N is the base slug plus rungs 1..N. A rung that resolves empty is
 * skipped. After the last rung there is nothing left to try and the walk
 * returns its final candidate, for the caller's numbering to finish.
 *
 * TWO KINDS OF RUNG.
 *   - `['value' => 'x']` — any non-date rung. Goes to the placement edge.
 *   - `['part' => 'day', 'parts' => ['year' => '2026', 'month' => '03', …]]` —
 *     a date rung. `part` is the finest precision it may SKIP AHEAD to; `parts`
 *     are the date's values (empty strings for a missing date). It is a small
 *     ladder of its own, one part finer at each step (month, month-day,
 *     month-day-hour …), and it commits its finest candidate before the next
 *     rung is added.
 *
 * WHERE A DATE GOES. Next to the date the slug already holds, because
 * `event-2026-03` should become `event-2026-03-14`, not `14-event-2026-03`.
 * What "already holds" means is read off the slug itself — the longest run of
 * the date's own parts, year first, that appears in it — so the pattern never
 * needs consulting. The walk starts at the later of the rung's named part and
 * the first part finer than the slug shows; a slug showing no date starts at
 * the year, and a date with nothing to splice against follows the placement.
 *
 * ponytail: only the first date rung looks for an anchor; a second one goes to
 * the placement edge. Two date rungs are not a case anyone has asked for.
 */
final class SlugLadder {

    public const PARTS = ['year', 'month', 'day', 'hour', 'minute'];

    /**
     * @param string   $base      A slug already known to be taken.
     * @param array[]  $rungs     Resolved rungs, in order (see the class doc).
     * @param string   $placement `prefix` or `suffix`; anything else is `suffix`.
     * @param callable $is_unique string -> bool, the caller's uniqueness lookup.
     * @return string The first unique candidate, else the last one tried (the base when no rung produced one).
     */
    public static function walk(string $base, array $rungs, string $placement, callable $is_unique): string {
        $prefix = $placement === 'prefix';
        $before = [];     // edge parts, in rung order
        $after  = [];
        $splice = null;   // [byte offset in $base, date text]
        $found  = $base;

        $build = static function (array $before, array $after, ?array $splice) use ($base): string {
            $core = $splice === null ? $base : substr($base, 0, $splice[0]) . '-' . $splice[1] . substr($base, $splice[0]);
            return implode('-', [...$before, $core, ...$after]);
        };

        foreach ($rungs as $rung) {
            if (!isset($rung['parts'])) {
                $value = trim((string) ($rung['value'] ?? ''), '-');
                if ($value === '') {
                    continue;
                }
                $prefix ? $before[] = $value : $after[] = $value;
                $found = $build($before, $after, $splice);
                if ($is_unique($found)) {
                    return $found;
                }
                continue;
            }

            $named = array_search($rung['part'] ?? '', self::PARTS, true);
            $texts = [];
            foreach (self::PARTS as $part) {
                if (($rung['parts'][$part] ?? '') === '') {
                    break;
                }
                $texts[] = (string) $rung['parts'][$part];
            }
            if ($named === false || !$texts) {
                continue; // not a date part, or no date to read
            }

            [$shown, $end] = $splice === null ? self::shown($base, $texts) : [0, null];
            $from          = $end === null ? 0 : $shown;
            $last          = null;
            // A rung naming a part finer than the date has (hour on a date-only
            // value) starts at the finest part there is; a slug that already shows
            // every part leaves nothing to add.
            $start = $from < count($texts) ? min(max($named, $from), count($texts) - 1) : count($texts);
            for ($i = $start; $i < count($texts); $i++) {
                $text = implode('-', array_slice($texts, $from, $i - $from + 1));
                $b    = $before;
                $a    = $after;
                $s    = $splice;
                if ($end === null) {
                    $prefix ? $b[] = $text : $a[] = $text;
                } else {
                    $s = [$end, $text];
                }
                $found = $build($b, $a, $s);
                if ($is_unique($found)) {
                    return $found;
                }
                $last = [$b, $a, $s];
            }
            if ($last !== null) {
                [$before, $after, $splice] = $last; // commit the finest candidate
            }
        }

        return $found;
    }

    /**
     * How much of the date the slug already shows.
     *
     * @param string   $slug  Base slug.
     * @param string[] $texts Date part texts, year first, no gaps.
     * @return array{0: int, 1: ?int} Count of parts shown, and the byte offset just past them (null when none).
     */
    private static function shown(string $slug, array $texts): array {
        for ($n = count($texts); $n > 0; $n--) {
            $run = implode('-', array_slice($texts, 0, $n));
            if (preg_match('/(?<![0-9])' . preg_quote($run, '/') . '(?![0-9])/', $slug, $m, PREG_OFFSET_CAPTURE)) {
                return [$n, $m[0][1] + strlen($run)];
            }
        }
        return [0, null];
    }
}
