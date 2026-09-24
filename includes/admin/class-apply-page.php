<?php
/**
 * Apply to existing posts — the author-facing page over the existing-posts
 * applier.
 *
 * @since 0.9.0
 */

namespace BWS\MetaConductor\Admin;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Core\ExistingPostsApplier;
use BWS\MetaConductor\Core\RuleChoice;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * A second Wireframe page under the Meta Conductor menu: a rule dropdown, an
 * optional limit, and Preview / Apply / Continue / Start over.
 *
 * A THIN LAYER. Each button's action filter decodes the values the button
 * posts, calls `ExistingPostsApplier`, and renders what it returns. Which
 * posts, which rows, the stale check and the off switches are all the
 * applier's — so a later entry point (FW-31's in-row buttons) gets the same
 * answers without going through this page.
 *
 * IN-FLIGHT VALUES, NEVER THE STORED ONES. Wireframe shows Save on any page
 * with editable fields (hiding it is wp-wireframe#36), so this page has its
 * own option key and Save persists only the dropdown and the limit. A run
 * reads what the button posted; saving here changes no rule.
 *
 * CONTINUE IS APPLY WITHOUT THE CONFIRM, AND ONLY FOR A RUN ALREADY STARTED.
 * The confirm guards the first write; asking again every batch would train
 * the author to click through it. So Continue refuses when no run of the
 * choice is in progress, and the only way to start one is past the confirm.
 */
final class ApplyPage {

    /** Wireframe page id — the middle of every action filter name. */
    public const PAGE_ID = 'apply';

    /** This page's own option. Never `bws_meta_conductor_settings`. */
    public const OPTION_KEY = 'bws_meta_conductor_apply';

    /** The action field whose buttons this page answers. */
    private const ACTION_FIELD = 'run';

    /**
     * Register one action filter per button.
     *
     * Called from `WireframeBootstrap::init()`. Filter names follow
     * Wireframe's `{prefix}/action/{pageId}/{fieldId}/{actionId}` shape (the
     * `CollisionDetector::init()` pattern, in multi-button mode).
     */
    public static function init(): void {
        foreach (['preview', 'apply', 'continue', 'start_over'] as $button) {
            add_filter(
                'bws-meta-conductor/action/' . self::PAGE_ID . '/' . self::ACTION_FIELD . '/' . $button,
                function ($unhandled, $values = []) use ($button) {
                    return self::act($button, is_array($values) ? $values : []);
                },
                10,
                2
            );
        }
    }

    /**
     * The entry for `WireframeBootstrap::boot()`'s `pages[]`.
     *
     * Built per request from the stored kind lists, so the dropdown on a
     * page load names the rows as they are now — and the fingerprint in each
     * value is what lets the applier refuse a click after they change.
     *
     * @return array
     */
    public static function page(): array {
        $storage = StorageFactory::get_instance();

        return [
            'id'         => self::PAGE_ID,
            'option_key' => self::OPTION_KEY,
            'page_title' => __('Apply to existing posts', 'meta-conductor'),
            'menu_title' => __('Apply to existing posts', 'meta-conductor'),
            'menu_slug'  => ConfigHelpers::APPLY_PAGE_SLUG,
            'parent'     => 'meta-conductor',
            'config'     => self::config(self::choice_options(
                $storage->get_kind_rules(OptionRuleStorage::KIND_TERM),
                $storage->get_kind_rules(OptionRuleStorage::KIND_FORMAT)
            )),
        ];
    }

    /**
     * Dropdown options: a placeholder, "All enabled rules", then every row of
     * the term list and every row of the format list, in authored order.
     *
     * Grouped by a kind prefix rather than `<optgroup>`: Wireframe's select
     * takes a flat map. Each label is the row's collapsed-repeater title with
     * its position and disabled marker re-derived from the row itself — the
     * stored title is a save-time snapshot, and a fixture or CLI write can
     * leave it naming a position or state the row no longer has.
     *
     * @param array[] $term_rows   Projected term kind list, UNFILTERED.
     * @param array[] $format_rows Projected format kind list, UNFILTERED.
     * @return array<string,string> `RuleChoice` value => label.
     */
    public static function choice_options(array $term_rows, array $format_rows): array {
        $options = [
            ''                       => __('— Choose a rule —', 'meta-conductor'),
            RuleChoice::ALL_ENABLED => __('All enabled rules', 'meta-conductor'),
        ];

        foreach ([
            OptionRuleStorage::KIND_TERM   => [__('Term rules', 'meta-conductor'), $term_rows],
            OptionRuleStorage::KIND_FORMAT => [__('Format rules', 'meta-conductor'), $format_rows],
        ] as $kind => [$group, $rows]) {
            foreach (array_values($rows) as $position => $row) {
                // The snapshot is escaped HTML; the select renders text.
                $title = html_entity_decode((string) ($row['row_title'] ?? ''), ENT_QUOTES, 'UTF-8');
                $title = preg_replace('/^#\d+ /', '', $title);
                $title = self::strip_prefix($title, html_entity_decode(__('[Disabled] ', 'meta-conductor'), ENT_QUOTES, 'UTF-8'));
                if (trim($title) === '') {
                    $title = (string) ($row['type'] ?? '');
                }

                $options[RuleChoice::encode($kind, $position, $row)] = sprintf(
                    '%s: #%d %s%s',
                    $group,
                    $position + 1,
                    ($row['enabled'] ?? true) === true ? '' : __('(disabled)', 'meta-conductor') . ' ',
                    $title
                );
            }
        }

        return $options;
    }

    /**
     * @param string $text
     * @param string $prefix
     * @return string
     */
    private static function strip_prefix(string $text, string $prefix): string {
        return str_starts_with($text, $prefix) ? substr($text, strlen($prefix)) : $text;
    }

    /**
     * @param array<string,string> $options choice_options().
     * @return array Wireframe page config.
     */
    private static function config(array $options): array {
        return [
            'title'    => __('Apply to existing posts', 'meta-conductor'),
            'subtitle' => __('Run the rules over posts that already exist, as re-saving each one would.', 'meta-conductor'),
            'sections' => [
                [
                    'id'          => 'apply',
                    'title'       => __('Apply a rule', 'meta-conductor'),
                    'description' => __('A run passes every enabled rule, in your order, over the posts the chosen rule can reach — published, draft, private and scheduled, never trashed. Choosing a disabled rule runs it once, at its place in the order, without enabling it.', 'meta-conductor'),
                    'fields'      => [
                        [
                            'id'      => 'choice',
                            'type'    => 'select',
                            'label'   => __('Rule', 'meta-conductor'),
                            'default' => '',
                            'columns' => 8,
                            'args'    => [
                                'options'      => $options,
                                // A stale value must reach the applier, whose
                                // refusal names the cause; validating against
                                // this request's options would blank it into
                                // "choose a rule" instead.
                                'allow_custom' => true,
                            ],
                        ],
                        [
                            'id'          => 'limit',
                            'type'        => 'number',
                            'label'       => __('Limit', 'meta-conductor'),
                            'description' => __('Stop after this many posts. Empty or 0 for all. A run keeps the limit it started with.', 'meta-conductor'),
                            'default'     => 0,
                            'columns'     => 4,
                            'args'        => ['integer' => true, 'min' => 0],
                        ],
                        [
                            'id'      => self::ACTION_FIELD,
                            'type'    => 'action',
                            'label'   => '',
                            'columns' => 12,
                            'args'    => [
                                'buttons' => [
                                    ['id' => 'preview', 'label' => __('Preview', 'meta-conductor')],
                                    [
                                        'id'      => 'apply',
                                        'label'   => __('Apply', 'meta-conductor'),
                                        'variant' => 'primary',
                                        'confirm' => [
                                            'title'        => __('Apply to existing posts', 'meta-conductor'),
                                            'message'      => __('This writes to every post in the chosen rule\'s reach and cannot be undone. Take a backup first. If the chosen rule is disabled, this is a one-time run: the rule stays disabled, and later saves neither maintain nor undo what it did.', 'meta-conductor'),
                                            'button_label' => __('Apply', 'meta-conductor'),
                                        ],
                                    ],
                                    ['id' => 'continue', 'label' => __('Continue', 'meta-conductor')],
                                    ['id' => 'start_over', 'label' => __('Start over', 'meta-conductor')],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * One button press.
     *
     * @param string $button preview | apply | continue | start_over.
     * @param array  $values The in-flight values the button posted.
     * @return array `{status, message, html}`.
     */
    public static function act(string $button, array $values): array {
        $value = (string) ($values['choice'] ?? '');
        if ($value === '') {
            return ['status' => 'error', 'message' => __('Choose a rule first.', 'meta-conductor'), 'html' => ''];
        }

        switch ($button) {
            case 'preview':
                $result = ExistingPostsApplier::preview($value);
                return $result['status'] === 'success' ? self::render_preview($result) : self::render_error($result);

            case 'continue':
                if (!ExistingPostsApplier::in_progress($value)) {
                    return ['status' => 'error', 'message' => __('No run of this rule is in progress — use Apply to start one.', 'meta-conductor'), 'html' => ''];
                }
                // A run keeps its own limit; the one posted is ignored.
                // Fall through.
            case 'apply':
                $result = ExistingPostsApplier::run_batch($value, max(0, (int) ($values['limit'] ?? 0)));
                return $result['status'] === 'success' ? self::render_batch($result) : self::render_error($result);

            case 'start_over':
                ExistingPostsApplier::start_over($value);
                return ['status' => 'success', 'message' => __('Run reset — the next Apply starts from the first post.', 'meta-conductor'), 'html' => ''];
        }

        return ['status' => 'error', 'message' => __('Unknown action.', 'meta-conductor'), 'html' => ''];
    }

    /**
     * @param array $result An applier error.
     * @return array
     */
    private static function render_error(array $result): array {
        return ['status' => 'error', 'message' => $result['message'], 'html' => '<p>' . esc_html($result['message']) . '</p>'];
    }

    /**
     * @param array $result `ExistingPostsApplier::preview()` success.
     * @return array
     */
    private static function render_preview(array $result): array {
        $html = '<p>' . esc_html($result['message']) . ' ' . esc_html__('Preview writes nothing.', 'meta-conductor') . '</p>';

        if ($result['posts'] !== []) {
            $html .= '<p>' . esc_html__('Newest posts in reach (a term rule cannot be dry-run):', 'meta-conductor') . '</p><ul>';
            foreach ($result['posts'] as $post) {
                $html .= '<li>' . self::post_link($post['post_id'], $post['title'], $post['edit_link']) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($result['sample'] !== []) {
            $html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__('Post', 'meta-conductor') . '</th><th>'
                . esc_html__('Title', 'meta-conductor') . '</th><th>' . esc_html__('Slug', 'meta-conductor') . '</th></tr></thead><tbody>';
            foreach ($result['sample'] as $row) {
                $html .= '<tr><td>' . esc_html('#' . $row['post_id']) . '</td><td>' . self::change($row['title'])
                    . '</td><td>' . self::change($row['slug']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if ($result['note'] !== '') {
            $html .= '<p><em>' . esc_html($result['note']) . '</em></p>';
        }

        return ['status' => 'success', 'message' => $result['message'], 'html' => $html];
    }

    /**
     * @param array $result `ExistingPostsApplier::run_batch()` success.
     * @return array
     */
    private static function render_batch(array $result): array {
        $html = '<p><strong>' . esc_html($result['message']) . '</strong> '
            . esc_html($result['done']
                ? __('Run complete.', 'meta-conductor')
                : __('Press Continue for the next batch.', 'meta-conductor'))
            . '</p>';

        if ($result['rows'] !== []) {
            $html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__('Post', 'meta-conductor') . '</th><th>'
                . esc_html__('Changes', 'meta-conductor') . '</th></tr></thead><tbody>';
            foreach ($result['rows'] as $row) {
                $lines = [];
                if ($row['title'] !== null) {
                    $lines[] = esc_html__('Title:', 'meta-conductor') . ' ' . self::change($row['title']);
                }
                if ($row['slug'] !== null) {
                    $lines[] = esc_html__('Slug:', 'meta-conductor') . ' ' . self::change($row['slug']);
                }
                foreach ($row['terms'] as $taxonomy => $delta) {
                    $parts = [];
                    if ($delta['added'] !== []) {
                        $parts[] = '+ ' . esc_html(implode(', ', $delta['added']));
                    }
                    if ($delta['removed'] !== []) {
                        $parts[] = '− ' . esc_html(implode(', ', $delta['removed']));
                    }
                    $lines[] = esc_html($taxonomy) . ': ' . implode('; ', $parts);
                }
                $html .= '<tr><td>' . self::post_link($row['post_id'], get_the_title($row['post_id']), (string) get_edit_post_link($row['post_id'], 'raw'))
                    . '</td><td>' . implode('<br>', $lines) . '</td></tr>';
            }
            $html .= '</tbody></table>';

            if ($result['changed'] > count($result['rows'])) {
                $html .= '<p>' . esc_html(sprintf(
                    /* translators: %d: changed posts listed */
                    __('Only the first %d changed posts are listed.', 'meta-conductor'),
                    count($result['rows'])
                )) . '</p>';
            }
        }

        $html .= '<p><em>' . esc_html($result['note']) . '</em></p>';

        return ['status' => $result['done'] ? 'success' : 'info', 'message' => $result['message'], 'html' => $html];
    }

    /**
     * @param array{0:string,1:string} $pair Before, after.
     * @return string Escaped "before → after", or the value when unchanged.
     */
    private static function change(array $pair): string {
        return $pair[0] === $pair[1] ? esc_html($pair[0]) : esc_html($pair[0]) . ' &rarr; <strong>' . esc_html($pair[1]) . '</strong>';
    }

    /**
     * @param int    $post_id
     * @param string $title
     * @param string $edit_link '' when the user cannot edit it.
     * @return string Escaped.
     */
    private static function post_link(int $post_id, string $title, string $edit_link): string {
        $label = esc_html(($title !== '' ? $title : __('(no title)', 'meta-conductor')) . ' #' . $post_id);

        return $edit_link !== '' ? '<a href="' . esc_url($edit_link) . '">' . $label . '</a>' : $label;
    }
}
