<?php
/**
 * Main BWS Taxonomy Manager Class
 * 
 * @since 0.1.0
 */

namespace BWS\MetaConductor;

use BWS\MetaConductor\Handlers\TimeBasedHandler;
use BWS\MetaConductor\RuleTypes\Registry;
use BWS\MetaConductor\Core\AcfWriteQueue;
use BWS\MetaConductor\Core\TermDispatcher;
use BWS\MetaConductor\Core\FormatDispatcher;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class TaxonomyManager {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Handler instances
     */
    private $handlers = array();

    /**
     * ACF write queue — AC-agnostic reapply trigger (#42).
     *
     * @var AcfWriteQueue|null
     */
    private $acf_write_queue = null;

    /**
     * Term dispatcher — sole entry point to term-rule execution (#60).
     *
     * @var TermDispatcher|null
     */
    private $term_dispatcher = null;

    /**
     * Format dispatcher — sole entry point to format-rule execution (#64).
     *
     * @var FormatDispatcher|null
     */
    private $format_dispatcher = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the ACF write queue (#42).
     *
     * Exposed so a behavior sweep can drive flush_post() directly — the Admin
     * Columns entry point is otherwise only reachable from an admin request
     * with AC Pro loaded, which WP-CLI cannot produce (AC returns early on
     * !is_admin()).
     *
     * @return AcfWriteQueue|null
     */
    public function get_acf_write_queue() {
        return $this->acf_write_queue;
    }

    /**
     * Get the term dispatcher (#60).
     *
     * Exposed for the same reason as get_acf_write_queue(): a behaviour sweep
     * needs to provoke a pass directly (`run_pass`/`drain_post`) rather than
     * wait for the shutdown drain, which WP-CLI reaches only at the very end of
     * a run.
     *
     * @return TermDispatcher|null
     */
    public function get_term_dispatcher() {
        return $this->term_dispatcher;
    }

    /**
     * Get the format dispatcher (#64).
     *
     * Exposed for the same reason as get_term_dispatcher(). Note that a sweep
     * wanting the REAL sequence must drive the TERM dispatcher's drain — the
     * format pass runs inside its per-entity step, and calling this one alone
     * skips the term pass whose writes a `{term:TAX}` pattern reads.
     *
     * @return FormatDispatcher|null
     */
    public function get_format_dispatcher() {
        return $this->format_dispatcher;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Dependencies load on demand via the PSR-4 autoloader (autoload.php);
        // no manual require chain (Phase 2a).
        $this->init_handlers();
    }

    /**
     * Initialize handlers
     */
    private function init_handlers() {
        // Handlers take no constructor argument: they read rules through
        // StorageFactory, not through an injected settings object. The
        // `Settings` compat shell that used to be passed here died with the
        // last legacy handler (#55). Keyed by the stored rule type — the key
        // a kind-list row carries, so the dispatchers look handlers up by it.
        $this->handlers = array();
        foreach (Registry::all() as $type => $descriptor) {
            $class = $descriptor->handler_class();
            $this->handlers[$type] = new $class();
        }

        // The two dispatchers (#60, #64) — the sole entry points to rule
        // execution. Built BEFORE the ACF write queue because the queue marks
        // entities dirty on the term one: a bare update_field() fires none of
        // its triggers, so the queue is how that write reaches a pass.
        //
        // CONSTRUCTION ORDER NOW CARRIES NO EXECUTION MEANING AT ALL, which is
        // the point. Two dependencies used to live in the handler array above
        // and both are dead: propagation-before-hierarchical (#62 — both types
        // are converted, the pass runs them in authored order, and propagation
        // no longer writes a child that a hierarchical hook then expands out of
        // band), and title-slug-LAST (#64 — the only thing that used to order
        // term writes before title reads, now expressed by the term dispatcher
        // running the format pass inside its own drain step). The format
        // dispatcher is constructed first only because the term one takes it.
        $this->format_dispatcher = new FormatDispatcher($this->handlers);
        $this->format_dispatcher->register();

        $this->term_dispatcher = new TermDispatcher($this->handlers, $this->format_dispatcher);
        $this->term_dispatcher->register();

        // READ-TIME hooks, from the descriptors (FW-51): the filters a handler
        // serves to visitors — a permalink, a request. A handler registers
        // nothing itself, so what is declared here is the whole list, and H13
        // checks the callbacks only read. Next to the dispatchers because it is
        // the same question — which hooks does a rule type own — answered once.
        foreach (Registry::all() as $type => $descriptor) {
            foreach ($descriptor->runtime_hooks() as $hook => $spec) {
                add_filter($hook, [$this->handlers[$type], $spec[0]], $spec[1] ?? 10, $spec[2] ?? 1);
            }
        }

        // Time-based's daily sweep (#61). It lives HERE rather than in
        // TimeBasedHandler::init_hooks() because a converted handler must
        // register nothing at all — that is the bright line H13 holds, and
        // "nothing except the one hook that only enqueues" is not a line a
        // static check can hold. The sweep is a provocation like bulk apply,
        // not an apply: it selects the posts an expired rule still holds, marks
        // them dirty on the dispatcher above and drains ordered passes.
        add_action('bws_taxonomy_manager_cleanup', array($this->handlers[Registry::for_handler(TimeBasedHandler::class)->type()], 'cleanup_expired_rules'));

        // AC-agnostic ACF write queue (#42). Watches ACF's own write filter, so
        // EVERY write that bypasses the save_post family — AC v7 inline/bulk,
        // bare update_field(), REST — reapplies the handlers afterwards. This
        // generalises the #37 AC-only fallback below, which now just asks the
        // queue to flush one post immediately.
        $this->acf_write_queue = new AcfWriteQueue($this->term_dispatcher);
        $this->acf_write_queue->register();

        // Admin Columns v7 immediate flush (#37). Gate on the ACP_VERSION
        // constant (defined iff AC Pro active, any v7.x) — NOT a class_exists
        // on ACP\Plugin, which does NOT exist in v7 (B3/§V5). The
        // ac/editing/saved hook is itself v7-only, so const + hook name self-gate.
        // (SPEC §V1/§V3/§V5/§V6, B3)
        if (defined('ACP_VERSION')) {
            $this->register_admin_columns_v7_reapply();
        }

    }

    /**
     * Admin Columns v7 IMMEDIATE flush (#37, generalised by #42).
     *
     * AC v7 inline/bulk edits of an ACF field column write via update_field(),
     * which fires acf/update_value ONLY — never acf/save_post / save_post /
     * set_object_terms. So every handler whose apply is gated on the save_post
     * family (related-post-terms, related, level-restriction, propagation,
     * title-slug) silently fails to reapply after such an edit. (SPEC §V1/§V6)
     *
     * The apply itself is now AcfWriteQueue's job — the update_field() call has
     * ALREADY enqueued this post, and the shutdown flush would apply it anyway.
     * This hook exists only to make the apply happen EARLIER: AC builds its
     * inline-edit AJAX response before shutdown, so without it the column would
     * render pre-sync terms and the editor would see a stale value. flush_post
     * removes the post from the pending set, so there is no double apply.
     *
     * The native taxonomy-column path is already covered — AC v7 writes native
     * terms via wp_set_object_terms, which fires set_object_terms → the handlers'
     * own listeners run. So we act ONLY on ACF field columns
     * (\AC\Column\CustomFieldContext). (SPEC §V1/§V3)
     *
     * ac/editing/saved fires AFTER AC's storage write (InlineSave/BulkSave), so
     * reads see the new value — post-persist, no pre-write hazard. (SPEC §V2)
     */
    private function register_admin_columns_v7_reapply() {
        add_action('ac/editing/saved', function ($column, $id, $value, $table) {
            // ACF field columns only; native taxonomy edits self-cover (§V1).
            if (!$column instanceof \AC\Column\CustomFieldContext) {
                return;
            }

            $post_id = (int) $id;
            if ($post_id <= 0) {
                return;
            }

            $this->acf_write_queue->flush_post($post_id);
        }, 20, 4);
    }
    
    /**
     * Get handler instance
     *
     * @param string $type Stored rule type (`time_based_rules`).
     */
    public function get_handler($type) {
        return $this->handlers[$type] ?? null;
    }
}
