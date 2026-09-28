<?php
/**
 * H17 — Token engine harness (FW-40).
 *
 * Renders patterns through `Tokens\TokenEngine::render()` on host PHP, fed by a
 * fake array-backed source, and asserts the rendered strings — never segments
 * or private helpers.
 *
 * Two halves:
 *   - the golden table (`tests/fixtures/token-engine-golden.json`), captured
 *     from `TitleSlugHandler` BEFORE the engine moved out of it. Every resolver
 *     row replays here with the same inputs, so the extraction is proven, not
 *     asserted. The stub sanitizer is a LOOKUP into the `sanitize_title` pairs
 *     the capture logged: an input it never saw fails rather than guessing.
 *   - targeted cases for the engine's contract: empty-token drop, trailing
 *     literals, dangling punctuation, the guard only when a base is passed,
 *     bound variables never guarded, unknown and non-scalar values.
 *
 * The golden's `rules` table exercises `resolve_rule_output()`, which stays in
 * the handler — that half is checked on the testbed by
 * `tools/fixtures/mc-rules/golden-token-engine.php check`.
 *
 * Run: php tests/verify-token-engine.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Tokens\OutputPolicy;
use BWS\MetaConductor\Tokens\TokenEngine;
use BWS\MetaConductor\Tokens\TokenSourceInterface;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) {
        $fail[] = $name;
    }
};

final class FakeTokenSource implements TokenSourceInterface {
    /** @param array $terms taxonomy => [[name, slug], ...] */
    public function __construct(
        private array $meta,
        private array $terms = [],
        private ?\DateTimeInterface $published = null,
    ) {}

    public function field(string $key): mixed {
        return $this->meta[$key] ?? '';
    }

    public function terms(string $taxonomy): array {
        return array_map(static fn($t) => (object) ['name' => $t[0], 'slug' => $t[1]], $this->terms[$taxonomy] ?? []);
    }

    public function published(): ?\DateTimeInterface {
        return $this->published;
    }
}

// --- Golden replay. ---------------------------------------------------------

$golden = json_decode((string) file_get_contents(__DIR__ . '/fixtures/token-engine-golden.json'), true);
if (!is_array($golden)) {
    fwrite(STDERR, "TOKEN-ENGINE FAIL — cannot read tests/fixtures/token-engine-golden.json\n");
    exit(1);
}

// Replay under the capture's clocks. Every date reads the site zone (04), and
// the server zone is kept as captured so a server-zone leak would show.
date_default_timezone_set($golden['server_timezone']);
$site_tz = $golden['site_timezone'];
function wp_timezone(): \DateTimeZone {
    return new \DateTimeZone($GLOBALS['site_tz']);
}

$unseen   = [];
$sanitize = static function (string $raw) use ($golden, &$unseen): string {
    if (!array_key_exists($raw, $golden['sanitize_title'])) {
        $unseen[$raw] = true;
        return $raw;
    }
    return $golden['sanitize_title'][$raw];
};

$source = new FakeTokenSource(
    $golden['source']['meta'],
    $golden['source']['terms'],
    new \DateTimeImmutable($golden['post_date'], new \DateTimeZone($golden['site_timezone']))
);
$title = OutputPolicy::title();
$slug  = OutputPolicy::slug($sanitize);

foreach ($golden['resolver'] as $i => $row) {
    $is_slug = $row['context'] === 'slug';
    $base    = $row['computed_title'];
    $vars    = ['default_title' => $base, 'default_slug' => $sanitize($base)];
    $guard   = $row['guard'] ? ($is_slug ? $sanitize($base) : $base) : null;

    $out = TokenEngine::render($row['pattern'], $source, $is_slug ? $slug : $title, $vars, $guard);
    $check(sprintf('golden #%d %s %s base=%s guard=%s: expected %s got %s',
        $i, json_encode($row['pattern']), $row['context'], json_encode($base),
        $row['guard'] ? 'on' : 'off', json_encode($row['out']), json_encode($out)), $out === $row['out']);
}
$check('stub sanitizer saw only inputs the capture logged: ' . implode(', ', array_keys($unseen)), !$unseen);

// --- Targeted contract cases. ----------------------------------------------

$src = new FakeTokenSource(['a' => 'Alpha', 'b' => 'Beta', 'blank' => '', 'arr' => [1, 2], 'obj' => new \stdClass()]);
$r   = static fn(string $p, ?string $base = null, array $vars = []) => TokenEngine::render($p, $src, $title, $vars, $base);

// Pending separators are pinned as they ARE, not as they read: a literal in
// front of an empty token is held, and emitted whole once a later token
// resolves — so a mid-pattern gap keeps its dashes. Only trailing ones drop.
$check('empty leading token drops its separator', $r('{meta:blank} - {meta:a}') === 'Alpha');
$check('empty trailing tokens drop their pending separators',
    $r('{meta:a} - {meta:blank} - {meta:missing}') === 'Alpha');
$check('pending separators are emitted when a later token resolves',
    $r('{meta:a} - {meta:blank} - {meta:b}') === 'Alpha - - Beta');
$check('trailing literal kept after a resolved token', $r('{meta:a} end') === 'Alpha end');
$check('trailing literal dropped when nothing resolved', $r('{meta:blank} end') === '');
$check('dangling opening punctuation stripped', $r('{meta:a} (') === 'Alpha' && $r('{meta:a} - [') === 'Alpha');
$check('guard drops a token already in the base', $r('{meta:a} {meta:b}', 'the alpha one') === 'Beta');
$check('no base, no guard', $r('{meta:a} {meta:b}') === 'Alpha Beta');
$check('empty base, no guard', $r('{meta:a} {meta:b}', '') === 'Alpha Beta');
$check('bound variable returned as-is and never guarded',
    $r('{default_title} {meta:b}', 'Alpha Beta', ['default_title' => 'Alpha']) === 'Alpha');
$check('unbound variable resolves empty', $r('{default_title} {meta:a}') === 'Alpha');
$check('unknown token resolves empty', $r('{meta:a} {mta:x} {meta:b}') === 'Alpha Beta');
$check('non-scalar field resolves empty', $r('{meta:arr}') === '' && $r('{meta:obj}') === '');
$check('non-scalar date field resolves empty', $r('{date_year:arr}') === '');

$check('slug policy sanitizes each token and collapses dashes',
    TokenEngine::render('--{meta:a}--{meta:b}--', $src, OutputPolicy::slug(static fn(string $v) => strtolower($v)))
        === 'alpha-beta');

// --- tokens() / date_part_of(): the grammar slug escalation reads. -----------

$check('tokens() lists every token in order',
    TokenEngine::tokens('{meta:a} - {term:genre} ({pub_year})') === ['meta:a', 'term:genre', 'pub_year']);
$check('tokens() keeps a repeated token each time it appears',
    TokenEngine::tokens('{meta:a}-{meta:a}') === ['meta:a', 'meta:a']);
$check('tokens() on a pattern with no tokens is empty', TokenEngine::tokens('plain text') === []);
$check('tokens() on an empty pattern is empty', TokenEngine::tokens('') === []);
foreach (['year', 'month', 'day', 'hour', 'minute'] as $part) {
    $check("date_part_of date_$part:field", TokenEngine::date_part_of("date_$part:start") === $part);
    $check("date_part_of pub_$part", TokenEngine::date_part_of("pub_$part") === $part);
}
// The same forms render() resolves empty are not date tokens here either.
$check('date_part_of: date_ needs a field', TokenEngine::date_part_of('date_year') === null);
$check('date_part_of: pub_ takes no field', TokenEngine::date_part_of('pub_year:x') === null);
$check('date_part_of: non-date tokens', TokenEngine::date_part_of('meta:date_year') === null
    && TokenEngine::date_part_of('term:genre') === null && TokenEngine::date_part_of('default_slug') === null);

// --- Date parsing reads site time (04). --------------------------------------
// Site New York, server Auckland: a leak of the server zone lands on a
// different day from both UTC and the site.

$site_tz = 'America/New_York';
date_default_timezone_set('Pacific/Auckland');
$dsrc  = new FakeTokenSource([
    'unix'  => (string) gmmktime(2, 0, 0, 1, 1, 2027), // 2026-12-31 21:00 New York
    'zoned' => '2027-01-01T02:00:00+00:00',            // same instant, strtotime path
    'wall'  => 'April 3 2026 23:45',                   // no zone: site wall clock as written
    'ymd'   => '20260403',
    'iso'   => '2026-04-03',
    'dmy'   => '03/04/2026',
    'bad'   => 'garbage',
]);
$parts = static fn(string $key) => TokenEngine::render(
    "{date_year:$key}-{date_month:$key}-{date_day:$key} {date_hour:$key}:{date_minute:$key}",
    $dsrc, OutputPolicy::slug(static fn(string $v) => $v));

$check('unix timestamp near midnight converts into site time', $parts('unix') === '2026-12-31 21:00');
$check('zoned strtotime string near midnight converts into site time', $parts('zoned') === '2026-12-31 21:00');
$check('wall-clock strtotime string reads as site time', $parts('wall') === '2026-04-03 23:45');
$check('Ymd parts unchanged, time zeroed', $parts('ymd') === '2026-04-03 00:00');
$check('Y-m-d parts unchanged, time zeroed', $parts('iso') === '2026-04-03 00:00');
$check('d/m/Y parts unchanged, time zeroed', $parts('dmy') === '2026-04-03 00:00');
$check('unparseable date resolves empty', $parts('bad') === '');
$check('parse_date: blank is null, not now', TokenEngine::parse_date('') === null && TokenEngine::parse_date(' ') === null);
$check('parse_date returns site time',
    TokenEngine::parse_date('20260403')?->getTimezone()->getName() === 'America/New_York');

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nTOKEN-ENGINE FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach (array_slice($fail, 0, 40) as $f) {
        fwrite(STDERR, "  \xE2\x9C\x97 $f\n");
    }
    exit(1);
}
fwrite(STDOUT, "TOKEN-ENGINE OK — all $total assertions passed (" . count($golden['resolver']) . " golden rows + contract cases).\n");
exit(0);
