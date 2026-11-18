<?php
/**
 * Shared option builders for Wireframe config classes.
 *
 * Centralizes the get_taxonomies() / get_post_types() / get_terms()
 * lookups that multiple rule type configs need at boot time.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

use BWS\MetaConductor\Relationships\AcfRelationship;
use BWS\MetaConductor\RuleTypes\FieldFromPatternRules;
use BWS\MetaConductor\RuleTypes\Registry;
use BWS\MetaConductor\Storage\OptionRuleStorage;

if (!defined('ABSPATH')) {
    exit;
}

class ConfigHelpers {

    /**
     * A `when` value (see `gate()`) meaning "the sibling field holds something",
     * not "equals this". No select or radio stores it, so it cannot collide
     * with a real value.
     */
    public const NOT_EMPTY = '@not_empty';

    /**
     * The Apply page's admin slug. Here rather than on ApplyPage so the rule
     * tabs can link to it without config depending on the page controller.
     */
    public const APPLY_PAGE_SLUG = 'meta-conductor-apply';

    /**
     * Notice below an ordered rule list pointing at the Apply page, which
     * is where these rules run over posts that already exist.
     *
     * The href is relative on purpose: both pages live under wp-admin/, and
     * it keeps the config buildable without WP loaded (H11/H12).
     *
     * @param string $id Field id, unique within the section.
     */
    public static function apply_page_note(string $id): array {
        return [
            'id'      => $id,
            'type'    => 'html',
            'columns' => 12,
            'args'    => [
                'variant' => 'info',
                'content' => '<p>' . esc_html__('Rules run automatically when a post is saved. To run them over posts that already exist, use', 'meta-conductor')
                           . ' <a href="' . esc_url('admin.php?page=' . self::APPLY_PAGE_SLUG) . '">' . esc_html__('Apply to Existing Posts', 'meta-conductor') . '</a>.</p>',
            ],
        ];
    }

    /**
     * The token reference, a section-level `html` field above the format
     * repeater so it stays in view while any rule is edited (it used to sit
     * inside the title & slug row, which the Computed field rule never saw).
     *
     * Every `<code>` is click-to-copy: `WireframeBootstrap::enqueue_token_copy()`
     * delegates on `.mc-token`. The text copied is the token verbatim, so
     * `{meta:field_name}` still needs its placeholder replaced.
     */
    public static function token_reference_field(): array {
        $tok = static fn(string ...$tokens): string => implode(' / ', array_map(
            static fn(string $t): string => '<code class="mc-token" style="cursor:pointer;" title="' . esc_attr__('Click to copy', 'meta-conductor') . '">' . esc_html($t) . '</code>',
            $tokens
        ));

        $rows = [
            [$tok('{meta:field_name}'), __('Raw field value', 'meta-conductor'), __('Sanitized value', 'meta-conductor')],
            [$tok('{default_title}'), __('Title before this rule runs', 'meta-conductor'), '—'],
            [$tok('{default_slug}'), '—', __('Slug derived from computed title', 'meta-conductor')],
            [$tok('{date_year:field}'), '2024', '2024'],
            [$tok('{date_month:field}'), __('March', 'meta-conductor'), '03'],
            [$tok('{date_day:field}'), '15', '15'],
            [$tok('{date_hour:field}'), '14', '14'],
            [$tok('{date_minute:field}'), '30', '30'],
            [$tok('{pub_year}'), __('2024 (publication date, local time)', 'meta-conductor'), '2024'],
            [$tok('{pub_month}'), __('March', 'meta-conductor'), '03'],
            [$tok('{pub_day}', '{pub_hour}', '{pub_minute}'), __('Same numeric pattern as above', 'meta-conductor'), ''],
            [$tok('{mod_year}', '{mod_month}', '{mod_day}', '{mod_hour}', '{mod_minute}'), __('Same as the {pub_*} tokens, from the modified date', 'meta-conductor'), ''],
            [$tok('{parent:inner}'), __('The inner token read from the parent post, such as {parent:meta:address} or {parent:slug}; empty with no parent', 'meta-conductor'), __('Same', 'meta-conductor')],
            [$tok('{refs:field:inner}'), __('The inner token read from the first post the ACF field names that has a value, such as {refs:venue:meta:season}', 'meta-conductor'), __('Same', 'meta-conductor')],
            [$tok('{term:taxonomy}'), __('First term name (alpha)', 'meta-conductor'), __('First term slug', 'meta-conductor')],
            [$tok('{terms:taxonomy}'), __('All term names, comma-joined', 'meta-conductor'), __('All slugs, hyphen-joined', 'meta-conductor')],
        ];

        $html  = '<details><summary style="cursor:pointer;"><strong>' . esc_html__('Available tokens', 'meta-conductor') . '</strong></summary>';
        $html .= '<p style="margin:8px 0 0;">' . esc_html__('Click a token to copy it. Rules that set a field use the Title output column; the Slug output column and {default_slug} are for rules that set the title and slug only.', 'meta-conductor') . '</p>';
        $html .= '<table class="widefat" style="margin-top:8px;font-size:12px;"><thead><tr><th>' . esc_html__('Token', 'meta-conductor') . '</th><th>' . esc_html__('Title output', 'meta-conductor') . '</th><th>' . esc_html__('Slug output', 'meta-conductor') . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td>' . $row[0] . '</td><td>' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
        }

        // The html field's content is passed through raw, so the bare slug token
        // is safe here (it is not a description: don't #4b).
        $notes = [
            __('The inner token of a parent or refs token is a meta, term, terms, pub_ or mod_ token, or slug (the related post\'s own slug). A bare {slug} on its own is empty.', 'meta-conductor'),
            __('One hop only: a parent or refs token inside another resolves empty. Values are read as stored on the related post, whatever its status, so a draft or private source reads like a published one. That value can then appear in a public title, slug or field, so do not point a token at a value you want kept private.', 'meta-conductor'),
            __('A refs token names the ACF field by its name alone, and takes the first related post, in the field\'s own order, that has a value (a post that is deleted or in the trash is skipped, and so is a post whose value is a list rather than a single value). Use a field name no other field group shares, since two groups with the same bare name are ambiguous; write a field inside an ACF Group by its group-qualified name.', 'meta-conductor'),
            __('Editing a parent updates its children (and theirs) automatically. Editing a post that a refs token reads does not: the receiving post updates when it is saved, or when you run Apply to Existing Posts, and a bulk run can leave one stale until a second run.', 'meta-conductor'),
            __('A pattern on a repeater subfield resolves both parent and refs tokens empty.', 'meta-conductor'),
        ];
        $html_notes = '<ul style="margin:8px 0 0 18px;list-style:disc;font-size:12px;">'
            . implode('', array_map(static fn(string $n): string => '<li>' . esc_html($n) . '</li>', $notes))
            . '</ul>';

        return [
            'id'      => 'token_reference',
            'type'    => 'html',
            'columns' => 12,
            'args'    => [
                'variant' => 'info',
                'content' => $html . '</tbody></table>' . $html_notes . '</details>',
            ],
        ];
    }

    /**
     * A `conditions` clause restricting a kind-repeater subfield to the given
     * rule types.
     *
     * Always `in` with an explicit list, never `not_in`: a subfield gated by
     * exclusion silently becomes visible on every type added later, and a
     * subfield that is visible where nothing reads it stores junk — whereas a
     * missing `in` entry merely hides a field, which H11/H12 catch. With one
     * type in a kind, a `not_in` gate is indistinguishable from no gate.
     *
     * @param string ...$types Stored type keys the subfield belongs to.
     */
    public static function type_gate(string ...$types): array {
        return [
            'field'    => 'type',
            'operator' => 'in',
            'value'    => $types,
        ];
    }

    /**
     * `type_gate()` plus an optional second dimension: a `[field => value, …]`
     * map, every pair of which must hold (`equals`, or `is_not_empty` when the
     * value is `NOT_EMPTY`, for a control that only means something once a
     * sibling text field holds a value). A type with several
     * relationships gates a control on `relationship` this way, so no
     * hand-written type list appears and a flip of that value drops the control
     * on save exactly as a change of `type` does.
     *
     * With no `$when` this IS `type_gate()`, so a single-dimension gate keeps its
     * flat `field`/`operator`/`value` shape.
     *
     * @param string[]             $types Stored type keys the subfield belongs to.
     * @param array<string,string> $when  Extra value conditions.
     */
    public static function gate(array $types, array $when = []): array {
        $gate = self::type_gate(...$types);
        if (!$when) {
            return $gate;
        }

        $rules = [$gate];
        foreach ($when as $field => $value) {
            $rules[] = $value === self::NOT_EMPTY
                ? ['field' => (string) $field, 'operator' => 'is_not_empty']
                : ['field' => (string) $field, 'operator' => 'equals', 'value' => $value];
        }

        return ['all' => $rules];
    }

    /**
     * The gate for a shared subfield: every type whose descriptor lists it in
     * `reads_shared_fields()`, in the order given.
     *
     * A type missing from a declaration has that value hidden and DROPPED on
     * save (architecture.md → The ordered rule repeaters); H11 / H12's
     * hand-written `$expected_visible` is the independent check.
     *
     * A reader may add a second condition (`shared_field_when()`): the types
     * with none keep ONE `in` gate, and each type with one gets its own `all`
     * clause, the lot joined by `any`. With no extras this is exactly the flat
     * `type in [...]` gate.
     *
     * @param string[] $types Stored type keys of the list, in registry order.
     * @param string   $field Shared subfield id.
     */
    public static function readers(array $types, string $field): array {
        $plain   = [];
        $clauses = [];
        foreach ($types as $type) {
            $descriptor = Registry::get($type);
            if (!in_array($field, $descriptor->reads_shared_fields(), true)) {
                continue;
            }
            $when = $descriptor->shared_field_when($field);
            if ($when) {
                $clauses[] = self::gate([$type], $when);
            } else {
                $plain[] = $type;
            }
        }

        if (!$clauses) {
            return self::type_gate(...$plain);
        }
        if ($plain) {
            array_unshift($clauses, self::type_gate(...$plain));
        }

        return count($clauses) === 1 ? $clauses[0] : ['any' => $clauses];
    }

    /**
     * Every type's own subfields, each gated to that type alone, and further to
     * its `when` if the descriptor declared one.
     *
     * The gate is stamped here, not by the descriptor, so no subfield can be
     * visible on a type that did not declare it (RuleType::subfields()). `when`
     * is the descriptor's input and is stripped: Wireframe never sees it.
     *
     * One id is one control. Two types that declare an IDENTICAL subfield (the
     * relationship block the two related-post types share) get it ONCE, at the
     * first declarer's position, gated to every declarer: types whose `when` is
     * equal share one `in` gate and the rest are joined by `any`. Declared
     * twice, one copy would be hidden whenever the other shows, and Wireframe
     * drops a hidden subfield's value on save. A same-id declaration that is NOT
     * identical is kept as a second entry, so H11's unique-id check fails on it
     * instead of the config guessing which wording wins.
     *
     * @param string[] $types Stored type keys, in registry order.
     * @return array[]
     */
    public static function type_subfields(array $types): array {
        return array_map(static function (array $field): array {
            unset($field['group']);
            return $field;
        }, self::tagged_type_subfields($types));
    }

    /**
     * `type_subfields()` split by where each field sits in the row: a
     * descriptor's `group` says `result` (below the shared target term it
     * qualifies) or `limit` (with the scope limits); everything else is `body`.
     * Placement only: a field's gate is the same wherever it lands. The marker
     * is stripped, so Wireframe never sees it.
     *
     * @param string[] $types Stored type keys, in registry order.
     * @return array{body:array[],result:array[],limit:array[]}
     */
    public static function type_subfield_groups(array $types): array {
        $groups = ['body' => [], 'result' => [], 'limit' => []];

        foreach (self::tagged_type_subfields($types) as $field) {
            $group = (string) ($field['group'] ?? 'body');
            unset($field['group']);
            $groups[isset($groups[$group]) ? $group : 'body'][] = $field;
        }

        return $groups;
    }

    /** `type_subfields()` before the `group` marker is stripped. */
    private static function tagged_type_subfields(array $types): array {
        $fields  = [];
        $merged  = [];
        $readers = [];

        foreach ($types as $type) {
            foreach (Registry::get($type)->subfields() as $field) {
                $when = (array) ($field['when'] ?? []);
                unset($field['when']);
                $id = (string) ($field['id'] ?? '');

                if ($id !== '' && isset($merged[$id]) && $merged[$id]['body'] === $field) {
                    $w = json_encode($when);
                    if (isset($readers[$id][$w])) {
                        $readers[$id][$w]['types'][] = $type;
                    } else {
                        $readers[$id][$w] = ['types' => [$type], 'when' => $when];
                    }
                    continue;
                }

                $key = $id !== '' && !isset($merged[$id]) ? $id : $id . '#' . count($fields);
                if ($id !== '' && !isset($merged[$id])) {
                    $merged[$id] = ['body' => $field];
                }
                $readers[$key][json_encode($when)] = ['types' => [$type], 'when' => $when];
                $fields[]                          = ['key' => $key, 'field' => $field];
            }
        }

        $out = [];
        foreach ($fields as ['key' => $key, 'field' => $field]) {
            $clauses = [];
            foreach ($readers[$key] as $group) {
                $clauses[] = self::gate($group['types'], $group['when']);
            }
            $field['conditions'] = count($clauses) === 1 ? $clauses[0] : ['any' => $clauses];
            $out[]               = $field;
        }

        return $out;
    }

    /**
     * slug => label for a list of registered WP objects (taxonomies, post
     * types, post statuses) — the ONE loop behind every option builder here.
     *
     * Before 0.8.0 this body was copy-pasted seven times (four post-type
     * variants and two taxonomy variants here, plus one on the title/slug config)
     * differing only in the registry query and whether a leading placeholder
     * row was prepended (#38 cluster 1). The variation is now entirely in the
     * caller's `get_*()` args, which is why this takes ALREADY-FETCHED objects
     * rather than a query: a caller that has to filter the registry first
     * (FormatRulesConfig drops `attachment`) can still share the loop.
     *
     * @param object[]    $objects     Registered objects with ->name / ->label.
     * @param string|null $placeholder Leading `'' => …` row, or null for none
     *                                 (checkboxes render one row per option
     *                                 and must not carry an empty row).
     * @return array<string,string>
     */
    public static function label_options(array $objects, ?string $placeholder = null): array {
        $options = $placeholder === null ? [] : ['' => $placeholder];

        foreach ($objects as $object) {
            $options[$object->name] = $object->label;
        }

        return $options;
    }

    /**
     * All public taxonomies, with placeholder.
     */
    public static function taxonomy_options(string $placeholder = ''): array {
        return self::label_options(
            get_taxonomies(['public' => true], 'objects'),
            $placeholder ?: __('— Select taxonomy —', 'meta-conductor')
        );
    }

    /**
     * All public taxonomies as slug => label, with NO empty placeholder —
     * the checkboxes variant of taxonomy_options().
     */
    public static function taxonomy_checkbox_options(): array {
        return self::label_options(get_taxonomies(['public' => true], 'objects'));
    }

    /**
     * Public post types as slug => label, with NO empty placeholder.
     *
     * Checkbox fields render one row per option and need no placeholder
     * row (unlike a select). Use for the shared post_types_field().
     */
    public static function post_types_checkbox_options(): array {
        return self::label_options(get_post_types(['public' => true], 'objects'));
    }

    /**
     * Public post types minus attachment — format rules rewrite a post's own
     * fields, which don't apply to media. Both format descriptors offer this
     * list: title & slug as a select (pass a placeholder), Computed field as
     * checkboxes (pass none).
     *
     * @return array<string,string>
     */
    public static function format_post_type_options(?string $placeholder = null): array {
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        return self::label_options($post_types, $placeholder);
    }

    /**
     * The shared body of every "limit this rule to …" checkboxes subfield.
     *
     * All of them are the same field: checkboxes, full width, empty means
     * all, and — critically — an id the handlers read BY NAME, which is why
     * `$id` is forced last and cannot be overridden. Renaming `post_types`
     * or `post_status` would not error; it would silently widen the rule to
     * everything. That id-lock is the reason this base exists rather than
     * each builder writing its own array_merge (#38 cluster 1).
     *
     * @param string $id        Canonical, non-overridable field id.
     * @param array  $defaults  label / description / args for this gate.
     * @param array  $overrides Per-call field-definition overrides.
     */
    private static function gate_field(string $id, array $defaults, array $overrides): array {
        return array_merge(
            ['type' => 'checkboxes', 'columns' => 12],
            $defaults,
            $overrides,
            ['id' => $id]
        );
    }

    /**
     * Canonical "Limit to post types" checkboxes subfield, shared by every
     * rule type that scopes by post type. Single source of truth: id,
     * label, and empty-means-all semantics live here.
     *
     * Empty/all-unchecked ⇒ rule applies to every post type using the
     * taxonomy. Handlers read the resulting `post_types` value via
     * UnifiedHandlerBase::should_process_post.
     *
     * Wireframe stores a checkboxes value as a FLAT LIST of selected slugs,
     * and its REST validator accepts only that shape — handed a {slug:bool}
     * map it validates the map's values, so `true` arrives as "1" and the
     * save 400s with `"1" is not a valid option`. Storage's read
     * projection additionally tolerates the map (legacy and hand-seeded data
     * carries it), but nothing should WRITE it.
     *
     * Every public post type is offered, including flat ones. A
     * hierarchical-only variant existed until 0.8.0 for propagation (SPEC
     * §V5); the ordered term repeater has ONE post-type gate shared across
     * rule types, so the parent/child requirement is now stated as a
     * type-conditioned note on the repeater instead of by withholding
     * options. Enforcement never lived here — a flat post type has no
     * children, so a propagation rule scoped to one simply matches nothing.
     *
     * @param array $overrides Per-call field-definition overrides (e.g. columns).
     */
    public static function post_types_field(array $overrides = []): array {
        return self::gate_field('post_types', [
            'label'       => __('Limit to post types', 'meta-conductor'),
            'description' => __('Leave all unchecked to apply to every post type using this taxonomy.', 'meta-conductor'),
            'args'        => [
                'options' => self::post_types_checkbox_options(),
            ],
        ], $overrides);
    }

    /**
     * The claim vocabulary: stored value => domain term.
     *
     * SINGLE SOURCE OF TRUTH for the claim axis on every author-visible
     * surface — the two config dropdowns and both row-title snapshots
     * (WireframeBootstrap::claim_label() delegates here). Renaming a claim
     * is a one-line change; before this existed the vocabulary was restated
     * in three places and a rename could leave two of them stale.
     *
     * The stored values are unchanged legacy: `replace`/`merge`/`skip`
     * predate the vocabulary, and nothing migrates. See CONTEXT.md → Claim
     * for why the axis has four values (the fourth,
     * `restricting`, is fixed by rule type and reaches no dropdown).
     */
    public const CLAIM_NAMES = [
        'replace' => 'owning',
        'merge'   => 'contributing',
        'skip'    => 'deferring',
    ];

    /**
     * Domain term for a stored claim value. Unknown/absent ⇒ contributing,
     * matching every config's `merge` default.
     */
    public static function claim_name(string $value): string {
        return self::CLAIM_NAMES[$value] ?? self::CLAIM_NAMES['merge'];
    }

    /**
     * Canonical "Claim on terms" select subfield.
     *
     * Shared by TermRulesConfig (per-rule, on propagation rows) and
     * GeneralConfig (per-taxonomy default). Unlike post_types_field() the
     * `id` is NOT forced — the two surfaces genuinely store under different
     * keys (`conflict_handling` vs `mode`) — so it is required in $overrides.
     *
     * `$subject` picks which of the two option wordings to use. Both are
     * written out in full rather than sprintf'd from a noun: a translator
     * given "add only if the %s has no terms" cannot get word order or
     * agreement right, and there are only ever two variants.
     *
     * Option labels lead with the domain word, then a colon, then a plain
     * gloss — so the author reads the same term the docs and row titles use
     * (CONTEXT.md → Claim). Owning's gloss must keep the "anything else is removed"
     * clause: the "allowed" framing is correct about the outcome but would
     * otherwise read as an edit-time refusal, when enforcement is a later
     * removal. The label it replaced, "Replace existing terms", hid the
     * widening to the whole taxonomy, the most consequential thing the author
     * could not see.
     *
     * @param string $subject 'child' (a propagation target) or 'post'.
     * @param array  $overrides Field-definition overrides; MUST carry `id`.
     */
    public static function claim_field(string $subject = 'post', array $overrides = []): array {
        $options = $subject === 'child'
            ? [
                'replace' => __('Owning: only this rule\'s terms are allowed here; anything else is removed', 'meta-conductor'),
                'merge'   => __('Contributing: add this rule\'s terms, leave everything else alone', 'meta-conductor'),
                'skip'    => __('Deferring: add only if the child has no terms in this taxonomy yet', 'meta-conductor'),
            ]
            : [
                'replace' => __('Owning: only the rule\'s terms are allowed here; anything else is removed', 'meta-conductor'),
                'merge'   => __('Contributing: add the rule\'s terms, leave everything else alone', 'meta-conductor'),
                'skip'    => __('Deferring: add only if the post has no terms in this taxonomy yet', 'meta-conductor'),
            ];

        return array_merge([
            'type'    => 'select',
            'label'   => __('Claim on terms', 'meta-conductor'),
            'default' => 'merge',
            'columns' => 12,
            'args'    => ['options' => $options],
        ], $overrides);
    }

    /**
     * Registered post statuses as slug => label, with NO empty placeholder.
     *
     * Limited to the statuses meaningful as a rule gate: the built-in
     * publish/draft/pending/private/future, plus any custom public status.
     * Internal statuses (auto-draft, inherit, trash) are excluded — they are
     * never a meaningful filter target.
     */
    public static function post_status_checkbox_options(): array {
        $options = [];

        // Built-in gateable statuses, in a sensible order.
        $builtin = ['publish', 'future', 'draft', 'pending', 'private'];
        foreach ($builtin as $slug) {
            $obj = \get_post_status_object($slug);
            if ($obj) {
                $options[$slug] = $obj->label;
            }
        }

        // Custom public statuses registered by other plugins/themes.
        $custom = \get_post_stati(['public' => true, '_builtin' => false], 'objects');
        foreach ($custom as $obj) {
            if (!isset($options[$obj->name])) {
                $options[$obj->name] = $obj->label;
            }
        }

        return $options;
    }

    /**
     * The "Limits" heading that opens the scope controls at the foot of a rule
     * row (post types, statuses, filters), so a long row reads as what the rule
     * does, then what it is limited to.
     *
     * An `html` field carries no value, so nothing can be dropped by it; its
     * `conditions` only decide whether the heading shows. Plain variant, so it
     * reads as a heading and not as a note.
     *
     * @param array $overrides Per-call field-definition overrides (e.g. `conditions`).
     */
    public static function limits_heading(array $overrides = []): array {
        return array_merge([
            'id'      => 'limits_heading',
            'type'    => 'html',
            'columns' => 12,
            'args'    => [
                'content' => '<p><strong>' . esc_html__('Limits', 'meta-conductor') . '</strong></p>',
            ],
        ], $overrides);
    }

    /**
     * Canonical "Limit to post statuses" checkboxes subfield, shared by every
     * rule type that gates on publication status. Single source of truth for
     * the id (`post_status`), label, and empty-means-all semantics.
     *
     * Empty/all-unchecked ⇒ no status filter (all statuses considered).
     * Enforcement is per-rule-type: most handlers gate the trigger post via
     * UnifiedHandlerBase::should_process_post (reads `post_status`); the
     * ACF-reference rule gates the SOURCE post during term-collection instead
     * (SPEC §V5). The field shape is shared; the enforcement is not.
     *
     * @param array $overrides Per-call field-definition overrides (e.g. label, columns).
     */
    public static function post_status_field(array $overrides = []): array {
        return self::gate_field('post_status', [
            'label'       => __('Limit to post statuses', 'meta-conductor'),
            'description' => __('Leave all unchecked to apply to every status.', 'meta-conductor'),
            'args'        => [
                'options' => self::post_status_checkbox_options(),
            ],
        ], $overrides);
    }

    /**
     * All terms across all public taxonomies, labelled "Taxonomy: Term".
     *
     * Designed for small sites (under ~500 terms). Result is cached for
     * the request lifetime via a static property.
     */
    public static function all_term_options(): array {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $options    = [];
        $taxonomies = get_taxonomies(['public' => true], 'objects');

        // Sort taxonomies alphabetically by label (V8).
        usort($taxonomies, fn($a, $b) => strcmp($a->label, $b->label));

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy->name,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);

            if (is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $options[(string) $term->term_id] = $taxonomy->label . ': ' . $term->name;
            }
        }

        $cache = $options;
        return $options;
    }

    /**
     * The relationship subfields the two related-post rule types share
     * (`terms_from_related_post`, `field_from_related_post`): `relationship`,
     * the ACF-off notice, `acf_field_name`, `holder_role`,
     * `reverse_acf_field_name`, in that order.
     *
     * The two types sit in ONE repeater (the Auto-Set list), so these are ONE
     * set of subfields, not two: a stored value has a single control, and two
     * declarations of one id would leave one of them hidden and dropping the
     * value on save (architecture.md → *The ordered rule repeaters*). Both
     * descriptors return this block and `type_subfields()` merges the identical
     * declarations into one gated to both types. The wording is therefore the
     * shared wording; what is specific to a type goes in a note gated to that
     * type. No bare `{word}` in any string (H12).
     *
     * @return array[]
     */
    public static function acf_relationship_subfields(): array {
        $acf = ['relationship' => 'acf'];

        $fields = [
            [
                'id'          => 'relationship',
                'type'        => 'select',
                'label'       => __('Relationship', 'meta-conductor'),
                'description' => __('Where the source post comes from. Changing it clears the settings that belonged to the other relationship.', 'meta-conductor'),
                'default'     => 'parent',
                'required'    => true,
                'columns'     => 12,
                'args'        => [
                    'options' => [
                        'parent' => __('Parent post — a post takes the value from its parent', 'meta-conductor'),
                        'acf'    => AcfRelationship::is_available()
                            ? __('ACF relationship field — posts joined by a relationship or post-object field', 'meta-conductor')
                            : __('ACF relationship field — requires ACF, which is not active', 'meta-conductor'),
                    ],
                ],
            ],
        ];

        // The notice a stored ACF row shows while ACF is off. Declared only
        // then: with ACF active it would be a permanent inert note.
        if (!AcfRelationship::is_available()) {
            $fields[] = [
                'id'      => 'acf_required_note',
                'type'    => 'html',
                'columns' => 12,
                'when'    => $acf,
                'args'    => [
                    'variant' => 'warning',
                    'content' => '<p>' . esc_html__('This rule requires ACF, which is not active. It does nothing until ACF is active again; its settings are kept.', 'meta-conductor') . '</p>',
                ],
            ];
        }

        return array_merge($fields, [
            [
                'id'          => 'acf_field_name',
                'type'        => 'select',
                'label'       => __('Monitored relationship field', 'meta-conductor'),
                'description' => __('The post-object or relationship field connecting the two posts. Watched at both ends — a change to either post re-syncs. The post type that OWNS this field is the "field holder"; "Source" below decides which end the value is copied from. Each option names its field group, so two fields sharing a name can be told apart. ⚠ Only top-level relationship/post-object fields are listed — fields nested inside an ACF Group, Repeater, or Flexible Content container are not shown and are not currently supported.', 'meta-conductor'),
                'default'     => '',
                'required'    => true,
                'columns'     => 12,
                'when'        => $acf,
                'args'        => [
                    'options' => self::acf_relationship_field_options(),
                ],
            ],
            [
                'id'          => 'holder_role',
                'type'        => 'radio',
                'label'       => __('Source (copied from)', 'meta-conductor'),
                'description' => __('Which end is authoritative — its terms or field value are copied to the other end. The trigger is ambient (a change at either end re-syncs); this decides direction.', 'meta-conductor'),
                'default'     => 'source',
                'columns'     => 12,
                'when'        => $acf,
                'args'        => [
                    'options' => [
                        'source' => __('Field holder → copies out to related posts (push)', 'meta-conductor'),
                        'target' => __('Related posts → copies in to the field holder (pull)', 'meta-conductor'),
                    ],
                ],
            ],
            [
                'id'          => 'reverse_acf_field_name',
                'type'        => 'select',
                'label'       => __('Reverse relationship field (optional)', 'meta-conductor'),
                'description' => __('The inverse relationship field on the other end, if any. Speeds the reverse lookup and, in a push, orders the sources. Leave blank to auto-detect ACF bidirectional fields. ⚠ Only top-level relationship/post-object fields are listed — group/repeater/flexible-content-nested fields are not shown or supported. ⚠ With neither an explicit reverse field nor a detectable bidirectional field, the reverse lookup falls back to an unindexed query on every save — slow on large sites. Set this (or use an ACF bidirectional field) to avoid it.', 'meta-conductor'),
                'default'     => '',
                'columns'     => 12,
                'when'        => $acf,
                'args'        => [
                    'options' => self::acf_relationship_field_options(__('— None / auto —', 'meta-conductor')),
                ],
            ],
        ]);
    }

    /**
     * ACF relationship/post-object fields across all field groups,
     * labelled "Post Type: field_name".
     *
     * A field attached to multiple post types appears once per attachment.
     * Stored value is the bare ACF field name; the sibling post_type
     * subfield disambiguates at runtime.
     *
     * Returns empty array when ACF is unavailable.
     */
    public static function acf_relationship_field_options(string $placeholder = ''): array {
        // Cache the field map WITHOUT the placeholder row, so callers that pass
        // different placeholders (e.g. the reverse-field "— None / auto —") each
        // get their own first option instead of the first caller's cached one.
        static $fields_cache = null;

        $placeholder_row = ['' => $placeholder ?: __('— Select ACF field —', 'meta-conductor')];

        if ($fields_cache !== null) {
            return $placeholder_row + $fields_cache;
        }

        // Do NOT set $fields_cache before confirming ACF is loaded: if this runs
        // on an early hook (before ACF registers its functions), setting the
        // cache to [] would make it non-null and PERMANENTLY return only the
        // placeholder for the rest of the request — even after ACF loads.
        // (PR#24 round 6 #2)
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $placeholder_row + self::stored_acf_field_options();
        }

        $fields_cache = [];

        $post_types  = get_post_types(['public' => true], 'objects');
        $field_types = ['relationship', 'post_object'];

        foreach ($post_types as $post_type) {
            $groups = acf_get_field_groups(['post_type' => $post_type->name]);
            if (empty($groups)) {
                continue;
            }

            foreach ($groups as $group) {
                $fields = acf_get_fields($group['key']);
                if (empty($fields)) {
                    continue;
                }

                foreach ($fields as $field) {
                    if (!in_array($field['type'] ?? '', $field_types, true)) {
                        continue;
                    }

                    // Option key = the STORED value, "post_type:name:field_key"
                    // (OptionRuleStorage::split_acf_field_value). The key is the
                    // only part that separates two separately-created fields
                    // sharing a bare name; the other two are there because the
                    // key cannot yield them back — a group may be located on
                    // several post types, and the read path wants the name. (#25)
                    $option_key  = $post_type->name . ':' . $field['name'] . ':' . ($field['key'] ?? '');
                    $field_label = $field['label'] ?? $field['name'];

                    // Group title included ALWAYS, not only on collision: two
                    // same-named fields render identical labels otherwise, and
                    // an author cannot re-pick the right row without being able
                    // to tell them apart. Detecting the collision first would be
                    // a second code path that only runs on the sites already in
                    // trouble. (#25)
                    $group_title = (string) ($group['title'] ?? '');
                    $label       = sprintf('%s: %s (%s)', $post_type->label, $field_label, $field['name'])
                        . ($group_title !== '' ? ' — ' . $group_title : '');

                    $fields_cache[$option_key] = $label;
                }
            }
        }

        return $placeholder_row + $fields_cache;
    }

    /**
     * With ACF off there is no field list, and a select drops a value that is
     * not among its options on save. A stored ACF-relationship rule must survive
     * ACF being switched off, so each stored field value is offered as itself;
     * the author sees the raw value, the rule runs nothing, and the next save
     * writes the same value back.
     *
     * @return array<string,string> stored combined value => label.
     */
    private static function stored_acf_field_options(): array {
        // Raw on purpose (not don't 1's `get_rules()`): the select's option keys are
        // the COMBINED values, and the projection would split them.
        $stored = get_option(OptionRuleStorage::OPTION_NAME, []);
        $out    = [];

        // Both kinds: the term list's ACF rows and *Set a field from a related post*.
        foreach ([OptionRuleStorage::KIND_TERM, OptionRuleStorage::KIND_FORMAT] as $kind) {
            foreach ((array) (is_array($stored) ? ($stored[$kind] ?? []) : []) as $row) {
                foreach (['acf_field_name', 'reverse_acf_field_name'] as $key) {
                    $value = is_array($row) ? (string) ($row[$key] ?? '') : '';
                    if ($value !== '') {
                        /* translators: %s: the stored ACF field value */
                        $out[$value] = sprintf(__('%s (ACF inactive)', 'meta-conductor'), $value);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * ACF field types a Computed field rule may write: those that hold one
     * composed string. Relationship, gallery, group and the like are not
     * offered, so a pattern cannot be pointed at a field that would reject it.
     */
    public const COMPUTED_TARGET_TYPES = ['text', 'textarea', 'wysiwyg', 'number', 'email', 'url'];

    /**
     * The Computed field rule's target select: top-level ACF fields a pattern
     * can be stored in, keyed by ACF FIELD KEY (the identity that survives a
     * rename), labelled with the post types and group they belong to.
     *
     * One entry per field, not per post type — the key is the option, and a
     * group located on several post types would otherwise repeat it. The post
     * types ride in the label instead. A static list cannot follow the row's
     * own `post_type` select, so the author picks both.
     *
     * Writable DIRECT subfields of top-level repeaters follow the post-level
     * fields, one entry each, keyed `row|<repeater key>|<subfield key>` so the
     * single select carries the pair (see `FieldFromPatternRules::ROW_PREFIX`).
     *
     * Empty (placeholder only) when ACF is unavailable. Like the relationship
     * list, nothing is cached before ACF is confirmed loaded.
     *
     * @return array<string,string> field key => label.
     */
    public static function acf_computed_target_options(): array {
        static $fields_cache = null;
        static $rows_cache   = [];

        $placeholder_row = ['' => __('— Select ACF field —', 'meta-conductor')];

        if ($fields_cache !== null) {
            return $placeholder_row + $fields_cache + $rows_cache;
        }
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $placeholder_row;
        }

        $fields_cache = [];
        foreach (self::format_post_type_options() as $post_type => $post_type_label) {
            foreach ((array) acf_get_field_groups(['post_type' => $post_type]) as $group) {
                foreach ((array) acf_get_fields($group['key']) as $field) {
                    $key = (string) ($field['key'] ?? '');
                    if ($key !== '' && ($field['type'] ?? '') === 'repeater') {
                        // One entry per writable DIRECT subfield; the pair is the option key.
                        foreach ((array) acf_get_fields($field) as $sub) {
                            $sub_key = (string) ($sub['key'] ?? '');
                            if ($sub_key !== '' && in_array($sub['type'] ?? '', self::COMPUTED_TARGET_TYPES, true)) {
                                $rows_cache[FieldFromPatternRules::ROW_PREFIX . $key . '|' . $sub_key]
                                    = sprintf('%1$s › %2$s (%3$s)', $field['label'] ?? $field['name'], $sub['label'] ?? $sub['name'], $sub['name'] ?? '');
                            }
                        }
                        continue;
                    }
                    if ($key === '' || !in_array($field['type'] ?? '', self::COMPUTED_TARGET_TYPES, true)) {
                        continue;
                    }
                    $fields_cache[$key]['label'] = sprintf('%s (%s)', $field['label'] ?? $field['name'], $field['name'] ?? '')
                        . ((string) ($group['title'] ?? '') !== '' ? ' — ' . $group['title'] : '');
                    $fields_cache[$key]['types'][] = $post_type_label;
                }
            }
        }

        foreach ($fields_cache as $key => $entry) {
            $fields_cache[$key] = $entry['label'] . ' [' . implode(', ', $entry['types']) . ']';
        }

        return $placeholder_row + $fields_cache + $rows_cache;
    }

    /**
     * ACF field types that hold no value of their own (layout and display
     * fields), so there is nothing to copy. `clone` stores its members under the
     * clone's own prefix, not as a value, so it is left out too.
     */
    public const NON_VALUE_TYPES = ['tab', 'message', 'accordion', 'clone', 'separator'];

    /**
     * The source select of *Set a field from a related post*: every top-level
     * ACF field that holds a value, keyed by ACF FIELD KEY, labelled with its
     * group and the post types the group is shown on. The post types ride in
     * the label because a static list cannot follow the row's own post-type
     * scope; the handler refuses a field its group does not show on the
     * receiving type.
     *
     * Empty (placeholder only) when ACF is unavailable. Nothing is cached before
     * ACF is confirmed loaded.
     *
     * @return array<string,string> field key => label.
     */
    public static function acf_source_field_options(): array {
        static $cache = null;

        $placeholder_row = ['' => __('— Select ACF field —', 'meta-conductor')];

        if ($cache !== null) {
            return $placeholder_row + $cache;
        }
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $placeholder_row;
        }

        $cache = [];
        foreach (self::format_post_type_options() as $post_type => $post_type_label) {
            foreach ((array) acf_get_field_groups(['post_type' => $post_type]) as $group) {
                foreach ((array) acf_get_fields($group['key']) as $field) {
                    $key = (string) ($field['key'] ?? '');
                    if ($key === '' || in_array($field['type'] ?? '', self::NON_VALUE_TYPES, true)) {
                        continue;
                    }
                    $cache[$key]['label'] ??= sprintf('%s (%s)', $field['label'] ?? $field['name'], $field['name'] ?? '')
                        . ((string) ($group['title'] ?? '') !== '' ? ' — ' . $group['title'] : '');
                    $cache[$key]['types'][] = $post_type_label;
                }
            }
        }

        foreach ($cache as $key => $entry) {
            $cache[$key] = $entry['label'] . ' [' . implode(', ', array_unique($entry['types'])) . ']';
        }

        return $placeholder_row + $cache;
    }

    /**
     * The group choices of the copy source select (FW-5 group mode): every ACF
     * field group shown on a public post type, keyed by GROUP key, labelled
     * with its title and the post types it is shown on. Never a target: a group
     * source writes into the same group.
     *
     * @return array<string,string> group key => label (no placeholder).
     */
    public static function acf_group_options(): array {
        if (!function_exists('acf_get_field_groups')) {
            return [];
        }

        $groups = [];
        foreach (self::format_post_type_options() as $post_type => $post_type_label) {
            foreach ((array) acf_get_field_groups(['post_type' => $post_type]) as $group) {
                $key = (string) ($group['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $groups[$key]['title'] ??= (string) ($group['title'] ?? $key);
                $groups[$key]['types'][] = $post_type_label;
            }
        }

        $options = [];
        foreach ($groups as $key => $entry) {
            $options[$key] = sprintf(
                /* translators: 1: field group title, 2: post types it is shown on */
                __('Field group: %1$s [%2$s]', 'meta-conductor'),
                $entry['title'],
                implode(', ', array_unique($entry['types']))
            );
        }

        return $options;
    }

    /**
     * The target picker's ACF choices: the source list's fields (no
     * placeholder) with each field's TYPE in the label. A static select cannot
     * follow the row's source, so the author matches types by the label and the
     * handler refuses a target whose type differs from the source's.
     *
     * @return array<string,string> field key => label.
     */
    public static function acf_target_field_options(): array {
        $options = [];
        foreach (self::acf_source_field_options() as $key => $label) {
            if ($key === '') {
                continue;
            }
            $field         = \acf_get_field($key);
            $options[$key] = $label . (is_array($field) ? ' (' . ($field['type'] ?? '') . ')' : '');
        }

        return $options;
    }

    /**
     * Whether a field rule reaches a post type, given its `written_post_types()`.
     * A non-empty list is the author's say-so; an empty one (none checked) is
     * every public post type except attachment, the one the format list never
     * offers. The handlers then also ask whether the target field is shown there.
     *
     * @param string[] $scope     The row's `written_post_types()`; `[]` = none checked.
     * @param string   $post_type Receiving post type.
     */
    public static function scope_reaches(array $scope, string $post_type): bool {
        if ($scope !== []) {
            return in_array($post_type, $scope, true);
        }

        $object = get_post_type_object($post_type);

        return $object !== null && !empty($object->public) && $post_type !== 'attachment';
    }

    /**
     * Whether an ACF field's group is shown (by its location rules) on a post
     * type — what *Same* needs before a copy may write a field onto a post.
     *
     * @param string $field_key ACF field key (top level).
     * @param string $post_type Receiving post type.
     */
    public static function acf_field_shown_on(string $field_key, string $post_type): bool {
        if ($field_key === '' || $post_type === ''
            || !function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return false;
        }

        foreach ((array) acf_get_field_groups(['post_type' => $post_type]) as $group) {
            foreach ((array) acf_get_fields($group['key']) as $field) {
                if (($field['key'] ?? '') === $field_key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A field's human label from its ACF field key, or '' when the key is
     * empty or no longer resolves. Row titles use it; a stale key shows
     * nothing rather than a raw `field_…` string.
     *
     * @param string $key ACF field key.
     * @return string Unescaped.
     */
    public static function acf_field_label(string $key): string {
        if ($key === '' || !function_exists('acf_get_field')) {
            return '';
        }

        $field = \acf_get_field($key);

        return is_array($field) ? (string) ($field['label'] ?? '') : '';
    }

    /**
     * A field group's title from its key, or '' when it no longer resolves.
     */
    public static function acf_group_title(string $key): string {
        if ($key === '' || !function_exists('acf_get_field_group')) {
            return '';
        }

        $group = \acf_get_field_group($key);

        return is_array($group) ? (string) ($group['title'] ?? '') : '';
    }

    /**
     * A field's name (its meta key) from its ACF field key, or '' when the key
     * is empty or no longer resolves.
     *
     * @param string $key ACF field key.
     * @return string
     */
    public static function acf_field_name(string $key): string {
        if ($key === '' || !function_exists('acf_get_field')) {
            return '';
        }

        $field = \acf_get_field($key);

        return is_array($field) ? (string) ($field['name'] ?? '') : '';
    }
}
