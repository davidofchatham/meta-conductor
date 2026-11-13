<?php
namespace BWS\MetaConductor\Storage;

if (!defined('ABSPATH')) exit;

/**
 * One-shot rewrite of stored rule-type strings to the ids that read like their
 * labels (FW-63).
 *
 * A stored-row rewrite on live rule types, run once by the upgrade routine in
 * meta-conductor.php; there is no read-time shim, so nothing accepts an old id
 * and an unmigrated row reads as an unknown type and is skipped. **Delete this
 * class, its H2 line, its harness and the upgrade call one minor release after
 * the one that ships it.**
 *
 * Only a row's `type` string changes. Row order, array keys, every other key,
 * rows of other types and rows already on a new id come back unchanged. Both
 * persisted kind lists (`term_rules`, `format_rules`) are walked. MAP is a
 * plain old-id → new-id table; a merge changes row shape, not just the id, so
 * FW-61's is a separate step with its own flag, RelatedPostMergeMigration, run
 * directly after this one.
 *
 * Runs after SlugEscalationMigration, which still matches the old
 * `title_slug_rules` string.
 *
 * @since 0.11.0
 */
class RuleTypeIdMigration {

    /** Schema flag: set once the stored rows have been through migrate_rows(). */
    const FLAG_OPTION = 'bws_mc_rule_type_id_schema';

    /** Old stored type string → new id. */
    const MAP = [
        'hierarchical_rules'                   => 'terms_from_term_hierarchy',
        'related_rules'                        => 'terms_from_trigger_term',
        'time_based_rules'                     => 'terms_from_date_window',
        'term_count_rules'                     => 'restrict_term_count',
        'hierarchical_level_restriction_rules' => 'restrict_terms_by_level',
        'title_slug_rules'                     => 'title_slug_from_pattern',
        'field_transformation_rules'           => 'field_from_pattern',
    ];

    /**
     * Pure translation of a stored kind list.
     *
     * @param array $rows A stored `term_rules` or `format_rules` list.
     * @return array The list with old type strings swapped for new ids.
     */
    public static function migrate_rows(array $rows): array {
        foreach ($rows as $i => $row) {
            $type = is_array($row) ? ($row['type'] ?? null) : null;
            if (is_string($type) && isset(self::MAP[$type])) {
                $rows[$i]['type'] = self::MAP[$type];
            }
        }

        return $rows;
    }

    /**
     * Migrate the stored option once. Idempotent: the flag short-circuits, and
     * migrate_rows() is a no-op on a migrated list anyway.
     *
     * @return bool True when this call rewrote rows.
     */
    public static function run(): bool {
        if (get_option(self::FLAG_OPTION)) {
            return false;
        }

        $changed  = false;
        $settings = get_option(OptionRuleStorage::OPTION_NAME);
        if (is_array($settings)) {
            foreach ([OptionRuleStorage::KIND_TERM, OptionRuleStorage::KIND_FORMAT] as $kind) {
                if (!is_array($settings[$kind] ?? null)) {
                    continue;
                }
                $migrated = self::migrate_rows($settings[$kind]);
                if ($migrated !== $settings[$kind]) {
                    $settings[$kind] = $migrated;
                    $changed = true;
                }
            }
            if ($changed) {
                update_option(OptionRuleStorage::OPTION_NAME, $settings);
            }
        }

        update_option(self::FLAG_OPTION, 1);
        return $changed;
    }
}
