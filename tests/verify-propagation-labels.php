<?php
/**
 * H4 — Propagation row-title snapshot harness (Phase 3, T3).
 *
 * Exercises WireframeBootstrap::snapshot_propagation_labels() end-to-end with
 * stubbed WP, WITHOUT booting WordPress. Catches what H1/H2 cannot:
 *   - cross-namespace FQN resolution (the Config\ConfigHelpers call that the
 *     autoload harness can't see — it only checks class_exists, not call sites)
 *   - the empty-post_types ⇒ no scope prefix (applies to all)
 *   - {slug:bool} checkbox map AND plain-list handling
 *   - claim suffix + disabled_prefix baking into row_title
 *     (stored merge|replace|skip render as contributing|owning|deferring —
 *      CONTEXT.md → Claim, ADR 0004)
 *
 * Also covers snapshot_claim_override_labels() (General tab), which shares
 * claim_label() with the propagation path. Both live here on purpose: one
 * vocabulary feeds BOTH row-title surfaces, so a rename can't leave one of
 * them stale. The dropdown option strings that restate the same vocabulary
 * are locked separately, in H3 (tests/verify-config-helpers.php).
 *
 * Loads the two real classes (ConfigHelpers + WireframeBootstrap) so the FQNs
 * actually resolve at call time.
 *
 * Run:  php tests/verify-propagation-labels.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// --- WP shim (only what the snapshot path touches at runtime). ---------------

if (!function_exists('__'))       { function __($t, $d = 'default') { return $t; } }
if (!function_exists('esc_html')) { function esc_html($t) { return $t; } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = 'default') { return $t; } }
if (!function_exists('add_action')) { function add_action() {} }
if (!function_exists('add_filter')) { function add_filter() {} }

// Post-type registry → label lookup.
$GLOBALS['__pt'] = [
    'page' => (object) ['name' => 'page', 'label' => 'Pages',       'hierarchical' => true,  'public' => true],
    'dept' => (object) ['name' => 'dept', 'label' => 'Departments', 'hierarchical' => true,  'public' => true],
    'post' => (object) ['name' => 'post', 'label' => 'Posts',       'hierarchical' => false, 'public' => true],
];
if (!function_exists('get_post_type_object')) {
    function get_post_type_object($slug) { return $GLOBALS['__pt'][$slug] ?? null; }
}
if (!function_exists('get_taxonomy')) {
    function get_taxonomy($slug) {
        $map = ['category' => (object) ['label' => 'Categories'], 'breaker' => (object) ['label' => 'Breakers']];
        return $map[$slug] ?? false;
    }
}

require dirname(__DIR__) . '/includes/admin/config/class-config-helpers.php';
require dirname(__DIR__) . '/includes/admin/class-wireframe-bootstrap.php';

use BWS\MetaConductor\Admin\WireframeBootstrap;

$fail = [];
$check = function (string $name, bool $cond) use (&$fail) { if (!$cond) { $fail[] = $name; } };

// --- Case 1: explicit post_types map, merge, enabled. ------------------------
$out = WireframeBootstrap::snapshot_propagation_labels([
    'propagation_rules' => [[
        'enabled'           => true,
        'post_types'        => ['page' => true, 'dept' => true],
        'taxonomy'          => 'category',
        'conflict_handling' => 'merge',
    ]],
]);
$r = $out['propagation_rules'][0];
$check('restricted scope prefix',     str_starts_with($r['row_title'], 'Pages, Departments: '));
$check('verb + taxonomy + children',  str_contains($r['row_title'], 'Copy Categories terms to children'));
$check('claim suffix = contributing', str_contains($r['row_title'], '(contributing)'));
$check('no arrow in title',           !str_contains($r['row_title'], '→'));

// --- Case 2: empty post_types ⇒ no scope prefix. ----------------------------
$out = WireframeBootstrap::snapshot_propagation_labels([
    'propagation_rules' => [[
        'enabled'           => true,
        'post_types'        => [],
        'taxonomy'          => 'breaker',
        'conflict_handling' => 'replace',
    ]],
]);
$r = $out['propagation_rules'][0];
$check('all-types ⇒ no prefix',        str_starts_with($r['row_title'], 'Copy Breakers terms to children'));
$check('claim suffix = owning',        str_contains($r['row_title'], '(owning)'));

// --- Case 3: plain-list post_types + disabled prefix. -----------------------
$out = WireframeBootstrap::snapshot_propagation_labels([
    'propagation_rules' => [[
        'enabled'           => false,
        'post_types'        => ['page'],
        'taxonomy'          => 'category',
        'conflict_handling' => 'skip',
    ]],
]);
$r = $out['propagation_rules'][0];
$check('disabled prefix first',        str_starts_with($r['row_title'], '[Disabled] '));
$check('plain-list scope resolves',    str_contains($r['row_title'], 'Pages: '));
$check('claim suffix = deferring',      str_contains($r['row_title'], '(deferring)'));

// --- Case 4: no propagation_rules key ⇒ untouched. --------------------------
$untouched = WireframeBootstrap::snapshot_propagation_labels(['other' => 1]);
$check('non-propagation payload untouched', $untouched === ['other' => 1]);

// --- Case 5: General-tab claim overrides share claim_label(). ---------------
// Same vocabulary, second surface — locks the two helpers together so a change
// to one claim name can't silently leave the other on the old wording.
$out = WireframeBootstrap::snapshot_claim_override_labels([
    'conflict_handling_overrides' => [
        ['taxonomy' => 'category', 'mode' => 'replace'],
        ['taxonomy' => 'breaker',  'mode' => 'skip'],
        ['taxonomy' => 'category'],                      // mode absent ⇒ default
        ['taxonomy' => 'ghost_tax', 'mode' => 'merge'],  // unresolvable taxonomy
    ],
]);
$rows = $out['conflict_handling_overrides'];
$check('override title = label + owning',  $rows[0]['row_title'] === 'Categories: owning');
$check('override title = deferring',       $rows[1]['row_title'] === 'Breakers: deferring');
$check('absent mode ⇒ contributing',       $rows[2]['row_title'] === 'Categories: contributing');
$check('unresolvable tax falls back to slug', $rows[3]['row_title'] === 'ghost_tax: contributing');

// --- Case 6: no overrides key ⇒ untouched. ----------------------------------
$untouched = WireframeBootstrap::snapshot_claim_override_labels(['other' => 1]);
$check('non-override payload untouched', $untouched === ['other' => 1]);

// --- Report. ----------------------------------------------------------------
$total = 15;
if ($fail) {
    fwrite(STDERR, "\nPROPAGATION-LABELS FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "PROPAGATION-LABELS OK — all $total assertions passed (V11 snapshot + FQN resolution).\n");
exit(0);
