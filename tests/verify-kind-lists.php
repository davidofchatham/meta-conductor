<?php
/**
 * H10 — Kind-list storage harness (#56 expand → #66 contract, Phase 4 Gate 1).
 *
 * The kind lists (`term_rules`, `format_rules`) are the ONLY stored rule shape
 * since #66. This harness covers the three things that has to mean:
 *
 * 1. **The migration off the seven type-keyed arrays is lossless.** `fan_in()`
 *    survives as migration code, and `fan_out()` survives with it because
 *    "lossless" is only checkable against an inverse. `related` and
 *    `related_post_terms` are LIVE on a real site, so this is the assertion
 *    that has to hold before that data is touched — a property of the
 *    transform, not of any one rule firing.
 * 2. **The upgrade cannot be missed and cannot clobber.** It runs on READ, so
 *    a front-end or cron request on a site whose admin has never been loaded
 *    still sees its rules; and a kind list that already exists wins over the
 *    legacy arrays, because re-deriving one would discard the author's
 *    cross-type order.
 * 3. **Every storage entry point operates on the kind list.** `get_rules()`
 *    and friends still speak in rule TYPES, but a type is a filter over a kind
 *    list now, and `$rule_id` is a per-type index into a cross-type array —
 *    the one number every mutator has to translate. The projected `id` is
 *    that same per-type number, so a rule read out and a rule addressed by
 *    a mutator agree on which rule is meant.
 *
 * Plus the #27 boundary: `update_option()` returns false both for a genuine
 * failure and for a write that was not needed, and the mutators must not
 * report the second as the first.
 *
 * Runs WITHOUT booting WordPress — the option store below is a plain array, so
 * the mutators are exercised as pure state transitions.
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
// `update_option` is modelled the way WordPress behaves, because that behaviour
// IS the subject of the #27 assertions: it returns FALSE when the new value
// equals the stored one, which is indistinguishable at the call site from a
// genuine write failure. Two switches let both be provoked:
//
//   $GLOBALS['mc_write_fails'] — the write does not happen and reports false.
//   $GLOBALS['mc_write_quiet'] — the write happens and reports false anyway.

$GLOBALS['mc_options']     = [];
$GLOBALS['mc_write_fails'] = false;
$GLOBALS['mc_write_quiet'] = false;

function get_option(string $name, $default = false) {
    return array_key_exists($name, $GLOBALS['mc_options'])
        ? $GLOBALS['mc_options'][$name]
        : $default;
}

function update_option(string $name, $value, $autoload = null): bool {
    if ($GLOBALS['mc_write_fails']) {
        return false;
    }

    $unchanged = array_key_exists($name, $GLOBALS['mc_options'])
        && $GLOBALS['mc_options'][$name] === $value;

    $GLOBALS['mc_options'][$name] = $value;

    return !$unchanged && !$GLOBALS['mc_write_quiet'];
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
    $GLOBALS['mc_options']     = [OptionRuleStorage::OPTION_NAME => $option];
    $GLOBALS['mc_write_fails'] = false;
    $GLOBALS['mc_write_quiet'] = false;

    return new OptionRuleStorage();
};

/** What is actually in the option right now. */
$stored = static fn(): array => $GLOBALS['mc_options'][OptionRuleStorage::OPTION_NAME] ?? [];

// --- Fixture: one PRE-#56 settings option carrying every rule type, with the -
// two live types in their LEGACY shapes (pre-rename keys, combined
// acf_field_name, scalar trigger_term_id, [N] single-term arrays) — the shapes
// the read-time migration already handles and which must survive the fan-in
// untouched.

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
    // Non-rule globals must be ignored by the regroup, not swept into a list.
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

$lists = OptionRuleStorage::fan_in($settings);

// =============================================================================
// 1. THE MIGRATION — fan_in / fan_out.
// =============================================================================

// --- Shape: both kinds always present, every row typed. ----------------------

$check('fan_in returns exactly the two kind keys', array_keys($lists) === ['term_rules', 'format_rules']);
$check('empty settings still yield both kind keys, both empty',
    OptionRuleStorage::fan_in([]) === ['term_rules' => [], 'format_rules' => []]);

$all_rows = array_merge($lists['term_rules'], $lists['format_rules']);
$check('every row carries a type', count($all_rows) === count(array_filter(
    $all_rows,
    static fn(array $r): bool => in_array($r['type'] ?? null, $all_types, true)
)));
$check('no rule is lost across the regroup', count($all_rows) === 10);
$check('kind lists are 0-indexed lists',
    array_keys($lists['term_rules']) === range(0, 8)
    && array_keys($lists['format_rules']) === [0]);

// --- Order: the documented Auto-Set tab order, authored order within a type. --

$check('term_rules follows the documented tab order',
    array_column($lists['term_rules'], 'type') === [
        'propagation_rules',
        'related_post_terms_rules',
        'related_post_terms_rules',
        'time_based_rules',
        'related_rules',
        'related_rules',
        'hierarchical_rules',
        'hierarchical_rules',
        'hierarchical_level_restriction_rules',
    ]);
$check('title_slug is the sole format_rules member',
    array_column($lists['format_rules'], 'type') === ['title_slug_rules']);
$check('authored order within a type is preserved',
    array_column($lists['term_rules'], 'name') === ['P1', 'A1', 'A2', 'T1', 'R1', 'R2', 'H1', 'H2', 'L1']);

// --- Losslessness: fan_out(fan_in(s)) reproduces the type-keyed arrays. ------

$back = OptionRuleStorage::fan_out($lists);
$check('fan_out returns all seven type keys', array_keys($back) === [
    'propagation_rules',
    'related_post_terms_rules',
    'time_based_rules',
    'related_rules',
    'hierarchical_rules',
    'hierarchical_level_restriction_rules',
    'title_slug_rules',
]);

$round_trips = true;
foreach ($all_types as $type) {
    if ($back[$type] !== array_values($settings[$type])) {
        $round_trips = false;
    }
}
$check('every legacy rule shape round-trips byte-for-byte', $round_trips);
$check('fan_out strips the grouping key',
    !array_key_exists('type', $back['propagation_rules'][0]));
$check('fan_out names every type, even the empty ones',
    OptionRuleStorage::fan_out(['term_rules' => [], 'format_rules' => []])
        === array_fill_keys(array_keys($back), []));
$check('fan_out drops untyped and non-array rows',
    OptionRuleStorage::fan_out([
        'term_rules' => ['garbage', ['taxonomy' => 'x'], ['type' => 'nope', 'taxonomy' => 'y']],
    ]) === array_fill_keys(array_keys($back), []));

// The regroup must not COERCE. Every key the legacy shapes carry has to arrive
// on the far side untouched — normalize_rule_shape() would have renamed four of
// these, and persisting that rename is exactly what would break the admin's
// "post_type:field" select option keys.
$legacy = $back['related_post_terms_rules'][0];
$check('fan-in applies no shape coercion to legacy rows',
    $legacy['source_taxonomy'] === 'sport'
    && $legacy['bidirectional'] === true
    && $legacy['acf_field_name'] === 'event:related_team'
    && $legacy['reverse_acf_field_name'] === 'team:related_events'
    && !isset($legacy['taxonomy'])
    && !isset($legacy['keep_in_sync']));

$check('non-rule global keys are not swept into a kind list',
    !in_array('conflict_handling_overrides', array_column($all_rows, 'type'), true));

// --- Idempotence. -----------------------------------------------------------

$check('fan_in is idempotent on its own output',
    OptionRuleStorage::fan_in($settings + $lists) === $lists);

$twice = array_merge($settings, $lists);
$check('a second migration pass finds nothing to change',
    OptionRuleStorage::fan_in($twice) === [
        'term_rules'   => $twice['term_rules'],
        'format_rules' => $twice['format_rules'],
    ]);

// A row round-tripped back through save_rule() would carry a stale `type`. The
// owning array names the type, so the stale value must be corrected, not kept —
// otherwise one mis-saved row disappears from its handler's filtered read.
$stale = OptionRuleStorage::fan_in([
    'hierarchical_rules' => [['name' => 'X', 'type' => 'related_rules']],
]);
$check('a stale row type is corrected by the owning array',
    $stale['term_rules'][0]['type'] === 'hierarchical_rules');

// --- Robustness: garbage in the option must not fatal or leak an untyped row. -

$junk = OptionRuleStorage::fan_in([
    'hierarchical_rules' => ['not-an-array', ['name' => 'ok']],
    'related_rules'      => 'not-an-array-either',
]);
$check('non-array rows and non-array type buckets are dropped',
    count($junk['term_rules']) === 1 && $junk['term_rules'][0]['name'] === 'ok');

// =============================================================================
// 2. THE UPGRADE ON READ — a pre-#56 option must still fire, and a kind list
//    that already exists must never be re-derived over.
// =============================================================================

$legacy_storage = $storage_over($settings);

$check('a pre-#56 option reads as rules without any admin load having run',
    array_column($legacy_storage->get_kind_rules(OptionRuleStorage::KIND_TERM), 'name')
        === ['P1', 'A1', 'A2', 'T1', 'R1', 'R2', 'H1', 'H2', 'L1']);
$check('the upgrade seeds the format kind too',
    array_column($legacy_storage->get_kind_rules(OptionRuleStorage::KIND_FORMAT), 'name')
        === ['S1']);
$check('a read alone persists nothing — the option is untouched',
    $stored() === $settings);
$check('non-rule globals survive the upgrade',
    $legacy_storage->get_raw_settings()['manual_processing_enabled'] === true);
$check('the legacy type-keyed keys are gone from what the layer serves',
    array_intersect($all_types, array_keys($legacy_storage->get_raw_settings())) === []);

// An ALREADY-authored kind list is authority. Re-deriving it from the legacy
// arrays would group by type and so discard the cross-type order the author
// dragged into place — the clobber #66 exists to make impossible.
$authored = [
    ['type' => 'hierarchical_rules', 'taxonomy' => 'category', 'name' => 'second'],
    ['type' => 'propagation_rules',  'taxonomy' => 'category', 'name' => 'first'],
];
$mixed_storage = $storage_over([
    // Legacy arrays that DISAGREE with the stored list, in every way that used
    // to force a rebuild: different order, an extra rule, a missing one.
    'propagation_rules'  => [['taxonomy' => 'category', 'name' => 'first']],
    'hierarchical_rules' => [['taxonomy' => 'category', 'name' => 'second'], ['taxonomy' => 'post_tag', 'name' => 'ghost']],
    OptionRuleStorage::KIND_TERM   => $authored,
    OptionRuleStorage::KIND_FORMAT => [],
]);
$check('a stored kind list wins over the legacy arrays, order and membership',
    array_column($mixed_storage->get_kind_rules(OptionRuleStorage::KIND_TERM), 'name')
        === ['second', 'first']);

// The prune is a consequence of the next WRITE, never of the read that decided
// which shape to trust.
$mixed_storage->save_rule('propagation_rules', -1, ['taxonomy' => 'post_tag', 'name' => 'added']);
$check('the next write prunes the legacy arrays out of storage',
    array_intersect($all_types, array_keys($stored())) === []);
$check('the write appended to the END of the authored list',
    array_column($stored()[OptionRuleStorage::KIND_TERM], 'name')
        === ['second', 'first', 'added']);

// =============================================================================
// 3. TYPE COVERAGE — the kind map IS the enumeration now.
// =============================================================================

$storage = $storage_over([]);

$flat = OptionRuleStorage::all_types();
sort($flat);
$sorted_all = $all_types;
sort($sorted_all);
$check('all_types() is exactly the seven rule types', $flat === $sorted_all);

$reported = $storage->get_rule_types();
sort($reported);
$check('get_rule_types() reports the same set — there is no second list',
    $reported === $sorted_all);

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
$check('an unknown type cannot be saved', $storage->save_rule('nope_rules', -1, ['a' => 1]) === -1);

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

// `id` is PER TYPE, not the kind-list position — the number the type-facing
// mutators take. H2 in the term list sits at kind
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
// 5. THE MUTATORS — every entry point operates on the kind list, and the
//    per-type $rule_id has to be translated to a list POSITION to do it.
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
$check('rule_exists agrees with get_rule',
    $s->rule_exists('propagation_rules', 0) && !$s->rule_exists('propagation_rules', 1));
$check('count_rules counts one type\'s slice',
    $s->count_rules('hierarchical_rules') === 2 && $s->count_rules('title_slug_rules') === 1);

// save_rule, update: the row at the type's Nth position, in place.
$s = $storage_over($seed());
$check('save_rule updates the type\'s Nth rule in place',
    $s->save_rule('hierarchical_rules', 1, ['name' => 'h1-edited']) === 1);
$check('the update landed on the right list position, order intact',
    array_column($stored()[OptionRuleStorage::KIND_TERM], 'name') === ['h0', 'p0', 'h1-edited']);
$check('the update kept the row typed',
    (($stored()[OptionRuleStorage::KIND_TERM][2] ?? [])['type'] ?? '') === 'hierarchical_rules');
$check('save_rule rejects an id the type does not have',
    $s->save_rule('hierarchical_rules', 5, ['name' => 'nope']) === -1);
$check('save_rule rejects a negative id that is not the create sentinel',
    $s->save_rule('hierarchical_rules', -2, ['name' => 'nope']) === -1);

// save_rule, create: appended to the END of the kind list. Position is order
// and order is composition, so an unplaced rule belongs last, where it cannot
// change what the existing list already does.
$s = $storage_over($seed());
$check('save_rule returns the new per-type id, not the list position',
    $s->save_rule('propagation_rules', -1, ['name' => 'p1']) === 1);
$check('a new rule is appended to the end of the kind list',
    array_column($stored()[OptionRuleStorage::KIND_TERM], 'name') === ['h0', 'p0', 'h1', 'p1']);
$check('the appended row carries its type',
    (($stored()[OptionRuleStorage::KIND_TERM][3] ?? [])['type'] ?? '') === 'propagation_rules');
$check('a read-time id on the payload is never persisted',
    !array_key_exists('id', $stored()[OptionRuleStorage::KIND_TERM][3] ?? []));
$check('a create writes into the right KIND',
    $s->save_rule('title_slug_rules', -1, ['name' => 's1']) === 1
    && count($stored()[OptionRuleStorage::KIND_FORMAT]) === 2
    && count($stored()[OptionRuleStorage::KIND_TERM]) === 4);

// A stale `type` on the payload is corrected, not trusted — the argument names
// the type, the same rule fan_in() follows.
$s = $storage_over($seed());
$s->save_rule('propagation_rules', -1, ['name' => 'p1', 'type' => 'related_rules']);
$check('a stale type on the payload is overwritten by the argument',
    (($stored()[OptionRuleStorage::KIND_TERM][3] ?? [])['type'] ?? '') === 'propagation_rules');

// delete_rule: removes the type's Nth row and renumbers the type, leaving the
// other types where they were.
$s = $storage_over($seed());
$check('delete_rule removes the type\'s Nth rule', $s->delete_rule('hierarchical_rules', 0));
$check('the remaining rows keep their order',
    array_column($stored()[OptionRuleStorage::KIND_TERM] ?? [], 'name') === ['p0', 'h1']);
// A hole would still READ correctly (the projection walks values), so the
// symptom of a missing reindex is a stored list that is no longer a list —
// which serializes differently and breaks any === against a rebuilt one.
$check('the stored list stays 0-indexed after a delete',
    array_keys($stored()[OptionRuleStorage::KIND_TERM] ?? []) === [0, 1]);
$check('the surviving rule of that type renumbers to 0',
    ($s->get_rule('hierarchical_rules', 0)['name'] ?? '') === 'h1');
$check('the other type is unaffected',
    ($s->get_rule('propagation_rules', 0)['name'] ?? '') === 'p0');
$check('delete_rule on an id the type does not have is false',
    !$s->delete_rule('hierarchical_rules', 3));
$check('delete_rule of an unknown type is false', !$s->delete_rule('nope_rules', 0));

// bulk_toggle_rules: per-type ids again, and no-ops are not counted.
$s = $storage_over($seed());
$check('bulk_toggle counts only the rules whose state changes',
    $s->bulk_toggle_rules('hierarchical_rules', [0, 1], false) === 1);
$check('bulk_toggle toggled the right list position',
    array_column($stored()[OptionRuleStorage::KIND_TERM], 'enabled') === [false, true, false]);
$check('bulk_toggle of an all-correct set reports 0 without failing',
    $s->bulk_toggle_rules('hierarchical_rules', [0, 1], false) === 0);
$check('bulk_toggle of an unknown type reports 0',
    $s->bulk_toggle_rules('nope_rules', [0], true) === 0);

// duplicate_rule rides on get_rule + save_rule, so it must land at the end too.
$s = $storage_over($seed());
$check('duplicate_rule appends a copy with the next per-type id',
    $s->duplicate_rule('hierarchical_rules', 0) === 2);
$check('the copy is last in the list and named',
    (($stored()[OptionRuleStorage::KIND_TERM][3] ?? [])['name'] ?? '') === 'h0 (Copy)');

// search_rules sweeps both kinds and reports each row's own type.
$s = $storage_over($seed());
$hits = $s->search_rules('0');
$check('search_rules sweeps every kind', count($hits) === 3);
$check('search_rules reports the row\'s type',
    array_column($hits, 'type')
        === ['hierarchical_rules', 'propagation_rules', 'title_slug_rules']);
$check('search_rules honours filters',
    count($s->search_rules('h', ['type' => 'hierarchical_rules'])) === 2);

// export / import: the interchange format stays type-keyed, but both ends of it
// now read and write the kind list.
$s      = $storage_over($seed());
$export = $s->export_rules();
$check('export groups the kind lists back by type',
    array_keys($export['rules']) === ['propagation_rules', 'hierarchical_rules', 'title_slug_rules']);
$check('export omits types with no rules',
    !array_key_exists('time_based_rules', $export['rules']));
$check('export honours an explicit type list',
    array_keys($s->export_rules(['types' => ['title_slug_rules', 'nope_rules']])['rules'])
        === ['title_slug_rules']);

$s      = $storage_over($seed());
$result = $s->import_rules([
    'time_based_rules' => [['name' => 't0'], ['name' => 't1']],
    'nope_rules'       => [['name' => 'x']],
]);
$check('import reports what it imported', $result['imported'] === 2);
$check('import rejects an unknown type by name',
    $result['errors'] === ['Invalid rule type: nope_rules']);
$check('imported rules land in the kind list, at the end, typed',
    array_column($stored()[OptionRuleStorage::KIND_TERM], 'name')
        === ['h0', 'p0', 'h1', 't0', 't1']
    && (($stored()[OptionRuleStorage::KIND_TERM][4] ?? [])['type'] ?? '') === 'time_based_rules');
$check('import skips an exact-name duplicate',
    $s->import_rules(['time_based_rules' => [['name' => 't0']]])['skipped'] === 1);

// =============================================================================
// 6. #27 — a write that was not needed is not a failure.
// =============================================================================

$s = $storage_over($seed());
$check('save_rule of data identical to storage reports success, not -1',
    $s->save_rule('hierarchical_rules', 0, ['name' => 'h0', 'enabled' => true]) === 0);

$s = $storage_over($seed());
$GLOBALS['mc_write_quiet'] = true;
$check('a write that succeeds but reports false is still a success',
    $s->save_rule('propagation_rules', -1, ['name' => 'p1']) === 1);
$check('...and it really did persist',
    count($stored()[OptionRuleStorage::KIND_TERM]) === 4);
$check('import counts a quiet write as imported, not an error',
    $s->import_rules(['time_based_rules' => [['name' => 't0']]]) === [
        'imported' => 1,
        'skipped'  => 0,
        'errors'   => [],
    ]);
$GLOBALS['mc_write_quiet'] = false;

// A GENUINE failure still fails, and must not leave the request cache pointing
// at data the database does not hold — a later save in the same request would
// otherwise persist the failed data as its baseline.
$s = $storage_over($seed());
$GLOBALS['mc_write_fails'] = true;
$check('a genuine write failure still reports failure',
    $s->save_rule('propagation_rules', -1, ['name' => 'p1']) === -1);
$check('import counts a genuine failure as an error',
    $s->import_rules(['time_based_rules' => [['name' => 't0']]])['errors']
        === ['Failed to import rule: t0']);
$check('bulk_toggle reports 0 on a genuine write failure',
    $s->bulk_toggle_rules('hierarchical_rules', [0], false) === 0);
$GLOBALS['mc_write_fails'] = false;
$check('the failed write did not poison the request cache',
    array_column($s->get_kind_rules(OptionRuleStorage::KIND_TERM), 'name') === ['h0', 'p0', 'h1']);

// =============================================================================
// 7. THE ADMIN-LOAD PERSIST — one write, then nothing.
// =============================================================================

$s = $storage_over($settings);
$check('the first admin load persists the upgraded shape',
    $s->maybe_migrate_kind_lists() === true);
$check('what it wrote is the kind shape, legacy arrays pruned',
    array_keys($stored()) === [
        'conflict_handling_overrides',
        'manual_processing_enabled',
        OptionRuleStorage::KIND_TERM,
        OptionRuleStorage::KIND_FORMAT,
    ]);
$check('the persisted list is what the read path was already serving',
    ($stored()[OptionRuleStorage::KIND_TERM] ?? null) === $lists['term_rules']);
$check('a second load finds nothing to do',
    (new OptionRuleStorage())->maybe_migrate_kind_lists() === false);
$check('...and the marker is set',
    get_option(OptionRuleStorage::KIND_SCHEMA_FLAG) === OptionRuleStorage::KIND_SCHEMA_VERSION);

// The acf-ref rewrite runs over the kind list now, and is flag-gated once.
$s = $storage_over($settings);
$check('the acf-ref rewrite persists the key renames', $s->maybe_migrate_acf_ref_storage() === true);
$rewritten = array_values(array_filter(
    $stored()[OptionRuleStorage::KIND_TERM] ?? [],
    static fn(array $r): bool => ($r['type'] ?? '') === 'related_post_terms_rules'
));
$check('the rewritten row is in the new shape, in the list',
    (($rewritten[0] ?? [])['taxonomy'] ?? '') === 'sport'
    && (($rewritten[0] ?? [])['keep_in_sync'] ?? null) === true
    && !array_key_exists('bidirectional', $rewritten[0] ?? []));
$check('the combined acf_field_name is NOT split in storage',
    (($rewritten[0] ?? [])['acf_field_name'] ?? '') === 'event:related_team');
$check('the rewrite is flag-gated to one run',
    (new OptionRuleStorage())->maybe_migrate_acf_ref_storage() === false);

// ACF is not loaded in this file yet (the shims are declared further down, on
// purpose), so the row above still has no field key. The flag must be WITHHELD:
// setting it here would retire the backfill scan forever over a row it never
// got to look at. (#25)
$check('the flag is withheld while a key is still missing and ACF is absent',
    (int) get_option(OptionRuleStorage::ACFREF_SCHEMA_FLAG, 0)
        < OptionRuleStorage::ACFREF_SCHEMA_VERSION);

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

// ACF shims, declared inside a closure ON PURPOSE: a top-level function
// declaration is hoisted at compile time, which would make ACF "present" for
// the deferral block above — whose whole subject is what happens when it is
// absent.
(static function (): void {
    $GLOBALS['mc_acf_fields'] = [
        'group_a' => [
            ['key' => 'field_alpha', 'name' => 'related_team', 'type' => 'relationship'],
            ['key' => 'field_dupe_a', 'name' => 'twin', 'type' => 'relationship'],
        ],
        'group_b' => [
            // The #25 shape: a SECOND field with the same bare name, on the
            // same post type, in a different group.
            ['key' => 'field_dupe_b', 'name' => 'twin', 'type' => 'relationship'],
            ['key' => 'field_text', 'name' => 'related_team', 'type' => 'text'],
        ],
    ];

    function acf_get_field_groups(array $args = []): array {
        return [['key' => 'group_a', 'title' => 'A'], ['key' => 'group_b', 'title' => 'B']];
    }

    function acf_get_fields($group): array {
        return $GLOBALS['mc_acf_fields'][(string) $group] ?? [];
    }
})();

$GLOBALS['mc_options'] = [
    'bws_meta_conductor_settings' => [
        OptionRuleStorage::KIND_TERM => [
            ['type' => 'related_post_terms_rules', 'taxonomy' => 'sport', 'holder_role' => 'target',
             'acf_field_name' => 'event:related_team', 'reverse_acf_field_name' => 'event:related_team'],
            ['type' => 'related_post_terms_rules', 'taxonomy' => 'sport', 'holder_role' => 'target',
             'acf_field_name' => 'event:twin'],
            ['type' => 'related_post_terms_rules', 'taxonomy' => 'sport', 'holder_role' => 'target',
             'acf_field_name' => 'event:related_team:field_alpha'],
        ],
        OptionRuleStorage::KIND_FORMAT => [],
    ],
];

$backfilled = (new OptionRuleStorage())->maybe_migrate_acf_ref_storage();
$after      = $GLOBALS['mc_options']['bws_meta_conductor_settings'][OptionRuleStorage::KIND_TERM];

$check('the one-shot reports the rewrite it performed', $backfilled === true);
$check('an unambiguous name gains its key, in BOTH field values',
    ($after[0]['acf_field_name'] ?? '') === 'event:related_team:field_alpha'
    && ($after[0]['reverse_acf_field_name'] ?? '') === 'event:related_team:field_alpha');
$check('a name matching two fields is LEFT two-part, never guessed',
    ($after[1]['acf_field_name'] ?? '') === 'event:twin');
$check('a row that already carries a key is untouched',
    ($after[2]['acf_field_name'] ?? '') === 'event:related_team:field_alpha');
$check('the backfill is flag-gated once ACF has actually been consulted',
    (new OptionRuleStorage())->maybe_migrate_acf_ref_storage() === false
    && (int) get_option(OptionRuleStorage::ACFREF_SCHEMA_FLAG, 0)
        === OptionRuleStorage::ACFREF_SCHEMA_VERSION);

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nKIND-LISTS FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) {
        fwrite(STDERR, "  \xE2\x9C\x97 $f\n");
    }
    exit(1);
}

fwrite(STDOUT, "KIND-LISTS OK — all $total assertions passed (migration lossless + idempotent, upgrade-on-read never clobbers, every mutator on the kind list, no-op-equal is not failure, ACF field identity by key; #56/#66/#25).\n");
exit(0);
