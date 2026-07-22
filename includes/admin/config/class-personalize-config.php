<?php
/**
 * Personalize tab — user-driven rules (auto-set + restrict by user role/ID).
 *
 * Placeholder. Real rule types land when BWS User Based Terms is absorbed.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class PersonalizeConfig {

    public static function tab(): array {
        return [
            'id'       => 'personalize',
            'title'    => __('Personalize by User', 'meta-conductor'),
            'sections' => [
                [
                    'id'          => 'personalize_intro',
                    'title'       => __('User-driven term rules', 'meta-conductor'),
                    'description' => __('Rules whose trigger or constraint depends on the current user — role, capability, or specific user ID.', 'meta-conductor'),
                    'fields'      => [
                        [
                            'id'      => 'personalize_intro_html',
                            'type'    => 'html',
                            'columns' => 12,
                            'args'    => [
                                'variant' => 'info',
                                'content' => '<p>' . esc_html__('User-based rule types are planned but not yet implemented.', 'meta-conductor') . '</p>'
                                           . '<p>' . esc_html__('Coming: auto-set terms by user role, lock taxonomies to specific roles, pre-select terms for specific users.', 'meta-conductor') . '</p>',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
