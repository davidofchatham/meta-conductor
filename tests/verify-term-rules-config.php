<?php
/**
 * H11 — Ordered term-rule repeater shape harness (0.8.0, #57).
 *
 * The one harness in this repo whose failure mode is DATA LOSS rather than a
 * wrong answer. Wireframe drops a condition-hidden subfield at sanitize
 * (`RepeaterField::sanitize` skips it, `Validator::validateRepeater` skips it
 * too), so in a repeater where one `type` select gates everything:
 *
 *   - a subfield with a WRONG gate silently stops persisting for the types it
 *     no longer matches;
 *   - a SHARED subfield that acquires a gate stops persisting for every type
 *     outside it;
 *   - two subfields sharing an `id` let one clobber the other, last-write-wins,
 *     with nothing in the UI to show for it.
 *
 * None of that raises an error, appears in a diff, or fails a lint. So this
 * file asserts the static half directly, and — crucially — does it through
 * Wireframe's REAL `Conditions::evaluate()`, the same evaluator the sanitizer
 * uses, rather than re-reading the config's intent. The dynamic half (an
 * actual save round-trip per type) is the testbed sweep; this is what makes a
 * regression fail before it gets there.
 *
 * Also locks the ticket's structural claims: three tabs, no Personalize, the
 * `type` select's stored values are the legacy keys storage expects, claim
 * comes from the shared builder, and the type-change warning is stated.
 *
 * Run:  php tests/verify-term-rules-config.php   (local PHP CLI, no WP needed)
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

if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names') {
        $registry = [
            'post' => (object) ['name' => 'post', 'label' => 'Posts', 'public' => true, 'hierarchical' => false],
            'page' => (object) ['name' => 'page', 'label' => 'Pages', 'public' => true, 'hierarchical' => true],
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
            'post_tag' => (object) ['name' => 'post_tag', 'label' => 'Tags',       'public' => true, 'hierarchical' => false],
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
if (!function_exists('get_terms')) {
    function get_terms($args = []) {
        return [(object) ['term_id' => 7, 'name' => 'Term A', 'taxonomy' => $args['taxonomy'] ?? 'category']];
    }
}
// The related-term title resolves single term ids through get_term.
if (!function_exists('get_term')) {
    function get_term($id) {
        return ((int) $id) > 0
            ? (object) ['term_id' => (int) $id, 'name' => 'Term ' . (int) $id, 'taxonomy' => 'category']
            : null;
    }
}
if (!function_exists('get_post_status_object')) {
    function get_post_status_object($slug) {
        $labels = ['publish' => 'Published', 'future' => 'Scheduled', 'draft' => 'Draft',
                   'pending' => 'Pending Review', 'private' => 'Private'];
        return isset($labels[$slug]) ? (object) ['name' => $slug, 'label' => $labels[$slug]] : null;
    }
}
if (!function_exists('get_post_stati')) { function get_post_stati($a = [], $o = 'names') { return []; } }
// The row-title snapshot resolves labels through these two.
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
use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Admin\Config\TermRulesConfig;
use BWS\MetaConductor\Admin\Config\WireframeConfig;
use BWS\MetaConductor\Handlers\HierarchicalHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use Wireframe\Framework\Conditions;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

$KIND      = OptionRuleStorage::KIND_TERM;
$TYPES     = OptionRuleStorage::migrated_types_for_kind($KIND);
$section   = TermRulesConfig::section();
$field     = $section['fields'][0];
$subfields = $field['args']['subfields'];

// --- The repeater is bound to the persisted kind list. ----------------------

$check('repeater binds the term kind list', $field['id'] === $KIND);
$check('repeater is sortable (order is authored)', !empty($field['args']['sortable']));
$check('row title template resolves the snapshot', $field['args']['title_template'] === '{row_title}');

// --- Every subfield id is unique. -------------------------------------------
// Two subfields sharing an id would have one silently overwrite the other in
// RepeaterField::sanitize's $cleanRow, with no error anywhere.

$ids = array_map(fn($s) => $s['id'] ?? '', $subfields);
$check('every subfield id is unique', count($ids) === count(array_unique($ids)));
$check('no subfield has an empty id', !in_array('', $ids, true));

// --- The `type` select. ------------------------------------------------------

$type_field = $subfields[0];
$check('type select leads the row', $type_field['id'] === 'type');
$check('type select is required',   !empty($type_field['required']));

$opts = $type_field['args']['options'];
$check('type select offers an empty placeholder', array_key_first($opts) === '');
$check('type select STORES the legacy type keys',
    array_values(array_filter(array_keys($opts), fn($k) => $k !== '')) === $TYPES);
$check('every offered type is labelled',
    count(array_filter($opts, fn($l) => is_string($l) && $l !== '')) === count($opts));

// The type-change warning the ticket asks for, stated where it happens.
$check('type select warns that changing it clears the old type\'s settings',
    str_contains(strtolower($type_field['description'] ?? ''), 'clears'));

// --- Shared subfields every type reads carry NO gate. ------------------------
// A gate on one of these means it stops saving for every type outside it.
// `post_types` left this list in #58: the ACF-reference rule never reads it
// (its post type is pinned by the monitored field), so it is gated to the
// five types that do — see the gate assertion below.

$by_id = [];
foreach ($subfields as $s) { $by_id[$s['id']] = $s; }

foreach (['type', 'enabled', 'post_status', 'row_title'] as $shared) {
    $check("shared subfield '$shared' exists",      isset($by_id[$shared]));
    $check("shared subfield '$shared' is ungated",  !isset($by_id[$shared]['conditions']));
}

// #23's config half: the publication-status gate now reaches every type here.
$check('post_status is the shared builder\'s field',
    ($by_id['post_status']['args']['options'] ?? null) === ConfigHelpers::post_status_checkbox_options());

// post_types: still the shared builder's field, gated to exactly the types
// whose handlers read it — every type but the ACF-reference rule (#58). A
// type missing from this gate loses its scope on save; a type wrongly added
// gets an inert control that stores junk.
$check('post_types is the shared builder\'s field',
    ($by_id['post_types']['args']['options'] ?? null) === ConfigHelpers::post_types_checkbox_options());
$check('post_types gates on every type except the ACF-reference rule',
    ($by_id['post_types']['conditions']['value'] ?? [])
        === array_values(array_diff($TYPES, ['related_post_terms_rules'])));

// taxonomy: the ACF-reference rule joined the shared select's gate in #58.
$check('taxonomy gate includes the ACF-reference rule',
    in_array('related_post_terms_rules', $by_id['taxonomy']['conditions']['value'] ?? [], true));

// --- Gated subfields name only real types, via `in`. ------------------------

$ungated = ['type', 'enabled', 'post_status', 'row_title'];
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

// --- Claim comes from the shared builder, not re-authored inline. -----------
// The 0.8.0 claim vocabulary (ADR 0004) must not regress in the collapse.

$claim    = $by_id['conflict_handling'] ?? [];
$expected = ConfigHelpers::claim_field('child', ['id' => 'conflict_handling']);
$check('claim subfield exists',        !empty($claim));
$check('claim options are the builder\'s',
    ($claim['args']['options'] ?? null) === $expected['args']['options']);
$check('claim label is the builder\'s', ($claim['label'] ?? null) === $expected['label']);
$check('claim default is the builder\'s', ($claim['default'] ?? null) === $expected['default']);

// --- Vocabularies that live in more than one file must agree. ---------------
// Each of these option sets is restated by a row-title phrase map in
// WireframeBootstrap, and the hierarchy one by BEHAVIOR_MAP in the handler.
// Nothing forces them to move together, so assert they have — this is the same
// guard CLAIM_NAMES gets in H3, for the same reason.

$behaviour_opts = array_keys($by_id['inheritance_behavior']['args']['options']);
$check('the hierarchy outcomes offered are exactly the ones the handler maps',
    $behaviour_opts === array_keys((new ReflectionClassConstant(
        HierarchicalHandler::class, 'BEHAVIOR_MAP'
    ))->getValue()));

// Every offered outcome must produce a distinct, non-fallback row-title phrase.
$phrases = [];
foreach ($behaviour_opts as $outcome) {
    $phrases[] = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
        'type' => 'hierarchical_rules', 'taxonomy' => 'category',
        'inheritance_behavior' => $outcome,
    ]]])[$KIND][0]['row_title'];
}
$check('every hierarchy outcome has its own row-title phrase',
    count(array_unique($phrases)) === count($behaviour_opts));
$check('no hierarchy outcome falls through to "nothing"',
    empty(array_filter($phrases, fn($p) => str_contains($p, 'nothing'))));

// Same for the restriction modes.
$mode_opts = array_keys($by_id['restriction_mode']['args']['options']);
$mode_titles = [];
foreach ($mode_opts as $mode) {
    $mode_titles[] = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
        'type' => 'hierarchical_level_restriction_rules', 'taxonomy' => 'category',
        'restriction_mode' => $mode,
    ]]])[$KIND][0]['row_title'];
}
$check('every restriction mode has its own row-title phrase',
    count(array_unique($mode_titles)) === count($mode_opts));

// And the type dispatch itself: every offered rule type must reach a real
// title builder, not the untyped fallback.
$titled = true;
foreach ($TYPES as $type) {
    $t = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
        'type' => $type, 'taxonomy' => 'category',
    ]]])[$KIND][0]['row_title'];
    if ($t === '' || str_contains($t, 'no rule type chosen')) {
        $titled = false;
    }
}
$check('every offered rule type reaches a real row-title builder', $titled);

// --- The propagation post-type note (what replaced SPEC §V5). ---------------
// The hierarchical-only option list is gone; the guarantee it stood for is now
// this note, so the note is what gets machine-checked.

$note = $by_id['hierarchical_post_type_note'] ?? [];
$check('propagation carries a hierarchical-post-type note',
    ($note['conditions']['value'] ?? []) === ['propagation_rules']);
$check('the note actually says hierarchical',
    str_contains(strtolower($note['args']['content'] ?? ''), 'hierarchical'));

// --- The real evaluator: what actually persists, per type. ------------------
// This is the assertion the whole file exists for. For each rule type, run
// every subfield's conditions through Wireframe's own Conditions::evaluate()
// against a row carrying just that type, and compare the visible set to what
// the type genuinely needs. A subfield missing here is a value silently
// dropped on save; an extra one is junk stored for a type that never reads it.

$expected_visible = [
    'propagation_rules' => [
        'type', 'enabled', 'taxonomy', 'hierarchical_post_type_note',
        'post_types', 'post_status', 'conflict_handling', 'row_title',
    ],
    'time_based_rules' => [
        'type', 'enabled', 'post_types', 'post_status',
        'filter_taxonomies', 'filter_terms', 'start_date', 'end_date',
        'target_term_id', 'row_title',
    ],
    'hierarchical_rules' => [
        'type', 'enabled', 'taxonomy', 'hierarchical_taxonomy_note',
        'post_types', 'post_status',
        'inheritance_behavior', 'inheritance_depth', 'row_title',
    ],
    'hierarchical_level_restriction_rules' => [
        'type', 'enabled', 'taxonomy', 'hierarchical_taxonomy_note',
        'post_types', 'post_status',
        'restriction_mode', 'include_ancestors', 'row_title',
    ],
    // The two LIVE types (#58). A subfield missing here is a live rule's
    // value silently dropped on the next settings save.
    'related_rules' => [
        'type', 'enabled', 'post_types', 'post_status',
        'trigger_type', 'trigger_term_id', 'trigger_taxonomy',
        'target_term_id', 'bidirectional', 'row_title',
    ],
    'related_post_terms_rules' => [
        'type', 'enabled', 'taxonomy', 'acf_taxonomy_note',
        'post_status', 'acf_status_note',
        'acf_field_name', 'holder_role', 'reverse_acf_field_name',
        'keep_in_sync', 'row_title',
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

// An untyped row (freshly added) shows the shared frame only — nothing
// type-specific, so nothing type-specific is written before a type is chosen.
$untyped = [];
foreach ($subfields as $s) {
    if (Conditions::evaluate($s['conditions'] ?? null, ['type' => ''])) {
        $untyped[] = $s['id'];
    }
}
$check('an untyped row shows only the shared frame',
    $untyped === ['type', 'enabled', 'post_status', 'row_title']);

// --- The save path: repeater rows land back in the legacy arrays. ----------
//
// The repeater writes `term_rules`, but every handler still reads rules
// derived from the type-keyed arrays until #66. Without this projection a rule
// authored here would save, render back correctly, and never fire — the
// quietest possible failure.

$payload = WireframeBootstrap::fan_out_rule_lists([
    $KIND => [
        ['type' => 'hierarchical_rules', 'taxonomy' => 'category', 'row_title' => 'first'],
        ['type' => 'propagation_rules',  'taxonomy' => 'category'],
        ['type' => 'related_rules',      'trigger_taxonomy' => 'category'],
        ['type' => 'hierarchical_rules', 'taxonomy' => 'post_tag'],
    ],
    'title_slug_rules' => [['post_type' => 'untouched']],
]);
$check('fan-out writes each migrated type\'s array',
    $payload['hierarchical_rules'] === [
        ['taxonomy' => 'category', 'row_title' => 'first'],
        ['taxonomy' => 'post_tag'],
    ]);
$check('fan-out carries the baked row title through',
    ($payload['hierarchical_rules'][0]['row_title'] ?? null) === 'first');
$check('fan-out writes the live types too (#58)',
    $payload['related_rules'] === [['trigger_taxonomy' => 'category']]);
$check('fan-out names every migrated type, even the empty ones',
    $payload['time_based_rules'] === []
    && $payload['hierarchical_level_restriction_rules'] === []
    && $payload['related_post_terms_rules'] === []);
$check('fan-out leaves a non-migrated type alone',
    $payload['title_slug_rules'] === [['post_type' => 'untouched']]);
// The format kind has no repeater yet (#59): even a payload carrying its
// list must not project onto title_slug_rules.
$fmt_payload = WireframeBootstrap::fan_out_rule_lists([
    OptionRuleStorage::KIND_FORMAT => [['type' => 'title_slug_rules', 'post_type' => 'x']],
]);
$check('fan-out does not project the format kind yet (#59)',
    !array_key_exists('title_slug_rules', $fmt_payload));

// An emptied repeater must CLEAR the legacy arrays. `array_merge($saved,
// $clean)` only replaces keys the payload carries, so an absent key would
// leave every deleted rule still firing.
$cleared = WireframeBootstrap::fan_out_rule_lists([$KIND => []]);
$check('deleting every row clears the legacy arrays',
    $cleared['propagation_rules'] === [] && $cleared['hierarchical_rules'] === []);

// A save from another tab carries no rule list at all — it must not be read
// as "delete everything".
$other_tab = WireframeBootstrap::fan_out_rule_lists(['manual_processing_enabled' => true]);
$check('a payload without the rule list writes no rule arrays',
    $other_tab === ['manual_processing_enabled' => true]);

// Hook order: titles are baked at priority 10, projected at 20, so the copy
// in the legacy array carries the title too.
$ordered = WireframeBootstrap::fan_out_rule_lists(
    WireframeBootstrap::snapshot_term_rule_labels([
        $KIND => [['type' => 'propagation_rules', 'enabled' => false, 'taxonomy' => 'category']],
    ])
);
$check('the snapshot runs before the projection',
    str_starts_with($ordered['propagation_rules'][0]['row_title'] ?? '', '[Disabled] '));

// --- #16: a legacy hierarchical row is REWRITTEN, not just read. -----------
//
// Wireframe reads the settings option raw, so a row that still carries only
// hierarchy_direction + expansion_behavior would render with the new select's
// `ancestors` default and be persisted as such on the next save — silently
// converting the rule. A read-time fallback alone does not cover the admin.

$migrate = new ReflectionMethod(WireframeBootstrap::class, 'migrate_inheritance_behavior');
// (private; PHP 8.1+ needs no setAccessible)
$m = fn(array $row) => $migrate->invoke(null, $row);

$legacy = $m(['type' => 'hierarchical_rules', 'enabled' => true,
              'hierarchy_direction' => 'parent_to_child', 'expansion_behavior' => 'merge']);
$check('a legacy pair migrates to the outcome it behaved as',
    ($legacy['inheritance_behavior'] ?? null) === 'descendants_always');
$check('the migrated row sheds the legacy keys',
    !isset($legacy['hierarchy_direction']) && !isset($legacy['expansion_behavior']));

$check('a row already naming an outcome is untouched',
    $m(['type' => 'hierarchical_rules', 'inheritance_behavior' => 'both_smart',
        'hierarchy_direction' => 'child_to_parent'])['inheritance_behavior'] === 'both_smart');

$other = ['type' => 'propagation_rules', 'hierarchy_direction' => 'both'];
$check('a row of another type is untouched', $m($other) === $other);

$fresh = ['type' => 'hierarchical_rules', 'taxonomy' => 'category'];
$check('a row with neither shape is untouched', $m($fresh) === $fresh);

// parent_to_child + never applied nothing at all. Preserve that as disabled
// rather than inventing a behaviour the rule never had.
$inert = $m(['type' => 'hierarchical_rules', 'enabled' => true,
             'hierarchy_direction' => 'parent_to_child', 'expansion_behavior' => 'never']);
$check('the do-nothing pair migrates to a DISABLED rule', empty($inert['enabled']));
$check('the do-nothing pair still names a valid outcome',
    in_array($inert['inheritance_behavior'], $behaviour_opts, true));

// --- #58: the two live types' row titles and legacy-shape repair. ------------

// Related: trigger → target, with the disabled prefix like everything else.
$rel_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'related_rules', 'enabled' => false,
    'trigger_type' => 'term', 'trigger_term_id' => [7],
    'target_term_id' => [9],
]]])[$KIND][0]['row_title'];
$check('related title joins trigger and target with an arrow',
    str_contains($rel_title, "\xE2\x86\x92")
    && str_contains($rel_title, 'Term 7') && str_contains($rel_title, 'Term 9'));
$check('related title carries the uniform disabled prefix',
    str_starts_with($rel_title, '[Disabled] '));

// Taxonomy-triggered variant names the taxonomy, not a term.
$rel_tax_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'related_rules',
    'trigger_type' => 'taxonomy', 'trigger_taxonomy' => 'category',
    'target_term_id' => [9],
]]])[$KIND][0]['row_title'];
$check('taxonomy-triggered related title names the taxonomy',
    str_starts_with($rel_tax_title, 'Categories'));

// ACF-reference: verb tracks keep_in_sync, direction tracks holder_role, and
// the COMBINED "post_type:field" value renders as the bare field name (the
// value itself round-trips whole — fan-in/fan-out apply no coercion, H10).
$acf_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'related_post_terms_rules', 'keep_in_sync' => true,
    'holder_role' => 'source', 'taxonomy' => 'category',
    'acf_field_name' => 'event:related_team',
]]])[$KIND][0]['row_title'];
$check('acf-reference title: Sync + to + bare field name',
    str_starts_with($acf_title, 'Sync')
    && str_contains($acf_title, ' to ')
    && str_contains($acf_title, 'related_team')
    && !str_contains($acf_title, 'event:'));
$acf_copy_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'related_post_terms_rules', 'keep_in_sync' => false,
    'holder_role' => 'target', 'taxonomy' => 'category',
    'acf_field_name' => 'event:related_team',
]]])[$KIND][0]['row_title'];
$check('acf-reference title: Copy + from when pull and not syncing',
    str_starts_with($acf_copy_title, 'Copy') && str_contains($acf_copy_title, ' from '));

// Legacy related rows: scalar term ids must become the arrays the selects
// bind, or the admin renders them EMPTY and the next save disarms the rule.
$repair = new ReflectionMethod(WireframeBootstrap::class, 'migrate_related_term_shape');
$r = fn(array $row) => $repair->invoke(null, $row);

$legacy_rel = $r(['type' => 'related_rules', 'trigger_term_id' => '12',
                  'target_term_id' => 9, 'trigger_label' => 'stale']);
$check('a legacy scalar trigger becomes the select\'s array shape',
    $legacy_rel['trigger_term_id'] === [12]);
$check('a legacy scalar target becomes the select\'s array shape',
    $legacy_rel['target_term_id'] === [9]);
$check('the stale three-token label keys are shed',
    !isset($legacy_rel['trigger_label']));
$check('an array-shaped related row is untouched',
    $r(['type' => 'related_rules', 'trigger_term_id' => [5], 'target_term_id' => [9]])
        === ['type' => 'related_rules', 'trigger_term_id' => [5], 'target_term_id' => [9]]);
$other_rel = ['type' => 'time_based_rules', 'target_term_id' => '21'];
$check('a row of another type is untouched by the related repair',
    $r($other_rel) === $other_rel);

// Legacy ACF-reference rows: the key-rename migration is one-shot flag-gated
// in storage, so the admin repair re-applies it to kind-list rows — a row
// written behind the flag must not render with the radio's `source` default
// and have its direction reversed on resave.
$legacy_acf = OptionRuleStorage::migrate_related_post_terms_shape([
    'type' => 'related_post_terms_rules',
    'acf_field_name' => 'event:ref', 'source_taxonomy' => 'category',
    'bidirectional' => true,
]);
$check('a legacy acf-ref row gains the runtime holder_role default, not the config one',
    $legacy_acf['holder_role'] === 'target');
$check('a legacy acf-ref row migrates its renamed keys',
    $legacy_acf['taxonomy'] === 'category'
    && $legacy_acf['keep_in_sync'] === true
    && !isset($legacy_acf['source_taxonomy']) && !isset($legacy_acf['bidirectional']));
$check('the combined acf_field_name is NOT split by the migration',
    $legacy_acf['acf_field_name'] === 'event:ref');
$migrated_acf = ['type' => 'related_post_terms_rules', 'acf_field_name' => 'event:ref',
                 'taxonomy' => 'category', 'holder_role' => 'source', 'keep_in_sync' => false];
$check('an already-migrated acf-ref row is untouched',
    OptionRuleStorage::migrate_related_post_terms_shape($migrated_acf) === $migrated_acf);

// --- Tabs: exactly three, Personalize gone. ---------------------------------

$tab_ids = array_map(fn($t) => $t['id'], WireframeConfig::build()['tabs']);
$check('exactly three tabs', count($tab_ids) === 3);
$check('tabs are auto-set / format-transform / general',
    $tab_ids === ['auto-set', 'format-transform', 'general']);
$check('Personalize tab is gone', !in_array('personalize', $tab_ids, true));
$check('Restrict is no longer its own tab', !in_array('restrict', $tab_ids, true));

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nTERM-RULES-CONFIG FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "TERM-RULES-CONFIG OK — all $total assertions passed (subfield gates, claim builder, live types, tabs; #57/#58).\n");
exit(0);
