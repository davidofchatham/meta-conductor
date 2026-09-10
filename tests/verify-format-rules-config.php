<?php
/**
 * H12 — Ordered format-rule repeater shape harness (0.8.0, #59).
 *
 * H11's twin for the `format_rules` kind. The hazard is identical and is NOT
 * lessened by there being one rule type: Wireframe drops a condition-hidden
 * subfield at sanitize (`RepeaterField::sanitize` skips it,
 * `Validator::validateRepeater` skips it too), so a wrong `conditions` clause
 * stops persisting a value with no error, no UI symptom and no lint failure.
 *
 * With a single type the dangerous direction inverts. In the term list the
 * classic bug is a gate that is too NARROW; here it is a gate that exists at
 * all where it should not — a `conditions` clause on `post_type` or `name`
 * would evaluate false for the only type there is and delete the field
 * outright. So the shared frame's ungatedness is asserted first, and the
 * visible-subfield set is computed through Wireframe's REAL
 * `Conditions::evaluate()`, the same evaluator the sanitizer runs.
 *
 * Also locks #59's structural claims: the repeater binds the persisted kind
 * list, the `type` select stores the legacy key `title_slug_rules` and keeps
 * its untyped placeholder, the row-title snapshot replaced the live `{name}`
 * template, and the save-time projection lands rows back in
 * `title_slug_rules` so a rule authored here still fires.
 *
 * Run:  php tests/verify-format-rules-config.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

// --- WP shim (only what building the config touches). ------------------------

if (!function_exists('__'))         { function __($t, $d = 'default') { return $t; } }
if (!function_exists('esc_html'))   { function esc_html($t) { return $t; } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = 'default') { return $t; } }
if (!function_exists('esc_attr__')) { function esc_attr__($t, $d = 'default') { return $t; } }
if (!function_exists('add_action')) { function add_action() {} }
if (!function_exists('add_filter')) { function add_filter() {} }
if (!function_exists('_n'))         { function _n($s, $p, $n, $d = 'default') { return $n === 1 ? $s : $p; } }
// The collision section (#65) reads its findings from an option of its own.
// Absent here, which is the "never saved" state — the section renders the
// re-check button and no notice.
if (!function_exists('get_option'))    { function get_option($name, $default = false) { return $default; } }
if (!function_exists('update_option')) { function update_option($name, $value, $autoload = null) { return true; } }
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return false; } }

// `attachment` is registered here deliberately — the post-type select is
// supposed to drop it, and a registry without it could not tell a working
// filter from a deleted one.
if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names') {
        $registry = [
            'post'       => (object) ['name' => 'post',       'label' => 'Posts',       'public' => true, 'hierarchical' => false],
            'page'       => (object) ['name' => 'page',       'label' => 'Pages',       'public' => true, 'hierarchical' => true],
            'attachment' => (object) ['name' => 'attachment', 'label' => 'Media',       'public' => true, 'hierarchical' => false],
            'mc_item'    => (object) ['name' => 'mc_item',    'label' => 'MC Items',    'public' => true, 'hierarchical' => false],
        ];
        $out = [];
        foreach ($registry as $slug => $obj) {
            foreach ($args as $k => $v) {
                if ($obj->$k !== $v) { continue 2; }
            }
            $out[$slug] = ($output === 'objects') ? $obj : $slug;
        }
        return $out;
    }
}
if (!function_exists('get_taxonomies')) {
    function get_taxonomies($args = [], $output = 'names') {
        $registry = [
            'category' => (object) ['name' => 'category', 'label' => 'Categories', 'public' => true, 'hierarchical' => true],
        ];
        $out = [];
        foreach ($registry as $slug => $obj) {
            foreach ($args as $k => $v) {
                if ($obj->$k !== $v) { continue 2; }
            }
            $out[$slug] = ($output === 'objects') ? $obj : $slug;
        }
        return $out;
    }
}
if (!function_exists('get_terms')) { function get_terms($args = []) { return []; } }
if (!function_exists('get_term'))  { function get_term($id) { return null; } }
if (!function_exists('get_post_status_object')) {
    function get_post_status_object($slug) { return null; }
}
if (!function_exists('get_post_stati')) { function get_post_stati($a = [], $o = 'names') { return []; } }
if (!function_exists('get_taxonomy')) {
    function get_taxonomy($slug) {
        $map = get_taxonomies([], 'objects');
        return $map[$slug] ?? false;
    }
}
if (!function_exists('get_post_type_object')) {
    function get_post_type_object($slug) {
        $map = get_post_types([], 'objects');
        return $map[$slug] ?? null;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Admin\WireframeBootstrap;
use BWS\MetaConductor\Admin\Config\FormatRulesConfig;
use BWS\MetaConductor\Admin\Config\TermRulesConfig;
use BWS\MetaConductor\Admin\Config\WireframeConfig;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use Wireframe\Framework\Conditions;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

$KIND      = OptionRuleStorage::KIND_FORMAT;
$TYPES     = OptionRuleStorage::migrated_types_for_kind($KIND);
$section   = FormatRulesConfig::section();
$field     = $section['fields'][0];
$subfields = $field['args']['subfields'];

// --- The repeater is bound to the persisted kind list. ----------------------

$check('the format kind has a migrated type at all (#59)', $TYPES === ['title_slug_rules']);
$check('repeater binds the format kind list', $field['id'] === $KIND);
$check('repeater is sortable (order is authored)', !empty($field['args']['sortable']));
$check('row title template resolves the snapshot', $field['args']['title_template'] === '{row_title}');
$check('the live {name} template is gone',
    !str_contains($field['args']['title_template'], '{name}'));

// The section replaced a per-type one; its id is the kind, like the term tab's.
$check('the section is the ordered list, not a per-type section',
    $section['id'] === 'format_rules');

// --- Every subfield id is unique. -------------------------------------------

$ids = array_map(fn($s) => $s['id'] ?? '', $subfields);
$check('every subfield id is unique', count($ids) === count(array_unique($ids)));
$check('no subfield has an empty id', !in_array('', $ids, true));

// --- The `type` select. ------------------------------------------------------

$type_field = $subfields[0];
$check('type select leads the row', $type_field['id'] === 'type');
$check('type select is required',   !empty($type_field['required']));

$opts = $type_field['args']['options'];
$check('type select offers an empty placeholder', array_key_first($opts) === '');
// The placeholder must survive the list being one item long: a `type` that
// defaulted to the only option would pre-claim every row added before the
// second type lands.
$check('the placeholder is the DEFAULT, so a new row starts untyped',
    ($type_field['default'] ?? null) === '');
$check('type select STORES the legacy type keys',
    array_values(array_filter(array_keys($opts), fn($k) => $k !== '')) === $TYPES);
$check('every offered type is labelled',
    count(array_filter($opts, fn($l) => is_string($l) && $l !== '')) === count($opts));
$check('type select warns that changing it clears the old type\'s settings',
    str_contains(strtolower($type_field['description'] ?? ''), 'clears'));

// --- The shared frame carries NO gate. ---------------------------------------
// The failure this file exists for: with one rule type, a gate here is not a
// narrowing, it is a deletion.

$by_id = [];
foreach ($subfields as $s) { $by_id[$s['id']] = $s; }

foreach (['type', 'enabled', 'name', 'post_type', 'row_title'] as $shared) {
    $check("shared subfield '$shared' exists",     isset($by_id[$shared]));
    $check("shared subfield '$shared' is ungated", !isset($by_id[$shared]['conditions']));
}

// `post_type` is SCALAR here, not the term list's `post_types` checkboxes —
// TitleSlugHandler::rule_matches() reads one slug, first match wins.
// Swapping in the shared checkbox builder would silently stop every rule
// matching, since the handler compares the value to $post->post_type.
$check('post_type is a single-value select, not the shared checkboxes gate',
    ($by_id['post_type']['type'] ?? '') === 'select'
    && !isset($by_id['post_types']));
$check('post_type is required', !empty($by_id['post_type']['required']));
$check('the post-type select drops attachment',
    !array_key_exists('attachment', $by_id['post_type']['args']['options']));
$check('the post-type select offers the other public types',
    array_key_exists('mc_item', $by_id['post_type']['args']['options'])
    && array_key_exists('page', $by_id['post_type']['args']['options']));
$check('the post-type select carries a placeholder row',
    array_key_first($by_id['post_type']['args']['options']) === '');
// Order decides which of two rules on the same post type runs; the ordered
// list is the first surface where that is visible, so it has to be said.
$check('the post-type description states first-match-wins',
    str_contains(strtolower($by_id['post_type']['description'] ?? ''), 'never runs'));

// --- Gated subfields name only real types, via `in`. ------------------------

$ungated  = ['type', 'enabled', 'name', 'post_type', 'row_title'];
$gated_ok = true;
$gate_covers = [];
foreach ($subfields as $s) {
    if (in_array($s['id'], $ungated, true)) {
        continue;
    }
    $c = $s['conditions'] ?? null;
    if (!is_array($c)
        || ($c['field'] ?? '') !== 'type'
        || ($c['operator'] ?? '') !== 'in'
        || !is_array($c['value'] ?? null)
        || array_diff($c['value'], $TYPES)) {
        $gated_ok = false;
        break;
    }
    $gate_covers = array_merge($gate_covers, $c['value']);
}
$check('every type-specific subfield gates on `type` with `in` + real types', $gated_ok);
$check('every offered type owns at least one gated subfield',
    empty(array_diff($TYPES, array_unique($gate_covers))));
// A `not_in` gate is indistinguishable from no gate while there is one type,
// and would silently expose everything to the second one.
$check('no subfield uses not_in',
    empty(array_filter($subfields, fn($s) => ($s['conditions']['operator'] ?? '') === 'not_in')));

// --- The real evaluator: what actually persists, per type. ------------------
// Every key TitleSlugHandler reads must be in this set. A key missing here is
// a stored value dropped on the next settings save.

$expected_visible = [
    'title_slug_rules' => [
        'type', 'enabled', 'name', 'post_type',
        'title_pattern', 'token_reference', 'slug_pattern', 'slug_mode',
        'date_escalation', 'date_field', 'row_title',
    ],
];

foreach ($TYPES as $type) {
    $visible = [];
    foreach ($subfields as $s) {
        if (Conditions::evaluate($s['conditions'] ?? null, ['type' => $type])) {
            $visible[] = $s['id'];
        }
    }

    sort($visible);
    $want = $expected_visible[$type] ?? [];
    sort($want);

    $check("visible subfields for '$type' are exactly what it needs", $visible === $want);
}

// Stated separately from the set above, because the set can be edited to match
// a regression while this list is the handler's actual read contract
// (TitleSlugHandler::apply_to_data / rule_matches / escalation).
$handler_reads = ['enabled', 'post_type', 'title_pattern', 'slug_pattern',
                  'slug_mode', 'date_escalation', 'date_field'];
$visible_title_slug = [];
foreach ($subfields as $s) {
    if (Conditions::evaluate($s['conditions'] ?? null, ['type' => 'title_slug_rules'])) {
        $visible_title_slug[] = $s['id'];
    }
}
$check('every key the title/slug handler reads survives a save',
    empty(array_diff($handler_reads, $visible_title_slug)));

// An untyped row (freshly added) shows the shared frame only.
$untyped = [];
foreach ($subfields as $s) {
    if (Conditions::evaluate($s['conditions'] ?? null, ['type' => ''])) {
        $untyped[] = $s['id'];
    }
}
$check('an untyped row shows only the shared frame',
    $untyped === ['type', 'enabled', 'name', 'post_type', 'row_title']);

// --- Row titles. -------------------------------------------------------------

$titled = fn(array $row) => WireframeBootstrap::snapshot_format_rule_labels(
    [$KIND => [$row]]
)[$KIND][0]['row_title'];

// Every row title LEADS with its list position (#65 UX follow-up): Wireframe
// only numbers a row whose title renders empty, and position is what the
// collision advisory names and what the author reorders. Asserted once, then
// stripped — the per-type schema checks below are about the schema.
$check('a row title leads with its list position',
    str_starts_with($titled(['type' => 'title_slug_rules', 'name' => 'X']), '#1 '));
$title = fn(array $row) => preg_replace('/^#\d+ /', '', $titled($row));

$check('a title/slug row title is the name plus its post-type scope',
    $title(['type' => 'title_slug_rules', 'name' => 'MC item slug', 'post_type' => 'mc_item'])
        === 'MC item slug (MC Items)');
$check('a row naming no post type carries no scope suffix',
    $title(['type' => 'title_slug_rules', 'name' => 'MC item slug']) === 'MC item slug');
$check('an unresolvable post type falls back to its slug',
    $title(['type' => 'title_slug_rules', 'name' => 'X', 'post_type' => 'gone'])
        === 'X (gone)');
// The uniform marker the term list gained in #57 — the whole reason the live
// {name} template was replaced by a snapshot.
$check('a disabled row carries the uniform prefix',
    str_starts_with(
        $title(['type' => 'title_slug_rules', 'enabled' => false, 'name' => 'X']),
        '[Disabled] '
    ));
$check('an enabled row carries no prefix',
    !str_contains($title(['type' => 'title_slug_rules', 'enabled' => true, 'name' => 'X']), '['));
// A fixture/import row has no name; it must stay findable in a collapsed list.
$check('an unnamed row is named rather than left blank',
    $title(['type' => 'title_slug_rules', 'post_type' => 'page']) === 'Untitled title/slug rule (Pages)');
$check('an untyped row says so rather than rendering blank',
    str_contains($title(['name' => 'X']), 'no rule type chosen'));
// Titles are snapshot on a list, not a single row.
$snapshotted = WireframeBootstrap::snapshot_format_rule_labels([$KIND => [
    ['type' => 'title_slug_rules', 'name' => 'A'],
    ['type' => 'title_slug_rules', 'name' => 'B'],
]])[$KIND];
$check('every row in the list is titled',
    array_column($snapshotted, 'row_title') === ['#1 A', '#2 B']);
// A payload without the format list must not be touched — the term tab saves
// without one, and inventing an empty list here would clear title_slug_rules
// at the projection below.
$check('a payload without the format list is returned untouched',
    WireframeBootstrap::snapshot_format_rule_labels(['manual_processing_enabled' => true])
        === ['manual_processing_enabled' => true]);

// --- The save path: the repeater's list IS the stored shape (#66). ---------
//
// Until #66 these rows had to be projected back onto `title_slug_rules` on the
// same save, because that was what TitleSlugHandler read. The contract ticket
// deleted the projection along with the array: `format_rules` is what storage
// holds and what the format pass reads.

$check('the type-keyed save projection is gone (#66)',
    !method_exists(WireframeBootstrap::class, 'fan_out_rule_lists'));

$ordered = WireframeBootstrap::snapshot_format_rule_labels([
    $KIND => [
        ['type' => 'title_slug_rules', 'enabled' => false,
         'name' => 'MC item slug', 'post_type' => 'mc_item'],
        ['type' => 'title_slug_rules', 'name' => 'Page slug', 'post_type' => 'page'],
    ],
]);
$check('a format-tab save payload carries the kind list and no type-keyed copy',
    array_keys($ordered) === [$KIND]);
$check('the snapshot keeps the authored order, still typed',
    array_column($ordered[$KIND], 'post_type') === ['mc_item', 'page']
    && array_column($ordered[$KIND], 'type') === ['title_slug_rules', 'title_slug_rules']);
$check('the snapshot bakes the row title onto the list rows',
    ($ordered[$KIND][0]['row_title'] ?? '') === '#1 [Disabled] MC item slug (MC Items)');

// --- The admin-load repair: a title backfill and nothing else. --------------
//
// A rule that reached storage without passing through the settings page — a
// seeded fixture, an import, WP-CLI save_rule() — has no row_title, and the
// snapshot-only template would render its collapsed row blank. The repair also
// must not TOUCH anything else: #59 moved title/slug rules with their stored
// shape unchanged, which is the ticket's "existing rules survive" criterion.

$repair = new ReflectionMethod(WireframeBootstrap::class, 'repair_format_rows');
$r = fn(array $rows) => $repair->invoke(null, $rows);

$stored = [[
    'enabled' => true, 'name' => 'MC item slug', 'post_type' => 'mc_item',
    'slug_pattern' => '{default_slug}-{date_year:mc_event_date}',
    'slug_mode' => 'replace', 'date_escalation' => true,
    'date_field' => 'mc_event_date', 'type' => 'title_slug_rules',
]];
$repaired = $r($stored);
$check('the repair backfills a missing row_title',
    ($repaired[0]['row_title'] ?? '') === '#1 MC item slug (MC Items)');
$check('the repair changes NOTHING else about a stored row',
    array_diff_key($repaired[0], ['row_title' => null]) === $stored[0]);
$check('the repair is idempotent', $r($repaired) === $repaired);
$check('the repair leaves an empty list empty', $r([]) === []);

// --- Tabs: the format tab is the ordered list now. --------------------------

$tabs = WireframeConfig::build()['tabs'];
$tab_ids = array_map(fn($t) => $t['id'], $tabs);
$check('still exactly three tabs', $tab_ids === ['auto-set', 'format-transform', 'general']);

// Two sections since #65: the collision advisory LEADS, then the ordered list.
// The order is the assertion — a warning about the list rendered below the list
// is a warning the author scrolls past.
$fmt_tab = $tabs[1];
$check('the format tab holds the advisory and the list', count($fmt_tab['sections']) === 2);
$check('the collision advisory leads the tab (#65)',
    $fmt_tab['sections'][0]['id'] === $KIND . '_collisions');
$check('and the ordered format list follows it',
    $fmt_tab['sections'][1]['fields'][0]['id'] === $KIND);
// The Preview/Apply note survived the collapse — it is the only thing telling
// an author those buttons are coming rather than missing.
$check('the deferred Preview/Apply note is still on the tab',
    ($section['fields'][1]['id'] ?? '') === 'title_slug_actions_note');

// --- No bare {token} in any description, on EITHER ordered list. ------------
//
// Wireframe runs a field's `description` through the same interpolator that
// fills the repeater's row title: `/\{(\w+)\}/g` against the row's own values,
// with an unmatched name replaced by the empty string. A description that
// documents a pattern token therefore loses it silently — `{default_slug}`
// rendered as "the pattern contains ." — and one that happens to name a
// SUBFIELD would render that row's stored value instead, which is worse.
//
// A token with a colon (`{meta:x}`, `{term:tax}`) is not `\w+` and is safe, as
// are `placeholder` and the `html` field's `content`, neither of which is
// interpolated. Only the `\w+` form is asserted here, because only that form
// is what the interpolator can see.
$bare_token = '/\{(\w+)\}/';
$descriptions = [];
$walk = function (array $fields, string $where) use (&$walk, &$descriptions) {
    foreach ($fields as $f) {
        if (isset($f['description'])) {
            $descriptions[] = [$where . ':' . ($f['id'] ?? '?'), (string) $f['description']];
        }
        foreach ($f['args']['subfields'] ?? [] as $sub) {
            $walk([$sub], $where);
        }
    }
};
$walk($section['fields'], 'format');
$walk(TermRulesConfig::section()['fields'], 'term');

$check('both ordered lists offered descriptions to check', count($descriptions) > 10);
foreach ($descriptions as [$where, $text]) {
    $check("no bare {token} in the $where description", !preg_match($bare_token, $text));
}

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nFORMAT-RULES-CONFIG FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "FORMAT-RULES-CONFIG OK — all $total assertions passed (shared frame ungated, gates, row titles, projection, repair; #59).\n");
exit(0);
