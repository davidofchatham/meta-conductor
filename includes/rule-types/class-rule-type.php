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
     * The collapsed row title for one row of this type.
     *
     * UNESCAPED, and without the position number or disabled marker —
     * `WireframeBootstrap::snapshot_row_titles()` adds both and escapes once.
     * The row arrives through `OptionRuleStorage::project_kind_rules()`, so
     * term ids and the ACF field value are already decoded. Shared label
     * helpers live on `Labels`.
     *
     * @param array $row Projected row.
     * @return string Unescaped.
     */
    abstract public function row_title(array $row): string;

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

    /**
     * Which of its kind's SHARED subfields a row of this type shows.
     *
     * The kind config builds every gated shared subfield's `conditions` from
     * these declarations. List every shared field the handler reads: a reader
     * missing here has that value hidden and DROPPED on the next save
     * (CLAUDE.md don't 3). A shared `html` note is listed by the types it
     * annotates. Subfields every type reads (`type`, `enabled`, `post_status`,
     * `row_title`, the format frame) are ungated and never listed.
     *
     * H11/H12's hand-written `$expected_visible` is the independent check on
     * this list; never derive it from here.
     *
     * @return string[] Shared subfield ids.
     */
    public function reads_shared_fields(): array {
        return [];
    }

    /**
     * This type's own subfields, in render order.
     *
     * Returned UNGATED: the kind config stamps a `type in [this type]` gate on
     * every one, overwriting any `conditions` set here. Ids must be unique
     * across the whole kind repeater (H11/H12), so a field two types read is
     * a shared one, declared by the config and listed in
     * `reads_shared_fields()`, not here.
     *
     * @return array[] Wireframe subfield definitions.
     */
    public function subfields(): array {
        return [];
    }

    /**
     * This type's branch of the stored-shape projection.
     *
     * Runs AFTER `OptionRuleStorage::normalize_rule_shape()` has applied the
     * cross-type coercions (checkbox gates, term ids), so a row arrives with
     * its shared fields already canonical. Read-only, like the projection:
     * nothing writes the result back.
     *
     * @param array $row Stored row, shared fields already projected.
     * @return array
     */
    public function normalize(array $row): array {
        return $row;
    }
}
