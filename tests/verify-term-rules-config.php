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
if (!function_exists('esc_url'))    { function esc_url($u) { return $u; } }
if (!function_exists('add_action')) { function add_action() {} }
if (!function_exists('add_filter')) { function add_filter() {} }
if (!function_exists('_n'))         { function _n($s, $p, $n, $d = 'default') { return $n === 1 ? $s : $p; } }
// The collision section (#65) reads its findings from an option of its own.
// Absent here, which is the "never saved" state — the section renders the
// re-check button and no notice.
if (!function_exists('get_option'))    { function get_option($name, $default = false) { return $GLOBALS['mc_test_options'][$name] ?? $default; } }
if (!function_exists('update_option')) { function update_option($name, $value, $autoload = null) { return true; } }
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
use BWS\MetaConductor\Handlers\TermsFromTermHierarchyHandler;
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
// whose handlers read it — every type since FW-61 04, where the ACF
// relationship took it as an optional narrowing of the receiving post (it was
// the one type that did not, #58). A type missing from this gate loses its
// scope on save; a type wrongly added gets an inert control that stores junk.
$check('post_types is the shared builder\'s field',
    ($by_id['post_types']['args']['options'] ?? null) === ConfigHelpers::post_types_checkbox_options());
$check('post_types gates on every type',
    ($by_id['post_types']['conditions']['value'] ?? []) === $TYPES);

// taxonomy: the merged type reads it under BOTH relationships, so its gate is
// the plain `type in` one with no relationship condition.
$check('taxonomy gate includes the merged related-post type, with no relationship condition',
    in_array('terms_from_related_post', $by_id['taxonomy']['conditions']['value'] ?? [], true)
    && ($by_id['taxonomy']['conditions']['field'] ?? '') === 'type');

// FW-56: the field takes a LIST. `multiple` is what makes the stored value one;
// `required` stays so a typed row cannot be saved with no taxonomy; and there is
// no placeholder option, which a multi-select would offer as a pickable "taxonomy".
$check('taxonomy is a required multi-select over the taxonomies, with no placeholder',
    ($by_id['taxonomy']['type'] ?? '') === 'select'
    && ($by_id['taxonomy']['args']['multiple'] ?? false) === true
    && ($by_id['taxonomy']['required'] ?? false) === true
    && ($by_id['taxonomy']['args']['options'] ?? null) === ConfigHelpers::taxonomy_checkbox_options());

// --- Gated subfields name only real types, via `in`. ------------------------

// A gate is either the flat `type in [...]` clause, or (FW-61 04) clauses joined
// by `all` / `any` where every leaf is either that `type in` clause or an
// `equals` on `relationship`. Anything else is a hand-written condition the
// descriptors do not know about.
$leaves = function ($c) use (&$leaves): array {
    if (!is_array($c)) { return [null]; }
    foreach (['all', 'any'] as $join) {
        if (isset($c[$join])) {
            return array_merge(...array_map($leaves, $c[$join]));
        }
    }
    return [$c];
};

$ungated = ['type', 'enabled', 'post_status', 'row_title'];
$gated_ok = true;
$gate_covers = [];
foreach ($subfields as $s) {
    if (in_array($s['id'], $ungated, true)) {
        continue;
    }
    $has_type_clause = false;
    foreach ($leaves($s['conditions'] ?? null) as $c) {
        if (is_array($c) && ($c['field'] ?? '') === 'type') {
            if (($c['operator'] ?? '') !== 'in' || !is_array($c['value'] ?? null) || array_diff($c['value'], $TYPES)) {
                $gated_ok = false;
                break 2;
            }
            $has_type_clause = true;
            $gate_covers     = array_merge($gate_covers, $c['value']);
        } elseif (!is_array($c)
            || ($c['field'] ?? '') !== 'relationship'
            || ($c['operator'] ?? '') !== 'equals'
            || !in_array($c['value'] ?? null, ['parent', 'acf'], true)) {
            $gated_ok = false;
            break 2;
        }
    }
    if (!$has_type_clause) {
        $gated_ok = false;
        break;
    }
}
$check('every type-specific subfield gates on `type` with `in` + real types, plus at most a `relationship` equals', $gated_ok);
$check('every offered type owns at least one gated subfield',
    empty(array_diff($TYPES, array_unique($gate_covers))));
$check('the descriptors\' `when` never reaches Wireframe',
    empty(array_filter($subfields, fn($s) => array_key_exists('when', $s))));

// --- Claim comes from the shared builder, not re-authored inline. -----------
// The 0.8.0 claim vocabulary (CONTEXT.md → Claim) must not regress in the collapse.

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
        TermsFromTermHierarchyHandler::class, 'BEHAVIOR_MAP'
    ))->getValue()));

// Every offered outcome must produce a distinct, non-fallback row-title phrase.
$phrases = [];
foreach ($behaviour_opts as $outcome) {
    $phrases[] = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
        'type' => 'terms_from_term_hierarchy', 'taxonomy' => 'category',
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
        'type' => 'restrict_terms_by_level', 'taxonomy' => 'category',
        'restriction_mode' => $mode,
    ]]])[$KIND][0]['row_title'];
}
$check('every restriction mode has its own row-title phrase',
    count(array_unique($mode_titles)) === count($mode_opts));
$check('restriction mode offers exactly deepest and shallowest, default deepest',
    $mode_opts === ['deepest_only', 'shallowest_only']
    && $by_id['restriction_mode']['default'] === 'deepest_only');

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

// --- The parent-relationship post-type note (what replaced SPEC §V5). -------
// The hierarchical-only option list is gone; the guarantee it stood for is now
// this note, so the note is what gets machine-checked.

$gate_of = fn(string $id): array => $by_id[$id]['conditions'] ?? [];
$note    = $by_id['hierarchical_post_type_note'] ?? [];
$check('the related-post type carries a hierarchical-post-type note, on the parent relationship only',
    $gate_of('hierarchical_post_type_note') === ConfigHelpers::gate(['terms_from_related_post'], ['relationship' => 'parent']));
$check('the note actually says hierarchical',
    str_contains(strtolower($note['args']['content'] ?? ''), 'hierarchical'));

// --- FW-61 04: the second gate dimension. ----------------------------------
// The ACF-only controls and their two notes gate on `relationship = acf`; each
// is the descriptor's own declaration (`when` / `shared_field_when`), never a
// hand-written list. A control wrongly gated drops its value on save.

foreach (['acf_field_name', 'holder_role', 'reverse_acf_field_name', 'acf_taxonomy_note', 'acf_status_note'] as $acf_only) {
    $check("'$acf_only' gates on the merged type AND relationship = acf",
        $gate_of($acf_only) === ConfigHelpers::gate(['terms_from_related_post'], ['relationship' => 'acf']));
}
$check('the relationship select is ungated beyond the type (both relationships read it)',
    $gate_of('relationship') === ConfigHelpers::gate(['terms_from_related_post']));
$check('the claim is shared by both relationships, so it has no relationship condition',
    ($gate_of('conflict_handling')['field'] ?? '') === 'type'
    && in_array('terms_from_related_post', $gate_of('conflict_handling')['value'] ?? [], true));

// Both options stay stored whatever is installed; an unavailable one is only
// labeled, because a vanishing option would drop the stored value on save.
$rel_options = $by_id['relationship']['args']['options'] ?? [];
$check('the relationship select always offers parent and acf', array_keys($rel_options) === ['parent', 'acf']);
$check('without ACF the acf option is labeled "requires ACF" and a stored acf row shows the notice',
    function_exists('acf_get_field')
    || (str_contains($rel_options['acf'], 'requires ACF') && isset($by_id['acf_required_note'])
        && $gate_of('acf_required_note') === ConfigHelpers::gate(['terms_from_related_post'], ['relationship' => 'acf'])));
$check('the claim help says Contributing withdraws a lost term on a parent relationship',
    str_contains($by_id['conflict_handling']['description'] ?? '', 'parent relationship')
    && str_contains($by_id['conflict_handling']['description'] ?? '', 'Contributing'));
$check('keep_in_sync is gone from the config', !isset($by_id['keep_in_sync']));

// With ACF off the field select has no options, and a select drops a value that is
// not among them on save. A stored ACF row must survive ACF being switched off, so
// its stored field values are offered as themselves.
if (!function_exists('acf_get_field_groups')) {
    $GLOBALS['mc_test_options'][OptionRuleStorage::OPTION_NAME] = [$KIND => [[
        'type' => 'terms_from_related_post', 'relationship' => 'acf',
        'acf_field_name' => 'event:related_team:field_abc', 'reverse_acf_field_name' => 'team:related_events:field_def',
    ]]];
    $off = ConfigHelpers::acf_relationship_field_options('— pick —');
    $check('ACF off: a stored field value stays selectable, so a save keeps it',
        isset($off['event:related_team:field_abc'], $off['team:related_events:field_def'])
        && array_key_first($off) === '');
    unset($GLOBALS['mc_test_options']);
    $check('ACF off, nothing stored: only the placeholder', array_keys(ConfigHelpers::acf_relationship_field_options()) === ['']);
}

// --- The real evaluator: what actually persists, per type. ------------------
// This is the assertion the whole file exists for. For each rule type, run
// every subfield's conditions through Wireframe's own Conditions::evaluate()
// against a row carrying just that type, and compare the visible set to what
// the type genuinely needs. A subfield missing here is a value silently
// dropped on save; an extra one is junk stored for a type that never reads it.

// The merged type is checked once per relationship (FW-61 04): the second gate
// dimension is `relationship`, and flipping it is a save that drops whatever is
// hidden, so each value gets its own exact set.
$expected_visible = [
    'terms_from_related_post/parent' => [
        'type', 'enabled', 'taxonomy', 'hierarchical_post_type_note',
        'post_types', 'post_status', 'conflict_handling', 'relationship', 'row_title',
    ],
    'terms_from_related_post/acf' => array_merge([
        'type', 'enabled', 'taxonomy', 'acf_taxonomy_note',
        'post_types', 'post_status', 'acf_status_note', 'conflict_handling', 'relationship',
        'acf_field_name', 'holder_role', 'reverse_acf_field_name', 'row_title',
    ], function_exists('acf_get_field') ? [] : ['acf_required_note']),
    'terms_from_date_window' => [
        'type', 'enabled', 'post_types', 'post_status',
        'filter_taxonomies', 'filter_terms', 'start_date', 'end_date',
        'target_term_id', 'row_title',
    ],
    'terms_from_term_hierarchy' => [
        'type', 'enabled', 'taxonomy', 'hierarchical_taxonomy_note',
        'post_types', 'post_status',
        'inheritance_behavior', 'inheritance_depth', 'row_title',
    ],
    'restrict_terms_by_level' => [
        'type', 'enabled', 'taxonomy', 'hierarchical_taxonomy_note',
        'post_types', 'post_status',
        'restriction_mode', 'include_ancestors', 'row_title',
    ],
    // No hierarchical_taxonomy_note: a cap means the same on a flat taxonomy.
    'restrict_term_count' => [
        'type', 'enabled', 'taxonomy', 'post_types', 'post_status',
        'max_terms', 'per_level', 'row_title',
    ],
    // A LIVE type (#58). A subfield missing here is a live rule's value
    // silently dropped on the next settings save.
    'terms_from_trigger_term' => [
        'type', 'enabled', 'post_types', 'post_status',
        'trigger_type', 'trigger_term_id', 'trigger_taxonomy',
        'target_term_id', 'bidirectional', 'row_title',
    ],
];

$scenarios = [];
foreach ($TYPES as $type) {
    if ($type === 'terms_from_related_post') {
        $scenarios["$type/parent"] = [$type, ['relationship' => 'parent']];
        $scenarios["$type/acf"]    = [$type, ['relationship' => 'acf']];
    } else {
        $scenarios[$type] = [$type, []];
    }
}

foreach ($scenarios as $name => [$type, $row]) {
    $visible = [];
    foreach ($subfields as $s) {
        if (Conditions::evaluate($s['conditions'] ?? null, ['type' => $type] + $row)) {
            $visible[] = $s['id'];
        }
    }

    sort($visible);
    $want = $expected_visible[$name] ?? [];
    sort($want);

    $check("visible subfields for '$name' are exactly what it needs", $visible === $want);
}

// The deliberate flip: moving a row from acf to parent hides exactly the ACF-only
// controls, and nothing the parent branch needs. This is the one drop the round-trip
// sweep is expected to show.
$only_acf = array_values(array_diff($expected_visible['terms_from_related_post/acf'], $expected_visible['terms_from_related_post/parent']));
sort($only_acf);
$check('flipping acf to parent drops exactly the ACF-only settings',
    $only_acf === array_values(array_filter(
        ['acf_field_name', 'acf_required_note', 'acf_status_note', 'acf_taxonomy_note', 'holder_role', 'reverse_acf_field_name'],
        fn($id) => in_array($id, $expected_visible['terms_from_related_post/acf'], true)
    )));

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

// --- The save path: the repeater's list IS the stored shape (#66). ---------
//
// Until #66 these rows had to be projected back onto seven type-keyed arrays on
// the same save, because that was what handlers read. The contract ticket
// deleted the projection along with the arrays: what the repeater writes to
// `term_rules` is what storage holds and what a pass reads, in that order. A
// payload filter that re-introduced a type-keyed copy would recreate exactly
// the two-shapes reconciliation the kind lists exist to remove.

$check('the type-keyed save projection is gone (#66)',
    !method_exists(WireframeBootstrap::class, 'fan_out_rule_lists'));

// The snapshots are all that touch the list now, and they must leave it a KIND
// list: same rows, same order, still typed, `row_title` baked on.
$snapshotted = WireframeBootstrap::snapshot_term_rule_labels([
    $KIND => [
        ['type' => 'terms_from_term_hierarchy', 'taxonomy' => 'category'],
        ['type' => 'terms_from_related_post', 'enabled' => false, 'taxonomy' => 'category'],
    ],
]);
$check('a rule-tab save payload carries the kind list and no type-keyed copy',
    array_keys($snapshotted) === [$KIND]);
$check('the snapshot keeps every row, in order, still typed',
    array_column($snapshotted[$KIND], 'type')
        === ['terms_from_term_hierarchy', 'terms_from_related_post']);
// FW-56: a row naming several taxonomies is ONE row with ONE title that names
// them all — "Categories, Tags", not the first.
$listed = WireframeBootstrap::snapshot_term_rule_labels([
    $KIND => [['type' => 'terms_from_related_post', 'taxonomy' => ['category', 'post_tag']]],
]);
$check('a multi-taxonomy row keeps one row, titled with every taxonomy',
    count($listed[$KIND]) === 1
    && str_contains($listed[$KIND][0]['row_title'] ?? '', 'Categories, Tags'));
$check('the snapshot bakes the row title onto the list rows',
    ($snapshotted[$KIND][0]['row_title'] ?? '') !== ''
    && str_starts_with($snapshotted[$KIND][1]['row_title'] ?? '', '#2 [Disabled] '));
// Every row title LEADS with its list position (#65 UX follow-up): Wireframe
// only numbers a row whose title renders empty, and position is what the
// collision advisory names and what the author reorders.
$check('and numbers each row by its place in the list',
    str_starts_with($snapshotted[$KIND][0]['row_title'] ?? '', '#1 '));

// A save from another tab carries no rule list at all — it must pass through
// untouched, not be read as "delete everything".
$check('a payload without the rule list is returned untouched',
    WireframeBootstrap::snapshot_term_rule_labels(['manual_processing_enabled' => true])
        === ['manual_processing_enabled' => true]);

// --- #58: the two live types' row titles. -----------------------------------

// Related: trigger → target, with the disabled prefix like everything else.
$rel_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'terms_from_trigger_term', 'enabled' => false,
    'trigger_type' => 'term', 'trigger_term_id' => [7],
    'target_term_id' => [9],
]]])[$KIND][0]['row_title'];
$check('related title joins trigger and target with an arrow',
    str_contains($rel_title, "\xE2\x86\x92")
    && str_contains($rel_title, 'Term 7') && str_contains($rel_title, 'Term 9'));
$check('related title carries the uniform disabled prefix',
    str_starts_with($rel_title, '#1 [Disabled] '));

// Taxonomy-triggered variant names the taxonomy, not a term.
$rel_tax_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'terms_from_trigger_term',
    'trigger_type' => 'taxonomy', 'trigger_taxonomy' => 'category',
    'target_term_id' => [9],
]]])[$KIND][0]['row_title'];
$check('taxonomy-triggered related title names the taxonomy',
    str_starts_with($rel_tax_title, '#1 Categories'));

// ACF relationship: direction tracks holder_role, the claim closes the title,
// and the COMBINED "post_type:field" value renders as the bare field name (the
// value itself round-trips whole — fan-in/fan-out apply no coercion, H10). The
// old "Sync" verb went with keep_in_sync.
$acf_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'terms_from_related_post', 'relationship' => 'acf', 'conflict_handling' => 'replace',
    'holder_role' => 'source', 'taxonomy' => 'category',
    'acf_field_name' => 'event:related_team',
]]])[$KIND][0]['row_title'];
$check('acf title: Copy + to + bare field name + claim',
    str_starts_with($acf_title, '#1 Copy')
    && str_contains($acf_title, ' to ')
    && str_contains($acf_title, 'related_team')
    && !str_contains($acf_title, 'event:')
    && str_ends_with($acf_title, '(owning)'));
$acf_copy_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'terms_from_related_post', 'relationship' => 'acf', 'conflict_handling' => 'merge',
    'holder_role' => 'target', 'taxonomy' => 'category', 'post_status' => ['publish'],
    'acf_field_name' => 'event:related_team',
]]])[$KIND][0]['row_title'];
$check('acf title: from when pull, the status list, and the claim',
    str_contains($acf_copy_title, ' from ')
    && str_contains($acf_copy_title, ' on Published')
    && str_ends_with($acf_copy_title, '(contributing)')
    && !str_contains($acf_copy_title . $acf_title, 'Sync'));
$parent_title = WireframeBootstrap::snapshot_term_rule_labels([$KIND => [[
    'type' => 'terms_from_related_post', 'relationship' => 'parent', 'conflict_handling' => 'replace',
    'taxonomy' => 'category', 'post_types' => ['page'],
]]])[$KIND][0]['row_title'];
$check('a parent title is unchanged: scope, copy, "to children", claim',
    $parent_title === '#1 Pages: Copy Categories terms to children (owning)');

// --- The admin-load repair: a title backfill and nothing else. --------------
//
// The three every-boot shape migrations are gone (FW-39): no stored row is in
// a legacy shape. What stays is the row_title backfill, which is not a
// migration — a row that reached storage without the admin has no title, and
// the collapsed row renders blank. A legacy-shaped row now passes through
// untouched apart from its title.

$repair = new ReflectionMethod(WireframeBootstrap::class, 'repair_term_rows');
$r = fn(array $rows) => $repair->invoke(null, $rows);

$stored = [
    ['type' => 'terms_from_term_hierarchy', 'enabled' => true, 'taxonomy' => 'category',
     'hierarchy_direction' => 'parent_to_child', 'expansion_behavior' => 'merge'],
    ['type' => 'terms_from_trigger_term', 'trigger_term_id' => '12', 'target_term_id' => 9],
    ['type' => 'terms_from_related_post', 'relationship' => 'acf', 'acf_field_name' => 'event:ref',
     'source_taxonomy' => 'category', 'bidirectional' => true],
];
$repaired = $r($stored);
$check('the repair backfills every missing row_title',
    count(array_filter(array_column($repaired, 'row_title'))) === count($stored));
// FW-56 adds ONE shape to it. The taxonomy control is a multi-select, and the
// client renders a stored SCALAR in it as an empty selection while posting the
// scalar back untouched; the server's multi-select sanitize then turns a
// non-array into the default. Left alone, the first save of the rules tab would
// blank every live row's taxonomy. So a scalar becomes the one-element list the
// control reads, written back before Wireframe reads the option raw.
$expected = $stored;
$expected[0]['taxonomy'] = ['category'];
$check('the repair changes NOTHING else about a stored row, but a scalar taxonomy becomes a list (FW-56)',
    array_map(fn($row) => array_diff_key($row, ['row_title' => null]), $repaired) === $expected);
$check('the repair is idempotent', $r($repaired) === $repaired);
$check('the repair leaves an empty list empty', $r([]) === []);
$check('FW-56: an empty or list taxonomy, and a type that does not read it, are left alone',
    array_column($r([
        ['type' => 'terms_from_related_post', 'taxonomy' => ''],
        ['type' => 'terms_from_related_post', 'taxonomy' => ['category', 'post_tag']],
        ['type' => 'terms_from_trigger_term', 'taxonomy' => 'category'],
    ]), 'taxonomy') === ['', ['category', 'post_tag'], 'category']);

// --- Tabs: exactly three, Personalize gone. ---------------------------------

$tab_ids = array_map(fn($t) => $t['id'], WireframeConfig::build()['tabs']);
$check('exactly three tabs', count($tab_ids) === 3);
$check('tabs are auto-set / format-transform / general',
    $tab_ids === ['auto-set', 'format-transform', 'general']);
$check('Personalize tab is gone', !in_array('personalize', $tab_ids, true));
$check('Restrict is no longer its own tab', !in_array('restrict', $tab_ids, true));

// The collision advisory LEADS the tab (#65). Order is the assertion: a warning
// about the list rendered below the list is a warning the author scrolls past.
$auto_set = WireframeConfig::build()['tabs'][0];
$check('the collision advisory leads the term tab (#65)',
    $auto_set['sections'][0]['id'] === $KIND . '_collisions');
$check('and the ordered term list follows it',
    $auto_set['sections'][1]['fields'][0]['id'] === $KIND);
// The note under the list is the tab's only pointer to the Apply page.
$check('the Apply page note is on the tab',
    ($auto_set['sections'][1]['fields'][1]['id'] ?? '') === 'term_rules_apply_note');

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nTERM-RULES-CONFIG FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) { fwrite(STDERR, "  \xE2\x9C\x97 $f\n"); }
    exit(1);
}
fwrite(STDOUT, "TERM-RULES-CONFIG OK — all $total assertions passed (subfield gates, claim builder, live types, tabs; #57/#58).\n");
exit(0);
