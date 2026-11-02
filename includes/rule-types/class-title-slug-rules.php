<?php
/**
 * Descriptor for `title_slug_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
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

    /**
     * Every row writes the same two fields, so the key is a constant and the
     * collision predicate reduces to post-type overlap — the handler's
     * first-match-wins lookup, stated as a collision.
     *
     * A row with no post type picked writes NOTHING (`rule_matches()` requires
     * one). Without the null the key is a constant, so the half-authored skip
     * could never fire for this kind and a blank new row would be reported as
     * colliding with every other format row.
     */
    public function target_key(array $rule): ?string {
        return trim((string) ($rule['post_type'] ?? '')) === '' ? null : 'fields|title_slug';
    }

    /** A lookup table keyed by post type: the first matching rule is the only one that runs. */
    public function first_match_wins(): bool {
        return true;
    }

    /**
     * Flat URLs are served at read time: the permalink is rewritten, the
     * request resolved, and a redirect back to the nested form refused, by the
     * handler's callbacks, registered by `TaxonomyManager` (FW-51).
     */
    public function runtime_hooks(): array {
        return [
            'post_type_link' => ['flat_permalink', 20, 3],
            'request'        => ['resolve_flat_request', 10, 1],
            'wp_redirect'    => ['veto_nested_redirect', 99, 2],
        ];
    }

    /**
     * The scalar `post_type`. Never []: a row without one resolves no target
     * (see `target_key()`), and if one ever reached here `['']` intersects
     * nothing — the safe failure — where [] would claim every post type.
     */
    public function written_post_types(array $rule): array {
        return [trim((string) ($rule['post_type'] ?? ''))];
    }

    /**
     * Schema:
     *   {name}{ (Post type)}
     *   e.g. "MC item slug (MC Items)"
     *
     * The author names these rules themselves (`name` is required), so unlike
     * the term titles there is nothing to assemble from the mechanics — the
     * snapshot's job here is the scope suffix and the disabled marker. A row
     * that reached storage without a name (a fixture, an import) is NAMED
     * rather than left blank: it is still selectable in a collapsed,
     * reorderable list and has to stay findable.
     */
    public function row_title(array $row): string {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            $name = __('Untitled title/slug rule', 'meta-conductor');
        }

        return $name . Labels::post_type_scope_label($row['post_type'] ?? '');
    }

    /**
     * Title & slug patterns — the mechanics of this one transformation.
     *
     * Every field here is gated: they are read only by `TitleSlugHandler`, and
     * offering them on a future `field_transformation` row would store junk
     * that reads as a working setting.
     *
     * @return array[]
     */
    public function subfields(): array {
        return [
            [
                // Scalar, not a `post_types` checkboxes scope.
                // `TitleSlugHandler::rule_matches()` reads this key by name
                // and the format pass stops at the FIRST title & slug rule
                // matching the post's type, so the value is a lookup key
                // rather than a scope, and two rules on one post type means
                // the lower one never runs.
                'id'          => 'post_type',
                'type'        => 'select',
                'label'       => __('Post type', 'meta-conductor'),
                // Order decides the winner, so the description says so.
                'description' => __('The post type this rule acts on. If two title & slug rules name the same post type, the higher one wins and the lower one never runs.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => ConfigHelpers::format_post_type_options(__('— Select post type —', 'meta-conductor')),
                ],
            ],
            [
                'id'          => 'title_pattern',
                'type'        => 'text',
                'label'       => __('Title pattern', 'meta-conductor'),
                'description' => __('Optional. Leave blank to skip title modification. Use tokens like {meta:first_name}.', 'meta-conductor'),
                'columns'     => 12,
                'args'        => [
                    'placeholder' => __('e.g. {meta:first_name} {meta:last_name}', 'meta-conductor'),
                ],
            ],
            [
                'id'          => 'slug_pattern',
                'type'        => 'text',
                'label'       => __('Slug pattern', 'meta-conductor'),
                'description' => __('Optional. Leave blank to derive slug from computed title.', 'meta-conductor'),
                'columns'     => 12,
                'args'        => [
                    'placeholder' => __('e.g. {pub_year}-{meta:first_name}-{meta:last_name}', 'meta-conductor'),
                ],
            ],
            [
                'id'          => 'slug_mode',
                'type'        => 'select',
                'label'       => __('Slug mode', 'meta-conductor'),
                // NO BARE `{token}` IN A DESCRIPTION. Wireframe interpolates a
                // field's description against the row's own values before it
                // renders (`/\{(\w+)\}/g`), and an unmatched name is replaced
                // with the empty string — so `{default_slug}` here rendered as
                // "…when the pattern contains ." A token carrying a colon
                // (`{meta:x}`) is not `\w+` and survives, which is why the other
                // descriptions are unaffected. `placeholder` and the `html`
                // field's `content` are passed through raw and are safe. H12
                // fails on any bare `{word}` in a description on either config.
                'description' => __('How the slug pattern combines with the default slug. Only used when a slug pattern is set. Automatically forced to Replace when the pattern contains the default-slug token.', 'meta-conductor'),
                'default'     => 'prefix',
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        'prefix'  => __('Prefix — pattern-default-slug', 'meta-conductor'),
                        'suffix'  => __('Suffix — default-slug-pattern', 'meta-conductor'),
                        'replace' => __('Replace — pattern only', 'meta-conductor'),
                    ],
                ],
            ],
            [
                'id'          => 'slug_escalation',
                'type'        => 'text',
                'label'       => __('Collision avoidance — tokens to add', 'meta-conductor'),
                // No bare word-in-braces in a description (H12 fails it): the
                // tokens are listed in the html table below, which is not interpolated.
                'description' => __('Optional. When a generated slug collides with an existing post, add these one at a time, in order, until the slug is free; WordPress numbering is the last resort. Leave blank to use WordPress numbering only. A date adds its next finer part each time (year, month, day, hour, minute) beside the date already in the slug, or at the start when the slug shows none. Warning: a modified-date token makes the slug depend on the last save, so order it last.', 'meta-conductor'),
                'columns'     => 12,
                'args'        => [
                    'placeholder' => '{pub_year}',
                ],
            ],
            [
                'id'      => 'slug_escalation_tokens',
                'type'    => 'html',
                'columns' => 12,
                'args'    => [
                    'variant' => 'info',
                    'content' => self::escalation_tokens_html(),
                ],
            ],
            [
                'id'          => 'escalation_placement',
                'type'        => 'select',
                'label'       => __('Collision avoidance — where to add them', 'meta-conductor'),
                'description' => __('Where parts other than dates go. A date always goes beside the date already in the slug; with none there, it follows this setting too.', 'meta-conductor'),
                'default'     => 'suffix',
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        'suffix' => __('Suffix — slug-added', 'meta-conductor'),
                        'prefix' => __('Prefix — added-slug', 'meta-conductor'),
                    ],
                ],
            ],
            [
                'id'          => 'flat_urls',
                'type'        => 'toggle',
                'label'       => __('Flat URLs', 'meta-conductor'),
                // No bare word-in-braces here: H12 fails the description lint.
                'description' => __('Hierarchical custom post types only; ignored for any other type, and for Pages. Serve child posts at /base/child/ instead of /base/parent/child/, while the parent stays at /base/parent/. The old nested URL redirects (301) to the flat one. Turning this off reverts every URL to the nested form. Names are made unique across the whole type, not per parent: a taken name tries the collision tokens, then is numbered. This applies to posts this rule gives a title or slug pattern. A child named page, feed, embed, attachment or a bare number is numbered. Posts that already share a name resolve to the published one, then the lowest ID.', 'meta-conductor'),
                'default'     => false,
                'columns'     => 12,
            ],
        ];
    }

    /**
     * The *Available tokens* table for the collision-avoidance field. A section
     * `html` field would not do: the token reference above the list is shared
     * by every format rule, and these tokens only mean something here.
     */
    private static function escalation_tokens_html(): string {
        $tok  = static fn(string ...$tokens): string => implode(' / ', array_map(
            static fn(string $t): string => '<code>' . esc_html($t) . '</code>', $tokens
        ));
        $rows = [
            [$tok('{pub_year}'), __('Published date, finer parts follow: {pub_month} … {pub_minute}', 'meta-conductor')],
            [$tok('{mod_year}'), __('Modified date. The slug then depends on the last save', 'meta-conductor')],
            [$tok('{date_year:field}'), __('A date meta field. {date_day:field} skips ahead to the day', 'meta-conductor')],
            [$tok('{parent:slug}'), __('The immediate parent\'s slug', 'meta-conductor')],
            [$tok('{term:taxonomy}'), __('First term\'s slug', 'meta-conductor')],
            [$tok('{meta:field_name}'), __('A meta field value', 'meta-conductor')],
        ];

        $html = '<details><summary style="cursor:pointer;"><strong>' . esc_html__('Available tokens', 'meta-conductor') . '</strong></summary>'
              . '<table class="widefat" style="margin-top:8px;font-size:12px;"><thead><tr><th>' . esc_html__('Token', 'meta-conductor') . '</th><th>' . esc_html__('Adds', 'meta-conductor') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . $row[0] . '</td><td>' . esc_html($row[1]) . '</td></tr>';
        }

        return $html . '</tbody></table></details>';
    }
}
