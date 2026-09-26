<?php
/**
 * Descriptor for `hierarchical_level_restriction_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\HierarchicalLevelRestrictionHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `hierarchical_level_restriction_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class HierarchicalLevelRestrictionRules extends RuleType {

    public function type(): string {
        return 'hierarchical_level_restriction_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Level restriction — limit which tree depths may hold terms', 'meta-conductor');
    }

    public function handler_class(): string {
        return HierarchicalLevelRestrictionHandler::class;
    }
}
