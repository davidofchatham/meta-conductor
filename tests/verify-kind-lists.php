<?php
/**
 * H10 — Kind-list storage harness (#56 expand → #66 contract; pre-0.8.0
 * migration deleted in FW-39).
 *
 * The kind lists (`term_rules`, `format_rules`) are the ONLY stored rule shape.
 * This harness covers:
 *
 * 1. **The pre-0.8.0 guard.** A site still holding type-keyed rule ROWS and no
 *    kind list reads no rules — the migration is gone — so
 *    `holds_pre_08_rows()` must say so, and must NOT fire on empty legacy
 *    arrays or on a site whose kind list exists.
 * 2. **Every storage read operates on the kind list.** `get_rules()` and
 *    `get_rule()` still speak in rule TYPES, but a type is a filter over a
 *    kind list, and `$rule_id` is a per-type index into a cross-type array.
 *    The projected `id` is that same per-type number.
 * 3. **The projection is the canonical shape** (FW-29) and ACF field values
 *    parse in every stored form (#25).
 *
 * Runs WITHOUT booting WordPress — the option store below is a plain array, so
 * the writes are exercised as pure state transitions.
 *
 * Run:  php tests/verify-kind-lists.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// --- Minimal WP option shims. ------------------------------------------------
//

$GLOBALS['mc_options'] = [];

function get_option(string $name, $default = false) {
    return array_key_exists($name, $GLOBALS['mc_options'])
        ? $GLOBALS['mc_options'][$name]
        : $default;
}

function wp_cache_delete($key, $group = ''): bool {
    return true;
}

function current_time(string $type, $gmt = 0): string {
    return '2026-01-01 00:00:00';
}

function taxonomy_exists(string $taxonomy): bool {
    return in_array($taxonomy, ['category', 'post_tag', 'sport'], true);
}

function post_type_exists(string $post_type): bool {
    return in_array($post_type, ['post', 'page', 'event', 'mc_item'], true);
}

// --- Load the class under test. ----------------------------------------------

require dirname(__DIR__) . '/includes/storage/class-rule-storage.php';
require dirname(__DIR__) . '/includes/storage/class-option-rule-storage.php';

use BWS\MetaConductor\Storage\OptionRuleStorage;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) {
        $fail[] = $name;
    }
};

/** Keep only the rows of one type from a projected kind list. */
$of_type = static function (array $rules, string $type): array {
    return array_values(array_filter(
        $rules,
        static fn(array $r): bool => ($r['type'] ?? '') === $type
    ));
};

/** Seed the option store and hand back a storage instance reading it. */
$storage_over = static function (array $option): OptionRuleStorage {
    $GLOBALS['mc_options'] = [OptionRuleStorage::OPTION_NAME => $option];

    return new OptionRuleStorage();
};

// --- Fixture: a PRE-0.8.0 settings option carrying every rule type, the two
// live types in their LEGACY shapes (pre-rename keys, combined acf_field_name,
// scalar trigger_term_id, [N] single-term arrays) — shapes the read-time
// projection must still canonicalize.

$settings = [
    'hierarchical_rules' => [
        ['name' => 'H1', 'taxonomy' => 'category', 'enabled' => true],
        ['name' => 'H2', 'taxonomy' => 'category', 'enabled' => false],
    ],
    'propagation_rules' => [
        ['name' => 'P1', 'taxonomy' => 'category', 'enabled' => true, 'conflict_handling' => 'merge'],
    ],
    'related_rules' => [
        // Legacy scalar trigger + FormTokenField single-value target.
        ['name' => 'R1', 'enabled' => true, 'trigger_term_id' => '12', 'target_term_id' => [34]],
        ['name' => 'R2', 'enabled' => true, 'trigger_term_id' => [5, 5, 0, 7], 'target_term_id' => '9'],
    ],
    'time_based_rules' => [
        ['name' => 'T1', 'enabled' => true, 'target_term_id' => ['21'], 'schedule_type' => 'daily'],
    ],
    'related_post_terms_rules' => [
        // Fully legacy: pre-rename keys, combined "post_type:field" values.
        [
            'name'                   => 'A1',
            'enabled'                => true,
            'acf_field_name'         => 'event:related_team',
            'reverse_acf_field_name' => 'team:related_events',
            'source_taxonomy'        => 'sport',
            'target_taxonomy'        => 'sport',
            'bidirectional'          => true,
        ],
        // The rare conflict_handling=skip row that gets flagged for review.
        ['name' => 'A2', 'enabled' => false, 'acf_field_name' => 'post:ref', 'source_taxonomy' => 'sport', 'conflict_handling' => 'skip'],
    ],
    'hierarchical_level_restriction_rules' => [
        ['name' => 'L1', 'taxonomy' => 'category', 'enabled' => true, 'restriction_mode' => 'deepest_only'],
    ],
    'title_slug_rules' => [
        ['name' => 'S1', 'enabled' => true, 'post_type' => 'event', 'title_pattern' => '{term:sport}'],
    ],
    // Non-rule globals: never rows, never a guard trigger.
    'conflict_handling_overrides' => [['taxonomy' => 'category', 'mode' => 'replace']],
    'manual_processing_enabled'   => true,
];

$all_types = [
    'hierarchical_rules',
    'propagation_rules',
    'related_rules',
    'time_based_rules',
    'related_post_terms_rules',
    'hierarchical_level_restriction_rules',
    'title_slug_rules',
];

// The same rules in the kind-list shape, KIND_TYPES order — what a 0.8.0+ site
// stores. Built here rather than by a storage helper: the regroup is fixture
// work since the pre-0.8.0 migration went.
$lists = ['term_rules' => [], 'format_rules' => []];
foreach (OptionRuleStorage::all_types() as $type) {
    $kind = (new OptionRuleStorage())->get_kind_for_type($type);
    foreach ($settings[$type] as $row) {
        $lists[$kind][] = ['type' => $type] + $row;
    }
}

// =============================================================================
// 1. THE PRE-0.8.0 GUARD — legacy ROWS with no kind list, and nothing else.
// =============================================================================

$check('legacy rows and no kind lists trip the guard',
    OptionRuleStorage::holds_pre_08_rows($settings) === true);
$check('one legacy row is enough',
    OptionRuleStorage::holds_pre_08_rows(['title_slug_rules' => [['name' => 'S1']]]) === true);
$check('empty legacy arrays (a 0.7.x site with no rules) do not',
    OptionRuleStorage::holds_pre_08_rows(array_fill_keys($all_types, [])) === false);
$check('a present kind list means the site already crossed 0.8.0',
    OptionRuleStorage::holds_pre_08_rows($settings + $lists) === false);
$check('a fresh install (empty option) does not',
    OptionRuleStorage::holds_pre_08_rows([]) === false);
$check('non-rule globals and garbage buckets do not',
    OptionRuleStorage::holds_pre_08_rows([
        'conflict_handling_overrides' => [['taxonomy' => 'category', 'mode' => 'replace']],
        'related_rules'               => 'not-an-array',
    ]) === false);

// Storage no longer upgrades on read: that is WHY the guard exists.
$check('a pre-0.8.0 option reads as no rules',
    $storage_over($settings)->get_kind_rules(OptionRuleStorage::KIND_TERM) === []);
$check('a stored kind list is read as it stands, legacy arrays beside it ignored',
    array_column($storage_over([
        'hierarchical_rules'           => [['taxonomy' => 'post_tag', 'name' => 'ghost']],
        OptionRuleStorage::KIND_TERM   => [
            ['type' => 'hierarchical_rules', 'taxonomy' => 'category', 'name' => 'second'],
            ['type' => 'propagation_rules',  'taxonomy' => 'category', 'name' => 'first'],
        ],
    ])->get_kind_rules(OptionRuleStorage::KIND_TERM), 'name') === ['second', 'first']);

// =============================================================================
// 3. TYPE COVERAGE — the kind map IS the enumeration now.
// =============================================================================

$storage = $storage_over([]);

$flat = OptionRuleStorage::all_types();
sort($flat);
$sorted_all = $all_types;
sort($sorted_all);
$check('all_types() is exactly the seven rule types', $flat === $sorted_all);

$check('every rule type maps to its kind', array_map(
    static fn(string $t): string => $storage->get_kind_for_type($t),
    $all_types
) === [
    'term_rules',
    'term_rules',
    'term_rules',
    'term_rules',
    'term_rules',
    'term_rules',
    'format_rules',
]);
$check('an unknown type maps to no kind', $storage->get_kind_for_type('nope_rules') === '');
$check('an unknown kind reads as empty, not a fatal', $storage->get_kind_rules('nope') === []);
$check('an unknown type reads as empty', $storage->get_rules('nope_rules') === []);

// A type may be declared in KIND_TYPES before its repeater subfields exist —
// that is what CONFIG_MIGRATED_TYPES is for — but never the other way round: a
// migrated type with no kind would be authored into a list nothing reads.
$migrated = array_merge(
    OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_TERM),
    OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_FORMAT)
);
$check('every config-migrated type is a known type',
    array_diff($migrated, $all_types) === []);
$check('the term kind reports its migrated types — all six as of #58',
    OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_TERM) === [
        'propagation_rules',
        'related_post_terms_rules',
        'time_based_rules',
        'related_rules',
        'hierarchical_rules',
        'hierarchical_level_restriction_rules',
    ]);
$check('the format kind reports its migrated type (#59)',
    OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_FORMAT)
        === ['title_slug_rules']);

// =============================================================================
// 4. THE READ — projection, normalization, and the per-type `id`.
// =============================================================================

$projected = array_merge(
    OptionRuleStorage::project_kind_rules($lists['term_rules']),
    OptionRuleStorage::project_kind_rules($lists['format_rules'])
);

$acf = $of_type($projected, 'related_post_terms_rules')[0];
$check('the read normalizes: legacy acf-ref keys are migrated at read time',
    $acf['taxonomy'] === 'sport'
    && $acf['keep_in_sync'] === true
    && $acf['post_type'] === 'event'
    && $acf['acf_field_name'] === 'related_team'
    && $acf['reverse_acf_field_name'] === 'related_events'
    && $acf['holder_role'] === 'target');
$check('the read normalizes: conflict_handling=skip is flagged for review',
    ($of_type($projected, 'related_post_terms_rules')[1]['_migration_flag'] ?? '')
        === 'conflict_handling=skip dropped');
$check('the read normalizes: related term ids are canonicalized',
    $of_type($projected, 'related_rules')[0]['trigger_term_id'] === [12]
    && $of_type($projected, 'related_rules')[0]['target_term_id'] === 34
    && $of_type($projected, 'related_rules')[1]['trigger_term_id'] === [5, 7]
    && $of_type($projected, 'related_rules')[1]['target_term_id'] === 9);
$check('the read normalizes: time_based single-term array collapses to int',
    $of_type($projected, 'time_based_rules')[0]['target_term_id'] === 21);

// FW-29: the projection is the GUARANTEED canonical shape — consumers never
// re-decode, so every form/legacy shape must land here, for every type.
$canon = static fn(array $row): array => OptionRuleStorage::project_kind_rules([$row])[0];
foreach (OptionRuleStorage::all_types() as $type) {
    $row = $canon([
        'type'              => $type,
        'post_types'        => ['page' => true, 'post' => false],
        'post_status'       => ['publish' => true, 'draft' => true],
        'filter_taxonomies' => ['category' => false, 'post_tag' => true],
        'target_term_id'    => ['34'],
        'trigger_term_id'   => ['12', '12', 0, '7'],
        'filter_terms'      => '5',
    ]);
    $check("canonical ($type): {slug:bool} maps → slug lists",
        $row['post_types'] === ['page']
        && $row['post_status'] === ['publish', 'draft']
        && $row['filter_taxonomies'] === ['post_tag']);
    $check("canonical ($type): [N] target → int, token lists → deduped int[]",
        $row['target_term_id'] === 34
        && $row['trigger_term_id'] === [12, 7]
        && $row['filter_terms'] === [5]);
}
$check('canonical: a slug list passes through, an empty target reads as 0',
    $canon(['type' => 'hierarchical_rules', 'post_types' => ['page', 'post'], 'target_term_id' => []])
        === ['type' => 'hierarchical_rules', 'post_types' => ['page', 'post'], 'target_term_id' => 0, 'id' => 0]);
$check('canonical: an absent field stays absent — empty means all, read `?? []`',
    !array_key_exists('post_types', $canon(['type' => 'propagation_rules'])));

// `id` is PER TYPE, not the kind-list position — the number the type-facing
// `get_rule()` takes. H2 in the term list sits at kind
// position 7 but must still report id 1; the sole title_slug rule must be 0.
$check('id is the per-type index, not the kind-list position',
    array_column($of_type($projected, 'hierarchical_rules'), 'id') === [0, 1]
    && array_column($of_type($projected, 'related_rules'), 'id') === [0, 1]
    && $of_type($projected, 'hierarchical_level_restriction_rules')[0]['id'] === 0
    && $of_type($projected, 'title_slug_rules')[0]['id'] === 0);

// An `id` already on the row is left alone — it is the caller's, not ours.
$check('a row carrying its own id keeps it',
    OptionRuleStorage::project_kind_rules([
        ['type' => 'hierarchical_rules', 'id' => 7],
    ])[0]['id'] === 7);

// Rows are read in AUTHORED order. Nothing may regroup them by type: order is
// the composition semantics a dispatcher pass executes in (ADR 0003 dec. 3).
$interleaved = $storage_over([
    OptionRuleStorage::KIND_TERM => [
        ['type' => 'hierarchical_rules', 'name' => 'a'],
        ['type' => 'propagation_rules',  'name' => 'b'],
        ['type' => 'hierarchical_rules', 'name' => 'c'],
    ],
    OptionRuleStorage::KIND_FORMAT => [],
]);
$check('the kind read preserves interleaved authored order',
    array_column($interleaved->get_kind_rules(OptionRuleStorage::KIND_TERM), 'name')
        === ['a', 'b', 'c']);
$check('a type read is that list filtered, still in authored order',
    array_column($interleaved->get_rules('hierarchical_rules'), 'name') === ['a', 'c']);
$check('a type read numbers ids within its own type',
    array_column($interleaved->get_rules('hierarchical_rules'), 'id') === [0, 1]);
$check('a caller-supplied type filter cannot widen a type read',
    $interleaved->get_rules('propagation_rules', ['type' => 'hierarchical_rules'])
        === $interleaved->get_rules('propagation_rules'));

// =============================================================================
// 5. get_rule() — the per-type $rule_id is an index within one type, across
//    a cross-type list.
// =============================================================================

$seed = static fn(): array => [
    OptionRuleStorage::KIND_TERM => [
        ['type' => 'hierarchical_rules', 'name' => 'h0', 'enabled' => true],
        ['type' => 'propagation_rules',  'name' => 'p0', 'enabled' => true],
        ['type' => 'hierarchical_rules', 'name' => 'h1', 'enabled' => false],
    ],
    OptionRuleStorage::KIND_FORMAT => [
        ['type' => 'title_slug_rules', 'name' => 's0', 'enabled' => true],
    ],
];

// get_rule() indexes by the per-type id, across a cross-type list.
$s = $storage_over($seed());
$check('get_rule reads the type\'s Nth rule, not the list\'s Nth',
    ($s->get_rule('hierarchical_rules', 1)['name'] ?? '') === 'h1');
$check('get_rule past the end of a type is null',
    $s->get_rule('hierarchical_rules', 2) === null);
$check('get_rule rejects a negative id', $s->get_rule('hierarchical_rules', -1) === null);
// =============================================================================
// ACF FIELD IDENTITY (#25) — the stored value carries the field KEY.
//
// `acf_get_field($name)` returns ONE arbitrary field when several share a bare
// name, so the identity has to travel in the stored value. The value is also
// the select's option key, which is why it stays ONE string and why parsing it
// has to tolerate every shape a site may already hold.
// =============================================================================

$check('a three-part value splits into post type, name and key',
    OptionRuleStorage::split_acf_field_value('event:related_team:field_abc')
        === ['event', 'related_team', 'field_abc']);
$check('a legacy two-part value yields an empty key, not a missing one',
    OptionRuleStorage::split_acf_field_value('event:related_team')
        === ['event', 'related_team', '']);
$check('a bare name yields a null post type, so the row keeps its own',
    OptionRuleStorage::split_acf_field_value('related_team') === [null, 'related_team', '']);
$check('a name is never confused for a key',
    OptionRuleStorage::split_acf_field_value('event:related_team')[2] === '');

$acf_shaped = static fn(array $row): array => OptionRuleStorage::project_kind_rules([
    ['type' => 'related_post_terms_rules', 'taxonomy' => 'sport'] + $row,
])[0];

$keyed = $acf_shaped([
    'acf_field_name'         => 'event:related_team:field_fwd',
    'reverse_acf_field_name' => 'team:related_events:field_rev',
]);
$check('the read splits the key out of both field values',
    $keyed['post_type'] === 'event'
    && $keyed['acf_field_name'] === 'related_team'
    && $keyed['acf_field_key'] === 'field_fwd'
    && $keyed['reverse_acf_field_name'] === 'related_events'
    && $keyed['reverse_acf_field_key'] === 'field_rev');

$legacy_read = $acf_shaped(['acf_field_name' => 'event:related_team']);
$check('a legacy row reads with an empty key, which callers treat as "by name"',
    $legacy_read['acf_field_name'] === 'related_team'
    && $legacy_read['post_type'] === 'event'
    && $legacy_read['acf_field_key'] === '');

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nKIND-LISTS FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) {
        fwrite(STDERR, "  \xE2\x9C\x97 $f\n");
    }
    exit(1);
}

fwrite(STDOUT, "KIND-LISTS OK — all $total assertions passed (pre-0.8.0 guard, every read on the kind list, canonical projection, ACF field values; #56/#66/#25/FW-29/FW-39).\n");
exit(0);
