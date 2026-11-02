<?php
/**
 * Slug ladder harness (FW-51 02).
 *
 * `Tokens\SlugLadder::walk()` is pure, so the whole collision ladder — order,
 * skipping, cumulative candidates, the date start rule, where each part lands —
 * is pinned here on host PHP with a taken-slug list standing in for the
 * database. The handler's share (resolving rung values, the uniqueness lookup,
 * numbering) is a testbed sweep.
 *
 * Run:  php tests/verify-slug-ladder.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Tokens\SlugLadder;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

/** A rung of plain text. */
$val = static fn(string $v): array => ['value' => $v];
/** A date rung reaching down to `$part`. The date is 2026-03-14 09:05. */
$date = static fn(string $part, array $over = []): array => [
    'part'  => $part,
    'parts' => $over + ['year' => '2026', 'month' => '03', 'day' => '14', 'hour' => '09', 'minute' => '05'],
];
$none = ['year' => '', 'month' => '', 'day' => '', 'hour' => '', 'minute' => ''];

/**
 * Walk with `$taken` as the occupied slugs; returns [slug, candidates tried in order].
 */
$run = function (string $base, array $rungs, array $taken, string $placement = 'suffix') {
    $tried = [];
    $slug  = SlugLadder::walk($base, $rungs, $placement, function (string $c) use ($taken, &$tried) {
        $tried[] = $c;
        return !in_array($c, $taken, true);
    });
    return [$slug, $tried];
};

// --- No rungs, nothing to add. ----------------------------------------------

[$slug, $tried] = $run('event', [], ['event']);
$check('no rungs returns the base for the caller to number', $slug === 'event' && $tried === []);

// --- Non-date rungs: order, cumulative, placement. ---------------------------

[$slug, $tried] = $run('event', [$val('north'), $val('annex')], ['event', 'event-north']);
$check('rung order: the first free candidate wins', $slug === 'event-north-annex');
$check('candidates are cumulative, one rung at a time', $tried === ['event-north', 'event-north-annex']);

[$slug, $tried] = $run('event', [$val('north'), $val('annex')], []);
$check('the first rung alone is enough when it is free', $slug === 'event-north' && $tried === ['event-north']);

[$slug, $tried] = $run('event', [$val(''), $val('annex')], ['event']);
$check('an empty rung is skipped', $slug === 'event-annex' && $tried === ['event-annex']);

[$slug, $tried] = $run('event', [$val('north'), $val('')], ['event', 'event-north']);
$check('a trailing empty rung adds no candidate', $slug === 'event-north' && $tried === ['event-north']);

[$slug] = $run('event', [$val('north'), $val('annex')], ['event', 'event-north', 'event-north-annex']);
$check('past the last rung the final candidate is returned for numbering', $slug === 'event-north-annex');

[$slug] = $run('event', [$val(''), $val('')], ['event']);
$check('every rung empty returns the base', $slug === 'event');

[$slug, $tried] = $run('event', [$val('north'), $val('annex')], ['event', 'north-event'], 'prefix');
$check('prefix placement puts rungs before the slug, in rung order', $slug === 'north-annex-event');
$check('prefix: first candidate is rung 1 in front', $tried[0] === 'north-event');
$check('prefix: second candidate keeps rung order', ($tried[1] ?? '') === 'north-annex-event');

[$slug] = $run('event', [$val('north')], ['event'], 'sideways');
$check('an unknown placement is suffix', $slug === 'event-north');

[$slug] = $run('event', [$val('-north-')], ['event']);
$check('stray dashes on a rung value do not double up', $slug === 'event-north');

// --- Date rung, slug shows a year. ------------------------------------------

[$slug, $tried] = $run('event-2026', [$date('year')], ['event-2026']);
$check('slug shows year: first candidate is month, spliced after the year',
    $slug === 'event-2026-03' && $tried === ['event-2026-03']);

[$slug, $tried] = $run('event-2026', [$date('year')], ['event-2026', 'event-2026-03']);
$check('then day', $slug === 'event-2026-03-14' && $tried === ['event-2026-03', 'event-2026-03-14']);

[$slug, $tried] = $run('event-2026-extra', [$date('year')], ['event-2026-extra']);
$check('the splice lands next to the date, before what follows it', $slug === 'event-2026-03-extra');

[$slug, $tried] = $run('2026-event', [$date('year')], ['2026-event']);
$check('a date at the front splices after itself, not at the end', $slug === '2026-03-event');

[$slug, $tried] = $run('event-2026-03', [$date('year')], ['event-2026-03']);
$check('slug shows year-month: first candidate is day', $slug === 'event-2026-03-14' && $tried === ['event-2026-03-14']);

[$slug, $tried] = $run('event-2026-03-14-09-05', [$date('year')], ['event-2026-03-14-09-05']);
$check('slug already shows the finest part: the rung has nothing to add',
    $slug === 'event-2026-03-14-09-05' && $tried === []);

// --- The start rule: named part against what the slug shows. -----------------

[$slug, $tried] = $run('event-2026', [$date('day')], ['event-2026']);
$check('named part finer than the slug shows: start at the named part, text from the first unshown part',
    $slug === 'event-2026-03-14' && $tried === ['event-2026-03-14']);

[$slug, $tried] = $run('event-2026-03', [$date('month')], ['event-2026-03']);
$check('named part coarser than what the slug shows: start at the first finer part',
    $slug === 'event-2026-03-14' && $tried === ['event-2026-03-14']);

[$slug, $tried] = $run('event-2026', [$date('day')], ['event-2026', 'event-2026-03-14']);
$check('skipping ahead continues finer afterwards', $slug === 'event-2026-03-14-09' && $tried === ['event-2026-03-14', 'event-2026-03-14-09']);

// --- Anchorless dates. -------------------------------------------------------

[$slug, $tried] = $run('event', [$date('year')], ['event']);
$check('no date in the slug: start at the year, placement suffix',
    $slug === 'event-2026' && $tried === ['event-2026']);

[$slug, $tried] = $run('event', [$date('year')], ['event', 'event-2026']);
$check('anchorless date continues month, joined to the year', $slug === 'event-2026-03');

[$slug] = $run('event', [$date('year')], ['event'], 'prefix');
$check('anchorless date follows prefix placement', $slug === '2026-event');

[$slug, $tried] = $run('event', [$date('day')], ['event']);
$check('anchorless date named day: starts at day but still reads year-month-day',
    $slug === 'event-2026-03-14');

[$slug] = $run('event-26', [$date('year')], ['event-26']);
$check('a year the slug does not show is anchorless', $slug === 'event-26-2026');

[$slug] = $run('event-20260', [$date('year')], ['event-20260']);
$check('digits that merely contain the year are not an anchor', $slug === 'event-20260-2026');

$dateonly = ['year' => '2026', 'month' => '03', 'day' => '14', 'hour' => '', 'minute' => ''];
[$slug, $tried] = $run('event-2026', [$date('hour', $dateonly)], ['event-2026']);
$check('a named part finer than the date has starts at the finest part there is',
    $slug === 'event-2026-03-14' && $tried === ['event-2026-03-14']);

// --- A date missing or unusable. --------------------------------------------

[$slug, $tried] = $run('event', [$date('year', $none)], ['event']);
$check('a date rung with no date is skipped', $slug === 'event' && $tried === []);

[$slug, $tried] = $run('event', [$date('year', $none), $val('north')], ['event']);
$check('a skipped date rung leaves the next rung to try', $slug === 'event-north');

[$slug, $tried] = $run('event', [['part' => 'decade', 'parts' => $date('year')['parts']]], ['event']);
$check('a part that is not a date part is skipped', $slug === 'event' && $tried === []);

// --- Mixed ladders: a date rung commits its finest candidate. ---------------

[$slug, $tried] = $run('event-2026', [$date('year'), $val('north')],
    ['event-2026', 'event-2026-03', 'event-2026-03-14', 'event-2026-03-14-09', 'event-2026-03-14-09-05']);
$check('after a date rung exhausts, the next rung builds on its finest candidate',
    $slug === 'event-2026-03-14-09-05-north');

[$slug, $tried] = $run('event-2026', [$val('north'), $date('year')], ['event-2026', 'event-2026-north']);
$check('rung then date: the rung is tried alone first, then the date splices next to the year with it kept',
    $slug === 'event-2026-03-north' && $tried === ['event-2026-north', 'event-2026-03-north']);

[$slug, $tried] = $run('event-2026', [$val('north'), $date('year')], ['event-2026', 'north-event-2026'], 'prefix');
$check('same, prefix placement', $slug === 'north-event-2026-03' && $tried === ['north-event-2026', 'north-event-2026-03']);

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nSLUG-LADDER FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "SLUG-LADDER OK — all $total assertions passed (order, skip, cumulative, date start rule, splice, placement; FW-51 02).\n");
exit(0);
