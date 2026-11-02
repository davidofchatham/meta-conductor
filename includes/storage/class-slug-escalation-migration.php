<?php
namespace BWS\MetaConductor\Storage;

if (!defined('ABSPATH')) exit;

/**
 * One-shot rewrite of stored title/slug rows from `date_escalation` +
 * `date_field` to the `slug_escalation` ladder field (FW-51 03, D17).
 *
 * A stored-row rewrite on a live rule type, run once by the upgrade routine
 * in meta-conductor.php; there is no read-time shim, so nothing reads the old
 * keys. **Delete this class, its H2 line, its harness and the upgrade call one
 * minor release after the one that ships it.**
 *
 * Translation, per `title_slug_rules` row in the format kind list:
 * - `date_escalation` on, no `date_field` → `slug_escalation = {pub_year}`
 * - `date_escalation` on, `date_field` X  → `slug_escalation = {date_year:X}`
 * - `date_escalation` off                 → `slug_escalation = ''`
 * - both old keys are dropped from every row that held either
 * A row already carrying a non-empty `slug_escalation` keeps it. Rows of other
 * types and rows holding neither old key are returned untouched.
 *
 * @since 0.10.0
 */
class SlugEscalationMigration {

    /** Schema flag: set once the stored rows have been through migrate_rows(). */
    const FLAG_OPTION = 'bws_mc_slug_escalation_schema';

    /**
     * Pure translation of a format kind list.
     *
     * @param array $rows The stored `format_rules` list.
     * @return array The list with title/slug rows rewritten.
     */
    public static function migrate_rows(array $rows): array {
        foreach ($rows as $i => $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'title_slug_rules') {
                continue;
            }
            if (!array_key_exists('date_escalation', $row) && !array_key_exists('date_field', $row)) {
                continue;
            }

            $field = trim((string) ($row['date_field'] ?? ''));
            if (empty($row['date_escalation'])) {
                $ladder = '';
            } else {
                $ladder = $field === '' ? '{pub_year}' : '{date_year:' . $field . '}';
            }

            unset($row['date_escalation'], $row['date_field']);
            if (trim((string) ($row['slug_escalation'] ?? '')) === '') {
                $row['slug_escalation'] = $ladder;
            }
            $rows[$i] = $row;
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
        $list     = is_array($settings) ? ($settings[OptionRuleStorage::KIND_FORMAT] ?? null) : null;
        if (is_array($list)) {
            $migrated = self::migrate_rows($list);
            if ($migrated !== $list) {
                $settings[OptionRuleStorage::KIND_FORMAT] = $migrated;
                update_option(OptionRuleStorage::OPTION_NAME, $settings);
                $changed = true;
            }
        }

        update_option(self::FLAG_OPTION, 1);
        return $changed;
    }
}
