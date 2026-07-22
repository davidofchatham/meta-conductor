<?php
/**
 * Title & Slug pattern rules config.
 *
 * Preview + Apply-to-Existing action buttons are deferred until Wireframe
 * exposes a client-side field-type extension API. For now, settings save
 * normally; Preview/Apply can be invoked from a separate utility page or
 * via the existing AJAX endpoints.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class TitleSlugConfig {

    public static function section(): array {
        return [
            'id'          => 'title_slug',
            'title'       => __('Title & slug patterns', 'meta-conductor'),
            'description' => __('Generate post titles and slugs from a pattern of tokens (meta fields, dates, terms).', 'meta-conductor'),
            'fields'      => [
                [
                    'id'    => 'title_slug_rules',
                    'type'  => 'repeater',
                    'label' => __('Title & slug rules', 'meta-conductor'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add title/slug rule', 'meta-conductor'),
                        'empty_message'  => __('No title/slug rules configured.', 'meta-conductor'),
                        'title_template' => '{name}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'meta-conductor'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            [
                                'id'       => 'name',
                                'type'     => 'text',
                                'label'    => __('Rule name', 'meta-conductor'),
                                'required' => true,
                                'columns'  => 12,
                                'args'     => [
                                    'placeholder' => __('e.g. Personnel Title & Slug', 'meta-conductor'),
                                ],
                            ],
                            [
                                'id'       => 'post_type',
                                'type'     => 'select',
                                'label'    => __('Post type', 'meta-conductor'),
                                'default'  => '',
                                'required' => true,
                                'columns'  => 12,
                                'args'     => [
                                    'options' => self::post_type_options_no_attachment(),
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
                                'id'      => 'token_reference',
                                'type'    => 'html',
                                'columns' => 12,
                                'args'    => [
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
                                'description' => __('How the slug pattern combines with the default slug. Only used when a slug pattern is set. Automatically forced to Replace when the pattern contains {default_slug}.', 'meta-conductor'),
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
                        ],
                    ],
                ],
                [
                    'id'      => 'title_slug_actions_note',
                    'type'    => 'html',
                    'columns' => 12,
                    'args'    => [
                        'variant' => 'info',
                        'content' => '<p><strong>' . esc_html__('Preview & Apply to Existing Posts:', 'meta-conductor') . '</strong> '
                                   . esc_html__('Coming as part of the unified Migration / Preview tool. Active rules still apply automatically when posts are saved.', 'meta-conductor') . '</p>',
                    ],
                ],
            ],
        ];
    }

    /**
     * Public post types minus attachment (title/slug rules don't apply to media).
     */
    private static function post_type_options_no_attachment(): array {
        $options    = ['' => __('— Select post type —', 'meta-conductor')];
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        foreach ($post_types as $post_type) {
            $options[$post_type->name] = $post_type->label;
        }

        return $options;
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
