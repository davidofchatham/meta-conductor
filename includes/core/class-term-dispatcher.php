<?php
/**
 * Term dispatcher — the sole entry point to term-rule execution.
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
 * Owns the trigger union for the `term_rules` effect kind, coalesces triggers
 * into a dirty-entity queue, and drains that queue as full ordered passes.
 *
 * WHY THIS EXISTS (ADR 0003 decision 3, #60). Handlers used to own their own
 * hooks, so execution order was whatever order `TaxonomyManager` happened to
 * construct them in, refracted through each handler's hook priorities. That is
 * not an order an author can express, and two of the four Phase-4 gates exist
 * because the resulting sequence was load-bearing and invisible. The dispatcher
 * takes the hooks; handlers become pure appliers on the `apply_to_post(int,
 * array): bool` seam that #31 already carved out for bulk apply. This inverts
 * handler-authoring invariant (a).
 *
 * THREE LAYERS, IN THIS ORDER. They are separate mechanisms and conflating any
 * two of them reintroduces a defect the design is built to avoid:
 *
 *   1. TRIGGERS mark an entity dirty. They never execute anything. A single
 *      editor save fires `save_post`, `acf/save_post` and one
 *      `set_object_terms` per taxonomy touched — six-ish entry points for one
 *      user action. Executing per trigger means six passes, five of them
 *      reading half-written state.
 *   2. The QUEUE coalesces. One save leaves one dirty entity, and the drain
 *      runs one pass for it. A write to a DIFFERENT entity during a pass (a
 *      child post, a related post) enqueues that entity, drained in the same
 *      flush — which is how cross-entity effects still happen without nesting.
 *      This generalises the claim/pending/flush mechanism `AcfWriteQueue`
 *      already runs for ACF writes.
 *   3. The LOCK is pass-scoped and keyed (entity, effect kind). It suppresses
 *      cascade: a rule's own write must not re-enter the pass it is inside.
 *
 * The queue sits ABOVE the lock. The lock decides whether a trigger for THIS
 * entity is a new signal or the pass's own echo; the queue decides when the
 * work happens.
 *
 * WHY A FULL PASS, NOT THE RULES WHOSE TRIGGER FIRED. Order is the composition
 * mechanism (CONTEXT.md → Order), and a rule that consumes an earlier rule's
 * write has no trigger of its own for that write — the earlier rule's
 * `wp_set_object_terms` is exactly what the lock suppresses. Filtering the pass
 * by trigger would therefore skip the consumer in precisely the case authored
 * order exists to settle. So every enabled rule in the kind list runs, in list
 * order, each recomputing from live state and doing nothing when nothing
 * changed. Idempotence is the handler's contract, not the dispatcher's.
 *
 * WHY THE LOCK IS NOT REQUEST-SCOPED, AND NOT PER-TAXONOMY. Request-scoped
 * would silence the author's own chain after its first rule wrote, which is the
 * whole chain. Per-taxonomy would let a cross-taxonomy write (`related` rules
 * are cross-taxonomy by construction) start a nested pass, reintroducing
 * cascade as a second composition mechanism competing with order — and making
 * termination depend on the taxonomy graph being acyclic. Keyed on
 * (entity, kind), a rule writing a different entity still starts that entity's
 * own pass and a genuine cycle terminates on the first entity's held lock.
 *
 * CONVERSION IS INCREMENTAL. `CONVERTED_TYPES` is what a pass runs;
 * `UNCONVERTED_TYPES` still own their hooks and are named here so each
 * conversion ticket shrinks a visible list. H13
 * (tests/verify-term-dispatcher.php) reads both constants and fails if a
 * handler's registrations disagree with the side it is listed on.
 */
class TermDispatcher {

    /** The effect kind this dispatcher owns. One dispatcher per kind (#64). */
    public const KIND = OptionRuleStorage::KIND_TERM;

    /**
     * Shutdown drain priority.
     *
     * MUST run after `AcfWriteQueue::flush` (registered at 10), which marks
     * the posts behind bare `update_field()` writes dirty as it applies the
     * handlers that still own their hooks. Draining first would leave those
     * entities queued with nothing left to drain them.
     */
    private const DRAIN_PRIORITY = 20;

    /**
     * Editor-visible drain priority on `wp_after_insert_post`.
     *
     * WHY A SECOND DRAIN POINT AT ALL. `shutdown` is the only drain guaranteed
     * to fire, but it fires after the response has been built — so a block
     * editor save would render the author's raw term selection and only show
     * the pass's result on reload. Under the old per-handler hooks the apply
     * happened inside `set_object_terms`, i.e. before the REST response, so
     * deferring everything to shutdown would be a visible regression rather
     * than a change of timing.
     *
     * `wp_after_insert_post` is the hook for it: it fires at the end of
     * `wp_insert_post`, once per save, AFTER terms and meta have landed, on
     * the classic editor, the block editor and REST alike.
     *
     * WHAT IT COSTS. A block-editor save that also writes ACF fields writes
     * them on `rest_after_insert_*`, which lands after this — so that save
     * marks the post dirty a second time and gets a second pass at shutdown.
     * That is a second genuine write, not a re-run of the same one, and a pass
     * recomputes from live state, so the result is the same either way. One
     * pass per SAVE, not always one per request.
     */
    private const SAVE_DRAIN_PRIORITY = 999;

    /**
     * Rule types the dispatcher executes, as pure appliers.
     *
     * A type here MUST register no apply hooks of its own; a type in
     * UNCONVERTED_TYPES MUST NOT be executed here, or its rules would run
     * twice — once from its hooks and once from the pass.
     *
     * @var string[]
     */
    private const CONVERTED_TYPES = [
        'hierarchical_rules',
        'hierarchical_level_restriction_rules',
    ];

    /**
     * Rule types that still own their apply hooks. MUST be empty by #66.
     *
     * Named rather than inferred so the list shrinking is visible in the diff
     * of each conversion ticket: #61 takes time_based + related, #62
     * propagation, #63 related_post_terms.
     *
     * @var string[]
     */
    private const UNCONVERTED_TYPES = [
        'propagation_rules',
        'related_post_terms_rules',
        'time_based_rules',
        'related_rules',
    ];

    /**
     * Hooks a CONVERTED handler may still register, by rule type.
     *
     * A capture hook only snapshots pre-write state into a request-scoped
     * queue, because the state it reads no longer exists after the write.
     * Capture is not execution: the captured value is consumed by the
     * handler's applier during a pass, so the dispatcher stays the sole caller
     * of `apply_to_post`.
     *
     * Empty today — neither converted type reads pre-write state.
     * `related_post_terms` is the type that will need entries here
     * (`capture_removed_dependents` on `acf/update_value/type=relationship`
     * and `.../type=post_object`, which read the dependent end of a
     * relationship before ACF overwrites it); it is unconverted, so every hook
     * it registers is still its own and none of them belong on this list yet.
     *
     * @var array<string,string[]>
     */
    private const CAPTURE_HOOKS = [];

    /**
     * Handlers keyed by RULE type (`hierarchical_rules`), not handler type.
     *
     * A kind-list row carries its rule type, so that is the key a pass has in
     * hand. Every handler is in the map, including format-kind ones — the map
     * is what `apply_rule()` resolves through, and a pass separately filters
     * to this kind's rules.
     *
     * @var array<string,UnifiedHandlerBase>
     */
    private array $handlers = [];

    /**
     * Entities awaiting a pass, as a hash SET (`[post_id => true]`) so the
     * six-ish triggers of one save coalesce into one entry.
     *
     * @var array<int,true>
     */
    private array $dirty = [];

    /** Drain re-entrancy guard — a pass's own writes must not nest a drain. */
    private bool $draining = false;

    /**
     * Pass locks, `"kind|entity"` => true.
     *
     * STATIC because the lock is a property of the request, not of the
     * dispatcher instance: `apply()` is a static choke point that bulk apply
     * routes through without holding a dispatcher, and a per-instance lock
     * would let those two paths run concurrent passes over one entity.
     *
     * @var array<string,true>
     */
    private static array $passes = [];

    /**
     * The registered dispatcher, for callers that hold no reference to it.
     *
     * `process_existing_posts()` is the one that matters: it lives on the
     * handler base, runs long after boot, and needs to ask for a pass rather
     * than loop its own rules. Set at register() rather than construction, so
     * "the live dispatcher" means one that actually owns the triggers.
     *
     * @var self|null
     */
    private static ?self $instance = null;

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
     * Whether a pass executes this rule type, as opposed to the type still
     * running itself off its own hooks.
     *
     * @param string $rule_type Rule type key.
     * @return bool
     */
    public static function owns(string $rule_type): bool {
        return in_array($rule_type, self::CONVERTED_TYPES, true);
    }

    /**
     * Register the trigger union and the drain.
     *
     * Every registration here is mark-only, so priority carries no meaning on
     * any of them — the pass happens at the drain. `set_object_terms` covers
     * both native term writes and anything that routes through
     * `wp_set_object_terms`; `save_post` covers a save that touched no terms
     * at all (a rule can key off post fields); `acf/save_post` covers the ACF
     * editor path. Bare `update_field()` writes fire none of these and reach
     * the queue through `AcfWriteQueue`, which marks them dirty as it flushes.
     */
    public function register(): void {
        self::$instance = $this;

        add_action('set_object_terms', [$this, 'on_terms_set'], 10, 6);
        add_action('save_post', [$this, 'on_save_post'], 10, 1);
        add_action('acf/save_post', [$this, 'on_acf_save_post'], 10, 1);

        // Editor-visible drain, then the guaranteed one. Both call the same
        // drain; the queue is what makes the pair produce one pass, not two.
        add_action('wp_after_insert_post', [$this, 'drain'], self::SAVE_DRAIN_PRIORITY, 0);
        add_action('shutdown', [$this, 'drain'], self::DRAIN_PRIORITY, 0);
    }

    // ---------------------------------------------------------------- triggers

    /**
     * Trigger: native term write. Marks only — see register().
     *
     * @param int    $object_id  Object the terms were set on.
     * @param mixed  $terms      Unused.
     * @param mixed  $tt_ids     Unused.
     * @param string $taxonomy   Unused — a pass is not scoped to a taxonomy.
     * @param mixed  $append     Unused.
     * @param mixed  $old_tt_ids Unused.
     */
    public function on_terms_set($object_id, $terms = null, $tt_ids = null, $taxonomy = '', $append = false, $old_tt_ids = null): void {
        $this->mark_dirty($object_id);
    }

    /**
     * Trigger: post save.
     *
     * @param mixed $post_id Post ID.
     */
    public function on_save_post($post_id): void {
        $this->mark_dirty($post_id);
    }

    /**
     * Trigger: ACF save.
     *
     * @param mixed $post_id ACF target — a post ID, or a pseudo-target such as
     *                       'options' / 'user_5' / 'term_3', which mark_dirty
     *                       rejects.
     */
    public function on_acf_save_post($post_id): void {
        $this->mark_dirty($post_id);
    }

    /**
     * Enqueue an entity for a pass.
     *
     * The lock check is what makes a rule's own write invisible to the queue:
     * during a pass on this entity every `set_object_terms` the pass causes
     * arrives here, and enqueuing them would run a second pass per rule that
     * wrote — cascade, wearing the queue's clothes. A write to a DIFFERENT
     * entity has no lock held for it and enqueues normally, which is how a
     * cross-entity effect gets its own pass in the same flush.
     *
     * @param mixed $entity_id Post ID, or anything else — non-posts are dropped.
     */
    public function mark_dirty($entity_id): void {
        if (!is_numeric($entity_id)) {
            return;
        }
        $id = (int) $entity_id;
        if ($id <= 0) {
            return;
        }
        if (\wp_is_post_autosave($id) || \wp_is_post_revision($id)) {
            return;
        }
        if (self::pass_active($id)) {
            return;
        }

        $this->dirty[$id] = true;
    }

    // ------------------------------------------------------------------ drain

    /**
     * Run one pass for every dirty entity.
     *
     * Primary trigger: `shutdown`, the only hook guaranteed to fire whatever
     * wrote — a cron sweep, a WP-CLI run, a REST field write. Draining once,
     * late, is what makes the pass count one-per-entity rather than
     * one-per-trigger.
     *
     * A pass can enqueue further entities (a rule writing a child post), so
     * this drains until empty; `$seen` bounds the loop at one pass per entity
     * per drain, the same bound `AcfWriteQueue::flush` uses.
     */
    public function drain(): void {
        if ($this->draining) {
            return;
        }
        $this->draining = true;

        try {
            $seen = [];
            while (!empty($this->dirty)) {
                $ids         = array_keys($this->dirty);
                $this->dirty = [];

                foreach ($ids as $id) {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $this->run_pass($id);
                }
            }
        } finally {
            $this->draining = false;
        }
    }

    /**
     * Run one pass for ONE entity immediately, ahead of the drain.
     *
     * For callers that build a response before `shutdown` and would otherwise
     * render pre-pass state — the Admin Columns v7 inline edit is the one that
     * exists today. Drops the entity from the queue first so the drain cannot
     * pass it a second time; the unset is inside the same guard for the reason
     * `AcfWriteQueue::flush_post` documents (a re-entrant call must leave the
     * entity queued, not consume it without running).
     *
     * @param int $post_id Entity to pass over.
     * @return int Rules that changed the entity.
     */
    public function drain_post(int $post_id): int {
        if ($post_id <= 0 || $this->draining) {
            return 0;
        }
        $this->draining = true;

        try {
            unset($this->dirty[$post_id]);
            return $this->run_pass($post_id);
        } finally {
            $this->draining = false;
        }
    }

    // ------------------------------------------------------------------- pass

    /**
     * One full ordered pass over one entity.
     *
     * Every enabled rule of this kind, in AUTHORED order, each recomputing from
     * live state. Rules of unconverted types are skipped — their own hooks
     * already ran them, and running them here too would double-apply.
     *
     * The lock is taken for the whole pass and released in `finally`, so a
     * handler throwing cannot strand it and silence the entity for the rest of
     * the request. Re-entry returns 0 rather than queueing: the pass in
     * progress reads live state per rule, so whatever provoked the re-entry is
     * already visible to every rule that has not run yet.
     *
     * @param int $post_id Entity to pass over.
     * @return int Rules that actually changed the entity.
     */
    public function run_pass(int $post_id): int {
        if ($post_id <= 0 || self::pass_active($post_id)) {
            return 0;
        }
        if (!$this->pass_enabled($post_id)) {
            return 0;
        }

        self::$passes[self::pass_key(self::KIND, $post_id)] = true;
        $changed = 0;

        try {
            foreach ($this->ordered_rules() as $rule) {
                $type = (string) ($rule['type'] ?? '');

                if (!in_array($type, self::CONVERTED_TYPES, true)) {
                    continue;
                }
                $handler = $this->handlers[$type] ?? null;
                if (!$handler) {
                    continue;
                }

                if (self::apply($post_id, $rule, $handler)) {
                    $changed++;
                }
            }
        } finally {
            unset(self::$passes[self::pass_key(self::KIND, $post_id)]);
        }

        return $changed;
    }

    /**
     * Whether a pass may run for this entity. THE authoritative off switch.
     *
     * It sits on `run_pass()` — the choke point every provocation funnels
     * through — for the reason `AcfWriteQueue::apply()` documents about its own
     * gate: gating the triggers instead would leave the direct entry points
     * (`drain_post()` from the Admin Columns bridge, `run_pass()` from bulk
     * apply) executing regardless, which is exactly what an admin is reaching
     * for while diagnosing something else.
     *
     * TWO FILTERS, DELIBERATELY. `meta_conductor_acf_reapply_enabled` is
     * consulted first because it is the established "do not recompute rules
     * for this post" switch, and every existing user of it means that here
     * too: WordPress imports (via `WP_IMPORTING`), the conversion tool's bulk
     * field writes, and the fixture seeder's empty-rules-then-restore window —
     * which would otherwise be reopened by this dispatcher, since the pass now
     * happens on a drain that lands after the seeder restores the rules. It
     * was named for the queue because the queue was the only thing that
     * recomputed out of band; the dispatcher inherits the meaning, not just
     * the name.
     *
     * `meta_conductor_term_pass_enabled` is then the finer control, for a site
     * that wants passes while the ACF reapply path is off.
     *
     * Bulk apply is gated too, unlike its unconverted-handler path. A site that
     * has turned recomputation off has turned it off; a bulk button that
     * quietly recomputed anyway would be the surprise, not the restriction.
     *
     * @param int $post_id Entity a pass is about to run for.
     * @return bool
     */
    private function pass_enabled(int $post_id): bool {
        $default = !(defined('WP_IMPORTING') && WP_IMPORTING);
        $default = (bool) apply_filters('meta_conductor_acf_reapply_enabled', $default, $post_id);

        return (bool) apply_filters('meta_conductor_term_pass_enabled', $default, $post_id);
    }

    /**
     * The enabled rules of this kind, in authored order.
     *
     * @return array[] Rows carrying `type`.
     */
    private function ordered_rules(): array {
        return StorageFactory::get_instance()
            ->get_authored_kind_rules(self::KIND, ['enabled' => true]);
    }

    /**
     * Apply ONE rule to ONE entity. The sole caller of `apply_to_post()`.
     *
     * Static, and taking its handler explicitly, so the paths that hold a rule
     * and a handler but not a dispatcher — `process_existing_posts()`, the bulk
     * primitive — route through the same choke point rather than reaching past
     * it. That is the property H13 checks: one call site in the whole plugin.
     *
     * Takes the pass lock when none is held, so a bulk apply's own writes are
     * suppressed exactly as a pass's are. When a pass IS in progress the lock
     * is already held and this leaves it alone — releasing it here would open
     * the rest of the pass to its own echo.
     *
     * @param int                $post_id Entity to apply to.
     * @param array              $rule    One enabled rule (canonical shape).
     * @param UnifiedHandlerBase $handler The rule type's handler.
     * @return bool Whether the rule actually changed the entity.
     */
    public static function apply(int $post_id, array $rule, UnifiedHandlerBase $handler): bool {
        // Key the lock on the handler's OWN kind, not this dispatcher's. Bulk
        // apply routes every handler through here, format-kind ones included,
        // and locking a title/slug apply under `term_rules` would suppress the
        // term enqueues its write legitimately causes.
        $kind = StorageFactory::get_instance()->get_kind_for_type($handler->rule_type());
        $key  = self::pass_key($kind !== '' ? $kind : self::KIND, $post_id);
        $held = isset(self::$passes[$key]);

        if (!$held) {
            self::$passes[$key] = true;
        }

        try {
            return $handler->apply_to_post($post_id, $rule);
        } finally {
            if (!$held) {
                unset(self::$passes[$key]);
            }
        }
    }

    // ------------------------------------------------------------------- lock

    /**
     * The pass lock key. Both halves are load-bearing: dropping the entity
     * makes the lock request-scoped, dropping the kind makes it global across
     * kinds — see the class docblock for what each of those breaks.
     *
     * @param string $kind      Effect kind under pass.
     * @param int    $entity_id Entity under pass.
     * @return string
     */
    private static function pass_key(string $kind, int $entity_id): string {
        return $kind . '|' . $entity_id;
    }

    /**
     * Whether a pass of the given kind is running for this entity.
     *
     * @param string $kind      Effect kind; defaults to this dispatcher's.
     * @param int    $entity_id Entity to test.
     * @return bool
     */
    public static function pass_active(int $entity_id, string $kind = self::KIND): bool {
        return isset(self::$passes[self::pass_key($kind, $entity_id)]);
    }
}
