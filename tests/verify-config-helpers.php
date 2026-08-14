<?php
/**
 * H3 — ConfigHelpers field-builder harness (Phase 3).
 *
 * Locks the post-type field-builder invariants WITHOUT booting WordPress:
 * stub get_post_types() + __(), require only the ConfigHelpers class file,
 * call the builders, assert their shape. Pure-declarative builders (no method
 * executes WP at class load), so requiring the single file is safe.
 *
 * Covers the shared id-lock contract: post_types_field / post_status_field
 * force their canonical id even under override — handlers read `post_types`
 * and `post_status` BY NAME, so a renamed id would not error, it would
 * silently widen the rule to every post type / status.
 *
 * Also covers the 0.8.0 builder collapse (#38 cluster 1): six copies of the
 * same slug=>label loop became one `label_options()` behind three public
 * accessors, and the two near-identical checkbox field builders became one
 * `gate_field()` base. The assertions below are on the OBSERVABLE contract of
 * each accessor — placeholder present or absent, options complete — because
 * that is what the collapse had to preserve.
 *
 * SPEC §V5's hierarchical-only post-type field is GONE as of 0.8.0: the
 * ordered term repeater has one shared post-type gate across rule types, so
 * propagation's parent/child requirement is stated as a type-conditioned note
 * on the repeater (locked in H11) instead of by withholding options.
 *
 * Also locks the claim vocabulary (ADR 0004): CLAIM_NAMES is the one mapping
 * behind both config dropdowns and both row-title snapshots, so this harness
 * asserts the option strings, the `<claim>: ` lead-in, and claim_name()'s
 * fallback. Note claim_field() INVERTS the id-lock — its id must stay
 * overridable, since the two surfaces store under different keys.
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

// Fake taxonomy registry — the placeholder/no-placeholder split is shared with
// the post-type accessors, so both sides of label_options() are exercised.
if (!function_exists('get_taxonomies')) {
    function get_taxonomies($args = [], $output = 'names') {
        $registry = [
            'category' => (object) ['name' => 'category', 'label' => 'Categories', 'public' => true, 'hierarchical' => true],
            'post_tag' => (object) ['name' => 'post_tag', 'label' => 'Tags',       'public' => true, 'hierarchical' => false],
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

// Post statuses — only what post_status_checkbox_options() touches.
if (!function_exists('get_post_status_object')) {
    function get_post_status_object($slug) {
        $labels = ['publish' => 'Published', 'future' => 'Scheduled', 'draft' => 'Draft',
                   'pending' => 'Pending Review', 'private' => 'Private'];
        return isset($labels[$slug]) ? (object) ['name' => $slug, 'label' => $labels[$slug]] : null;
    }
}
if (!function_exists('get_post_stati')) {
    function get_post_stati($args = [], $output = 'names') { return []; }
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

// The one shared post-type gate offers EVERY public type, flat included —
// the ordered repeater has a single post_types subfield across rule types.
$a_opts = ConfigHelpers::post_types_checkbox_options();
$check('post-type opts include flat post', isset($a_opts['post']));
$check('post-type opts include page',      isset($a_opts['page']));
$check('post-type opts include dept',      isset($a_opts['dept']));
$check('checkbox opts have no placeholder', !array_key_exists('', $a_opts));

// The placeholder half of the collapsed loop: a SELECT gets a leading empty
// row, and it must come first (it is the field's default rendering).
$t_opts = ConfigHelpers::taxonomy_options();
$check('taxonomy select leads with placeholder', array_key_first($t_opts) === '');
$check('taxonomy select placeholder overridable',
    array_values(ConfigHelpers::taxonomy_options('— Pick one —'))[0] === '— Pick one —');
$check('taxonomy checkbox opts have no placeholder',
    !array_key_exists('', ConfigHelpers::taxonomy_checkbox_options()));
$check('taxonomy checkbox opts carry the same taxonomies',
    array_keys(ConfigHelpers::taxonomy_checkbox_options())
        === array_values(array_filter(array_keys($t_opts), fn($k) => $k !== '')));

// id-lock: both checkbox gates force their canonical id, even when an
// override tries to rename, and still honour every other override.
$a_field = ConfigHelpers::post_types_field(['id' => 'evil', 'columns' => 6]);
$check('post_types field id forced',        $a_field['id'] === 'post_types');
$check('post_types field honors override',  ($a_field['columns'] ?? null) === 6);
$check('post_types field is checkboxes',    $a_field['type'] === 'checkboxes');
$check('post_types field offers flat type', isset($a_field['args']['options']['post']));

$s_field = ConfigHelpers::post_status_field(['id' => 'evil', 'label' => 'Only when']);
$check('post_status field id forced',       $s_field['id'] === 'post_status');
$check('post_status field honors override', $s_field['label'] === 'Only when');
$check('post_status field is checkboxes',   $s_field['type'] === 'checkboxes');

// --- Claim field (ADR 0004). ------------------------------------------------
// Unlike post_types_field the id is deliberately NOT forced — the two surfaces
// store under different keys — so the id-lock assertion is inverted here.

$c_rule = ConfigHelpers::claim_field('child', ['id' => 'conflict_handling']);
$c_glob = ConfigHelpers::claim_field('post',  ['id' => 'mode', 'label' => 'Default claim on terms']);

$check('claim field id NOT forced',        $c_rule['id'] === 'conflict_handling' && $c_glob['id'] === 'mode');
$check('claim field label overridable',    $c_glob['label'] === 'Default claim on terms');
$check('claim field defaults to merge',    $c_rule['default'] === 'merge');
$check('claim options are the stored keys', array_keys($c_rule['args']['options']) === ['replace', 'merge', 'skip']);
$check('child subject wording',            str_contains($c_rule['args']['options']['skip'], 'the child has no terms'));
$check('post subject wording',             str_contains($c_glob['args']['options']['skip'], 'the post has no terms'));

// Every option leads with its domain term, then a colon (ADR 0004) — this is
// what keeps the dropdown correlated with the docs and the row titles.
foreach (ConfigHelpers::CLAIM_NAMES as $stored => $claim) {
    $check("option '$stored' leads with '$claim:'",
        str_starts_with($c_rule['args']['options'][$stored], ucfirst($claim) . ': '));
}

// claim_name() is the one mapping both dropdowns and both row titles read.
$check('claim_name maps replace ⇒ owning',   ConfigHelpers::claim_name('replace') === 'owning');
$check('claim_name unknown ⇒ contributing',  ConfigHelpers::claim_name('bogus') === 'contributing');

// The other half of the claim surface — coercing the General tab's override
// ROWS back to the canonical {slug: mode} dict — is a storage-adapter concern,
// not a field builder. It lives on OptionRuleStorage; see H9.

// --- Report. ----------------------------------------------------------------

$total = 26;
if ($fail) {
    fwrite(STDERR, "\nCONFIG-HELPERS FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) {
        fwrite(STDERR, "  \xE2\x9C\x97 $f\n");
    }
    exit(1);
}

fwrite(STDOUT, "CONFIG-HELPERS OK — all $total assertions passed (builder collapse + id-lock + claim).\n");
exit(0);
