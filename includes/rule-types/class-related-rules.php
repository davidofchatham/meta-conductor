<?php
/**
 * Descriptor for `related_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\RelatedHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `related_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class RelatedRules extends RuleType {

    public function type(): string {
        return 'related_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Related term — a trigger term applies a target term', 'meta-conductor');
    }

    public function handler_class(): string {
        return RelatedHandler::class;
    }
}
