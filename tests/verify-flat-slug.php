<?php
/**
 * Flat slug harness (FW-51 05).
 *
 * `Tokens\FlatSlug` is pure, so the reserved names, the numbering order and the
 * truncation are pinned here on host PHP with a taken-slug list standing in for
 * the database. The per-TYPE scope is the handler's uniqueness lookup — pinned
 * below as a source check (no `post_parent` in it, flat branch numbers through
 * `FlatSlug`) and swept end to end on the testbed.
 *
 * Run:  php tests/verify-flat-slug.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Tokens\FlatSlug;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

$feeds = ['feed', 'rdf', 'rss', 'rss2', 'atom']; // core's default $wp_rewrite->feeds

// --- Reserved names: core's check plus `page` and `attachment`. -------------

foreach (['feed', 'rdf', 'rss', 'rss2', 'atom'] as $n) {
    $check("core feed name '$n' is reserved", FlatSlug::reserved($n, $feeds));
}
foreach (['embed', 'page', 'attachment', '7', '2026', '0', 'page2', 'page10'] as $n) {
    $check("'$n' is reserved", FlatSlug::reserved($n, $feeds));
}
foreach (['news', 'page-2', 'feedback', 'embedded', 'attachments', 'a1', '1a', 'pages', 'paged2', 'event-2026', ''] as $n) {
    $check("'$n' is NOT reserved", !FlatSlug::reserved($n, $feeds));
}
$check('a custom feed name from the rewrite is reserved', FlatSlug::reserved('podcast', [...$feeds, 'podcast']));
$check('without the feed list, feed is free (the handler supplies it)', !FlatSlug::reserved('feed'));
$check('a renamed pagination base is the reserved one', FlatSlug::reserved('seite', $feeds, 'seite') && FlatSlug::reserved('seite3', $feeds, 'seite')
    && !FlatSlug::reserved('page', $feeds, 'seite') && !FlatSlug::reserved('page2', $feeds, 'seite') && FlatSlug::reserved('3', $feeds, 'seite'));

// --- Numbering. ---------------------------------------------------------------

$free = static function (array $taken) use ($feeds, &$tried) {
    $tried = [];
    return static function (string $c) use ($taken, $feeds, &$tried): bool {
        $tried[] = $c;
        return !in_array($c, $taken, true) && !FlatSlug::reserved($c, $feeds);
    };
};

$check('a free slug is returned untouched, one lookup', FlatSlug::number('news', $free([])) === 'news' && $tried === ['news']);
$check('a taken slug is numbered from 2, in order',
    FlatSlug::number('news', $free(['news', 'news-2', 'news-3'])) === 'news-4' && $tried === ['news', 'news-2', 'news-3', 'news-4']);
$check('a reserved slug is numbered', FlatSlug::number('page', $free([])) === 'page-2' && $tried === ['page', 'page-2']);
$check('a bare number is numbered, and the numbered form is free', FlatSlug::number('2026', $free([])) === '2026-2');
$check('feed is numbered past a taken feed-2', FlatSlug::number('feed', $free(['feed-2'])) === 'feed-3');

$long = str_repeat('a', 200);
$out  = FlatSlug::number($long, $free([$long]));
$check('a 200-char slug is cut so the suffixed slug stays within 200', $out === str_repeat('a', 198) . '-2' && strlen($out) === 200);
$check('two-digit suffixes shrink the stem further', strlen(FlatSlug::number($long, $free([$long, ...array_map(fn($i) => str_repeat('a', 198) . "-$i", range(2, 9))]))) === 200);
$enc = str_repeat('a', 197) . '%e2%80%94'; // cut lands inside the encoded byte
$out = FlatSlug::number($enc, $free([$enc]));
$check('a cut through a percent-encoded byte leaves no stray %', !preg_match('/%[0-9a-f]?-\d+$/i', $out) && strlen($out) <= 200);

// --- The handler's side: per-type scope, flat branch numbers through FlatSlug. --

$src = file_get_contents(dirname(__DIR__) . '/includes/handlers/class-title-slug-handler.php');
preg_match('/private function slug_is_unique\b.*?\R    }\R/s', $src, $m);
$check('the uniqueness lookup is per type (no post_parent in it)', isset($m[0]) && !str_contains($m[0], 'post_parent') && str_contains($m[0], 'post_type = %s'));
$check('a flat type numbers through FlatSlug, a non-flat type still through core',
    str_contains($src, 'FlatSlug::number(') && str_contains($src, 'wp_unique_post_slug('));
preg_match('/protected function make_unique_slug\b.*?\R    }\R/s', $src, $m);
$check('the ladder runs before numbering', isset($m[0]) && strpos($m[0], 'SlugLadder::walk(') < strpos($m[0], 'FlatSlug::number('));

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nFLAT-SLUG FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "FLAT-SLUG OK — all $total assertions passed (reserved names, numbering order, truncation, per-type scope; FW-51 05).\n");
exit(0);
