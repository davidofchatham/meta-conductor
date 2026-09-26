<?php
/**
 * Descriptor for `time_based_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\TimeBasedHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `time_based_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class TimeBasedRules extends RuleType {

    public function type(): string {
        return 'time_based_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Date window — apply a term while a date range is current', 'meta-conductor');
    }

    public function handler_class(): string {
        return TimeBasedHandler::class;
    }
}
