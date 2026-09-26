<?php
/**
 * Descriptor for `related_post_terms_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\RelatedPostTermsHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `related_post_terms_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class RelatedPostTermsRules extends RuleType {

    public function type(): string {
        return 'related_post_terms_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('From referenced post (ACF) — copy terms across a relationship field', 'meta-conductor');
    }

    public function handler_class(): string {
        return RelatedPostTermsHandler::class;
    }
}
