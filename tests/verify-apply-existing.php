<?php
/**
 * H15 — Apply-to-existing-posts harness (Phase 7 / FW-16).
 *
 * A bulk run is named by a RULE CHOICE — kind + position in the kind list +
 * row fingerprint — built when the page loads and re-checked when the run
 * starts. A row has no stable id, so the fingerprint is the only thing that
 * tells "the rule the author picked" from "whatever sits at that position
 * now". The failure this harness exists for is a run over a DIFFERENT rule
 * than the one chosen: silent, and a bulk write.
 *
 * What this pins:
 *   1. The fingerprint: stable across reads, blind to the read-time `id`,
 *      sensitive to `enabled` and to every authored field.
 *   2. The codec: encode → decode round-trips for both kinds and the
 *      "All enabled rules" sentinel; malformed values decode to nothing.
 *   3. The stale check: an edited, re-ordered, deleted or toggled row is
 *      refused — never resolved to a neighbour.
 *   4. Reach → statuses: `post_status` narrows for every type whose gate it
 *      is, NEVER for `related_post_terms` (don't 6e(b) — there it gates the
 *      source), and trash / auto-draft never make it into the set.
 *   5. The disabled-row override: the enabled rows plus exactly the included
 *      one, in authored order; with no override, today's enabled filter.
 *   6. The Apply page dropdown: every row of both kinds, grouped, each value
 *      decoding back to its own row; labels re-derive position and the
 *      "(disabled)" marker from the row, not from its stored title.
 *
 * Run:  php tests/verify-apply-existing.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

// --- Minimal WP option shim — enough for OptionRuleStorage to read. ----------

$GLOBALS['mc_options'] = [];

if (!function_exists('get_option'))    { function get_option($n, $d = false) { return $GLOBALS['mc_options'][$n] ?? $d; } }
if (!function_exists('update_option')) { function update_option($n, $v, $a = null) { $GLOBALS['mc_options'][$n] = $v; return true; } }
if (!function_exists('__'))            { function __($s, $d = null) { return $s; } }

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Admin\ApplyPage;
use BWS\MetaConductor\Core\RuleChoice;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

$KIND_TERM   = OptionRuleStorage::KIND_TERM;
$KIND_FORMAT = OptionRuleStorage::KIND_FORMAT;

/** A fresh storage instance over the option — a real second READ, no shared cache. */
$read = function (string $kind, array $option, array $filters = []): array {
    $GLOBALS['mc_options'] = [OptionRuleStorage::OPTION_NAME => $option];

    return (new OptionRuleStorage())->get_kind_rules($kind, $filters);
};

$term_rows = [
    ['type' => 'hierarchical_rules', 'row_title' => 'H1', 'taxonomy' => 'category', 'enabled' => true],
    ['type' => 'related_rules', 'row_title' => 'R1', 'taxonomy' => 'category', 'target_term_id' => [7], 'trigger_term_id' => [3], 'enabled' => false],
    ['type' => 'hierarchical_rules', 'row_title' => 'H2', 'taxonomy' => 'post_tag', 'enabled' => true],
];
$format_rows = [
    ['type' => 'title_slug_rules', 'name' => 'T1', 'post_type' => 'post', 'title_pattern' => '{title}', 'enabled' => true],
];
$option = [$KIND_TERM => $term_rows, $KIND_FORMAT => $format_rows];

// --- 1. Fingerprint. ----------------------------------------------------------

$first  = $read($KIND_TERM, $option);
$second = $read($KIND_TERM, $option);

$check('the same row fingerprints the same across two reads',
    RuleChoice::fingerprint($first[1]) === RuleChoice::fingerprint($second[1]));
$check('two different rows fingerprint differently',
    RuleChoice::fingerprint($first[0]) !== RuleChoice::fingerprint($first[2]));

// `id` is the per-type index, re-derived on every read: H1 and H2 are id 0 and
// 1, and moving a related row between them would not change either row.
$with_other_id       = $first[0];
$with_other_id['id'] = 99;
$check('the read-time id is not part of the fingerprint',
    RuleChoice::fingerprint($with_other_id) === RuleChoice::fingerprint($first[0]));

$toggled = $option;
$toggled[$KIND_TERM][1]['enabled'] = true;
$check('toggling enabled changes the fingerprint',
    RuleChoice::fingerprint($read($KIND_TERM, $toggled)[1]) !== RuleChoice::fingerprint($first[1]));

$edited = $option;
$edited[$KIND_TERM][1]['target_term_id'] = [8];
$check('editing an authored field changes the fingerprint',
    RuleChoice::fingerprint($read($KIND_TERM, $edited)[1]) !== RuleChoice::fingerprint($first[1]));

// The fingerprint is taken over the PROJECTED row, so a legacy scalar and its
// canonical int[] are the same rule, not a change.
$legacy = $option;
$legacy[$KIND_TERM][1]['trigger_term_id'] = 3;
$check('a shape difference normalize_rule_shape() erases is not a change',
    RuleChoice::fingerprint($read($KIND_TERM, $legacy)[1]) === RuleChoice::fingerprint($first[1]));

// --- 2. Codec. --------------------------------------------------------------

$formats = $read($KIND_FORMAT, $option);
$first   = $read($KIND_TERM, $option);

foreach ([[$KIND_TERM, 1, $first[1]], [$KIND_FORMAT, 0, $formats[0]]] as [$kind, $pos, $row]) {
    $decoded = RuleChoice::decode(RuleChoice::encode($kind, $pos, $row));
    $check("$kind: encode → decode round-trips", $decoded === [
        'all'         => false,
        'kind'        => $kind,
        'position'    => $pos,
        'fingerprint' => RuleChoice::fingerprint($row),
    ]);
}

$check('the "All enabled rules" sentinel round-trips',
    RuleChoice::decode(RuleChoice::ALL_ENABLED) === ['all' => true]);

$fp = RuleChoice::fingerprint($first[0]);
foreach ([
    'empty'              => '',
    'unknown kind'       => "hierarchical_rules:0:$fp",
    'negative position'  => "$KIND_TERM:-1:$fp",
    'non-numeric pos'    => "$KIND_TERM:x:$fp",
    'bad fingerprint'    => "$KIND_TERM:0:nothex",
    'missing part'       => "$KIND_TERM:0",
    'trailing garbage'   => "$KIND_TERM:0:$fp:x",
] as $label => $value) {
    $check("malformed value decodes to null ($label)", RuleChoice::decode($value) === null);
}

// --- 3. Stale check. --------------------------------------------------------

$choice = RuleChoice::decode(RuleChoice::encode($KIND_TERM, 1, $first[1]));

$check('an unchanged kind list resolves the chosen row',
    RuleChoice::resolve($choice, $read($KIND_TERM, $option)) === $first[1]);
$check('an edited row is stale',
    RuleChoice::resolve($choice, $read($KIND_TERM, $edited)) === null);
$check('a toggled row is stale',
    RuleChoice::resolve($choice, $read($KIND_TERM, $toggled)) === null);

// Moved one slot down: the row still exists, at position 2. Resolving it there
// would run the rule the author chose — but only by guessing, and the same
// guess resolves a DIFFERENT rule the next time two rows are identical.
$reordered = $option;
$reordered[$KIND_TERM] = [$term_rows[0], $term_rows[2], $term_rows[1]];
$check('a re-ordered row is stale, not followed to its new position',
    RuleChoice::resolve($choice, $read($KIND_TERM, $reordered)) === null);

$deleted = $option;
$deleted[$KIND_TERM] = [$term_rows[0]];
$check('a deleted row is stale',
    RuleChoice::resolve($choice, $read($KIND_TERM, $deleted)) === null);

// Deleting row 0 slides R1 into position 0 and H2 into position 1: the
// position is still valid, the row there is a different rule.
$shifted = $option;
$shifted[$KIND_TERM] = [$term_rows[1], $term_rows[2]];
$check('a neighbour sliding into the position is stale',
    RuleChoice::resolve($choice, $read($KIND_TERM, $shifted)) === null);

$check('the sentinel resolves no single row',
    RuleChoice::resolve(['all' => true], $first) === null);

// --- 4. Reach → statuses. ---------------------------------------------------

$default = ['publish', 'draft', 'private', 'future'];

$check('All enabled rules gets the default set', RuleChoice::reach_statuses(null) === $default);
$check('no default status is trash or auto-draft',
    array_intersect($default, ['trash', 'auto-draft']) === []);

$h = ['type' => 'hierarchical_rules', 'taxonomy' => 'category'];
$check('no post_status → default set', RuleChoice::reach_statuses($h) === $default);
$check('an empty checkbox map → default set',
    RuleChoice::reach_statuses($h + ['post_status' => ['publish' => false]]) === $default);
$check("'any' → default set",
    RuleChoice::reach_statuses($h + ['post_status' => ['any']]) === $default);
$check('a checkbox map narrows the set',
    RuleChoice::reach_statuses($h + ['post_status' => ['publish' => true, 'draft' => false]]) === ['publish']);
$check('a list narrows the set',
    RuleChoice::reach_statuses($h + ['post_status' => ['draft', 'pending']]) === ['draft', 'pending']);
$check('trash and auto-draft are dropped from a narrowed set',
    RuleChoice::reach_statuses($h + ['post_status' => ['trash' => true, 'auto-draft' => true, 'private' => true]]) === ['private']);
$check('a gate of only trash reaches nothing, never the default set',
    RuleChoice::reach_statuses($h + ['post_status' => ['trash' => true]]) === []);

foreach (['propagation_rules', 'related_rules', 'time_based_rules', 'hierarchical_level_restriction_rules'] as $type) {
    $check("$type narrows by post_status",
        RuleChoice::reach_statuses(['type' => $type, 'post_status' => ['publish' => true]]) === ['publish']);
}

$check('related_post_terms never narrows — post_status gates the source there',
    RuleChoice::reach_statuses(['type' => 'related_post_terms_rules', 'post_status' => ['publish' => true]]) === $default);

// --- 5. Disabled-row override (dispatcher pass rows). -----------------------

$names = fn(array $rows) => array_column($rows, 'row_title');

$over = $option;
$over[$KIND_TERM][] = ['type' => 'hierarchical_rules', 'row_title' => 'H3', 'taxonomy' => 'post_tag', 'enabled' => false];
$today = $read($KIND_TERM, $over, ['enabled' => true]);
$all   = $read($KIND_TERM, $over);

$check('no override equals today\'s enabled filter',
    RuleChoice::pass_rows($all, null) === $today);
$check('an included fingerprint adds exactly that row, in authored order',
    $names(RuleChoice::pass_rows($all, RuleChoice::fingerprint($all[1]))) === ['H1', 'R1', 'H2']);
$check('a row included at the end stays at the end',
    $names(RuleChoice::pass_rows($all, RuleChoice::fingerprint($all[3]))) === ['H1', 'H2', 'H3']);
$check('a fingerprint that matches nothing adds nothing',
    RuleChoice::pass_rows($all, str_repeat('0', 32)) === $today);
$check('including an enabled row does not duplicate it',
    RuleChoice::pass_rows($all, RuleChoice::fingerprint($all[0])) === $today);

// --- 6. Apply page dropdown. ------------------------------------------------

$titled = $option;
$titled[$KIND_TERM][1]['row_title']   = '#2 [Disabled] Tags &amp; more';
$titled[$KIND_FORMAT][0]['row_title'] = '#1 T1 (Posts)';
$terms   = $read($KIND_TERM, $titled);
$formats = $read($KIND_FORMAT, $titled);
$options = ApplyPage::choice_options($terms, $formats);
$values  = array_keys($options);

$check('options: placeholder, All enabled rules, term rows, then format rows',
    $values === [
        '',
        RuleChoice::ALL_ENABLED,
        RuleChoice::encode($KIND_TERM, 0, $terms[0]),
        RuleChoice::encode($KIND_TERM, 1, $terms[1]),
        RuleChoice::encode($KIND_TERM, 2, $terms[2]),
        RuleChoice::encode($KIND_FORMAT, 0, $formats[0]),
    ]);
$check('every row value decodes back to its own row',
    RuleChoice::resolve(RuleChoice::decode($values[3]), $terms) === $terms[1]);
$check('a disabled row is prefixed (disabled), its stored marker dropped and entities decoded',
    $options[$values[3]] === 'Term rules: #2 (disabled) Tags & more');
$check('an enabled row carries its kind and position',
    $options[$values[2]] === 'Term rules: #1 H1'
    && $options[$values[5]] === 'Format rules: #1 T1 (Posts)');
$check('a stale stored position is replaced by the real one',
    ApplyPage::choice_options([], [['type' => 'title_slug_rules', 'row_title' => '#9 X']] )[RuleChoice::encode($KIND_FORMAT, 0, ['type' => 'title_slug_rules', 'row_title' => '#9 X'])]
    === 'Format rules: #1 X');
$check('an untitled row is labeled by its type',
    in_array('Format rules: #1 title_slug_rules', ApplyPage::choice_options([], [['type' => 'title_slug_rules']]), true));

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nAPPLY-EXISTING FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $name) {
        fwrite(STDERR, "  ✗ $name\n");
    }
    exit(1);
}

fwrite(STDOUT, "APPLY-EXISTING OK — all $total assertions passed (fingerprint, codec, stale check, reach statuses, disabled-row override, Apply page dropdown; FW-16).\n");
