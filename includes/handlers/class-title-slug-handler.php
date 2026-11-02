<?php

namespace BWS\MetaConductor\Handlers;

use BWS\MetaConductor\Tokens\FlatSlug;
use BWS\MetaConductor\Tokens\OutputPolicy;
use BWS\MetaConductor\Tokens\PostTokenSource;
use BWS\MetaConductor\Tokens\SlugLadder;
use BWS\MetaConductor\Tokens\TokenEngine;

if (!defined('ABSPATH')) exit;

/**
 * Computes a post's title and slug from a pattern. A pure applier on the format
 * seam since #64 — it owns no hooks and no request state.
 *
 * WHAT WENT AWAY, AND WHY NONE OF IT IS NEEDED. The handler used to be five
 * request-scoped maps and four hooks straddling the DB write: a pre-write
 * `wp_insert_post_data` filter for rules reading no meta, an `acf/save_post`
 * p99 + `save_post` p99 pair for the rest, a `redirect_post_location` fix for
 * the second path, and dedup flags so the two paths could not both fire.
 * `FormatDispatcher` runs the whole thing once, after the term pass, so:
 *
 *   - the two paths collapse to one, and the flags that separated them
 *     (`$handled_pre_write`, `$processed_in_request`) have nothing to separate;
 *   - `$is_updating_post` guarded re-entry from our own `wp_update_post()`. The
 *     handler no longer writes — the dispatcher does, under the pass lock;
 *   - `$pending_submitted_titles` captured the submitted title BEFORE the row
 *     was written, because the pre-write filter had no other way to see it.
 *     Post-write it is simply `post_title`, which is what the pass reads;
 *   - `$final_slugs` and the redirect fix moved onto the dispatcher, with the
 *     write they exist to compensate for.
 *
 * THE PRE-WRITE PHASE COULD NOT SURVIVE. It ran before terms landed, so a
 * `{term:TAX}` pattern resolved against the PREVIOUS save's terms — and the
 * result is a plausible title, which is why nobody would have found it. It did
 * not return with `field_transformation`: field effects ride this same
 * data-in/data-out seam and the pass stays post-write (docs/architecture.md →
 * Field effects ride the $data seam).
 */
class TitleSlugHandler extends UnifiedHandlerBase {

    // -------------------------------------------------------------------------
    // Required abstracts
    // -------------------------------------------------------------------------

    /**
     * Override base class validation. We only check 'enabled' —
     * action['type'], source_type, target_type are not applicable to title/slug rules.
     */
    protected function validate_rule_internal($rule): bool {
        return !empty($rule['enabled']);
    }

    // -------------------------------------------------------------------------
    // Hook registration
    // -------------------------------------------------------------------------

    /**
     * Nothing. `FormatDispatcher` owns the pass and `TermDispatcher` owns the
     * triggers that provoke it, so registering anything here would run this
     * rule type twice — once out of authored order, and once from a hook whose
     * priority is the very ordering accident #64 removed. H13 fails on any
     * registration in this file.
     */
    protected function init_hooks(): void {}

    // -------------------------------------------------------------------------
    // The applier
    // -------------------------------------------------------------------------

    /**
     * Resolve this rule's title and slug patterns against the entity's live
     * state and hand the result on. THE format applier seam (#64).
     *
     * Writes nothing: the dispatcher performs the single row update at the end
     * of the pass, and `commit_data()` the per-rule state. That is what lets
     * the dispatcher's compute step run this for a preview (FW-16 03).
     *
     * @param array $data    Post data as the previous rule left it.
     * @param int   $post_id Entity being passed over.
     * @param array $rule    One enabled title/slug rule.
     * @return array|null Post data, or null when the rule names another post type.
     */
    public function apply_to_data(array $data, int $post_id, array $rule): ?array {
        $post_type = (string) ($data['post_type'] ?? '');
        if (!$this->rule_matches($rule, $post_type)) {
            return null;
        }

        // A plain object over the SAME array the seam carries, so a token reads
        // the title an earlier rule computed rather than the row's stored one.
        $post          = (object) $data;
        $current_title = (string) ($data['post_title'] ?? '');

        $resolved = $this->resolve_rule_output($rule, $post_id, $post, $current_title);
        $new_slug = $resolved['slug'];

        if ($new_slug !== null) {
            $new_slug = $this->make_unique_slug($new_slug, $post_id, $post, $rule);
        }

        $data['post_title'] = $resolved['title'];
        if ($new_slug !== null) {
            $data['post_name'] = $new_slug;
        }

        return $data;
    }

    /**
     * The idempotency meta for one `apply_to_data()` answer.
     *
     * @param array $before  Post data the applier was handed.
     * @param array $after   Post data it returned.
     * @param int   $post_id Entity being passed over.
     * @param array $rule    The rule that answered.
     */
    public function commit_data(array $before, array $after, int $post_id, array $rule): void {
        $new_title = (string) ($after['post_title'] ?? '');

        // Idempotency state, only when the pattern folds the existing title back
        // into itself. Stored UNCONDITIONALLY — the pass re-runs whether or not
        // anything moved, so what is recorded must be a function of this pass's
        // own resolution rather than of whether it happened to change the row.
        // Recording the *resolved base* rather than the submitted title is what
        // makes the second pass over an unchanged post write the same pair back:
        // the submitted title of a post this rule has already renamed IS the
        // applied title, and storing that as the base would compound it.
        //
        // The base is re-resolved from the data the applier was handed. Nothing
        // it reads has been written since the applier ran — this IS the write —
        // so it is the same base the applier resolved.
        //
        // Both go through as_stored(), because the comparison they exist for is
        // against a value read back OUT of the post row — see that method.
        if (!empty($rule['title_pattern']) && $this->pattern_uses_default_title($rule['title_pattern'])) {
            $default_title = $this->resolve_default_title($post_id, (object) $before, $rule,
                                                          new PostTokenSource($post_id, $before));
            update_post_meta($post_id, '_bws_raw_title', $this->as_stored($default_title, $post_id));
            update_post_meta($post_id, '_bws_applied_title', $this->as_stored($new_title, $post_id));
        }
    }

    /**
     * Resolve one rule's title and slug for one post, writing nothing.
     *
     * The ONE encoding of the pattern -> title -> slug chain, including which
     * side of the duplicate-insertion guard each pattern lands on.
     * `apply_to_data()` is its caller; the existing-posts preview reaches it
     * through `FormatDispatcher::compute()`, so a dry run and a real pass cannot
     * report different things.
     *
     * @param array  $rule          One title/slug rule.
     * @param int    $post_id       Entity being resolved.
     * @param object $post          Post-shaped object the tokens read.
     * @param string $current_title Title to fall back to when the rule is silent.
     * @return array{title: string, slug: ?string, default_title: string}
     *         `slug` is null when the rule has no slug opinion at all.
     */
    private function resolve_rule_output(array $rule, int $post_id, object $post, string $current_title): array {
        $source = new PostTokenSource($post_id, (array) $post);

        // --- Title ---
        $new_title     = $current_title; // default: unchanged
        $default_title = $current_title;
        if (!empty($rule['title_pattern'])) {
            $default_title = $this->resolve_default_title($post_id, $post, $rule, $source);
            $new_title = $this->render($rule['title_pattern'], $source, false, $default_title,
                                       $this->pattern_uses_default_title($rule['title_pattern']));
            if ($new_title === '') $new_title = $current_title; // never blank a title
        }

        // --- Slug ---
        // {default_slug} is always sanitize_title($new_title) in the current pass —
        // NEVER read from post_name. Slug idempotency derives from title idempotency.
        $new_slug     = null;
        $default_slug = sanitize_title($new_title);
        if (!empty($rule['slug_pattern'])) {
            $built = $this->render($rule['slug_pattern'], $source, true, $new_title,
                                   $this->slug_keeps_default($rule));
            $new_slug = $this->apply_slug_mode($built, $default_slug, $rule['slug_mode'] ?? 'prefix', $rule['slug_pattern'] ?? '');
        } elseif (!empty($rule['title_pattern'])) {
            $new_slug = $default_slug; // implicit: derive slug from computed title
        }

        return ['title' => $new_title, 'slug' => $new_slug, 'default_title' => $default_title];
    }

    /**
     * Whether this rule governs the given post type.
     *
     * The dispatcher offers every rule in list order and stops at the first of
     * this type that answers — which is how first-match-wins survives the move
     * off `find_matching_rule()`. It is by design: a title/slug rule set is a
     * lookup table keyed by post type, not a set of independently-scoped rules,
     * so a second rule on one post type never runs (#59).
     *
     * TODO(UI): the config layer should warn when a user creates a second rule
     * for a post_type that already has one — silently ignored today. Add when
     * we next revisit the title/slug config screen.
     *
     * @param array  $rule      One rule.
     * @param string $post_type Entity's post type.
     * @return bool
     */
    private function rule_matches(array $rule, string $post_type): bool {
        return !empty($rule['post_type']) && $rule['post_type'] === $post_type;
    }

    private function pattern_uses_default_title(string $pattern): bool {
        return str_contains($pattern, '{default_title}');
    }

    /**
     * What the post row will actually hold for a title we are about to store.
     *
     * WHY THE IDEMPOTENCY META CANNOT RECORD THE COMPUTED VALUE. `_bws_raw_title`
     * and `_bws_applied_title` exist to answer one question — did the author
     * edit the title, or is this the same title the rule produced last time? —
     * and the value they are compared against is read back OUT of the post row.
     * `wp_insert_post()` runs `sanitize_post()` on the way in, which for a user
     * without `unfiltered_html` (every WP-CLI and cron write included) puts the
     * title through kses: `R&D` is stored as `R&amp;D`. Record the computed
     * form and the comparison misses on the very next read, `try_inverse_strip()`
     * cannot recover a base from a suffix that no longer matches, and the
     * pattern applies a second time — `R&amp;D - x - x`.
     *
     * That was survivable while the two passes were separate requests. Since
     * #64 the format write re-marks the entity and the drain passes it again in
     * the SAME request, so the compounding is immediate. Predicting the stored
     * form here is cheaper and more honest than reading the row back after the
     * write, which would need a post-write seam on an applier defined to write
     * nothing.
     *
     * Slashed in and unslashed out because that is the state `sanitize_post()`
     * sees inside `wp_insert_post()`: the kses filters `stripslashes` their
     * input and `addslashes` their output.
     *
     * @param string $title   Title as this pass computed it.
     * @param int    $post_id Entity the title belongs to.
     * @return string The same title as the row will hold it.
     */
    private function as_stored(string $title, int $post_id): string {
        return (string) wp_unslash(
            sanitize_post_field('post_title', wp_slash($title), $post_id, 'db')
        );
    }

    // -------------------------------------------------------------------------
    // Flat URLs (FW-51) — READ-TIME callbacks
    // -------------------------------------------------------------------------
    //
    // Served from the descriptor's `runtime_hooks()`, registered by
    // `TaxonomyManager`; this class registers nothing. They run on page views,
    // outside any pass, so they may read and redirect and must never write (H13).

    /**
     * Post types served flat: hierarchical, with an enabled row whose
     * `flat_urls` is on. First enabled row per type decides, like the format
     * pass's lookup (`rule_matches()`), so a lower row on the same type cannot
     * flatten what the winning row did not.
     *
     * @return string[]
     */
    public function flat_post_types(): array {
        $flat = [];
        $seen = [];

        foreach ($this->get_enabled_rules() as $rule) {
            $post_type = (string) ($rule['post_type'] ?? '');
            if ($post_type === '' || isset($seen[$post_type])) {
                continue;
            }
            $seen[$post_type] = true;

            if ($this->is_flat_rule($rule, $post_type)) {
                $flat[] = $post_type;
            }
        }

        return $flat;
    }

    /**
     * Whether this row serves `$post_type` flat: `flat_urls` on, and a
     * hierarchical type other than `page` — core hands a page's path over as
     * `pagename`, not `post_type` + `name`, so its links would flatten and never
     * resolve.
     */
    private function is_flat_rule(array $rule, string $post_type): bool {
        return !empty($rule['flat_urls']) && $post_type !== 'page' && is_post_type_hierarchical($post_type);
    }

    /**
     * `post_type_link` — `/base/parent/child/` becomes `/base/child/`.
     *
     * Core builds the link from the full ancestor URI, so the flat form is that
     * URI's last occurrence swapped for the post's own name; everything the link
     * carries around it (base, trailing slash, any other plugin's edit) is kept.
     * Sitemaps, REST `link`, feeds and attachment links all read this filter.
     * Untouched: a non-flat type, a top-level post, a link still holding the
     * edit screen's `%placeholder%` (`$leavename`), and a post with no name yet.
     *
     * @param string   $post_link Link as core built it.
     * @param \WP_Post $post      The post.
     * @param bool     $leavename Whether the name segment is left as a placeholder.
     * @return string
     */
    public function flat_permalink($post_link, $post, $leavename = false) {
        // The hierarchy test is a registry lookup; it spares every link of every
        // other post type (menus, sitemaps, REST listings) the rule scan.
        if ($leavename || !$post instanceof \WP_Post || $post->post_name === ''
            || !is_post_type_hierarchical($post->post_type)
            || !in_array($post->post_type, $this->flat_post_types(), true)) {
            return $post_link;
        }

        $uri = get_page_uri($post);
        $pos = $uri === false || $uri === $post->post_name ? false : strrpos($post_link, $uri);

        return $pos === false ? $post_link : substr_replace($post_link, $post->post_name, $pos, strlen($uri));
    }

    /**
     * `request` — resolve `/base/child/` for a flat type, and 301 the nested form.
     *
     * Core resolves a hierarchical single through `get_page_by_path()`, which
     * wants the whole ancestor chain, so a flat path 404s. The path's LAST
     * segment names the post inside the type, and the query is rewritten to
     * `p=ID`. A nested path (`/base/parent/child/`) is 301'd to the flat link
     * ONLY when its chain is exactly the post's real one: a stale or junk chain
     * is left to core, which 404s it. Anything else the path carried (a feed,
     * an embed) is not guessed at and falls through too.
     *
     * @param array $vars Query vars as `WP::parse_request()` built them.
     * @return array
     */
    public function resolve_flat_request($vars) {
        $post_type = is_array($vars) ? ($vars['post_type'] ?? '') : '';
        $name      = is_array($vars) ? ($vars['name'] ?? '') : '';

        if (!is_string($post_type) || !is_string($name) || $name === ''
            || !in_array($post_type, $this->flat_post_types(), true)) {
            return $vars;
        }

        $chain = array_map('sanitize_title_for_query', explode('/', trim($name, '/')));
        $leaf  = array_pop($chain);
        $post  = $this->find_flat_post($post_type, $leaf, $chain);
        if ($post === null) {
            return $vars;
        }

        // The rewrite hands the path over as `post_type` + `name` AND under the
        // type's own query var; both go, or the old lookup runs beside ours.
        $query_var = get_post_type_object($post_type)->query_var;
        $own       = array_merge(['post_type', 'name', 'page'], $query_var ? [$query_var] : []);

        if ($chain === []) {
            unset($vars['name']);
            if ($query_var) {
                unset($vars[$query_var]);
            }
            $vars['p'] = $post;

            return $vars;
        }

        // Only a post the visitor may read gets a redirect: a 301 for a draft or
        // a private post would confirm it exists and hand over its URL.
        $status = get_post_status($post);
        if (array_filter(array_diff_key($vars, array_flip($own))) !== []
            || !($status === 'publish' || ($status === 'private' && current_user_can('read_post', $post)))) {
            return $vars;
        }

        $url  = get_permalink($post);
        $page = (int) ($vars['page'] ?? 0);
        if ($page > 1) {
            $url = trailingslashit($url) . user_trailingslashit((string) $page);
        }
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        wp_redirect($query === '' ? $url : $url . '?' . $query, 301, 'Meta Conductor');
        exit;
    }

    /**
     * The post a flat path names: published first, then the lowest ID, then any
     * status — core's own `name` lookup orders by date with no tiebreak and 404s
     * a logged-out visitor whenever the newest of two same-named posts is a
     * draft. With a chain, the first candidate whose REAL ancestor names equal
     * it; without one, the first candidate.
     *
     * @param string   $post_type Flat post type.
     * @param string   $leaf      Last path segment.
     * @param string[] $chain     Ancestor names the path carried, outermost first.
     * @return int|null Post ID.
     */
    private function find_flat_post(string $post_type, string $leaf, array $chain): ?int {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s AND post_name = %s AND post_status NOT IN ('trash','auto-draft')
             ORDER BY (post_status = 'publish') DESC, ID ASC",
            $post_type, $leaf
        ));

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($chain === []) {
                return $id;
            }
            $real = array_map(
                static fn($ancestor) => (string) get_post_field('post_name', $ancestor),
                array_reverse(get_post_ancestors($id))
            );
            if ($real === $chain) {
                return $id;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Token rendering
    // -------------------------------------------------------------------------

    /**
     * Render one title or slug pattern through `Tokens\TokenEngine`.
     *
     * Binds `{default_title}` / `{default_slug}` from `$computed_title`, and
     * decides the guard base: the title itself, or its slug — whichever form
     * the output would carry it in. Whether to guard at all is the caller's
     * call (`pattern_uses_default_title()` / `slug_keeps_default()`).
     */
    private function render(string $pattern, PostTokenSource $source, bool $slug,
                            string $computed_title = '', bool $guard = true): string {
        $default_slug = sanitize_title($computed_title);
        return TokenEngine::render(
            $pattern, $source, $slug ? OutputPolicy::slug() : OutputPolicy::title(),
            ['default_title' => $computed_title, 'default_slug' => $default_slug],
            $guard ? ($slug ? $default_slug : $computed_title) : null
        );
    }

    /**
     * Whether this rule's slug output still carries the base slug, and so needs
     * the duplicate-insertion guard.
     *
     * `prefix`/`suffix` concatenate `$default_slug` onto the built string, so a
     * token that already appears in it would double. `replace` discards the base
     * entirely — UNLESS the pattern names `{default_slug}` itself, which
     * `apply_slug_mode()` treats as replace precisely because the token folds the
     * base back in.
     */
    private function slug_keeps_default(array $rule): bool {
        return ($rule['slug_mode'] ?? 'prefix') !== 'replace'
               || str_contains((string) ($rule['slug_pattern'] ?? ''), '{default_slug}');
    }

    // -------------------------------------------------------------------------
    // Idempotency: avoid double-application on re-save
    // -------------------------------------------------------------------------

    protected function resolve_default_title(int $post_id, object $post, array $rule,
                                             PostTokenSource $source): string {
        $pattern = $rule['title_pattern'] ?? '';

        // Short-circuit: if pattern has no {default_title}, all tokens are external —
        // no compounding possible, no meta tracking needed.
        if (!$this->pattern_uses_default_title($pattern)) {
            return (string) $post->post_title;
        }

        // The title the author last submitted. Under the pass this is simply
        // what the row holds: the pass runs AFTER the row is written and before
        // anything of ours has touched it, so the pre-write capture the
        // `wp_insert_post_data` filter needed has nothing left to capture (#64).
        $submitted = (string) $post->post_title;
        $applied   = (string) get_post_meta($post_id, '_bws_applied_title', true);
        $raw       = (string) get_post_meta($post_id, '_bws_raw_title', true);

        // Branch 1: user didn't touch the title field (or source field changed) — use stored raw.
        if ($applied !== '' && $submitted === $applied) {
            return $raw !== '' ? $raw : $submitted;
        }

        // Branch 2: user edited the title — try to recover the base by stripping the
        // rule's computed prefix/suffix (single attempt, case-insensitive).
        $candidate = $this->try_inverse_strip($submitted, $rule, $source);
        if ($candidate !== null) {
            return $candidate;
        }

        // Branch 3: entirely new title (or inverse strip failed).
        return $submitted;
    }

    private function try_inverse_strip(string $submitted, array $rule, PostTokenSource $source): ?string {
        $pattern = $rule['title_pattern'] ?? '';

        // Split pattern at {default_title} to get prefix/suffix portions.
        $dt_pos = mb_strpos($pattern, '{default_title}');
        if ($dt_pos === false) return null;

        $prefix_pattern = mb_substr($pattern, 0, $dt_pos);
        $suffix_pattern = mb_substr($pattern, $dt_pos + mb_strlen('{default_title}'));

        // Resolve prefix/suffix using current field values (no {default_title} token here).
        $computed_prefix = $prefix_pattern !== ''
            ? $this->render($prefix_pattern, $source, false)
            : '';
        $computed_suffix = $suffix_pattern !== ''
            ? $this->render($suffix_pattern, $source, false)
            : '';

        // Case-insensitive strip both ends.
        $candidate = $submitted;
        if ($computed_prefix !== '' && mb_stripos($candidate, $computed_prefix) === 0) {
            $candidate = mb_substr($candidate, mb_strlen($computed_prefix));
        }
        if ($computed_suffix !== '') {
            $lower_candidate = mb_strtolower($candidate);
            $lower_suffix    = mb_strtolower($computed_suffix);
            $suffix_pos      = mb_strlen($candidate) - mb_strlen($computed_suffix);
            if ($suffix_pos >= 0 && mb_substr($lower_candidate, $suffix_pos) === $lower_suffix) {
                $candidate = mb_substr($candidate, 0, $suffix_pos);
            }
        }
        $candidate = trim($candidate);

        if ($candidate === '' || $candidate === $submitted) return null;

        // Verify: re-apply full rule with candidate — result must match submitted.
        // Guarded explicitly rather than by the parameter default: this branch is
        // only reached when $pattern names {default_title} (see $dt_pos above), so
        // the candidate base DOES survive into the output and the production
        // resolve would have guarded it too. Reproducing that is the whole point
        // of the check — an unguarded verify would never match a guarded apply.
        $verified = $this->render($pattern, $source, false, $candidate, true);
        return ($verified === $submitted) ? $candidate : null;
    }

    // -------------------------------------------------------------------------
    // Slug collision avoidance
    // -------------------------------------------------------------------------

    protected function apply_slug_mode(string $built, string $default_slug, string $mode, string $slug_pattern = ''): string {
        if ($slug_pattern !== '' && str_contains($slug_pattern, '{default_slug}')) {
            $mode = 'replace';
        }
        return match($mode) {
            'prefix'  => trim($built . '-' . $default_slug, '-'),
            'suffix'  => trim($default_slug . '-' . $built, '-'),
            default   => $built, // replace
        };
    }

    /**
     * A free slug for the post. A taken one is walked up the rule's
     * disambiguation ladder (`SlugLadder`), then numbered for whatever is still
     * taken — this method supplies the uniqueness lookup and the rung values,
     * and nothing of the walk itself.
     *
     * Numbering is core's (`wp_unique_post_slug()`, per parent) except in a flat
     * type, where a slug is a path segment straight under the base: there it is
     * `FlatSlug::number()`, per TYPE, and a reserved name (`page`, `feed`, a bare
     * number …) is not free. A reserved base skips the ladder — it is not a
     * collision a parent or a date would explain — and goes straight to numbering.
     */
    protected function make_unique_slug(string $slug, int $post_id, object $post, array $rule): string {
        $flat = $this->is_flat_rule($rule, $post->post_type);
        $free = fn(string $candidate): bool => !($flat && $this->reserved_slug($candidate))
            && $this->slug_is_unique($candidate, $post_id, $post->post_type);

        if ($free($slug)) {
            return $slug;
        }
        $rungs = $this->ladder_rungs($rule, $post_id, $post);
        if ($rungs && !($flat && $this->reserved_slug($slug))) {
            $slug = SlugLadder::walk($slug, $rungs, (string) ($rule['escalation_placement'] ?? ''), $free);
        }
        return $flat
            ? FlatSlug::number($slug, $free)
            : wp_unique_post_slug($slug, $post_id, $post->post_status, $post->post_type, $post->post_parent);
    }

    /** Whether the slug would shadow the base's own feed / paging / embed URLs. */
    private function reserved_slug(string $slug): bool {
        global $wp_rewrite;
        return FlatSlug::reserved($slug, (array) $wp_rewrite->feeds, (string) $wp_rewrite->pagination_base);
    }

    private function slug_is_unique(string $slug, int $post_id, string $post_type): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_name = %s AND post_type = %s
               AND post_status NOT IN ('trash','auto-draft') AND ID != %d",
            $slug, $post_type, $post_id
        ));
        return $count === 0;
    }

    /**
     * The rule's `slug_escalation` tokens resolved for this post, as the walk
     * takes them. Every value is shaped by the slug policy, so a parent slug or
     * a term arrives already a slug. A date token also resolves each finer part
     * of ITS date, because the walk only knows part names.
     *
     * @return array[]
     */
    private function ladder_rungs(array $rule, int $post_id, object $post): array {
        $source = new PostTokenSource($post_id, (array) $post);
        $policy = OutputPolicy::slug();
        $rungs  = [];

        foreach (TokenEngine::tokens((string) ($rule['slug_escalation'] ?? '')) as $token) {
            $part = TokenEngine::date_part_of($token);
            if ($part === null) {
                $rungs[] = ['value' => TokenEngine::render('{' . $token . '}', $source, $policy)];
                continue;
            }
            $parts = [];
            foreach (SlugLadder::PARTS as $name) {
                $parts[$name] = TokenEngine::render('{' . TokenEngine::with_date_part($token, $name) . '}', $source, $policy);
            }
            $rungs[] = ['part' => $part, 'parts' => $parts];
        }

        return $rungs;
    }
}
