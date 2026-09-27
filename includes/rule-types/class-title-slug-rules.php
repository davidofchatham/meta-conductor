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
                'id'         => 'token_reference',
                'type'       => 'html',
                'columns'    => 12,
                'args'       => [
                    'variant' => 'info',
                    'content' => self::token_reference_html(),
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
                'id'          => 'date_escalation',
                'type'        => 'toggle',
                'label'       => __('Collision avoidance via date escalation', 'meta-conductor'),
                'description' => __('When a generated slug collides with an existing post, append progressively more date precision (year → month → day → hour → minute) before falling back to WordPress unique-slug suffixes.', 'meta-conductor'),
                'default'     => false,
                'columns'     => 12,
            ],
            [
                'id'          => 'date_field',
                'type'        => 'text',
                'label'       => __('Date field for collision avoidance', 'meta-conductor'),
                'description' => __('Optional. Meta field key to read dates from. Leave blank to use publication date. Only used when collision avoidance is on.', 'meta-conductor'),
                'columns'     => 12,
                'args'        => [
                    'placeholder' => __('e.g. event_date', 'meta-conductor'),
                ],
            ],
        ];
    }

    /**
     * Token reference HTML — multi-row reference table.
     */
    private static function token_reference_html(): string {
        $rows = [
            ['<code>{meta:field_name}</code>', __('Raw field value', 'meta-conductor'), __('Sanitized value', 'meta-conductor')],
            ['<code>{default_title}</code>',   __('Title before this rule runs', 'meta-conductor'), '—'],
            ['<code>{default_slug}</code>',    '—', __('Slug derived from computed title', 'meta-conductor')],
            ['<code>{date_year:field}</code>', '2024', '2024'],
            ['<code>{date_month:field}</code>', __('March', 'meta-conductor'), '03'],
            ['<code>{date_day:field}</code>', '15', '15'],
            ['<code>{date_hour:field}</code>', '14', '14'],
            ['<code>{date_minute:field}</code>', '30', '30'],
            ['<code>{pub_year}</code>', __('2024 (publication date, local time)', 'meta-conductor'), '2024'],
            ['<code>{pub_month}</code>', __('March', 'meta-conductor'), '03'],
            ['<code>{pub_day} / {pub_hour} / {pub_minute}</code>', __('Same numeric pattern as above', 'meta-conductor'), ''],
            ['<code>{term:taxonomy}</code>', __('First term name (alpha)', 'meta-conductor'), __('First term slug', 'meta-conductor')],
            ['<code>{terms:taxonomy}</code>', __('All term names, comma-joined', 'meta-conductor'), __('All slugs, hyphen-joined', 'meta-conductor')],
        ];

        $html  = '<details><summary style="cursor:pointer;"><strong>' . esc_html__('Available tokens', 'meta-conductor') . '</strong></summary>';
        $html .= '<table class="widefat" style="margin-top:8px;font-size:12px;"><thead><tr><th>' . esc_html__('Token', 'meta-conductor') . '</th><th>' . esc_html__('Title output', 'meta-conductor') . '</th><th>' . esc_html__('Slug output', 'meta-conductor') . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td>' . $row[0] . '</td><td>' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
        }

        $html .= '</tbody></table></details>';
        return $html;
    }
}
