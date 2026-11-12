<?php
/**
 * Descriptor for `terms_from_related_post` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\TermsFromRelatedPostHandler;
use BWS\MetaConductor\Relationships\ParentRelationship;
use BWS\MetaConductor\Relationships\Relationship;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Set terms from a related post (FW-61): a post takes terms from a post it is
 * related to, by a `relationship` the row names. `parent` is the post's
 * `post_parent` (what propagation did); the ACF relationship joins it in
 * FW-61 04, where `related_post_terms_rules` is absorbed too.
 *
 * Everything that differs by relationship is answered by the relationship
 * module (`written_post_types()`, `status_gates_source()`), so the collision
 * detector and the Apply page never re-derive it.
 */
final class TermsFromRelatedPostRules extends RuleType {

    public function type(): string {
        return 'terms_from_related_post';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Set terms from a related post', 'meta-conductor');
    }

    public function handler_class(): string {
        return TermsFromRelatedPostHandler::class;
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
     * The one control this type owns. Both stored values are `parent` and
     * (from FW-61 04) `acf`; only `parent` is offered until then, because an
     * option that vanishes would drop the stored value on save.
     */
    public function subfields(): array {
        return [
            [
                'id'          => 'relationship',
                'type'        => 'select',
                'label'       => __('Relationship', 'meta-conductor'),
                'description' => __('Where the source post comes from.', 'meta-conductor'),
                'default'     => 'parent',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        'parent' => __('Parent post — a post takes terms from its parent', 'meta-conductor'),
                    ],
                ],
            ],
        ];
    }

    /**
     * The post types this row writes, as its relationship reports them.
     */
    public function written_post_types(array $rule): array {
        return $this->relationship($rule)?->written_post_types($rule) ?? [];
    }

    /**
     * Whether `post_status` gates the source (true) or the written post, as
     * the row's relationship states it. For `parent` it gates the written post
     * and the parent with it.
     */
    public function status_gates_source(array $rule): bool {
        return $this->relationship($rule)?->status_gates_source($rule) ?? false;
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

    /**
     * A throwaway instance, enough for the pure facts above. The handler keeps
     * its own, because their request memos belong to it.
     */
    private function relationship(array $rule): ?Relationship {
        return match ((string) ($rule['relationship'] ?? '')) {
            'parent' => new ParentRelationship(),
            default  => null,
        };
    }
}
