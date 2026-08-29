<?php
/**
 * #59 dynamic sweep — title/slug through the ordered format repeater.
 *
 * The dynamic half of H12 for the #59 batch, run against the real testbed.
 * Same two acts as sweep-58-roundtrip.php, on the other kind list:
 *
 *   1. Simulates the admin-load storage sequence (kind-list sync → stored-rule
 *      repair) the way WireframeBootstrap::boot runs it, then asserts the
 *      persisted `format_rules` list carries the seeded title/slug rule in
 *      admin-renderable shape — its `type`, a backfilled `row_title`, and
 *      every stored key it had before the move.
 *
 *   2. Runs every persisted row through Wireframe's REAL
 *      RepeaterField::sanitize against the live FormatRulesConfig subfields —
 *      the exact code path a settings save takes — and asserts no value
 *      TitleSlugHandler reads is dropped or altered. This is #59's "existing
 *      stored title/slug rules survive the move with their patterns and slug
 *      mode intact" criterion, checked against the real sanitizer rather than
 *      reasoned about.
 *
 * Run: wp eval-file <mount>/tools/fixtures/mc-rules/sweep-59-roundtrip.php --allow-root
 * Prereq: mc-rules seeded (seed.php). Read-only apart from the boot writes.
 */

use BWS\MetaConductor\Admin\Config\FormatRulesConfig;
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
$before = $storage->get_rules('title_slug_rules');

$storage->maybe_migrate_kind_lists();

$repair = new ReflectionMethod(WireframeBootstrap::class, 'repair_stored_rules');
$repair->invoke(null, $storage);

$settings = get_option(OptionRuleStorage::OPTION_NAME, []);
$rows     = $settings[OptionRuleStorage::KIND_FORMAT] ?? [];

$note('format_rules is populated', !empty($rows));
$note('every format row is a title/slug rule',
    array_values(array_unique(array_column($rows, 'type'))) === ['title_slug_rules']);
$note('the legacy title_slug_rules array is gone from storage (#66)',
    !array_key_exists('title_slug_rules', $settings));

$titled = array_filter($rows, static fn($r) => ($r['row_title'] ?? '') !== '');
$note('every row carries a repaired row_title', count($titled) === count($rows));

foreach ($rows as $i => $r) {
    $note("row $i names a post type", !empty($r['post_type']));
    $note("row $i row_title carries the post-type scope",
        str_contains((string) ($r['row_title'] ?? ''), '('));
}

// The read path handlers use must be unchanged by the sync + repair — the
// repair adds row_title and nothing else.
$after = StorageFactory::get_instance();
$after->clear_cache();
$after_rules = $after->get_rules('title_slug_rules');
$strip = static fn(array $rs) => array_map(
    static function (array $r) { unset($r['row_title']); return $r; },
    $rs
);
$note('what the handler reads is unchanged apart from row_title',
    $strip($before) == $strip($after_rules));

// ── 2. RepeaterField::sanitize round-trip — the real save path. ─────────────

$field     = FormatRulesConfig::section()['fields'][0];
$sanitized = RepeaterField::sanitize($rows, $field['args']);

$note('sanitize keeps every row', count($sanitized) === count($rows));

// Keys TitleSlugHandler READS — these must survive a save unchanged.
$must_survive = ['type', 'enabled', 'post_type', 'title_pattern', 'slug_pattern',
                 'slug_mode', 'date_escalation', 'date_field', 'name'];

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
// onto title_slug_rules any more), so the list has to survive it in place.
$note('the sanitized list keeps every row', count($sanitized) === count($rows));
$note('every sanitized row is still typed',
    array_values(array_unique(array_column($sanitized, 'type'))) === ['title_slug_rules']);

echo $fail ? "\nSWEEP-59 FAIL: " . count($fail) . " failed\n" : "\nSWEEP-59 OK\n";
exit($fail ? 1 : 0);
