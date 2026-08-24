<?php
/**
 * #58 dynamic sweep — the two LIVE types through the unified repeater.
 *
 * The dynamic half of H11 for the #58 batch, run against the real testbed:
 *
 *   1. Simulates the admin-load storage sequence (acf-ref migration →
 *      kind-list sync → stored-rule repair) the way WireframeBootstrap::boot
 *      runs it, then asserts the persisted `term_rules` list carries the
 *      related + related_post_terms rows in admin-renderable shape —
 *      combined "post_type:field" acf_field_name, array term ids, row_title.
 *
 *   2. Runs every persisted row through Wireframe's REAL
 *      RepeaterField::sanitize against the live TermRulesConfig subfields —
 *      the exact code path a settings save takes — and asserts no value a
 *      rule type reads is dropped or altered. This is the "no subfield
 *      silently dropped" acceptance criterion, per stored row.
 *
 * Run: wp eval-file <mount>/tools/fixtures/mc-rules/sweep-58-roundtrip.php --allow-root
 * Prereq: mc-rules seeded (seed.php). Read-only apart from the boot writes.
 */

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
$storage->maybe_migrate_acf_ref_storage();
$storage->sync_kind_lists();

$repair = new ReflectionMethod(WireframeBootstrap::class, 'repair_stored_rules');
$repair->invoke(null, $storage);

$settings = get_option(OptionRuleStorage::OPTION_NAME, []);
$rows     = $settings[OptionRuleStorage::KIND_TERM] ?? [];

$by_type = [];
foreach ($rows as $row) {
    $by_type[$row['type'] ?? ''][] = $row;
}

$note('term_rules carries all 10 fixture term-kind rows', count($rows) === 10);
$note('2 related rows present',  count($by_type['related_rules'] ?? []) === 2);
$note('2 acf-reference rows present', count($by_type['related_post_terms_rules'] ?? []) === 2);

foreach ($by_type['related_post_terms_rules'] ?? [] as $i => $r) {
    $note("acf row $i keeps the COMBINED post_type:field value",
        str_contains((string) ($r['acf_field_name'] ?? ''), ':'));
    $note("acf row $i is in migrated shape (taxonomy present, no source_taxonomy)",
        !empty($r['taxonomy']) && !isset($r['source_taxonomy']));
}
foreach ($by_type['related_rules'] ?? [] as $i => $r) {
    $note("related row $i trigger_term_id is the select's array shape",
        !isset($r['trigger_term_id']) || is_array($r['trigger_term_id']));
    $note("related row $i target_term_id is the select's array shape",
        !isset($r['target_term_id']) || is_array($r['target_term_id']));
}

$titled = array_filter($rows, static fn($r) => ($r['row_title'] ?? '') !== '');
$note('every row carries a repaired row_title', count($titled) === count($rows));

// ── 2. RepeaterField::sanitize round-trip — the real save path. ─────────────

$field     = TermRulesConfig::section()['fields'][0];
$sanitized = RepeaterField::sanitize($rows, $field['args']);

$note('sanitize keeps every row', count($sanitized) === count($rows));

// Keys the handler READS per live type — these must survive a save unchanged.
$must_survive = [
    'related_rules'            => ['type', 'enabled', 'trigger_type', 'trigger_term_id',
                                   'trigger_taxonomy', 'target_term_id', 'bidirectional',
                                   'post_types', 'post_status'],
    'related_post_terms_rules' => ['type', 'enabled', 'acf_field_name', 'holder_role',
                                   'reverse_acf_field_name', 'taxonomy', 'keep_in_sync', 'post_status'],
];

foreach ($rows as $i => $row) {
    $type = $row['type'] ?? '';
    if (!isset($must_survive[$type])) {
        continue;
    }
    foreach ($must_survive[$type] as $key) {
        if (!array_key_exists($key, $row)) {
            continue; // absent pre-save stays absent-or-default; nothing to lose
        }
        $before = $row[$key];
        $after  = $sanitized[$i][$key] ?? '(DROPPED)';
        // Checkbox gates round-trip {slug:bool}→list; compare selected sets.
        if (in_array($key, ['post_types', 'post_status'], true)) {
            $norm   = static fn($v) => array_values(array_filter(is_array($v)
                ? (array_is_list($v) ? $v : array_keys(array_filter($v)))
                : []));
            $same = $norm($before) === $norm($after);
        } else {
            $same = $before == $after; // loose: sanitize may cast '1'→true etc.
        }
        $note("row $i ($type) '$key' survives sanitize" . ($same ? '' : " [$after]"),
            $same && $after !== '(DROPPED)');
    }
}

// The full-list re-projection must still agree with the legacy arrays after a
// simulated save (fan-out on the sanitized payload).
$projected = WireframeBootstrap::fan_out_rule_lists([
    OptionRuleStorage::KIND_TERM => $sanitized,
]);
$note('sanitized fan-out reproduces both live types row-for-row (count)',
    count($projected['related_rules']) === 2
    && count($projected['related_post_terms_rules']) === 2);

echo $fail ? "\nSWEEP-58 FAIL: " . count($fail) . " failed\n" : "\nSWEEP-58 OK\n";
exit($fail ? 1 : 0);
