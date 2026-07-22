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
| Data | Posts on `mc_item` with 0 terms (clean slate per scenario). Tree ≥3 deep for `inheritance_depth: immediate` vs `all` distinction; sibling children under one parent for `expansion_behavior: smart` (skip-if-child-selected). |
| Rule config | `taxonomy: mc_topic`, `post_types: {mc_item: true}`, `hierarchy_direction` (child_to_parent / parent_to_child / both), `inheritance_depth` (immediate / all), `expansion_behavior` (smart / always / never). |
| Rules to seed | 1 baseline (child_to_parent + all + smart). Direction/depth variants toggled per-scenario in UI or via option rewrite. |
| Mutates | Same post's `mc_topic` terms; post meta `_bws_auto_terms`. |
| Scenarios | Assign Harbor (L4) → expect Coastal+East+Region auto-added; remove; promotion case (auto term kept manually). `_bws_auto_terms` asserted directly. |
| Shared reuse | None (needs hierarchical tax it may freely rewrite). |

## 2. hierarchical_level_restriction_rules — LevelRestrictionHandler

| Aspect | Need |
|---|---|
| Trigger | `set_object_terms` **p5** (pre-hierarchical) + `acf/save_post` p15. |
| Schema | Hierarchical taxonomy + **ACF taxonomy-type field** on `mc_topic` (the ACF branch discovers fields by `type==taxonomy && taxonomy==mc_topic`). |
| Data | Posts holding multiple same-level terms (one_per_level prune → keeps *last*), and mixed-depth sets (deepest_only / shallowest_only). Needs ≥2 depth levels on a post to observe pruning; tree gives 4. |
| Rule config | `taxonomy: mc_topic`, `restriction_mode` (one_per_level / deepest_only / shallowest_only), `include_ancestors` (only meaningful for deepest_only/one_per_level), `post_types: {mc_item: true}`. |
| Rules to seed | 1 (one_per_level, include_ancestors off). Mode variants per-scenario. |
| Mutates | Post's `mc_topic` terms (native) AND the ACF field value (write by field key). |
| Scenarios | Native path: assign East+West (both L2) → one survives. ACF path: set via `mc_topics` field → same prune lands in both channels. Interaction: p5 runs before hierarchical p10 — combined-rule scenario (restriction then expansion) is its own row. |
| Shared reuse | None. |

## 3. related_rules — RelatedHandler

| Aspect | Need |
|---|---|
| Trigger | `set_object_terms` p10 + `acf/save_post` p20. |
| Schema | Any taxonomy. Trigger terms + target term in `mc_topic` (Status root: target `Featured`). Cross-taxonomy removal check ⇒ also a trigger term in a *second* taxonomy on the same post (reuse `department` as trigger-read-only: rule still writes only `mc_topic` on `mc_item` — `department` needs registering on `mc_item`, additive schema, or use a second mc taxonomy `mc_flag` to stay fully owned — **decide at skeleton time; default `mc_flag` flat taxonomy, zero shared surface**). |
| Data | Posts with/without trigger terms; ACF taxonomy field carrying a trigger term (ACF branch matches fields whose taxonomy ∈ trigger taxonomies). |
| Rule config | `trigger_type` (term / taxonomy), `trigger_term_id: int[]` (OR), `trigger_taxonomy`, `target_term_id` (single int), `bidirectional`, `post_types: {mc_item: true}`. |
| Rules to seed | 2: term-trigger (Coastal ⇒ Featured, bidirectional on), taxonomy-trigger (`mc_flag` ⇒ Featured). |
| Mutates | Merge-adds target; bidirectional removal only when NO trigger remains anywhere on post (cross-tax check). |
| Scenarios | Add trigger → target appears; remove last trigger → target removed (bidirectional); remove one of two triggers → target stays; trigger via ACF field. |
| Shared reuse | Optional dept-as-trigger variant — deferred. |

## 4. related_post_terms_rules — RelatedPostTermsHandler (ACF reference)

| Aspect | Need |
|---|---|
| Trigger | Widest surface: `acf/save_post` p30, `save_post` p25, `set_object_terms` p15, `acf/update_value` (relationship/post_object) p5 sever capture, `before_delete_post`/`deleted_post`. |
| Schema | **ACF relationship field** on `mc_section` targeting `mc_item` (`mc_related_items`), + a **post_object** field variant (`mc_primary_item`), + optional `reverse_acf_field_name` partner on `mc_item` (`mc_parent_section`) to exercise reverse tier 1 (explicit) vs tier 3 (meta_query scan). Shared taxonomy both ends: `mc_topic` (registered on both CPTs ✓). |
| Data | Holder post (`mc_section`) + ≥2 related `mc_item`s; holder with terms (push), related with terms (pull); a second holder referencing the same related post (multi-holder union on pull-side severs). |
| Rule config | `acf_field_name: "mc_section:mc_related_items"` (stored split), `holder_role` (source=push / target=pull), `taxonomy: mc_topic`, `keep_in_sync` (replace vs add-only), `post_status`, optional `reverse_acf_field_name`. |
| Rules to seed | 2: push+keep_in_sync (relationship field), pull+add-only (post_object field). |
| Mutates | `wp_set_object_terms` on the dependent side; empty-replace on sever orphan. Declarative — no tracking meta. |
| Scenarios | Save holder → related inherit; edit relationship removing a post → sever (terms cleared if keep_in_sync + no other holder); delete holder → orphan cleanup; add-only never removes. |
| Shared reuse | Tempting to use `staff` as relationship target — writes would stay in `mc_topic`, invisible to GBDTE matrices. Deferred; own both ends first. |

## 5. propagation_rules — PropagationHandler

| Aspect | Need |
|---|---|
| Trigger | `save_post` p15, `set_object_terms` p10, `acf/save_post` p25. |
| Schema | **Hierarchical post type**: `mc_section` (public + hierarchical; empty `post_types` resolves to all hierarchical public types — which would include `page`! ⇒ rule MUST pin `post_types: {mc_section: true}`). Taxonomy `mc_topic`; ACF taxonomy field participates (native+ACF union read, ACF write by key). |
| Data | 3-level `mc_section` chain (grandparent → parent → child), statuses publish + one draft child (descendant statuses publish/draft/private included). Child holding an independent term (removal propagation must NOT strip it). |
| Rule config | `taxonomy: mc_topic`, `post_types: {mc_section: true}`, `conflict_handling` (merge / replace / skip). |
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
| Rule config | `start_date`/`end_date` (Y-m-d, required), `target_term_id` (single), `filter_taxonomies`, `filter_terms`, `post_types: {mc_item: true}`. |
| Rules to seed | 3 (in-range / expired / future). |
| Mutates | Merge-adds target in-range on save; removes when outside range; cron cleanup strips expired target from ALL matching posts (no ownership tracking — over-removal is current known behavior, assert it as-is). |
| Scenarios | Save in-range → term added; save post against expired rule holding the term → removed; future rule → no-op; cron fire (`wp cron event run bws_taxonomy_manager_cleanup` or `do_action` eval) → bulk strip. |
| Shared reuse | None. |

## 7. title_slug_rules — TitleSlugHandler

| Aspect | Need |
|---|---|
| Trigger | `wp_insert_post_data` filter p1 (non-meta patterns), `acf/save_post` p99 / `save_post` p99 (meta patterns, post-write), `redirect_post_location`. |
| Schema | Single `post_type` per rule, **one rule per post type** (first-match-wins). Own CPT so shared titles never mutate: `mc_item`. Token sources: post meta (ACF date field `mc_event_date`), terms (`{term:mc_topic}`). |
| Data | Posts with meta + terms feeding tokens; two posts colliding on generated slug (date_escalation ladder year→minute → `wp_unique_post_slug`). |
| Rule config | `name`, `post_type: mc_item`, `title_pattern` / `slug_pattern` (tokens: `{default_title}`, `{meta:x}`, `{date_year:x}`, `{pub_*}`, `{term:tax}`, `{terms:tax}`), `slug_mode` (prefix/suffix/replace), `date_escalation` + `date_field`. |
| Rules to seed | 1 (`mc_item`: slug_pattern with `{meta:...}` + `{term:mc_topic}`, escalation on). Pattern variants per-scenario (one-per-type limit blocks parallel rules). |
| Mutates | `post_title`/`post_name` only; meta `_bws_raw_title`/`_bws_applied_title`; option `bws_title_slug_rule_status`. No taxonomy writes. |
| Scenarios | Pre-write (non-meta pattern) vs deferred post-write (meta pattern); idempotent re-save; slug collision escalation. |
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
| title_slug | — | p99 (no-ACF only) | p99 | wp_insert_post_data p1 |

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

2. **Handler dedup is per-request.** `HierarchicalHandler::$processed[post:tax]`
   (and siblings) short-circuit a second `apply_rule` for the same post+taxonomy
   within one PHP request. Two user-edits in one `wp eval` → the second is a
   silent no-op. Each user-edit scenario needs its **own eval** (one WP-CLI call
   = one request = fresh dedup). A single-eval multi-edit sweep reports artifacts
   (e.g. "removal did nothing"), not real behavior.

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
- Note: apply is merge-add; removal fires ONLY when a trigger was actually
  removed in the change AND none remains (absence alone never removes —
  `apply_related_terms` needs the old/new tt_id diff, so a plain re-save of a
  post that lacks the trigger is a no-op, not a removal).

### §4 related_post_terms — results

Seeded rule: PUSH source, field `mc_section:mc_related_items` (relationship),
taxonomy `mc_topic`, `keep_in_sync=true`. `section-holder` (terms
Coastal+East) references `item-alpha`/`item-beta`; `section-holder2` is the
second-holder subject. This is the handler whose push clobbers plain mc_item
sweeps — tested here WITH itself isolated (other rule arrays emptied).

**CLI trigger note (handler KNOWN LIMIT, ~line 417):** a bare
`update_field($f,$v,$id)` fires `acf/update_value` (→ sever capture) but
neither `save_post` NOR `acf/save_post`, so the sever is captured but never
drained. Every relationship edit in a sweep must be followed by
`do_action('acf/save_post', $holder_id)` to flush. All §4 evals do this.

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

### §6 time_based — results

3 seeded rules (dates relative to seed day): [0] in-range `{TODAY-1}..{TODAY+7}`
→ Featured, filter `mc_topic`; [1] expired `{TODAY-30}..{TODAY-2}` → Archived,
no filter; [2] future `{TODAY+10}..{TODAY+20}` → Archived. Fires on
`save_post`/`publish_post` + the daily `bws_taxonomy_manager_cleanup` cron.
String Y-m-d comparison. Run against gamma/delta/solo subjects, time_based
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
- **§6e cron cleanup** ✅ `do_action('bws_taxonomy_manager_cleanup')` strips the
  expired rule's Archived target from ALL matching `mc_item` posts in one pass.
  This is the documented **over-removal**: no per-post ownership tracking, so it
  removes Archived from every matching post regardless of how it got there.
- Negative controls unchanged.

### §7 title_slug — results

Seeded rule: `mc_item`, `slug_pattern = {default_slug}-{date_year:mc_event_date}`,
mode replace, `date_escalation=true`, `date_field=mc_event_date`. It's a META
pattern (`date_year:`) so it runs POST-write (`acf/save_post` p99), not the
pre-write `wp_insert_post_data` path. Subjects: `item-alpha` (event 2030-03-15),
`item-slug-a`/`item-slug-b` (both titled "Slug Probe", both event 2030-04-01 —
a deliberate slug collision). Run title_slug isolated, `do_action('acf/save_post', id)`.

- **§7a slug build** ✅ save `item-alpha` → `mc-item-alpha-2030` (default_slug +
  meta year, replace mode).
- **§7b collision escalation** ✅ `item-slug-a` → `slug-probe-2030`; `item-slug-b`
  collides → escalates year→month → `slug-probe-2030-04` (month spliced adjacent
  to the year, not appended). Ladder confirmed.
- **§7c idempotent re-save** ✅ re-saving either subject twice more leaves the
  slug unchanged — no drift, no further escalation.
- Negative controls unchanged.

**RESTORE GOTCHA (this handler only): it renames `post_name`.** Re-seed's
`mc_fixture_find_post` addresses posts by `post_name`, so a renamed subject reads
as missing and the seeder INSERTS a duplicate. Before re-seeding after a
title_slug sweep you MUST first (a) empty the `title_slug_rules` array (so the
rename doesn't re-fire) and (b) `wp_update_post` the subjects' `post_name` back to
their manifest values. Then re-seed. Verified afterward: `mc_item` count = 8
(6 fixtures + 2 solo), all unique names, rule live, no duplicates.

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

## Harnesses

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
