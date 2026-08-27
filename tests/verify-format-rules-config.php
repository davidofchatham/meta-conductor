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
// TitleSlugHandler::find_matching_rule() reads one slug, first match wins.
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
// (TitleSlugHandler::apply_to_post / find_matching_rule / escalation).
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

$title = fn(array $row) => WireframeBootstrap::snapshot_format_rule_labels(
    [$KIND => [$row]]
)[$KIND][0]['row_title'];

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
    array_column($snapshotted, 'row_title') === ['A', 'B']);
// A payload without the format list must not be touched — the term tab saves
// without one, and inventing an empty list here would clear title_slug_rules
// at the projection below.
$check('a payload without the format list is returned untouched',
    WireframeBootstrap::snapshot_format_rule_labels(['manual_processing_enabled' => true])
        === ['manual_processing_enabled' => true]);

// --- The save path: repeater rows land back in title_slug_rules. -----------
//
// The repeater writes `format_rules`, but TitleSlugHandler still reads rules
// derived from `title_slug_rules` until #66. Without this projection a rule
// authored here would save, render back correctly, and never fire.

$payload = WireframeBootstrap::fan_out_rule_lists([
    $KIND => [
        ['type' => 'title_slug_rules', 'post_type' => 'mc_item', 'row_title' => 'first'],
        ['type' => 'title_slug_rules', 'post_type' => 'page'],
    ],
]);
$check('fan-out writes title_slug_rules in the authored order',
    $payload['title_slug_rules'] === [
        ['post_type' => 'mc_item', 'row_title' => 'first'],
        ['post_type' => 'page'],
    ]);
$check('fan-out strips the grouping key',
    !array_key_exists('type', $payload['title_slug_rules'][0]));
$check('fan-out leaves the term kind alone when only the format list is posted',
    !array_key_exists('propagation_rules', $payload));

// An emptied repeater must CLEAR the legacy array — `array_merge($saved,
// $clean)` only replaces keys the payload carries.
$check('deleting every row clears title_slug_rules',
    WireframeBootstrap::fan_out_rule_lists([$KIND => []])['title_slug_rules'] === []);

// A save from another tab carries no format list — not "delete everything".
$check('a payload without the format list writes no rule array',
    WireframeBootstrap::fan_out_rule_lists(['manual_processing_enabled' => true])
        === ['manual_processing_enabled' => true]);

// Hook order: titled at priority 10, projected at 20, so the copy in the
// legacy array carries the title too.
$ordered = WireframeBootstrap::fan_out_rule_lists(
    WireframeBootstrap::snapshot_format_rule_labels([
        $KIND => [['type' => 'title_slug_rules', 'enabled' => false,
                   'name' => 'MC item slug', 'post_type' => 'mc_item']],
    ])
);
$check('the snapshot runs before the projection',
    ($ordered['title_slug_rules'][0]['row_title'] ?? '')
        === '[Disabled] MC item slug (MC Items)');

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
    ($repaired[0]['row_title'] ?? '') === 'MC item slug (MC Items)');
$check('the repair changes NOTHING else about a stored row',
    array_diff_key($repaired[0], ['row_title' => null]) === $stored[0]);
$check('the repair is idempotent', $r($repaired) === $repaired);
$check('the repair leaves an empty list empty', $r([]) === []);

// --- Tabs: the format tab is the ordered list now. --------------------------

$tabs = WireframeConfig::build()['tabs'];
$tab_ids = array_map(fn($t) => $t['id'], $tabs);
$check('still exactly three tabs', $tab_ids === ['auto-set', 'format-transform', 'general']);

$fmt_tab = $tabs[1];
$check('the format tab holds one section', count($fmt_tab['sections']) === 1);
$check('and that section is the ordered format list',
    $fmt_tab['sections'][0]['fields'][0]['id'] === $KIND);
// The Preview/Apply note survived the collapse — it is the only thing telling
// an author those buttons are coming rather than missing.
$check('the deferred Preview/Apply note is still on the tab',
    ($section['fields'][1]['id'] ?? '') === 'title_slug_actions_note');

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nFORMAT-RULES-CONFIG FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "FORMAT-RULES-CONFIG OK — all $total assertions passed (shared frame ungated, gates, row titles, projection, repair; #59).\n");
exit(0);
