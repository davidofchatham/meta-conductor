<?php
/**
 * H3 — ConfigHelpers field-builder harness (Phase 3).
 *
 * Locks the post-type field-builder invariants WITHOUT booting WordPress:
 * stub get_post_types() + __(), require only the ConfigHelpers class file,
 * call the builders, assert their shape. Pure-declarative builders (no method
 * executes WP at class load), so requiring the single file is safe.
 *
 * Covers SPEC §V5 (propagation hierarchical-only field) + the shared id-lock
 * contract (post_types_field / hierarchical_post_types_field force the
 * canonical `post_types` id even under override — should_process_post reads it
 * by name, so a renamed id would silently gate every post type).
 *
 * Run:  php tests/verify-config-helpers.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// --- WP shim. ---------------------------------------------------------------

// Translation passthrough.
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}

// Fake post-type registry: 2 hierarchical (page, dept), 1 flat (post).
// get_post_types() must honor the hierarchical filter so we can prove the
// hierarchical field excludes flat types.
if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names') {
        $registry = [
            'post' => (object) ['name' => 'post', 'label' => 'Posts',       'public' => true, 'hierarchical' => false],
            'page' => (object) ['name' => 'page', 'label' => 'Pages',       'public' => true, 'hierarchical' => true],
            'dept' => (object) ['name' => 'dept', 'label' => 'Departments', 'public' => true, 'hierarchical' => true],
        ];
        $out = [];
        foreach ($registry as $slug => $obj) {
            foreach ($args as $k => $v) {
                if ($obj->$k !== $v) {
                    continue 2;
                }
            }
            $out[$slug] = ($output === 'objects') ? $obj : $slug;
        }
        return $out;
    }
}

// --- Load the class under test (declare-only, no WP at load). ----------------

require dirname(__DIR__) . '/includes/admin/config/class-config-helpers.php';

use BWS\MetaConductor\Admin\Config\ConfigHelpers;

// --- Assertions. ------------------------------------------------------------

$fail = [];
$check = function (string $name, bool $cond) use (&$fail) {
    if (!$cond) {
        $fail[] = $name;
    }
};

// V5: hierarchical field offers ONLY hierarchical types.
$h_opts = ConfigHelpers::hierarchical_post_types_checkbox_options();
$check('hierarchical opts include page',  isset($h_opts['page']));
$check('hierarchical opts include dept',  isset($h_opts['dept']));
$check('hierarchical opts EXCLUDE post',  !isset($h_opts['post']));
$check('hierarchical opts have no placeholder', !array_key_exists('', $h_opts));

// All-types field still offers the flat type (regression guard on the split).
$a_opts = ConfigHelpers::post_types_checkbox_options();
$check('all-types opts include post', isset($a_opts['post']));
$check('all-types opts include page', isset($a_opts['page']));

// id-lock: both fields force `post_types`, even when an override tries to rename.
$h_field = ConfigHelpers::hierarchical_post_types_field(['id' => 'evil', 'columns' => 6]);
$check('hierarchical field id forced to post_types', $h_field['id'] === 'post_types');
$check('hierarchical field honors non-id override',  ($h_field['columns'] ?? null) === 6);
$check('hierarchical field is checkboxes',           $h_field['type'] === 'checkboxes');
$check('hierarchical field options hierarchical-only', !isset($h_field['args']['options']['post']));

$a_field = ConfigHelpers::post_types_field(['id' => 'evil']);
$check('all-types field id forced to post_types', $a_field['id'] === 'post_types');

// --- Report. ----------------------------------------------------------------

$total = 11;
if ($fail) {
    fwrite(STDERR, "\nCONFIG-HELPERS FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) {
        fwrite(STDERR, "  \xE2\x9C\x97 $f\n");
    }
    exit(1);
}

fwrite(STDOUT, "CONFIG-HELPERS OK — all $total assertions passed (V5 + id-lock).\n");
exit(0);
