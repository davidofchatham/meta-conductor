<?php
/**
 * Descriptor for `title_slug_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\TitleSlugHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `title_slug_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class TitleSlugRules extends RuleType {

    public function type(): string {
        return 'title_slug_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_FORMAT;
    }

    public function label(): string {
        return __('Title & slug pattern — build the title and slug from tokens', 'meta-conductor');
    }

    public function handler_class(): string {
        return TitleSlugHandler::class;
    }
}
