<?php
namespace BWS\MetaConductor\Storage;

if (!defined('ABSPATH')) exit;

/**
 * One-shot rewrite of the two related-post rule types into the merged
 * `terms_from_related_post` type (FW-61).
 *
 * A stored-row rewrite on live rule types, run once by the upgrade routine in
 * meta-conductor.php directly after RuleTypeIdMigration; there is no read-time
 * shim, so nothing accepts the old strings and an unmigrated row reads as an
 * unknown type and is skipped. It is a separate class with its own flag, not a
 * MAP entry or a new step under RuleTypeIdMigration's flag: a site that already
 * ran that migration would never run a new step under the same flag, and a merge
 * changes row shape, not just the id. **Delete this class, its H2 line, its
 * harness, its uninstall line and the upgrade call one minor release after the
 * one that ships it.**
 *
 * - `propagation_rules` → `terms_from_related_post` + `relationship = parent`;
 *   `conflict_handling` and every other key kept.
 * - `related_post_terms_rules` → `terms_from_related_post` + `relationship = acf`;
 *   the ACF field reference is stored whole and kept as is; `keep_in_sync` maps
 *   to `conflict_handling` (truthy `replace`, falsy `merge`) and is removed. A
 *   row with no `keep_in_sync` key gets no `conflict_handling`: the handler
 *   reads an absent claim as `merge`, which is what an absent `keep_in_sync`
 *   always meant.
 *
 * Row order, array keys, rows of other types, rows already on the new id and
 * unrelated keys come back unchanged. Both persisted kind lists are walked.
 *
 * @since 0.11.0
 */
class RelatedPostMergeMigration {

    /** Schema flag: set once the stored rows have been through migrate_rows(). */
    const FLAG_OPTION = 'bws_mc_related_post_merge_schema';

    /** Stored type of the merged rule. */
    const NEW_TYPE = 'terms_from_related_post';

    /**
     * Pure translation of a stored kind list.
     *
     * @param array $rows A stored `term_rules` or `format_rules` list.
     * @return array The list with the two old types rewritten to the merged one.
     */
    public static function migrate_rows(array $rows): array {
        foreach ($rows as $i => $row) {
            $type = is_array($row) ? ($row['type'] ?? null) : null;

            if ($type === 'propagation_rules') {
                $row['relationship'] = 'parent';
            } elseif ($type === 'related_post_terms_rules') {
                $row['relationship'] = 'acf';
                if (array_key_exists('keep_in_sync', $row)) {
                    $row['conflict_handling'] = $row['keep_in_sync'] ? 'replace' : 'merge';
                    unset($row['keep_in_sync']);
                }
            } else {
                continue;
            }

            $row['type'] = self::NEW_TYPE;
            $rows[$i]    = $row;
        }

        return $rows;
    }

    /**
     * Migrate the stored option once. Idempotent: the flag short-circuits, and
     * migrate_rows() is a no-op on a migrated list anyway.
     *
     * @return bool True when this call rewrote rows (the caller clears the cache).
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
