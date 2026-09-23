<?php
/**
 * Format dispatcher — the sole entry point to format-rule execution.
 *
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Core;

use BWS\MetaConductor\Handlers\UnifiedHandlerBase;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs the `format_rules` kind list, in authored order, from the term
 * dispatcher's drain — immediately after the term pass for the same entity.
 *
 * WHY THIS EXISTS (ADR 0003 decision 2, #64). The cross-kind order terms →
 * title/field is DERIVED, not authored: `title_slug` reads terms
 * (`{term:TAX}`, `{terms:TAX}`) and writes none, so it is a sink with inbound
 * edges. Until this ticket that derived order was held together by two
 * accidents — `TitleSlugHandler` registered at `acf/save_post`/`save_post`
 * priority 99, above every term handler, and `TaxonomyManager` constructed it
 * last. Coalescing the term pass onto a late drain (#60) would have inverted
 * both silently: the title would have been computed from the PREVIOUS save's
 * terms, and nothing would have looked broken because a title is always
 * plausible. Sequencing the two dispatchers inside one drain step makes the
 * order hold by construction.
 *
 * ONE QUEUE, TWO DISPATCHERS. This one owns no triggers and no dirty set.
 * `TermDispatcher` marks entities and drains them; for each entity it runs its
 * own ordered pass and then calls `run_pass()` here. A second queue would have
 * to be kept in step with the first for the derived order to mean anything,
 * which is the coupling the single queue removes.
 *
 * SHARING THE QUEUE WIDENS WHAT PROVOKES A FORMAT RULE, DELIBERATELY. Every
 * entity the drain reaches gets a format pass, and the drain reaches more than
 * saves: a propagation fan-out's children, a captured sever's dependent, a post
 * the ACF write queue flushed. `title_slug` used to hook only `save_post` and
 * `acf/save_post`, so a post whose TERMS changed without being saved kept a
 * stale `{term:TAX}` title until someone re-saved it. It no longer does, which
 * is the correct reading of a rule that consumes terms — but it means a term
 * edit on a parent can now rewrite a published child's `post_name`, and WP
 * leaves no redirect behind when a slug changes. That is called out in the
 * changelog rather than gated: the pass is defined as "the same pass however
 * provoked", and a rule that reconciles on some provocations and not others is
 * the class of defect the dispatcher exists to remove.
 *
 * THE APPLIER SEAM IS DATA-IN/DATA-OUT, NOT `apply_to_post()`. A term rule's
 * effect is a set of term relationships, which is why its applier can write and
 * report a boolean. A format rule's effect is the post row itself, and several
 * rules can land on one row — so the appliers hand a post-data array along the
 * list and THIS class performs the single write at the end. That is what keeps
 * one save to one `wp_update_post()` however many rules matched, and it is the
 * shape the pre-write phase needs: when `field_transformation` lands and the
 * pass splits in two (ADR 0003), the pre-write half can feed
 * `wp_insert_post_data`'s own `$data` array into the same seam unchanged.
 *
 * FIRST MATCH OF A TYPE WINS. `apply_to_data()` returns null for a rule that
 * does not apply to the entity, and once a rule of some type has returned data
 * the remaining rules OF THAT TYPE are skipped. This is `title_slug`'s
 * documented semantics — a rule set is a lookup table keyed by post type, not a
 * set of independently-scoped rules (#59) — and enforcing it here rather than
 * in the handler is what keeps the handler stateless. It is per TYPE, not per
 * pass, so a second format rule type still composes with this one in list
 * order.
 *
 * WHY EVERY APPLY IS NOW POST-WRITE. The old handler split itself across
 * `wp_insert_post_data` (pre-write, for rules reading no meta) and
 * `acf/save_post` p99 (post-write, for rules reading meta). The pre-write half
 * cannot survive this ticket: it runs BEFORE the term pass, so a
 * `{term:TAX}` pattern would read the previous save's terms — the exact defect
 * the ticket exists to remove. The cost is one extra `wp_update_post()` per
 * save that actually changes something, with the revision the update would
 * otherwise create suppressed and the redirect's slug cache flushed, both of
 * which the post-write half already had to do.
 */
class FormatDispatcher {

    /** The effect kind this dispatcher owns. One dispatcher per kind (#64). */
    public const KIND = OptionRuleStorage::KIND_FORMAT;

    /**
     * Rule types the dispatcher executes, as pure appliers.
     *
     * A type here MUST register no apply hooks of its own. The mirror of
     * `TermDispatcher::CONVERTED_TYPES`, and H13 reads both.
     *
     * @var string[]
     */
    private const CONVERTED_TYPES = [
        'title_slug_rules',
    ];

    /**
     * Format rule types that still own their apply hooks.
     *
     * EMPTY from the start — the kind has one member and #64 converts it. Kept
     * as the other half of the partition H13 group 9 checks, so a format type
     * added later must be named on one side or the other rather than silently
     * running nowhere.
     *
     * @var string[]
     */
    private const UNCONVERTED_TYPES = [];

    /**
     * Hooks a CONVERTED format handler may still register, by rule type.
     *
     * Empty: nothing in this kind reads state a write is about to destroy.
     * `title_slug` resolves its tokens from live state at pass time, and the
     * one value it used to capture pre-write — the submitted `post_title` — is
     * exactly what the post row holds by the time the pass runs, because the
     * pass no longer runs before the row is written.
     *
     * Kept as a declared constant for the same reason the term dispatcher's
     * was while it was empty: the allow-list is what makes "a converted
     * handler registers nothing" a property with an explicit exception list
     * rather than an implicit one.
     *
     * @var array<string,string[]>
     */
    private const CAPTURE_HOOKS = [];

    /**
     * Handlers keyed by RULE type. Same map the term dispatcher builds.
     *
     * @var array<string,UnifiedHandlerBase>
     */
    private array $handlers = [];

    /**
     * Slugs this dispatcher wrote in the current request, `post_id => slug`.
     *
     * Read only by `fix_post_redirect()`. The admin's post-save redirect is
     * built from a cached post that predates our `wp_update_post()`, so
     * without the flush the "View Post" link on the destination screen points
     * at the pre-rule slug — a 404 the author sees once and cannot explain.
     *
     * @var array<int,string>
     */
    private array $final_slugs = [];

    /**
     * The registered dispatcher, for callers that hold no reference to it.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Fingerprint of a disabled row passes run anyway; see `include_row()`.
     *
     * @var string|null
     */
    private static ?string $included = null;

    /**
     * @param array<string,UnifiedHandlerBase> $handlers Handlers as built by
     *                                                   TaxonomyManager, keyed
     *                                                   by handler type.
     */
    public function __construct(array $handlers) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->rule_type()] = $handler;
        }
    }

    /**
     * The registered dispatcher, or null before boot.
     *
     * @return self|null
     */
    public static function instance(): ?self {
        return self::$instance;
    }

    /**
     * Whether a pass executes this rule type.
     *
     * @param string $rule_type Rule type key.
     * @return bool
     */
    public static function owns(string $rule_type): bool {
        return in_array($rule_type, self::CONVERTED_TYPES, true);
    }

    /**
     * Register what little this dispatcher hooks.
     *
     * NO TRIGGERS AND NO DRAIN — `TermDispatcher` owns both, and calls
     * `run_pass()` here once per entity per drain step. The one registration is
     * the admin redirect fix, which moved off the handler with the write it
     * belongs to.
     */
    public function register(): void {
        self::$instance = $this;

        add_filter('redirect_post_location', [$this, 'fix_post_redirect'], 10, 2);
    }

    /**
     * Flush the object cache when the post's slug was rewritten in this request.
     *
     * @param string $location Redirect target.
     * @param int    $post_id  Post just saved.
     * @return string The location, unchanged — this is a cache flush, not a rewrite.
     */
    public function fix_post_redirect($location, $post_id = 0): string {
        $post_id = (int) $post_id;
        if (isset($this->final_slugs[$post_id])) {
            clean_post_cache($post_id);
        }

        return (string) $location;
    }

    // ------------------------------------------------------------------- pass

    /**
     * One full ordered pass over one entity's format rules.
     *
     * `compute()`, then `write()`. The lock is `TermDispatcher`'s, keyed on
     * THIS kind — so a format write cannot re-enter a format pass, while the
     * term marks that write legitimately raises are still heard (they are
     * keyed on the term kind).
     *
     * @param int $post_id Entity to pass over.
     * @return int Rules that changed the post data.
     */
    public function run_pass(int $post_id): int {
        if ($post_id <= 0 || TermDispatcher::pass_active($post_id, self::KIND)) {
            return 0;
        }
        if (!$this->pass_enabled($post_id)) {
            return 0;
        }

        TermDispatcher::take_pass(self::KIND, $post_id);
        $changed = 0;

        try {
            $result = $this->compute($post_id);
            if ($result !== null) {
                $this->write($post_id, $result);
                foreach ($result['applied'] as $step) {
                    if ($step['after'] !== $step['before']) {
                        $changed++;
                    }
                }
            }
        } finally {
            TermDispatcher::release_pass(self::KIND, $post_id);
        }

        return $changed;
    }

    /**
     * What a pass over this entity would produce, writing nothing.
     *
     * Every enabled rule of this kind, in AUTHORED order, each handed the
     * post data the previous one returned. The pass writes the result; the
     * format preview only shows it (FW-16 03). No pass lock is taken, because
     * nothing here writes.
     *
     * @param int $post_id Entity to compute for.
     * @return array{before: array, after: array, applied: array[]}|null Null
     *         when the entity takes no format pass. `applied` holds one
     *         `{rule, before, after}` per rule that answered, in order.
     */
    public function compute(int $post_id): ?array {
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post instanceof \WP_Post) {
            return null;
        }
        // An autosave/revision is not the entity, and an auto-draft or trashed
        // post is not one an author is looking at — renaming either produces a
        // title nobody asked for on a row nobody sees.
        if (\wp_is_post_autosave($post_id) || \wp_is_post_revision($post_id)) {
            return null;
        }
        if (in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            return null;
        }

        $before  = $this->post_data($post);
        $data    = $before;
        $applied = [];
        $claimed = [];

        foreach ($this->ordered_rules() as $rule) {
            $type = (string) ($rule['type'] ?? '');

            if (!in_array($type, self::CONVERTED_TYPES, true)) {
                continue;
            }
            if (isset($claimed[$type])) {
                continue;
            }
            $handler = $this->handlers[$type] ?? null;
            if (!$handler) {
                continue;
            }

            $next = self::apply($post_id, $data, $rule, $handler);
            if ($next === null) {
                continue;
            }

            $claimed[$type] = true;
            $applied[]      = ['rule' => $rule, 'before' => $data, 'after' => $next];
            $data           = $next;
        }

        return ['before' => $before, 'after' => $data, 'applied' => $applied];
    }

    /**
     * Apply ONE rule to ONE entity's data. The sole caller of `apply_to_data()`.
     *
     * Static and taking its handler explicitly, mirroring
     * `TermDispatcher::apply()`, so any path that holds a rule and a handler
     * but not a dispatcher routes through the same choke point. That single
     * call site is what H13 checks.
     *
     * @param int                $post_id Entity being passed over.
     * @param array              $data    Post data as the previous rule left it.
     * @param array              $rule    One enabled rule (canonical shape).
     * @param UnifiedHandlerBase $handler The rule type's handler.
     * @return array|null Post data, or null when the rule does not apply here.
     */
    public static function apply(int $post_id, array $data, array $rule, UnifiedHandlerBase $handler): ?array {
        return $handler->apply_to_data($data, $post_id, $rule);
    }

    /**
     * Write a `compute()` result: each answering rule's `commit_data()`, then
     * the row, if it differs from what the row holds.
     *
     * The per-rule state goes first because it did when the appliers wrote it
     * themselves, and the row update below can drain a re-pass that reads it.
     *
     * ONE UPDATE FOR THE WHOLE LIST — the appliers do not write, so however
     * many rules matched, an author's save costs at most one extra row update.
     *
     * The extra revision that update would otherwise create is suppressed: it
     * is our write, not the author's, and a revision per save whose only
     * difference is a rule's own output is noise in a history the author reads.
     * Through `wp_revisions_to_keep`, not `wp_save_post_revision_post_has_changed`:
     * WP consults the latter only when the post already HAS a revision, so a
     * post with none still got one per write — one per post on a bulk run
     * (FW-16 04). Zero-to-keep returns before WP saves or prunes anything.
     *
     * @param int   $post_id Entity.
     * @param array $result  What `compute()` returned for it.
     */
    private function write(int $post_id, array $result): void {
        foreach ($result['applied'] as $step) {
            $this->handlers[$step['rule']['type']]->commit_data($step['before'], $step['after'], $post_id, $step['rule']);
        }

        $before = $result['before'];
        $after  = $result['after'];
        $update = ['ID' => $post_id];

        // SLASHED, because `wp_update_post()` expects it. It slashes only the
        // fields it re-reads from the existing row and then `wp_insert_post()`
        // unslashes the whole merged array, so an unslashed value handed in
        // here loses one level of backslashes: a title of `AC\DC` stores as
        // `ACDC`. The old `wp_insert_post_data` half never had to think about
        // this — it edited data that was already slashed — and routing every
        // rule post-write is what makes it universal (#64).
        if (($after['post_title'] ?? '') !== ($before['post_title'] ?? '')) {
            $update['post_title'] = wp_slash((string) $after['post_title']);
        }
        if (($after['post_name'] ?? '') !== ($before['post_name'] ?? '')) {
            $update['post_name']         = wp_slash((string) $after['post_name']);
            $this->final_slugs[$post_id] = (string) $after['post_name'];
        }

        if (count($update) === 1) {
            return;
        }

        add_filter('wp_revisions_to_keep', '__return_zero');
        try {
            wp_update_post($update);
        } finally {
            remove_filter('wp_revisions_to_keep', '__return_zero');
        }
    }

    /**
     * The post fields a format applier may read, and the two it may change.
     *
     * A plain array rather than the WP_Post, because the seam is data-in/
     * data-out: an applier composes with the one before it by editing what it
     * was handed, and the pre-write phase this shape is designed for has no
     * post object at all — only `wp_insert_post_data`'s `$data`.
     *
     * @param \WP_Post $post Entity under pass.
     * @return array
     */
    private function post_data(\WP_Post $post): array {
        return [
            'ID'          => (int) $post->ID,
            'post_title'  => (string) $post->post_title,
            'post_name'   => (string) $post->post_name,
            'post_type'   => (string) $post->post_type,
            'post_status' => (string) $post->post_status,
            'post_date'   => (string) $post->post_date,
            'post_parent' => (int) $post->post_parent,
        ];
    }

    /**
     * Whether a pass may run for this entity. THE authoritative off switch.
     *
     * Same two-filter shape and the same reasoning as
     * `TermDispatcher::pass_enabled()`: `meta_conductor_acf_reapply_enabled` is
     * the established "do not recompute rules for this post" switch and every
     * existing user of it — imports, the conversion tool, the fixture seeder's
     * empty-rules window — means it here too; `meta_conductor_format_pass_enabled`
     * is the finer control for a site that wants term passes without renames.
     * Public for the same reason as the term dispatcher's.
     *
     * @param int $post_id Entity a pass is about to run for.
     * @return bool
     */
    public function pass_enabled(int $post_id): bool {
        $default = !(defined('WP_IMPORTING') && WP_IMPORTING);
        $default = (bool) apply_filters('meta_conductor_acf_reapply_enabled', $default, $post_id);

        return (bool) apply_filters('meta_conductor_format_pass_enabled', $default, $post_id);
    }

    /**
     * Run one disabled row in every pass until cleared — the one-time run
     * over a disabled rule. It runs at its authored position, so it can win
     * its type's first match. Request-scoped; the stored row is never
     * written. Its caller must clear it in `finally`, so it cannot leak into
     * a save later in the same request.
     *
     * @param string $fingerprint `RuleChoice::fingerprint()` of the row.
     */
    public static function include_row(string $fingerprint): void {
        self::$included = $fingerprint;
    }

    /** Drop the `include_row()` override. */
    public static function clear_included_row(): void {
        self::$included = null;
    }

    /**
     * The enabled rules of this kind, plus the included row if any, in
     * authored order.
     *
     * @return array[] Rows carrying `type`.
     */
    private function ordered_rules(): array {
        return RuleChoice::pass_rows(
            StorageFactory::get_instance()->get_kind_rules(self::KIND),
            self::$included
        );
    }
}
