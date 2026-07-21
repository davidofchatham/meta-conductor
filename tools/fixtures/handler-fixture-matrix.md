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
