<?php
/**
 * Rule-type descriptor — the static facts of one rule type, stated once.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * One subclass per rule type, listed in `Registry`.
 *
 * A descriptor is NOT the handler. A handler is a runtime object whose
 * constructor registers capture hooks; a descriptor holds only facts that
 * admin and runtime both read, and names its handler class. Constructing one
 * does nothing.
 *
 * Subclasses are named after the STORAGE key (`TimeBasedRules` for
 * `time_based_rules`), not after the domain concept. The name is a mirror of
 * the key and must not be renamed on its own; renaming a rule type for
 * authors (FW-14) edits `label()` only.
 */
abstract class RuleType {

    /**
     * The storage key: the `type` value on every stored row of this type.
     *
     * @return string
     */
    abstract public function type(): string;

    /**
     * The kind list the type lives in: `OptionRuleStorage::KIND_TERM` or
     * `KIND_FORMAT`.
     *
     * @return string
     */
    abstract public function kind(): string;

    /**
     * Author-facing label, as the `type` select shows it.
     *
     * @return string
     */
    abstract public function label(): string;

    /**
     * FQN of the handler that applies rows of this type.
     *
     * @return class-string<\BWS\MetaConductor\Handlers\UnifiedHandlerBase>
     */
    abstract public function handler_class(): string;

    /**
     * Whether the kind-list repeater declares this type's subfields yet.
     *
     * A type may be declared (and therefore read) a change before its config
     * exists, but must not be offered or persisted until it does:
     * `RepeaterField::sanitize` drops undeclared subfields, so a row rendered
     * without them is gutted on the next save (CLAUDE.md don't 6).
     *
     * @return bool
     */
    public function has_subfields(): bool {
        return true;
    }
}
