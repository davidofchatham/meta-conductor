<?php
/**
 * FW-40 golden capture — what today's title/slug token resolution produces,
 * recorded BEFORE the engine moves out of `TitleSlugHandler`, so the extraction
 * can prove it changed nothing.
 *
 * Every value a token reads is INJECTED, not seeded: `get_post_metadata` and
 * `get_the_terms` are short-circuited for the subject post while a case runs,
 * so each row's inputs are exactly the values recorded next to it and the
 * fixture replays through a fake token source on host PHP (H17). The subject is
 * a real fixture post only because `get_the_terms()` bails on an ID with no
 * post before its filter runs. Every `sanitize_title()` call is logged raw ->
 * result, so H17's stub sanitizer is a lookup, not a reimplementation.
 *
 * Two tables:
 *   - `resolver` — `resolve_pattern()` over a grid: every token kind x title/slug
 *     x empty/non-empty x guard on/off x default title bound or not, plus
 *     separator and punctuation edge cases. H17 replays these.
 *   - `rules` — `resolve_rule_output()` for whole rules (the live site's two
 *     patterns, the fixture rule, one per slug mode, the idempotency branches).
 *     That method stays in the handler, so these are checked here, on the
 *     testbed, with `check`.
 *
 * Deliberately left OUT: `{date_hour:}`/`{date_minute:}` over a date-only meta
 * value. `DateTime::createFromFormat('Ymd', …)` fills the missing time from the
 * clock, so those outputs change every run — a latent bug, not behavior to pin.
 *
 * NON-MUTATING: no post is saved and no option written.
 *
 * Run (the plugin mount is read-only, so the fixture is written from stdout):
 *   wp eval-file <mount>/tools/fixtures/mc-rules/golden-token-engine.php capture --allow-root \
 *     > tests/fixtures/token-engine-golden.json
 *   wp eval-file <mount>/tools/fixtures/mc-rules/golden-token-engine.php check --allow-root
 * Prereq: mc-rules seeded (seed.php).
 */

use BWS\MetaConductor\Handlers\TitleSlugHandler;
use BWS\MetaConductor\TaxonomyManager;

require_once __DIR__ . '/lookup.php';

$mode = $args[0] ?? 'capture';

$post_id = (int) mc_fixture_find_post('mc-item-alpha', 'mc_item');
if (!$post_id) {
    fwrite(STDERR, "cannot find fixture post mc-item-alpha — seed.php first\n");
    exit(1);
}
$handler = TaxonomyManager::get_instance()->get_handler('title_slug_rules');
if (!$handler instanceof TitleSlugHandler) {
    fwrite(STDERR, "title_slug handler not live\n");
    exit(1);
}

// ── Injection ────────────────────────────────────────────────────────────────

$GLOBALS['mc_golden_src'] = null; // ['meta' => [...], 'terms' => [tax => [[name, slug], ...]]]
$GLOBALS['mc_golden_san'] = [];

add_filter('get_post_metadata', static function ($check, $object_id, $key, $single) use ($post_id) {
    $src = $GLOBALS['mc_golden_src'];
    if ($src === null || (int) $object_id !== $post_id) {
        return $check;
    }
    return [$src['meta'][$key] ?? ''];
}, 1, 4);

add_filter('get_the_terms', static function ($terms, $object_id, $taxonomy) use ($post_id) {
    $src = $GLOBALS['mc_golden_src'];
    if ($src === null || (int) $object_id !== $post_id) {
        return $terms;
    }
    $list = $src['terms'][$taxonomy] ?? [];
    return $list ? array_map(static fn($t) => (object) ['name' => $t[0], 'slug' => $t[1]], $list) : false;
}, 999, 3);

add_filter('sanitize_title', static function ($title, $raw) {
    $GLOBALS['mc_golden_san'][(string) $raw] = (string) $title;
    return $title;
}, 999, 2);

$with = static function (array $src, callable $fn) {
    $GLOBALS['mc_golden_src'] = $src;
    try {
        return $fn();
    } finally {
        $GLOBALS['mc_golden_src'] = null;
    }
};

$resolve = new ReflectionMethod(TitleSlugHandler::class, 'resolve_pattern');
$rule_out = new ReflectionMethod(TitleSlugHandler::class, 'resolve_rule_output');

// ── The resolver grid ───────────────────────────────────────────────────────

$POST_DATE = '2026-04-03 23:30:00';

$grid_src = [
    'meta' => [
        'name'    => 'Harbor Light',
        'other'   => 'Coastal',
        'amp'     => 'R&D Lab',
        'num'     => '42',
        'blank'   => '',
        'arr'     => [1, 2],
        'd_ymd'   => '20260403',
        'd_iso'   => '2026-04-03',
        'd_dt'    => '2026-04-03 23:30:00',
        'd_dmy'   => '03/04/2026',
        'd_unix'  => '1775260800', // 2026-04-04 00:00 UTC = 2026-04-03 20:00 New York
        'd_str'   => 'April 3 2026 22:15',
        'd_bad'   => 'garbage',
        'd_empty' => '',
    ],
    'terms' => [
        'mc_topic' => [['Harbor', 'harbor'], ['Coastal Waters', 'coastal-waters']],
        'mc_flag'  => [],
    ],
];

$tokens = [
    'meta:name', 'meta:other', 'meta:amp', 'meta:num', 'meta:blank', 'meta:arr', 'meta:missing',
    'pub_year', 'pub_month', 'pub_day', 'pub_hour', 'pub_minute',
    'term:mc_topic', 'terms:mc_topic', 'term:mc_flag', 'terms:mc_flag',
    'mta:typo', 'default_title', 'default_slug',
];
foreach (['d_ymd', 'd_iso', 'd_dt', 'd_dmy', 'd_unix', 'd_str', 'd_bad', 'd_empty'] as $k) {
    $parts = in_array($k, ['d_dt', 'd_unix', 'd_str'], true)
        ? ['year', 'month', 'day', 'hour', 'minute']
        : ['year', 'month', 'day'];
    foreach ($parts as $p) {
        $tokens[] = "date_$p:$k";
    }
}

$patterns = [];
foreach ($tokens as $t) {
    $patterns[] = '{' . $t . '}';
    $patterns[] = 'A: {' . $t . '} | B';
}
array_push($patterns,
    '',
    'Literal only',
    '{meta:blank} - {meta:name}',
    '{meta:name} - {meta:blank} - {meta:other}',
    '{meta:name} - {meta:blank} - {meta:missing} - {meta:other}',
    '{meta:name} ({meta:blank})',
    '({meta:blank}) {meta:name}',
    '{meta:name} (',
    '{meta:name} [{meta:blank}]',
    '- {meta:name} -',
    ' / {meta:name} , {meta:other} : ',
    '{meta:name}   {meta:other}',
    '--{meta:name}--{meta:other}--',
    '{meta:name}{meta:other}',
    '{default_title} - {pub_year}',
    '{default_title} - {meta:name}',
    '{meta:other} {default_title}',
    '{default_slug}-{term:mc_topic}',
    '{default_slug}-{date_year:d_iso}',
    '{pub_year}-{pub_month}-{pub_day}',
    '{terms:mc_topic} ({pub_year})',
    // The live site's patterns, over the grid's values.
    '{date_year:d_ymd}',
    '{meta:name} {meta:blank} {meta:other} {meta:missing}',
    '{meta:name}-{meta:other}-{meta:blank}',
);

$bases = ['', 'Harbor Light Coastal 2026 April'];

$post_obj = (object) ['post_date' => $POST_DATE, 'post_title' => '', 'post_type' => 'mc_item'];

$resolver_rows = [];
foreach ($patterns as $pattern) {
    foreach (['title', 'slug'] as $context) {
        foreach ($bases as $base) {
            foreach ([true, false] as $guard) {
                $out = $with($grid_src, static fn() => $resolve->invoke(
                    $handler, $pattern, $post_id, $post_obj, $context, $base, $guard
                ));
                $resolver_rows[] = [
                    'pattern' => $pattern, 'context' => $context,
                    'computed_title' => $base, 'guard' => $guard, 'out' => $out,
                ];
            }
        }
    }
}

// ── Whole rules ─────────────────────────────────────────────────────────────

$rule = static fn(array $r) => $r + [
    'type' => 'title_slug_rules', 'enabled' => true, 'post_type' => 'mc_item',
    'title_pattern' => '', 'slug_pattern' => '', 'slug_mode' => 'prefix',
    'date_escalation' => false, 'date_field' => '',
];

$live_event = $rule(['name' => 'live event', 'slug_pattern' => '{date_year:start_date}', 'slug_mode' => 'suffix',
    'date_escalation' => true, 'date_field' => 'start_date']);
$live_person = $rule(['name' => 'live personnel',
    'title_pattern' => '{meta:name_pre} {meta:name_first} {meta:name_last} {meta:name_post}',
    'slug_pattern' => '{meta:name_first}-{meta:name_last}-{meta:name_post}', 'slug_mode' => 'replace',
    'date_escalation' => true]);
$fixture = $rule(['name' => 'fixture mc_item', 'slug_pattern' => '{default_slug}-{date_year:mc_event_date}',
    'slug_mode' => 'replace', 'date_escalation' => true, 'date_field' => 'mc_event_date']);
$dt_suffix = $rule(['name' => 'default_title suffix', 'title_pattern' => '{default_title} - {meta:other}']);
$dt_prefix = $rule(['name' => 'default_title prefix', 'title_pattern' => '{meta:other}: {default_title}',
    'slug_pattern' => '{pub_year}', 'slug_mode' => 'suffix']);
$prefix = $rule(['name' => 'slug prefix', 'slug_pattern' => '{pub_year}', 'slug_mode' => 'prefix']);
$replace = $rule(['name' => 'slug replace', 'slug_pattern' => '{meta:other}-{pub_year}', 'slug_mode' => 'replace']);

$person_full  = ['name_pre' => 'Dr.', 'name_first' => 'Ada', 'name_last' => 'Lovelace', 'name_post' => 'PhD'];
$person_short = ['name_first' => 'Ada', 'name_last' => 'Lovelace'];
$common = ['start_date' => '20260403', 'mc_event_date' => '2026-04-03', 'other' => 'Coastal'];

$cases = [
    [$live_event,  $common, 'Spring Concert'],
    [$live_event,  ['start_date' => ''], 'Spring Concert'],
    [$live_person, $person_full, 'anything'],
    [$live_person, $person_short, 'anything'],
    [$live_person, [], 'Kept Title'],
    [$fixture,     $common, 'Alpha Item'],
    [$fixture,     ['mc_event_date' => ''], 'Alpha Item'],
    [$prefix,      [], 'Harbor Report'],
    [$prefix,      [], 'Harbor Report 2026'],
    [$replace,     $common, 'Harbor Report'],
    [$dt_prefix,   $common, 'Harbor'],
    // Idempotency: no meta yet; unedited re-pass; edited title (inverse strip); brand-new title.
    [$dt_suffix,   $common, 'Harbor'],
    [$dt_suffix,   $common + ['_bws_applied_title' => 'Harbor - Coastal', '_bws_raw_title' => 'Harbor'], 'Harbor - Coastal'],
    [$dt_suffix,   $common + ['_bws_applied_title' => 'Harbor - Coastal', '_bws_raw_title' => 'Harbor'], 'Harbour - Coastal'],
    [$dt_suffix,   $common + ['_bws_applied_title' => 'Harbor - Coastal', '_bws_raw_title' => 'Harbor'], 'Something Else'],
    [$dt_suffix,   $common, 'Coastal Harbor'],
];

$rule_rows = [];
foreach ($cases as [$r, $meta, $current]) {
    $src = ['meta' => $meta, 'terms' => $grid_src['terms']];
    $p = (object) ['post_date' => $POST_DATE, 'post_title' => $current, 'post_type' => 'mc_item'];
    $out = $with($src, static fn() => $rule_out->invoke($handler, $r, $post_id, $p, $current));
    $rule_rows[] = ['rule' => $r, 'meta' => $meta, 'current_title' => $current, 'out' => $out];
}

// ── Emit or compare ─────────────────────────────────────────────────────────

$san = $GLOBALS['mc_golden_san'];
ksort($san, SORT_STRING);

$golden = [
    'about' => 'FW-40 golden: token resolution captured from TitleSlugHandler before extraction. '
             . 'Regenerate only on purpose (tools/fixtures/mc-rules/golden-token-engine.php capture).',
    'site_timezone'   => wp_timezone_string(),
    'server_timezone' => date_default_timezone_get(),
    'post_date'       => $POST_DATE,
    'source'          => $grid_src,
    'sanitize_title'  => $san,
    'resolver'        => $resolver_rows,
    'rules'           => $rule_rows,
];

if ($mode === 'capture') {
    echo json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

$path = dirname(__DIR__, 3) . '/tests/fixtures/token-engine-golden.json';
$stored = json_decode((string) file_get_contents($path), true);
if (!is_array($stored)) {
    fwrite(STDERR, "cannot read $path\n");
    exit(1);
}

$fail = 0;
foreach (['resolver', 'rules'] as $table) {
    foreach ($stored[$table] as $i => $row) {
        $now = $golden[$table][$i] ?? null;
        if ($now !== $row) {
            $fail++;
            echo "FAIL $table #$i: " . json_encode($row['pattern'] ?? $row['rule']['name'])
                . ' expected ' . json_encode($row['out']) . ' got ' . json_encode($now['out'] ?? null) . "\n";
        }
    }
    if (count($stored[$table]) !== count($golden[$table])) {
        $fail++;
        echo "FAIL $table row count " . count($stored[$table]) . ' -> ' . count($golden[$table]) . "\n";
    }
}
echo $fail ? "\nGOLDEN-TOKEN-ENGINE FAIL: $fail\n" : "\nGOLDEN-TOKEN-ENGINE OK\n";
exit($fail ? 1 : 0);
