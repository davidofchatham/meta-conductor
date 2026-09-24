<?php
/**
 * Rule choice — which configured rule a bulk run is over, and what it reaches.
 *
 * @since 0.9.0
 */

namespace BWS\MetaConductor\Core;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Names one kind-list row, or every enabled rule, as a string a dropdown can
 * carry — and tells, when the run starts, whether that row is still there.
 *
 * WHY A FINGERPRINT. A row has no stable identity: `id` is its per-type index,
 * re-derived on every read, and the kind list is stored positionally. Between
 * the page load that built the dropdown and the click that runs it, an author
 * can edit, re-order, delete or toggle rows, and position alone would then
 * name whatever rule slid into the slot. So a choice is position AND a hash of
 * the row as a pass would see it, and a mismatch is refused — never resolved
 * to a nearby row, because the same guess picks the wrong rule the day two
 * rows are identical. Refusing costs the author a reload; guessing costs a
 * bulk write of a rule they did not pick.
 *
 * Pure: no WordPress calls, so H15 (`tests/verify-apply-existing.php`) proves
 * it on host PHP. The caller does the re-read.
 */
final class RuleChoice {

    /** Dropdown value for "All enabled rules" — every enabled row of both kinds. */
    public const ALL_ENABLED = 'all';

    /**
     * Statuses a run reaches when no rule narrows it. Never trash or
     * auto-draft: a clean-up run is for real content.
     */
    public const DEFAULT_STATUSES = ['publish', 'draft', 'private', 'future'];

    /** Never reached, whatever a rule's gate says. */
    private const EXCLUDED_STATUSES = ['trash', 'auto-draft'];

    /**
     * Types whose `post_status` does NOT gate the post being written. On
     * `related_post_terms` it gates the SOURCE (don't 6e(b)); narrowing the
     * dependents by it would silently drop a draft dependent of a published
     * source from the run.
     */
    private const SOURCE_STATUS_TYPES = ['related_post_terms_rules'];

    /**
     * Identity of a row's content: a hash of the PROJECTED row (as
     * `get_kind_rules()` returns it) minus the read-time `id`. Projected, so a
     * legacy shape the storage adapter erases is not a change; `enabled`
     * included, so a toggle is.
     *
     * @param array $row One projected kind-list row.
     * @return string 32 hex chars.
     */
    public static function fingerprint(array $row): string {
        unset($row['id']);

        return md5(serialize($row));
    }

    /**
     * @param string $kind     KIND_TERM or KIND_FORMAT.
     * @param int    $position The row's index in its (unfiltered) kind list.
     * @param array  $row      The projected row at that index.
     * @return string `kind:position:fingerprint`.
     */
    public static function encode(string $kind, int $position, array $row): string {
        return $kind . ':' . $position . ':' . self::fingerprint($row);
    }

    /**
     * Parse a dropdown value. Validates shape only — whether the row is still
     * there is `resolve()`'s question.
     *
     * @param string $value
     * @return array|null `['all' => true]`, or `['all' => false, 'kind',
     *                    'position', 'fingerprint']`; null when malformed.
     */
    public static function decode(string $value): ?array {
        if ($value === self::ALL_ENABLED) {
            return ['all' => true];
        }

        $parts = explode(':', $value);

        if (count($parts) !== 3) {
            return null;
        }

        [$kind, $position, $fingerprint] = $parts;

        if (!in_array($kind, [OptionRuleStorage::KIND_TERM, OptionRuleStorage::KIND_FORMAT], true)
            || !ctype_digit($position)
            || !preg_match('/^[0-9a-f]{32}$/', $fingerprint)
        ) {
            return null;
        }

        return [
            'all'         => false,
            'kind'        => $kind,
            'position'    => (int) $position,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * The stale check: the chosen row, if the row at its position in a fresh
     * read of its kind list still has its fingerprint.
     *
     * @param array $choice A decoded single-row choice.
     * @param array $rows   The choice's kind list, re-read, UNFILTERED.
     * @return array|null The row; null when stale (or the sentinel).
     */
    public static function resolve(array $choice, array $rows): ?array {
        if (!empty($choice['all'])) {
            return null;
        }

        $row = $rows[$choice['position']] ?? null;

        return is_array($row) && self::fingerprint($row) === $choice['fingerprint'] ? $row : null;
    }

    /**
     * The rows a pass runs: the enabled ones, plus the one row whose
     * fingerprint is `$include` — a disabled rule a one-time run is over.
     *
     * Authored order, because the included row runs at its own position: on a
     * format rule that is what lets it win its type's first match, exactly as
     * it would once enabled. With `$include` null this is the storage layer's
     * `['enabled' => true]` filter, and H15 holds it to that.
     *
     * @param array       $rows    A projected kind list, UNFILTERED.
     * @param string|null $include Fingerprint of the row to run anyway.
     * @return array[]
     */
    public static function pass_rows(array $rows, ?string $include): array {
        return array_values(array_filter($rows, fn(array $row): bool =>
            ($row['enabled'] ?? true) === true
            || ($include !== null && self::fingerprint($row) === $include)
        ));
    }

    /**
     * Which post statuses a run over this rule may touch.
     *
     * The rule's `post_status` where it gates the written post, else the
     * default set. Empty or `any` means ungated, as in `should_process_post()`.
     *
     * Can return []: a gate of only trash reaches NOTHING. Never hand [] to
     * `WP_Query` as `post_status` — it reads that as the default ("publish").
     *
     * @param array|null $row Projected row; null for "All enabled rules".
     * @return string[]
     */
    public static function reach_statuses(?array $row): array {
        if ($row === null || in_array($row['type'] ?? '', self::SOURCE_STATUS_TYPES, true)) {
            return self::DEFAULT_STATUSES;
        }

        $gate = ConfigHelpers::selected_checkbox_slugs($row['post_status'] ?? []);

        if ($gate === [] || $gate[0] === 'any') {
            return self::DEFAULT_STATUSES;
        }

        return array_values(array_diff($gate, self::EXCLUDED_STATUSES));
    }
}
