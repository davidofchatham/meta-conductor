<?php
/**
 * The zero-source gate every relationship rule shares.
 *
 * @package Meta_Conductor
 * @since 0.12.0
 */

namespace BWS\MetaConductor\Relationships;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which sources count for a dependent under a row, and whether the row acts.
 *
 * THE ONE ENCODING. The eligible sources are the resolved sources that pass the
 * row's `post_status` limit (empty or `any` = ungated); a source that no longer
 * exists never counts. With zero eligible sources the row is SKIPPED under every
 * claim, because a dependent with nothing to read from is not managed by the
 * row, and acting on it would let an unpublished or deleted source empty a post
 * that did not change. The one exception is a sever captured against THIS row
 * (`AcfRelationship::is_severed()`): the same evidence of management one moment
 * later, so the row proceeds with an empty set.
 *
 * Every relationship rule routes through `resolve()`, and no handler filters
 * sources by status or tests for an empty source list itself (H13). The
 * relationship's `is_eligible_source()` is a different question, whether a post
 * can be a source at all, and stays on the contract.
 */
final class SourceGate {

    /**
     * @param int[]  $sources Resolved source post IDs (`Relationship::sources_of()`).
     * @param array  $row     Canonical-shape rule row.
     * @param bool   $severed Whether this row captured a sever against the dependent.
     * @return int[]|null The eligible source IDs, in resolved order (empty only
     *                    for a severed row); null = skip the row for this dependent.
     */
    public static function resolve(array $sources, array $row, bool $severed = false): ?array {
        $statuses = (array) ($row['post_status'] ?? []);
        $gated    = !empty($statuses) && ($statuses[0] ?? '') !== 'any';

        $eligible = [];
        foreach ($sources as $id) {
            $status = \get_post_status((int) $id);
            if ($status === false) {
                continue;
            }
            if (!$gated || in_array($status, $statuses, true)) {
                $eligible[] = (int) $id;
            }
        }

        return ($eligible === [] && !$severed) ? null : $eligible;
    }
}
