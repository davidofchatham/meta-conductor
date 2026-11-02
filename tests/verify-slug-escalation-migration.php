<?php
/**
 * Slug-escalation migration harness (FW-51 03).
 *
 * Pins the stored-row translation `date_escalation` + `date_field` →
 * `slug_escalation` on host PHP, with get_option/update_option stubbed over an
 * array. Delete with `Storage\SlugEscalationMigration`, one minor after it ships.
 *
 * Run:  php tests/verify-slug-escalation-migration.php   (local PHP CLI, no WP needed)
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

use BWS\MetaConductor\Storage\SlugEscalationMigration as M;
use BWS\MetaConductor\Storage\OptionRuleStorage as S;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

$ts = static fn(array $extra): array => $extra + ['type' => 'title_slug_rules', 'name' => 'r'];
$one = static fn(array $row): array => M::migrate_rows([$row])[0];

// --- Translation table. -----------------------------------------------------

$r = $one($ts(['date_escalation' => true]));
$check('on, no date field → {pub_year}', $r['slug_escalation'] === '{pub_year}');
$check('on, no date field: both old keys gone', !array_key_exists('date_escalation', $r) && !array_key_exists('date_field', $r));

$r = $one($ts(['date_escalation' => '1', 'date_field' => '']));
$check('on, empty date field → {pub_year}', $r['slug_escalation'] === '{pub_year}');

$r = $one($ts(['date_escalation' => true, 'date_field' => 'mc_event_date']));
$check('on, with date field → {date_year:KEY}', $r['slug_escalation'] === '{date_year:mc_event_date}');
$check('on, with date field: both old keys gone', !array_key_exists('date_escalation', $r) && !array_key_exists('date_field', $r));

$r = $one($ts(['date_escalation' => false, 'date_field' => 'mc_event_date']));
$check('off with a leftover date field → blank, keys gone',
    $r['slug_escalation'] === '' && !array_key_exists('date_escalation', $r) && !array_key_exists('date_field', $r));

$r = $one($ts(['date_escalation' => '']));
$check('off (empty string) → blank', $r['slug_escalation'] === '');

$r = $one($ts(['date_field' => 'mc_event_date']));
$check('date field alone (no toggle) → blank, key gone', $r['slug_escalation'] === '' && !array_key_exists('date_field', $r));

$r = $one($ts(['date_escalation' => true, 'date_field' => 'mc_event_date', 'slug_escalation' => '{parent:slug}']));
$check('a row already carrying a ladder keeps it', $r['slug_escalation'] === '{parent:slug}' && !array_key_exists('date_field', $r));

$r = $one($ts(['name' => 'keep', 'enabled' => true, 'date_escalation' => true]));
$check('other row fields pass through', $r['name'] === 'keep' && $r['enabled'] === true);

// --- Rows that must come back untouched. ------------------------------------

$done  = $ts(['slug_escalation' => '{pub_year}', 'escalation_placement' => 'suffix']);
$other = ['type' => 'field_transformation_rules', 'date_escalation' => true, 'date_field' => 'x'];
$plain = $ts(['post_type' => 'post']);
$check('already-migrated row untouched', $one($done) === $done);
$check('other row type untouched, old-looking keys and all', $one($other) === $other);
$check('title/slug row holding neither old key untouched (no key added)', $one($plain) === $plain);

$list = [$other, $ts(['date_escalation' => true, 'date_field' => 'd']), $done, $plain];
$out  = M::migrate_rows($list);
$check('list: order and length kept', count($out) === 4 && $out[0] === $other && $out[2] === $done && $out[3] === $plain);
$check('list: only the old-shape row changes', $out[1]['slug_escalation'] === '{date_year:d}');
$check('migrate_rows is idempotent', M::migrate_rows($out) === $out);
$check('empty list', M::migrate_rows([]) === []);

// --- run(): flag, option write, idempotency. --------------------------------

$old = [
    'format_rules' => [$ts(['date_escalation' => true]), $ts(['date_escalation' => true, 'date_field' => 'mc_event_date'])],
    'term_rules'   => [['type' => 'related_post_terms', 'date_escalation' => 'untouched']],
    'conflict_handling_overrides' => ['category' => 'merge'],
];
$GLOBALS['mc_opts'] = [S::OPTION_NAME => $old];
$check('run() reports a rewrite', M::run() === true);
$s = get_option(S::OPTION_NAME);
$check('run(): rows rewritten in place',
    $s['format_rules'][0]['slug_escalation'] === '{pub_year}' && $s['format_rules'][1]['slug_escalation'] === '{date_year:mc_event_date}');
$check('run(): term list and unrelated keys untouched',
    $s['term_rules'] === $old['term_rules'] && $s['conflict_handling_overrides'] === $old['conflict_handling_overrides']);
$check('run(): flag set', (bool) get_option(M::FLAG_OPTION));

$after = $GLOBALS['mc_opts'];
$check('run() twice: second call is a no-op', M::run() === false && $GLOBALS['mc_opts'] === $after);

// A row saved with the old shape AFTER the flag is set (e.g. a restored snapshot) is not re-migrated.
$GLOBALS['mc_opts'][S::OPTION_NAME]['format_rules'][] = $ts(['date_escalation' => true]);
$check('flag gates: later old-shape rows are left alone',
    M::run() === false && array_key_exists('date_escalation', get_option(S::OPTION_NAME)['format_rules'][2]));

// Fresh / empty / malformed options: flag set, nothing written, no fatal.
foreach ([[], [S::OPTION_NAME => []], [S::OPTION_NAME => 'junk'], [S::OPTION_NAME => ['format_rules' => 'junk']]] as $i => $start) {
    $GLOBALS['mc_opts'] = $start;
    $check("run() on empty/malformed option #$i: no rewrite, flag set", M::run() === false && (bool) get_option(M::FLAG_OPTION));
}

// --- No read path reads the old keys. ---------------------------------------

$readers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/includes', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || $f->getFilename() === 'class-slug-escalation-migration.php') { continue; }
    if (preg_match('/[\'"]date_escalation[\'"]|[\'"]date_field[\'"]/', file_get_contents($f->getPathname()))) {
        $readers[] = $f->getFilename();
    }
}
$check('nothing under includes/ but the migration names date_escalation / date_field: ' . implode(',', $readers), !$readers);

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nSLUG-ESCALATION-MIGRATION FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "SLUG-ESCALATION-MIGRATION OK — all $total assertions passed (translation table, idempotency, flag gate, no old-key readers; FW-51 03).\n");
exit(0);
