<?php
/**
 * Terms-from-related-post merge migration harness (FW-61 05).
 *
 * Pins the stored-row rewrite of `propagation_rules` and
 * `related_post_terms_rules` into `terms_from_related_post` on host PHP, with
 * get_option/update_option stubbed over an array. Delete with
 * `Storage\RelatedPostMergeMigration`, one minor after it ships.
 *
 * Run:  php tests/verify-related-post-merge-migration.php   (local PHP CLI, no WP needed)
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

use BWS\MetaConductor\Storage\RelatedPostMergeMigration as M;
use BWS\MetaConductor\Storage\OptionRuleStorage as S;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

$row = static fn(string $type, array $extra = []): array =>
    ['type' => $type, 'name' => 'r', 'enabled' => true, 'post_types' => ['mc_item'], 'taxonomy' => ['mc_topic']] + $extra;
$one = static fn(array $r): array => M::migrate_rows([$r])[0];

// --- propagation → parent. ---------------------------------------------------

foreach (['merge', 'replace', 'skip'] as $claim) {
    $in  = $row('propagation_rules', ['conflict_handling' => $claim]);
    $out = $one($in);
    $check("propagation ($claim): type and relationship",
        $out['type'] === 'terms_from_related_post' && $out['relationship'] === 'parent');
    $check("propagation ($claim): conflict_handling kept", $out['conflict_handling'] === $claim);
    unset($in['type'], $out['type'], $out['relationship']);
    $check("propagation ($claim): every other key survives", $out === $in);
}
$out = $one($row('propagation_rules'));
$check('propagation with no claim: none invented', !array_key_exists('conflict_handling', $out));

// --- related_post_terms → acf, keep_in_sync mapped. --------------------------

$acf = ['acf_field_name' => 'mc_item:mc_related:field_abc123', 'holder' => 'source', 'reverse_field' => 'mc_back'];

$out = $one($row('related_post_terms_rules', $acf + ['keep_in_sync' => true]));
$check('acf keep_in_sync true → replace',
    $out['type'] === 'terms_from_related_post' && $out['relationship'] === 'acf' && $out['conflict_handling'] === 'replace');
$check('acf keep_in_sync true: old key removed', !array_key_exists('keep_in_sync', $out));

$out = $one($row('related_post_terms_rules', $acf + ['keep_in_sync' => false]));
$check('acf keep_in_sync false → merge', $out['conflict_handling'] === 'merge' && !array_key_exists('keep_in_sync', $out));

foreach ([0, '', '0', null] as $i => $falsy) {
    $out = $one($row('related_post_terms_rules', ['keep_in_sync' => $falsy]));
    $check("acf keep_in_sync falsy #$i → merge", $out['conflict_handling'] === 'merge' && !array_key_exists('keep_in_sync', $out));
}
foreach ([1, '1', 'on'] as $i => $truthy) {
    $out = $one($row('related_post_terms_rules', ['keep_in_sync' => $truthy]));
    $check("acf keep_in_sync truthy #$i → replace", $out['conflict_handling'] === 'replace');
}

$out = $one($row('related_post_terms_rules', $acf));
$check('acf with no keep_in_sync: no claim invented (handler default is merge)', !array_key_exists('conflict_handling', $out));

$in  = $row('related_post_terms_rules', $acf + ['keep_in_sync' => true]);
$out = $one($in);
$check('acf: field reference stored whole', $out['acf_field_name'] === 'mc_item:mc_related:field_abc123');
unset($in['type'], $in['keep_in_sync'], $out['type'], $out['relationship'], $out['conflict_handling']);
$check('acf: every other key survives', $out === $in);

// --- Rows that must come back untouched. -------------------------------------

$unknown = $row('mystery_rules');
$done    = $row('terms_from_related_post', ['relationship' => 'acf', 'conflict_handling' => 'skip']);
$other   = $row('terms_from_date_window');
$check('unregistered type string left alone', $one($unknown) === $unknown);
$check('row already on the new id untouched', $one($done) === $done);
$check('already-migrated row keeps a stray keep_in_sync (not ours to judge)',
    $one($done + ['keep_in_sync' => true]) === $done + ['keep_in_sync' => true]);
$check('other registered type untouched', $one($other) === $other);
$check('non-array row tolerated', M::migrate_rows(['junk', 5, null]) === ['junk', 5, null]);
$check('row with no type tolerated', $one(['name' => 'x']) === ['name' => 'x']);

$list = [
    $row('propagation_rules', ['conflict_handling' => 'replace']),
    $unknown,
    $row('related_post_terms_rules', ['keep_in_sync' => true]),
    $done,
    $row('propagation_rules'),
];
$out = M::migrate_rows($list);
$check('list: order and length kept',
    count($out) === 5
    && array_column($out, 'type') === ['terms_from_related_post', 'mystery_rules', 'terms_from_related_post', 'terms_from_related_post', 'terms_from_related_post']
    && array_column($out, 'relationship') === ['parent', 'acf', 'acf', 'parent']);
$check('migrate_rows is idempotent', M::migrate_rows($out) === $out);
$check('empty list', M::migrate_rows([]) === []);

$keyed = ['a' => $row('propagation_rules')];
$check('array keys kept', array_keys(M::migrate_rows($keyed)) === ['a']);

// --- run(): both kind lists, flag, option write, idempotency. ----------------

$old = [
    S::KIND_TERM   => [$row('propagation_rules'), $row('terms_from_date_window'), $row('related_post_terms_rules', ['keep_in_sync' => true])],
    S::KIND_FORMAT => [$row('propagation_rules')],
    'conflict_handling_overrides' => ['category' => 'merge'],
];
$GLOBALS['mc_opts'] = [S::OPTION_NAME => $old];
$check('run() reports a rewrite', M::run() === true);
$s = get_option(S::OPTION_NAME);
$check('run(): term list walked',
    array_column($s[S::KIND_TERM], 'type') === ['terms_from_related_post', 'terms_from_date_window', 'terms_from_related_post']
    && $s[S::KIND_TERM][2]['conflict_handling'] === 'replace');
$check('run(): format list walked too', $s[S::KIND_FORMAT][0]['type'] === 'terms_from_related_post');
$check('run(): unrelated keys untouched', $s['conflict_handling_overrides'] === $old['conflict_handling_overrides']);
$check('run(): flag set', (bool) get_option(M::FLAG_OPTION));

$after = $GLOBALS['mc_opts'];
$check('run() twice: second call is a no-op', M::run() === false && $GLOBALS['mc_opts'] === $after);

// A row saved with an old id AFTER the flag is set is not re-migrated (flag short-circuit).
$GLOBALS['mc_opts'][S::OPTION_NAME][S::KIND_TERM][] = $row('propagation_rules');
$check('flag gates: later old-id rows are left alone',
    M::run() === false && get_option(S::OPTION_NAME)[S::KIND_TERM][3]['type'] === 'propagation_rules');

// Only one kind list present: the other is not conjured.
$GLOBALS['mc_opts'] = [S::OPTION_NAME => [S::KIND_TERM => [$row('propagation_rules')]]];
$check('one list only: rewritten, other not created',
    M::run() === true && !array_key_exists(S::KIND_FORMAT, get_option(S::OPTION_NAME)));

// Nothing to rewrite: option not written, flag still set, run() signals no cache clear.
$GLOBALS['mc_opts'] = [S::OPTION_NAME => [S::KIND_TERM => [$done]]];
$before = get_option(S::OPTION_NAME);
$check('already-migrated option: no rewrite (no cache clear), flag set',
    M::run() === false && get_option(S::OPTION_NAME) === $before && (bool) get_option(M::FLAG_OPTION));

// Fresh / empty / malformed options: flag set, nothing written, no fatal.
foreach ([[], [S::OPTION_NAME => []], [S::OPTION_NAME => 'junk'], [S::OPTION_NAME => [S::KIND_TERM => 'junk', S::KIND_FORMAT => null]]] as $i => $start) {
    $GLOBALS['mc_opts'] = $start;
    $check("run() on empty/malformed option #$i: no rewrite, flag set", M::run() === false && (bool) get_option(M::FLAG_OPTION));
    $check("run() on empty/malformed option #$i: option unchanged", array_diff_key($GLOBALS['mc_opts'], [M::FLAG_OPTION => 1]) === $start);
}

// --- Sequencing: the id rewrite first, then the merge. -----------------------

$legacy = ['type' => 'related_post_terms_rules', 'name' => 'r', 'keep_in_sync' => true];
$GLOBALS['mc_opts'] = [S::OPTION_NAME => [S::KIND_TERM => [$legacy]]];
$i = \BWS\MetaConductor\Storage\RuleTypeIdMigration::run();
$m = M::run();
$r = get_option(S::OPTION_NAME)[S::KIND_TERM][0];
$check('id migration leaves both old strings for the merge', $i === false && $m === true);
$check('id then merge: merged row', $r['type'] === 'terms_from_related_post' && $r['relationship'] === 'acf' && $r['conflict_handling'] === 'replace');

// --- Report. -----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nRELATED-POST-MERGE-MIGRATION FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "RELATED-POST-MERGE-MIGRATION OK — all $total assertions passed (both mappings, keep_in_sync, untouched rows, both kind lists, idempotency, flag gate; FW-61 05).\n");
exit(0);
