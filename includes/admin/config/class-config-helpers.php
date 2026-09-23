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

use BWS\MetaConductor\Storage\OptionRuleStorage;

if (!defined('ABSPATH')) {
    exit;
}

class ConfigHelpers {

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
     * save 400s with `"1" is not a valid option`. Handlers additionally
     * tolerate the map on read (see selected_checkbox_slugs) because legacy
     * and hand-seeded data carries it, but nothing should WRITE it.
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
     * Normalize a Wireframe checkboxes value to a flat list of selected slugs.
     *
     * Checkboxes store a `{slug: bool}` map; a plain list of slugs (or an empty
     * value) is also accepted. Single source of truth for the extraction shared
     * by handlers (gate enforcement) and the label snapshot (row titles) — keep
     * the three former copies (handler status_gate, bootstrap status_gate_label,
     * should_process_post) from drifting.
     *
     * @param mixed $value Checkbox map, list of slugs, or empty.
     * @return string[] Selected slugs (truthy keys), or [] when nothing selected.
     */
    public static function selected_checkbox_slugs($value): array {
        if (empty($value) || !is_array($value)) {
            return [];
        }
        return array_is_list($value)
            ? array_values($value)
            : array_keys(array_filter($value));
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
     * and ADR 0004 for why the axis has four values (the fourth,
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
     * (ADR 0004). Owning's gloss must keep the "anything else is removed"
     * clause: the "allowed" framing is correct about the outcome but would
     * otherwise read as an edit-time refusal, when enforcement is a later
     * removal.
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
            return $placeholder_row;
        }

        $fields_cache = [];

        $post_types  = get_post_types(['public' => true], 'objects');
        $field_types = OptionRuleStorage::ACF_REFERENCE_FIELD_TYPES;

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
}
