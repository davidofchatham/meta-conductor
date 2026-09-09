<?php
/**
 * H14 — Collision detector harness (0.8.0, #65).
 *
 * The detector's failure mode is a warning that is WRONG rather than absent:
 * an advisory that fires on independent rules gets ignored, and an advisory
 * that misses the pair it exists for (#51's contradictory pair, #69's mutual
 * annihilation) is worse than none because the author now believes the plugin
 * checked. Neither shows up in a lint or a diff, so both directions are
 * asserted here — every pair that must warn, and every near-miss that must not.
 *
 * What this pins:
 *   1. The three target-resolution lists PARTITION every rule type storage
 *      knows. A type added without being filed is silently skipped by the
 *      detector, which is exactly the class of miss this harness exists for.
 *   2. Target keying: taxonomy for the four types that declare one, the TERM
 *      for the two term-pairing types that do not (the #65 comment's gap).
 *   3. The written post types, including the two types that answer with
 *      something other than `post_types`.
 *   4. The named contradictions — #51's ancestors pair, #69's cancelling pair,
 *      the format kind's first-match-wins — and the generic fallback.
 *   5. The advisory's boundaries: disabled rows, half-authored rows, disjoint
 *      post types, and the date window NOT being a disjointness signal.
 *
 * Run:  php tests/verify-collision-detector.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

// --- WP shim (only what the detector touches). ------------------------------

if (!function_exists('__'))         { function __($t, $d = 'default') { return $t; } }
if (!function_exists('_n'))         { function _n($s, $p, $n, $d = 'default') { return $n === 1 ? $s : $p; } }
if (!function_exists('esc_html'))   { function esc_html($t) { return $t; } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = 'default') { return $t; } }
// Recording, not no-op: the last group asserts WHICH hooks the detector
// registers, and a silent stub would make that assertion vacuous.
$GLOBALS['mc_hooks'] = [];
if (!function_exists('add_action')) { function add_action($h, $cb = null, $p = 10, $n = 1) { $GLOBALS['mc_hooks'][] = $h; } }
if (!function_exists('add_filter')) { function add_filter($h, $cb = null, $p = 10, $n = 1) { $GLOBALS['mc_hooks'][] = $h; } }
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return false; } }
if (!function_exists('get_option'))    { function get_option($n, $d = false) { return $d; } }
if (!function_exists('update_option')) { function update_option($n, $v, $a = null) { return true; } }
// The REAL inverse of esc_html. `row_title` is stored already escaped (the
// repeater's title_template renders raw), so the detector has to decode it —
// this must not be stubbed to identity or the assertion below proves nothing.
if (!function_exists('wp_specialchars_decode')) {
    function wp_specialchars_decode($t, $q = ENT_NOQUOTES) { return html_entity_decode((string) $t, $q, 'UTF-8'); }
}

// A three-level tree in one taxonomy plus a term in a second, so a term-keyed
// pair and a taxonomy-keyed pair can be told apart by their labels.
if (!function_exists('get_term')) {
    function get_term($id) {
        $terms = [
            21 => ['term_id' => 21, 'name' => 'Archived', 'taxonomy' => 'mc_topic'],
            22 => ['term_id' => 22, 'name' => 'Featured', 'taxonomy' => 'mc_topic'],
            31 => ['term_id' => 31, 'name' => 'Priority', 'taxonomy' => 'mc_flag'],
        ];
        return isset($terms[$id]) ? (object) $terms[$id] : null;
    }
}
if (!function_exists('get_taxonomy')) {
    function get_taxonomy($slug) {
        $map = [
            'mc_topic' => (object) ['name' => 'mc_topic', 'label' => 'Topics'],
            'mc_flag'  => (object) ['name' => 'mc_flag',  'label' => 'Flags'],
        ];
        return $map[$slug] ?? false;
    }
}
if (!function_exists('get_post_type_object')) {
    function get_post_type_object($slug) {
        $map = [
            'mc_item'    => (object) ['name' => 'mc_item',    'label' => 'MC Items'],
            'mc_section' => (object) ['name' => 'mc_section', 'label' => 'MC Sections'],
        ];
        return $map[$slug] ?? null;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Admin\CollisionDetector;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

$KIND_TERM   = OptionRuleStorage::KIND_TERM;
$KIND_FORMAT = OptionRuleStorage::KIND_FORMAT;

/** Run the detector the way both surfaces do: project, then scan. */
$scan = function (string $kind, array $rows) {
    return CollisionDetector::detect($kind, OptionRuleStorage::project_kind_rules($rows));
};
/** The `code` of each finding, in order. */
$codes = fn(array $found) => array_map(fn($f) => $f['code'], $found);
/** Each finding as the ordered pair of list positions it names. */
$pairs = fn(array $found) => array_map(fn($f) => [$f['a']['index'], $f['b']['index']], $found);

// --- 1. Every rule type is filed under exactly one target scheme. -----------
//
// A type storage knows but the detector does not resolve is skipped in silence
// — the advisory would simply never mention it. Reflection reads the three
// private lists rather than restating them, so this fails when a type is added
// to storage and nowhere else, not when this file falls behind.

$reflected = new ReflectionClass(CollisionDetector::class);
$filed     = array_merge(
    $reflected->getConstant('TAXONOMY_TARGET_TYPES'),
    $reflected->getConstant('TERM_TARGET_TYPES'),
    array_keys($reflected->getConstant('FIELD_TARGET_TYPES'))
);
$known = array_merge(
    OptionRuleStorage::migrated_types_for_kind($KIND_TERM),
    OptionRuleStorage::migrated_types_for_kind($KIND_FORMAT)
);

sort($filed);
$sorted_known = $known;
sort($sorted_known);

$check('every rule type resolves to a target scheme', $filed === $sorted_known);
$check('no type is filed under two schemes', count($filed) === count(array_unique($filed)));

// --- 2. Target keys. --------------------------------------------------------

$check('a taxonomy-declaring type keys on its taxonomy',
    CollisionDetector::target_key('hierarchical_rules', ['taxonomy' => 'mc_topic']) === 'taxonomy|mc_topic');
$check('the ACF-reference type keys on its taxonomy too',
    CollisionDetector::target_key('related_post_terms_rules', ['taxonomy' => 'mc_topic']) === 'taxonomy|mc_topic');

// The gap the #65 comment names: neither term-pairing type HAS a taxonomy
// subfield, so keying on `taxonomy` would resolve nothing for either.
$check('a date-window rule keys on its target TERM, not a taxonomy',
    CollisionDetector::target_key('time_based_rules', ['target_term_id' => 21]) === 'term|21');
$check('a related-term rule keys on its target term',
    CollisionDetector::target_key('related_rules', ['target_term_id' => 22]) === 'term|22');
$check('the FormTokenField [N] shape resolves to the same key',
    CollisionDetector::target_key('related_rules', ['target_term_id' => [22]]) === 'term|22');

$check('a format rule keys on the fields it writes',
    CollisionDetector::target_key('title_slug_rules', ['post_type' => 'mc_item']) === 'fields|title_slug');

$check('a row with no taxonomy picked resolves nothing',
    CollisionDetector::target_key('hierarchical_rules', []) === null);
$check('a row with no target term picked resolves nothing',
    CollisionDetector::target_key('time_based_rules', ['target_term_id' => 0]) === null);
$check('an untyped row resolves nothing',
    CollisionDetector::target_key('', ['taxonomy' => 'mc_topic']) === null);

// --- 3. Written post types. -------------------------------------------------

$check('post_types is read through the checkbox extractor',
    CollisionDetector::written_post_types('hierarchical_rules', ['post_types' => ['mc_item' => true, 'mc_section' => false]])
    === ['mc_item']);
$check('empty post_types means every post type',
    CollisionDetector::written_post_types('hierarchical_rules', []) === []);
$check('the `any` sentinel means every post type, not a type called any',
    CollisionDetector::written_post_types('hierarchical_rules', ['post_types' => ['any']]) === []);
$check('a format rule reads its scalar post_type',
    CollisionDetector::written_post_types('title_slug_rules', ['post_type' => 'mc_item']) === ['mc_item']);

// The ACF-reference rule writes its DEPENDENT end, and a push rule does not
// constrain that end's type — so "all", exactly as dependent_post_type() reads.
$check('a push ACF-reference rule writes every post type',
    CollisionDetector::written_post_types('related_post_terms_rules',
        ['post_type' => 'mc_section', 'holder_role' => 'source']) === []);
$check('a pull ACF-reference rule writes the holder type',
    CollisionDetector::written_post_types('related_post_terms_rules',
        ['post_type' => 'mc_section', 'holder_role' => 'target']) === ['mc_section']);

$check('empty on either side overlaps',
    CollisionDetector::post_types_overlap([], ['mc_item'])
    && CollisionDetector::post_types_overlap(['mc_item'], []));
$check('disjoint post types do not overlap',
    !CollisionDetector::post_types_overlap(['mc_item'], ['mc_section']));

// --- 4. #51 — the contradictory pair, named. --------------------------------
//
// child_to_parent hierarchy (now `ancestors`) ADDS ancestor terms;
// one_per_level level restriction with include_ancestors off does not keep
// them. One taxonomy, overlapping post types.

$s51 = [
    ['type' => 'hierarchical_rules', 'enabled' => true, 'taxonomy' => 'mc_topic',
     'post_types' => ['mc_item'], 'inheritance_behavior' => 'ancestors',
     'row_title' => 'Inherit Topics'],
    ['type' => 'hierarchical_level_restriction_rules', 'enabled' => true, 'taxonomy' => 'mc_topic',
     'post_types' => ['mc_item'], 'restriction_mode' => 'one_per_level',
     'include_ancestors' => false, 'row_title' => 'One per level'],
];
$found = $scan($KIND_TERM, $s51);
$check('#51: the contradictory pair is flagged', count($found) === 1);
$check('#51: and the contradiction is NAMED, not generic',
    $codes($found) === ['ancestors_stripped']);
$check('#51: the warning names both rules',
    ($found[0]['a']['title'] ?? '') === 'Inherit Topics'
    && ($found[0]['b']['title'] ?? '') === 'One per level');
$check('#51: and names the shared taxonomy by label', ($found[0]['target'] ?? '') === 'Topics');
$check('#51: and the overlapping post types', ($found[0]['scope'] ?? '') === 'MC Items');

$message = CollisionDetector::message($found[0]);
$check('#51: the message states the contradiction',
    str_contains($message, 'adds ancestor terms') && str_contains($message, 'does not keep them'));
$check('#51: and says the result depends on list order',
    str_contains($message, 'lower in the list acts last'));

// The diagnosis is a property of the CONFIGURATION, not of the pair of types:
// turn the ancestors back on and the pair still contends, but not over that.
$s51_kept = $s51;
$s51_kept[1]['include_ancestors'] = true;
$check('#51: keeping ancestors leaves a collision but not that contradiction',
    $codes($scan($KIND_TERM, $s51_kept)) === ['shared_target']);

// Deepest-only strips ancestors outright, which is the same contradiction.
$s51_deepest = $s51;
$s51_deepest[1]['restriction_mode'] = 'deepest_only';
$check('#51: deepest-only strips ancestors too',
    $codes($scan($KIND_TERM, $s51_deepest)) === ['ancestors_stripped']);

// Shallowest-only prunes the deep end, so ancestors survive — but descendants
// do not, and the mirror-image hierarchy rule contradicts it.
$s51_desc = $s51;
$s51_desc[0]['inheritance_behavior'] = 'descendants_always';
$s51_desc[1]['restriction_mode']     = 'shallowest_only';
$check('#51: the mirror image is named too',
    $codes($scan($KIND_TERM, $s51_desc)) === ['descendants_stripped']);
// …and it is role-ordered too. The two templates are separate `case`s, so the
// ancestors one being right says nothing about this one.
$found_desc_swapped = $scan($KIND_TERM, [$s51_desc[1], $s51_desc[0]]);
$check('#51: the descendants message names the adder first as well',
    str_starts_with(
        CollisionDetector::message($found_desc_swapped[0]),
        '“Inherit Topics” adds descendant terms'
    ));

// The templates are ASYMMETRIC — "%1$s adds … %3$s does not keep them" — so the
// pair must be ordered by ROLE, not by list position. Author the restriction
// rule ABOVE the hierarchy rule and the sentence must not invert.
$s51_swapped = [$s51[1], $s51[0]];
$found_swapped = $scan($KIND_TERM, $s51_swapped);
$check('#51: the contradiction survives the rules being authored the other way',
    $codes($found_swapped) === ['ancestors_stripped']);
$check('#51: and the rule that ADDS leads the sentence, whatever its position',
    ($found_swapped[0]['a']['type'] ?? '') === 'hierarchical_rules'
    && ($found_swapped[0]['b']['type'] ?? '') === 'hierarchical_level_restriction_rules');
$check('#51: while the positions still report the authored order',
    [$found_swapped[0]['a']['index'], $found_swapped[0]['b']['index']] === [1, 0]);
$check('#51: so the message names the adder first',
    str_starts_with(
        CollisionDetector::message($found_swapped[0]),
        '“Inherit Topics” adds ancestor terms'
    ));

// The snapshot row title now LEADS with the row's list position, so the
// sentence must not append a second one — "“#6 Rule” (#6)" was the bug, and
// "Rule 6" (#6) for a row with no title yet. A title-less row is named by the
// bare position, which is what the repeater renders for it too.
$s_untitled = $s51;
unset($s_untitled[0]['row_title'], $s_untitled[1]['row_title']);
$m_untitled = CollisionDetector::message($scan($KIND_TERM, $s_untitled)[0]);
$check('an untitled row is named by its list position', str_contains($m_untitled, '“#1”'));
$check('and no position is ever appended a second time',
    !str_contains($m_untitled, '(#')
    && !str_contains(CollisionDetector::message($found_swapped[0]), '(#'));

// A legacy row carrying only the pre-#16 pair must not be read as the
// `ancestors` default — that would name the wrong contradiction. Resolved
// through HierarchicalHandler::behavior_key(), the one reading of that pair.
$s51_legacy = $s51;
unset($s51_legacy[0]['inheritance_behavior']);
$s51_legacy[0]['hierarchy_direction'] = 'parent_to_child';
$s51_legacy[0]['expansion_behavior']  = 'always';
$s51_legacy[1]['restriction_mode']    = 'shallowest_only';
$check('#51: a legacy hierarchy row is read as what it was',
    $codes($scan($KIND_TERM, $s51_legacy)) === ['descendants_stripped']);

// A legacy `both` row adds descendants TOO — the re-derived switch this
// delegates away from read it as ancestors-only and missed the diagnosis.
$s51_both = $s51_legacy;
$s51_both[0]['hierarchy_direction'] = 'both';
$check('#51: a legacy `both` row is diagnosed on its descendants as well',
    $codes($scan($KIND_TERM, $s51_both)) === ['descendants_stripped']);

// The degenerate legacy pair applies NOTHING, so it can contradict nothing —
// it still collides, but there is no contradiction to name.
$s51_degenerate = $s51_legacy;
$s51_degenerate[0]['expansion_behavior'] = 'never';
$check('#51: a hierarchy rule that applies nothing names no contradiction',
    $codes($scan($KIND_TERM, $s51_degenerate)) === ['shared_target']);

// Disjoint post types make the same two rules independent — the AC's clear.
$s51_disjoint = $s51;
$s51_disjoint[1]['post_types'] = ['mc_section'];
$check('#51: disjoint post types clear the warning', $scan($KIND_TERM, $s51_disjoint) === []);

// So does a disjoint taxonomy.
$s51_other_tax = $s51;
$s51_other_tax[1]['taxonomy'] = 'mc_flag';
$check('#51: a disjoint taxonomy clears the warning', $scan($KIND_TERM, $s51_other_tax) === []);

// --- 5. #39 — propagation vs level restriction, one taxonomy. ---------------

$s39 = [
    ['type' => 'propagation_rules', 'enabled' => true, 'taxonomy' => 'mc_topic',
     'post_types' => ['mc_section'], 'conflict_handling' => 'merge', 'row_title' => 'Cascade Topics'],
    ['type' => 'hierarchical_level_restriction_rules', 'enabled' => true, 'taxonomy' => 'mc_topic',
     'post_types' => ['mc_section'], 'restriction_mode' => 'one_per_level', 'row_title' => 'One per level'],
];
$found39 = $scan($KIND_TERM, $s39);
$check('#39: the propagation/restriction pair warns', count($found39) === 1);
$check('#39: as a generic shared-target collision', $codes($found39) === ['shared_target']);
$check('#39: naming both rules and the taxonomy',
    str_contains(CollisionDetector::message($found39[0]), 'Cascade Topics')
    && str_contains(CollisionDetector::message($found39[0]), 'One per level')
    && str_contains(CollisionDetector::message($found39[0]), 'Topics'));

$s39_disjoint = $s39;
$s39_disjoint[1]['post_types'] = ['mc_item'];
$check('#39: moving one to a disjoint post type clears it',
    $scan($KIND_TERM, $s39_disjoint) === []);

// --- 6. #69 — two date windows on one term cancel. --------------------------
//
// The mc-rules fixture's ready-made case: time_based[1] (expired) and [2]
// (future) both target topic-archived, both scoped to mc_item.

$s69 = [
    ['type' => 'time_based_rules', 'enabled' => true, 'post_types' => ['mc_item'],
     'start_date' => '2020-01-01', 'end_date' => '2020-12-31', 'target_term_id' => 21,
     'row_title' => 'Archive window (expired)'],
    ['type' => 'time_based_rules', 'enabled' => true, 'post_types' => ['mc_item'],
     'start_date' => '2099-01-01', 'end_date' => '2099-12-31', 'target_term_id' => 21,
     'row_title' => 'Archive window (future)'],
];
$found69 = $scan($KIND_TERM, $s69);
$check('#69: two date windows over one term warn', count($found69) === 1);
$check('#69: and the mutual-annihilation case is named',
    $codes($found69) === ['shared_term_cancels']);
$check('#69: the warning names the shared TERM, not just its taxonomy',
    ($found69[0]['target'] ?? '') === 'Topics: Archived');
$check('#69: the message says either rule removes what the other applied',
    str_contains(CollisionDetector::message($found69[0]), 'whoever applied it'));

// A collision is about contention, not about whether both are active now.
$s69_slid = $s69;
$s69_slid[0]['start_date'] = '2098-01-01';
$s69_slid[0]['end_date']   = '2098-12-31';
$check('#69: changing only the date window does NOT clear it',
    $codes($scan($KIND_TERM, $s69_slid)) === ['shared_term_cancels']);

// Changing the target term does.
$s69_retarget = $s69;
$s69_retarget[1]['target_term_id'] = 22;
$check('#69: changing one rule\'s target term clears it',
    $scan($KIND_TERM, $s69_retarget) === []);

// Two date windows in one taxonomy but on different terms must NOT warn — the
// whole reason the term-pairing types key on the term (an advisory that fires
// on every pair of date rules in a taxonomy gets ignored).
$check('#69: two date rules on different terms in one taxonomy are independent',
    $scan($KIND_TERM, $s69_retarget) === []);

// --- 7. related_rules: only a bidirectional rule can cancel. ----------------

$s_related = [
    ['type' => 'related_rules', 'enabled' => true, 'post_types' => ['mc_item'],
     'target_term_id' => 22, 'bidirectional' => false, 'row_title' => 'Coastal ⇒ Featured'],
    ['type' => 'related_rules', 'enabled' => true, 'post_types' => ['mc_item'],
     'target_term_id' => 22, 'bidirectional' => false, 'row_title' => 'Any flag ⇒ Featured'],
];
$check('two add-only related rules collide but cannot cancel',
    $codes($scan($KIND_TERM, $s_related)) === ['shared_target']);

$s_related_bidi = $s_related;
$s_related_bidi[0]['bidirectional'] = true;
$s_related_bidi[1]['bidirectional'] = true;
$check('two bidirectional related rules can cancel',
    $codes($scan($KIND_TERM, $s_related_bidi)) === ['shared_term_cancels']);

// A date rule and a related rule over one term is a cross-type pair, and the
// date rule always removes.
$s_cross = [
    $s69[0],
    ['type' => 'related_rules', 'enabled' => true, 'post_types' => ['mc_item'],
     'target_term_id' => 21, 'bidirectional' => true, 'row_title' => 'Trigger ⇒ Archived'],
];
$check('a date rule and a bidirectional related rule over one term cancel',
    $codes($scan($KIND_TERM, $s_cross)) === ['shared_term_cancels']);

// --- 8. The deferred conjunct, stated as a non-assertion. -------------------
//
// A taxonomy-wide rule is NOT paired with a term rule whose term lives in that
// taxonomy. That pairing is real, and it is the reach/component detector's —
// it needs ADR 0004's jurisdiction conjunct to avoid warning on every pair.
// Asserted so the boundary is a decision on the record rather than an
// oversight someone "fixes" without reading the ADR.

$s_nested = [
    ['type' => 'hierarchical_level_restriction_rules', 'enabled' => true, 'taxonomy' => 'mc_topic',
     'post_types' => ['mc_item'], 'restriction_mode' => 'one_per_level', 'row_title' => 'One per level'],
    ['type' => 'time_based_rules', 'enabled' => true, 'post_types' => ['mc_item'],
     'target_term_id' => 21, 'row_title' => 'Archive window'],
];
$check('a taxonomy-wide rule is not paired with a term rule inside it (deferred)',
    $scan($KIND_TERM, $s_nested) === []);

// --- 9. Boundaries: disabled, half-authored, and pair enumeration. ----------

$s_disabled = $s51;
$s_disabled[1]['enabled'] = false;
$check('a disabled rule contends with nothing', $scan($KIND_TERM, $s_disabled) === []);

$s_missing_enabled = $s51;
unset($s_missing_enabled[0]['enabled'], $s_missing_enabled[1]['enabled']);
$check('a row with no `enabled` key counts as enabled',
    count($scan($KIND_TERM, $s_missing_enabled)) === 1);

$s_unfinished = $s51;
$s_unfinished[1]['taxonomy'] = '';
$check('a half-authored row is unfinished, not colliding',
    $scan($KIND_TERM, $s_unfinished) === []);

// Three rules over one target are three pairs, each named by list POSITION —
// the positions are what the author reorders, so they must survive a row that
// took no part in the scan.
$s_three = [
    ['type' => 'time_based_rules', 'enabled' => false, 'post_types' => ['mc_item'],
     'target_term_id' => 21, 'row_title' => 'off'],
    $s69[0],
    $s69[1],
    ['type' => 'time_based_rules', 'enabled' => true, 'post_types' => ['mc_item'],
     'target_term_id' => 21, 'row_title' => 'third'],
];
$check('every contending pair is reported once',
    $pairs($scan($KIND_TERM, $s_three)) === [[1, 2], [1, 3], [2, 3]]);
$check('positions are list positions, not scan positions',
    ($scan($KIND_TERM, $s_three)[0]['a']['index'] ?? null) === 1);

// A rule with no post-type scope reaches every post type, so it contends with
// a scoped one — over-report, never under-report.
$s_unscoped = $s51;
$s_unscoped[0]['post_types'] = [];
$check('an unscoped rule contends with a scoped one',
    count($scan($KIND_TERM, $s_unscoped)) === 1);
$check('and the shared scope reads as the scoped side',
    ($scan($KIND_TERM, $s_unscoped)[0]['scope'] ?? '') === 'MC Items');

$s_both_unscoped = $s_unscoped;
$s_both_unscoped[1]['post_types'] = [];
$check('two unscoped rules report every post type',
    ($scan($KIND_TERM, $s_both_unscoped)[0]['scope'] ?? null) === '');
$check('and the message says so rather than leaving a hole',
    str_contains(CollisionDetector::message($scan($KIND_TERM, $s_both_unscoped)[0]), 'every post type'));

// An unresolvable post type falls back to its SLUG. Dropping it would empty the
// scope, and an empty scope renders as "every post type" — turning the
// narrowest rule pair into the broadest possible claim.
$s_unknown_pt = $s51;
$s_unknown_pt[0]['post_types'] = ['mc_unregistered'];
$s_unknown_pt[1]['post_types'] = ['mc_unregistered'];
$check('an unregistered post type is named by slug, not dropped',
    ($scan($KIND_TERM, $s_unknown_pt)[0]['scope'] ?? null) === 'mc_unregistered');

// `row_title` is stored ALREADY escaped, and `message()` is a plain-text API
// its one HTML caller escapes. Carrying the stored form through would put a
// rule called "Q&A" on the page as "Q&amp;A".
$s_entity = $s51;
$s_entity[0]['row_title'] = 'Q&amp;A Topics';
$check('a snapshot title is decoded, so the page escapes it exactly once',
    str_contains(CollisionDetector::message($scan($KIND_TERM, $s_entity)[0]), '“Q&A Topics”'));

// --- 10. The format kind: first-match-wins, stated as a collision. ----------

$s_format = [
    ['type' => 'title_slug_rules', 'enabled' => true, 'post_type' => 'mc_item',
     'row_title' => 'MC item slug'],
    ['type' => 'title_slug_rules', 'enabled' => true, 'post_type' => 'mc_item',
     'row_title' => 'MC item slug (second)'],
];
$found_fmt = $scan($KIND_FORMAT, $s_format);
$check('two format rules on one post type warn', count($found_fmt) === 1);
$check('and the first-match-wins consequence is named',
    $codes($found_fmt) === ['first_match_wins']);
$check('the message says the lower rule never runs',
    str_contains(CollisionDetector::message($found_fmt[0]), 'never does'));

$s_format_split = $s_format;
$s_format_split[1]['post_type'] = 'mc_section';
$check('two format rules on different post types are independent',
    $scan($KIND_FORMAT, $s_format_split) === []);

// A blank new row writes nothing — `TitleSlugHandler::rule_matches()` requires
// a non-empty post_type — so it is unfinished, not colliding. The format target
// key is a constant, so nothing else in the scan would have caught this.
$check('a format rule with no post type resolves no target',
    CollisionDetector::target_key('title_slug_rules', ['post_type' => '']) === null);
$s_format_blank = $s_format;
$s_format_blank[1]['post_type'] = '';
$check('a blank format row collides with nothing',
    $scan($KIND_FORMAT, $s_format_blank) === []);

// --- 11. The section both tabs render. --------------------------------------

$section = CollisionDetector::section($KIND_TERM);
$field_ids = array_map(fn($f) => $f['id'], $section['fields']);
$check('the section id is derived from the kind', $section['id'] === $KIND_TERM . '_collisions');
$check('with nothing stored, only the re-check button renders',
    $field_ids === [$KIND_TERM . '_collision_check']);
$check('the re-check control is an ActionField',
    $section['fields'][0]['type'] === 'action');
$check('in single-button sugar mode (no buttons[] ⇒ action id `run`)',
    !isset($section['fields'][0]['args']['buttons']));
$check('the format kind gets its own section from the same builder',
    CollisionDetector::section($KIND_FORMAT)['fields'][0]['id'] === $KIND_FORMAT . '_collision_check');

// Reset wipes the rules the stored findings name, so it has to recompute like a
// save does — otherwise the notice describes rules that no longer exist.
$GLOBALS['mc_hooks'] = [];
CollisionDetector::init();
$hooked = $GLOBALS['mc_hooks'];
$check('the passive surface listens on save AND on reset',
    in_array('bws-meta-conductor/settings_saved', $hooked, true)
    && in_array('bws-meta-conductor/settings_reset', $hooked, true));
$check('and both kinds get an action route',
    in_array('bws-meta-conductor/action/settings/term_rules_collision_check/run', $hooked, true)
    && in_array('bws-meta-conductor/action/settings/format_rules_collision_check/run', $hooked, true));

// --- 12. The empty and degenerate lists. ------------------------------------

$check('an empty list has no collisions', $scan($KIND_TERM, []) === []);
$check('a single rule collides with nothing', $scan($KIND_TERM, [$s51[0]]) === []);
$check('a non-array row is skipped rather than fatal',
    CollisionDetector::detect($KIND_TERM, ['nonsense']) === []);

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nCOLLISION-DETECTOR FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $name) {
        fwrite(STDERR, "  ✗ $name\n");
    }
    exit(1);
}

fwrite(STDOUT, "COLLISION-DETECTOR OK — all $total assertions passed (targets, scopes, named contradictions, boundaries; #65).\n");
