<?php
/**
 * H4 — Propagation row-title snapshot harness (Phase 3, T3).
 *
 * Exercises WireframeBootstrap::snapshot_propagation_labels() end-to-end with
 * stubbed WP, WITHOUT booting WordPress. Catches what H1/H2 cannot:
 *   - cross-namespace FQN resolution (the Config\ConfigHelpers call that the
 *     autoload harness can't see — it only checks class_exists, not call sites)
 *   - the empty-post_types ⇒ "All post types" child-scope label
 *   - {slug:bool} checkbox map AND plain-list handling
 *   - conflict_label + disabled_prefix baking into the leading token
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
$check('scope = human labels',        $r['scope_label'] === 'Pages, Departments');
$check('tax_label resolved',          $r['tax_label'] === 'Categories');
$check('conflict_label = merge',      $r['conflict_label'] === 'merge');

// --- Case 2: empty post_types ⇒ "All post types". ---------------------------
$out = WireframeBootstrap::snapshot_propagation_labels([
    'propagation_rules' => [[
        'enabled'           => true,
        'post_types'        => [],
        'taxonomy'          => 'breaker',
        'conflict_handling' => 'replace',
    ]],
]);
$r = $out['propagation_rules'][0];
$check('empty scope ⇒ All post types', $r['scope_label'] === 'All post types');
$check('conflict_label = replace',     $r['conflict_label'] === 'replace');

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
$check('plain-list scope resolves',    str_contains($r['scope_label'], 'Pages'));
$check('disabled prefix baked in',     str_starts_with($r['scope_label'], '[Disabled] '));
$check('conflict_label = skip if set', $r['conflict_label'] === 'skip if set');

// --- Case 4: no propagation_rules key ⇒ untouched. --------------------------
$untouched = WireframeBootstrap::snapshot_propagation_labels(['other' => 1]);
$check('non-propagation payload untouched', $untouched === ['other' => 1]);

// --- Report. ----------------------------------------------------------------
$total = 11;
if ($fail) {
    fwrite(STDERR, "\nPROPAGATION-LABELS FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "PROPAGATION-LABELS OK — all $total assertions passed (V11 snapshot + FQN resolution).\n");
exit(0);
