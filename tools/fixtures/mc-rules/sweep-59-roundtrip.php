<?php
/**
 * #59 dynamic sweep — title/slug through the ordered format repeater.
 *
 * The dynamic half of H12 for the #59 batch, run against the real testbed.
 * Same two acts as sweep-58-roundtrip.php, on the other kind list:
 *
 *   1. Simulates the admin-load storage sequence (stored-rule repair) the
 *      way WireframeBootstrap::boot runs it, then asserts the
 *      persisted `format_rules` list carries the seeded title/slug rule in
 *      admin-renderable shape — its `type`, a backfilled `row_title`, and
 *      every stored key it had before the move.
 *
 *   2. Runs every persisted row through Wireframe's REAL
 *      RepeaterField::sanitize against the live FormatRulesConfig subfields —
 *      the exact code path a settings save takes — and asserts no value
 *      TitleSlugFromPatternHandler reads is dropped or altered. This is #59's "existing
 *      stored title/slug rules survive the move with their patterns and slug
 *      mode intact" criterion, checked against the real sanitizer rather than
 *      reasoned about.
 *
 * Run: wp eval-file <mount>/tools/fixtures/mc-rules/sweep-59-roundtrip.php --allow-root
 * Prereq: mc-rules seeded (seed.php). Read-only apart from the boot writes.
 */

use BWS\MetaConductor\Admin\Config\FormatRulesConfig;
use BWS\MetaConductor\Admin\Config\TermRulesConfig;
use BWS\MetaConductor\Admin\WireframeBootstrap;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;
use Wireframe\Framework\Fields\RepeaterField;

$fail = [];
$note = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . " $label\n";
    if (!$ok) {
        $fail[] = $label;
    }
};

// ── 1. The admin-load storage sequence. ─────────────────────────────────────

$storage = StorageFactory::get_instance();

// What the handler sees BEFORE any of this runs. The move must not change it.
$before = $storage->get_rules('title_slug_from_pattern');

$repair = new ReflectionMethod(WireframeBootstrap::class, 'repair_stored_rules');
$repair->invoke(null, $storage);

$settings = get_option(OptionRuleStorage::OPTION_NAME, []);
$rows     = $settings[OptionRuleStorage::KIND_FORMAT] ?? [];

$note('format_rules is populated', !empty($rows));
// The format list holds title/slug rules and, since FW-4, Computed field rules.
// (The field copy is authored in the Auto-Set list; section 3 covers it.)
$format_types = ['title_slug_from_pattern', 'field_from_pattern'];
$note('every format row is a known format rule type',
    array_diff(array_column($rows, 'type'), $format_types) === []);
$note('the legacy title_slug_from_pattern array is gone from storage (#66)',
    !array_key_exists('title_slug_from_pattern', $settings));

$titled = array_filter($rows, static fn($r) => ($r['row_title'] ?? '') !== '');
$note('every row carries a repaired row_title', count($titled) === count($rows));

foreach ($rows as $i => $r) {
    $note("row $i names a post type", !empty($r['post_type']) || !empty($r['post_types']));
    $note("row $i row_title carries the post-type scope",
        str_contains((string) ($r['row_title'] ?? ''), '('));
}

// The read path handlers use must be unchanged by the sync + repair — the
// repair adds row_title and nothing else.
$after = StorageFactory::get_instance();
$after->clear_cache();
$after_rules = $after->get_rules('title_slug_from_pattern');
$strip = static fn(array $rs) => array_map(
    static function (array $r) { unset($r['row_title']); return $r; },
    $rs
);
$note('what the handler reads is unchanged apart from row_title',
    $strip($before) == $strip($after_rules));

// ── 2. RepeaterField::sanitize round-trip — the real save path. ─────────────

$field     = current( array_filter( FormatRulesConfig::section()['fields'], static fn( $f ) => ( $f['id'] ?? '' ) === 'format_rules' ) ); // the token reference leads the section
$sanitized = RepeaterField::sanitize($rows, $field['args']);

$note('sanitize keeps every row', count($sanitized) === count($rows));

// Keys the format handlers READ — these must survive a save unchanged. The
// last three are the Computed field rule's, stored by ACF field KEY.
$must_survive = ['type', 'enabled', 'post_type', 'post_types', 'title_pattern', 'slug_pattern',
                 'slug_mode', 'slug_escalation', 'escalation_placement', 'name',
                 'target_field', 'pattern', 'output'];

foreach ($rows as $i => $row) {
    foreach ($must_survive as $key) {
        if (!array_key_exists($key, $row)) {
            continue; // absent pre-save stays absent-or-default; nothing to lose
        }
        $before_val = $row[$key];
        $after_val  = $sanitized[$i][$key] ?? '(DROPPED)';
        $same       = $before_val == $after_val; // loose: sanitize may cast '1'→true
        $note("row $i '$key' survives sanitize" . ($same ? '' : " [$after_val]"),
            $same && $after_val !== '(DROPPED)');
    }
}

// What the sanitize produced IS what gets stored (#66 — there is no projection
// onto title_slug_from_pattern any more), so the list has to survive it in place.
$note('the sanitized list keeps every row', count($sanitized) === count($rows));
$note('every sanitized row is still typed',
    array_column($sanitized, 'type') === array_column($rows, 'type'));

// ── 3. A Set a field from a related post row (FW-5 04). ─────────────────────
// The manifest baseline carries no row of this type (it would be live in every
// other sweep), so one is built here and run through the same sanitizer. Every
// key the handler reads must come back; a wrong gate drops one silently.
//
// The type is authored in the Auto-Set list, so the sanitizer runs over THAT
// list's repeater: `$field` is rebound to it for the rest of the sweep.

$field = current(array_filter(TermRulesConfig::section()['fields'], static fn($f) => ($f['id'] ?? '') === OptionRuleStorage::KIND_TERM));

$copy_row = [
    'type' => 'field_from_related_post', 'enabled' => true, 'name' => 'Copy probe',
    'post_types' => ['mc_section'], 'relationship' => 'parent', 'source_field' => 'field_mc_cp_text',
    'copy_target' => '', 'conflict_handling' => 'replace', 'post_status' => ['publish'], 'row_title' => 'x',
];
$copy_out = RepeaterField::sanitize([$copy_row], $field['args'])[0] ?? [];
foreach (['type', 'enabled', 'name', 'post_types', 'relationship', 'source_field', 'copy_target', 'conflict_handling', 'post_status'] as $key) {
    $note("copy row '$key' survives sanitize", ($copy_out[$key] ?? '(DROPPED)') == $copy_row[$key]);
}
// Plain meta on both ends (FW-5 06): the two names must survive while their select picks the meta choice.
$meta_row = array_merge($copy_row, ['source_field' => '@meta', 'source_meta' => 'mc_pm_src', 'copy_target' => '@meta', 'target_meta' => 'mc_pm_dst']);
$meta_out = RepeaterField::sanitize([$meta_row], $field['args'])[0] ?? [];
foreach (['source_field', 'source_meta', 'copy_target', 'target_meta'] as $key) {
    $note("meta copy row '$key' survives sanitize", ($meta_out[$key] ?? '(DROPPED)') == $meta_row[$key]);
}
// …and drop once the select moves back to an ACF field (the `when` gate).
$back_out = RepeaterField::sanitize([array_merge($meta_row, ['source_field' => 'field_mc_cp_text', 'copy_target' => 'field_mc_cp_display'])], $field['args'])[0] ?? [];
$note('a stale meta name is dropped once the select picks an ACF field',
    !array_key_exists('source_meta', $back_out) && !array_key_exists('target_meta', $back_out) && ($back_out['copy_target'] ?? '') === 'field_mc_cp_display');
// Group mode (FW-5 07): the group key rides in `source_field`; Copy into stays (ignored).
$group_row = array_merge($copy_row, ['source_field' => 'group_mc_copy_group', 'copy_target' => '']);
$group_out = RepeaterField::sanitize([$group_row], $field['args'])[0] ?? [];
$note("group copy row 'source_field' survives sanitize", ($group_out['source_field'] ?? '(DROPPED)') === 'group_mc_copy_group');
$note('copy row carries no pattern-rule or title/slug keys',
    array_intersect(['target_field', 'pattern', 'output', 'title_pattern', 'post_type'], array_keys($copy_out)) === []);

// The relationship block is ONE set of controls for both related-post types
// (merged by ConfigHelpers::type_subfields()). An acf row of each type, saved in
// the same list, must keep every relationship value: a second declaration of an
// id would be hidden whenever the first shows, and drop the value.
// The field selects drop a value that is not one of their options, so the values
// are a seeded acf row's own (a made-up one would fail for that reason alone).
$seeded_acf = current(array_filter(
    $settings[OptionRuleStorage::KIND_TERM] ?? [],
    static fn($r) => ($r['relationship'] ?? '') === 'acf' && !empty($r['acf_field_name']) && !empty($r['reverse_acf_field_name'])
));
$note('the seeded term list holds an acf row with both relationship fields to borrow values from', is_array($seeded_acf));
$acf_keys  = [
    'relationship'           => 'acf',
    'acf_field_name'         => (string) ($seeded_acf['acf_field_name'] ?? ''),
    'holder_role'            => 'target',
    'reverse_acf_field_name' => (string) ($seeded_acf['reverse_acf_field_name'] ?? ''),
];
$acf_copy  = array_merge($copy_row, $acf_keys);
$acf_terms = array_merge(['type' => 'terms_from_related_post', 'enabled' => true, 'taxonomy' => ['mc_topic'], 'conflict_handling' => 'merge', 'row_title' => 'y'], $acf_keys);
$both_out  = RepeaterField::sanitize([$acf_copy, $acf_terms], $field['args']);
foreach (['copy' => 0, 'terms' => 1] as $which => $i) {
    foreach (array_keys($acf_keys) as $key) {
        $note("acf $which row '$key' survives sanitize in a list holding both", ($both_out[$i][$key] ?? '(DROPPED)') === $acf_keys[$key]);
    }
}

echo $fail ? "\nSWEEP-59 FAIL: " . count($fail) . " failed\n" : "\nSWEEP-59 OK\n";
exit($fail ? 1 : 0);
