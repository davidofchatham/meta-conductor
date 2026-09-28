<?php
/**
 * Rule-type registry — the one place rule types are enumerated.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ordered list of every rule type's descriptor.
 *
 * ORDER IS MEANINGFUL: it is the type-select order and the order fixture
 * tooling authors by-type rules in. It is never the order a pass runs rows in
 * — that is the authored kind-list order, which storage never re-sorts.
 *
 * Storage's kind map, the configs' type labels and TaxonomyManager's handler
 * map are all views of this list. H16 (`tests/verify-rule-type-registry.php`)
 * pins the order and each descriptor's completeness.
 */
final class Registry {

    /**
     * @var class-string<RuleType>[]
     */
    private const DESCRIPTORS = [
        PropagationRules::class,
        RelatedPostTermsRules::class,
        TimeBasedRules::class,
        RelatedRules::class,
        HierarchicalRules::class,
        HierarchicalLevelRestrictionRules::class,
        TitleSlugRules::class,
    ];

    /**
     * @var array<string,RuleType>|null
     */
    private static ?array $all = null;

    /**
     * Every descriptor, keyed by storage type, in registry order.
     *
     * @return array<string,RuleType>
     */
    public static function all(): array {
        if (self::$all === null) {
            self::$all = [];
            foreach (self::DESCRIPTORS as $class) {
                $descriptor = new $class();
                self::$all[$descriptor->type()] = $descriptor;
            }
        }

        return self::$all;
    }

    /**
     * The descriptor for a stored type, or null if no type has that key.
     *
     * @param string $type Storage type key.
     * @return RuleType|null
     */
    public static function get(string $type): ?RuleType {
        return self::all()[$type] ?? null;
    }

    /**
     * The descriptor that names a handler class, or null if none does.
     *
     * How a handler learns its own storage key without spelling it: the type
     * string lives in the descriptor alone (ticket 12).
     *
     * @param string $handler_class Fully-qualified handler class.
     * @return RuleType|null
     */
    public static function for_handler(string $handler_class): ?RuleType {
        foreach (self::all() as $descriptor) {
            if ($descriptor->handler_class() === $handler_class) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * Whether a stored row's type has a descriptor — the runtime question.
     *
     * A row with no descriptor is skipped, never guessed at and never fatal:
     * a front-end request is no place to refuse a rule set. The first miss of
     * each type logs one line (under WP_DEBUG), so a drain over many posts
     * says it once. Admin surfaces refuse the row visibly instead.
     *
     * @param string $type Storage type key.
     * @return bool
     */
    public static function known(string $type): bool {
        static $logged = [];

        if (self::get($type) !== null) {
            return true;
        }
        if (!isset($logged[$type]) && defined('WP_DEBUG') && WP_DEBUG) {
            $logged[$type] = true;
            error_log('[Meta Conductor] Skipping a stored rule of unknown type "' . $type . '" — no descriptor is registered for it.');
        }

        return false;
    }

    /**
     * The descriptors of one kind, keyed by storage type, in registry order.
     *
     * @param string $kind KIND_TERM or KIND_FORMAT.
     * @return array<string,RuleType>
     */
    public static function of_kind(string $kind): array {
        return array_filter(self::all(), static fn(RuleType $d): bool => $d->kind() === $kind);
    }
}
