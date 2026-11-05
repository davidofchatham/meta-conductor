<?php

namespace BWS\MetaConductor\Core;

if (!defined('ABSPATH')) exit;

/**
 * Which terms survive a Term count limit (`term_count_rules`).
 *
 * PURE. No WordPress call, no stored option: the handler hands in the post's
 * terms with their tree depths and the delta an outside write just added, so
 * `tests/verify-term-count-survivors.php` pins the whole ordering on host PHP.
 *
 * ORDER OF PREFERENCE: (1) terms in the delta, (2) deepest, (3) lowest term ID.
 * Within the delta the tie-break is the same, deepest then lowest ID. With
 * `$per_level` the cap applies inside each depth; without it, to the whole set.
 * A flat input (every depth 0) gives the same answer either way.
 *
 * The delta is request-lifetime only, never stored, so this is not provenance
 * (architecture.md invariant 19). With an empty delta (bulk apply, cron, a save
 * that touched no terms) the result is deterministic and stable across reruns.
 */
final class TermCountSurvivors {

    /**
     * @param array<int,array{id:int,depth:int}> $terms    The post's current terms.
     * @param int[]                              $delta    Term IDs an outside write just added; may be empty.
     * @param int                                $max      The cap (values below 1 act as 1).
     * @param bool                               $per_level Cap each depth instead of the whole set.
     * @return array<int,array{id:int,depth:int}> Survivors, most preferred first; independent of the input order.
     */
    public static function select(array $terms, array $delta, int $max, bool $per_level): array {
        $max   = max(1, $max);
        $delta = array_flip(array_map('intval', $delta));

        $unique = [];
        foreach ($terms as $t) {
            $unique[(int) $t['id']] ??= ['id' => (int) $t['id'], 'depth' => (int) $t['depth']];
        }

        $rank = static function (array $a, array $b) use ($delta): int {
            return (isset($delta[$b['id']]) <=> isset($delta[$a['id']]))
                ?: ($b['depth'] <=> $a['depth'])
                ?: ($a['id'] <=> $b['id']);
        };

        $groups = $per_level ? [] : [0 => array_values($unique)];
        if ($per_level) {
            foreach ($unique as $t) {
                $groups[$t['depth']][] = $t;
            }
        }

        $out = [];
        foreach ($groups as $group) {
            usort($group, $rank);
            array_push($out, ...array_slice($group, 0, $max));
        }
        usort($out, $rank);

        return $out;
    }
}
