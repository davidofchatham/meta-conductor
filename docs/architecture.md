# Architecture

How Meta Conductor's pieces fit together. For project status and phase plan see [ROADMAP.md](../ROADMAP.md). For release log see [CHANGELOG.md](../CHANGELOG.md).

> **Scope note.** This file is intentionally conceptual. Per-class detail (exact class names, method lists, file paths) drifts every phase and is NOT mirrored here — the code is the source of truth for that. PHPDoc on the enforcing class carries the load-bearing invariants.

## Three-layer rule engine

```
WordPress hooks (save_post, etc.)
    ↓
Handlers (one per rule type)
    ↓
Rule Engine (orchestrator)  ←→  Condition Evaluator + Action Executor
    ↓
Entity Abstraction (Core\Entity)
    ↓
WordPress core (posts, terms, users, comments)
```

Key boundaries (the rules that matter, regardless of class names):

- **Handlers never touch `get_post()` / `get_term()` / `get_user_meta()` directly** — they go through `Core\Entity`, the polymorphic wrapper over WP entities. Rule logic stays agnostic about the underlying WP storage.
- **Handlers never call `get_option()` directly** — they read/write through the storage layer (`Storage\StorageFactory`), which is also the canonical-shape adapter (see below).
- **One `wp_options` key** (`bws_meta_conductor_settings`) holds every rule type, each an array of rule rows, plus a few global keys (per-taxonomy conflict overrides, manual-processing toggle).
- **One handler base**: `UnifiedHandlerBase` (typed PHP 8.1 helpers, storage-backed), composing the `TermOperations` and `AcfBridge` traits. All 7 handlers extend it; the legacy `HandlerBase` was deleted in 0.6.0 when the last handler migrated.

## Writing a rule handler — hard-won invariants

Distilled from the 0.5.0 ACF-reference rework and its eight review rounds. These
are cross-handler traps, not ACF-specific. Read before building or migrating a
handler (temporal-rule, status-mirroring, and the Phase-4 rule-list rework —
seven type-keyed arrays → two ordered per-effect-kind lists — all hit several).

1. **Wireframe reads the option RAW** (`get_option`, no filter seam), bypassing
   `normalize_rule_shape`. A read-time migration that RENAMES or REMOVES a key is
   invisible to the admin form → the form binds the new key, finds it absent,
   falls back to the config default, and a resave PERSISTS that default
   (corruption). Any key-renaming migration needs a one-time, flag-gated option
   REWRITE, not just read-time normalization. (B6) Directional adapters that
   reshape the SAME key (array↔scalar) are safe — the admin round-trips them.
   **The Phase-4 rule-list rework is exactly this trap, at the top-level-key
   level:** folding the seven per-type arrays into `term_rules` / `format_rules`
   must REWRITE storage before Wireframe reads the option raw, or the repeaters
   render empty and a resave wipes every rule. It also needs a **read-time
   adapter**, not only the rewrite — `WireframeBootstrap::boot` runs on admin and
   REST requests only, while handlers read storage on front-end and cron saves.
   (ADR 0003)

2. **Never gate a destructive write on post-type match alone.** A rule whose
   target type is `''`=any matches every post; combined with a replace/remove
   mode it wipes unrelated posts. Require positive evidence the rule MANAGES this
   object (e.g. a resolved source present) before any remove/replace. A "force"
   flag must be scoped to the exact case that needs it (the true orphan,
   `source_count===0`), never used as a blanket replace trigger — that strips
   sibling add-only rules' contributions. (B3/V13, R6#1)

3. **Cache DATA, never DECISIONS.** Memoizing a pure lookup (relationship graph
   read, ACF field config) is safe and request-lived. Memoizing a write/skip
   DECISION that depends on mutable state (status gate, time window) is a bug:
   the state can change between the two fires of one save, and the cached
   decision masks it. (B5/V15 — a recompute-result cache hid a publish→draft
   transition.) Invalidate a data cache on the mutation that changes its inputs.

4. **The save_post + acf/save_post double-fire is universal.** Every ACF-aware
   handler runs twice per admin save. Make writes idempotent (short-circuit on
   no-change) and cascade-guarded, scoped to (post, taxonomy); never assume
   "runs once". Conversely, a bare programmatic `update_field()` fires
   `acf/update_value` but NEITHER save hook — document that callers must fire
   `acf/save_post`/`wp_update_post` to flush deferred work. (V11, R8#2)

5. **Delete has no field-update hook.** Sever/cleanup on permanent delete needs
   `before_delete_post` (capture while the post still resolves) + `deleted_post`
   (act after it's gone, so it no longer counts as its own remaining source).
   Guard against revision/autosave IDs. (R2#4, R5#4, R7#4)

6. **ACF field identity is by KEY, not name.** `acf_get_field($name)` returns an
   arbitrary field when two groups share a bare name; `bidirectional_target` is a
   LIST of partner keys (resolve all); the old value at `acf/update_value` time
   is an impl-detail ordering (have a `get_post_meta` fallback); relationship
   values serialize as an INT array (`i:42;`, not `s:2:"42"`). Resolve by key;
   never assume serialization format. (#25, R2#2, R4#1, B4) **Group prefix is a
   name-matching landmine of the same class:** a field inside an ACF Group stores
   and reads by the GROUP-QUALIFIED name (`get_field('group_sub')` resolves), but
   its runtime "name" — `acf/update_value`'s `$field['name']` AND Admin Columns
   v7's `AC\Column\Context::get_meta_key()` — is the BARE subfield (`sub`, prefix
   stripped). Matching a stored qualified name against either by `===` silently
   misses. `related_post_terms` matches by name and is verified for TOP-LEVEL
   relationship fields only; `ConfigHelpers::acf_relationship_field_options()`
   lists top-level fields only (`acf_get_fields($group_key)` doesn't recurse
   Group/Repeater/Flex), so the picker can't offer a nested field and the config
   UI warns as much. Any nested-field support must match by field KEY or reconcile
   bare↔qualified. The AC v7 `ac/editing/saved` reapply fallback (#37) matches
   `get_meta_key()` — correct for top-level, revisit if nested is ever supported.
   (#37; future-features.md → grouped relationship fields)

7. **Storage write results must propagate; cache must mirror storage.**
   `update_option` returns false for BOTH a no-op-equal write AND a real failure
   — never ignore the bool, and don't let the request cache adopt data that
   didn't persist (it ghost-persists on the next save). Distinguish equal-vs-fail
   by re-reading. (R5#5/R6#4/R8#1/R8#3; tracked as issue #27)

8. **Pre-filter site-wide hooks in BOTH directions.** Global `save_post` /
   `set_object_terms` / `acf/update_value` hooks fire for every post on the site;
   gate eligibility (is this post a plausible source AND/OR dependent of any
   rule?) before any expensive reverse lookup or query. (B7/V17)

9. **Sibling-conditional subfields use real `conditions`, not description text.**
   Wireframe 1.0.6 (#13) evaluates subfield-level `conditions` against the other
   subfields in the same repeater row, client- AND server-side
   ([Conditions.php](../vendor/tdrayson/wp-wireframe/src/Framework/Conditions.php),
   `RepeaterField` honors it). A subfield only meaningful when a sibling holds a
   given value gets a `conditions` node (`{field, operator, value}`, or `all`/`any`
   combinators; `in`/`not_in` for multi-value) — NOT a "Only used when X…"
   description. **When migrating or revisiting any rule type, convert its
   description-text conditionals to real `conditions`.** Caveat: a condition-hidden
   subfield is dropped from the save payload (not persisted), so the handler reads
   it absent (falsy/default) — make sure that's the intended hidden-state value.
   The canonical example is now the ordered term-rule repeater (0.8.0, #57),
   where a single `type` select gates every type-specific subfield — and where
   the drop-on-hide behaviour is load-bearing rather than incidental: changing a
   row's type is HOW its old type's values are discarded. `tests/verify-term-rules-config.php`
   (H11) and `tests/verify-format-rules-config.php` (H12) run the real
   `Conditions::evaluate()` over each config and assert the visible set per
   type, because a wrong gate is silent data loss. Both rule tabs are ordered
   repeaters as of #59, so no config carries the old description-text
   workaround any more. (don't #3, SPEC §V11)

10. **Parent↔child term sync fires on the CHILD's own `save_post`, honoring
    `conflict_handling`.** A child inherits its parent's terms via
    `inherit_terms_from_parent` (merge = additive, replace = overwrite, skip =
    only-if-empty). This MUST trigger on the child's own `save_post` (post has
    `post_parent > 0`), NOT the `wp_insert_post` `$update===false` path — that
    fires at auto-draft creation before parent/terms exist and is skipped at the
    real update save, so a new child never inherits until the parent is later
    re-saved. `conflict_handling` defines the ongoing sync semantics: replace =
    always-sync, skip = inherit-once, merge = additive. Downward (parent→children)
    and upward (child←parent) are symmetric, both on `save_post`, both
    conflict-aware; guard reentrancy (`$processing`) so the write's
    `set_object_terms` cascade doesn't re-enter within one request (see #4).
    (propagation; was SPEC §V12/B3)

11. **A base-class flip must port EVERY base method the handler still calls, not
    just the obvious primitives.** Re-parenting a handler (e.g. `HandlerBase →
    UnifiedHandlerBase`) silently drops any method that lived only on the old
    base; the call site then resolves to nothing → undefined-method fatal at
    runtime, INVISIBLE to `php -l` (H1) and the autoload harness (H2), which check
    declarations, not method resolution. Before deleting an old base, grep the
    handler for every `$this->`/`parent::` call and confirm each target exists on
    the new base, a trait the base composes, or the handler itself. This bit the
    0.6.0 migration: the ACF helpers (`get_acf_taxonomy_value`/`set_acf_taxonomy_value`)
    lived only on `HandlerBase` and had to be ported before deletion; the fatal
    only fires the first time a post has a matching ACF taxonomy field, so
    native-only testing misses it. The same trap re-opens if these primitives are
    composed per-handler rather than on the base: a later handler that calls one
    without `use`-ing the trait resolves to nothing. As of 0.6.3 they live in the
    `TermOperations`/`AcfBridge` traits, `use`d on `UnifiedHandlerBase` itself so
    every handler inherits them — grep the trait files, not just the base body.
    (was SPEC §V14/B4)

12. **Discover a post's ACF fields by LOCATION rules, not by stored values.**
    `get_field_objects($post_id)` enumerates from stored ACF meta and returns
    FALSE for a post with no saved values — so a field that is *attached* (via
    field-group location rules) but *empty* is invisible. A handler that needs to
    POPULATE such a field for the first time (propagation writing a child that has
    no ACF value yet) hits a chicken-and-egg: no value → not discovered → never
    written. Use `acf_get_field_groups(['post_id' => $id])` + `acf_get_fields()`
    (the same engine the ACF admin uses; value-independent, respects location
    rules; recurse `sub_fields` for nested fields) — see
    `UnifiedHandlerBase::get_acf_taxonomy_fields()` (defined in trait `AcfBridge`,
    `class-acf-bridge.php`, as of 0.6.3). And on a FIRST write pass the
    field KEY (not name) to `update_field()`, so ACF registers the hidden
    `_{name}` reference row (name-only first writes save a bare meta value
    `get_field()` can't later resolve). Extends #6 (identity by key). Still open
    in level-restriction + related handlers (#41). (0.6.0 ACF sweep)

13. **An external-plugin integration writes term-sync through the plugin's real
    write hook, and detects that plugin by a verified surface — not an assumed
    class name.** Two halves, both learned from the Admin Columns v7 fix (#37):

    - *Which hook.* An editor add-on (Admin Columns, bulk editors, REST) may not
      fire the `save_post`/`acf/save_post` hooks a handler's apply path listens
      on. AC v7 writes ACF fields via `update_field()` → fires `acf/update_value`
      ONLY; native taxonomy columns via `wp_set_object_terms` → fires
      `set_object_terms` (handlers already catch that). So the ACF-column path
      needs a bridge: AC v7's post-persist `ac/editing/saved` action → a shared
      `UnifiedHandlerBase::reapply_for_post(int $post_id)` (no-op default; the
      ACF-listening handlers that still own their hooks override it to delegate
      to their own gated `on_acf_save_post`). A CONVERTED handler has no
      override and no `on_acf_save_post` to delegate to — the ACF write queue
      marks the post dirty instead and the dispatcher's pass does the work
      (#60), so the seam shrinks with each conversion and goes at #66. The bridge does NOT dispatch by column type — it hands
      the post ID to EVERY handler, each self-gating, mirroring how `save_post`
      fires for every post. Hook post-persist, never the pre-write
      `acf/update_value` (the capture path reads OLD there — see the sever model).
    - *How to detect the plugin.* Gate on a surface you have VERIFIED exists in
      the target version — a constant or bootstrap class you've grepped for — not
      a class name you assume. AC Pro v7 has NO `ACP\Plugin` class (it uses
      `ACP\Loader` + defines `ACP_VERSION`), so `class_exists('ACP\Plugin')` is
      silently false on v7 and the whole integration dead-registers. This is
      INVISIBLE to `php -l`/autoload and reads as correct on inspection — only a
      live target-version site reveals it. Gate on `defined('ACP_VERSION')`.
      Guarded by `tests/verify-acp-gate.php` (H7). (#37; was SPEC §V1/§V5/§V6/§V7,
      B3. AC-agnostic follow-up #42; dependent-end sever gap #43.)

    **Superseded in part by #14.** The per-integration bridge above still exists
    (AC's `ac/editing/saved` → `AcfWriteQueue::flush_post`) so an inline-edit
    response is accurate before shutdown, but it is no longer how coverage is
    achieved — the queue covers every ACF writer, including ones no bridge was
    written for. Build a new bridge only to make a specific integration's
    response *timely*, never to make it *work*.

14. **The ACF write signal is `acf/update_value`, not the save hooks — but you
    RECORD there and APPLY later.** Every handler gates its apply on the
    `save_post` family, and a large class of ACF writes never fires those hooks
    at all: `update_field()` fires `acf/update_value` and nothing else. That
    covers Admin Columns inline/bulk edits, bare programmatic writes (custom
    code, WP-CLI, cron) and REST writes to an ACF field. Gating on the save
    hooks silently misses all of them — the field changes, the rule never runs,
    the post keeps stale terms with no warning (#42). `Core\AcfWriteQueue` is
    the one mechanism that closes this; five constraints make it correct, and
    each is load-bearing:

    - *Record pre-write, apply post-write.* `acf/update_value` fires BEFORE the
      value is persisted, so a listener there must never read the post — it
      records the ID only. Every apply happens later (`shutdown`, or a bounded
      mid-request flush). This is what keeps the queue clear of the pre-write
      hazard the sever capture has to reason about (#12).
    - *Gate on the ACF TARGET, never the field type.* A post is recorded only
      when ACF's `$post_id` resolves to a positive integer, which naturally
      excludes the `options` / `user_N` / `term_N` pseudo-targets. Narrowing by
      field type reintroduces a smaller version of the same bug: the handlers
      key off relationship, post-object, taxonomy and plain-text fields
      respectively, so any type filter is a new hole.
    - *The ordinary save path claims its own posts.* An editor/REST post save
      already ran every handler, so the queue drops those posts on
      `save_post`/`acf/save_post` at a priority ABOVE every handler. Below that,
      the claim lands before the handlers run and NEITHER path applies the post.
    - *The disable gate belongs on the apply step, not the listener.* Every
      flush path funnels through one `apply()`, so that is the only place "turn
      the whole behaviour off" can be honoured; gating only the listener leaves
      the one-post flush applying regardless — precisely the path an admin is
      trying to silence. The listener keeps an early-out, but the decision has
      ONE site, and the target gate runs BEFORE it so the filter is always
      handed a real post ID.
    - *A flush path mutates the pending set INSIDE the reentrancy guard.* The
      guard returns without running its body when a flush is already in
      progress, so a mutation outside it happens on a call that then does
      nothing — the post is dropped from the pending set and never applied,
      neither applied nor pending, stale terms surviving with no signal.

    All five are pure hook constants and control-flow gates with no
    runtime-observable signature until they are ALREADY wrong on a live site, so
    they are guarded by source inspection: `tests/verify-acf-write-queue.php`
    (H8), the same way and for the same reason as the AC gate guard.

15. **Every END of a relationship that can drop a link needs its own capture
    shape.** Direction of the RULE and direction of the EDIT are independent, so
    a push rule edited at the dependent end is a different code path from a push
    rule edited at the holder end — and from either end of a pull rule. Enumerate
    the matrix; do not assume the rule's direction tells you where the edit lands.
    This is recorded because it was the second omission of the same class: B8
    added the pull-rule source-end case, #43 the push-rule dependent-end case.
    Related: a sever queue must be keyed by the post whose save DRAINS it, not by
    the post that caused it — anything else keys under a post that is never saved
    in that request and silently never drains (see `RelatedPostTermsHandler`).

16. **Anything that writes ACF fields in bulk must stand the queue down.** The
    queue cannot tell a user edit from a bulk rewrite; both are `update_field()`.
    So a bulk writer that does not want one full rule recompute per post has to
    say so, via `meta_conductor_acf_reapply_enabled`, scoped to the call rather
    than the request. Three known cases: WordPress imports (automatic, via
    `WP_IMPORTING`), the conversion tool, and the fixture seeder — which learned
    it the hard way, its "empty the rules → write content → restore the rules"
    model silently broken by a flush that now runs AFTER the restore. The
    plugin's own bulk apply action is exempt: it does not write through ACF.

17. **A converted handler owns no hooks, and the dispatcher is the only caller
    of `apply_to_post`.** This INVERTS the rule every hook-driven handler was
    written to (#60, [ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md)
    decisions 3 and 4). `Core\TermDispatcher` owns the trigger union for the
    `term_rules` kind; handlers of the types it has taken over are pure appliers
    on the `apply_to_post(int, array): bool` seam. The conversion is
    incremental, so both regimes are live — `CONVERTED_TYPES` and
    `UNCONVERTED_TYPES` on the dispatcher name which is which, and
    `tests/verify-term-dispatcher.php` (H13) fails if a handler's registrations
    disagree with the side it is listed on. Four things make it correct:

    - *Triggers mark, they never execute.* One editor save fires `save_post`,
      `acf/save_post` and one `set_object_terms` per taxonomy touched. Executing
      per trigger is six passes, five of them over half-written state. Each
      trigger instead marks the entity dirty, and the queue drains once per
      request at `shutdown` — after `AcfWriteQueue::flush`, which marks the
      posts behind bare `update_field()` writes as it flushes. The observable
      cost is that the apply is no longer synchronous with the term write: a
      caller reading terms back in the same request sees pre-pass state unless
      it drains explicitly (`drain_post()`; what the AC inline-edit bridge uses
      so its response is not stale). There is a second drain on
      `wp_after_insert_post` for the same reason at editor scale — `shutdown`
      lands after the response, so without it a block-editor save would render
      the author's raw selection until reload. One pass per *save*, therefore,
      rather than always one per request: a block-editor save that writes ACF
      fields on `rest_after_insert_*` marks the post again and gets a second
      pass, which recomputes to the same state.
    - *The off switch sits on the pass, not on the triggers.* Same argument as
      #14's: `drain_post()` and bulk apply reach a pass directly, so gating the
      triggers would leave them running. `pass_enabled()` honours
      `meta_conductor_acf_reapply_enabled` first — every existing user of that
      filter means "do not recompute rules for this post", the fixture seeder's
      empty-rules-then-restore window included, which the drain would otherwise
      reopen — then `meta_conductor_term_pass_enabled` as the finer control.
    - *A pass runs the WHOLE list, not the rules whose trigger fired.* A rule
      consuming an earlier rule's write has no trigger for it — that write is
      exactly what the lock suppresses — so trigger-filtering would skip the
      consumer in precisely the case authored order exists to settle. Every
      enabled rule of the kind runs, in authored order, recomputing from live
      state. Idempotence is therefore the applier's contract: an override must
      be the handler's WHOLE apply, and must do nothing when nothing changed.
    - *The lock is pass-scoped and keyed (entity, effect kind).* Not
      request-scoped, which silences the author's own chain after its first
      write; not per-taxonomy, which lets a cross-taxonomy write start a nested
      pass and reintroduces cascade as a second composition mechanism competing
      with order. A converted handler carries **no** `private $processing`
      boolean — a second guard is not defence in depth, it is the defect class
      #35 was.
    - *The queue sits ABOVE the lock.* `mark_dirty()` consults the lock, so a
      rule's write to the entity under pass is the pass's own echo and is
      dropped, while a write to a DIFFERENT entity enqueues and gets its own
      pass in the same drain. That is how cross-entity effects still happen
      without nesting, and why a genuine cycle terminates on the first entity's
      held lock.

    A converted handler may keep an allow-listed **capture** hook — one that
    only snapshots pre-write state into a request-scoped queue, because the
    state it reads does not survive the write (#12). Capture is not execution;
    the captured value is consumed by the applier during a pass. The allow-list
    is `TermDispatcher::CAPTURE_HOOKS`, empty until `related_post_terms`
    converts (#63).

    **A provocation names entities; the pass decides their fate (#61).** Not
    every entry point is one of the dispatcher's own hooks — bulk apply and
    `time_based`'s daily expiry sweep are ordinary callers. Both mark entities
    dirty and drain; neither applies a rule. The sweep is the instructive one,
    because it used to do the opposite: it removed its own rule's target term
    from each post it found, which is one rule executed alone, outside any pass,
    in handler-map order. A term written that way was invisible to every rule
    that should have consumed it until somebody re-saved the post. Selecting the
    posts is the sweep's job; what happens to them is the pass's, which is what
    makes "the same pass however provoked" (CONTEXT.md → **Pass**) true of cron
    as well as of a save. A converted handler's non-apply hook therefore lives
    at its registration site — `TaxonomyManager` — not in the handler, so
    "a converted handler registers nothing" stays a line H13 can hold.

    **A delta is not available to an applier, so a rule that wanted one must be
    restated in terms of live state (#61).** `related`'s removal used to read
    `set_object_terms`' old/new term-taxonomy IDs and fire only when a trigger
    left in *that* write. A pass hands over rules, not deltas — deliberately,
    per the third bullet above — so the condition became "no trigger is present"
    rather than "a trigger just went". That is a real behaviour change on a live
    rule type, recorded as such in the changelog, and it is the shape every
    remaining conversion should expect to hit: the alternative, capturing the
    delta, buys exact parity at the cost of the rule no longer being a function
    of live state, which makes bulk and cron disagree with a save.

## Settings UI — WP Wireframe

The settings UI is a React app provided by `tdrayson/wp-wireframe`. Config classes live under [includes/admin/config/](../includes/admin/config/), each exposing a `section()` method; the top-level composer assembles **three tabs** from them:

| Tab | Sections |
|---|---|
| Auto-Set & Restrict | **The ordered term-rule list** ([TermRulesConfig](../includes/admin/config/class-term-rules-config.php)) — all six term rule types (#57, #58) |
| Format & Transform | **The ordered format-rule list** ([FormatRulesConfig](../includes/admin/config/class-format-rules-config.php)) — `title_slug` today (#59). Future: date / name / phone field transforms |
| General | Per-taxonomy claim overrides, manual processing toggle |

Boot path: [class-wireframe-bootstrap.php](../includes/admin/class-wireframe-bootstrap.php) calls `\Wireframe\App::boot()` on `init` priority 10 with the assembled config.

### The ordered rule repeaters

**One repeater per effect kind**, each bound to that kind's persisted list — `term_rules` (#57, #58) and `format_rules` (#59) ([ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md)). No tab holds a per-type section any more. Each row carries a `type` select — storing the **legacy type key verbatim** (`hierarchical_rules`, not `hierarchical`), so `get_enabled_rules()` filters on `get_rule_type()` with no mapping table — and every type-specific subfield is `conditions`-gated on it. Restrict stopped being a tab because a level-restriction rule writes terms like every other rule in the list; Personalize went because it described rule types that do not exist yet.

Two Wireframe constraints drive the shape, both verified against the vendored 1.0.6: there is **no cross-repeater ordering primitive** (so an ordered list spanning rule types must be one repeater), and there is **no flexible content** (so that repeater has one fixed subfield superset, gated per type).

**The hazard, and why it earns a harness.** `RepeaterField::sanitize` rebuilds each row from *declared subfields only*, skipping any whose `conditions` evaluate false, and runs **before** the `wp-wireframe/save/payload` filter. So an undeclared key cannot be injected post-hoc, and a condition-hidden subfield is **dropped from storage entirely**. Three consequences:

- A wrong gate is silent **data loss**, not a rendering bug. Nothing errors; the value just stops persisting.
- Two subfields sharing an `id` let one overwrite the other, last-write-wins, invisibly.
- Changing a row's `type` **discards its type-specific values** — which is the correct behaviour and how the design works, but it is silent, so the `type` select's description says so.

H11 (`tests/verify-term-rules-config.php`) and H12 (`tests/verify-format-rules-config.php`) run Wireframe's own `Conditions::evaluate()` over each config and assert the exact visible subfield set per rule type, plus id uniqueness, that shared subfields carry no gate, and that claim comes through `ConfigHelpers::claim_field()` rather than being re-authored inline. Every show/hide combination is additionally swept on the testbed (`sweep-58-roundtrip.php`, `sweep-59-roundtrip.php`) — the harness proves the config's shape, only a real save proves the round-trip.

**A one-type list still needs the harness, and the dangerous direction inverts.** On `term_rules` the classic bug is a gate that is too narrow. On `format_rules`, where there is one rule type, the bug is a gate that exists at all where it should not: it evaluates false for the only type there is and deletes the field outright. So H12 pins the shared frame's ungatedness first.

Row titles are a **save-time snapshot** (`row_title`, rendered by `title_template`), assembled by `snapshot_term_rule_labels()` / `snapshot_format_rule_labels()` dispatching on the row's `type`. The format list gained one in #59 despite `{name}` having been interpolable live: a substitution-only template cannot carry the disabled marker, the post-type scope, or a second rule type's schema, and adding the snapshot later would be the restructure #59 exists to avoid.

`WireframeBootstrap::repair_stored_rules()` runs on admin load, before `App::boot()`, and exists because **Wireframe reads the settings option raw** — it does not pass through the storage layer's read-time adapters, so anything a handler tolerates on read but the config does not declare gets rewritten by the first save (`RepeaterField::sanitize` rebuilds each row from declared subfields, filling defaults). That is invariant #1's hazard, and it takes two kinds of repair: the term list's shape migrations (`#16` `inheritance_behavior`, legacy related term ids, the ACF-reference key rename), and — on **both** kind lists since #59 — backfilling a `row_title` for any rule that reached storage some other way. `title_slug_rules` needs no shape migration: it moved into the format repeater with its stored keys unchanged. Everything writes through `fan_out_rule_lists()` in ONE `update_option`, so the ordered lists and the type-keyed arrays stay in agreement — repairing one side only would make `authored_kind_list()` distrust the list and throw the authored order away.

### Why Wireframe

Replaces a ~2,000-line hand-rolled god-class settings file plus 1,700-line jQuery template-cloning admin.js. Wireframe gives us:

- React-based repeater fields with sortable/collapsible/duplicate
- Server-side validation via Rakit
- REST endpoints for save/load
- `@wordpress/components` UI consistency

### Wireframe quirks worth knowing

- **No client-side field type extension API** (stock Wireframe). Custom field types declared via `wp-wireframe/field_types` filter handle sanitize/validate server-side but don't render in React. Use stock field types only — *unless* we ship our own Wireframe fork that adds the JS registry (Gap A), which is buildable and fork-releasable: see [.claude/plans/wireframe-js-field-type-extension-blocker.md](../.claude/plans/wireframe-js-field-type-extension-blocker.md).
- **Dot-notation field IDs are skipped by Sanitizer** ([src/Framework/Sanitizer.php](../vendor/tdrayson/wp-wireframe/src/Framework/Sanitizer.php)). Don't use `field.subkey` syntax for save fields — use repeaters with subfields instead.

Fixed upstream in 1.0.6 (no longer quirks): single-page `App::boot()` honors `menu_slug` (#5); subfield-level `conditions` evaluate client-side (#13); admin-screen body class anchors on the `_page_{menu_slug}` suffix instead of a substring (#6).

## Canonical shape adapter

Wireframe writes some fields differently than handlers expect (e.g. a `multiple+max=1` FormTokenField writes `[id]` where a handler wants `int`; an ACF field select writes `"post_type:field_name"` where a handler wants the bare name plus a separate post type). The storage layer's `normalize_rule_shape()` coerces these on read.

Storage is the adapter boundary between writers (current: Wireframe REST) and handlers — future writers (CLI, import) plug in at the same boundary. **Caveat:** a key-RENAMING migration here is read-time-only and the Wireframe admin reads the option RAW, so a renamed/removed key must ALSO be persisted (one-time rewrite) or the admin renders defaults and corrupts on resave. (See the ACF-reference migration; SPEC §V16 while active.)

## Effect-kind rule lists

Rules also exist as **two ordered lists keyed by effect kind** — `term_rules` (six types) and `format_rules` (`title_slug`) — each row carrying its own `type`, with order being array position. This is the model [ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md) settles on and the shape the Phase 4 dispatcher iterates.

It ships **expand-first**, so both shapes are live at once and the split of duties matters:

| | Type-keyed arrays (7) | Kind lists (2) |
|---|---|---|
| Written by | the ordered repeater's save-time projection, plus every storage-layer save | the ordered repeater (raw `update_option` via Wireframe), plus `sync_kind_lists()` |
| Read by | `get_rules()`, everything pre-existing | `get_kind_rules()` → `get_enabled_rules()`, and the settings page |
| Authority today | **yes, for behaviour** | **yes, for order** — but the rules themselves are derived on read |

`get_kind_rules()` **derives the list at read time** via `fan_in()` rather than reading the persisted copy. The type-keyed arrays are what every handler's behaviour hangs off, so deriving is the only way a front-end or cron request — which never reaches the admin-gated `WireframeBootstrap::boot` — is guaranteed to see what the admin last saved. It also means no admin save can desync behaviour, and an emptied rule set cannot resurrect from a stale persisted copy. Authority flips to the persisted copy in the contract ticket (#66), when the type-keyed path is deleted.

### Authored order vs derived rules

The config collapse (#57, #58, #59) made the persisted `term_rules` and `format_rules` keys the things the settings page **writes**, which splits the two lists' roles in a way worth stating plainly:

- The **rules** are derived. `WireframeBootstrap::fan_out_rule_lists()` projects each saved row back into its type-keyed array on the same save, so the derived read keeps seeing repeater edits, and a deleted row actually stops firing (the projection writes an *empty* array rather than omitting the key — Wireframe merges rather than replaces, so an absent key would leave the rule in place).
- The **order** is authored, and only the persisted list has it. `fan_in()` groups by type, so it can never reproduce a list where a level-restriction rule sits above a propagation rule.

  Since #60 that order has a consumer, and it reads the persisted list: `get_authored_kind_rules()` is the dispatcher's path, `get_kind_rules()` stays every handler's. Two reads of one kind is not duplication but a division of authority while both shapes are live — the persisted list is authoritative for ORDER, the type-keyed arrays for MEMBERSHIP, and `authored_kind_list()` is where the two are reconciled (keep the stored order while it still agrees per type; rebuild in `KIND_TYPES` order when a writer went behind the repeater's back). Handlers keep the derived read because they filter to their own type, where the two orders are the same sequence. The pair collapses at #66.

`sync_kind_lists()` therefore stopped being a plain recompute, because on an authored list a recompute *is* a clobber. `authored_kind_list()` decides per kind:

| Kind has… | Behaviour |
|---|---|
| migrated types (both kinds since #59) | keep the stored list while `fan_out_types()` of it still equals the type-keyed arrays; otherwise rebuild |
| no migrated types (no kind, since #59) | plain `fan_in()` — a pure derived duplicate, #56's regime. Kept as what makes declaring a future type in `KIND_TYPES` safe a change before its subfields exist |

The rebuild path is what picks up a write that bypassed the page — a WP-CLI `save_rule()`, a seeded fixture, an import — at the cost of the authored order, which that writer never had. Still not gated on the schema flag, which stays a marker: a one-shot gate would refuse to ever repair the list again.

**Since #60 that cost is behavioural.** Authored order is what a pass executes in, so a rebuild can change the end state of every rule in the kind, and `import_rules()` / `duplicate_rule()` / a CLI `save_rule()` reorder the author's list as a side effect of adding one rule. Rebuilding remains right while the type-keyed arrays are authoritative for membership — hiding the new rules would be worse — but it is no longer free, and #66 resolves it by making the persisted list authoritative rather than reconciled.

**Types whose config has not collapsed yet are excluded from the persisted list.** The repeater renders every row in the key it is bound to, and `RepeaterField::sanitize` drops any subfield the config does not declare — so a row the repeater has no subfields for would be gutted on the next save. `CONFIG_MIGRATED_TYPES` is the list of types with repeater subfields, and a type is added to it in the same change that gives it those subfields, never before. As of #59 every rule type is in: batch 1 (#57) took the four term types not live on a real site, `related_rules` + `related_post_terms_rules` followed with their subfields (#58), and `title_slug_rules` joined the format repeater (#59). The list is now identical to the flattened `KIND_TYPES` and stays a separate constant precisely so the next type can be declared in `KIND_TYPES` — and therefore fanned in and read — a change before its subfields exist. Handlers are unaffected: they derive, so behaviour never depended on the persisted list's membership.

Three invariants hold the expand phase together, all asserted by H10 (`tests/verify-kind-lists.php`):

- **`fan_in()` is a pure regroup** — rows cross over verbatim plus a `type` key, with no shape coercion, so `fan_out(fan_in($s))` reproduces the type-keyed arrays byte-for-byte. Coercion stays at read time where it already was.
- **`id` stays the per-type index**, not the kind-list position. `TitleSlugHandler::write_rule_status()` persists per-rule state against it, so re-basing it would silently repoint every stored status. Both projections derive it from *position*, so they agree even on a sparse stored array — the kind list has no keys to preserve, so a key-based id would diverge silently.
- **The kind map and the storage layer's valid-type list are the same set.** A rule type added to one and not the other fails the harness instead of silently reading zero rules, which is what lets `get_enabled_rules()` carry no type-keyed fallback.

Together these make `get_kind_rules($kind, ['type' => X])` element-for-element equal to `get_rules(X)` — which is why every handler moved onto the kind list with no handler file changes.

## Data conversion tool

[includes/conversion/](../includes/conversion/)

Multi-step wizard for ACF → taxonomy data migration. Lives at the `meta-conductor-conversion` admin subpage under the Meta Conductor menu. Phase 7 of the [ROADMAP](../ROADMAP.md) absorbs this into a unified Migration / Preview tool that also hosts Title/Slug bulk-apply and future field transforms.

## Diagnostics page

[includes/admin/class-diagnostics.php](../includes/admin/class-diagnostics.php)

Subpage under Meta Conductor menu. Visible when `WP_DEBUG` is on, or via filter `bws_meta_conductor_show_diagnostics`. Dev-only Storage section dumps the raw option contents; future user-level sections (rule counts, handler status) will hang here without dev mode.

## Spec lifecycle

**Specs live in GitHub Issues.** A substantive feature gets an issue written by the `to-spec` skill — problem statement, user stories, implementation and testing decisions — and that issue is the spec for as long as the work is in flight. Decisions taken mid-build are recorded as comments on it, so the issue stays the single account of what was agreed and why.

Post-ship, the durable parts move to where the next person will actually look:

1. Load-bearing invariants migrate into PHPDoc on the enforcing function (closest to the code), or into this file when conceptual — as the ACF write queue's did, above.
2. Behaviour changes and new filters go to CHANGELOG.
3. Anything still open becomes its own Issue.
4. The spec issue closes with the PR.

**A root `SPEC.md` is no longer used** (retired 2026-08-12, at 0.7.0). It duplicated the issue, drifted from it, and its `§Vn` numbering restarted every feature — so a citation like "§V14" means a different invariant depending on which retired spec it came from. Historic `SPEC §Vn` references surviving in code comments are dead links; read them as "there was once a spec section here", and prefer the invariant list above. Replace them opportunistically as each file is touched, rather than in one sweep.
