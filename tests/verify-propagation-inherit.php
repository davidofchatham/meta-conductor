<?php
/**
 * H5 — Propagation inherit no-change short-circuit (Phase 3, B3/V12).
 *
 * Locks child_needs_inherit()'s per-conflict-mode decision matrix WITHOUT
 * booting WordPress: stub wp_get_object_terms to return the child's current
 * terms, then assert whether an inherit write would fire for each mode. This is
 * the logic that suppresses the duplicate-inherit write+log on save_post
 * double-fire / re-save (B3 follow-up).
 *
 * Private method → exercised via reflection.
 *
 * Run:  php tests/verify-propagation-inherit.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

// --- WP shim. ---------------------------------------------------------------
foreach (['add_action', 'add_filter'] as $fn) {
    if (!function_exists($fn)) { eval("function {$fn}() {}"); }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof \WP_Error_Stub; } }
if (!function_exists('absint'))      { function absint($n) { return abs((int) $n); } }

// Child current terms come from this global; the test sets it per case.
$GLOBALS['__child_terms'] = [];
if (!function_exists('wp_get_object_terms')) {
    function wp_get_object_terms($id, $tax, $args = []) { return $GLOBALS['__child_terms']; }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Handlers\PropagationHandler;

// Reach the private method.
$handler = new PropagationHandler();
$ref = new ReflectionMethod($handler, 'child_needs_inherit');
$needs =fn($current, $parent, $mode) => (function () use ($ref, $handler, $current, $parent, $mode) {
    $GLOBALS['__child_terms'] = $current;
    return $ref->invoke($handler, 99, 'breaker', $parent, $mode);
})();

$fail = [];
$check = function (string $name, bool $cond) use (&$fail) { if (!$cond) { $fail[] = $name; } };

// --- replace: write iff child != parent (exact set). ------------------------
$check('replace: empty child needs write',     $needs([],        [6, 8], 'replace') === true);
$check('replace: exact match skips',           $needs([6, 8],    [6, 8], 'replace') === false);
$check('replace: order-insensitive skip',      $needs([8, 6],    [6, 8], 'replace') === false);
$check('replace: differing set needs write',   $needs([6],       [6, 8], 'replace') === true);
$check('replace: extra child term needs write',$needs([6, 8, 9], [6, 8], 'replace') === true);

// --- merge: write iff parent has a term the child lacks. --------------------
$check('merge: empty child needs write',       $needs([],        [6, 8], 'merge') === true);
$check('merge: parent subset of child skips',  $needs([6, 8, 9], [6, 8], 'merge') === false);
$check('merge: missing one needs write',       $needs([6],       [6, 8], 'merge') === true);

// --- skip: write only when child empty. -------------------------------------
$check('skip: empty child needs write',        $needs([],        [6, 8], 'skip') === true);
$check('skip: non-empty child skips',          $needs([1],       [6, 8], 'skip') === false);

// --- Report. ----------------------------------------------------------------
$total = 10;
if ($fail) {
    fwrite(STDERR, "\nPROPAGATION-INHERIT FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "PROPAGATION-INHERIT OK — all $total assertions passed (B3/V12 short-circuit matrix).\n");
exit(0);
