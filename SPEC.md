# SPEC — Phase 3 handler migration (finish) — 0.6.0, IN FLIGHT

Status: **planning → build**. Branch `claude/handler-migration-p3` off `main`. Slated `[0.6.0] — Unreleased`
in CHANGELOG; NO version-header bump, NO `v*` tag until release decided (may combine with later work).
Migrate the last 3 legacy handlers + coupled config label/UX pass, then delete legacy base + remove the
save loop. Truncate this file on merge+tag per lifecycle.

## §G — goal

Finish Phase 3: migrate 3 remaining handlers (level-restriction, propagation, time-based) from
`HandlerBase` → `UnifiedHandlerBase`; revise each handler's Wireframe config (labels + shared post-type
field) in the same step; then delete `class-handler-base.php` + remove the `on_post_save` handler loop.
Test-site data only — schema break (scalar `post_type` → plural `post_types`) is free; resave acceptable,
NO migration code.

## §C — constraints

- C1. **Test-site data only — schema break is free.** All 3 rule types have only InstaWP test rules (user
  confirmed). Scalar `post_type` → plural `post_types` needs NO `normalize_rule_shape` migration, NO resave
  gating. Old rules may need one manual resave; acceptable. (contrast 0.5.0 C1.)
- C2. PHP 8.1 coercive (no strict_types in handler files). Leading-backslash globals under ns (don't #0/§V13).
  No consecutive-cap class names (don't #0). H1 `php tests/lint.php` + H2 `php tests/verify-autoload.php`
  green after every class edit; new FQNs added to H2. (don't #0/#6)
- C3. **Base-flip rebinds helpers to TYPED versions.** `HandlerBase`→`UnifiedHandlerBase` retargets
  `apply_terms_to_post`/`remove_terms_from_post`/`post_has_terms`/`debug_log` to PHP-8.1-typed copies
  (`int $post_id`, `array $terms`). Coercive ⇒ numeric-string post_id binds, non-array `$terms` = TypeError.
  Audit every helper call site per handler. (don't #6, V1)
- C4. **Post-type gate via `should_process_post` only.** Reads `post_types` array/`{slug:bool}` map; empty =
  all. Replaces legacy `rule_applies_to_post` (scalar `post_type`). Status gate also available but not
  required here. (I.base, V2)
- C5. **Config + handler move together — atomic per handler.** Config emits `post_types` checkboxes
  `{slug:bool}`; handler reads `post_types` via `should_process_post`. Split = broken gating. One §T row =
  handler + config + InstaWP verify. (V3)
- C6. No nested Wireframe repeaters / dot-notation field ids (don't #4). Flat subfields + storage adapter.
- C7. **All 3 handlers already InstaWP-confirmed working** on legacy base (level-restriction, time-based, +
  propagation confirmed this session). Retest = regression-confirm, NOT first-run discovery.
- C8. **Scalar→plural `post_type` is arch#1-EXEMPT.** `docs/architecture.md` trap #1 (key-rename corruption)
  does NOT apply: this is a same-key array↔scalar reshape, which arch#1 explicitly calls safe ("admin
  round-trips them"). No flag-gated option rewrite needed — only the `should_process_post` read path changes.
  (architecture.md "Writing a rule handler" #1)

## §I — surfaces

- I.lvl-h = `includes/handlers/class-hierarchical-level-restriction-handler.php` — base flip; `get_handler_type`
  + `get_rule_type`; own hooks already (`set_object_terms` pri 5, `acf/save_post` pri 15) → `init_hooks`;
  no-op `process_post`; swap `rule_applies_to_post` → `should_process_post`. Already plural `post_types`.
- I.lvl-c = `includes/admin/config/class-level-restriction-config.php` — DELETE local
  `post_type_options_no_placeholder()` (L102) + its `select`; use shared `ConfigHelpers::post_types_field()`.
  Title/label polish.
- I.prop-h = `includes/handlers/class-propagation-handler.php` — base flip; **scalar `post_type` → plural**
  everywhere; child-walk `get_all_child_posts` passes `post_types` array to `\WP_Query` (L190/230/373);
  `process_new_child_post` (L170) is a SECOND entry point — survives loop removal (called from
  `class-taxonomy-manager.php:223` `on_post_insert`, not the save loop). swap gate. no-op `process_post`.
- I.prop-c = `includes/admin/config/class-propagation-config.php` — single `post_type` select (L44) → plural
  checkboxes, but HIERARCHICAL-only (propagation needs parent/child). Use new I.helper field. Snapshot label
  subfield(s) for `title_template`. Title polish.
- I.time-h = `includes/handlers/class-time-based-handler.php` — base flip; scalar `post_type` → plural
  (L179/319); own `save_post` hook (L32) + cron `bws_taxonomy_manager_cleanup` (L38) stay; loop removal fixes
  the double-run (own hook + loop). swap gate. no-op `process_post`. (Temporal extension DEFERRED — own spec.)
- I.time-c = `includes/admin/config/class-time-based-config.php` — `post_type` select → shared
  `post_types_field()` (non-hierarchical OK). Title/label polish.
- I.helper = `includes/admin/config/class-config-helpers.php` — ADD `hierarchical_post_types_field()` (or
  `$hierarchical` param on `post_types_field`): checkboxes over `get_post_types(['hierarchical'=>true])`.
  Existing `post_types_field()` is all-public-types — wrong for propagation. (V5)
- I.tax-mgr = `includes/class-taxonomy-manager.php` — REMOVE `on_post_save` loop (L200–210) AFTER last
  migration. `on_post_insert`/`process_new_child_post` path (L215–224) STAYS. (V4)
- I.base = `includes/handlers/class-unified-handler-base.php` — `should_process_post` (L527, post-type +
  status gate, {slug:bool} normalization); typed helpers (L385/447/484/510). Migration target. Template:
  `class-related-handler.php`.
- I.legacy = `includes/handlers/class-handler-base.php` — DELETE after last migration. `rule_applies_to_post`
  (L313, scalar+plural), legacy untyped helpers.
- I.changelog = `CHANGELOG.md` — `## [0.6.0] — Unreleased` above `[0.5.0]`; accumulate per handler.

## §V — invariants

- V1. **Typed-helper rebind safety.** Under `UnifiedHandlerBase` the term helpers are PHP-8.1-typed
  (`int $post_id`, `array $terms`, return `array|false|\WP_Error`/`bool`/`void`). No `strict_types` ⇒
  coercive: numeric-string post_id binds fine, but passing a non-array `$terms` (or null) hits a TypeError
  invisible to H1/H2 — only runtime. Every migrated handler MUST pass `int`-coercible post_id + real `array`
  terms to `apply_terms_to_post`/`remove_terms_from_post`/`post_has_terms`. (I.base, C3)
- V2. **Post-type gating goes through `should_process_post`, never scalar `post_type`.** Migrated handlers
  drop `rule_applies_to_post`. `should_process_post` reads `post_types` (array or `{slug:bool}`); empty/all-
  unchecked = every post type. A handler that still reads `$rule['post_type']` (singular) after migration
  silently gates wrong. (I.base, C4)
- V3. **Config + handler are one atomic change.** The config's `post_types` checkboxes write `{slug:bool}`;
  the handler's `should_process_post` reads it. Shipping the config field without the handler base-flip (or
  vice versa) = a rule that gates on a key nothing produces/consumes ⇒ applies-to-all or applies-to-none.
  Commit + verify them together. (C5, I.*-h + I.*-c)
- V4. **Loop removal must not break propagation's second entry point.** `on_post_save` loop (I.tax-mgr
  L200–210) calls `process_post` on every handler; deleting it is safe ONLY because each unified handler
  registers its own hooks in `init_hooks`. BUT `process_new_child_post` is reached via `on_post_insert`
  (L223), a SEPARATE hook the loop removal does not touch — it MUST stay wired after propagation migrates.
  Also: the no-op `process_post` overrides in hierarchical + title_slug handlers exist only to stop the base
  routing flat rules through `RuleEngine` via the loop; once the loop is gone, re-confirm those no-ops are
  harmless (still present, no longer load-bearing). (I.tax-mgr, I.prop-h)
- V5. **Propagation post-type field is hierarchical-only.** Propagation requires a parent/child relationship,
  so its post-type options MUST come from `get_post_types(['hierarchical'=>true])`, not the all-public-types
  `post_types_field()`. Reusing the shared field blindly offers non-hierarchical types that can never
  propagate. New `hierarchical_post_types_field()` helper (I.helper) supplies the canonical `post_types` id +
  empty-means-all semantics over the hierarchical set. (I.helper, I.prop-c)
- V6. **Time-based double-run closes on loop removal, not before.** Time-based registers its own `save_post`
  hook (I.time-h L32) AND is currently invoked by the I.tax-mgr loop — it runs twice per save today. The
  migration's loop removal (T6) is what makes it single-run. Until T6 lands, do not add a second guard;
  after T6, confirm single application on the test site. (I.time-h, I.tax-mgr)

V7–V10 = cross-handler traps from `docs/architecture.md` ("Writing a rule handler"). These migrations TOUCH
the 3 handlers, so each MUST be **confirmed-preserved, not introduced** — verify the handler already honors
it (all 3 are confirmed-working, C7), don't bolt on new logic unless a violation surfaces.

- V7. **No destructive write gated on post-type alone** (arch#2). A replace/remove mode (level-restriction
  `deepest_only`/`one_per_level` sibling drop; propagation `replace`) needs positive evidence the rule
  MANAGES this object. level-restriction is clean by construction — it only restricts terms ALREADY on the
  post being saved (`calculate_restricted_terms($post_own_terms)`), never wipes other posts. Propagation
  `replace` writes to CHILDREN — confirm it targets only resolved children of a matched parent, never an
  any-type blanket replace. Empty `post_types` (=all) + replace/remove must still be object-scoped. (T2,T3)
- V8. **Cache data, never decisions** (arch#3). level-restriction's `$term_level_cache` is a pure
  term-hierarchy lookup = safe, request-lived. Its `$processing` flag is a reentrancy guard, not a decision
  cache = safe. No migrated handler may memoize a write/skip DECISION that depends on mutable state (time
  window, post status) across the double-fire. Confirm none does. (T2,T3,T4)
- V9. **Idempotent + cascade-guarded under the save_post + acf/save_post double-fire** (arch#4). Every
  ACF-aware handler runs ≥twice per admin save. level-restriction guards reentrancy with `$processing` +
  only writes when `$final_terms !== $current` (short-circuit) = idempotent. propagation + time-based MUST
  short-circuit on no-change and be (post, taxonomy)-scoped; never assume "runs once". Confirm idempotent on
  the test site (resave = no spurious diff). (T2,T3,T4)
- V10. **Pre-filter site-wide hooks before expensive work** (arch#8). `set_object_terms` / `save_post` fire
  for EVERY post on the site. level-restriction early-continues on `$rule['taxonomy'] !== $taxonomy` before
  any term math = cheap gate. propagation (`save_post` every post) + time-based (`save_post` + publish every
  post) MUST gate eligibility (matched post_type/rule) before any `WP_Query` child-walk or date scan. The
  `should_process_post` swap (V2) is part of this gate, but confirm the handler bails EARLY, before the
  expensive call. (T2,T3,T4)

## §T — tasks

| id | st | task | cites |
|----|----|------|-------|
| T1 | x | Add `hierarchical_post_types_field()` to `ConfigHelpers` (checkboxes over hierarchical public post types; canonical `post_types` id; empty=all). H1+H2. | V5,I.helper |
| T2 | . | Migrate level-restriction (I.lvl-h + I.lvl-c together): base flip, `get_handler_type`/`get_rule_type`, own hooks → `init_hooks`, no-op `process_post`, swap gate; config drop local `post_type_options_no_placeholder` → shared `post_types_field()`; title/label polish. H1+H2. InstaWP: all 3 modes, include_ancestors on/off, ACF taxonomy-field path; confirm-preserve traps V7 (object-scoped), V9 (resave = no spurious diff), V10 (taxonomy early-continue). | V1,V2,V3,V7,V8,V9,V10,I.lvl-h,I.lvl-c |
| T3 | . | Migrate propagation (I.prop-h + I.prop-c together): base flip, gate swap, no-op `process_post`; scalar `post_type` → plural `post_types` across handler incl `get_all_child_posts` child-walk; config single select → `hierarchical_post_types_field()` + snapshot label subfields; title polish. Keep `process_new_child_post` wired. H1+H2. InstaWP: parent term-removal → child propagation, new-child-post path, multi-post-type rule, existing rule resolves; confirm-preserve traps V7 (replace targets resolved children only, never blanket), V9 (idempotent resave), V10 (eligibility gate before child-walk WP_Query). | V1,V2,V3,V4,V5,V7,V8,V9,V10,I.prop-h,I.prop-c,T1 |
| T4 | . | Migrate time-based (I.time-h + I.time-c together): base flip, gate swap, no-op `process_post`; scalar→plural; keep own `save_post` + cron `bws_taxonomy_manager_cleanup`; config select → `post_types_field()`; title/label polish. (Temporal extension OUT — separate spec.) H1+H2. InstaWP: in/out-of-range apply, publish path, cron cleanup; confirm-preserve traps V8 (no time-window decision cache), V9 (idempotent), V10 (gate before date scan). | V1,V2,V3,V6,V7,V8,V9,V10,I.time-h,I.time-c |
| T5 | . | Delete `class-handler-base.php` (I.legacy). Confirm no remaining `extends HandlerBase` references. H1+H2. | I.legacy,T2,T3,T4 |
| T6 | . | Remove `on_post_save` loop (I.tax-mgr L200–210). Verify `on_post_insert`/`process_new_child_post` still wired; re-confirm hierarchical/title_slug no-op `process_post` overrides harmless post-loop. H1+H2. | V4,V6,I.tax-mgr,T5 |
| T7 | . | All-7-handler regression on InstaWP (loop removal touches every save path) + single-run confirm on time-based. CHANGELOG `[0.6.0] — Unreleased` entries. Update CLAUDE.md + ROADMAP P3 → done. | T5,T6,I.changelog |

## §B — bugs

| id | date | cause | fix |
|----|------|-------|-----|
