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

$check('empty leading token drops its separator', $r('{meta:blank} - {meta:a}') === 'Alpha');
$check('empty trailing tokens drop their separators',
    $r('{meta:a} - {meta:blank} - {meta:missing}') === 'Alpha');
// FW-44's three named cases.
$check('empty middle token sheds one separator, not none',
    $r('{meta:a} - {meta:blank} - {meta:b}') === 'Alpha - Beta');
$check('bracket pair around an empty token goes whole', $r('{meta:a} ({meta:blank})') === 'Alpha');
$check('leading bracket pair around an empty token goes whole', $r('({meta:blank}) {meta:a}') === 'Alpha');
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

// --- Separator cleanup (FW-44): the join test matrix's rows. -----------------
// From bws-gb-dynamic-tags-extensions tools/test/join-template-test.php, minus
// its `~…~` groups (no groups in this grammar) and its verbatim no-token
// format (here a pattern with nothing to resolve renders ''). `{N}` is slot N's
// value; '' is an empty token.

$cleanup = static function (array $values, string $format) use ($title): string {
    $meta = [];
    foreach ($values as $n => $v) {
        $meta["v$n"] = $v;
    }
    $pattern = preg_replace('/\{(\d+)\}/', '{meta:v$1}', $format);
    return TokenEngine::render($pattern, new FakeTokenSource($meta), $title);
};
$dense = ['Dr.', 'Tom', 'M', 'Smith', 'Jr.', 'PhD', 'USN (Ret.)'];
$name  = '{1} {2} {3}. {4} {5}, {6}, {7}';
$rows  = [
    // Unit punctuation sheds with the token.
    ['mid-string period', 'Smith', ['', 'Smith'], '{1}. {2}'],
    ['dangling " sheds', "5'", ['5', ''], '{1}\'{2}"'],
    ['dangling \' sheds', '11"', ['', '11'], '{1}\'{2}"'],
    ['both empty', '', ['', ''], '{1}\'{2}"'],
    ["'0' is a real value", '5\'0"', ['5', '0'], '{1}\'{2}"'],
    ['middle initial period', 'Jane Smith', ['Jane', '', 'Smith'], '{1} {2}. {3}'],
    // Connectives.
    ['comma, first empty', 'Smith', ['', 'Smith'], '{1}, {2}'],
    ['comma, last empty', 'Smith', ['Smith', ''], '{1}, {2}'],
    ['middle empty keeps one comma', 'Jane, Smith', ['Jane', '', 'Smith'], '{1}, {2}, {3}'],
    ['colon, first empty', 'Smith', ['', 'Smith'], '{1}: {2}'],
    ['colon, last empty', 'Smith', ['Smith', ''], '{1}: {2}'],
    ['single-sided comma survives', 'Smith, PhD', ['Smith', '', 'PhD'], '{1} {2}, {3}'],
    ['adjacent empties cascade', 'Smith', ['', '', 'Smith'], '{1}, {2}, {3}'],
    // Brackets.
    ['round pair removed', 'Tom', ['Tom', ''], '{1} ({2})'],
    ['punctuation and pair removed', 'Tom', ['Tom', ''], '{1} ({2}.)'],
    ['pair kept around the survivor', '(Tom.)', ['', 'Tom'], '{1} ({2}.)'],
    ['square pair removed', 'Tom', ['Tom', ''], '{1} [{2}]'],
    ['both empty in brackets', '', ['', ''], '{1} ({2})'],
    // Floating separators.
    ['last empty looks left', 'Jane', ['Jane', ''], '{1} · {2}'],
    ['first empty looks right', 'Associate', ['', 'Associate'], '{1} · {2}'],
    ['middle empty looks right', 'a · c', ['a', '', 'c'], '{1} · {2} – {3}'],
    ['last of three looks left', 'a · b', ['a', 'b', ''], '{1} · {2} – {3}'],
    ['outer empties leave the middle', 'b', ['', 'b', ''], '{1} · {2} · {3}'],
    ['pipe', 'b', ['', 'b'], '{1} | {2}'],
    ['slash', 'a', ['a', ''], '{1} / {2}'],
    ['em dash', 'a', ['a', ''], '{1} — {2}'],
    ['hyphen, middle empty', 'a - c', ['a', '', 'c'], '{1} - {2} - {3}'],
    // Edges and survivors.
    ['trailing colon stripped', 'Author', ['Author', ''], '{1}: {2}'],
    ['pattern-final period kept', 'Smith.', ['', '', 'Smith'], '{1} {2}, {3}.'],
    ['period and comma both go', 'Smith', ['', 'Smith', ''], '{1}. {2}, {3}'],
    ['trailing quote never stripped', "5'", ['5', ''], '{1}\'{2}"'],
    ['literal beside survivor kept', 'Mr. Smith', ['Smith', ''], 'Mr. {1} · {2}'],
    ['survivor keeps its brackets', '(Tom.)', ['', 'Tom'], '{1} · ({2}.)'],
    ['hyphen inside a value untouched', 'e-mail', ['e-mail', ''], '{1} · {2}'],
    ['all empty with literals', '', [''], 'Mr. {1}'],
    ['"0" alone survives', '0', ['0'], '{1}'],
    // Edge gaps keep the old engine's reading: the literal in front of a trailing
    // empty token goes with it, and a leading empty token's literal goes too.
    ['trailing gap, separator then literal text', 'Alpha - Draft', ['Alpha', ''], '{1} - {2} - Draft'],
    ['trailing gap, slash then literal text', 'Alpha / Notes', ['Alpha', ''], '{1} / {2} / Notes'],
    ['trailing gap drops its label', 'Alpha', ['Alpha', ''], '{1} Vol. {2}'],
    ['trailing gap drops its label and separator', 'Alpha', ['Alpha', ''], '{1} - Vol. {2}'],
    ['trailing gap drops a joining word', 'Alpha', ['Alpha', ''], '{1} & {2}'],
    ['trailing gap drops its quote marks', 'Alpha', ['Alpha', ''], '{1} "{2}"'],
    ['leading gap drops its label', 'Alpha', ['', 'Alpha'], 'The {1} {2}'],
    ['leading gaps drop their labels and separators', 'Alpha', ['', '', 'Alpha'], 'A: {1} | B {2} - {3}'],
    // The full-name stress case.
    ['name: dense', 'Dr. Tom M. Smith Jr., PhD, USN (Ret.)', $dense, $name],
    ['name: sparse', 'Jane Johnson', ['', 'Jane', '', 'Johnson', '', '', ''], $name],
    ['name: no generation', 'Dr. Tom M. Smith, PhD, USN (Ret.)', array_replace($dense, [4 => '']), $name],
    ['name: no credential sheds one comma', 'Dr. Tom M. Smith Jr., USN (Ret.)', array_replace($dense, [5 => '']), $name],
    ['name: no honorific', 'Tom M. Smith Jr., PhD, USN (Ret.)', array_replace($dense, [0 => '']), $name],
    ['name: no middle initial', 'Dr. Tom Smith Jr., PhD, USN (Ret.)', array_replace($dense, [2 => '']), $name],
    ['name: no service', 'Dr. Tom M. Smith Jr., PhD', array_replace($dense, [6 => '']), $name],
    ['name: last name only', 'Smith', ['', '', '', 'Smith', '', '', ''], $name],
];
foreach ($rows as [$label, $expected, $values, $format]) {
    $got = $cleanup(array_combine(range(1, count($values)), $values), $format);
    $check("cleanup: $label — " . json_encode($format) . ' expected ' . json_encode($expected) . ' got ' . json_encode($got),
        $got === $expected);
}

// Value characters are never mistaken for the pattern's: a value that opens
// with a separator or a unit mark survives beside an empty token.
$check('a value starting with a separator keeps it', $cleanup([1 => '', 2 => '·5'], '{1} {2}') === '·5');
$check('a value starting with a unit mark keeps it', $cleanup([1 => '', 2 => '.5'], '{1}{2}') === '.5');

// Policies: the same cleanup serves every output.
$check('slug policy: empty middle token', TokenEngine::render('{meta:a}-{meta:blank}-{meta:b}', $src,
    OutputPolicy::slug(static fn(string $v) => strtolower($v))) === 'alpha-beta');
$check('slug policy: empty bracketed token', TokenEngine::render('{meta:a} ({meta:blank})', $src,
    OutputPolicy::slug(static fn(string $v) => strtolower($v))) === 'alpha');

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
