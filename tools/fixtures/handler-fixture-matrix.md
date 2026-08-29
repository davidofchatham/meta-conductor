# Handler fixture matrix

Requirements inventory for the `mc-rules` fixture blueprint (local wp-litespeed
testbed, composing on GBDTE `core-structures` v4). One row-set per handler:
what schema/data/rule-config each needs, what it mutates, and what is reused
from core-structures vs MC-owned.

Derived from the handler sources (branch `main`, 0.6.1). Trigger/priority
matrix at the bottom. Companion skeleton: `tools/fixtures/mc-rules/`.

## Global design decisions

- **Isolation rule:** every seeded MC rule gates `post_types` to MC-owned
  types. Never `page`/`post`/`staff` — core-structures reseed re-saves its
  matrix pages (`save_post` fires), and MC rules scoped to shared types would
  rewrite their `department` terms and break the GBDTE matrices.
- **MC-owned schema** (prefix `mc_`): CPTs `mc_item` (flat), `mc_section`
  (hierarchical — propagation needs post_parent chains; core `page` is off
  limits per the isolation rule); taxonomy `mc_topic` (hierarchical, 4 levels,
  registered on `mc_item` + `mc_section` + `staff`); ACF group `group_mc_fields`.
- **Reused from core-structures:** site + env only (snapshot/restore, wp.sh,
  mu-plugin loader pattern), `staff`/`department`/matrix pages as **negative
  controls** — after any MC sweep, assert `department` terms on
  `page-matrix-*` and `staff-*` posts unchanged.
- **Rule seeding:** merge into `bws_meta_conductor_settings` via the
  `wp_options` recursive-merge mechanism, **after** posts/terms are seeded
  (rules fire on save hooks; seeding posts after rules would corrupt the
  manifest state). Rule arrays are positional; `id` = index, never persisted.
  Storage read-side normalization (`normalize_rule_shape`) applies at runtime,
  so seed the canonical UI-written shape (checkbox maps `{slug: bool}` fine —
  `selected_checkbox_slugs` flattens).
- **Dates:** time-based needs dates relative to seed day → `{TODAY±N}` tokens
  resolved at seed time (same pattern as core-structures `{CURRENT_YEAR}`).
- **Reseed is additive** (no key deletes). MC sweeps mutate terms by design →
  discipline is snapshot → seed → sweep → restore, not reseed-to-clean.

## Term tree (mc_topic, seeded)

```
Region (L1)
├── East (L2)
│   ├── Coastal (L3)
│   │   └── Harbor (L4)
│   └── Inland (L3)
└── West (L2)
Status (L1)          — second root: related/time-based targets live here so
├── Featured (L2)      level-restriction scenarios don't collide with them
└── Archived (L2)
```

---

## 1. hierarchical_rules — HierarchicalHandler

| Aspect | Need |
|---|---|
| Trigger | `set_object_terms` p10 only. No autosave guard needed in fixtures. |
| Schema | Hierarchical taxonomy (validated — rejects flat). `mc_topic` ✓. |
| Data | Posts on `mc_item` with 0 terms (clean slate per scenario). Tree ≥3 deep for `inheritance_depth: immediate` vs `all` distinction; sibling children under one parent for the "only when none picked by hand" outcomes (skip-if-child-selected). |
| Rule config | `taxonomy: mc_topic`, `post_types: ['mc_item']`, `inheritance_behavior` (ancestors / descendants_smart / descendants_always / both_smart / both_always), `inheritance_depth` (immediate / all). The direction × expansion pair it replaced in 0.8.0 (#16) is still READ when a row carries no `inheritance_behavior`. |
| Rules to seed | 1 baseline (`ancestors` + all levels). Behavior/depth variants toggled per-scenario in UI or via option rewrite. |
| Mutates | Same post's `mc_topic` terms; post meta `_bws_auto_terms`. |
| Scenarios | Assign Harbor (L4) → expect Coastal+East+Region auto-added; remove; promotion case (auto term kept manually). `_bws_auto_terms` asserted directly. |
| Shared reuse | None (needs hierarchical tax it may freely rewrite). |

## 2. hierarchical_level_restriction_rules — LevelRestrictionHandler

| Aspect | Need |
|---|---|
| Trigger | `set_object_terms` **p5** (pre-hierarchical) + `acf/save_post` p15. |
| Schema | Hierarchical taxonomy + **ACF taxonomy-type field** on `mc_topic` (the ACF branch discovers fields by `type==taxonomy && taxonomy==mc_topic`). |
| Data | Posts holding multiple same-level terms (one_per_level prune → keeps *last*), and mixed-depth sets (deepest_only / shallowest_only). Needs ≥2 depth levels on a post to observe pruning; tree gives 4. |
| Rule config | `taxonomy: mc_topic`, `restriction_mode` (one_per_level / deepest_only / shallowest_only), `include_ancestors` (0.8.0/#32: one meaning — keep the lineage of whatever the mode kept — and it applies in ALL three modes), `post_types: ['mc_item']`. |
| Rules to seed | 1 (one_per_level, include_ancestors off). Mode variants per-scenario; sweep include_ancestors=true in each mode, not just deepest_only. |
| Mutates | Post's `mc_topic` terms (native) AND the ACF field value (write by field key). |
| Scenarios | Native path: assign East+West (both L2) → one survives. ACF path: set via `mc_topics` field → same prune lands in both channels. Interaction: p5 runs before hierarchical p10 — combined-rule scenario (restriction then expansion) is its own row. |
| Shared reuse | None. |

## 3. related_rules — RelatedHandler

| Aspect | Need |
|---|---|
| Trigger | `set_object_terms` p10 + `acf/save_post` p20. |
| Schema | Any taxonomy. Trigger terms + target term in `mc_topic` (Status root: target `Featured`). Cross-taxonomy removal check ⇒ also a trigger term in a *second* taxonomy on the same post (reuse `department` as trigger-read-only: rule still writes only `mc_topic` on `mc_item` — `department` needs registering on `mc_item`, additive schema, or use a second mc taxonomy `mc_flag` to stay fully owned — **decide at skeleton time; default `mc_flag` flat taxonomy, zero shared surface**). |
| Data | Posts with/without trigger terms; ACF taxonomy field carrying a trigger term (ACF branch matches fields whose taxonomy ∈ trigger taxonomies). |
| Rule config | `trigger_type` (term / taxonomy), `trigger_term_id: int[]` (OR), `trigger_taxonomy`, `target_term_id` (single int), `bidirectional`, `post_types: ['mc_item']`. |
| Rules to seed | 2: term-trigger (Coastal ⇒ Featured, bidirectional on), taxonomy-trigger (`mc_flag` ⇒ Featured). |
| Mutates | Merge-adds target; bidirectional removal only when NO trigger remains anywhere on post (cross-tax check). |
| Scenarios | Add trigger → target appears; remove last trigger → target removed (bidirectional); remove one of two triggers → target stays; trigger via ACF field. |
| Shared reuse | Optional dept-as-trigger variant — deferred. |

## 4. related_post_terms_rules — RelatedPostTermsHandler (ACF reference)

| Aspect | Need |
|---|---|
| Trigger | Widest surface: `acf/save_post` p30, `save_post` p25, `set_object_terms` p15, `acf/update_value` (relationship/post_object) p5 sever capture, `before_delete_post`/`deleted_post`. |
| Schema | **ACF relationship field** on `mc_section` targeting `mc_item` (`mc_related_items`), + a **post_object** field variant (`mc_primary_item`), + a `reverse_acf_field_name` partner on `mc_item` (`mc_parent_section`) for reverse **tier 1** (explicit), + an ACF **native-bidirectional** pair (`mc_bidi_items` ⇄ `mc_bidi_sections`) for reverse **tier 2**. Tier 1 and tier 2 need SEPARATE rules — an explicit reverse short-circuits the bidi tier — so the bidi pair has its own posts (`section-bidi`/`item-bidi`) and its own taxonomy (`mc_flag`) to stay clear of every other subject. Shared taxonomy both ends: `mc_topic` (registered on both CPTs ✓). |
| Data | Holder post (`mc_section`) + ≥2 related `mc_item`s; holder with terms (push), related with terms (pull); a second holder referencing the same related post (multi-holder union on pull-side severs). |
| Rule config | `acf_field_name: "mc_section:mc_related_items"` (stored split), `holder_role` (source=push / target=pull), `taxonomy: mc_topic`, `keep_in_sync` (replace vs add-only), `post_status`, optional `reverse_acf_field_name`. |
| Rules to seed | 2 seeded: push+keep_in_sync tier-1 (relationship field + explicit reverse), push+keep_in_sync tier-2 (native-bidi pair, `mc_flag`). Pull+add-only (post_object field) stays a sweep-time rule edit. |
| Mutates | `wp_set_object_terms` on the dependent side; empty-replace on sever orphan. Declarative — no tracking meta. |
| Scenarios | Save holder → related inherit; edit relationship removing a post → sever (terms cleared if keep_in_sync + no other holder); **edit the DEPENDENT's reverse field removing its source → same sever, both tiers (#43)**; clear one of two sources → remaining source's terms survive; delete holder → orphan cleanup; add-only never removes; **bare `update_field()` with no post save → still applies (#42)**. |
| Sweep | `sweep-related-post-terms-sever.php`, stepped (one step per eval — the #42 flush runs on `shutdown`). Note: an explicit `reverse_acf_field_name` makes the reverse field the source of truth for reverse resolution and the plugin does NOT write it, so a holder-end edit alone leaves it stale and strips nothing (step `s7`, fails by design). Consistent graphs — native bidi (`s8b`) or both sides edited (`s7b`) — strip as before. |
| Shared reuse | Tempting to use `staff` as relationship target — writes would stay in `mc_topic`, invisible to GBDTE matrices. Deferred; own both ends first. |

## 5. propagation_rules — PropagationHandler

| Aspect | Need |
|---|---|
| Trigger | `save_post` p15, `set_object_terms` p10, `acf/save_post` p25. |
| Schema | **Hierarchical post type**: `mc_section` (public + hierarchical; empty `post_types` resolves to all hierarchical public types — which would include `page`! ⇒ rule MUST pin `post_types: ['mc_section']`). Taxonomy `mc_topic`; ACF taxonomy field participates (native+ACF union read, ACF write by key). |
| Data | 3-level `mc_section` chain (grandparent → parent → child), statuses publish + one draft child (descendant statuses publish/draft/private included). Child holding an independent term (removal propagation must NOT strip it). |
| Rule config | `taxonomy: mc_topic`, `post_types: ['mc_section']`, `conflict_handling` (merge / replace / skip). |
| Rules to seed | 1 (merge). |
| Mutates | Descendants' terms (down), new-child inherit on child save (up), ACF field on descendants. |
| Scenarios | Term on grandparent → appears on all descendants incl. draft; remove from parent → removed from child except independently-held; create/save new child under parent → inherits; replace vs merge conflict modes. |
| Shared reuse | None. **The empty-post_types ⇒ all-hierarchical default is the single biggest shared-site foot-gun in the plugin — never seed a propagation rule with empty post_types on this testbed.** |

## 6. time_based_rules — TimeBasedHandler

| Aspect | Need |
|---|---|
| Trigger | `save_post` p20, `publish_post` p10, cron `bws_taxonomy_manager_cleanup` (daily). |
| Schema | Any taxonomy — target `mc_topic:Archived` / `Featured`. Filter needs posts with/without terms in `filter_taxonomies` / `filter_terms`. |
| Data | `mc_item` posts: one matching filter, one not. Dates seeded relative to run day: in-range rule (`{TODAY-1}`..`{TODAY+7}`), expired (`{TODAY-30}`..`{TODAY-2}`), future (`{TODAY+10}`..`{TODAY+20}`). String Y-m-d comparison. |
| Rule config | `start_date`/`end_date` (Y-m-d, required), `target_term_id` (single), `filter_taxonomies`, `filter_terms`, `post_types: ['mc_item']`. |
| Rules to seed | 3 (in-range / expired / future). |
| Mutates | Merge-adds target in-range on save; removes when outside range; cron cleanup strips expired target from ALL matching posts (no provenance tracking — over-removal is current known behavior, assert it as-is). |
| Scenarios | Save in-range → term added; save post against expired rule holding the term → removed; future rule → no-op; cron fire (`wp cron event run bws_taxonomy_manager_cleanup` or `do_action` eval) → bulk strip. |
| Shared reuse | None. |

## 7. title_slug_rules — TitleSlugHandler

| Aspect | Need |
|---|---|
| Trigger | **None since #64** — the handler registers nothing. `FormatDispatcher` runs it from `TermDispatcher`'s drain, after the term pass for the same entity; the dispatcher owns the write, the revision suppression and `redirect_post_location`. (Was `wp_insert_post_data` p1 + `acf/save_post`/`save_post` p99.) |
| Schema | Single `post_type` per rule, **one rule per post type** (first-match-wins). Own CPT so shared titles never mutate: `mc_item`. Token sources: post meta (ACF date field `mc_event_date`), terms (`{term:mc_topic}`). |
| Data | Posts with meta + terms feeding tokens; two posts colliding on generated slug (date_escalation ladder year→minute → `wp_unique_post_slug`). |
| Rule config | `name`, `post_type: mc_item`, `title_pattern` / `slug_pattern` (tokens: `{default_title}`, `{meta:x}`, `{date_year:x}`, `{pub_*}`, `{term:tax}`, `{terms:tax}`), `slug_mode` (prefix/suffix/replace), `date_escalation` + `date_field`. |
| Rules to seed | 1 (`mc_item`: slug_pattern with `{meta:...}` + `{term:mc_topic}`, escalation on). Pattern variants per-scenario (one-per-type limit blocks parallel rules). |
| Mutates | `post_title`/`post_name` only; meta `_bws_raw_title`/`_bws_applied_title`; option `bws_title_slug_rule_status`. No taxonomy writes. |
| Scenarios | Same-pass `{term:TAX}` read (a term rule's write, not the previous save's); provocation-independence (editor save / ACF save / term write / bulk); idempotent re-pass; slug collision escalation. The pre-write vs post-write split is GONE (#64) — every apply is post-write. |
| Shared reuse | None — a rule on `page`/`post`/`staff` would rename GBDTE fixture slugs and break every matrix URL. Hard no. |

---

## Trigger/priority quick matrix (from handler sources)

| Handler | set_object_terms | save_post | acf/save_post | other |
|---|---|---|---|---|
| level_restriction | **p5** | — | p15 | |
| hierarchical | p10 | — | — | |
| related | p10 | — | p20 | |
| propagation | p10 | p15 | p25 | |
| related_post_terms | p15 | p25 | p30 | acf/update_value p5, before/deleted_post |
| time_based | — | p20 | — | publish_post p10, cron daily |
| title_slug | — | — | — | **none (#64)** — run by `FormatDispatcher` from the shared drain |

## Sweep method (learned running §1 on the live testbed)

Two constraints that shape EVERY per-handler sweep — not §1-specific:

1. **Per-handler sweeps require single-handler isolation.** All 7 handlers hook
   at boot; emptying a rule *array* just makes that handler's loop no-op, but
   the hooks stay live. Two kinds of interference on `mc_item` + `mc_topic`:

   - **Reference-based (the non-obvious one):** the `related_post_terms` holder
     `section-holder` (`mc_related_items => [item-alpha, item-beta]`,
     `keep_in_sync=true`, role `source`) **pushes its own terms onto both
     referenced items on any term edit** — a hierarchical/level/related/time
     edit on `item-alpha`/`item-beta` gets clobbered to the holder's set
     (observed: Harbor expansion `[13,14,15,16]` overwritten to `[14,15]` by the
     p15 push). **Dodge it** by using the push-free subjects `item-solo-a` /
     `item-solo-b` (referenced by no holder, no seeded terms).
   - **Rule-scope overlap (expected, visible):** `related_rules`,
     `level_restriction`, and `time_based` all also scope `mc_item` + `mc_topic`.
     On a solo subject with all rules live, assigning Harbor yields
     `[Region,Coastal,Harbor,Featured]` — hierarchical expands, related adds
     Featured (Coastal⇒Featured), one_per_level then prunes East (East and
     Featured are both L2). Correct composition, but NOT a pure single-handler
     read. For that, still empty the other `mc_item` rule arrays.

   Isolate + push-free subject for a pure single-handler read:
   ```
   eval1: empty every OTHER mc_rules type + clear_cache + setup solo subject
   eval2: act + assert            (fresh request — see #2)
   restore: re-seed (rebuilds the full option deterministically)
   ```
   Do NOT back up the option to `/tmp` between calls — each `docker compose run`
   is a fresh container; `/tmp` does not persist. `update_option` DOES persist
   (it's in the DB), so cross-eval isolation is fine; re-seed is the restore.

2. **Handler dedup is per-request — but NOT for hierarchical anymore (0.6.2).**
   Some handlers keep a per-request `$processed[key]` map (`UnifiedHandlerBase`,
   `TitleSlugHandler`) that short-circuits a second apply for the same subject
   within one PHP request. For THOSE, two user-edits in one `wp eval` → the second
   is a silent no-op; give each user-edit scenario its **own eval** (one WP-CLI
   call = one request = fresh dedup), or a single-eval multi-edit sweep reports
   artifacts (e.g. "removal did nothing"), not real behavior.
   **`HierarchicalHandler` no longer has this map** — it was removed (commit
   `03ee8b4`) because it silently skipped legitimate double-saves. Re-entrant
   recursion is still blocked by the separate `$processing` flag, and `apply_rule`
   recomputes from current terms + `_bws_auto_terms` fresh each call (idempotent),
   so a second hierarchical edit in the same eval now recomputes correctly.
   Confirmed on the testbed (§1d).

### §1 hierarchical — results

- **§1a expand** ✅ Harbor(L4) → `[Region,East,Coastal,Harbor]`,
  `_bws_auto_terms.mc_topic = [Region,East,Coastal]`.
- **§1b remove leaf only** — NOT a cascade. Removing just Harbor from the
  expanded set leaves `[Region,East,Coastal]`, `auto=[]`: the surviving
  ancestors are **promoted to user terms** (class docblock: "kept by the user
  after its source is removed → promoted"). To drop ancestors the user must
  remove them in the same edit. Matrix's original "remove" row was
  underspecified — this is documented, correct behavior.
- **§1c promotion + re-expand** ✅ From `[13,14,15,16]` keep only East(14):
  East promoted → child_to_parent re-expands → Region(13) re-added.
  Result `terms=[Region,East]`, `auto=[Region]`.
- **§1d double-save in one request** ✅ (0.6.2, `$processed`-removal regression
  guard). In ONE eval: edit1 assign Harbor → `[Region,East,Coastal,Harbor]`;
  edit2 SAME request set only West → `[Region,West]`, `auto={mc_topic:[Region]}`.
  The second edit recomputes (pre-0.6.2 the `$processed` map would have skipped
  it → raw `[West]`, stale Harbor-chain auto). No recursion/hang — `$processing`
  caught the handler's own write. **This is the one scenario that must live in a
  single eval**, opposite the usual one-edit-per-eval rule — it exists to prove
  the dedup map is gone.
- Negative controls after sweep: `staff` `department` terms, matrix/ls page
  slugs, and `bws_dynamic_tags_settings` all unchanged (MC only ever writes
  `mc_topic` on `mc_item`).

### §2 level_restriction — results

Handler level convention: **root = level 0** (`get_term_level`). Tree levels:
Region L0 › East/West L1 › Coastal/Inland L2 › Harbor L3. (Watch the tree:
Inland is a child of East = L2, NOT an L1 sibling — a same-L1 pair is
**East + West**, not East + Inland.)

Run against `item-solo-a`, level_restriction isolated (other rule arrays
emptied), one scenario per eval, rule mode edited between scenarios.

- **§2a one_per_level** ✅ East(14)+West(18), both L1 → `[West]`. Keeps
  `end()` of the level group = last-added. Confirmed same-level pruning.
- **§2b deepest_only** ✅ Region+East+Coastal+Harbor (L0–L3) → `[Harbor]`.
  With `include_ancestors=true` → `[Region,East,Coastal,Harbor]` (full chain).
- **§2c shallowest_only** ✅ same mixed set → `[Region]` (L0 only).
- **§2d ACF path** ✅ set `mc_topics` field to East+West + `acf/save_post` →
  prune to `[West]` lands in BOTH the ACF field value and native terms
  (dual-channel sync). Note the taxonomy field has `save_terms=1`, so the
  native-write also trips the p5 `set_object_terms` path — both converge.
- Negative controls (staff/dept, matrix slugs, dynamic-tags) unchanged.

### §3 related — results

Seeded rules: [0] term-trigger Coastal(15)⇒Featured(20), bidirectional;
[1] taxonomy-trigger any `mc_flag`⇒Featured(20), one-directional.
Run against `item-solo-a`, related isolated, one scenario per eval.

**Converted to a pure applier by #61.** The handler registers no hooks; the
dispatcher runs it in list position and it recomputes from LIVE STATE. So a
sweep must provoke a pass (`drain()` / `run_pass()`) before reading terms back
in the same request, and the removal rule below changed — see the closing note.
Covered by `sweep-61-appliers.php` step `related`.

- **§3a term add** ✅ assign Coastal(15) → `[Coastal,Featured]`.
- **§3b bidirectional remove** ✅ remove Coastal (last trigger) → `[]`.
  Featured dropped — `get_trigger_terms` confirmed no trigger remains
  (checked across ALL taxonomies, not just the changed one).
- **§3c multi-trigger keep** ✅ rule[0] edited to `trigger_term_id=[15,14]`
  (Coastal+East). From `[Coastal,East,Featured]` remove Coastal → `[East,Featured]`.
  One trigger removed, the other still present → target retained (V4 semantics).
- **§3d taxonomy trigger** ✅ assign Priority(22, `mc_flag`) → Featured(20)
  added to `mc_topic`. Cross-taxonomy trigger→target: `mc_flag` change drives
  an `mc_topic` write.
- Negative controls unchanged.
- **§3e removal from ABSENCE, not from a delta** ✅ *(#61 behaviour change).*
  `item-solo-b` given Featured with the rules silenced, then a pass run: the
  bidirectional rule removes Featured even though no trigger was ever present,
  so no removal delta ever existed. Under the delta model that post kept the
  term indefinitely.
- **§3f a rule whose trigger no longer EXISTS removes nothing** ✅ *(#61, the
  floor under §3e).* Same bidirectional rule with a nonexistent
  `trigger_term_id`: Featured stays. `get_trigger_terms()` answers `[]` both for
  "not on this post" and for "names a term that was deleted", and only the
  live-state reading has to tell them apart — under the delta model a
  nonexistent trigger simply generated no signal. Reading absence as *remove*
  here would turn one deleted term into a rule stripping its target from every
  in-scope post on every pass. A rule listing several triggers still works off
  whichever survive (§3c's shape).
- Note: apply is merge-add. Removal fires whenever the rule is bidirectional
  and NO trigger term is on the post — checked across ALL taxonomies, not just
  one. It used to require a trigger to have been removed *in that write*, which
  a pass cannot know; §3b/§3c still hold because they are also true of the
  live-state reading, and §3e is the case that separates the two.

### §4 related_post_terms — results

Seeded rule: PUSH source, field `mc_section:mc_related_items` (relationship),
taxonomy `mc_topic`, `keep_in_sync=true`. `section-holder` (terms
Coastal+East) references `item-alpha`/`item-beta`; `section-holder2` is the
second-holder subject. This is the handler whose push clobbers plain mc_item
sweeps — tested here WITH itself isolated (other rule arrays emptied).

**Converted to a per-rule pull applier + declared fan-out + capture layer by
#63** (`sweep-63-acf-reference.php`). The §4 results below stand as behaviour;
what changed is when and in what company they happen — see §63 at the end of
this section.

**CLI trigger note — CLOSED by #63.** A bare `update_field($f,$v,$id)` fires
`acf/update_value` (→ sever capture) but neither `save_post` NOR
`acf/save_post`, and before #63 the sever was captured and then never drained,
so the dependent kept a term whose source was gone. The capture now hands its
entities to the dispatcher at drain start and `AcfWriteQueue`'s shutdown flush
marks the post, so the bare write reconciles on its own (§63d/e). The
`do_action('acf/save_post', $holder_id)` flush in the §4 evals is no longer
load-bearing; it is left in place because it is also what a real form save
does.

**Reading terms back in the same eval now needs a drain.** The pass is no
longer synchronous with the write (CLAUDE.md don't 6b, trap a), which is what
made `sweep-related-post-terms-sever.php` step `s9` fail on conversion — it
asserted immediately after `wp_delete_post()`. It calls `drain()` now.

- **§4a push** ✅ save holder → `item-alpha`/`item-beta` both replaced with the
  holder set `[East,Coastal]` (keep_in_sync replace; a stale term on an item
  from a prior sweep was wiped).
- **§4b sever (edit)** ✅ drop `item-beta` from the relationship + flush →
  `item-beta` emptied (severed, keep_in_sync, no other holder); `item-alpha`
  retained. `capture_removed_dependents` + `process_severed` path.
- **§4c delete holder** ✅ make holder the SOLE source of `item-alpha`, then
  `wp_delete_post(holder, true)` → `item-alpha` orphan-cleaned to `[]`
  (`before_delete_post` capture → `deleted_post` strip). Re-seed recreates the
  holder (new post ID — fixtures address by slug, not ID; the old 104 became
  124, harmless).
- **§4d multi-holder union** ✅ two holders reference `item-alpha` (holder1
  `[East,Coastal]`, holder2 `[West]`) → union `[East,Coastal,West]`. Sever from
  holder1 → recompute from remaining source (holder2) → `[West]`. Declarative
  source-authoritative recompute confirmed: a severed dependent keeps other
  holders' contributions, not blindly emptied.
- Negative controls unchanged. Restore = re-seed (recreates the force-deleted
  holder and its relationship + terms).

**§4d is still a union and always will be** — that is ONE rule resolving two
sources, and a rule unions its own sources. What #63 changed is two *rows*, see
§63a/b.

#### §63 related_post_terms as a per-rule applier (#63, 0.8.0) — results

`sweep-63-acf-reference.php`, nine assertions, all green. Rules are authored by
hand: the seeded pair is two rows in two different taxonomies, which cannot
contend and so cannot demonstrate precedence. Subject is `item-solo-a` (alpha
is renamed by the title_slug rule on any real save).

- **§63a/b — two rows straddling another type's rule.** Row A pushes from
  `mc-holder` (Coastal) over the tier-1 explicit reverse; row C pushes from
  `mc-bidi-holder` (West) over the tier-2 native bidi; row B is a `related`
  rule keyed on Coastal ⇒ Inland. `[A,B,C]` → `[west]`; `[C,A,B]` →
  `[coastal,inland]`. Both halves differ: which owning row won, and whether the
  middle rule fired at all. Before #63 the type held one slot in the order, so
  these two arrangements were the same arrangement.
- **§63c — sever inside an ordered pass.** Dependent-end sever (#43 shape) with
  row B after it: the subject empties AND Inland is not re-added, because B runs
  against the withdrawn state. A private write would have left B reading the
  pre-withdrawal terms.
- **§63d/e — the bare-`update_field()` sever now reconciles**, split across
  three evals (stage / bare write / assert). This is the KNOWN LIMIT above,
  closed.
- **§63f — pull direction + tier-3 reverse lookup.** `holder_role=target` over
  `mc_item:mc_parent_section` with no reverse field and no native bidi. Editing
  the SOURCE re-syncs the holder, reached only by `fan_out()` running the tier-3
  scan — nothing else marks the holder dirty.

- **§63g — a sever licenses only the row whose link was cut.** Three rows in
  one taxonomy over one subject: A (tier-1 push), C (tier-2 bidi push) and S (a
  pull control over the subject's own `mc_parent_section`). Cutting A's link
  leaves A and S both resolving nothing, but only A is RECORDED as severed, so
  after the pass the subject holds C's `{west}` rather than being emptied by S.
  Verified discriminating: with the sever record keyed by taxonomy alone the
  step returns `[]`. **A row over the tier-2 bidi pair does not work as this
  control** — ACF writes both sides, so clearing the item's `mc_bidi_sections`
  also fires `acf/update_value` for the holder's `mc_bidi_items`, and a pull row
  over one side has the other side as its reverse field. Both are severed, and
  correctly: they are two views of one link.

**Sweep trap (recorded because it cost a red run).** The capture path reads
rules through a request-lifetime memo, and `mc63_stage()` writes relationship
fields while the rules are silenced — which fills that memo with the empty set.
A sever later in the SAME eval is then captured against no rules and silently
does nothing. Every sever step therefore runs in its own request.

### §5 propagation — results

Chain `section-grand → section-parent → section-child` (+ `section-draft`
under parent). Rule: merge, `mc_section` pinned. Run against the chain,
propagation isolated, one edit per eval.

**Fixture fix (manifest v3→v4, B1):** `section-child`'s independent term (West)
was seeded native-only via `post_terms`, leaving its `mc_topics` ACF mirror
empty. Propagation's ACF-merge write then merged against the empty ACF value
and the save_terms sync clobbered the native-only term. Moved it to
`post_fields` (`mc_topics => [{TERM:topic-west}]`); seed.php now resolves
`{TERM:}` tokens in taxonomy fields, and `save_terms=1` populates both stores.
H7 extended to validate `{TERM:}` tokens in `post_fields`.

- **§5a down-propagate** ✅ Coastal on grandparent → parent + child + DRAFT all
  receive it (`get_all_child_posts` includes publish/draft/private, recursive);
  child keeps its independent West via merge. (Only passes once both of the
  child's channels agree — see the fixture fix.)
- **§5b removal — FIXED (0.6.2, #45).** Removing a term from the parent now
  propagates to descendants when the parent carries an `mc_topics` ACF mirror
  field. Old bug: `on_parent_terms_set` ran removal-propagation (strips the term
  from children) AND `propagate_terms_to_children` in the same handler pass; the
  add-side read `get_post_terms(parent)` = union(native, ACF), the parent's ACF
  mirror still returned the OLD value at that instant, and the just-stripped term
  was immediately RE-propagated (`SET 102 new=[18]` strip → `SET 102 new=[15,18]`
  re-add, one request). Fix: `on_parent_terms_set` subtracts the same-pass
  removed term IDs from `propagate_terms_to_children`'s add source
  (`$exclude_term_ids`). Verified on the local testbed both ways: child `[15,18]`
  → `[18]` (Coastal stripped, stays gone; independent West kept) for
  `wp_set_object_terms([])` AND `update_field([])` (the true mirror-lag path,
  field key `field_mc_topics_section`). Down-ADD unchanged.
  **Coverage caveat (Load/Save-Terms ON only).** The fix lives in
  `on_parent_terms_set` (the `set_object_terms` hook). It covers `update_field([])`
  only because the field's save_terms=ON sync writes native and fires
  `set_object_terms`. On a **save_terms-OFF** field, `update_field([])` clears the
  ACF mirror WITHOUT firing `set_object_terms`, so the exclude list is empty and the
  bounce is NOT suppressed. Accepted: OFF fields are an intentionally separate store
  and `get_post_terms`'s docblock already states propagation is not
  channel-preserving there. The tested fixtures are all save_terms=ON. If a
  channel-separate model is ever needed, an ACF-side entry point (or the
  native/ACF split in `get_post_terms`) closes this.
- **§5e removal via `wp_remove_object_terms` — FIXED (#47).** `wp_remove_object_terms($parent, $term, $tax)`
  fires `deleted_term_relationships`, NOT `set_object_terms`, so before the fix
  the removal never reached `propagate_term_removals_to_children` and descendants
  silently kept the term. Fix: new `on_parent_terms_deleted` hook on
  `deleted_term_relationships` runs the removal walk (same `$processing` guard).
  Double-fire on the plain-set path is real — `wp_set_object_terms` removes
  dropped terms via an INTERNAL `wp_remove_object_terms` (taxonomy.php:2924) whose
  `deleted_term_relationships` fires FIRST, then `set_object_terms` — so the delete
  hook records handled tt_ids in `$removals_handled` and `on_parent_terms_set`
  subtracts them from the removal WALK (not from the #45 add-pass exclude list,
  which must stay full). Verified on the local testbed: put Coastal on parent →
  child `[15,18]`; `wp_remove_object_terms(parent, Coastal)` → child `[18]`
  (Coastal gone native + ACF, independent West kept), draft-child `[]` too. #45
  plain-set path re-swept green in the same run — one removal pass, no bounce-back.
- **§5c new-child inherit** ✅ new `mc_section` created under the parent inherits
  the parent's terms on its own save (the `post_parent > 0` branch of
  `on_parent_post_save`, not `wp_insert_post`).
- **§5d conflict modes** ✅ merge → child = `[Coastal, West]` (independent term
  kept); replace → child = `[Coastal]` (independent term dropped, as designed).
- Negative controls unchanged. Restore = re-seed (reverts conflict_handling to
  merge, resets the chain).

**Converted to a pull applier + declared fan-out by #62** (`sweep-62-propagation.php`).
The handler registers nothing except its `deleted_term_relationships` CAPTURE hook,
and every result above still holds — by a different route. What changed:

- **The direction.** `apply_to_post(P, rule)` reconciles P against P's PARENT and
  writes P only; `fan_out(P, rule)` returns P's IMMEDIATE children and the
  dispatcher marks them dirty, so each descendant gets its own full ordered pass.
  `get_all_child_posts`, `propagate_terms_to_children`,
  `propagate_term_removals_to_children`, `inherit_terms_from_parent` and the four
  hook callbacks are all gone; the recursion the first of those did now lives in
  the queue.
- **§5a/§5c** are the same statement about the same posts — §62a asserts the whole
  chain (publish + draft) reaches the term, and adds what the push model could not
  state: **one pass per chain member**, read off the `meta_conductor_term_pass_enabled`
  filter, which is the termination proof for the fan-out.
- **§5b/§5e removal** survive as **§62b**, but the mechanism inverted. There is no
  removal WALK and no `$removals_handled` dedup any more: `deleted_term_relationships`
  CAPTURES the removed term ids into a request-scoped map (the only hook that sees
  a `wp_remove_object_terms`, and the one that fires first on the plain-set path),
  and each child's applier subtracts what its parent lost. The #45 ACF-mirror-lag
  bounce is subsumed rather than special-cased: the capture is filtered to terms
  the parent does NOT currently hold NATIVELY, so a lagging mirror stays excluded
  and a remove-then-re-add in one request is *not* — which the old blanket
  exclude-list got wrong. The Load/Save-Terms-OFF caveat above is unchanged.
- **§62e (new) — the claim, and the empty-parent trap.** `claim` proves `owning`
  (`replace`) does NOT strip a child's own terms when the parent holds nothing in
  the taxonomy (both pre-#62 push paths bailed on `empty($parent_terms)`; the
  pull rewrite has to bail in `target_term_ids()` instead), that it replaces the
  whole taxonomy once the parent does have a source, and that `contributing`
  keeps the child's independent term — §5d restated against the applier.
  **Setup gotcha, and it is the fixture's own v3→v4 lesson:** the independent
  term must be written to BOTH stores (`mc62_set_own_terms` → `update_field`),
  because propagation writes the `mc_topics` mirror before it reads native and
  the save_terms sync then overwrites native with the merge result. A
  native-only term opposite an empty mirror is destroyed before the claim sees
  it, which reads as `merge` behaving like `replace`. `mc62_clean_chain()` clears
  both for the same reason.
- **§62c/§62d (new) — the #35 interaction, settled.** `order` / `order-swap` run
  propagation and a `descendants_always`/`immediate` hierarchical rule over the
  same chain in both list orders. Propagation first: the child pulls
  `[east,coastal,inland]` and hierarchical then expands it **once**, adding
  `harbor` — exactly one extra level, and the grandchild settles at the same set
  instead of gaining a level per generation. Hierarchical first: the child
  inherits `[east,coastal,inland]` with **no** expansion, because hierarchical ran
  before the terms arrived. Two different, documented outcomes from one edit —
  which is the point: the outcome is the author's order, not handler construction
  order.

### §6 time_based — results

3 seeded rules (dates relative to seed day): [0] in-range `{TODAY-1}..{TODAY+7}`
→ Featured, filter `mc_topic`; [1] expired `{TODAY-30}..{TODAY-2}` → Archived,
no filter; [2] future `{TODAY+10}..{TODAY+20}` → Archived. **[1] and [2]
share a target term on purpose** — a deliberate collision pair (§6f/§6g, #69),
and the ready-made testbed case for #65's detector. Don't "tidy" the duplicate
target away. **Converted to a pure applier by #61.** The handler registers nothing;
`save_post`/`publish_post` are the dispatcher's business now, and the daily
`bws_taxonomy_manager_cleanup` cron is registered in `TaxonomyManager` and
ENQUEUES the posts it selects rather than removing terms itself (§6h). String
Y-m-d comparison. Run against gamma/delta/solo subjects, time_based
isolated. (Seeded on 2026-07-21; the ± windows are re-resolved every seed, so
this is date-independent.)

- **§6a in-range apply** ✅ save `item-gamma` (holds Coastal ⇒ has an `mc_topic`
  term ⇒ passes the `mc_topic` filter) → Featured merge-added.
- **§6d filter miss** ✅ save `item-delta` (no `mc_topic` term) → Featured NOT
  added (`post_matches_filter` returns false).
- **§6b future no-op** ✅ save a clean item → the future rule adds nothing.
- **§6c expired removal on save** ✅ item holding Archived → save → the expired
  rule removes it (`!in_date_range && has_target`). (Side effect: an item whose
  only `mc_topic` term is Archived momentarily satisfies rule[0]'s taxonomy
  filter, so Featured is added in the same save — expected multi-rule
  composition, not a defect.)
- **§6e cron cleanup / retroactive ownership** ✅ `do_action('bws_taxonomy_manager_cleanup')`
  strips the expired rule's Archived target from ALL matching `mc_item` posts in
  one pass, regardless of how the term got onto each post.

  **This is the model, not a limitation.** A rule owns exactly its configured
  target terms and recomputes correctness from config each evaluation, keeping
  no record of what it applied — which is what makes ownership *retroactive*: a
  rule corrects posts tagged before it existed, with no migration. The accepted
  cost is that a manual tag colliding with a configured target is swept. See
  [ADR 0001](../../docs/adr/0001-temporal-rule-general-model-constrained-ui.md)
  → Consequences, and CONTEXT.md: *"A claim is settled by config and live
  recomputation, never by provenance."*

  **Sweep-verified only — nothing automated covers it,** and that is deliberate.
  Provenance is rejected *at this point* rather than unconditionally (ADR 0002
  records the third rejection), so pinning this in `verify.php` would be a small
  standing bet against a decision that could be revisited, paid for by widening
  the one mutating probe that file tolerates. The sweep record is the right
  weight.

- **§6e-bis cron cleanup, provenance-neutral** ✅ **this is what A7 asserts.**
  `verify.php` A7 drives the apply through the handler and then fires the event:
  isolate `time_based_rules`, synthesize a rule set holding ONLY the seeded
  expired-Archived rule with its window slid in-range, save `item-solo-a` so the
  handler applies Archived, assert that landed, append Featured by hand as a
  negative control, write the expiry back, fire, assert Archived went and
  Featured stayed.

  Handler-applied because that asserts the actual contract — *cleanup removes
  what its own rule applied* — and exercises the apply path a hand-planted term
  leaves untested. (That path is how §6g was found.) Both deviations from the
  seeded rule set are deliberate and asserted; running the rule ALONE is not
  tidiness, see §6g. A7 replaced a `wp_next_scheduled()` check, which proves
  nothing: the event is scheduled at plugin load and stays scheduled under
  `DISABLE_WP_CRON` (that constant disables only the page-load spawner), so it
  passed on a site where `cleanup_expired_rules()` had never run. A7's
  PRECONDITION is `item-solo-a` at seed state — it clears the subject rather
  than restoring it.

- **§6f the collision on the CRON path** ⚠ *known collision, warned not resolved
  — [#69](https://github.com/davidofchatham/meta-conductor/issues/69).*
  The seeded rule[1] (expired) and rule[2] (future) both target Archived — a
  deliberate pair, see the §6 preamble. `cleanup_expired_rules()` re-runs daily
  for as long as rule[1] stays expired, which is `recompute each evaluation`
  working as intended, so once rule[2]'s window opens the daily cron strips what
  rule[2] applies. Reproduced by sliding rule[2] into range with rule[1] left
  expired: save → `[Archived]`,
  `do_action('bws_taxonomy_manager_cleanup')` → `[]`. Unreachable on seed day —
  a future rule has applied nothing — which is why A7 can assert the seed-day
  state without waiting on anything.

- **§6g the collision on the SAVE path** ⚠ *known collision, warned not resolved
  — [#69](https://github.com/davidofchatham/meta-conductor/issues/69).* The
  sharper of the two. `apply_time_based_rule()` ends with
  `elseif (!$in_date_range && $has_target_term) { remove }`, evaluated per rule
  in array order against a freshly-read `$has_target_term`. So an out-of-range
  rule removes the target term whoever applied it — including a rule earlier in
  the same `process_post()` loop. From `{TODAY+10}` rule[2]'s term can therefore
  never land at all: it applies, and the permanently-expired rule[1] strips it
  before the save returns. ADR 0001 contemplated two rules that *"may fight"*;
  this is the degenerate case where neither ever wins.

  Found while making A7 handler-driven, because it blocks that route outright.
  Isolated on the testbed by varying only rule-set membership:

  ```
  rule[1] slid in-range, all 3 rules present   after save = []
  same, but rule[2] (future) dropped           after save = [21]
  slid rule alone                              after save = [21]
  ```

  This is why A7 synthesizes a single-rule set instead of only sliding dates.
  Under #61's ordered passes the outcome is now deterministic by
  author-controlled list order rather than array order (ADR 0002 — order is the
  resolution mechanism); #65 is what makes the pair visible at authoring time.
  The collision itself is unchanged — a pair on one target still cancels — but
  *which* of the two wins is now something the author drags rather than an
  artefact of array position.

- **§6h the cron sweep is a full ordered pass** ✅ *(#61).*
  `cleanup_expired_rules()` no longer removes anything itself: it selects the
  posts an expired rule still holds its target on, marks each dirty and drains.
  Proved on a hand-authored three-row list — expired rule (the selection
  basis), an in-range rule (the PRODUCER, writes Featured), and a `related`
  rule keyed on Featured (the CONSUMER, writes Coastal). `item-solo-a` holding
  only Archived, then `do_action('bws_taxonomy_manager_cleanup')`:

  ```
  consumer BELOW producer   after cron = [coastal, featured]   (archived gone)
  consumer ABOVE producer   after cron = [featured]
  ```

  The control is the assertion. A sweep that still removed terms by hand, or a
  pass that ran rules in any order but the authored one, would leave the first
  line looking identical and only the second would move. The consumer has no
  trigger of its own for the producer's write — the producer's
  `wp_set_object_terms` is exactly what the pass lock suppresses — so seeing
  Coastal at all means it ran later in the SAME pass.
  (`sweep-61-appliers.php` steps `cron` / `cron-swap`.)

- **§6i publish still provokes the date-window path** ✅ *(#61.)* The handler's
  own `publish_post` hook is gone; a draft→publish transition reaches the pass
  through the dispatcher's save-path drain (`wp_after_insert_post` p999), with
  no explicit drain in the sweep. (`sweep-61-appliers.php` step `publish`.)
- Negative controls unchanged.

### §7 title_slug — results

Seeded rule: `mc_item`, `slug_pattern = {default_slug}-{date_year:mc_event_date}`,
mode replace, `date_escalation=true`, `date_field=mc_event_date`. It's a META
pattern (`date_year:`). **As of #64 that distinction is gone** — the pre-write
`wp_insert_post_data` path is deleted and every apply happens in the format pass,
so §7a-§7c below hold for meta and non-meta patterns alike. Subjects: `item-alpha` (event 2030-03-15),
`item-slug-a`/`item-slug-b` (both titled "Slug Probe", both event 2030-04-01 —
a deliberate slug collision). Run title_slug isolated, `do_action('acf/save_post', id)`
followed by a `drain()` — since #64 the apply is not synchronous with the trigger.

- **§7a slug build** ✅ save `item-alpha` → `mc-item-alpha-2030` (default_slug +
  meta year, replace mode).
- **§7b collision escalation** ✅ `item-slug-a` → `slug-probe-2030`; `item-slug-b`
  collides → escalates year→month → `slug-probe-2030-04` (month spliced adjacent
  to the year, not appended). Ladder confirmed.
- **§7c idempotent re-save** ✅ re-saving either subject twice more leaves the
  slug unchanged — no drift, no further escalation.
- Negative controls unchanged.

**⚠️ RESTORE GOTCHA, WIDENED BY #64.** It used to be "this handler only, and
only when a post is saved". The format pass now runs for **every entity the
drain reaches**, and the drain reaches far more than saves — a propagation
fan-out's children, a captured sever's dependent, anything the ACF write queue
flushed. Observed on this fixture: `sweep-63-acf-reference.php restore` writes
the holder's relationship field, which marks `item-alpha` dirty, which gives it
a format pass, which applies the manifest's `{default_slug}-{date_year:...}`
rule and renames it to `mc-item-alpha-2030`. Nothing about that is a bug — it is
the behaviour the changelog calls out — but it means **any sweep that restores
the manifest rules and then touches an `mc_item` can rename one**, not just a
title_slug sweep. Check `wp post list --post_type=mc_item --fields=ID,post_name`
after a restore, and repair with a `wp_update_post` under
`add_filter('meta_conductor_acf_reapply_enabled','__return_false')` before
re-seeding.

**RESTORE GOTCHA (the original): it renames `post_name`.** Re-seed's
`mc_fixture_find_post` addresses posts by `post_name`, so a renamed subject reads
as missing and the seeder INSERTS a duplicate. Before re-seeding after a
title_slug sweep you MUST first (a) empty the `title_slug_rules` array (so the
rename doesn't re-fire) and (b) `wp_update_post` the subjects' `post_name` back to
their manifest values. Then re-seed. Verified afterward: `mc_item` count = 8
(6 fixtures + 2 solo), all unique names, rule live, no duplicates.

#### §64 title_slug as a format applier (#64, 0.8.0) — results

`sweep-64-format.php`, 13 assertions across `terms` / `provoke` / `bulk` /
`idempotent` / `s1`+`s2`, all green. The rule is written by the sweep rather
than seeded: the manifest rule is slug-only, and the cross-kind read under test
is a TITLE token (`{default_title} {term:mc_topic}`, no slug pattern, so the
slug derives).

- **§64a/b/c — THE ticket.** Subject `item-solo-a`, `{Harbor}` assigned by hand.
  With no term rule the title reads `MC Item Solo A Harbor`; with the fixture's
  `ancestors`/`all` hierarchy rule live the SAME provocation yields `MC Item Solo
  A Coastal`, because Harbor's ancestors land in the term pass and `{term:TAX}`
  returns the first term by NAME. Both titles are plausible, which is why this
  needs a sweep rather than a harness — the wrong one looks like a working rule.
- **§64d — the derived slug** follows the computed title
  (`mc-item-solo-a-coastal`), not the row's stored `post_name`.
- **§64e/f/g — provocation independence.** Editor-shaped save, `acf/save_post`
  and a bare term write all produce the same title. The format pass has no
  trigger of its own; it rides the term dispatcher's queue.
- **§64h — bulk apply** runs the format pass per post rather than looping the
  handler's rules. Deliberately the format pass ALONE — the title/slug button
  reconciles titles, the term list's own button reconciles terms — so the
  subject is armed with its term state already settled. Bulk legitimately
  reaches every `mc_item`, so the step snapshots and repairs the others.
- **§64i/j/k — idempotence.** A second drain over an unchanged post leaves the
  title alone, and `_bws_raw_title` still holds the BASE title rather than the
  applied one. That is the compounding failure mode (`…Coastal Coastal`) the
  #64 change of what gets recorded exists to prevent.
- **§64l/m — the shutdown drain**, split across two evals: `s1` writes terms and
  returns without draining, `s2` finds the title and slug rewritten. Nothing else
  runs the format pass on a write involving no explicit drain.

**RESTORE GOTCHA (in addition to §7's).** This sweep renames `post_name`, which
is what `mc_pid()` looks a fixture post up by — so the subject's ID is cached in
the `mc64_subject_id` option on first resolution and every later step reads that.
`restore` renames the subject back from the manifest and drops the cache.

### §8 ordered term repeater (#57, 0.8.0) — results

Run 2026-08-14 on the docker testbed, seeded mc-rules fixture. This is the
config-collapse sweep the ticket demanded ("every show/hide combination
verified — no subfield silently dropped at save for any type"). It drives the
REAL `Validator` + `Sanitizer` + `Conditions` over `TermRulesConfig::section()`
rather than clicking, because that is the exact code path that decides which
subfields persist; the UI adds nothing the sanitizer does not.

**§8a per-type round-trip** ✅ One row of each of the four types, populated as
the UI would. Validation clean; **no submitted value dropped for any type**.
Surviving keys per row:

| type | keys that persisted |
|---|---|
| `propagation_rules` | type, enabled, taxonomy, post_types, hierarchical_post_type_note, post_status, conflict_handling, row_title |
| `time_based_rules` | type, enabled, post_types, post_status, filter_taxonomies, filter_terms, start_date, end_date, target_term_id, row_title |
| `hierarchical_rules` | type, enabled, taxonomy, hierarchical_taxonomy_note, post_types, post_status, inheritance_behavior, inheritance_depth, row_title |
| `hierarchical_level_restriction_rules` | type, enabled, taxonomy, hierarchical_taxonomy_note, post_types, post_status, restriction_mode, include_ancestors, row_title |

(The two `*_note` keys are `html` subfields; Wireframe stores them as `null`.
Harmless, and the same thing the old `expansion_behavior_help` did.)

**§8b type change discards the old type's values** ✅ A level-restriction row
flipped to `propagation_rules` keeps `enabled`/`taxonomy`/`post_types` and
drops exactly `restriction_mode` + `include_ancestors`. Silent by design —
which is why the `type` select's description says so.

**§8c required-only-where-visible** ✅ A date-window row saves with no
taxonomy (the field is gated away); a propagation row without one is rejected;
a hierarchical row needs no start/end date. An **untyped** row is *rejected*
(`"The Type is required"`) rather than silently dropped at fan-out.

**§8d defaults + vocabulary** ✅ Untouched subfields land their declared
defaults (`one_per_level`, `false`, `enabled=true`). A row naming a
not-yet-migrated type (`related_rules`) is refused by the select's validator —
the live types cannot be reached from this repeater.

**§8e persistence + handler visibility** ✅ `sync_kind_lists()` writes
`term_rules` with only the four migrated types (live types excluded), is a
no-op on the second load, and does **not** clobber a hand-authored reorder
(level-restriction dragged to the front survived the next load). A repeater
edit disabling the hierarchical rule took its handler from 1 enabled rule to 0;
emptying the repeater took all four migrated types to 0 while `related` (2),
`related_post_terms` (2) and `title_slug` (1) were untouched. A CLI
`save_rule()` behind the repeater's back was picked up on the next sync.

**§8f row-title backfill** ✅ Fixture-seeded rules start untitled; one admin
load titles all six and writes the same titles into the type-keyed arrays, so
the authored list stays trusted. Second load is a no-op.

**§8g the un-migrated sections still save** ✅ `related_rules`,
`related_post_terms_rules` and `title_slug_rules` each validate clean and
round-trip 2/2/1 rows through their own sections, and the fan-out touches no
migrated array when they save. `snapshot_claim_override_labels` unchanged.

**§8h #32 / #16 behaviour** ✅ See the §1 and §2 rule-config rows above for the
new schema. `include_ancestors` verified additive in all three modes (deepest:
`Region,East,Coastal,Harbor`; shallowest with a deep-only input: same chain;
one_per_level: kept term + lineage). All five `inheritance_behavior` outcomes
round-trip through `resolve_behavior()`/`behavior_key()`, every legacy pair
still resolves to its old mechanism, and an unknown outcome is rejected by
`validate_rule_internal()`.

### §9 ordered format repeater (#59, 0.8.0) — results

Run 2026-08-27 on the docker testbed, seeded mc-rules fixture. #59 moves the
title/slug config into a `format_rules` repeater; the stored rule shape is
untouched, so the sweep's job is to prove *both* halves of that claim — the
existing rule survives, and a rule authored the new way still fires.

**§9a stored-rule survival** (`sweep-59-roundtrip.php`) ✅ The admin-load
sequence (`sync_kind_lists()` → `repair_stored_rules()`) leaves `format_rules`
carrying the seeded rule with its `type`, a backfilled `row_title`
(`MC item slug (MC Items)`) and every stored key intact. What the handler reads
is byte-identical apart from `row_title`. Every key `TitleSlugHandler` reads
survives Wireframe's real `RepeaterField::sanitize` against the live
`FormatRulesConfig` subfields, and the fan-out reproduces `title_slug_rules`
row-for-row. Both copies were repaired in ONE `update_option`.

**§9b validator + sanitizer over the whole page** ✅ A fully populated
title/slug row validates clean against the assembled page config; the clean row
carries all eleven declared subfields. An **untyped** row is *rejected*
(`"The Type is required"`, plus name and post type) rather than silently
gutted — the same answer §8c got on the term list.

**§9c authored order decides the winner** (`sweep-59-behaviour.php`) ✅ Two
rules on the SAME post type, pushed through the full save path (sanitize →
`snapshot_format_rule_labels` → `fan_out_rule_lists`). Stored order, projected
order and read-back order all agree; the first matching rule wins
(`rule_matches()` since #64, `find_matching_rule()` when this ran);
**swapping the two rows swaps which rule applies**. A disabled top row still
stores and drops out of the enabled set. This is the assertion the post-type
field's new description promises, and the first time authored order in a
repeater has been shown to change behaviour anywhere in the plugin.

**§9d token engine unchanged** ✅ Resolved through the handler against
`mc-item-alpha`, compared to expectations computed independently from WP:
`{meta:mc_event_date}` raw in title context and `sanitize_title()`d in slug
context; `{date_year:}` = the meta date's year; `{term:mc_topic}` first name /
first slug; `{terms:mc_topic}` comma-joined names / hyphen-joined slugs;
`{pub_year}`/`{pub_month}`/`{pub_day}` site-LOCAL (month as name in title,
number in slug); `{default_slug}` from the computed title.

**NON-MUTATING, unlike §7.** The behaviour sweep saves no post, so no
`post_name` is rewritten and the §7 restore gotcha does not apply. The settings
option is snapshotted up front and restored in a `finally`, and the sweep
asserts the restore.

### §10 collision advisory (#65, 0.8.0) — results

Run 2026-08-28 on the docker testbed, seeded mc-rules fixture.
`sweep-65-collisions.php`, 35 assertions across `seeded` / `config` /
`surfaces`, all green. The static half is H14
(`tests/verify-collision-detector.php`, 81 assertions, negative-tested against
24 mutations).

**§10a the seeded set is a torture set, and the expected finding list is
spelled out in full** ✅ Eight collisions over the ten-row term list, pinned by
list POSITION rather than counted — position is what the warning quotes and
what the author reorders, so a detector that agreed on the pairs and disagreed
on the numbering would still be wrong. The list also proves the detector reads
through `get_authored_kind_rules()` → `authored_kind_list()`: after a
`mc_restore()` the stored kind list is rebuilt in `KIND_TYPES` order, and the
positions asserted are that order.

The eight, and why each is real: propagation × ACF-reference and ACF-reference ×
{hierarchy, level restriction} all share `mc_topic` (the ACF-reference rows are
`holder_role=source`, so their DEPENDENT end is unconstrained and overlaps
everything); the date window on `topic-featured` contends with both `related`
rules, which target the same term; the two `related` rules contend with each
other; `time_based[1]` × `[2]` is #69; hierarchy × level restriction is #51. The
one seeded format rule collides with nothing.

**§10b the two ready-made cases, NAMED** ✅ #51 is reported as
`ancestors_stripped`, not as a generic collision: *"adds ancestor terms in MC
Topics"* / *"does not keep them"*, plus the order sentence. #69 is reported as
`shared_term_cancels` and names the shared **term** (`MC Topics: Archived`),
which is the whole reason the two term-pairing types key on `target_term_id` —
neither has a `taxonomy` subfield to read, and keying on the term's taxonomy
would warn on every pair of date rules in a taxonomy.

**§10c the #39 configuration, and both clears** ✅ Propagation + level
restriction on one taxonomy with overlapping post types warns, naming both rules
and the shared post types. Moving one to a disjoint post type clears it; so does
moving one to a disjoint taxonomy. Either conjunct being disjoint makes the
rules independent, which is the predicate stated as behaviour.

**§10d contention, not currency** ✅ Sliding a date rule's window — including
out of every other rule's window — does NOT clear its warning. Re-targeting it
does, and so does disabling it. A collision is about whether two rules can
contend, not about whether both happen to be active today.

**§10e the passive surface** ✅ The REAL `bws-meta-conductor/settings_saved`
action recomputes and persists findings for both kinds, and the section the tab
renders on the next load leads with a `warning` notice built from them, the
re-check button below it. Nothing static can prove that hook is wired.

**§10f the on-demand surface** ✅ The REAL Wireframe filter
`bws-meta-conductor/action/settings/term_rules_collision_check/run` answers over
IN-FLIGHT rows — two unsaved date rules on one term produce *"1 collision
found."* while storage still holds the seeded eight — and answering it
**persists nothing**, which is the assertion that matters: an unsaved row must
never end up in the notice describing the saved rule set. Unsaved rows carry no
`row_title`, so the path bakes one through the save path's own snapshot rather
than falling back to a position number.

**§10g the notice is not sticky** ✅ Authoring a collision-free list and firing
the save hook empties the stored findings and removes the notice field from the
section.

**§10h NON-MUTATING.** The advisory writes no term, saves no post and provokes
no pass — asserted directly, and it is why the §7 rename trap does not apply
here even though the sweep restores the manifest rules.

## Cross-handler interaction scenarios (later phase, own snapshot each)

- level_restriction (p5) + hierarchical (p10) same taxonomy — prune-then-expand
  ordering.
- hierarchical + propagation — expanded ancestors propagate to child sections.
- related + time_based sharing `Featured` target — both add/remove same term.

## Cross-blueprint traps

- **`get_posts( name=..., post_status => 'any' )` cannot see non-published
  posts when logged out** (i.e. under WP-CLI). `name` sets `is_single`, which
  arms `WP_Query`'s post-query permission re-check; `'any'` leaves `$q_status`
  as the literal `['any']`, so the escape hatch misses and a draft is wiped
  after the DB has already returned it. Any blueprint with a draft/pending/
  private fixture hits this — the lookup reads as "missing", so an upsert
  re-inserts and duplicates accumulate one per run. Use `post_name__in` with
  explicit statuses (`mc-rules/lookup.php`). Worth grepping sibling blueprints:
  they only escape it by having no non-published fixtures.
- **Assert one-post-per-slug.** The duplication above was invisible for four
  runs because nothing checked. Cheap assertion, catches a whole bug class.
- **A composed-on blueprint's manifest is read LIVE, not at the pinned version.**
  `verify.php` `require`s core-structures' `manifest.php` directly and section B
  iterates every `posts` entry in it; `min_version => 4` is a floor, not a pin.
  So a fixture added upstream arrives in our negative controls unannounced —
  which is the point, but it means an upstream assumption change lands silently.
  core-structures v14 added `staff-gate-trashed` in status `trash` **on purpose**
  and listed `trash` in its own seeder's status lookup; ours did not follow, so
  the post read as missing and B3 reported a slug change that had never happened.
  The fix separates a **policy** from a **census**: `mc_fixture_post_statuses()`
  is what an MC-OWNED fixture may hold (no trash — a trashed one should be
  re-created, not revived by an upsert), and `mc_fixture_readable_statuses()`
  adds trash for reading posts another blueprint owns. Only the core resolver
  passes the wider set, so seeding is unchanged. B3 gained a **post_status**
  assertion alongside the slug one: with trash now findable, an MC rule that
  trashed a core post would otherwise leave B3 green, the slug being intact in
  the bin. Fault-injection verified.

## Harnesses

- **H14 — `tests/verify-collision-detector.php`** (static, no WP): the collision
  advisory's predicate (#65). Asserts BOTH directions — every pair that must
  warn and every near-miss that must not — because an advisory that fires on
  independent rules gets ignored and one that misses its own headline pair is
  worse than none. First assertion is a PARTITION check: the three
  target-resolution lists together cover every rule type storage knows, read by
  reflection, so a type added to storage and nowhere else fails here rather than
  being silently skipped by the detector.
- **H12 — `tests/verify-format-rules-config.php`** (static, no WP): the format
  repeater's shape (#59). H11's twin, and the dangerous direction inverts — with
  one rule type a gate that should not exist deletes its field outright rather
  than narrowing it, so the shared frame's ungatedness is asserted first. Runs
  Wireframe's real `Conditions::evaluate()` for the visible set, then checks
  separately that every key `TitleSlugHandler` reads is inside it.
- **H10 — `tests/verify-kind-lists.php`** (static, no WP): the 7-type-arrays →
  2-kind-lists fan-in (#56) — idempotent, lossless, every legacy rule shape
  round-trips, and the kind read path reproduces the type read path element for
  element (`id` included). This is Phase 4 Gate 1; it is what stands in for a
  behaviour sweep on the transform itself, since a sweep can only show that the
  rules it happens to exercise still fire.
- **H7 — `tests/verify-fixture-manifest.php`** (static, no WP): manifest
  coherence — dangling fixture slugs, parent-before-child ordering, unknown
  rule types/value tokens, and the isolation invariant (every rule's post-type
  scope pinned to MC-owned types). Run before any seed. Fault-injection
  verified: catches unpinned scope, unknown `{TERM:}` token, dangling post ref.
- **`tools/fixtures/mc-rules/verify.php`** (WP, post-seed): seeded surface
  assertions (section A) + core-structures negative controls (section B).
  Re-run section B after every behavior sweep, pre-restore.

## Negative-control assertions (every sweep, cheap)

1. `wp post term list <matrix-page-ids> department` unchanged vs manifest.
2. `staff` jane/tom `department` terms unchanged.
3. Matrix page `post_name` values unchanged (title_slug isolation).
4. `bws_dynamic_tags_settings` untouched.
