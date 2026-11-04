<?php
/**
 * Term count survivors harness (term-count-limit 01).
 *
 * `Core\TermCountSurvivors` is pure, so its ordering contract (delta, then
 * deepest, then lowest ID; per level or whole set) is pinned here as a golden
 * table on host PHP, no WordPress.
 *
 * Run:  php tests/verify-term-count-survivors.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Core\TermCountSurvivors;

$fail  = [];
$total = 0;

/** Term list from `id => depth`. */
$t = static function (array $map): array {
    $out = [];
    foreach ($map as $id => $depth) {
        $out[] = ['id' => $id, 'depth' => $depth];
    }
    return $out;
};

// name, terms (id => depth), delta, max, per_level, expected surviving IDs in order.
$table = [
    ['delta alone, N=1',                  [5 => 0, 9 => 0],               [9],       1, true,  [9]],
    ['delta alone beats a deeper older term', [5 => 2, 9 => 0],           [9],       1, false, [9]],
    ['delta larger than N: deepest then lowest ID inside it', [1 => 0, 2 => 1, 3 => 1, 4 => 0], [2, 3, 4], 2, false, [2, 3]],
    ['delta larger than N, equal depth: lowest ID', [7 => 0, 3 => 0, 5 => 0], [7, 3, 5], 2, false, [3, 5]],
    ['delta fills part of N, rest by fallback', [1 => 0, 2 => 1, 3 => 2, 4 => 0], [4], 3, false, [4, 3, 2]],
    ['no delta: deepest wins',            [1 => 0, 2 => 1, 3 => 2],       [],        1, false, [3]],
    ['deep term beats an older ancestor', [1 => 0, 8 => 1],               [],        1, false, [8]],
    ['no delta, same depth: lowest ID',   [6 => 0, 4 => 0, 9 => 0],       [],        1, false, [4]],
    ['per level on: one per depth',       [1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 2], [], 1, true, [5, 3, 1]],
    ['per level off: whole set',          [1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 2], [], 1, false, [5]],
    ['per level on, N=2',                 [1 => 0, 2 => 0, 3 => 0, 4 => 1], [],      2, true,  [4, 1, 2]],
    ['per level on, delta picks within its depth', [1 => 0, 2 => 0, 3 => 1, 4 => 1], [2], 1, true, [2, 3]],
    ['per level off, delta in one depth', [1 => 0, 2 => 0, 3 => 1, 4 => 1], [2],    2, false, [2, 3]],
    ['flat input: per level on = off',    [4 => 0, 2 => 0, 8 => 0],       [8],       2, true,  [8, 2]],
    ['flat input, per level off',         [4 => 0, 2 => 0, 8 => 0],       [8],       2, false, [8, 2]],
    ['N larger than the set',             [3 => 0, 1 => 1],               [],        5, false, [1, 3]],
    ['N larger than the set, per level',  [3 => 0, 1 => 1],               [],        5, true,  [1, 3]],
    ['empty set',                         [],                             [],        1, true,  []],
    ['empty set with a delta',            [],                             [9],       2, false, []],
    ['delta id not in the set is ignored', [1 => 0, 2 => 0],              [99],      1, false, [1]],
    ['N=1 sole term',                     [7 => 3],                       [],        1, true,  [7]],
    ['N>1 whole set under cap untouched', [2 => 0, 1 => 0],               [],        3, false, [1, 2]],
    ['max below 1 acts as 1',             [2 => 0, 1 => 0],               [],        0, false, [1]],
];

foreach ($table as [$name, $map, $delta, $max, $per_level, $want]) {
    $total++;
    $got = array_column(TermCountSurvivors::select($t($map), $delta, $max, $per_level), 'id');
    if ($got !== $want) {
        $fail[] = "$name: want [" . implode(',', $want) . '] got [' . implode(',', $got) . ']';
    }
}

// Deterministic and independent of the input array's order.
$map = [5 => 1, 3 => 0, 9 => 1, 2 => 0, 7 => 2, 1 => 2];
foreach ([true, false] as $per_level) {
    foreach ([[], [3, 9]] as $delta) {
        $total++;
        $base = TermCountSurvivors::select($t($map), $delta, 2, $per_level);
        $same = TermCountSurvivors::select($t($map), $delta, 2, $per_level) === $base;
        $rev  = TermCountSurvivors::select(array_reverse($t($map)), $delta, 2, $per_level) === $base;
        $keys = array_keys($map);
        shuffle($keys);
        $shuf = [];
        foreach ($keys as $k) { $shuf[$k] = $map[$k]; }
        $mixed = TermCountSurvivors::select($t($shuf), array_reverse($delta), 2, $per_level) === $base;
        if (!($same && $rev && $mixed)) {
            $fail[] = 'order independence (per_level=' . var_export($per_level, true) . ', delta=[' . implode(',', $delta) . '])';
        }
    }
}

// Duplicate input rows collapse.
$total++;
if (array_column(TermCountSurvivors::select([['id' => 1, 'depth' => 0], ['id' => 1, 'depth' => 0]], [], 2, false), 'id') !== [1]) {
    $fail[] = 'duplicate term rows collapse';
}

if ($fail) {
    fwrite(STDERR, "\nTERM COUNT SURVIVORS FAIL — " . count($fail) . "/$total:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  ✗ $f\n"); }
    exit(1);
}

fwrite(STDOUT, "TERM COUNT SURVIVORS OK — $total checks.\n");
exit(0);
