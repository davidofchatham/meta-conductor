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
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Title_Slug_Config {

    public static function section(): array {
        return [
            'id'          => 'title_slug',
            'title'       => __('Title & slug patterns', 'bws-meta-manager'),
            'description' => __('Generate post titles and slugs from a pattern of tokens (meta fields, dates, terms).', 'bws-meta-manager'),
            'fields'      => [
                [
                    'id'    => 'title_slug_rules',
                    'type'  => 'repeater',
                    'label' => __('Title & slug rules', 'bws-meta-manager'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add title/slug rule', 'bws-meta-manager'),
                        'empty_message'  => __('No title/slug rules configured.', 'bws-meta-manager'),
                        'title_template' => '{name}',
                        'subfields'      => [
                            [
                                'id'      => 'enabled',
                                'type'    => 'toggle',
                                'label'   => __('Enabled', 'bws-meta-manager'),
                                'default' => true,
                                'columns' => 12,
                            ],
                            [
                                'id'       => 'name',
                                'type'     => 'text',
                                'label'    => __('Rule name', 'bws-meta-manager'),
                                'required' => true,
                                'columns'  => 12,
                                'args'     => [
                                    'placeholder' => __('e.g. Personnel Title & Slug', 'bws-meta-manager'),
                                ],
                            ],
                            [
                                'id'       => 'post_type',
                                'type'     => 'select',
                                'label'    => __('Post type', 'bws-meta-manager'),
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
                                'label'       => __('Title pattern', 'bws-meta-manager'),
                                'description' => __('Optional. Leave blank to skip title modification. Use tokens like {meta:first_name}.', 'bws-meta-manager'),
                                'columns'     => 12,
                                'args'        => [
                                    'placeholder' => __('e.g. {meta:first_name} {meta:last_name}', 'bws-meta-manager'),
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
                                'label'       => __('Slug pattern', 'bws-meta-manager'),
                                'description' => __('Optional. Leave blank to derive slug from computed title.', 'bws-meta-manager'),
                                'columns'     => 12,
                                'args'        => [
                                    'placeholder' => __('e.g. {pub_year}-{meta:first_name}-{meta:last_name}', 'bws-meta-manager'),
                                ],
                            ],
                            [
                                'id'          => 'slug_mode',
                                'type'        => 'select',
                                'label'       => __('Slug mode', 'bws-meta-manager'),
                                'description' => __('How the slug pattern combines with the default slug. Only used when a slug pattern is set.', 'bws-meta-manager'),
                                'default'     => 'prefix',
                                'columns'     => 12,
                                'args'        => [
                                    'options' => [
                                        'prefix'  => __('Prefix — pattern-default-slug', 'bws-meta-manager'),
                                        'suffix'  => __('Suffix — default-slug-pattern', 'bws-meta-manager'),
                                        'replace' => __('Replace — pattern only', 'bws-meta-manager'),
                                    ],
                                ],
                            ],
                            [
                                'id'          => 'date_escalation',
                                'type'        => 'toggle',
                                'label'       => __('Collision avoidance via date escalation', 'bws-meta-manager'),
                                'description' => __('When a generated slug collides with an existing post, append progressively more date precision (year → month → day → hour → minute) before falling back to WordPress unique-slug suffixes.', 'bws-meta-manager'),
                                'default'     => false,
                                'columns'     => 12,
                            ],
                            [
                                'id'          => 'date_field',
                                'type'        => 'text',
                                'label'       => __('Date field for collision avoidance', 'bws-meta-manager'),
                                'description' => __('Optional. Meta field key to read dates from. Leave blank to use publication date. Only used when collision avoidance is on.', 'bws-meta-manager'),
                                'columns'     => 12,
                                'args'        => [
                                    'placeholder' => __('e.g. event_date', 'bws-meta-manager'),
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
                        'content' => '<p><strong>' . esc_html__('Preview & Apply to Existing Posts:', 'bws-meta-manager') . '</strong> '
                                   . esc_html__('Coming as part of the unified Migration / Preview tool. Active rules still apply automatically when posts are saved.', 'bws-meta-manager') . '</p>',
                    ],
                ],
            ],
        ];
    }

    /**
     * Public post types minus attachment (title/slug rules don't apply to media).
     */
    private static function post_type_options_no_attachment(): array {
        $options    = ['' => __('— Select post type —', 'bws-meta-manager')];
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
            ['<code>{meta:field_name}</code>', __('Raw field value', 'bws-meta-manager'), __('Sanitized value', 'bws-meta-manager')],
            ['<code>{default_title}</code>',   __('Title before this rule runs', 'bws-meta-manager'), '—'],
            ['<code>{default_slug}</code>',    '—', __('Slug derived from computed title', 'bws-meta-manager')],
            ['<code>{date_year:field}</code>', '2024', '2024'],
            ['<code>{date_month:field}</code>', __('March', 'bws-meta-manager'), '03'],
            ['<code>{date_day:field}</code>', '15', '15'],
            ['<code>{date_hour:field}</code>', '14', '14'],
            ['<code>{date_minute:field}</code>', '30', '30'],
            ['<code>{pub_year}</code>', __('2024 (publication date, local time)', 'bws-meta-manager'), '2024'],
            ['<code>{pub_month}</code>', __('March', 'bws-meta-manager'), '03'],
            ['<code>{pub_day} / {pub_hour} / {pub_minute}</code>', __('Same numeric pattern as above', 'bws-meta-manager'), ''],
            ['<code>{term:taxonomy}</code>', __('First term name (alpha)', 'bws-meta-manager'), __('First term slug', 'bws-meta-manager')],
            ['<code>{terms:taxonomy}</code>', __('All term names, comma-joined', 'bws-meta-manager'), __('All slugs, hyphen-joined', 'bws-meta-manager')],
        ];

        $html  = '<details><summary style="cursor:pointer;"><strong>' . esc_html__('Available tokens', 'bws-meta-manager') . '</strong></summary>';
        $html .= '<table class="widefat" style="margin-top:8px;font-size:12px;"><thead><tr><th>' . esc_html__('Token', 'bws-meta-manager') . '</th><th>' . esc_html__('Title output', 'bws-meta-manager') . '</th><th>' . esc_html__('Slug output', 'bws-meta-manager') . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td>' . $row[0] . '</td><td>' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
        }

        $html .= '</tbody></table></details>';
        return $html;
    }
}
