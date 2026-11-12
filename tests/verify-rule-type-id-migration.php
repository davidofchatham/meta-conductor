<?php
/**
 * Rule-type id migration harness (FW-63 01).
 *
 * Pins the stored-row rewrite of the seven old rule-type strings to their new
 * ids on host PHP, with get_option/update_option stubbed over an array. Delete
 * with `Storage\RuleTypeIdMigration`, one minor after it ships.
 *
 * Run:  php tests/verify-rule-type-id-migration.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['mc_opts'] = [];
function get_option($k, $d = false) { return $GLOBALS['mc_opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['mc_opts'][$k] = $v; return true; }

require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Storage\RuleTypeIdMigration as M;
use BWS\MetaConductor\Storage\OptionRuleStorage as S;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

$expected = [
    'hierarchical_rules'                    => 'terms_from_term_hierarchy',
    'related_rules'                         => 'terms_from_trigger_term',
    'time_based_rules'                      => 'terms_from_date_window',
    'term_count_rules'                      => 'restrict_term_count',
    'hierarchical_level_restriction_rules'  => 'restrict_terms_by_level',
    'title_slug_rules'                      => 'title_slug_from_pattern',
    'field_transformation_rules'            => 'field_from_pattern',
];
$check('map holds exactly the seven documented pairs', M::MAP === $expected);

$row = static fn(string $type): array => ['type' => $type, 'name' => 'r', 'enabled' => true, 'post_types' => ['mc_item']];
$one = static fn(array $r): array => M::migrate_rows([$r])[0];

// --- Each mapping, and only the type string changes. -------------------------

foreach ($expected as $old => $new) {
    $in  = $row($old);
    $out = $one($in);
    $check("$old → $new", $out['type'] === $new);
    unset($out['type'], $in['type']);
    $check("$old: every other key survives", $out === $in);
}

// --- Rows that must come back untouched. -------------------------------------

$unknown = $row('mystery_rules');
$done    = $row('terms_from_date_window');
$other   = $row('propagation_rules');
$check('unregistered type string left alone', $one($unknown) === $unknown);
$check('row already on a new id untouched', $one($done) === $done);
$check('registered type outside the map untouched', $one($other) === $other);
$check('non-array row tolerated', M::migrate_rows(['junk', 5, null]) === ['junk', 5, null]);
$check('row with no type tolerated', $one(['name' => 'x']) === ['name' => 'x']);

$list = [$row('title_slug_rules'), $unknown, $row('hierarchical_rules'), $done, $row('hierarchical_rules')];
$out  = M::migrate_rows($list);
$check('list: order and length kept',
    count($out) === 5
    && array_column($out, 'type') === ['title_slug_from_pattern', 'mystery_rules', 'terms_from_term_hierarchy', 'terms_from_date_window', 'terms_from_term_hierarchy']);
$check('migrate_rows is idempotent', M::migrate_rows($out) === $out);
$check('empty list', M::migrate_rows([]) === []);

// String keys survive (a list keyed by something other than 0..n).
$keyed = ['a' => $row('related_rules')];
$check('array keys kept', array_keys(M::migrate_rows($keyed)) === ['a']);

// --- run(): both kind lists, flag, option write, idempotency. ----------------

$old = [
    S::KIND_TERM   => [$row('hierarchical_rules'), $row('related_rules'), $row('time_based_rules'), $row('term_count_rules'), $row('hierarchical_level_restriction_rules'), $row('related_post_terms_rules')],
    S::KIND_FORMAT => [$row('title_slug_rules'), $row('field_transformation_rules')],
    'conflict_handling_overrides' => ['category' => 'merge'],
];
$GLOBALS['mc_opts'] = [S::OPTION_NAME => $old];
$check('run() reports a rewrite', M::run() === true);
$s = get_option(S::OPTION_NAME);
$check('run(): term list walked',
    array_column($s[S::KIND_TERM], 'type') === [
        'terms_from_term_hierarchy', 'terms_from_trigger_term', 'terms_from_date_window',
        'restrict_term_count', 'restrict_terms_by_level', 'related_post_terms_rules',
    ]);
$check('run(): format list walked',
    array_column($s[S::KIND_FORMAT], 'type') === ['title_slug_from_pattern', 'field_from_pattern']);
$check('run(): unrelated keys untouched', $s['conflict_handling_overrides'] === $old['conflict_handling_overrides']);
$check('run(): flag set', (bool) get_option(M::FLAG_OPTION));

$after = $GLOBALS['mc_opts'];
$check('run() twice: second call is a no-op', M::run() === false && $GLOBALS['mc_opts'] === $after);

// A row saved with an old id AFTER the flag is set is not re-migrated.
$GLOBALS['mc_opts'][S::OPTION_NAME][S::KIND_TERM][] = $row('hierarchical_rules');
$check('flag gates: later old-id rows are left alone',
    M::run() === false && get_option(S::OPTION_NAME)[S::KIND_TERM][6]['type'] === 'hierarchical_rules');

// Only one kind list present: the other is not conjured.
$GLOBALS['mc_opts'] = [S::OPTION_NAME => [S::KIND_FORMAT => [$row('title_slug_rules')]]];
$check('one list only: rewritten, other not created',
    M::run() === true && !array_key_exists(S::KIND_TERM, get_option(S::OPTION_NAME)));

// Nothing to rewrite: option not written, flag still set.
$GLOBALS['mc_opts'] = [S::OPTION_NAME => [S::KIND_TERM => [$done]]];
$before = get_option(S::OPTION_NAME);
$check('already-migrated option: no rewrite, flag set',
    M::run() === false && get_option(S::OPTION_NAME) === $before && (bool) get_option(M::FLAG_OPTION));

// Fresh / empty / malformed options: flag set, nothing written, no fatal.
foreach ([[], [S::OPTION_NAME => []], [S::OPTION_NAME => 'junk'], [S::OPTION_NAME => [S::KIND_TERM => 'junk', S::KIND_FORMAT => null]]] as $i => $start) {
    $GLOBALS['mc_opts'] = $start;
    $check("run() on empty/malformed option #$i: no rewrite, flag set", M::run() === false && (bool) get_option(M::FLAG_OPTION));
    $check("run() on empty/malformed option #$i: option unchanged", array_diff_key($GLOBALS['mc_opts'], [M::FLAG_OPTION => 1]) === $start);
}

// --- Report. -----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nRULE-TYPE-ID-MIGRATION FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "RULE-TYPE-ID-MIGRATION OK — all $total assertions passed (seven mappings, untouched rows, both kind lists, idempotency, flag gate; FW-63 01).\n");
exit(0);
