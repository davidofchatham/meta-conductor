# Architecture

How Meta Conductor's pieces fit together. For planned work see [future-work.md](future-work.md). For release log see [CHANGELOG.md](../CHANGELOG.md).

> **Scope note.** This file is intentionally conceptual. Per-class detail (exact class names, method lists, file paths) drifts every phase and is NOT mirrored here — the code is the source of truth for that. PHPDoc on the enforcing class carries the load-bearing invariants.

## Layers

```
WordPress hooks (save_post, set_object_terms, etc.)
    ↓
Term dispatcher (marks entities dirty, drains the queue)
    ↓
Per entity: term pass, then format pass (each rule in authored order)
    ↓
Handlers (one per rule type, appliers only)
    ↓
Shared primitives (TermOperations / AcfBridge)
    ↓
WordPress core (posts, terms, users, comments)
```

Key boundaries (the rules that matter, regardless of class names):

- **Handlers call WordPress core directly** for posts and terms. The shared primitives are `TermOperations` (apply / remove / membership, and `compute_end_state()` as the only encoding of merge / replace / skip) and `AcfBridge` (ACF taxonomy-field read / write). The old `Core\Entity` wrapper was never adopted by the dispatcher-era handlers and is deleted.
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
   (#37; future-work.md → FW-19)

7. **Storage write results must propagate; cache must mirror storage.**
   `update_option` returns false for BOTH a no-op-equal write AND a real failure
   — never ignore the bool, and don't let the request cache adopt data that
   didn't persist (it ghost-persists on the next save). Distinguish equal-vs-fail
   by re-reading. (R5#5/R6#4/R8#1/R8#3.) `OptionRuleStorage::save_all_settings()`
   is where that re-read lives, and it returns true when the option ROUND-TRIPS
   — write succeeded, or the bytes already matched — so every mutator can trust
   the bool. (#27, closed in #66; the per-mutator statement is under
   *Effect-kind rule lists*.)

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

10. **Parent↔child term sync is reconciled on the CHILD, honoring
    `conflict_handling`.** A child holds its parent's terms under the rule's
    claim: merge = additive, replace = overwrite, skip = only-if-empty. The
    invariant is about WHERE the reconcile happens, and it has survived two
    rewrites of HOW. Originally it had to fire on the child's own `save_post`
    (post has `post_parent > 0`) rather than the `wp_insert_post`
    `$update===false` path, which fires at auto-draft creation before
    parent/terms exist and is skipped at the real update save — so a new child
    never inherited until the parent was re-saved. Since **#62** the child is
    reconciled by its own full ordered pass (invariant #17): propagation's
    applier PULLS from the parent and writes only the post it was handed, and
    the parent's fan-out is what marks the child dirty. `conflict_handling`
    still defines the ongoing semantics — replace = always-sync, skip =
    inherit-once, merge = additive — and the direction is no longer symmetric
    because there is only one: every post reads its own parent. The
    `$processing` reentrancy guard is GONE with the hooks; the pass lock is the
    only guard, and a second one silences the author's own chain.
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
    Related: a sever queue must be keyed by something that will actually reach
    the code that acts on it. Before #63 that meant the post whose save DRAINS
    the entry, because nothing else would ever look; keying by the post that
    caused the sever put it under a post that is never saved in that request, so
    it silently never drained. With a dirty-entity queue the natural key is the
    post to RECOMPUTE — the applier asks with exactly that — and the entities
    reach a pass through `drain_captures()` rather than through somebody else's
    save (see `RelatedPostTermsHandler`).

16. **Anything that writes ACF fields in bulk must stand the queue down.** The
    queue cannot tell a user edit from a bulk rewrite; both are `update_field()`.
    So a bulk writer that does not want one full rule recompute per post has to
    say so, via `meta_conductor_acf_reapply_enabled`, scoped to the call rather
    than the request. Two known cases: WordPress imports (automatic, via
    `WP_IMPORTING`) and the fixture seeder — which learned it the hard way, its
    "empty the rules → write content → restore the rules" model silently broken
    by a flush that now runs AFTER the restore. The plugin's own bulk apply is
    exempt: it does not write through ACF.

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
    is `TermDispatcher::CAPTURE_HOOKS`; `propagation_rules` is its first entry
    (#62, see the cross-entity bullet below) and `related_post_terms_rules` its
    second and larger one (#63, three hooks). H13 checks both halves — that the
    hook is registered, and that its callback writes nothing.

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

    **A cross-entity rule PULLS, and DECLARES its reach; it does not write the
    far entity (#62).** `apply_to_post` writes the entity it was handed and
    nothing else, so an entity written from inside another entity's pass is
    reconciled by ONE rule, out of authored order, with the rest of the list
    then running against it from whatever hooks fire. That is exactly #35:
    propagation walked its descendants and wrote them, the child's
    `set_object_terms` woke hierarchical on its own hook, and the child gained
    an expansion level nobody had authored. The split that dissolves it:

    - The applier INVERTS to a pull. `apply_to_post(P, rule)` reconciles P by
      READING the entities the rule points at — for propagation, P's parent.
      Every post that needs reconciling therefore arrives as the subject of its
      own full ordered pass.
    - The handler separately DECLARES its reach through `fan_out(int, array):
      array` on `UnifiedHandlerBase` (empty by default). The dispatcher marks
      what it returns dirty, once per rule per pass, whether or not the apply
      changed anything — the common case is a parent whose own terms a user
      edited, where the applier correctly reports no change to the parent and
      the children are precisely what must now be reconciled.
    - Declare NEIGHBOURS, not closures. Returning the immediate children puts
      the recursion in the queue, where the drain's one-pass-per-entity bound is
      the termination argument; returning the whole subtree would re-enqueue the
      same posts from every ancestor for the same result.

    This also kills the propagation-before-hierarchical instantiation-order
    dependency, which existed only because `TaxonomyManager` happened to
    construct the two handlers in that sequence. Both orders are now authorable
    and produce different, documented results (fixture matrix §62c/§62d).

    **The unit of execution is a rule ROW, and for a multi-row type that is a
    behaviour change, not a refactor (#63).** `related_post_terms` was the last
    conversion and the one where this bites: it ran *every* rule it owned from
    each of its own hooks, so however many rows it held it occupied ONE slot in
    the order and two of its rows straddling another type's rule was
    inexpressible — the failure mode ADR 0003 rejected type-level ordering for.
    Splitting it per row is what makes the position of each row mean something,
    and it necessarily changes what two rows in one taxonomy do: they used to
    union into a single write and they now compose by order, so a keep-in-sync
    (*owning*) row ordered last replaces what an earlier one wrote. One rule's
    own multiple sources still union — that is the rule resolving its
    jurisdiction, not two rules contending. A conversion that preserved the
    union would have had to keep the type as one slot, which is the thing being
    removed.

    The corollary is that a **captured sever must be recorded against the rule
    whose link was cut**, not against the taxonomy. That record is the one thing
    permitted to bypass the zero-source gate (invariant #2) — the one thing that
    lets a rule empty a post it resolves no source for — and while a single
    cross-rule write could afford a taxonomy key (it summed `source_count` over
    every rule before writing anything), a per-row applier cannot: a link cut
    under one row would license an unrelated row to empty-replace the taxonomy
    over what an earlier row had just legitimately written.

    **A capture can name an entity no fan-out can reach, and needs its own way
    into the queue (#63).** A fan-out is asked while passing over a post and
    names entities reachable *from* it. A sever capture exists precisely because
    the link that made the far entity reachable is what the write destroyed — or
    because the post holding it was deleted — so there is nothing left to
    declare from. The handler therefore hands those entities over through
    `UnifiedHandlerBase::drain_captures()`, which `TermDispatcher::drain()` asks
    every converted handler once per drain, before the loop. Which capture types
    need one is enumerated (`CAPTURE_QUEUE_TYPES`), not inferred: propagation's
    capture is consumed by an applier the queue was going to run anyway, so
    having no override is correct there and a silent dead end for a sever.
    Asked rather than
    pushed: a capture callback that marked dirty itself would be reaching into
    the dispatcher, and "a capture records and applies nothing" would stop being
    a property H13 can read off the callback body. Consuming rather than
    repeating: a handler that returned the same IDs on every call would refill
    the queue faster than the drain empties it. The visible payoff is that a
    bare `update_field()` sever — no `save_post`, no `acf/save_post` — now
    reconciles, which was a documented dead end before (invariant #15's note,
    fixture matrix §4).

    **The one thing live state cannot answer is a removal, and that is what a
    capture hook is for.** A term the parent HELD and then lost is, on the
    child, indistinguishable from a term the child holds independently — no
    provenance is recorded anywhere — yet under `merge` the two must be treated
    differently. So propagation captures the removal as it happens
    (`deleted_term_relationships`, the only hook that sees a
    `wp_remove_object_terms`) into a request-scoped map, and each child's
    applier subtracts what its parent lost. The capture is filtered to terms the
    parent does not currently hold NATIVELY, which subsumes the #45
    ACF-mirror-lag bounce *and* distinguishes a remove-then-re-add in the same
    request, which the pre-#62 blanket exclude list could not.

18. **Cross-KIND order is derived, and it holds because one drain runs both
    passes in sequence — not because of hook priorities (#64,
    [ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md) decision 2).**
    Within a kind the author orders the rules; between kinds the engine does,
    because the edges are producer→consumer rather than collisions. `title_slug`
    reads terms (`{term:TAX}`, `{terms:TAX}`) and writes none, so it is a sink:
    terms must settle first.

    Until #64 that order rested on two accidents — `TitleSlugHandler` registered
    at `acf/save_post`/`save_post` priority **99**, above every term handler,
    and `TaxonomyManager` constructed it **last**. Coalescing the term pass onto
    a late drain (#60) would have inverted both, and the result is a title
    computed from the *previous* save's terms: entirely plausible output, no
    error, nothing to observe. Both accidents die here.

    - **`Core\FormatDispatcher` owns the `format_rules` kind, and owns no
      queue.** `TermDispatcher` keeps the triggers and the dirty set for both;
      each drain step is `run_entity()` — the term pass, then the format pass,
      for the same entity. Two queues would have to be kept in step with each
      other for the derived order to mean anything, which is the coupling one
      queue removes. H13 pins the sequence, both halves: that the format pass is
      reached from the drain at all, and that it is reached *after* the term
      pass.
    - **The format applier seam is data-in/data-out**, not `apply_to_post`. A term rule's effect is a set of term relationships, so its applier writes and reports a boolean; a format rule's effect is the post ROW, and several rules can land on one row. So `apply_to_data(array, int, array): ?array` hands post data along the list and the *dispatcher* performs the single `wp_update_post()` at the end — one save, one row update, however many rules matched. It is also the shape the deferred two-phase split needs: when `field_transformation` lands, a pre-write phase can feed `wp_insert_post_data`'s own `$data` to the same seam. `null` means "this rule does not apply here", which is distinct from returning the data unchanged, and the difference is what decides first-match-wins. The applier writes nothing at all, not even per-rule state: `FormatDispatcher::run_pass()` is `compute()` (runs the appliers, returns before/after, writes nothing — the format preview's entry point) then `write()`, which calls each answering rule's `commit_data()` (title/slug's idempotency meta) before the row update.
    - **The pre-write phase could not survive the conversion.** `title_slug` ran
      half of itself on `wp_insert_post_data` so the editor saw final values
      without a second update. That half runs *before* terms land, so a
      `{term:TAX}` pattern there reads the previous save's terms — the exact
      defect this invariant exists to remove. Every apply is post-write now, at
      the cost of one extra `wp_update_post()` per save that changes something,
      with the revision suppressed and the redirect's slug cache flushed (both
      moved onto the dispatcher with the write they compensate for).
    - **First match of a type wins, and the dispatcher enforces it.** A
      title/slug rule set is a lookup table keyed by post type, not a set of
      independently-scoped rules (#59), so once a rule of a type has claimed the
      entity the rest of that type's rules are skipped. Keeping that decision in
      the dispatcher is what lets the handler stay stateless; keying it per TYPE
      rather than per pass is what leaves a second format rule type free to
      compose with this one in list order.
    - **The handler's whole request-scoped apparatus goes with the hooks.** Five
      maps and four hooks became one method. `$is_updating_post` guarded
      re-entry from a write the handler no longer makes; `$handled_pre_write` /
      `$processed_in_request` separated two paths that are now one;
      `$pending_submitted_titles` captured a value that, post-write, is simply
      `post_title`. The idempotency meta is now written from the *resolved base*
      rather than the submitted title, which is what makes a re-pass over an
      unchanged post record the same pair back instead of compounding.

19. **A collision is DETECTED and named, never resolved (#65,
    [ADR 0002](adr/0002-cross-rule-composition.md) decision 2,
    [ADR 0004](adr/0004-claim-axis-and-jurisdiction.md)).** Two rules contend
    when they write the same **effect target** on posts that can be the same
    posts. [`Admin\CollisionDetector`](../includes/admin/class-collision-detector.php)
    says so and does nothing else: advisory at authoring time, never consulted
    at runtime, never blocking a save. That is not a limitation — a collision
    means the combined result depends on **order**, which the author now
    controls, and picking a winner silently is the behaviour the ordered list
    exists to replace.

    - **The detector resolves each rule's effect target, not one field name.**
      The obvious predicate — shared `taxonomy` plus overlapping post types —
      reads four of the six term types and is *blind* to the other two:
      `time_based_rules` and `related_rules` have no `taxonomy` subfield at all
      (H11's expected-visible map is the proof) and name their target by
      `target_term_id`. So the target key is a taxonomy for the four that
      declare one, a **term** for the two term-pairing types, and the
      title/slug fields for the format kind. Keying the term-pairing types on
      the term rather than on the term's taxonomy is deliberate: two date rules
      in one taxonomy with different targets need not contend, and taxonomy
      keying would warn on every pair of them. An advisory that fires that
      often gets ignored, which is worse than none.
    - **The post types are the ones a rule WRITES.** Two rule types do not
      answer that with `post_types`: the format types name one in a scalar
      `post_type`, and the ACF-reference rule has no `post_types` subfield (#58)
      and writes its *dependent* end — unconstrained under `holder_role=source`,
      exactly as `dependent_post_type()` reports it. Empty means every post
      type, so the test over-reports and never under-reports.
    - **Targets must be EQUAL, not nested.** A taxonomy-wide rule is not paired
      with a term rule whose term lives in that taxonomy, and the claim axis is
      not consulted at all. Both pairings are real, and both belong to the
      deferred reach/component detector, which takes ADR 0004's second conjunct
      — reaches intersect **and** jurisdictions overlap. Nothing here should be
      read as "shared reach alone implies contention". H14 asserts the
      non-pairing so the boundary is a decision on the record.
    - **Where a pair is precisely diagnosable, the warning names the
      contradiction.** A hierarchy rule that adds a lineage beside a level
      restriction that does not undertake to keep it (#51) reads *"adds ancestor
      terms … does not keep them"*, not "these two collide". Two rules over one
      term that each remove it (#69, the mutual annihilation ADR 0001 names as
      the cost of retroactive ownership) say so. Two format rules on one post
      type say the lower one never runs.
    - **Two surfaces, one detector.** `settings_saved` — and `settings_reset`,
      which wipes the very rules a stored finding names — recompute from storage
      and persist into the detector's own option, rendered as the notice leading
      the relevant tab on the next load. That is the passive half, for an author
      who never thinks to check. An `ActionField` button re-runs the same
      `detect()` over the **in-flight** form values and answers the open UI,
      persisting nothing: the rows it read were never saved, and writing them
      into the notice would make the passive surface describe a rule set that
      does not exist.
    - **A named contradiction is asymmetric, so its pair is ROLE-ordered.** The
      message puts the rule that *adds* a lineage first and the one that does
      not keep it second; ordering the pair by list position instead would make
      the notice say the restriction rule adds ancestors whenever it happens to
      be authored above. Each side still carries its own list index, which is
      what the position numbers and the "lower in the list acts last" sentence
      refer to. Two smaller asymmetries in the same area: `row_title` is stored
      *already* `esc_html()`-ed (the repeater's `title_template` renders raw),
      so the detector decodes it and escapes once at the end; and the hierarchy
      outcome comes from `HierarchicalHandler::behavior_key()` rather than a
      second reading of the legacy `hierarchy_direction`/`expansion_behavior`
      pair, which is the re-derived-switch drift invariant 17's cousins warn
      about.

    H14 (`tests/verify-collision-detector.php`) pins the predicate in both
    directions, opening with a partition check that every rule type storage
    knows resolves to a target scheme — a type added to storage and nowhere else
    fails there rather than being silently skipped. `sweep-65-collisions.php`
    runs it over the real fixture and through both real hooks.

## Settings UI — WP Wireframe

The settings UI is a React app provided by `tdrayson/wp-wireframe`. Config classes live under [includes/admin/config/](../includes/admin/config/), each exposing a `section()` method; the top-level composer assembles **three tabs** from them:

| Tab | Sections |
|---|---|
| Auto-Set & Restrict | The collision advisory (#65), then **the ordered term-rule list** ([TermRulesConfig](../includes/admin/config/class-term-rules-config.php)) — all six term rule types (#57, #58) |
| Format & Transform | The collision advisory (#65), then **the ordered format-rule list** ([FormatRulesConfig](../includes/admin/config/class-format-rules-config.php)) — `title_slug` today (#59). Future: date / name / phone field transforms |
| General | Per-taxonomy claim overrides |

Boot path: [class-wireframe-bootstrap.php](../includes/admin/class-wireframe-bootstrap.php) calls `\Wireframe\App::boot()` on `init` priority 10 with the assembled config.

### The ordered rule repeaters

**One repeater per effect kind**, each bound to that kind's persisted list — `term_rules` (#57, #58) and `format_rules` (#59) ([ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md)). No tab holds a per-type section any more. Each row carries a `type` select — storing the **legacy type key verbatim** (`hierarchical_rules`, not `hierarchical`), so `get_enabled_rules()` filters on `get_rule_type()` with no mapping table — and every type-specific subfield is `conditions`-gated on it. Restrict stopped being a tab because a level-restriction rule writes terms like every other rule in the list; Personalize went because it described rule types that do not exist yet.

Two Wireframe constraints drive the shape, both verified against the vendored 1.0.6: there is **no cross-repeater ordering primitive** (so an ordered list spanning rule types must be one repeater), and there is **no flexible content** (so that repeater has one fixed subfield superset, gated per type).

**The hazard, and why it earns a harness.** `RepeaterField::sanitize` rebuilds each row from *declared subfields only*, skipping any whose `conditions` evaluate false, and runs **before** the `wp-wireframe/save/payload` filter. So an undeclared key cannot be injected post-hoc, and a condition-hidden subfield is **dropped from storage entirely**. Three consequences:

- A wrong gate is silent **data loss**, not a rendering bug. Nothing errors; the value just stops persisting.
- Two subfields sharing an `id` let one overwrite the other, last-write-wins, invisibly.
- Changing a row's `type` **discards its type-specific values** — which is the correct behaviour and how the design works, but it is silent, so the `type` select's description says so.

Both rule tabs are LED by the collision advisory's section (#65, invariant 19) — one section builder serving both, with the persisted notice when there is one and the on-demand re-check button always. It is its own section rather than a field prepended to the rule section, so each `section()` stays "the ordered list and nothing else".

H11 (`tests/verify-term-rules-config.php`) and H12 (`tests/verify-format-rules-config.php`) run Wireframe's own `Conditions::evaluate()` over each config and assert the exact visible subfield set per rule type, plus id uniqueness, that shared subfields carry no gate, and that claim comes through `ConfigHelpers::claim_field()` rather than being re-authored inline. Every show/hide combination is additionally swept on the testbed (`sweep-58-roundtrip.php`, `sweep-59-roundtrip.php`) — the harness proves the config's shape, only a real save proves the round-trip.

**A one-type list still needs the harness, and the dangerous direction inverts.** On `term_rules` the classic bug is a gate that is too narrow. On `format_rules`, where there is one rule type, the bug is a gate that exists at all where it should not: it evaluates false for the only type there is and deletes the field outright. So H12 pins the shared frame's ungatedness first.

Row titles are a **save-time snapshot** (`row_title`, rendered by `title_template`), assembled by `snapshot_term_rule_labels()` / `snapshot_format_rule_labels()` dispatching on the row's `type`. The format list gained one in #59 despite `{name}` having been interpolable live: a substitution-only template cannot carry the disabled marker, the post-type scope, or a second rule type's schema, and adding the snapshot later would be the restructure #59 exists to avoid.

`WireframeBootstrap::repair_stored_rules()` runs on admin load, before `App::boot()`, and exists because **Wireframe reads the settings option raw** — it does not pass through the storage layer's read-time adapters, so anything a handler tolerates on read but the config does not declare gets rewritten by the first save (`RepeaterField::sanitize` rebuilds each row from declared subfields, filling defaults). That is invariant #1's hazard. What it does now is backfill a `row_title`, on **both** kind lists, for any rule that reached storage some other way. The term list's shape repairs that used to run beside it (`#16` `inheritance_behavior`, legacy related term ids, the ACF-reference key rename) were deleted with FW-39 once no stored row was in a legacy shape. Both kinds are repaired in ONE `update_option`, so two writes cannot leave the two lists belonging to different admin loads.

### Why Wireframe

Replaces a ~2,000-line hand-rolled god-class settings file plus 1,700-line jQuery template-cloning admin.js. Wireframe gives us:

- React-based repeater fields with sortable/collapsible/duplicate
- Server-side validation via Rakit
- REST endpoints for save/load
- `@wordpress/components` UI consistency

### Wireframe quirks worth knowing

- **No client-side field type extension API** (stock Wireframe). Custom field types declared via `wp-wireframe/field_types` filter handle sanitize/validate server-side but don't render in React. Use stock field types only — *unless* we ship our own Wireframe fork that adds the JS registry (Gap A), which is buildable and fork-releasable: see [FW-23](future-work.md#fw-23--client-side-custom-field-types).
- **Dot-notation field IDs are skipped by Sanitizer** ([src/Framework/Sanitizer.php](../vendor/tdrayson/wp-wireframe/src/Framework/Sanitizer.php)). Don't use `field.subkey` syntax for save fields — use repeaters with subfields instead.

Fixed upstream in 1.0.6 (no longer quirks): single-page `App::boot()` honors `menu_slug` (#5); subfield-level `conditions` evaluate client-side (#13); admin-screen body class anchors on the `_page_{menu_slug}` suffix instead of a substring (#6).

## Canonical shape adapter

Wireframe writes some fields differently than handlers expect (e.g. a `multiple+max=1` FormTokenField writes `[id]` where a handler wants `int`; an ACF field select writes `"post_type:field_name:field_key"` where a handler wants the three parts separately). The storage layer's `normalize_rule_shape()` coerces these on read.

The projection is a **guarantee**, not a convenience (FW-29): checkbox gates (`post_types`, `post_status`, `filter_taxonomies`) arrive as slug lists (empty = all; a legacy `{slug:bool}` map is decoded here), `target_term_id` as `int`, `trigger_term_id` and `filter_terms` as `int[]`. No consumer re-decodes or re-casts them, and runtime code never imports `Admin\Config` to do so. Admin code holding raw form values — the row-title snapshot, the on-demand collision check — runs them through `OptionRuleStorage::project_kind_rules()` first. The projection is read-only, so widening it needs no migration.

Storage is the adapter boundary between writers (current: Wireframe REST) and handlers — future writers (CLI, import) plug in at the same boundary. **Caveat:** a key-RENAMING migration here is read-time-only and the Wireframe admin reads the option RAW, so a renamed/removed key must ALSO be persisted (one-time rewrite) or the admin renders defaults and corrupts on resave. (`WireframeBootstrap::repair_stored_rules()` was that rewrite until FW-39 deleted the migrations; it now only backfills row titles.)

### ACF field identity (#25)

An ACF field is identified by its **key**, never by its bare name. `acf_get_field($name)` resolves a name through a `get_posts()` lookup capped at one row, so two separately-created fields sharing a bare name — including two on the *same* post type, which is what real sites accumulate — resolve to whichever ACF returns first. Worse, the result is not even stable within a site: `acf_get_field()` caches what it resolves and aliases the name to that key for the rest of the request, so any post-scoped read or write of the field "repairs" the alias and a later bare-name lookup happens to be right. A bug that corrects itself depending on what else ran in the request is one that will be found in production, not in a test.

The identity therefore travels in the stored value, which is also the select's option key and so must stay one round-tripping string: `post_type:field_name:field_key`.

- **post_type** cannot be derived from the key — a field group may be located on several post types, which is why the options list emits one row per post-type/field pair.
- **field_name** stays because reading a VALUE (`get_field($name, $post_id)`) is post-scoped and was never ambiguous, and because it is what a lookup falls back to when a key no longer resolves (deleted field, a row imported from another site). That fallback is a safeguard, not a correctness requirement: both consumers already degrade permissively when a lookup finds nothing — empty target types mean "cannot narrow", an empty partner list means "fall to the tier-3 scan" — so a dead key costs the slow path and a bare-name row title, never a wrong write. It is verified in `acf_selector()` rather than assumed.
- **field_key** is what separates the twins.

Three lookups consume it — the bidirectional partner resolution, the target-post-type eligibility pre-filter, and the row-title label — all through `$key ?: $name`. A legacy two-part value parses to an empty key and resolves by name: pre-#25 behavior, never worse. The 0.9.x one-shot backfilled keys where the name resolved to exactly one field and **left an ambiguous name alone** rather than guessing at a field the author never chose; those rows keep working by name until re-picked, which the group-titled option labels make possible. The backfill itself was deleted after 0.9.x, the two-part parser was not.

## Effect-kind rule lists

Rules are stored as **two ordered lists keyed by effect kind** — `term_rules` (six types) and `format_rules` (`title_slug`) — each row carrying its own `type`, with order being array position. This is the model [ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md) settles on, the shape the Phase 4 dispatcher iterates, and since **#66** the only shape storage holds.

It arrived expand-first (#56): from #56 to #64 the two lists lived alongside the seven type-keyed arrays that predate them, so each rule type could move to the dispatcher one at a time. The contract ticket (#66) deleted the old shape once the last conversion landed. What that leaves:

| | Kind lists (2) |
|---|---|
| Written by | the ordered repeater (raw `update_option` via Wireframe) |
| Read by | `get_kind_rules()` — the dispatcher's pass, `get_enabled_rules()`, `get_rules()`, and the settings page |
| Authority | **rules and order both** |

`get_kind_rules()` serves the persisted list **verbatim**. Nothing regroups or re-sorts it, because order is the composition semantics a pass executes in (ADR 0003 decision 3) — a hierarchical rule sequenced after a level-restriction rule has to run after it. `get_rules($type)` is that read with a `['type' => $type]` filter; it is a VIEW, not a second path.

### The per-type `$rule_id`

`get_rule($type, $rule_id)` identifies a rule by its index **within its own type**, across a cross-type list. `id` deliberately did **not** re-base onto the kind-list position: it is the number `get_rule()` speaks, so a rule's `id` and the index it is addressed by stay the same number. It is still positional and re-derived on read, so nothing may persist state keyed on it — the title/slug status record that once did was deleted for exactly that reason.

The storage interface (`RuleStorage`) declares only what has callers — `get_kind_rules`, `get_rules`, `get_rule`, `get_raw_settings`, `clear_cache` — and `StorageFactory` only `get_instance()`. The type-addressed CRUD (save / delete / toggle / duplicate / search / import / export) had no caller and is gone; rules are written by the ordered repeater. FW-17 grows the interface back if a second backend lands.

### A pre-0.8.0 site

The migration off the seven type-keyed arrays shipped in 0.8.0–0.9.x and was deleted after (FW-39). Storage reads the kind lists only; an absent kind list reads as empty. A site that jumps straight from a pre-0.8.0 version therefore runs no rules, so `OptionRuleStorage::holds_pre_08_rows()` drives an admin error notice telling the author to pass through 0.9.x. It fires only on legacy **rows** with no kind list — empty legacy arrays (an old install with no rules) have nothing to lose and raise nothing. Fresh installs seed the two kind keys directly.

**`CONFIG_MIGRATED_TYPES`** is the list of types with repeater subfields. A type is added to it in the same change that gives it those subfields, never before: the repeater renders every row in the key it is bound to, and `RepeaterField::sanitize` drops any subfield the config does not declare — so a row the repeater has no subfields for would be gutted on the next save. As of #59 every rule type is in, making it identical to the flattened `KIND_TYPES`; it stays a separate constant precisely so the next type can be declared in `KIND_TYPES` — and therefore read — a change before its subfields exist.

Invariants asserted by H10 (`tests/verify-kind-lists.php`):

- **The pre-0.8.0 guard** fires on legacy rows with no kind list, and on nothing else.
- **`id` is the per-type index**, not the kind-list position.
- **`KIND_TYPES` is the enumeration.** `all_types()` flattens it and `get_kind_for_type()` inverts it, so there is no second list for it to drift out of step with — which is what lets `get_enabled_rules()` carry no fallback.

## Apply to Existing Posts

[includes/core/class-existing-posts-applier.php](../includes/core/class-existing-posts-applier.php) · [includes/admin/class-apply-page.php](../includes/admin/class-apply-page.php)

The plugin's one bulk mechanism. A Wireframe subpage under the Meta Conductor menu picks a rule (or *All enabled rules*) and hands the choice to `ExistingPostsApplier`, which names every post in that rule's reach to the term dispatcher and drains it — the same full ordered pass a save runs, never a separate apply. A disabled choice is a one-time run for that batch only; large runs proceed in time-boxed batches behind Continue. It replaced the Data Conversion page, whose Copy / Map jobs return as rule types applied through it.

## Diagnostics page

[includes/admin/class-diagnostics.php](../includes/admin/class-diagnostics.php)

Subpage under Meta Conductor menu. Visible when `WP_DEBUG` is on, or via filter `bws_meta_conductor_show_diagnostics`. Dev-only Storage section dumps the raw option contents; future user-level sections (rule counts, handler status) will hang here without dev mode.

## Naming surface

The rename splits by layer: anything users, translators or the WP admin UI see drops `BWS`; anything stored in a global PHP / JS / DB namespace where another plugin could collide keeps it.

| Layer | Value |
|---|---|
| Plugin display name | `Meta Conductor` |
| Plugin folder / main file / text domain | `meta-conductor` / `meta-conductor.php` / `meta-conductor` |
| Plugin constants | `META_CONDUCTOR_*` (no `BWS_META_MANAGER_*` / `BWS_TAX_MANAGER_*` aliases — no external consumer) |
| PHP namespace | `BWS\MetaConductor\` |
| Option keys, nonce actions, hook/filter prefix | `bws_meta_conductor_*` |
| JS localized object | `bwsMetaConductor` |

The internal function names `bws_meta_manager_init` and `bws_taxonomy_manager_activate` / `_deactivate` / `_uninstall` still carry the old prefix. They are not user-facing and nothing depends on them, so rename them when you are already touching that code.
