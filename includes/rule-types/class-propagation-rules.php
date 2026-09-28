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

    public function target_key(array $rule): ?string {
        return self::taxonomy_target($rule);
    }

    /**
     * Schema:
     *   {Scope: }Copy {Taxonomy} terms to children ({claim})
     *   e.g. "Pages: Copy Breakers terms to children (owning)"
     *        "Copy Categories terms to children (contributing)"
     * No arrow — direction is stated in words ("to children").
     */
    public function row_title(array $row): string {
        return Labels::scope_prefix($row['post_types'] ?? []) . sprintf(
            /* translators: 1: taxonomy label 2: claim */
            __('Copy %1$s terms to children (%2$s)', 'meta-conductor'),
            Labels::taxonomy_label($row['taxonomy'] ?? ''),
            Labels::claim_label($row['conflict_handling'] ?? 'merge')
        );
    }

    public function reads_shared_fields(): array {
        return ['taxonomy', 'post_types', 'hierarchical_post_type_note', 'conflict_handling'];
    }

    /**
     * The pull applier answers "what should this child hold, given its parent"
     * from live state, which cannot tell a term the parent HELD and lost from
     * one the child holds independently. So the removal is captured as it
     * happens — `deleted_term_relationships` is the only hook that sees it,
     * including inside `wp_set_object_terms` (#62).
     *
     * No `drains_captures()`: the capture concerns a parent whose children its
     * own `fan_out()` already reaches.
     */
    public function capture_hooks(): array {
        return ['deleted_term_relationships'];
    }
}
