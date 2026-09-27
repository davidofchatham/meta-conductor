<?php
/**
 * Descriptor for `propagation_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\PropagationHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `propagation_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class PropagationRules extends RuleType {

    public function type(): string {
        return 'propagation_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('From parent post — cascade terms to children', 'meta-conductor');
    }

    public function handler_class(): string {
        return PropagationHandler::class;
    }

    public function reads_shared_fields(): array {
        return ['taxonomy', 'post_types', 'hierarchical_post_type_note', 'conflict_handling'];
    }
}
