<?php
/**
 * Format dispatcher — the sole entry point to format-rule execution.
 *
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Core;

use BWS\MetaConductor\Handlers\UnifiedHandlerBase;
use BWS\MetaConductor\RuleTypes\Registry;
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
 * one save to one `wp_update_post()` however many rules matched. The same array
 * carries field effects: a Computed field rule sets `fields[<name>]` (and the
 * ACF field key in `field_keys[<name>]`), and `write()` persists the difference
 * through ACF's write-by-key before the row update.
 *
 * FIRST MATCH OF A TYPE WINS — FOR A TYPE WHOSE DESCRIPTOR SAYS SO.
 * `apply_to_data()` returns null for a rule that does not apply to the entity,
 * and once a rule of a `first_match_wins()` type has returned data the
 * remaining rules OF THAT TYPE are skipped. A type that is not
 * (`field_transformation`) runs every applicable rule in list order, so the
 * last write to a field wins. This is `title_slug`'s
 * documented semantics — a rule set is a lookup table keyed by post type, not a
 * set of independently-scoped rules (#59) — and enforcing it here rather than
 * in the handler is what keeps the handler stateless. It is per TYPE, not per
 * pass, so a second format rule type still composes with this one in list
 * order.
 *
 * FIELD EFFECTS RIDE THE `$data` SEAM; THE FORMAT PASS STAYS POST-WRITE ONLY
 * (docs/architecture.md → "Field effects ride the $data seam"). The old handler
 * split itself across `wp_insert_post_data` (pre-write, for rules reading no
 * meta) and `acf/save_post` p99 (post-write, for rules reading meta). The
 * pre-write half cannot survive #64: it runs BEFORE the term pass, so a
 * `{term:TAX}` pattern would read the previous save's terms — the exact defect
 * that ticket exists to remove. ADR 0003 anticipated that the pass would split
 * in two again when `field_transformation` landed; it did not need to. A field
 * write is post meta, which `wp_insert_post_data` never carries, and the
 * post-write pass already sees the current pass's terms. REJECTED ALTERNATIVE:
 * a pre-write phase — it would reintroduce the stale-term read and gain nothing
 * a meta write needs. The cost of post-write is one extra `wp_update_post()` per
 * save that changes the row, with the revision it would create suppressed and
 * the redirect's slug cache flushed.
 */
class FormatDispatcher {

    /** The effect kind this dispatcher owns. One dispatcher per kind (#64). */
    public const KIND = OptionRuleStorage::KIND_FORMAT;

    /**
     * Handlers keyed by stored rule type. Same map the term dispatcher holds.
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
     *                                                   by stored rule type.
     */
    public function __construct(array $handlers) {
        $this->handlers = $handlers;
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
     * Whether a pass of this kind executes this rule type — its descriptor's
     * `kind()`. False for a type with no descriptor.
     *
     * @param string $rule_type Rule type key.
     * @return bool
     */
    public static function owns(string $rule_type): bool {
        return Registry::get($rule_type)?->kind() === self::KIND;
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
     * @return int Rules that changed the title or slug, plus fields written.
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
                $changed = $this->write($post_id, $result);
                foreach ($result['applied'] as $step) {
                    foreach (['post_title', 'post_name'] as $column) {
                        if ($step['after'][$column] !== $step['before'][$column]) {
                            $changed++;
                            break;
                        }
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

            if (!Registry::known($type)) {
                continue;
            }
            if (!self::owns($type)) {
                continue;
            }
            $first_match = Registry::get($type)->first_match_wins();
            if ($first_match && isset($claimed[$type])) {
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

            if ($first_match) {
                $claimed[$type] = true;
            }
            $applied[]      = ['rule' => $rule, 'before' => $data, 'after' => $next];
            $data           = $next;
        }

        return ['before' => $before, 'after' => $data, 'applied' => $applied];
    }

    /**
     * Apply ONE rule to ONE entity's data. The sole caller of `apply_to_data()`.
     *
     * Private, and called only from `compute()` — the pass and its preview —
     * so every format execution is one of those two. H13 checks the call site.
     *
     * @param int                $post_id Entity being passed over.
     * @param array              $data    Post data as the previous rule left it.
     * @param array              $rule    One enabled rule (canonical shape).
     * @param UnifiedHandlerBase $handler The rule type's handler.
     * @return array|null Post data, or null when the rule does not apply here.
     */
    private static function apply(int $post_id, array $data, array $rule, UnifiedHandlerBase $handler): ?array {
        return $handler->apply_to_data($data, $post_id, $rule);
    }

    /**
     * Write a `compute()` result: each answering rule's `commit_data()`, the
     * computed fields (`write_fields()`), then the row, if it differs from what
     * the row holds.
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
     * @return int Fields written.
     */
    private function write(int $post_id, array $result): int {
        foreach ($result['applied'] as $step) {
            $this->handlers[$step['rule']['type']]->commit_data($step['before'], $step['after'], $post_id, $step['rule']);
        }

        $before = $result['before'];
        $after  = $result['after'];
        $update = ['ID' => $post_id];

        $written = $this->write_fields($post_id, $after);

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
            return $written;
        }

        add_filter('wp_revisions_to_keep', '__return_zero');
        try {
            wp_update_post($update);
        } finally {
            remove_filter('wp_revisions_to_keep', '__return_zero');
        }

        return $written;
    }

    /**
     * Persist the pass's computed fields: each value that differs from what the
     * field holds, written by ACF field KEY so ACF maintains the field's
     * reference row. The write fires neither `acf/save_post` nor `save_post`, so
     * it provokes no pass of its own; the write queue's `acf/update_value`
     * listener is switched off for it, or every write would queue a second,
     * idempotent pass at shutdown.
     *
     * SLASHED, like the row update below: `update_field()` unslashes its value
     * on the way to `update_metadata()`, so an unslashed `a` stores as `ab`.
     *
     * An unchanged value is not written, and an all-empty result writes `''` —
     * the stored value must not outlive its sources. Compared as strings: ACF
     * stores every supported target type as one.
     *
     * @param int   $post_id Entity.
     * @param array $after   `compute()`'s final data.
     * @return int Fields written.
     */
    private function write_fields(int $post_id, array $after): int {
        $written = 0;
        if (empty($after['fields']) || !function_exists('update_field')) {
            return $written;
        }

        add_filter('meta_conductor_acf_reapply_enabled', '__return_false');
        try {
            foreach ($after['fields'] as $name => $value) {
                $key = (string) ($after['field_keys'][$name] ?? '');
                if ($key !== '' && (string) get_post_meta($post_id, $name, true) !== (string) $value) {
                    update_field($key, wp_slash((string) $value), $post_id);
                    $written++;
                }
            }
        } finally {
            remove_filter('meta_conductor_acf_reapply_enabled', '__return_false');
        }

        return $written;
    }

    /**
     * The post fields a format applier may read, and what it may change: the
     * title and slug, and the `fields` map.
     *
     * A plain array rather than the WP_Post, because the seam is data-in/
     * data-out: an applier composes with the one before it by editing what it
     * was handed.
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
            // Field effects: computed values by field NAME, and the ACF field
            // KEY each is written by. Empty until a Computed field rule sets one.
            'fields'      => [],
            'field_keys'  => [],
        ];
    }

    /**
     * Whether a pass may run for this entity. THE authoritative off switch.
     *
     * Same two-filter shape and the same reasoning as
     * `TermDispatcher::pass_enabled()`: `meta_conductor_acf_reapply_enabled` is
     * the established "do not recompute rules for this post" switch and every
     * existing user of it — imports and the fixture seeder's
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
