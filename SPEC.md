# SPEC

Active feature: **ACF-Reference rule rework + Phase 3 handler migration** (`related_post_terms`).

Prior shipped feature (Phase 3b multi-trigger Related Term Mapping) RETIRED from this spec per SPEC lifecycle
— its invariants live in handler PHPDoc + git history; §B B1/B2 closed. Plan source:
`.claude/plans/acf-reference-rework.md`. Deferred sibling: `.claude/plans/status-mirroring.md`. Shared field +
status-effect primitive cross-ref: `.claude/plans/temporal-rule.md`.

## §G — goal

Rework "From referenced post (ACF)" rule (`related_post_terms`): copy taxonomy terms between a post and the
posts it relates to via an ACF relationship field. Direction selectable, single taxonomy, status-gated,
declarative source-authoritative sync. Migrate handler to `UnifiedHandlerBase` (Phase 3 #4). Kill the
AcfIntegration shadow-engine. Live-data safe.

## §C — constraints

- C1. Rule type LIVE on athletics site (+ `related` also live). Schema change MUST be live-data safe:
  `normalize_rule_shape` maps old→new, NO silent behavior flip. (V8)
- C2. PHP 8.1 coercive (no strict_types in handler/storage). Leading-backslash globals under ns (don't #0).
  No consecutive-cap class names (don't #0). H1 `php tests/lint.php` + H2 `php tests/verify-autoload.php`
  green after class edits. (don't #0/#6)
- C3. Handler migration: `HandlerBase`→`UnifiedHandlerBase` rebinds helpers to TYPED versions — pass `int`
  post_id + `array` terms or TypeError. Post-type gate via `should_process_post` ({slug:bool} map; empty=all).
  (don't #6)
- C4. No nested Wireframe repeaters / dot-notation field ids (don't #4). Flat subfields + storage adapter.
- C5. Status mirroring DEFERRED (status-mirroring.md) — this spec reserves a label slot + the source-status
  gate only; no mirror effect built here.
- C6. ACF taxonomy fields on in-scope POST types are Load/Save-Terms-ON (native = single source of truth).
  Premise for AcfIntegration redundancy. Verify on athletics test copy. (V7)

## §I — surfaces

- I.config = `includes/admin/config/class-related-post-terms-config.php` — collapse to single `taxonomy`;
  `keep_in_sync` (was `bidirectional`); DROP `conflict_handling`; add `holder_role`, optional
  `reverse_acf_field_name`, `post_status` gate, hidden label subfield(s).
- I.storage = `includes/storage/class-option-rule-storage.php` `normalize_rule_shape()` case
  `related_post_terms_rules` — migration mapping (V8). `id` decoration L183 (V2 — NOT stable).
- I.handler = `includes/handlers/class-related-post-terms-handler.php` — migrate base; declarative sync; bidi
  triggers; 3-tier reverse lookup; source-status gate; idempotent short-circuit.
- I.base = `includes/handlers/class-unified-handler-base.php` — `should_process_post` (L527; post-type gate
  only here, NOT status — V5); `apply_terms_to_post` (reuse short-circuit if present).
- I.acf = `includes/integrations/class-acf-integration.php` — shadow-engine; `on_acf_save_post` L62 kill-switch
  (V9); booted `class-taxonomy-manager.php:135`.
- I.helpers = `includes/admin/config/class-config-helpers.php` — add `post_status_field()` (shared w/ temporal);
  `acf_relationship_field_options()` (holder pin).
- I.label = `includes/admin/class-wireframe-bootstrap.php` — new `snapshot_acf_reference_labels` callback (V10).
- I.changelog = `CHANGELOG.md`; I.future = `docs/future-features.md` (single-owner opt, tier-filter, manual-
  survives, status-filter sweep).

## §V — invariants

- V1. **Direction = holder + role, not push/pull-as-trigger.** The ACF-field selection PINS the holder post
  type (`post_type:field_name` option value). `holder_role` ∈ {source, target} says which end is
  authoritative. source ⇒ holder's terms copy OUT to related posts (push); target ⇒ holder receives from
  related posts (pull, = legacy). Field-location DERIVED from selection, never a separate field. NEW-row
  default `holder_role='source'`. (I.config, I.handler)
- V2. **Rule `id` is NOT stable** — it is the array index, re-derived each read (I.storage L183), never
  persisted (`unset($data['id'])`; `array_values()` reindex on delete). Wireframe stores rules positionally →
  reorder/delete renumbers. ANY persistent per-rule state MUST key on stable identity (post id + field name),
  NEVER `id`. (Here: moot — V3 stores nothing.)
- V3. **Declarative source-authoritative removal (Model 1) — NO tracking meta.** Ownership = computed
  predicate, never logged. Per dependent D, taxonomy T:
  `authoritative(T) = ∪ over ALL enabled rules R targeting T on D of terms(T) from each VALID source of D
  under R`. keep_in_sync ON ⇒ `final(D,T) = authoritative(T)` (i.e. `wp_set_object_terms(D, authoritative,
  T)`); keep_in_sync OFF ⇒ `final = existing(T) ∪ authoritative(T)` (add-only, never removes). RULE-UNION:
  a term survives iff SOME enabled rule derives it (no last-writer clobber across rules). Source-authoritative:
  source drops term ⇒ term goes, NO promotion. Manual non-derivable terms in a keep_in_sync taxonomy ARE
  removed. Direction-agnostic: "valid source" = term origin (push: authoritative field-holder/reverse-resolved;
  pull: the related posts). (I.handler)
- V4. **Full bidirectional triggering.** Sync fires on: (a) authoritative post saved, (b) authoritative terms
  changed (`set_object_terms`), (c) a dependent saved on its own (self-heal). Triggers always on; keep_in_sync
  gates REMOVAL, not the trigger set. (I.handler)
- V5. **Status gate is SOURCE-scoped**, applied during `authoritative(T)` collection (filter out sources
  failing the gate) — NOT via `should_process_post` (which gates the TRIGGER post = wrong under bidi
  triggering). Dependent status is NEVER a gate (it is status-mirroring's OUTPUT, deferred). Empty gate = any.
  Post-TYPE gate may still use `should_process_post` on the trigger. (I.handler, I.base, C5)
- V6. **Three-tier reverse-field resolution** (finding the other end): (1) explicit `reverse_acf_field_name`
  set ⇒ read it directly; else (2) ACF native bidirectional detected (`acf_get_field()['bidirectional_target']`)
  ⇒ use partner key — wrapped defensively, absent/old ACF / no bidi falls through SILENTLY (no fatal/warning);
  else (3) `find_posts_with_related_post` meta_query fallback. Tier 1/2 = O(1) read per dependent; tier 3 =
  O(N) on push (perf-gated). (I.handler)
- V7. **AcfIntegration term-sync is redundant for Load-Terms-ON posts.** Handlers read NATIVE terms
  (RelatedHandler `post_has_terms`/`wp_get_object_terms`; RelatedPostTermsHandler `wp_get_object_terms`); with
  Load Terms on, native == ACF field value, so the handler sees every trigger/source the field-reading engine
  would. ⇒ disabling the engine is behavior-neutral for native terms. (I.acf, C6)
- V8. **Migration mapping (live-data safe), `normalize_rule_shape` case `related_post_terms_rules`:**
  `source_taxonomy`(+fallback `target_taxonomy`) → single `taxonomy` (prefer source if they differ — cross-tax
  never worked, term-ID copy rejects foreign-tax IDs); `bidirectional`→`keep_in_sync`; `conflict_handling`
  DROPPED (`merge`→keep_in_sync OFF, `replace`→ON, `skip`→OFF+flag); backfill `holder_role='target'` for ALL
  legacy rows (= today's pull); `post_status` absent = any; no `direction` key; no tracking-meta key. (I.storage,
  C1)
- V9. **AcfIntegration shadow-engine kill-switch.** `on_acf_save_post` short-circuits behind filter
  `bws_mc_acf_sync_engine_enabled` (default FALSE this branch) ⇒ all 6 reimplemented cases stop. Keep harmless
  field-settings UI (`add_taxonomy_field_settings`, `modify_taxonomy_field_query`). After two-stage verify
  clean ⇒ DELETE engine wholesale (follow-up commit). The engine's `related` case is ALREADY half-broken vs
  the migrated RelatedHandler (scalar `target_term_id` read, survives by max=1 luck). NOT a UBT dependency. (I.acf)
- V10. **Label snapshot — separate callback, no arrow.** `snapshot_acf_reference_labels` on
  `wp-wireframe/save/payload` (PRE-storage; raw `acf_field_name` still `post_type:field_name`). Schema:
  `{Copy|Sync} {Taxonomy} terms {to|from} {field_label}{ on {statuses}}` — `Copy|Sync`←keep_in_sync(off|on);
  `to|from`←holder_role(source|target); `{field_label}`←`acf_get_field()['label']` (clean human label, NOT raw
  name, NOT option string), fallback bare name; `{ on {statuses}}`←post_status gate, only when set, comma-join
  `get_post_status_object()->label`. No A→B arrow (same term, same taxonomy across a relationship). Status-
  mirror clause slot RESERVED (deferred). Don't refactor the working `snapshot_related_labels`. (I.label, C5)
- V11. **Re-entrancy = idempotent short-circuit.** Before writing to a dependent, compare `authoritative(T)` to
  current terms in T; if EQUAL, skip the write. Cascade (`set_object_terms`→re-trigger) dies on 2nd pass. No
  global lock, no in-flight set. Reuse `UnifiedHandlerBase::apply_terms_to_post` short-circuit if present. (I.handler)
- V12. **conflict_handling DROPPED — one axis.** keep_in_sync IS the merge(off)/replace(on) control. No
  second redundant field. `skip` (seed-only) = possible future flag, NOT carried. (I.config, V8)
- V13. **Managed-dependent precondition — write ONLY when ≥1 resolved source exists.** A dependent D is
  recomputed/written for a rule ONLY if `sources_of_dependent(D, rule)` resolves ≥1 source under that rule.
  Across ALL enabled rules: if D has ZERO resolved sources, DO NOT write — leave D's terms untouched (D is not
  managed by this rule). The write gate depends on SOURCE PRESENCE, never post-type match alone (post-type
  match for push is `''`=any → matches every post). Empty-replace (`wp_set_object_terms(D, [], T)`) is
  permitted when sources RESOLVE but yield no terms — INCLUDING when the only source(s) fail the V5 status gate
  (a status-gated-out source counts as resolved: it contributes no terms ⇒ legit sync-to-empty; e.g. a
  published dependent of a still-draft source under a publish gate is correctly emptied). Empty-replace is
  FORBIDDEN only when NO source resolves at all (not-managed ≠ source-emptied). Fixes B3. (I.handler, V3, V5)
- V14. **Orphan strip on SOURCE-side sever only.** When a source's relationship field REMOVES a dependent
  (the edge is severed from the source side), recompute that removed dependent WHILE THE SOURCE IS STILL
  KNOWN, so the source's contribution withdraws (its terms drop out of the union; keep-in-sync then removes
  them). Removed dependent IDs captured via `acf/update_value` (priority 5, before write) reading the OLD
  value with `get_field` and diffing the new — no per-term tracking, stays within V3's no-meta model. Forced
  replace (`recompute_dependent(...,$force_sync=true)`) bypasses the V13 zero-source skip for exactly these
  orphans so the withdrawn source's terms drop; remaining valid sources' terms survive; only keep_in_sync
  taxonomies are swept (add-only never removes). Sever detected ONLY from the source side; a dependent-side
  sever leaves terms per V13 (documented gap). **Caveat:** the old-value read assumes ACF has not primed its
  value cache with the NEW value at update_value time; if it has, the diff misses the removal (best-effort,
  not guaranteed — verify on the athletics test copy). (I.handler, I.acf, V3, V13)
- V17. **Symmetric source-side eligibility pre-filter (perf).** In `sync_for_post`, treating the saved post as
  a SOURCE (→ `dependents_of_source` → reverse lookup for pull) MUST be gated on the post being a plausible
  source type, mirroring the dependent-side `is_eligible_dependent_type` pre-filter (#6). For PULL, the source
  is a related-post, so `source_post_type` is `''`=any → WITHOUT a pre-filter EVERY site-wide save runs
  `resolve_reverse` on the saved post, and at tier 3 (no explicit reverse + no bidi) that is a full
  `find_holders_referencing` meta_query LIKE scan over all holder-type posts on every save anywhere. The
  pre-filter (`is_eligible_source_type`) narrows pull to the ACF field's configured target post types (via
  `acf_field_target_post_types`); unknown/unconstrained ⇒ stay eligible (correctness preserved — resolve_reverse
  on an ineligible post returns empty regardless, V13's source-presence gate still prevents wrong writes; this
  is purely a perf pre-filter, like #6). Push is unaffected (`source_post_type` is the concrete holder type).
  Fixes B7. (I.handler, V6, V13)
- V16. **Key-renaming migrations MUST persist to storage, not read-time-only.** The Wireframe admin reads the
  option RAW via `get_option` (`Settings::resolvedFor`) with NO filter seam — it bypasses
  `OptionRuleStorage::normalize_rule_shape`. So a read-time-only migration that RENAMES or REMOVES keys (vs a
  directional adapter that reshapes the SAME key) is invisible to the admin: the form binds the new key name,
  finds it absent in raw storage, falls back to the config DEFAULT, and a resave PERSISTS that default —
  silently corrupting the rule (the "behaves identically until re-saved" promise inverts: resave corrupts
  instead of cleanly migrating). Audited (B6): the only affected type is `related_post_terms_rules`
  (source_taxonomy→taxonomy, bidirectional→keep_in_sync, conflict_handling drop, holder_role default). The
  term-ID `[N]`↔`int` and acf `pt:field` split transforms are SAFE — they are directional adapters where the
  admin's stored shape round-trips. Fix: a one-time, flag-gated option REWRITE on admin boot (before Wireframe
  reads) runs the legacy rows through the adapter and persists the normalized shape; handler + admin then read
  identical data. Fixes B6. (I.storage, I.admin, V8)
- V15. **No per-request recompute-result cache.** The save_post + acf/save_post double-fire is made safe ONLY
  by `write_terms`' idempotent short-circuit + the `in_sync` cascade guard (V11), NEVER by caching which
  (dependent,taxonomy) pairs were already recomputed this request. A result cache is a CORRECTNESS bug: the
  source-status gate input (V5) can change BETWEEN the two fires of one save — e.g. a publish→draft transition
  flips the source's status mid-request — so the first (stale-status, publish) recompute would suppress the
  second (correct-status, draft) recompute and the authoritative wipe never runs. Recompute is pure
  read+compare; repeating it is cheap and the short-circuit collapses redundant writes. Fixes B5. (I.handler, V5, V11)

## §T — tasks

| id | st | task | cites |
|----|----|------|-------|
| T1 | x | helpers: add `post_status_field()` shared checkboxes (empty=any); mirror `post_types_field()` | I.helpers,V5 |
| T2 | x | config: single `taxonomy`; `keep_in_sync` (was bidirectional); DROP conflict_handling; add `holder_role` (default source), optional `reverse_acf_field_name`, `post_status` gate, hidden label subfield(s); clarify ACF-field help | V1,V5,V6,V12,C4,I.config |
| T3 | x | storage: `normalize_rule_shape` case migration mapping (taxonomy collapse, keep_in_sync, conflict drop, holder_role backfill=target) | V8,C1,I.storage |
| T4 | x | handler: migrate HandlerBase→UnifiedHandlerBase; typed helper calls; H1+H2 | C2,C3,I.handler,I.base |
| T5 | x | handler: declarative Model 1 sync — authoritative(T) rule-union, source-authoritative removal, no meta; both holder_role modes | V3,I.handler |
| T6 | x | handler: full bidirectional triggering (authoritative-save, terms-change, dependent-save) | V4,I.handler |
| T7 | x | handler: source-scoped status gate in term-collection (NOT should_process_post) | V5,I.handler,I.base |
| T8 | x | handler: 3-tier reverse-field resolution (explicit → native-bidi graceful → meta_query) | V6,I.handler |
| T9 | x | handler: idempotent short-circuit re-entrancy (skip write when computed==current) | V11,I.handler |
| T10 | x | acf: kill-switch filter on `on_acf_save_post` (default off, all 6 cases); keep field-settings UI | V9,I.acf |
| T11 | x | label: `snapshot_acf_reference_labels` callback; schema assembly; `acf_get_field()` field_label; no arrow | V10,I.label |
| T12 | x | CHANGELOG + future-features (deferred: single-owner opt, tier-filter, manual-survives, status-filter sweep, status mirroring, AcfIntegration delete) | I.changelog,I.future,C5 |
| T13 | x | Stage 1 InstaWP: H1+H2 + mechanics (config render, migration shape, label, single/multi-rule additive, keep_in_sync removal, kill-switch toggles) — H1+H2 green; migration-shape harness 14/14 PASS; UI sweep = user-run | C2,C6 |
| T14 | . | Stage 2 athletics test copy: real-data gate — push+pull, source-status gate, declarative no-clobber, engine-off parity (both live types), legacy migration neutral; ALSO V13 (unrelated post sharing taxonomy NOT wiped on save), V14 (remove event from schedule → event loses synced terms; confirm ACF old-value read works); BEFORE production deploy | C1,C6,V7,V9,V13,V14 |
| T15 | x | handler: gate write on SOURCE PRESENCE not type-match — recompute_dependent requires ≥1 resolved source (across rules) before writing; no source ⇒ skip (no empty-replace). Fixes B3 | V13,I.handler |
| T16 | x | handler: source-side sever strip — hook `acf/update_value` (relationship/post_object), diff old-vs-new dependents, force-recompute removed ones while source known | V14,I.handler,I.acf |
| T17 | x | handler: drop per-request recompute-result cache (`$synced`) — relied on stale status snapshot across publish→draft transition, suppressed the wipe recompute. Idempotent short-circuit + in_sync guard already cover the double-fire. Fixes B5 | V15,I.handler |
| T19 | x | handler: symmetric source-side eligibility pre-filter — add is_eligible_source_type, gate the source-branch reverse lookup in sync_for_post so pull rules don't run resolve_reverse (tier-3 meta_query) on every site-wide save. Fixes B7 | V17,I.handler |
| T18 | x | storage: one-time flag-gated option rewrite for related_post_terms_rules — on admin boot (before Wireframe reads raw), detect legacy keys / missing holder_role+taxonomy, run rows through migrate_related_post_terms_shape + acf split, persist normalized shape, set schema flag. Fixes B6 | V16,I.storage,I.admin |

## §B — bugs

| id | date | cause | fix |
|----|------|-------|-----|
| B3 | 2026-06-24 | Push + keep_in_sync wipes terms on UNRELATED posts. `dependent_post_type=''` for push ⇒ `post_type_matches(post,'')` always true ⇒ every saved post marked its own dependent; `recompute_dependent` set `rule_applies` on type-match alone, resolved 0 sources, wrote `authoritative=[]` via replace ⇒ cleared any post that merely shares the taxonomy. Code review (CONFIRMED). | V13, T15 |
| B7 | 2026-06-25 | PERF: pull-mode rules run resolve_reverse on EVERY site-wide save. `source_post_type` for pull is `''`=any ⇒ `post_type_matches(post,'')` always true ⇒ every saved post (any type) treated as a possible source ⇒ dependents_of_source → resolve_reverse. At tier 3 (no explicit reverse + no bidi) that is an unindexed meta_query LIKE scan over all holder posts on every save anywhere — invisible (returns empty, "works"). Found via bidi-tier-2 trace (spurious empty resolve_reverse on an event). Mirror of #6 on the source side; fix = symmetric is_eligible_source_type pre-filter. | V17, T19 |
| B6 | 2026-06-24 | Legacy ACF-ref rule renders WRONG in admin + corrupts on resave (found via InstaWP migration test). Wireframe reads option raw (get_option, no filter), bypassing normalize_rule_shape; migrated keys (taxonomy, holder_role, keep_in_sync) absent from raw storage ⇒ form shows config defaults (push, empty taxonomy) not migrated values (pull, shaker) ⇒ resave persists corruption. Read-time-only migration can't reach the admin. Audit (V16): confined to related_post_terms_rules (only key-rename migration); term-ID + acf-split transforms are safe directional adapters. Fix: one-time flag-gated option rewrite at boot. | V16, T18 |
| B5 | 2026-06-24 | Source status publish→draft did NOT wipe dependents' synced terms (test on crdhmavadev real copy). Per-request `$synced` cache (added as #5 double-fire optimization) keyed (dep:tax); the early publish-status fire recomputed+cached all dependents with `passes:1`, so the later draft-status fire — which should recompute with the source gated out and authoritative=[] → keep_in_sync wipe — was deduped away. Cache snapshotted a status that changed mid-request. Fix: remove the cache; rely on write_terms idempotent short-circuit + in_sync guard. Found via debug tracing. | V15, T17 |
| B4 | 2026-06-24 | NOT A BUG — withdrawn after review. A published dependent whose only source fails the V5 status gate (draft source, publish gate) IS correctly sync-to-empty: gated-out source = resolved-but-contributes-nothing. This is intended source-authoritative behavior; status-mirroring (deferred) will later soften the UX by making the dependent private instead of term-stripped. No fix. (V13 records the gated-source semantics.) | — |
