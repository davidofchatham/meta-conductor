<?php
/**
 * H4 — Term-rule row-title snapshot harness (Phase 3 T3; rescoped 0.8.0/#57).
 *
 * Exercises WireframeBootstrap::snapshot_term_rule_labels() end-to-end with
 * stubbed WP, WITHOUT booting WordPress. Catches what H1/H2 cannot:
 *   - cross-namespace FQN resolution (the Config\ConfigHelpers and
 *     Handlers\HierarchicalHandler calls the autoload harness can't see — it
 *     checks class_exists, not call sites)
 *   - dispatch on each row's `type`, which is what one repeater made possible
 *     and what a wrong `type` value would silently break
 *   - the empty-post_types ⇒ no scope prefix (applies to all)
 *   - {slug:bool} checkbox map AND plain-list handling
 *   - claim suffix + disabled_prefix baking into row_title
 *     (stored merge|replace|skip render as contributing|owning|deferring —
 *      CONTEXT.md → Claim, ADR 0004)
 *   - the disabled prefix reaching EVERY type in the list, which is #30's
 *     interim follow-up and was previously true of two types only
 *
 * Time-based rows have their own file (H6) — same entry point, separate
 * schema. Also covers snapshot_claim_override_labels() (General tab), which
 * shares claim_label() with the rule path and is the one snapshot that did
 * NOT rescope onto a repeater. Both live here on purpose: one vocabulary
 * feeds BOTH row-title surfaces, so a rename can't leave one of them stale.
 * The dropdown option strings that restate the same vocabulary are locked
 * separately, in H3 (tests/verify-config-helpers.php).
 *
 * Run:  php tests/verify-propagation-labels.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

// --- WP shim (only what the snapshot path touches at runtime). ---------------

if (!function_exists('__'))       { function __($t, $d = 'default') { return $t; } }
if (!function_exists('esc_html')) { function esc_html($t) { return $t; } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = 'default') { return $t; } }
if (!function_exists('add_action')) { function add_action() {} }
if (!function_exists('add_filter')) { function add_filter() {} }
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return false; } }

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

// The real autoloader: HierarchicalHandler::behavior_key() is part of the
// hierarchical title, and loading that class pulls its base + traits.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Admin\WireframeBootstrap;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$KIND = OptionRuleStorage::KIND_TERM;

$fail = [];
$check = function (string $name, bool $cond) use (&$fail) { if (!$cond) { $fail[] = $name; } };

/** Snapshot one row and hand back its baked title. */
$title = function (array $rule) use ($KIND) {
    $out = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [$rule]]);
    // Row titles LEAD with the list position since the #65 UX follow-up;
    // asserted there, stripped here — these cases are about the schema.
    return preg_replace('/^#\d+ /', '', $out[$KIND][0]['row_title']);
};

// --- Propagation: explicit post_types map, merge, enabled. ------------------
$t = $title([
    'type'              => 'propagation_rules',
    'enabled'           => true,
    'post_types'        => ['page' => true, 'dept' => true],
    'taxonomy'          => 'category',
    'conflict_handling' => 'merge',
]);
$check('restricted scope prefix',     str_starts_with($t, 'Pages, Departments: '));
$check('verb + taxonomy + children',  str_contains($t, 'Copy Categories terms to children'));
$check('claim suffix = contributing', str_contains($t, '(contributing)'));
$check('no arrow in title',           !str_contains($t, '→'));

// --- Propagation: empty post_types ⇒ no scope prefix. -----------------------
$t = $title([
    'type'              => 'propagation_rules',
    'enabled'           => true,
    'post_types'        => [],
    'taxonomy'          => 'breaker',
    'conflict_handling' => 'replace',
]);
$check('all-types ⇒ no prefix', str_starts_with($t, 'Copy Breakers terms to children'));
$check('claim suffix = owning', str_contains($t, '(owning)'));

// --- Propagation: plain-list post_types + disabled prefix. ------------------
$t = $title([
    'type'              => 'propagation_rules',
    'enabled'           => false,
    'post_types'        => ['page'],
    'taxonomy'          => 'category',
    'conflict_handling' => 'skip',
]);
$check('disabled prefix first',     str_starts_with($t, '[Disabled] '));
$check('plain-list scope resolves', str_contains($t, 'Pages: '));
$check('claim suffix = deferring',  str_contains($t, '(deferring)'));

// --- Hierarchical: the #16 outcome selector reaches the title. --------------
$check('hierarchical: outcome + depth',
    $title([
        'type'                 => 'hierarchical_rules',
        'enabled'              => true,
        'taxonomy'             => 'category',
        'inheritance_behavior' => 'both_always',
        'inheritance_depth'    => 'immediate',
    ]) === 'Inherit Categories: ancestors and descendants (one level)');

$check('hierarchical: default depth reads all levels',
    str_contains($title([
        'type'                 => 'hierarchical_rules',
        'taxonomy'             => 'breaker',
        'inheritance_behavior' => 'descendants_smart',
    ]), 'descendants when none picked (all levels)'));

// A row saved before #16 carries only the mechanism pair; its title must
// still name the outcome that pair produces, not "(nothing)".
$check('hierarchical: legacy pair maps to an outcome',
    str_contains($title([
        'type'               => 'hierarchical_rules',
        'taxonomy'           => 'category',
        'hierarchy_direction' => 'both',
        'expansion_behavior' => 'never',
    ]), 'ancestors (all levels)'));

$check('hierarchical: disabled prefix applies',
    str_starts_with($title([
        'type'                 => 'hierarchical_rules',
        'enabled'              => false,
        'taxonomy'             => 'category',
        'inheritance_behavior' => 'ancestors',
    ]), '[Disabled] Inherit Categories'));

// --- Level restriction: mode phrase + the #32 ancestors clause. -------------
$check('level restriction: mode phrase',
    $title([
        'type'             => 'hierarchical_level_restriction_rules',
        'enabled'          => true,
        'taxonomy'         => 'breaker',
        'restriction_mode' => 'deepest_only',
    ]) === 'Restrict Breakers to the deepest level');

$check('level restriction: ancestors clause in ANY mode',
    $title([
        'type'              => 'hierarchical_level_restriction_rules',
        'taxonomy'          => 'breaker',
        'restriction_mode'  => 'shallowest_only',
        'include_ancestors' => true,
        'post_types'        => ['page'],
    ]) === 'Pages: Restrict Breakers to the shallowest level, keeping ancestors');

$check('level restriction: absent mode ⇒ one per level',
    str_contains($title([
        'type'     => 'hierarchical_level_restriction_rules',
        'taxonomy' => 'breaker',
    ]), 'to one term per level'));

// --- An untyped row stays findable rather than going blank. -----------------
$check('untyped row is named, not empty',
    $title(['enabled' => true, 'taxonomy' => 'category']) === '(no rule type chosen)');

// --- No term_rules key ⇒ untouched. -----------------------------------------
$untouched = WireframeBootstrap::snapshot_term_rule_labels(['other' => 1]);
$check('non-rule payload untouched', $untouched === ['other' => 1]);

// --- General-tab claim overrides share claim_label(). -----------------------
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

// --- No overrides key ⇒ untouched. ------------------------------------------
$untouched = WireframeBootstrap::snapshot_claim_override_labels(['other' => 1]);
$check('non-override payload untouched', $untouched === ['other' => 1]);

// --- Report. ----------------------------------------------------------------
$total = 22;
if ($fail) {
    fwrite(STDERR, "\nTERM-RULE-LABELS FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "TERM-RULE-LABELS OK — all $total assertions passed (V11 snapshot, per-type dispatch, FQN resolution).\n");
