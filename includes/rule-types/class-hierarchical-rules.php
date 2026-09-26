<?php
/**
 * Descriptor for `hierarchical_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\HierarchicalHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `hierarchical_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class HierarchicalRules extends RuleType {

    public function type(): string {
        return 'hierarchical_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Hierarchy — inherit terms up or down the taxonomy tree', 'meta-conductor');
    }

    public function handler_class(): string {
        return HierarchicalHandler::class;
    }
}
