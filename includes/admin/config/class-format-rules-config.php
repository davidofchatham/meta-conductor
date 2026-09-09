<?php
/**
 * The ordered format-rule list — one repeater for every rule whose effect is
 * "how a post's own fields read" (ADR 0003 decision 1, spec #53 §2,
 * ticket #59).
 *
 * The `term_rules` collapse's smaller twin. `title_slug` is the only member of
 * the `format_rules` kind today, so there is no condition-gating pressure yet
 * — and that is precisely why the shape is established now: when
 * `field_transformation` lands it is a row type added to an existing list, not
 * a tab restructured around a second section.
 *
 * ## The hazard this file is written around
 *
 * The same one `TermRulesConfig` is written around, and it is not lessened by
 * there being one type. Wireframe DROPS a condition-hidden subfield at
 * sanitize (`RepeaterField::sanitize`, and `Validator::validateRepeater` skips
 * it too), so a wrong `conditions` clause is SILENT DATA LOSS rather than a
 * rendering bug. `tests/verify-format-rules-config.php` (H12) locks the static
 * half through Wireframe's REAL `Conditions::evaluate()`; the dynamic half is
 * `tools/fixtures/mc-rules/sweep-59-roundtrip.php` on the testbed.
 *
 * With one type the failure that matters most is the *ungated* one: a shared
 * subfield is shared with nothing yet, so an accidental gate on `post_type` or
 * `name` would drop it for the only type there is. H12 asserts the shared
 * frame carries no `conditions` key at all.
 *
 * ## What is shared and what is gated
 *
 * Shared frame: `type`, `enabled`, `name`, `post_type`, `row_title`. Every one
 * of them is a question any format rule has to answer — which rule is this,
 * does it run, what is it called, what does it act on. Gated to
 * `title_slug_rules`: the patterns, the slug mode, and the collision-avoidance
 * pair, which are the mechanics of *this* transformation and mean nothing to
 * the next one.
 *
 * `post_type` is a SCALAR select here, not the shared `post_types` checkboxes
 * the term list uses, because the format pass is first-match-wins per rule type
 * (`TitleSlugHandler::rule_matches()`, #64) on one post type — a rule set is a lookup table keyed by
 * post type, not a set of independently-scoped rules. Unifying the two gates
 * is a handler change, deliberately out of #59's scope (it is a move, and the
 * ticket asks that stored rules survive it intact).
 *
 * The stored `type` value is the LEGACY TYPE KEY VERBATIM (`title_slug_rules`,
 * not `title_slug`), for the same reason it is in the term list: rule-type
 * renaming stays deferred (ADR 0002/0003), and reusing the key is what lets
 * `get_enabled_rules()` filter on `get_rule_type()` with no mapping table.
 *
 * Preview + Apply-to-Existing action buttons remain deferred until Wireframe
 * exposes a client-side field-type extension API (CLAUDE.md don't #5).
 *
 * @package BWS_Meta_Manager
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Admin\Config;

use BWS\MetaConductor\Admin\CollisionDetector;
use BWS\MetaConductor\Storage\OptionRuleStorage;

if (!defined('ABSPATH')) {
    exit;
}

class FormatRulesConfig {

    /**
     * Author-facing label for each rule type the repeater offers.
     *
     * Keyed by the stored legacy type key. Every format-kind type in
     * `OptionRuleStorage::migrated_types_for_kind()` must appear here — H12
     * asserts the two agree, so adding a type to storage without labelling it
     * fails the harness rather than rendering an unlabelled option.
     *
     * @return array<string,string>
     */
    private static function type_labels(): array {
        return [
            'title_slug_rules' => __('Title & slug pattern — build the title and slug from tokens', 'meta-conductor'),
        ];
    }

    /**
     * The rule types this repeater authors, in the order storage lists them.
     *
     * @return string[]
     */
    public static function types(): array {
        return array_values(array_intersect(
            OptionRuleStorage::migrated_types_for_kind(OptionRuleStorage::KIND_FORMAT),
            array_keys(self::type_labels())
        ));
    }

    /**
     * `type` select options: stored legacy key => author-facing label.
     *
     * Carries the leading empty placeholder even though there is one real
     * option, and `required` still rejects a save that leaves it there. Not
     * ceremony: a row whose `type` defaulted to the only type would look
     * configured the moment it was added, and — more to the point — the day a
     * second type joins, every row added in the meantime would already claim
     * to be a title/slug rule. Untyped-until-chosen is the shape that keeps
     * working when the list stops being a list of one.
     *
     * @return array<string,string>
     */
    public static function type_options(): array {
        $labels  = self::type_labels();
        $options = ['' => __('— Select rule type —', 'meta-conductor')];

        foreach (self::types() as $type) {
            $options[$type] = $labels[$type];
        }

        return $options;
    }

    /**
     * A `conditions` clause restricting a subfield to the given rule types.
     *
     * Always `in` with an explicit list, never `not_in` — same reasoning as
     * `TermRulesConfig::only()`, and it bites harder here: with one type a
     * `not_in` gate is indistinguishable from no gate at all, and would
     * silently expose every one of these subfields to `field_transformation`
     * the moment it is added.
     *
     * @param string ...$types Legacy type keys the subfield belongs to.
     */
    private static function only(string ...$types): array {
        return [
            'field'    => 'type',
            'operator' => 'in',
            'value'    => $types,
        ];
    }

    /**
     * The Format & Transform tab: the ordered format-rule list.
     */
    public static function tab(): array {
        return [
            'id'       => 'format-transform',
            'title'    => __('Format & Transform', 'meta-conductor'),
            'sections' => [
                // Same collision advisory the term tab leads with (#65), from
                // the same builder. On this kind it reports the one thing the
                // `post_type` description already warns about in prose: two
                // rules on one post type, where the lower never runs.
                CollisionDetector::section(OptionRuleStorage::KIND_FORMAT),
                self::section(),
            ],
        ];
    }

    /**
     * The ordered format-rule list section.
     */
    public static function section(): array {
        return [
            'id'          => 'format_rules',
            'title'       => __('Format rules, in order', 'meta-conductor'),
            'description' => __('Every rule that rewrites a post\'s own fields, in the order they run. Drag a rule up to let it act before the ones below it.', 'meta-conductor'),
            'fields'      => [
                [
                    'id'    => OptionRuleStorage::KIND_FORMAT,
                    'type'  => 'repeater',
                    'label' => __('Format rules', 'meta-conductor'),
                    'args'  => [
                        'sortable'       => true,
                        'collapsible'    => true,
                        'collapsed'      => true,
                        'duplicate_row'  => true,
                        'add_label'      => __('Add format rule', 'meta-conductor'),
                        'empty_message'  => __('No format rules configured.', 'meta-conductor'),
                        'title_template' => '{row_title}',
                        'subfields'      => self::subfields(),
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
     * The row's subfields: the shared frame, then the title/slug group, then
     * the row-title snapshot.
     *
     * Order matters to the author, not to storage — `type` leads because
     * nothing else in the row means anything until it is picked.
     *
     * @return array[]
     */
    private static function subfields(): array {
        return array_merge(
            self::shared_subfields(),
            self::title_slug_subfields(),
            [
                [
                    // Snapshot row title, assembled at save by
                    // WireframeBootstrap::snapshot_format_rule_labels. Declared
                    // so {row_title} resolves, and declared ONCE for every
                    // type, which is what gives the list a uniform
                    // "[Disabled] " prefix like the term list has.
                    //
                    // The old per-type repeater interpolated `{name}` live.
                    // That worked while `name` was the whole title; it cannot
                    // carry the post-type scope, the disabled marker, or a
                    // second rule type's schema, and adding the snapshot later
                    // would be exactly the restructure #59 exists to avoid.
                    'id'      => 'row_title',
                    'type'    => 'hidden',
                    'default' => '',
                ],
            ]
        );
    }

    /**
     * The shared frame: what every format rule answers, whatever it does.
     *
     * All UNGATED, and H12 asserts it. With one rule type a gate here is not
     * a narrowing, it is a deletion — the subfield would evaluate false for
     * the only type there is and stop persisting entirely.
     *
     * @return array[]
     */
    private static function shared_subfields(): array {
        return [
            [
                'id'          => 'type',
                'type'        => 'select',
                'label'       => __('Rule type', 'meta-conductor'),
                // Same warning the term list carries, next to the control that
                // causes it. It is not yet reachable — one type means there is
                // nothing to change to — but the wording is the row's contract,
                // not a description of today's option count.
                'description' => __('What this rule does. Changing it clears the settings that belonged to the old type — they are not kept in the background.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => self::type_options(),
                ],
            ],
            [
                'id'      => 'enabled',
                'type'    => 'toggle',
                'label'   => __('Enabled', 'meta-conductor'),
                'default' => true,
                'columns' => 12,
            ],
            [
                // The author's own label for the row. The handler never reads
                // it; the row-title snapshot does, which is what makes it worth
                // keeping required — an unnamed rule in a collapsed,
                // reorderable list is unfindable.
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
                // Scalar, not the shared post_types checkboxes — see the file
                // docblock. `TitleSlugHandler::rule_matches()` reads this key
                // by name and the format pass stops at the FIRST rule of the
                // type matching the post's type, so the value is a lookup key rather than a scope, and
                // two rules on one post type means the lower one never runs.
                'id'          => 'post_type',
                'type'        => 'select',
                'label'       => __('Post type', 'meta-conductor'),
                // Says the thing the old label left implicit, and which the
                // ordered list finally makes visible: order decides the winner.
                'description' => __('The post type this rule acts on. If two rules name the same post type, the higher one wins and the lower one never runs.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => self::post_type_options_no_attachment(),
                ],
            ],
        ];
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
    private static function title_slug_subfields(): array {
        $gate = self::only('title_slug_rules');

        return [
            [
                'id'          => 'title_pattern',
                'type'        => 'text',
                'label'       => __('Title pattern', 'meta-conductor'),
                'description' => __('Optional. Leave blank to skip title modification. Use tokens like {meta:first_name}.', 'meta-conductor'),
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'placeholder' => __('e.g. {meta:first_name} {meta:last_name}', 'meta-conductor'),
                ],
            ],
            [
                'id'         => 'token_reference',
                'type'       => 'html',
                'columns'    => 12,
                'conditions' => $gate,
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
                'conditions'  => $gate,
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
                'conditions'  => $gate,
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
                'conditions'  => $gate,
            ],
            [
                'id'          => 'date_field',
                'type'        => 'text',
                'label'       => __('Date field for collision avoidance', 'meta-conductor'),
                'description' => __('Optional. Meta field key to read dates from. Leave blank to use publication date. Only used when collision avoidance is on.', 'meta-conductor'),
                'columns'     => 12,
                'conditions'  => $gate,
                'args'        => [
                    'placeholder' => __('e.g. event_date', 'meta-conductor'),
                ],
            ],
        ];
    }

    /**
     * Public post types minus attachment (title/slug rules don't apply to media).
     */
    private static function post_type_options_no_attachment(): array {
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        // Shares ConfigHelpers' single slug ⇒ label loop (#38 cluster 1); the
        // attachment filter is this caller's business, which is exactly why
        // that helper takes objects rather than a registry query.
        return ConfigHelpers::label_options(
            $post_types,
            __('— Select post type —', 'meta-conductor')
        );
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
