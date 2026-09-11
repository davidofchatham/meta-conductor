# Plan: ACF-Reference rule rework + Phase 3 migration

> **Design history — lifted 2026-09-11 from a private plan file. Never corrected.**
>
> This is the design record for the `related_post_terms` rework and its Phase 3 migration, written *before* the work was done and kept as written. It is here for what the four post-ship records never carry: the options that were rejected, the mirror-vs-native question and why it was answered the way it was, and the staging strategy behind the decision. It is **not** documentation of the shipped code: for that see [architecture.md](../architecture.md) and [CHANGELOG.md](../../CHANGELOG.md).
>
> Expect drift. `AcfIntegration` was subsequently deleted as planned here, and the handler was later converted to the term dispatcher (#63), which changed the removal semantics this document describes. Site and host names have been generalized on the lift.

Branch: `claude/acf-reference-p3` (one branch, multi-commit)
Rule type: `related_post_terms` (UI "From referenced post (ACF)")
Files: config `class-related-post-terms-config.php`, handler `class-related-post-terms-handler.php`,
storage `class-option-rule-storage.php` (`normalize_rule_shape`), bootstrap `class-wireframe-bootstrap.php`,
shared `class-config-helpers.php`.

## Code-review findings (2026-06-24, high-effort /code-review) + dispositions

Data-loss findings already fixed via backprop (SPEC §V13/§V14, §B B3, commit 92d806e):
- **#1/B3** push wipes UNRELATED posts → FIXED (V13 source-presence write gate).
- **#2/B4** gated-out source wipes dependent → WITHDRAWN (intended sync-to-empty, not a bug).
- **orphan sever** → FIXED (V14 source-side `acf/update_value` diff + forced recompute).

Remaining 8 findings + disposition (decided 2026-06-24):
- **#3 `in_sync` leak on exception** — no try/finally around `wp_set_object_terms`; a throwing
  set_object_terms callback leaves `in_sync[id]=true` for the request → that post skipped after. → FIX NOW.
- **#4 multi-level chain propagation** — A→B→C where B is dependent of A AND source of C: B's write is
  suppressed by its own in_sync guard, so C doesn't recompute that pass. → DOCUMENT as known limit (athletics
  is 2-level). Tracked as FW-10; revisit if a 3-level chain appears.
- **#5 double-fire** save_post + acf/save_post both run sync_for_post per save → DEDUPE per request (guard).
- **#6 spurious reverse-lookup every save** — push rule with no reverse field runs a meta_query on EVERY saved
  post (the cost half of #1; correctness already fixed by V13's skip). → FIX NOW: skip the candidate-dependent
  reverse-lookup when the saved post's type can't be a dependent of the rule.
- **#7 write_terms duplicates base apply_terms_to_post** (CLAUDE.md #6 drift risk) → FIX NOW (dedup).
- **#8 status-normalization triplicated** (handler/bootstrap/base) → FIX NOW (extract shared helper).
- **#9 reverse-field dropdown placeholder cache** — `acf_relationship_field_options()` static cache ignores
  `$placeholder`; reverse dropdown shows wrong first-option label. Cosmetic. → FIX NOW (one-line).
- **#10 AcfIntegration stale keys** — `process_related_post_terms_acf` reads unset source/target_taxonomy.
  Dead behind kill-switch; dies when engine deleted (end Phase 3). → NO ACTION.

## Decisions (from user, 2026-06-23)
1. **Live data** → migrate safely. Old rules must keep working; map old keys in `normalize_rule_shape()`.
2. **Direction** = Pull vs Push, **default Push**.
3. **Taxonomy** → research done: single taxonomy (see Finding A).
4. **Scope** → plan-first, one branch, all 6 issues + handler migration together.

## Research findings
- **A. Cross-taxonomy copy is impossible as built.** Handler copies term *IDs* (`fields=>ids` → same IDs to
  target). A term ID belongs to exactly one taxonomy; `wp_set_object_terms` rejects foreign-taxonomy IDs.
  Dual source/target selector only ever works when source==target. Standalone script confirms: always
  same-taxonomy both ends (`school_year`→`school_year`, `teams`→`teams`). → **Collapse to one `taxonomy`
  field.** (If true cross-tax is ever wanted, must copy by slug/name — out of scope; tracked as FW-10.)
- **B. Status filter plumbing already exists.** `UnifiedHandlerBase::should_process_post()` already enforces
  `$rule['post_status']` (line 549). Missing pieces: (1) a UI field to set it, (2) the handler must be ON the
  unified base and actually call `should_process_post`. So status-filter = mostly UI + migration, not new logic.
- **C. This IS Phase 3 migration #4.** ROADMAP order: Related → Level-Restriction → Propagation →
  **Related-Post-Terms** → Time-Based. Touching it = doing the migration. Template = `class-hierarchical-handler.php`.
- **D. Label snapshot mechanism** is hardwired to `related_rules` in bootstrap (`snapshot_related_labels`,
  hooked `wp-wireframe/save/payload`). Need a sibling for `related_post_terms_rules` + hidden label subfields.

## Direction model (RESOLVED via grill 2026-06-23 — supersedes earlier push/pull framing)

Push/pull was the wrong axis. Real model: an **authoritative (source) post** owns the terms; **dependent
(target) posts** receive copies. An ACF relationship field connects them and **can live on either end**.

**Config shape (decision C):** the ACF-field select pins the **holder** post type (option value is
`post_type:field_name`, so holder is known from the selection). Then ONE direction toggle, anchored to the
holder:
- **"Field holder is the source"** → holder's terms copy OUT to the posts it relates to. (push-from-holder)
- **"Field holder is the target"** → holder receives terms from the posts it relates to. (pull-to-holder =
  current behavior)
Field-location is DERIVED from the field selection, not a separate field. The toggle is just
authoritative-end relative to the holder. Both athletics cases reachable: field-on-event+target = today's
pull; field-on-schedule+source = the intuitive push. Both make the schedule authoritative.

**Why the user's first attempt failed:** they put the field on the source (schedule) but the OLD code assumes
authoritative = the posts the holder points at (pull), so it read the events' terms as authoritative and
stripped the schedule's Sport Connectors. Correct per old impl, wrong per intent.

**Triggers (decision A — full bidirectional triggering, both ends).** Matches the standalone script; a
dependent created/edited on its own self-heals (the Admin-Columns case, script lines 33-36/106). Both modes
need: (1) authoritative-post saved → propagate to dependents; (2) authoritative terms changed
(`set_object_terms`) → propagate; (3) a dependent saved on its own → fetch from its authoritative. Triggers
are ALWAYS on; the Keep-in-sync toggle gates REMOVAL semantics (absence-of-source-term removes dependent
term vs add-only), NOT the trigger set.

**Reverse-field resolution (decision: three-tier, graceful).** Finding the other end (dependents from
authoritative, or authoritative from dependent) resolves in order:
1. **Explicit optional "reverse relationship field" select** set → read it directly. User override, wins.
2. Empty + **ACF native bidirectional** detected (`acf_get_field()['bidirectional_target']`) → use partner
   key. The user's common case (usually native bidi). **Wrap defensively — absent/old ACF or no bidi config
   must fall through silently, no fatal/warning.**
3. Neither → `find_posts_with_related_post` meta_query fallback (today's behavior; correct, slower, false-
   positive-prone — hence tiers 1–2 exist to avoid it).
Both ends of athletics ARE native bidi (`schedule_games` ⇄ `game_team_schedule_cpt`) but the user notes bidi
is NOT guaranteed in general (sometimes avoided due to ACF's max-posts limit being skipped on bidi update).

**Storage keys:** replace `direction:push|pull` plan-table row with `holder_role:source|target` (or keep
`direction` but document it as holder-relative). Add optional `reverse_acf_field_name`. Existing live rows:
field is on the dependent + holder-is-target → backfill `holder_role='target'` (= today's pull behavior).

## Schema change (old → new) — FINAL (post-grill 2026-06-23)
| old key | new key | migration in normalize_rule_shape |
|---|---|---|
| `source_taxonomy` + `target_taxonomy` | `taxonomy` (single) | new = `source_taxonomy` (fallback `target_taxonomy`); if they DIFFER, prefer source — cross-tax never worked, Finding A + Q6a=A |
| `bidirectional` (bool) | `keep_in_sync` (bool) | old `true` → on; `false` → off (Q1) |
| `conflict_handling` (merge/replace/skip) | **DROPPED** (Q9=A) | `merge`→keep_in_sync OFF; `replace`→keep_in_sync ON; `skip`→OFF + flag for manual review |
| (derived) | `holder_role` (source\|target) | absent → `'target'` for ALL legacy rows (= today's pull-to-holder). NEW-row UI default = `'source'` (push). Field-location DERIVED from the ACF-field selection; holder_role = is-holder-authoritative. (Decision C) |
| (none) | `reverse_acf_field_name` (optional) | absent = use ACF-native-bidi auto-detect, else meta_query fallback (Q4 three-tier) |
| (none) | `post_status` (gate) | absent = any (no behavior change, Q2). SOURCE-scoped enforcement (Q10=A) |

**Critical migration invariants:**
- Legacy rows backfill `holder_role='target'` (preserve today's pull). NEW-row default is `'source'` (push) —
  do NOT silently flip live rules. Wireframe field `default` handles new rows; `normalize_rule_shape`
  backfills `'target'` when the key is absent on a stored row.
- No `direction` key (renamed to `holder_role`; "push/pull" is descriptive, not stored).
- No tracking-meta key — removal is declarative/recomputed (Q7 Model 1), nothing persisted per-rule/post.

## Hazard: AcfIntegration is a THIRD writer (grill discovery 2026-06-23)

`AcfIntegration` (`includes/integrations/class-acf-integration.php`, booted unconditionally at
[class-taxonomy-manager.php:135](includes/class-taxonomy-manager.php#L135) whenever ACF active) is a
**parallel sync engine**, orthogonal to the legacy/unified base-class split. It takes the `$handlers` array,
reads each handler's `get_enabled_rules()`, and **reimplements the sync** on `acf/save_post` —
shadow-implementing 6 of 7 rule types via a `switch` ([line 139](includes/integrations/class-acf-integration.php#L139)),
incl. a `related_post_terms` case ([line 156](includes/integrations/class-acf-integration.php#L156) →
`process_related_post_terms_acf` → `sync_related_post_terms`). It uses the OLD schema keys
(`source_taxonomy`/`target_taxonomy`/`bidirectional`) and additionally writes ACF *taxonomy* fields.

- **Migrating the handler does NOT touch it** (it doesn't call handler methods, it reimplements them). After
  our schema change it would read undefined `source_taxonomy` → silent breakage / warnings.
- **Double-fire:** `on_acf_save_post` early-returns only when the post has NO ACF *taxonomy* field
  ([line 76](includes/integrations/class-acf-integration.php#L76)). NOTE: corrected below — most in-scope posts
  DO have ACF taxonomy fields, so this fires live alongside the handler (not the latent landmine first assumed).
- **NOT a UBT dependency.** Checked `ubt-merger.md`: UBT brings its OWN
  `class-bws-user-terms-acf-integration.php` (the UBT merger plan, [FW-8](../future-work.md#fw-8)); the
  "reuse ACF taxonomy-field integration" note refers to UBT's ported class, not MC's `AcfIntegration`.
  Severing MC's `related_post_terms` case costs UBT nothing.

**Most posts DO use ACF taxonomy fields** (grill correction) — so AcfIntegration's `related_post_terms` path
is NOT gated off; it fires alongside the handler today. First read = "latent landmine" was WRONG; it's a live
dual-write. BUT investigation of what that write does dissolves the problem:

- `get_acf_field_terms` = bare `get_field()` ([a:839](includes/integrations/class-acf-integration.php#L839));
  `update_acf_field_terms` = bare `update_field()` ([a:874](includes/integrations/class-acf-integration.php#L874)).
  No check of ACF's Save-Terms / Load-Terms field settings.
- **User's fields are configured "load from and save terms to the post"** = ACF Save Terms + Load Terms ON.
  Under that config ACF itself writes selected terms → native taxonomy on save, and repaints the field value
  ← native terms on load. **Native taxonomy is the single source of truth; the ACF field is a view.**
- ⇒ AcfIntegration's read-union (native + field) and explicit field-write are **REDUNDANT**: the handler
  operating on native terms is sufficient; ACF's Load Terms re-renders the field from native automatically.
- ⇒ The explicit `update_field()` is also a re-entrancy *trigger* (fires `acf/save_post` / `acf/update_value`
  → the loops the handler already guards). Removing it likely makes the handler MORE stable.

**DECISION (Q5, UPGRADED to AGGRESSIVE — grill 2026-06-23):** disable the WHOLE AcfIntegration term-sync
engine (all 6 cases), not just `related_post_terms`. Two rule types are LIVE on the athletics site
(`related_post_terms` + `related`), so this is active risk, not future debt.

**Why aggressive is safe (full chain verified):**
- Per-type schema-fragility of the 6 cases:
  | case | guard | old keys read | status |
  |--|--|--|--|
  | `related` | trigger-found | trigger_type/trigger_term_id/target_term_id/bidirectional | **already half-broken** — RelatedHandler is migrated (canonical `trigger_term_id`=int[]); AcfIntegration's trigger side was patched for arrays ([a:798](includes/integrations/class-acf-integration.php#L798)) but `target_term_id` at [a:819](includes/integrations/class-acf-integration.php#L819) still assumes scalar (survives only by max=1 luck) |
  | `related_post_terms` | was_acf_field_updated | source/target_taxonomy/bidirectional | our rework breaks it |
  | `propagation` | **none** (every save) | taxonomy/conflict_handling/post_type | breaks on P3 migration |
  | `hierarchical` | none | taxonomy | breaks on P3 migration |
  | `level_restriction` | was_acf_field_updated | restriction_mode/include_ancestors | breaks on P3 migration |
  | `time_based` | (no case) | — | — |
- **Redundancy for BOTH live types confirmed in code:** handlers read NATIVE terms for triggers/sources
  (RelatedHandler `post_has_terms`/`wp_get_object_terms` [related-handler:149/154](includes/handlers/class-related-handler.php#L149);
  RelatedPostTermsHandler `wp_get_object_terms` [:134](includes/handlers/class-related-post-terms-handler.php#L134)).
  With Load-Terms-ON (user's standing config for posts), native == ACF field value, so the handler sees every
  trigger/source AcfIntegration's field-reading would. AcfIntegration's term-sync is pure redundancy + a
  re-entrancy source + the half-broken `related` path. Disabling = behavior-neutral for native terms.

**Implementation (kill-switch-gated, reversible):**
1. Short-circuit `AcfIntegration::on_acf_save_post` ([a:62](includes/integrations/class-acf-integration.php#L62))
   behind a filter (e.g. `bws_mc_acf_sync_engine_enabled`, default FALSE in this branch). Stops all 6 cases.
2. KEEP the harmless field-settings UI (`add_taxonomy_field_settings`, `modify_taxonomy_field_query`).
3. Also remove the now-dead `related_post_terms` reliance — handler is sole writer regardless.
4. Engine-off verify happens in two stages (see Verify environments below) — kill-switch makes it reversible.
5. After both stages clean → DELETE the engine wholesale (follow-up commit, end of Phase 3). Regression → flip
   filter on, investigate the specific type. NOT a UBT dependency (UBT ships its own ACF class — the UBT merger plan, [FW-8](../future-work.md#fw-8)).

## Verify environments (two-stage, gated — grill 2026-06-23)

**Two distinct test sites, different roles:**
- **Dev sandbox** (a hosted scratch site at the time; the local docker testbed now). Limited SYNTHETIC data, NOT athletics. Validates
  MECHANICS: H1 (`php tests/lint.php`) + H2 (`php tests/verify-autoload.php`) static harness; config renders;
  schema migration shape; label snapshot; single + multi-rule additive; keep-in-sync removal logic; kill-switch
  toggles cleanly. CANNOT prove the live-data scenarios.
- **Athletics test copy** = staging clone of the REAL site (schedules/events, native bidi, Load-Terms-on, both
  live rule types: `related` + `related_post_terms`). The ONLY place that validates real-data behavior.

**Stage 1 — dev sandbox (before touching athletics):** all mechanics green, H1+H2 pass.
**Stage 2 — Athletics test copy (gate before production deploy):**
- AcfIntegration engine-off is behavior-neutral: related-term + ACF-reference rules behave identically; ACF
  taxonomy fields stay correct (Load Terms repaints from native); no double-write / stale field.
- Push + pull on real relationships; source-status gate; declarative removal does NOT clobber live terms.
- Legacy stored rules migrate with NO behavior change.
- Sanity: confirm athletics post-type taxonomy fields are Load/Save-Terms-on (redundancy premise).
**Production deploy only after Stage 2 clean.** (If a future NON-post variant appears, re-open the mirror
question — option B.)

**ROADMAP debt:** AcfIntegration is an undocumented shadow-engine reimplementing 6 of 7 rule types
(orthogonal to the legacy/unified split — reimplements, doesn't call handler methods). Aggressive disable
(above) takes the whole engine offline this branch behind a kill-switch; after the sandbox sweep proves all
exercised types behave with it off, DELETE it wholesale (follow-up commit, end of Phase 3). The 3 non-live
cases (propagation/hierarchical/level_restriction) get covered by the same sweep where their rule types are
exercised, or by their own P3 migration verify if not. Document deletion in ROADMAP + remove the
`add_taxonomy_field_settings` UI only if no longer meaningful.

## Keep-in-sync / removal semantics — DECLARATIVE ownership (grill 2026-06-23, Q7)

Old `bidirectional` blunt-wiped the whole taxonomy (`wp_set_object_terms($post_id, [], $tax)`,
[handler:125/157](includes/handlers/class-related-post-terms-handler.php#L125)) — destroyed other rules' +
manual terms. Replaced by a declarative model.

**DECISION (Model 1 — declarative, source-authoritative, NO tracking meta).** Ownership is a *computed
predicate*, not a logged history. The ruleset "owns" the terms derivable from its **source selection**
(post-type + relationship-field + publication-status gate). Recompute every sync from the LIVE relationship
graph; never store what-was-applied.

This dissolves the entire keying minefield from earlier rounds (rule-id instability, channel keys, orphans,
multi-owner entries) — there is no key because there is no stored state.

**Removal algorithm (Q7b = A: taxonomy-wide, rule-union):**
```
For a dependent post D, taxonomy T:
  authoritative(T) = ∪  over ALL enabled rules R that target T on D
                        of  terms(T) from each VALID source of D under R
     valid source = post related to D via R's relationship field, passing R's status gate (if set)
  when any contributing rule is keep-in-sync:
     final terms of D in T  =  authoritative(T)        # i.e. wp_set_object_terms(D, authoritative, T)
  when add-only (keep-in-sync off):
     final = existing(T) ∪ authoritative(T)             # never removes
```
- **Rule-UNION, not per-rule.** A term survives iff SOME enabled rule derives it. Safe under multiple rules on
  one taxonomy (no last-writer clobber). Cost: each sync considers all rules targeting T on D, not just the
  firing one.
- **Source-authoritative, NO promotion.** If the source drops a term, it goes. The hierarchical
  `_bws_auto_terms` promotion concept is intentionally NOT used here.
- **Manual terms NOT preserved.** A hand-added term in a keep-in-sync taxonomy that no source derives gets
  stripped. Synced taxonomies are wholly rule-owned; editors must not hand-edit them. (If "manual survives"
  is ever needed → reintroduces rule-domain-vs-manual marking = tracking meta = Model 2. Future option only.)

**Direction-agnostic:** "valid source" = term origin. Push: source = authoritative field-holder /
reverse-resolved posts → write to dependents. Pull: source = the referenced posts → write to the holder. Same
union math both ways.

**Future refinement (noted, not now):** sync only a particular TIER of a taxonomy (e.g. only 2nd-level terms).
Out of scope.

### Reusable codebase finding — rule `id` is NOT stable
Rule `id` = array index, re-derived on every read ([storage:183/148/265](includes/storage/class-option-rule-storage.php#L183)),
never persisted (`unset($data['id'])` [storage:296/398](includes/storage/class-option-rule-storage.php#L296);
`array_values()` reindex on delete [storage:332](includes/storage/class-option-rule-storage.php#L332)).
Wireframe stores rules as a positional repeater list → **reorder/delete renumbers all ids.** ANY future
per-rule persistent state must key on stable identity, never `id`. (Here it's moot — Model 1 stores nothing.)

## Performance + re-entrancy (grill 2026-06-23, Q8)

**Cost model under declarative rule-union + bidirectional triggering + synchronous push:** computing
`authoritative(T)` for one dependent = find its sources (relationship traversal) × read each source's terms.
Push of source S with N dependents = N recomputations. Source-finding cost is set by the reverse-field tier
(Q4): tier 1/2 (explicit reverse field / ACF native bidi) = one direct read per dependent (cheap); tier 3
(meta_query fallback) = O(N) LIKE queries on push (expensive). ⇒ **the reverse-field/bidi resolution is
load-bearing for push performance, not just convenience.** User's data is native bidi → tier 2 → cheap.
Document tier-3 as the perf-gated path; gate/batch large fan-outs only if a tier-3 deployment appears.

**Single-owner optimization — DECIDED: do NOT build now (optimization, not simplification).** In the
athletics scenario each event has exactly one valid owner schedule (event-side relationship field is single /
`max=1`, native bidi). Could be discovered from ACF field `max`/`multiple` (`acf_get_field()`) or specified
via a rule toggle, and would let removal skip the multi-source union. BUT with the reverse field present,
locating an event's source is already one read returning one schedule, and the rule-union over a single
element is already optimal — the optimization saves ~zero work at this scale. The safe rule-union (Q7b=A)
stays the ALWAYS path. Revisit single-owner only if a tier-3 (no reverse field) large-fan-out deployment shows
real cost. (Discovery is only *safe* to exploit when enforced — native bidi + `max=1` enforces it; a manual
toggle would trust an admin assertion the data could violate. Another reason to defer.)

**Re-entrancy (Q8 = A: idempotent short-circuit).** Push writes terms to N dependents → fires
`set_object_terms` N× → the handler's own reverse-sync hook would re-trigger → cascade. The old coarse
`$this->processing` bool is too blunt (single in-flight guard; blocks legitimate cross-rule work). Instead:
before writing, compare computed `authoritative(T)` to the dependent's current terms in T; **if equal, skip the
write.** The cascade dies on the second pass (terms already correct → no `set_object_terms` → no re-fire).
No global lock, no cleanup, self-limiting. Matches the hierarchical handler's existing equality check
([class-hierarchical-handler.php:122-123](includes/handlers/class-hierarchical-handler.php#L122)).
(`UnifiedHandlerBase::apply_terms_to_post` may already short-circuit — verify and reuse rather than re-add.)

## conflict_handling collapses into keep-in-sync (grill 2026-06-23, Q9 = A)

Old tri-mode `conflict_handling` ([config:83-95](includes/admin/config/class-related-post-terms-config.php#L83):
merge/replace/skip) is redundant under Model 1 — keep-in-sync already encodes the existing-terms interaction:
- keep-in-sync **ON** → `final = authoritative` = **replace** semantics (rule-union scoped).
- keep-in-sync **OFF** → `final = existing ∪ authoritative` = **merge** (add-only).

**DECISION: DROP `conflict_handling` entirely.** One axis, one control — avoids the redundant-double-control
confusion the whole rework is fixing (cf. the dual-taxonomy footgun). The only mode not covered is `skip`
(seed-only / don't overwrite populated dependents) — a real but separate use case; note as a possible future
flag, do NOT carry the legacy tri-mode for it.

**Migration mapping** (`normalize_rule_shape`): `merge` → keep-in-sync OFF; `replace` → keep-in-sync ON;
`skip` → keep-in-sync OFF + flag for manual review (no clean equivalent; rare/likely-unused).

## Status-gate semantics under push (grill 2026-06-23, Q10 = A)

The post_status field is a GATE (Q2). Under push there are TWO posts (source + dependent), so "which post the
gate filters" must be pinned — same directionality trap as the push/pull confusion.

**DECISION: gate the SOURCE only.** A term is authoritative only if it comes from a source whose status
matches the gate ("push from published schedules"). The DEPENDENT's own status is NOT a gate.
- Matches the primary athletics intent (don't propagate from a draft schedule).
- **Forward-compatible with status mirroring:** once mirroring exists, the dependent's status is an OUTPUT of
  the rule, not a filter. Gating on dependent status would fight mirroring (it'd skip the very private/draft
  dependents mirroring manages). Source-gate avoids the chicken-and-egg.
- Rejected B (dual source+target gate, matches the standalone script's two bails [script:41/108]) — the
  target bail becomes mirroring's job. Rejected C (gate the trigger post) — muddy under bidirectional
  triggering, gates the wrong post half the time.

**Implementation note (do NOT just call inherited `should_process_post`):**
`UnifiedHandlerBase::should_process_post` ([base:527/549](includes/handlers/class-unified-handler-base.php#L527))
gates **the post passed to it** = the trigger post (interpretation C). Under bidirectional triggering the
trigger is sometimes source, sometimes dependent → wrong. The status gate must be applied specifically to
**source posts during `authoritative(T)` collection** (filter out sources failing the gate), independent of
which post fired the hook. `should_process_post` may still be used for the post-TYPE gate on the trigger, but
the post-STATUS gate is source-scoped and lives in the term-collection step.

## Label snapshot mechanics (grill 2026-06-23, Q11)

Snapshot runs on `wp-wireframe/save/payload` ([class-wireframe-bootstrap.php:34](includes/admin/class-wireframe-bootstrap.php#L34)),
PRE-storage → reads RAW Wireframe values (so `acf_field_name` is still the `post_type:field_name` option key,
before `normalize_rule_shape` splits it). Reusable helpers exist: `taxonomy_label`, `scope_label`,
`get_post_type_object`.

**DECISION (Q11): separate callback `snapshot_acf_reference_labels`** added alongside the working
`snapshot_related_labels` (don't refactor the working `related_rules` path). Owns the `related_post_terms_rules`
key. Generalize to a single dispatcher LATER, once common shapes emerge across rule types.

**DECISION: `field_label` via `acf_get_field()`** (not string-parsing the option label, not storing the verbose
option label). The option label is `"Athletics Events: Team schedule (game_team_schedule_cpt)"`; the row title
wants just `"Team schedule"`. Resolve authoritatively: `acf_get_field(raw_field_name)['label']`. Matches how the
rest of the snapshot resolves names from source, not from display strings. Fallback to bare field name if ACF
can't resolve. ACF is loaded at save (this is an ACF rule type).

Snapshot assembles the locked schema (`{Copy|Sync} {Taxonomy} terms {to|from} {field_label}{ on {statuses}}`)
into hidden subfields (or one assembled `row_title`). Statuses → comma-joined human labels via
`get_post_status_object()->label`. Status-mirror clause slot reserved (deferred, see status-mirroring.md).

## Work items (commit-sized) — FINAL (reconciled with grill Q1–Q11)
1. **`ConfigHelpers::post_status_field()`** — shared checkboxes subfield (publish/draft/pending/private/future),
   empty = any. Mirror `post_types_field()`. Add to related-post-terms config now; note follow-up sweep into
   other post-working configs (related, propagation, time_based, title_slug, hierarchical, level_restriction).
   Shared with temporal-rule.md (enforcement differs — see Q10 note there).
2. **Config rewrite** (`class-related-post-terms-config.php`):
   - Collapse source/target taxonomy → ONE `taxonomy` select.
   - Replace `bidirectional` toggle → `keep_in_sync` toggle ("Keep in sync"; help: "Remove copied terms when
     the source no longer has them").
   - **DROP `conflict_handling`** (Q9 — keep_in_sync is the merge/replace axis).
   - Add `holder_role` toggle/radio: "Field holder is the source" (push) | "Field holder is the target" (pull).
     NEW-row default = **source** (push). Field-location DERIVED from the ACF-field selection (decision C).
   - Add optional `reverse_acf_field_name` select (Q4 three-tier; help: speeds the reverse lookup; auto if ACF
     native bidi).
   - Add `post_status` gate field (Q2; help framed as SOURCE-status — Q10).
   - Add hidden label subfield(s) for the snapshot (item 5).
   - Clarify ACF-field help: the field pins the holder post type; holder_role says which end is authoritative.
3. **Storage migration** (`normalize_rule_shape`, case `related_post_terms_rules`) — per the FINAL schema table:
   collapse taxonomy (prefer source); `bidirectional`→`keep_in_sync`; map `conflict_handling`→keep_in_sync +
   flag `skip`; backfill `holder_role='target'` for legacy rows; leave `post_status` absent. Live-data safe.
4. **Handler migration + new logic** (`class-related-post-terms-handler.php`):
   - `extends HandlerBase → UnifiedHandlerBase`; typed helper calls (`int $post_id`, `array $terms`); H1+H2.
   - Implement **declarative Model 1** sync (Q7): compute `authoritative(T)` from the LIVE relationship graph,
     rule-UNION across all enabled rules targeting T on the dependent; source-authoritative removal; NO
     tracking meta.
   - Both `holder_role` modes (push = holder→referenced; pull = holder←referenced); direction-agnostic union.
   - Full **bidirectional triggering** (Q3=A): authoritative-save, authoritative-terms-change, dependent-save.
   - **Source-scoped status gate** applied in term-collection, NOT via `should_process_post` (Q10). Post-TYPE
     gate may still use `should_process_post` on the trigger.
   - Reverse lookup = three-tier (Q4): explicit `reverse_acf_field_name` → ACF native-bidi auto-detect
     (defensive, graceful) → `find_posts_with_related_post` meta_query fallback.
   - Re-entrancy = idempotent short-circuit (Q8=A): skip write when computed == current; reuse base if present.
   - **DISABLE the whole AcfIntegration term-sync engine** behind a kill-switch filter (Q5 aggressive) — not
     just our case. Handler is sole writer. Engine off is behavior-neutral for Load-Terms-on posts; removes the
     half-broken `related` shadow path on the live site. Sweep both live types, delete engine after.
   - Drop the legacy ACF-taxonomy-field write path; no `conflict_handling`; no blunt full-taxonomy wipe.
5. **Dynamic label** (`class-wireframe-bootstrap.php`): separate callback `snapshot_acf_reference_labels` on
   save/payload (Q11). **No right-arrow.** `field_label` via `acf_get_field()['label']`. Locked schema:
   ```
   {Copy|Sync} {Taxonomy} terms {to|from} {field_label}{ on {statuses}}
   ```
   - `Copy|Sync` ← `keep_in_sync` (off=Copy, on=Sync) · `to|from` ← `holder_role` (source/push=to,
     target/pull=from) · `{field_label}` ← ACF field human label · `{ on {statuses}}` ← `post_status` gate,
     only when set, comma-joined human labels via `get_post_status_object()->label`.
   - Renders: `Copy Sport Connectors terms to Team schedule` ·
     `Sync Sport Connectors terms from Team schedule on published`
   - Status-mirror clause DEFERRED (status-mirroring.md) — reserved slot after `{field_label}`, own phrase, NOT
     the gate's `on` notation.
6. **Two-stage verify** (see "Verify environments"): Stage 1 dev sandbox = mechanics + H1/H2 (synthetic data;
   single/multi-rule additive, migration shape, label, kill-switch). Stage 2 athletics test copy = real-data
   gate (push+pull, source-status gate, declarative removal no-clobber, engine-off parity for both live types,
   legacy migration neutral) BEFORE production deploy.

## Resolved (was Q1–Q3) — user approved 2026-06-23
- **Q1 — sync/lock wording → "Keep in sync" single toggle.** Replaces "Bidirectional". Help: "Remove copied
  terms when the source no longer has them." Storage key reframed from `bidirectional`. Expand to multi-state
  later only if needed.
- **Q2 — status FILTER default → leave existing = any (no silent change); new-rows default = any.** User opts
  into publish-only. `normalize_rule_shape` does NOT backfill a status (absent = any).
- **Q3 — push fan-out → synchronous for now** (matches standalone script). Revisit batch/async if slow on
  large schedules.

## Status mirroring → separate plan
Split out 2026-06-23 to [status-mirroring.md](status-mirroring.md). Plan-now/build-later; NOT in this branch.
Summary of what it parks there: shared `set_managed_status()` status-effect primitive (also feeds temporal
action #5), `mirror_status` push-only toggle, configurable owner→referenced status map (shape = open Q S0),
configurable orphan policy, re-entrancy/capability/gate-interaction opens (S2–S4), and the "Auto-Set &
Restrict" page-label naming debt (ROADMAP P4). The term-sync label here reserves a deferred slot for its
clause (item 5). **Build the term-sync rework (items 1–6) first; status mirroring follows once verified live.**

## Out of scope (→ [future-work.md](../future-work.md), FW-10)
- True cross-taxonomy copy (by slug/name mapping).
- Status-filter sweep across the *other* 5 rule types (do in this branch only if cheap; else follow-up).
- Temporal action #5 ("set post status" effect) — built later, consumes the shared status-effect primitive
  (now planned in `status-mirroring.md`). Cross-ref `temporal-rule.md`.
