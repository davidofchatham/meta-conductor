<?php
/**
 * Descriptor for `restrict_terms_by_level` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\RestrictTermsByLevelHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `restrict_terms_by_level`, not a domain name — renaming a type for
 * authors edits `label()` only.
 */
final class RestrictTermsByLevelRules extends RuleType {

    public function type(): string {
        return 'restrict_terms_by_level';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Restrict terms by tree level', 'meta-conductor');
    }

    public function handler_class(): string {
        return RestrictTermsByLevelHandler::class;
    }

    public function target_key(array $rule): ?string {
        return self::taxonomy_target($rule);
    }

    public const MODE_DEEPEST    = 'deepest_only';
    public const MODE_SHALLOWEST = 'shallowest_only';

    /**
     * The row's mode, with anything unrecognized (absent, or a retired value
     * such as `one_per_level`) read as the default, `deepest_only`. Every
     * reader goes through here so they all degrade the same way.
     */
    public static function mode(array $row): string {
        $mode = (string) ($row['restriction_mode'] ?? '');
        return $mode === self::MODE_SHALLOWEST ? $mode : self::MODE_DEEPEST;
    }

    /**
     * Schema:
     *   {Scope: }Restrict {Taxonomy} to {mode}{, keeping ancestors}
     *   e.g. "Restrict Shakers to the deepest level"
     *        "Pages: Restrict Shakers to the shallowest level, keeping ancestors"
     */
    public function row_title(array $row): string {
        $mode = self::mode($row) === self::MODE_SHALLOWEST
            ? __('the shallowest level', 'meta-conductor')
            : __('the deepest level', 'meta-conductor');

        $title = Labels::scope_prefix($row['post_types'] ?? []) . sprintf(
            /* translators: 1: taxonomy label 2: which depths may keep terms */
            __('Restrict %1$s to %2$s', 'meta-conductor'),
            Labels::taxonomy_label($row['taxonomy'] ?? ''),
            $mode
        );

        // The flag means the same in both modes, so the clause shows whenever it is set.
        if (!empty($row['include_ancestors'])) {
            $title .= __(', keeping ancestors', 'meta-conductor');
        }

        return $title;
    }

    public function reads_shared_fields(): array {
        return ['taxonomy', 'hierarchical_taxonomy_note', 'post_types'];
    }

    /**
     * Restrict terms by tree level — limit which tree depths may hold terms.
     *
     * Two modes, both about depth: keep the terms at the deepest level the post
     * uses, or at the shallowest. Capping HOW MANY terms a post holds is the
     * Term count limit rule's job, not this one's.
     *
     * `include_ancestors` has one meaning: also keep the ancestors of whatever
     * the mode kept. It acts in both modes, so it carries no `conditions` gate
     * — hiding it in one mode would lose its value on save.
     *
     * A stored `restriction_mode` outside the two (a retired `one_per_level`
     * row) reads as the default through mode(); no migration, because the type
     * is not live on a real site.
     *
     * @return array[]
     */
    public function subfields(): array {
        return [
            [
                'id'          => 'restriction_mode',
                'type'        => 'radio',
                'label'       => __('Which depths may keep terms', 'meta-conductor'),
                'default'     => self::MODE_DEEPEST,
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        self::MODE_DEEPEST    => __('Only terms at the deepest level used', 'meta-conductor'),
                        self::MODE_SHALLOWEST => __('Only terms at the shallowest level used', 'meta-conductor'),
                    ],
                ],
            ],
            [
                'id'          => 'include_ancestors',
                'type'        => 'toggle',
                'label'       => __('Keep ancestor terms', 'meta-conductor'),
                'description' => __('Also keep the parent chain of every term the rule keeps, so a post tagged with a deep term still appears under its ancestor archives. Applies in both modes: the ancestors are added back even though they sit at shallower levels than the mode would otherwise allow.', 'meta-conductor'),
                'default'     => false,
                'columns'     => 12,
            ],
        ];
    }
}
