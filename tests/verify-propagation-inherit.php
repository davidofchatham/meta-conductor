<?php
/**
 * H5 — The claim end-state matrix, and propagation's removal subtraction.
 *
 * Locks the merge/replace/skip decision matrix WITHOUT booting WordPress.
 *
 * WHAT MOVED (#62/#38 cluster 2). This harness used to exercise
 * `PropagationHandler::write_would_change_terms()`, a per-mode switch that
 * re-encoded `apply_terms_to_post()`'s semantics so propagation could decide
 * whether a write was worth making. Two coupled switches, no shared source. The
 * semantics now live once, in `TermOperations::compute_end_state()`, which BOTH
 * the write path and the short-circuit predicate ask — so this harness asks it
 * too, and the "would this write change terms" question it used to pose is now
 * literally `compute_end_state(...) !== $current`.
 *
 * The second half covers what propagation adds on top: the parent's removals
 * are subtracted from the end state in EVERY claim, which is how a term taken
 * off a parent still reaches its descendants once propagation pulls instead of
 * pushing (#62). The subtraction is deliberately claim-independent — the
 * pre-#62 removal walk ignored `conflict_handling` too.
 *
 * Protected/private methods → exercised via reflection.
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

// Current terms come from this global; the test sets it per case.
$GLOBALS['__child_terms'] = [];
if (!function_exists('wp_get_object_terms')) {
    function wp_get_object_terms($id, $tax, $args = []) { return $GLOBALS['__child_terms']; }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Handlers\PropagationHandler;

$handler = new PropagationHandler();

// Protected trait/handler methods. No setAccessible() call: it is a no-op since
// PHP 8.1 and deprecated in 8.5, and ReflectionMethod::invoke() already ignores
// visibility.
// The shared claim semantics, on the base trait.
$end_state = new ReflectionMethod($handler, 'compute_end_state');

// Propagation's own target: the claim end state minus the parent's removals.
$target = new ReflectionMethod($handler, 'target_term_ids');

$normalize = new ReflectionMethod($handler, 'normalize_term_ids');

/** Would applying $parent to $current under $mode change anything? */
$needs = static function (array $current, array $parent, string $mode) use ($end_state, $normalize, $handler): bool {
    return $end_state->invoke($handler, $current, $parent, $mode)
        !== $normalize->invoke($handler, $current);
};

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

// --- The end state itself, not just whether it differs. ---------------------
$check('replace end state is the incoming set',
    $end_state->invoke($handler, [1, 2], [6, 8], 'replace') === [6, 8]);
$check('merge end state is the union, sorted',
    $end_state->invoke($handler, [9, 1], [6, 8], 'merge') === [1, 6, 8, 9]);
$check('skip end state on an occupied taxonomy is the current set',
    $end_state->invoke($handler, [9, 1], [6, 8], 'skip') === [1, 9]);
$check('unknown claim writes nothing',
    $end_state->invoke($handler, [1, 9], [6, 8], 'nonsense') === [1, 9]);

// --- Propagation's removal subtraction: claim-independent. ------------------
$t = static fn(array $current, array $source, string $mode, array $removed): array
    => $target->invoke($handler, $current, $source, $mode, $removed);

$check('merge: a term the parent lost leaves the child',
    $t([6, 8], [6], 'merge', [8]) === [6]);
$check('merge: no removals leaves the union alone',
    $t([6, 8], [6], 'merge', []) === [6, 8]);
$check('replace: removal subtracts from the incoming set too',
    $t([6, 8], [6, 8], 'replace', [8]) === [6]);
$check('skip: an occupied child still loses what the parent lost',
    $t([6, 8], [], 'skip', [8]) === [6]);
$check('removal of a term the child never had is a no-op',
    $t([6], [6], 'merge', [99]) === [6]);
$check('removing everything empties the child',
    $t([8], [], 'merge', [8]) === []);

// --- A term-less parent is not an instruction to empty the child. -----------
// Both pre-#62 push paths bailed on `empty($parent_terms)`, and under `replace`
// that early return was the only thing standing between a parent that holds
// nothing in this taxonomy and a child stripped of its own terms.
$check('replace: a term-less parent leaves the child alone',
    $t([6, 8], [], 'replace', []) === [6, 8]);
$check('merge: a term-less parent leaves the child alone',
    $t([6, 8], [], 'merge', []) === [6, 8]);
$check('replace: a term-less parent still delivers its removals',
    $t([6, 8], [], 'replace', [8]) === [6]);

// --- Report. ----------------------------------------------------------------
$total = 23;
if ($fail) {
    fwrite(STDERR, "\nPROPAGATION-INHERIT FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "PROPAGATION-INHERIT OK — all $total assertions passed (claim end-state matrix + #62 removal subtraction).\n");
exit(0);
