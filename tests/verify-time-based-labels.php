<?php
/**
 * H6 — Time-based row-title snapshot harness (Phase 3, T4).
 *
 * Exercises the date-window branch of
 * WireframeBootstrap::snapshot_term_rule_labels() no-WP. Locks the
 * date-first sentence schema: "{start}–{end}: Apply {target} to {scope}{ with
 * {filter}}" — en dash no spaces, "posts" vs post-type scope, filter clause
 * (specific terms > any-taxonomy > none), disabled prefix. (SPEC §V11)
 *
 * Rescoped in 0.8.0 (#57): the rules now ride in the ordered `term_rules`
 * repeater and each row carries `type`, so the fixtures below set it — a row
 * that lost its type would fall through to the "(no rule type chosen)" title
 * rather than this schema, and that is worth failing on.
 *
 * Run:  php tests/verify-time-based-labels.php
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

if (!function_exists('__'))         { function __($t, $d = 'default') { return $t; } }
if (!function_exists('esc_html'))   { function esc_html($t) { return $t; } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = 'default') { return $t; } }
if (!function_exists('add_action')) { function add_action() {} }
if (!function_exists('add_filter')) { function add_filter() {} }
if (!function_exists('is_wp_error')){ function is_wp_error($t) { return false; } }

$GLOBALS['__pt'] = [
    'post' => (object) ['name' => 'post', 'label' => 'Posts', 'hierarchical' => false, 'public' => true],
    'page' => (object) ['name' => 'page', 'label' => 'Pages', 'hierarchical' => true,  'public' => true],
];
if (!function_exists('get_post_type_object')) {
    function get_post_type_object($slug) { return $GLOBALS['__pt'][$slug] ?? null; }
}
// Terms: 9 = Shakers: Grandchild ii, 5 = Breakers: Term A.
$GLOBALS['__terms'] = [
    9 => (object) ['term_id' => 9, 'name' => 'Grandchild ii', 'taxonomy' => 'shaker'],
    5 => (object) ['term_id' => 5, 'name' => 'Term A',        'taxonomy' => 'breaker'],
];
if (!function_exists('get_term')) {
    function get_term($id, $tax = '') { return $GLOBALS['__terms'][(int) $id] ?? false; }
}
if (!function_exists('get_taxonomy')) {
    function get_taxonomy($slug) {
        $map = ['shaker' => (object) ['label' => 'Shakers'], 'breaker' => (object) ['label' => 'Breakers']];
        return $map[$slug] ?? false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Admin\WireframeBootstrap;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$KIND = OptionRuleStorage::KIND_TERM;

$fail = [];
$check = function (string $name, bool $cond) use (&$fail) { if (!$cond) { $fail[] = $name; } };
$title = function (array $rule) use ($KIND) {
    $rule['type'] = 'time_based_rules';
    $out = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [$rule]]);
    return $out[$KIND][0]['row_title'];
};

// Case 1: all types, no filter.
$t = $title([
    'enabled' => true, 'post_types' => [], 'target_term_id' => ['9'],
    'start_date' => '2026-05-26', 'end_date' => '2026-05-27',
    'filter_taxonomies' => [], 'filter_terms' => [],
]);
$check('window en-dash no spaces', str_starts_with($t, '2026-05-26' . "\xE2\x80\x93" . '2026-05-27: '));
$check('target term resolved',     str_contains($t, 'Apply Shakers: Grandchild ii'));
$check('all-types scope = posts',  str_contains($t, 'to posts'));
$check('no filter clause',         !str_contains($t, 'with'));

// Case 2: post-type scope.
$t = $title([
    'enabled' => true, 'post_types' => ['page' => true], 'target_term_id' => ['9'],
    'start_date' => '2026-05-26', 'end_date' => '2026-05-27',
]);
$check('scoped to Pages', str_contains($t, 'to Pages'));

// Case 3: filter by specific terms (wins over taxonomy).
$t = $title([
    'enabled' => true, 'post_types' => [], 'target_term_id' => ['9'],
    'start_date' => '2026-05-26', 'end_date' => '2026-05-27',
    'filter_taxonomies' => ['breaker' => true], 'filter_terms' => ['5'],
]);
$check('filter terms clause', str_contains($t, 'with Breakers: Term A'));
$check('terms win over taxonomy', !str_contains($t, 'any'));

// Case 4: filter by taxonomy only.
$t = $title([
    'enabled' => true, 'post_types' => [], 'target_term_id' => ['9'],
    'start_date' => '2026-05-26', 'end_date' => '2026-05-27',
    'filter_taxonomies' => ['breaker' => true], 'filter_terms' => [],
]);
$check('taxonomy filter clause', str_contains($t, 'with any Breakers term'));

// Case 5: disabled prefix.
$t = $title([
    'enabled' => false, 'post_types' => [], 'target_term_id' => ['9'],
    'start_date' => '2026-05-26', 'end_date' => '2026-05-27',
]);
$check('disabled prefix first', str_starts_with($t, '[Disabled] '));

// Case 6: payload with no rule list untouched.
$u = WireframeBootstrap::snapshot_term_rule_labels(['other' => 1]);
$check('non-rule payload untouched', $u === ['other' => 1]);

$total = 11;
if ($fail) {
    fwrite(STDERR, "\nTIME-BASED-LABELS FAIL — " . count($fail) . "/$total failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "TIME-BASED-LABELS OK — all $total assertions passed (V11 date-first title).\n");
exit(0);
