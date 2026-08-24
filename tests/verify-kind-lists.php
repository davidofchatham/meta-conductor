<?php
/**
 * H10 — Kind-list fan-in harness (#56, Phase 4 Gate 1).
 *
 * Locks the 7-type-keyed-arrays → 2-kind-keyed-ordered-lists regroup that
 * ADR 0003 decision 1 calls for: `OptionRuleStorage::fan_in()`, its inverse
 * `fan_out()`, and the two read-time projections that must agree
 * (`project_kind_rules()` vs `project_type_rules()`).
 *
 * Why a harness and not a behaviour sweep: `related` and `related_post_terms`
 * are LIVE on a real site, so this is a breaking schema change under the
 * CLAUDE.md live-rule-type rule. What has to be true before that data is
 * touched is a property of the transform, not of any one rule firing —
 * fan-in must be **idempotent**, **lossless**, and every legacy rule shape
 * must **round-trip**. A sweep can only show that the rules it happens to
 * exercise still fire; these assertions show the transform cannot lose a row
 * or a key it was never told about.
 *
 * The equivalence block is the load-bearing one. `get_enabled_rules()` moved
 * off the type-keyed read onto the kind list with no handler edits, which is
 * only safe while the kind path reproduces the type path element for element —
 * including the per-type `id`, which `TitleSlugHandler::write_rule_status()`
 * persists per-rule state against.
 *
 * Runs WITHOUT booting WordPress: every method under test is a pure function of
 * its input, and the class file only DECLARES at load.
 *
 * Run:  php tests/verify-kind-lists.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// --- Load the class under test (declare-only, no WP at load). ----------------

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

// --- Fixture: one settings option carrying every rule type, with the two -----
// live types in their LEGACY shapes (pre-rename keys, combined acf_field_name,
// scalar trigger_term_id, [N] single-term arrays) — the shapes the existing
// read-time migration already handles and which must survive the fan-in
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

$migrated = array_merge($settings, $lists);
$check('a second migration pass finds nothing to change',
    OptionRuleStorage::fan_in($migrated) === [
        'term_rules'   => $migrated['term_rules'],
        'format_rules' => $migrated['format_rules'],
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

// --- Equivalence: the kind read path reproduces the type read path. ----------
// This is what lets get_enabled_rules() move onto the kind list with no handler
// edits. It must hold AFTER normalization, per type, including `id`.

$projected = array_merge(
    OptionRuleStorage::project_kind_rules($lists['term_rules']),
    OptionRuleStorage::project_kind_rules($lists['format_rules'])
);

$equivalent = [];
foreach ($all_types as $type) {
    $via_kind = $of_type($projected, $type);
    $via_type = array_values(OptionRuleStorage::project_type_rules($type, $settings[$type]));

    // The kind path adds `type`; that is the only permitted difference.
    $stripped = array_map(static function (array $r): array {
        unset($r['type']);
        return $r;
    }, $via_kind);

    if ($stripped !== $via_type) {
        $equivalent[] = $type;
    }
}
$check('kind read == type read for every rule type, post-normalization',
    $equivalent === []);

// Spot-check that normalization actually ran on the kind path — an equivalence
// that held because BOTH paths skipped it would be worthless.
$acf = $of_type($projected, 'related_post_terms_rules')[0];
$check('kind path normalizes: legacy acf-ref keys are migrated at read time',
    $acf['taxonomy'] === 'sport'
    && $acf['keep_in_sync'] === true
    && $acf['post_type'] === 'event'
    && $acf['acf_field_name'] === 'related_team'
    && $acf['reverse_acf_field_name'] === 'related_events'
    && $acf['holder_role'] === 'target');
$check('kind path normalizes: conflict_handling=skip is flagged for review',
    ($of_type($projected, 'related_post_terms_rules')[1]['_migration_flag'] ?? '')
        === 'conflict_handling=skip dropped');
$check('kind path normalizes: related term ids are canonicalized',
    $of_type($projected, 'related_rules')[0]['trigger_term_id'] === [12]
    && $of_type($projected, 'related_rules')[0]['target_term_id'] === 34
    && $of_type($projected, 'related_rules')[1]['trigger_term_id'] === [5, 7]
    && $of_type($projected, 'related_rules')[1]['target_term_id'] === 9);
$check('kind path normalizes: time_based single-term array collapses to int',
    $of_type($projected, 'time_based_rules')[0]['target_term_id'] === 21);

// `id` is PER TYPE, not the kind-list position. TitleSlugHandler persists
// per-rule status keyed on this number, so re-basing it onto the kind list
// would silently repoint every stored status. H2 in the term list sits at kind
// position 7 but must still report id 1; the sole title_slug rule must be 0.
$check('id is the per-type index, not the kind-list position',
    array_column($of_type($projected, 'hierarchical_rules'), 'id') === [0, 1]
    && array_column($of_type($projected, 'related_rules'), 'id') === [0, 1]
    && $of_type($projected, 'hierarchical_level_restriction_rules')[0]['id'] === 0
    && $of_type($projected, 'title_slug_rules')[0]['id'] === 0);

// …and the two paths must still agree when the stored array is SPARSE. Nothing
// the plugin writes produces holes (save_rule appends, delete_rule reindexes,
// Wireframe stores lists), but the kind list has no keys to preserve — fan_in
// reindexes — so a key-based id on the type side would diverge here silently,
// and the equivalence is the whole basis for "no handler file changes".
$sparse       = [3 => ['name' => 'S1'], 9 => ['name' => 'S2']];
$sparse_type  = array_values(OptionRuleStorage::project_type_rules('hierarchical_rules', $sparse));
$sparse_kind  = OptionRuleStorage::project_kind_rules(
    OptionRuleStorage::fan_in(['hierarchical_rules' => $sparse])['term_rules']
);
$check('sparse stored arrays: both paths agree on id',
    array_column($sparse_type, 'id') === [0, 1]
    && array_column($sparse_kind, 'id') === [0, 1]);

// --- Type ⇒ kind mapping (the lookup get_enabled_rules() routes through). ----

$storage = new OptionRuleStorage();

// The kind map and the storage layer's valid-type list must be the SAME SET.
// This is what lets get_enabled_rules() drop its type-keyed fallback: a type
// added to one list and not the other fails here instead of silently reading
// zero rules at runtime. $valid_types is private, so read it reflectively —
// the coupling is the point of the assertion.
$vt    = new ReflectionProperty(OptionRuleStorage::class, 'valid_types');
$valid = $vt->getValue($storage);
sort($valid);
$mapped = $all_types;
sort($mapped);
$check('KIND_TYPES covers exactly the storage layer\'s valid rule types', $valid === $mapped);

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

// --- The AUTHORED list (0.8.0, #57). ----------------------------------------
//
// Once a repeater writes `term_rules` directly, a plain fan-in is no longer a
// refresh of that key — it is a CLOBBER, because the fan-in groups by type and
// so cannot reproduce the cross-type order the author just dragged into place.
// authored_kind_list() draws that line: keep the stored list while it still
// agrees with the type-keyed arrays, rebuild only when something wrote behind
// the repeater's back. These assertions are what stop a well-meaning
// "simplify this back to fan_in()" from silently discarding rule order on the
// next admin load.

$migrated = OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_TERM);
$check('the term kind reports its migrated types — all six as of #58',
    $migrated === [
        'propagation_rules',
        'related_post_terms_rules',
        'time_based_rules',
        'related_rules',
        'hierarchical_rules',
        'hierarchical_level_restriction_rules',
    ]);
$check('the format kind has no migrated types yet (#59)',
    OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_FORMAT) === []);

// fan_out_types: the save path's projection back onto the legacy arrays.
$rows = [
    ['type' => 'hierarchical_rules', 'taxonomy' => 'a'],
    ['type' => 'propagation_rules',  'taxonomy' => 'b'],
    ['type' => 'title_slug_rules',   'post_type' => 'c'],   // wrong kind, not requested
    ['type' => 'hierarchical_rules', 'taxonomy' => 'd'],
    'not-an-array',
    ['taxonomy' => 'e'],                                    // no type at all
];
$out = OptionRuleStorage::fan_out_types($rows, $migrated);
$check('fan_out_types keeps per-type order',
    $out['hierarchical_rules'] === [['taxonomy' => 'a'], ['taxonomy' => 'd']]);
$check('fan_out_types strips the grouping key',
    !array_key_exists('type', $out['propagation_rules'][0]));
$check('fan_out_types drops rows of unrequested types',
    !array_key_exists('title_slug_rules', $out));
$check('fan_out_types drops untyped and non-array rows',
    array_sum(array_map('count', $out)) === 3);
$check('fan_out_types always names every requested type',
    array_keys($out) === $migrated);
// An emptied repeater must CLEAR the legacy arrays, not leave orphans behind.
$check('fan_out_types on an empty list clears every requested type',
    OptionRuleStorage::fan_out_types([], $migrated)
        === array_fill_keys($migrated, []));

// authored_kind_list: keep authored order when the two shapes still agree.
// The live related row rides in the authored list like everything else now —
// #58 gave it repeater subfields, so the sanitize-gutting hazard that used to
// exclude it is gone.
$authored = [
    ['type' => 'hierarchical_rules', 'taxonomy' => 'h1'],
    ['type' => 'related_rules',      'taxonomy' => 'r1'],   // interleaved:
    ['type' => 'propagation_rules',  'taxonomy' => 'p1'],   // fan_in can't
    ['type' => 'hierarchical_rules', 'taxonomy' => 'h2'],   // reproduce this
];
$settings = [
    'propagation_rules'  => [['taxonomy' => 'p1']],
    'hierarchical_rules' => [['taxonomy' => 'h1'], ['taxonomy' => 'h2']],
    'related_rules'      => [['taxonomy' => 'r1']],
    OptionRuleStorage::KIND_TERM => $authored,
];
$check('authored order survives when the legacy arrays agree',
    OptionRuleStorage::authored_kind_list(OptionRuleStorage::KIND_TERM, $settings) === $authored);
// (The drop-a-non-migrated-row branch is unreachable for the term kind since
// #58 — every term type is migrated — but stays in the code: it is what makes
// adding a future type to KIND_TYPES before CONFIG_MIGRATED_TYPES safe.)

// A row naming NO type is different in kind: nothing else holds it, so it
// cannot be quietly filtered out of a list we then declare trustworthy. It
// must force the rebuild, so the stored shape is recomputed from the arrays
// that ARE authoritative rather than silently shedding a row.
// What a rebuild produces: the fan-in restricted to migrated types, which is
// also what an absent stored list seeds (asserted below).
$no_stored = $settings;
unset($no_stored[OptionRuleStorage::KIND_TERM]);
$rebuild_result = OptionRuleStorage::authored_kind_list(OptionRuleStorage::KIND_TERM, $no_stored);

foreach ([
    'untyped row'       => ['taxonomy' => 'x'],
    'unknown-type row'  => ['type' => 'not_a_rule_type', 'taxonomy' => 'x'],
    'non-array row'     => 'garbage',
] as $label => $bad) {
    $corrupt = $settings;
    $corrupt[OptionRuleStorage::KIND_TERM][] = $bad;
    $check("an $label forces a rebuild rather than a silent drop",
        OptionRuleStorage::authored_kind_list(OptionRuleStorage::KIND_TERM, $corrupt) === $rebuild_result);
}

// Something wrote a legacy array behind the repeater's back (CLI save_rule, a
// seeded fixture, an import): rebuild, so the new rule is visible rather than
// hidden behind a stale authored copy.
$behind_back = $settings;
$behind_back['propagation_rules'][] = ['taxonomy' => 'p2'];
$rebuilt = OptionRuleStorage::authored_kind_list(OptionRuleStorage::KIND_TERM, $behind_back);
$check('a write behind the repeater\'s back rebuilds the list',
    count($rebuilt) === 5);
// fan_in() APPENDS `type` to each row, so the key order differs from the
// authored rows above — compare on content, not on array identity.
$check('the rebuild carries the new rule',
    in_array(['taxonomy' => 'p2', 'type' => 'propagation_rules'], $rebuilt, true));
$check('the rebuild carries the live types too (#58)',
    in_array(['taxonomy' => 'r1', 'type' => 'related_rules'], $rebuilt, true));

// Idempotent: feeding the result back in must not change it again.
$behind_back[OptionRuleStorage::KIND_TERM] = $rebuilt;
$check('authored_kind_list is idempotent',
    OptionRuleStorage::authored_kind_list(OptionRuleStorage::KIND_TERM, $behind_back) === $rebuilt);

// A kind no repeater authors yet stays a pure derived duplicate (#56's regime).
$fmt = ['title_slug_rules' => [['pattern' => 'x']]];
$check('a kind with no migrated types is still the plain fan-in',
    OptionRuleStorage::authored_kind_list(OptionRuleStorage::KIND_FORMAT, $fmt)
        === OptionRuleStorage::fan_in($fmt)[OptionRuleStorage::KIND_FORMAT]);

// A first upgrade has no stored list at all — seed from the legacy arrays.
$unseeded = $settings;
unset($unseeded[OptionRuleStorage::KIND_TERM]);
$check('an absent stored list seeds from the legacy arrays',
    OptionRuleStorage::authored_kind_list(OptionRuleStorage::KIND_TERM, $unseeded) === [
        ['taxonomy' => 'p1', 'type' => 'propagation_rules'],
        ['taxonomy' => 'r1', 'type' => 'related_rules'],
        ['taxonomy' => 'h1', 'type' => 'hierarchical_rules'],
        ['taxonomy' => 'h2', 'type' => 'hierarchical_rules'],
    ]);

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nKIND-LISTS FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) {
        fwrite(STDERR, "  \xE2\x9C\x97 $f\n");
    }
    exit(1);
}

fwrite(STDOUT, "KIND-LISTS OK — all $total assertions passed (fan-in idempotent, lossless, kind read == type read; #56).\n");
exit(0);
