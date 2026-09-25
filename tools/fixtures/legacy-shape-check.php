<?php
/**
 * Read-only: does this site's stored rule option still hold any legacy shape?
 *
 * Gate for FW-39's storage PR. The one-time migrations and the every-boot
 * admin row repairs may be deleted only if every site with rules reports
 * CLEAN here. Reads `get_option()` and nothing else — no plugin class is
 * loaded or required, and nothing is written.
 *
 *   wp eval-file legacy-shape-check.php [--allow-root]
 *
 * Exit summary: `CLEAN` or `LEGACY: N finding(s)`. INFO lines are not
 * findings — they describe state the deletion does not depend on.
 */

$opt      = get_option('bws_meta_conductor_settings');
$findings = [];
$info     = [];

if (!is_array($opt)) {
    echo "No rule option on this site — nothing to check.\nCLEAN\n";
    return;
}

// 1. Pre-#66 type-keyed arrays still present (upgrade_legacy_shape's input).
$legacy_keys = [
    'hierarchical_rules', 'propagation_rules', 'related_rules', 'time_based_rules',
    'related_post_terms_rules', 'hierarchical_level_restriction_rules', 'title_slug_rules',
];
foreach ($legacy_keys as $k) {
    if (array_key_exists($k, $opt)) {
        $n = is_array($opt[$k]) ? count($opt[$k]) : 0;
        // Empty arrays are what the old activation seed wrote; harmless once
        // the kind lists exist, but they are what the pre-0.8 guard tests for.
        $n > 0
            ? $findings[] = "top-level legacy key '$k' holds $n row(s)"
            : $info[]     = "top-level legacy key '$k' present but empty";
    }
}

foreach (['term_rules', 'format_rules'] as $kind) {
    if (!array_key_exists($kind, $opt)) {
        $findings[] = "kind list '$kind' absent";
    }
}

// 2. Per-row shapes the admin repairs + RPT read migration still translate.
foreach ((array) ($opt['term_rules'] ?? []) as $i => $row) {
    $n    = $i + 1;
    $type = is_array($row) ? ($row['type'] ?? '') : '';
    $at   = "term_rules row $n ($type)";

    if (!is_array($row)) {
        $findings[] = "term_rules row $n is not an array";
        continue;
    }

    if ($type === 'hierarchical_rules') {
        foreach (['hierarchy_direction', 'expansion_behavior'] as $k) {
            if (isset($row[$k])) {
                $findings[] = "$at: legacy key '$k'";
            }
        }
        if ((string) ($row['inheritance_behavior'] ?? '') === '') {
            $findings[] = "$at: inheritance_behavior empty";
        }
    }

    if ($type === 'related_rules' || $type === 'time_based_rules') {
        foreach (['trigger_term_id', 'target_term_id'] as $k) {
            if (isset($row[$k]) && !is_array($row[$k])) {
                $findings[] = "$at: scalar '$k' (" . var_export($row[$k], true) . ')';
            }
        }
        foreach (['trigger_label', 'target_label', 'scope_label'] as $k) {
            if (isset($row[$k])) {
                $findings[] = "$at: stale label key '$k'";
            }
        }
    }

    if ($type === 'related_post_terms_rules') {
        foreach (['source_taxonomy', 'target_taxonomy', 'bidirectional', 'conflict_handling', '_migration_flag'] as $k) {
            if (array_key_exists($k, $row)) {
                $findings[] = "$at: legacy key '$k'";
            }
        }
        if (!isset($row['keep_in_sync'])) {
            $findings[] = "$at: keep_in_sync absent";
        }
        if ((string) ($row['holder_role'] ?? '') === '') {
            $findings[] = "$at: holder_role empty";
        }
        foreach (['acf_field_name', 'reverse_acf_field_name'] as $k) {
            $v = (string) ($row[$k] ?? '');
            if ($v !== '' && count(explode(':', $v, 3)) < 3) {
                // Ambiguous-name rows are left two-part ON PURPOSE (don't 6),
                // and the two-part parser survives the deletion — so this is
                // information, not a blocker.
                $info[] = "$at: '$k' is two-part ('$v') — resolves by name";
            }
        }
    }

    if ((string) ($row['row_title'] ?? '') === '') {
        $info[] = "$at: row_title empty (title backfill stays; not a finding)";
    }
}

// 3. One-time migration flags (informational: the code gated by them goes).
$info[] = 'bws_mc_acfref_schema = ' . var_export(get_option('bws_mc_acfref_schema'), true);
$info[] = 'bws_meta_conductor_version = ' . var_export(get_option('bws_meta_conductor_version'), true);
if (defined('META_CONDUCTOR_VERSION')) {
    $info[] = 'running META_CONDUCTOR_VERSION = ' . META_CONDUCTOR_VERSION;
}
$info[] = sprintf(
    'rows: term_rules=%d format_rules=%d',
    count((array) ($opt['term_rules'] ?? [])),
    count((array) ($opt['format_rules'] ?? []))
);

foreach ($info as $line) {
    echo "INFO  $line\n";
}
foreach ($findings as $line) {
    echo "FOUND $line\n";
}
echo $findings ? 'LEGACY: ' . count($findings) . " finding(s)\n" : "CLEAN\n";
