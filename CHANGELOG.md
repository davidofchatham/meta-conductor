# Changelog

All notable changes to Meta Conductor are documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.8.0] — Unreleased

**The settings page is ordered lists of rules, not five tabs of rule types.** Everything that writes terms to a post — cascading from a parent, applying inside a date window, inheriting up a tree, restricting which depths may hold terms — is authored in a single repeater, in the order it runs, with a *Rule type* select at the top of each row. Restrict is no longer a tab of its own, because a level-restriction rule writes terms like every other rule in that list, and putting it there is the point of the model rather than a tidy-up.

Alongside it, the page now speaks the domain's own vocabulary. What a rule does about terms a post already has is a **claim** — *owning*, *contributing*, *deferring* — and every dropdown, row title and doc uses those words.

### Changed

- **Five tabs became three: *Auto-Set & Restrict*, *Format & Transform*, *General*** (**#57**, [ADR 0003](docs/adr/0003-ordered-rule-list-and-dispatcher.md)). The empty *Personalize* placeholder is gone — it described rule types that do not exist yet, and an empty tab reads as a broken feature rather than a promise.
- **One ordered term-rule repeater replaces four per-type sections.** Propagation, date window, hierarchy inheritance and level restriction are now rows in a single list you can reorder with the existing up/down controls. Their shared settings are genuinely shared — one *Enabled*, one *Taxonomy*, one *Limit to post types*, one *Limit to post statuses*, one *Claim on terms* — rather than four near-identical copies that had drifted apart in wording. The type-specific settings show and hide on the row's *Rule type*.

  Batch 1 (**#57**) moved the four rule types **not** running on any real site.

  **Changing a row's type discards that row's type-specific settings.** That is correct — a propagation rule's claim means nothing on a date-window rule — but it is silent, so the *Rule type* select says so. Shared settings survive the change.
- **The two rule types running on a real site joined the ordered list** (**#58**), completing the term-rule collapse: *Related term mapping* and *terms from a referenced post (ACF)* are rows like everything else, their per-type sections and config classes deleted. Because their rows exist in real data, two shapes were preserved deliberately: the ACF field select's combined `post_type:field_name` value round-trips **whole** (the handler splits it at read time; persisting the split would break the select's option keys), and a legacy-shaped stored row is repaired on admin load rather than rendered with config defaults — a related rule saved before the Wireframe admin may carry scalar term ids, which would render its selects empty and be persisted that way on the next save, silently disarming a live rule. The same repair sheds the old three-token title keys (`trigger_label`/`target_label`/`scope_label`); related rows now use the shared `row_title` snapshot, keeping the `→` title shape (`Topics: Coastal → Status: Featured (MC Items)`).

  Two shared-frame decisions came with the move. The shared *Taxonomy* select now also serves the ACF-reference rule (with a type-gated note carrying its "same taxonomy on both ends, copied by ID" semantics, and another for its source-side status gate). And *Limit to post types* is now **gated to the five types whose handlers read it** — the ACF-reference rule's post type is pinned by the relationship field it monitors, so offering the checkboxes there would store junk that reads as a working scope. Every type that reads the value sits inside the gate, so nothing loses its scope on save.
- **Title & slug rules became the ordered *format* list** (**#59**), and with it *no tab holds a per-type section any more*. The Format & Transform tab is now a `format_rules` repeater built exactly like the term one — a `type` select at the top of each row, the patterns and slug mode `conditions`-gated on it, drag to reorder, and a snapshot row title carrying the `[Disabled]` marker and the post-type scope (`MC item slug (MC Items)`) in place of the live `{name}` it used to interpolate.

  **Nothing about a stored title/slug rule changed** — same keys, same patterns, same slug mode, no migration and no re-save. The only thing the move adds to an existing rule is its row title, backfilled on the next admin load like the term rules' was.

  `title_slug` is the list's *only* member today, which is the point of doing it now rather than alongside `field_transformation`: the second format rule type becomes a row type added to an existing list instead of a tab restructured around a second section. The `type` select therefore keeps its "— Select rule type —" placeholder as the default even with one real option, so rows added in the meantime do not pre-claim a type.

  Two things the ordered list makes visible for the first time. `post_type` stays a **single select**, not the term list's checkboxes, because the handler is first-match-wins on one post type — a rule set is a lookup table, not a set of independently-scoped rules — and its description now says what that means: *if two rules name the same post type, the higher one wins and the lower one never runs*. Which rule that is, is now something you drag.

- **Every rule type in the list gained the publication-status gate** (the config half of **#23**). *Limit to post statuses* was previously offered only by the ACF-reference rule. Empty means every status, so no existing rule changes behaviour.
- **The `[Disabled]` prefix on a collapsed row now applies to every type in the list** (**#30**'s interim follow-up). It previously reached two rule types; hierarchy and level-restriction rows had no snapshot title at all and now read, for example, `MC Items: Inherit Topics: ancestors (all levels)` and `Restrict Topics to the deepest level, keeping ancestors`. A rule that reached storage without passing through the settings page — a seeded fixture, an import, WP-CLI — gets its title backfilled on the next admin load rather than showing a blank row, in the same pass as the hierarchy rewrite above and at most one write per rule set.
- **Hierarchy rules: one outcome selector instead of two mechanism controls** (**#16**). *Hierarchy direction* × *child expansion behavior* offered nine combinations for six outcomes: two different spellings of "ancestors only", and one pairing (`parent_to_child` + `never`) that did nothing at all while looking configured. They collapse into **What to apply automatically**, whose five options are the five useful outcomes — ancestors; descendants only when none were picked by hand; descendants always; and either of those two plus ancestors. *How far to follow the tree* (one level / all levels) is unchanged.

  Stored as `inheritance_behavior`, and a row saved before this release is handled on both paths: the handler still reads the old pair at runtime, and a one-time rewrite on admin load converts it to the outcome it was already behaving as. The rewrite is not belt-and-braces — the settings page reads the option raw, so an un-migrated row would render with the new selector's default and the next save would persist *that*, silently turning a parent-to-child rule into an ancestors one. The single pairing that applied nothing (`parent_to_child` + `never`) converts to a **disabled** rule rather than being given a behaviour it never had. Taken now because hierarchy rules run on the test site only — the schema window closes the moment that stops being true.
- **Level restriction: "Keep ancestor terms" has one meaning in every mode** (**#32**). It used to do two different things: in *deepest only* it added each kept term's ancestor chain, and in *one term per level* it suppressed a pruning pass. The second was **dead code** — that pass could never remove anything, because the mode had already reduced the set to one term per level and the pass only dropped terms whose level held more than one. So the flag did nothing at all in that mode while the description claimed otherwise.

  It now means one thing everywhere: **also keep the parent chain of whatever the rule kept**. *Shallowest only* gains behaviour it did not have, and *one term per level* can end up with more than one term on a level when a kept term's ancestors are added back — which is what asking for the lineage means, and the description says so. Free to change because level restriction is test-site only.

- **Claim vocabulary on the config surfaces.** The propagation rule's "Conflict handling" select and the General tab's per-taxonomy default now read as claims, each option leading with the domain word and a plain gloss:
  - `Owning: only this rule's terms are allowed here; anything else is removed`
  - `Contributing: add this rule's terms, leave everything else alone`
  - `Deferring: add only if the child has no terms in this taxonomy yet`

  The former "Replace existing terms" **hid the most consequential fact about that option**: it does not replace only the terms the rule manages, it replaces *everything* in the taxonomy, including terms placed by hand or by another rule. Owning's gloss now says so. Row titles follow suit — a propagation row reads `Copy Categories terms to children (owning)` instead of `(merge)`.
- **`skip` is not "contributing" — it is a fourth claim, *deferring*.** It writes only into an empty taxonomy, so on a post that already carries any term the rule's values are never applied at all. It was previously documented as contributing on the strength of "never removes", which is only half of what contributing means. No behavior change; the option now describes what the code has always done.
- **The words "mode" and "conflict" leave the author-visible strings.** Both remain as stored keys and code identifiers. "Mode" was retired from the domain because it read as the same axis as claim (it is not — *overlap* combines two effects within one rule); "conflict" in this plugin means two *rules* contending, which is not what a single rule's claim is about.
- **Storage untouched.** `conflict_handling` still stores `merge` | `replace` | `skip` (`replace` = owning, `merge` = contributing, `skip` = deferring). No migration, no re-save required.

### Added

- **Rules are now also stored as two ordered lists, one per effect kind** (**#56**, [ADR 0003](docs/adr/0003-ordered-rule-list-and-dispatcher.md)). `term_rules` holds the six term types and `format_rules` holds `title_slug`; each row carries its own `type`, and order is array position. This is the model the Phase 4 dispatcher will iterate — one full ordered pass per entity instead of seven handlers racing on hook priorities.

  It ships **expand-first**, so nothing you have authored changes: the seven type-keyed arrays are still written by the settings page and left byte-for-byte untouched, every handler and hook keeps working, and a rule authored before this release fires identically after it. What moved is where handlers *read* from — `get_enabled_rules()` now reads its kind list and filters on each row's `type`. The two reads are equal by construction, which is what let all seven handlers move without a single handler file changing.

  A **migration** writes the lists on admin load, ordered as the Auto-Set tab reads today: propagation, related post terms, time-based, related, hierarchical, level restriction. It only *adds* keys — nothing is moved or deleted — and it writes only when the stored lists differ from what the rule arrays imply, so repeat loads cost nothing. Because that boot path is admin- and REST-only while handlers read storage on front-end and cron requests too, the lists are **also derived at read time**, so those requests never depend on an admin visit having happened.
- **Harness H10 — `tests/verify-kind-lists.php`** (27 assertions, host PHP, no WP). `related` and `related_post_terms` run on a live site, so the fan-in had to be proven before it touched real data: it is idempotent, lossless (`fan_out(fan_in($s))` reproduces every type array byte-for-byte, legacy `related_post_terms` shapes included), and the kind read path reproduces the type read path element for element — `id` included, which `TitleSlugHandler` persists per-rule status against.
- **Row titles for General-tab claim overrides.** A collapsed override row previously interpolated the raw stored value (`category: replace`) — the one surface the vocabulary would not have reached. It now reads `Categories: owning`, assembled at save like the other four snapshot helpers. Existing rows show the new title after their next save.
- **One home for the claim vocabulary.** `ConfigHelpers::CLAIM_NAMES` builds both dropdowns (via the new `claim_field()`) and backs both row-title snapshots, so renaming a claim is a one-line change that cannot leave a surface stale. It was previously restated in three places.
- **Domain model: `jurisdiction`.** The values within one effect target that a rule governs — what makes a Temporal rule's *owning* (its configured terms; a manual "Sale" survives) differ from propagation `replace`'s *owning* (the whole taxonomy; "Sale" is destroyed). Both were "owning" with no way to state the difference. See [ADR 0004](docs/adr/0004-claim-axis-and-jurisdiction.md) and `CONTEXT.md`, which also record the law that falls out of it: *owning requires a statically enumerable jurisdiction* — which is why `merge`/`skip` never reconcile (**#34**), why a scalar effect target always collides, and why `HierarchicalHandler` needs its `_bws_auto_terms` provenance meta when no other rule does.
- **The ordered list is now what the settings page writes** (#57). The repeater saves `term_rules` directly, and a save-time projection writes each row back into the type-keyed array its handler still reads — so a rule authored in the list fires, and one deleted from it stops firing. The projection is also what makes **cross-type order authored rather than derived**: grouping by type, which is all the read-time fan-in can do, cannot reproduce a list where a level-restriction rule sits above a propagation rule. Nothing consumes that order yet (each handler still filters to its own type); the dispatcher is where it starts to matter.

  The admin-load sync therefore stopped being a plain recompute — on a list the page authors, a recompute *is* a clobber. It now keeps the stored list whenever it still agrees with the type-keyed arrays, and rebuilds only when something wrote behind the page's back: a WP-CLI `save_rule()`, a seeded fixture, an import. Rules of the types that have not moved yet are kept out of the authored list entirely, because the repeater renders every row in the key it is bound to and drops any subfield the config does not declare — rendering a live rule it has no subfields for would gut it on the next save.
- **Harness H11 — `tests/verify-term-rules-config.php`** (77 assertions, host PHP, no WP). The one harness here whose failure mode is data loss rather than a wrong answer. A `conditions`-hidden subfield is dropped at sanitize, so a wrong gate does not error, does not show up in the UI, and does not fail a lint — it just stops persisting a value. H11 runs Wireframe's **real** `Conditions::evaluate()` over the config and asserts the exact visible subfield set for each rule type, plus: every subfield id is unique (a duplicate would silently overwrite), shared subfields carry no gate, gated ones name only real types, claim comes through `claim_field()` rather than being re-authored inline, the `type` select stores the legacy type keys storage expects, the save-time projection round-trips, and the #16 legacy rewrite converts a row to the outcome it was already behaving as. It also cross-checks the vocabularies that now live in more than one file — the five hierarchy outcomes against `HierarchicalHandler::BEHAVIOR_MAP`, and every rule type against the row-title dispatch — since nothing else forces them to move together.
- **Harness H12 — `tests/verify-format-rules-config.php`** (60 assertions, host PHP, no WP). H11's twin for the format list, and not a formality: with one rule type the dangerous direction inverts. On the term list the classic bug is a gate that is too *narrow*; here it is a gate that exists at all where it should not — it would evaluate false for the only type there is and delete the field outright — so H12 pins the shared frame's ungatedness first, then computes the visible subfield set through Wireframe's real `Conditions::evaluate()`, then asserts separately that every key `TitleSlugHandler` reads is inside it. It also locks what #59 must not quietly change: the post-type select is a single select that still drops `attachment`, the placeholder is still the default, the admin-load repair adds `row_title` **and nothing else** to a stored row, and the save-time projection lands rows back in `title_slug_rules` in authored order.
- Harness coverage: H3 11 → 26 assertions (the claim vocabulary, plus the collapsed option/field builders), H4 10 → 22 (per-type row-title dispatch across all four moved types, including the legacy-row fallback), H10 27 → 45 (the authored-vs-derived list decision, the projection back onto the type-keyed arrays, that a row naming no known type forces a rebuild rather than being quietly shed from a list still declared trustworthy, and — #58 — the live types riding in the authored list), H11 56 → 77 (#58: the live types' visible-subfield sets, gates, row titles and legacy-shape repair), H10 45 → 47 (#59: the format kind is authored too, and seeds from `title_slug_rules` when it has no stored list). Three dynamic sweeps run the real save path on the testbed rather than reasoning about it: `sweep-58-roundtrip.php` and `sweep-59-roundtrip.php` push every stored fixture row through Wireframe's real `RepeaterField::sanitize` and assert no value a rule type reads is dropped or altered, and `sweep-59-behaviour.php` authors two title/slug rules on one post type through the full save path — sanitize, row-title snapshot, projection — and proves that **swapping their order swaps which one the handler applies**, then resolves `{meta:}` / `{term:}` / `{terms:}` / `{pub_*}` against a real post in both title and slug context. It saves no post, so it renames nothing, and restores the settings option in a `finally`.

### Removed

- **The `Settings` compat shell is gone (#55).** `includes/class-settings.php` existed only for the legacy handlers, which read rules through an injected settings object; the Phase 3 migration put every handler on `StorageFactory` and left the shell with no live caller. Deleting it takes the last copy of the blunt top-level `array_merge` in `update_settings()` with it — the clobber that could overwrite sibling rule arrays on a partial write. The path had been dead since 0.6.0; now the code is too. Handlers, and `TaxonomyManager::init_handlers()`, no longer pass or accept a settings argument.
  - `flatten_conflict_overrides()` was rehomed to `Storage\OptionRuleStorage` **before** the deletion, since it is the only coercion from the General tab's override rows back to the canonical `{taxonomy_slug: mode}` dict. It sits beside `normalize_rule_shape()` at the same adapter boundary and for the same reason — the rows are an artifact of the writer, not the meaning — and it is where both the config docblock and CLAUDE.md don't #4 already said the coercion lived. Its future consumer is the dispatcher, so putting it on the admin-config class would have pulled `Admin\Config` into runtime resolution.
  - **The per-taxonomy claim default is still read by nobody.** Nothing consults it when a rule omits its own claim, so such a rule continues to fall back to a hard-coded `merge`. The value saves, reads back, and flattens correctly — only the last leg is absent. Wiring it means settling rule-level-vs-taxonomy-level precedence, which is dispatcher work. New harness `tests/verify-claim-overrides.php` (H9) covers the adapter meanwhile, since no behaviour sweep can reach unreferenced code.
  - **Two orphans this deletion creates, left in place deliberately.** `RuleStorage::get_raw_settings()` (interface + implementation) had `Settings::get_settings()` as its only caller and now has none — it stays because it is the seam the claim-default wiring will read through, and churning an interface method out and back is worse than leaving it. `manual_processing_enabled` remains write-only: the General tab saves it, activation seeds it, and no code gates on it, because the bulk-apply buttons it is meant to hide don't exist yet (Phase 7).
- **Five config classes, superseded by the ordered list** (#57): `PropagationConfig`, `TimeBasedConfig`, `HierarchicalConfig`, `LevelRestrictionConfig` and the `PersonalizeConfig` placeholder. `TitleSlugConfig` followed in #59, leaving `ConfigHelpers` and the two ordered-list configs.
- **The duplicated option and field builders in `ConfigHelpers`** (**#38** cluster 1). Six copies of the same "registered objects → slug ⇒ label" loop — four for post types, two for taxonomies, plus a seventh living on `TimeBasedConfig` — became one `label_options()`, and the two near-identical checkbox field builders became one `gate_field()` base so the non-overridable-id contract is written once. Two of the deleted accessors (`post_type_options`, `hierarchical_post_type_options`) had no callers at all.
  - The **hierarchical-only post-type field** goes with them. One shared post-type gate across rule types means propagation is offered every public post type; its parent/child requirement is now stated as a note that appears on propagation rows, rather than enforced by withholding options. Enforcement never lived in the option list — a flat post type has no children, so a propagation rule scoped to one matches nothing.
- **`remove_conflicting_ancestors()` on the level-restriction handler.** Reachable only on the `one term per level` + ancestors-off path, where it could not remove anything (see #32 above). Deleted with the flag's second meaning.
- **Two unreachable methods on `TaxonomyManager`**: `ajax_get_dashboard_stats()` (its `wp_ajax_` registration was dropped in Phase 2c and no script requests the action) and `get_dashboard_stats()`, whose only caller it was. The dashboard-widget feature they served does not exist; `get_plugin_status()` covers the same ground for the diagnostics screen. The five rule-management AJAX bodies named alongside them in the issue were already removed in an earlier pass.

### Fixed

- **The `mc-rules` fixture's only cron coverage was a false green** (dev tooling only — not shipped). `verify.php` A7 asserted `wp_next_scheduled( 'bws_taxonomy_manager_cleanup' )` was truthy. The event is scheduled at plugin load and stays scheduled under `DISABLE_WP_CRON` — which disables only the page-load spawner, not the schedule — so the check passed on a site where `cleanup_expired_rules()` had never run.

  A7 is now behavioural, and drives its subject through the handler rather than planting it: isolate `time_based_rules`, synthesize a rule set holding only the seeded expired-Archived rule with its window slid in-range, save `item-solo-a` so the handler applies the term, assert that landed, append Featured by hand as a negative control, write the expiry back, fire the event, assert Archived went and Featured stayed. Handler-applied on purpose: a hand-planted term asserts only that cleanup strips a term the post happens to hold, and never exercises the apply path — driving the apply is what surfaced the collision below. The claim worth making is the contract itself, that cleanup removes what its own rule applied. Both deviations from the seeded rule set are asserted, so neither can rot silently.

  It snapshots and restores the rule option, and **clears** the subject's `mc_topic` terms rather than restoring them — A7 requires `item-solo-a` at seed state, which the manifest gives it by assigning it no terms. The restore is registered as a shutdown function so a fatal mid-probe cannot leave the site isolated. It remains the only mutating probe in that file, and is safe to re-run against a seeded site. The scheduled-event check is kept alongside, demoted to what it is: proof the event exists, not that anything ran it.

  A **known collision** surfaced while writing it, not fixed here and not a defect to fix: two `time_based` rules naming the same target term cancel each other, because an out-of-range rule strips that term whoever applied it. On save ([#69](https://github.com/davidofchatham/meta-conductor/issues/69), matrix §6g) the strip happens inside one `process_post()` loop, so from `{TODAY+10}` the fixture's future-dated rule can never land its term at all; on the daily cron (§6f) an expired rule keeps stripping what an active one applies. Both are the same property that gives the handler *retroactive ownership* — a rule recomputes from config and keeps no record of what it applied, so it corrects posts tagged before it existed. Provenance is rejected at present (ADR 0001, ADR 0002, CONTEXT.md), so the remedy is the authoring-time collision warning ADR 0001 specifies — [#65](https://github.com/davidofchatham/meta-conductor/issues/65), which gained criteria for it, because its detector keys on a `taxonomy` field that the two term-pairing rule types do not have. The fixture's colliding pair is now documented as deliberate, and is what forces A7 to synthesize a rule set rather than only slide dates.
- **The `mc-rules` test fixture seeded checkbox fields in a shape Wireframe rejects** (dev tooling only — not shipped). `post_types` and `filter_taxonomies` were written as `{slug: true}` maps; Wireframe's REST validator accepts only a flat list of slugs, and given a map it validates the map's *values*, so `true` arrived as `"1"` and the save failed with `"1" is not a valid option`. Four tabs were unsaveable on a seeded testbed. Handlers were never affected — they read both shapes — which is why it went unnoticed. The docblock that misdescribed the stored shape, and so caused it, is corrected.

## [0.7.0] — 2026-08-12

Term sync now follows ACF itself: a new write queue on `acf/update_value` catches every ACF write, including Admin Columns edits, bare `update_field()`, and REST, not just the ones that fire a save hook. Also repairs the Data Conversion tool, the bulk "process existing posts" button, and three propagation/severance gaps. Two behavior changes are flagged ⚠ below: imports no longer sync terms, and keep-in-sync rules can now clear a taxonomy from the dependent end.

### Added

- **AC-agnostic ACF write queue (#42).** New `Core\AcfWriteQueue` watches ACF's own `acf/update_value` filter — the one signal that fires for *every* ACF write — records the posts touched, and reapplies every handler once those writes have landed. This covers all the paths that fire no `save_post`-family hook and therefore silently skipped term sync: Admin Columns Pro v7 inline/bulk edits, bare programmatic `update_field()` (custom code, WP-CLI, cron), and REST writes to an ACF field. The 0.6.0 AC-only fallback (#37) is retained but reduced to a single `flush_post()` call, so inline-edit responses stay accurate while all apply logic lives in one module. Ordinary editor/REST post saves are unaffected — the queue *claims* those posts above every handler priority, so they run through the existing path exactly as before.
  - New filter `meta_conductor_acf_reapply_enabled` (bool, post ID) — force the behavior on or off per site. The gate sits on the apply step every flush path funnels through, so returning `false` disables reapply everywhere including the Admin Columns inline-edit path. The filter is always handed a real post ID, never one of ACF's `options` / `user_N` / `term_N` pseudo-targets.
  - **The conversion tool suppresses reapply for its own writes.** It writes target fields with `update_field()`, so without this every converted post would be reapplied at shutdown or at the bounded flush — a second wave of rule processing on top of the heaviest run the plugin does. Suppression is scoped to the conversion call, not the request. Reconcile afterwards with "Apply to Existing Posts", as with imports.
  - A bounded mid-request flush past a fixed cap (100 pending posts) keeps a long single-process run writing progressively instead of deferring everything to shutdown. The cap is deliberately NOT filterable yet: no site has needed to tune it, and adding a filter later is non-breaking whereas removing a published one is not.
  - Regression guard `tests/verify-acf-write-queue.php` (H8) pins the four properties that make the mechanism correct and that a refactor could silently break: claim priority above the latest handler, the import gate, the positive-integer post-ID target gate, and the bounded flush skipping the post currently mid-write.

### Changed

- **⚠️ Imports no longer sync terms (#42).** The write queue stands down entirely while `WP_IMPORTING` is set (WP core importers and WP All Import both set it), so an import of thousands of posts does not trigger thousands of rule recomputes. **Reconcile afterwards with "Apply to Existing Posts".** Override with `meta_conductor_acf_reapply_enabled`.
- **⚠️ Behavior change for live `related_post_terms` rules with `keep_in_sync`.** With #43 fixed, a dependent that loses its **last** source now reaches the existing true-orphan path, which performs an *empty replace* on that taxonomy — clearing manually assigned terms alongside the inherited ones. This is the pre-existing keep-in-sync contract (the same already happened on a holder-end sever), but it is now reachable from the end editors actually touch. **If you run a live rule of this type, audit that taxonomy before upgrading.**
- **`UnifiedHandlerBase` split into traits (agent-friendliness; no behavior change).** The shared term + ACF primitives moved out of the 1067-line base into two composed traits: `TermOperations` (`apply_terms_to_post`/`remove_terms_from_post`/`post_has_terms`/`terms_fingerprint`) and `AcfBridge` (`get_acf_taxonomy_value`/`set_acf_taxonomy_value`/`get_acf_taxonomy_fields`). Both are `use`d on the base itself, so every handler still resolves them via `$this->…` unchanged — pure structural refactor. Base drops to ~800 lines. H2 autoload harness now `trait_exists`-checks both.
- **Phase 2b rename sweep (internal identifiers).** Completes the branding pass begun in 2c:
  - Text domain unified to `meta-conductor` across all `__()`/`_e()` calls (510 args, 29 files). Cosmetic (private plugin, no `.po` files) but removes the mixed `bws-meta-manager`/`bws-taxonomy-manager` domains.
  - Plugin constants renamed `BWS_META_MANAGER_*` → `META_CONDUCTOR_*` (`VERSION`/`PLUGIN_DIR`/`PLUGIN_URL`). The 3 dead `BWS_TAX_MANAGER_*` defines (zero references) were dropped. No back-compat aliases — no external consumer references them.
  - Nonce action `bws_taxonomy_manager_nonce` → `bws_meta_conductor_nonce` (21 sites).
  - Core hooks (rule-engine, condition/action, storage-factory, unified-base) renamed `bws_meta_manager_*` → `bws_meta_conductor_*`, including the dynamic `before/after_process_{type}` and `clear_{type}_cache` actions and paired transient key. No aliases (no external listeners). Conversion-subsystem hooks (cron/AJAX/transients) and the JS localized object deferred to Phase 7.
  - Fixed a latent stale admin-page slug (`bws-meta-manager` → `meta-conductor`) in the conversion tab-URL builder.

### Removed

- **Dead `validate_rule()` handler overrides + orphaned helpers (#40).** The public `validate_rule()` overrides on the time-based, propagation, level-restriction, and title-slug handlers had **zero call sites** (whole-tree grep confirmed) — rule saving goes through Wireframe → storage normalization, never a handler `validate_rule`. Removed the 4 overrides plus their sole-caller-orphaned `sanitize_rule_data` (×3) and `is_valid_date` (×1). Live paths (`validate_rule_internal`, the base compat wrapper) untouched. Net −254/+33 lines across the handlers; H1–H6 green.

### Migrated

- **Core log table renamed** `{prefix}bws_meta_manager_log` → `{prefix}bws_meta_conductor_log` via an idempotent `RENAME TABLE` in the `admin_init` version-check seam (fires on the first admin request after upgrading; guarded so it runs at most once and preserves existing rows). Uninstall now drops all three historical table names.

### Fixed

- **ACF Reference: a dependent dropping its own relationship now severs (#43).** For a push rule with `keep_in_sync`, clearing the reverse relationship field on the *dependent* post — the natural way to say "this item no longer belongs to that group" — left the inherited term in place forever. The sever capture recognised only two shapes (a push rule's forward field edited on the holder, a pull rule's reverse field edited on an eligible source); the third, a push rule's reverse field edited on an eligible dependent, was missing. Both reverse-resolution styles are covered — an explicitly configured `reverse_acf_field_name` and an ACF native bidirectional partner. Clearing the relationship from either end now does the same thing. Rules with neither a configured reverse field nor a native bidi partner still have no reverse field name to match, so their edit-sever remains covered at delete time only — unchanged, and documented at the enforcing code.
  - Side effect: because the #42 flush routes through the handler's normal ACF-save entry point, it also drains pending severs — closing the previously documented gap where a bare `update_field()` captured a sever that nothing ever processed.
  - The `$severed` bookkeeping's key contract changed from "the source that severed" to "the post whose save drains the entry"; a dependent-end sever keys under itself, since that is the only post saved in the request.
- **Fixture blueprint `mc-rules` v5.** Adds the reverse-field surface the matrix already called for: an explicit reverse field (`mc_parent_section`, tier 1) on the existing `related_post_terms` rule, plus a self-contained ACF native-bidirectional pair (`mc_bidi_items` ⇄ `mc_bidi_sections` on new `section-bidi`/`item-bidi`, taxonomy `mc_flag`, tier 2). Two rules are required because an explicit reverse short-circuits the bidi tier — one rule can only prove one tier.

- **Value-independent ACF field discovery in level-restriction + related handlers (#41).** Both handlers discovered ACF taxonomy fields via `get_field_objects($post_id)`, which returns `FALSE` for a post with no saved ACF meta — so an attached-but-empty ACF taxonomy field was never found and the ACF path silently no-opped. Rewired to the shared `get_acf_taxonomy_fields()` (resolves fields from field-group *location* rules, value-independent; same fix landed for propagation in `f9f4926`). Level-restriction now writes by field **key** so a first write registers the ACF reference row. Verified on the local testbed: a previously-empty subject's ACF taxonomy field is discovered and pruned to `one_per_level`, native + ACF channels agree.

- **Data Conversion tool was completely unusable — every selector stayed empty and no conversion could run.** The eight `wp_ajax_bws_meta_manager_conversion_*` endpoints in `TaxonomyManager` were divergent local copies that shadowed the canonical, correctly-shaped handlers on `ConversionUi`, each emitting a payload the client couldn't consume:
  - **Fields** (`get_fields`) returned each group's `fields` as a key-preserved PHP array → JSON object `{}`, so `conversion-admin.js`'s `group.fields.forEach` silently no-op'd ("Total fields added: 0"). It also ignored `content_type`/`post_types`/`taxonomies`/`field_type_filter`, returning all groups unfiltered.
  - **Taxonomies / terms / options** were wrapped (`{taxonomies:…}`, `{terms:…}`, `{options:…}` with the wrong inner key) where the client expected bare arrays → Source/Target Taxonomy dropdowns rendered blank, term and option mapping broke.
  - **Estimate-size** and **process-chunk** were unimplemented stubs returning hardcoded zeros (size dialog showed `undefined`; chunked runs did nothing yet reported complete).
  - **Process** and **preview** read a nested `$_POST['config']` array the client never sends — the form posts a flat `FormData` — so conversions ran on empty config.

  All eight endpoints now delegate to `ConversionUi` via a lazily-built instance on `ConversionManager` (`get_conversion_ui()`), which owns the canonical response shapes and reads the flat POST through `sanitize_conversion_config()`. The five `ConversionUi` handlers that lacked auth checks (`handle_get_fields`/`get_options`/`get_taxonomies`/`conversion`/`preview` — chunk/estimate/terms were already guarded) gained the nonce + `manage_options` check the old stubs carried, so rerouting is not a security regression.
- **Bulk "process existing posts" now works for the hook-driven handlers (was an inert, over-reporting button).** `process_existing_posts()` drove bulk re-apply through `process_post()`, which the hook-driven handlers (related, propagation, level-restriction) override as a no-op — so bulk did nothing yet reported every scanned post as processed. The base now routes bulk through a new `apply_to_post(int, array): bool` primitive that each hook-driven handler overrides from its own per-post logic (level-restriction wires the long-kept-ready `apply_level_restrictions()`; related re-uses its add-only `process_related_terms`; propagation runs its down/up walk; time-based its date-range apply). `apply_to_post` returns whether the post's terms *actually changed* (measured by a before/after taxonomy fingerprint — target-term taxonomy for related/time-based, self∪descendants for propagation), and the batch message now reports changed-of-scanned per batch, so the count is honest instead of "Processed N of N" while writing nothing. (#31)
- **Propagation: removing a term from a parent now sticks on its descendants.** When a parent carried an ACF taxonomy mirror field (the normal case), a down-removal was undone within the same request: the removal pass stripped the term from every descendant, then the add pass re-read the parent as native∪ACF and — because the parent's ACF mirror still held the pre-removal value — re-pushed the just-removed term back onto them. The add pass now excludes the same-request removals from its source, so removals persist. Method-independent (`wp_set_object_terms([])` and `update_field([])` both hit it). (#45)
- **Propagation: `wp_remove_object_terms()` on a parent now propagates the removal down.** That function fires `deleted_term_relationships`, not `set_object_terms`, so the removal-propagation path was never reached and descendants silently kept the term (sibling gap to #45, which fixed the `set_object_terms` path). A new `deleted_term_relationships` hook runs the removal walk under the same reentrancy guard. On the plain `wp_set_object_terms` path — where WordPress removes dropped terms via an internal `wp_remove_object_terms` and both hooks would see the same removal — the delete hook records the handled term-taxonomy IDs so the set hook does not walk them a second time. (#47)

## [0.6.1] — 2026-07-17

### Fixed

- **Admin settings page now loads on symlinked installs.** When the plugin is symlinked into `wp-content/plugins` (a common local-dev layout), PHP's `realpath()` resolves the Wireframe package to a path outside `WP_PLUGIN_DIR`, so Wireframe's asset-URL derivation failed and emitted a broken script/style base (e.g. `https://site.testindex.js`) — the admin UI never rendered. The Wireframe bootstrap now passes an explicit `assets_url` derived from `plugins_url()` (which honors the symlink), so the React bundle and stylesheet load correctly. Non-symlinked installs are unaffected.

## [0.6.0] — 2026-07-10

Phase 3 complete: the last three legacy handlers migrate to the unified base, the legacy base class and the redundant save loop are removed, and each migrated rule type gets a config/label pass. Also fixes the Admin Columns integration for Admin Columns Pro v7.

### Changed

- **Level Restriction, Propagation, and Date Window (time-based) rules migrated to the unified handler base.** These were the last three rule types still on the old handler base. Behavior is unchanged for existing rules, with the fixes and polish below.
- **"Limit to post types" is now multi-select on every rule type.** Propagation, Level Restriction, and Date Window rules previously took a single post type; they now use the shared post-type checkboxes (empty = all). Propagation offers only hierarchical post types (it needs a parent/child relationship). *Existing single-post-type rules of these three types need a one-time re-save to pick up the new field.*
- **Clearer collapsed row titles:**
  - Propagation: e.g. "Pages: Copy Breakers terms to children (replace)" — post-type scope shown only when restricted.
  - Date Window: e.g. "2026-05-26–2026-05-27: Apply Shakers: Grandchild ii to posts with Breakers: Term A" — date window first, then the target term, scope, and any post filter.
- **Level Restriction "Keep ancestor terms"** (was "Include ancestors") now has an accurate description of what it does in each mode and only appears in the modes where it has an effect.

### Fixed

- **New child posts now inherit their parent's terms on their own save** (propagation), honoring the rule's conflict handling (merge / replace / skip). Previously a new child did not receive inherited terms until the parent was re-saved.
- **Propagation no longer writes/logs a redundant term update** when a post already has the terms — both directions (a child inheriting from its parent, and a parent cascading to its children). A no-op parent save no longer re-writes terms to every descendant.
- **Propagation now populates a child's ACF taxonomy field even when the child had no ACF value yet.** Field discovery used `get_field_objects()`, which returns nothing for a post with no saved ACF meta, so a never-populated child could never receive its first ACF write (native terms applied, ACF field left empty). Fields are now resolved from ACF location rules (value-independent), and the first write uses the field key so ACF registers the field reference correctly. The child-inheriting and parent-cascading paths, the ACF-only source read, and the term-removal path all use the same discovery.
- **Prevented a latent crash**: propagation and level-restriction rules that act on an ACF taxonomy field would have hit an undefined-method error after the base migration; the ACF read/write helpers are now on the unified base. (Only reachable with an ACF taxonomy field configured; native-taxonomy rules were unaffected.)
- **Date Window daily cleanup no longer runs twice** — the scheduled expired-term cleanup was registered both directly by the handler and via a redundant relay; the relay is removed.
- **Date Window "Filter by taxonomies" now works** — the taxonomy filter read the checkbox field in the wrong shape, so a rule with a taxonomy filter set never matched any post. Filtering by specific terms was unaffected.
- **Post-status gating hardened** — the shared post-status filter didn't normalize its checkbox value, so a status gate could be silently bypassed. (No rule type gates on status via this path yet; fixed proactively.)
- **Post-type gating normalized consistently** — the shared post-type gate hand-rolled its checkbox extraction while the post-status gate used the canonical helper; both now go through the same extractor, removing a drift risk. No behavior change for correctly-saved rules.
- **Level Restriction ACF saves no longer re-enter the handler** — saving a post whose ACF taxonomy field is under a level-restriction rule wrote terms without setting the reentrancy guard, so the handler ran a second (redundant) restriction pass in the same request. The guard is now symmetric with the native-terms path. (Idempotent before; the fix removes the wasted work and an edge-case extra write.)
- **Bulk "process existing posts" reads the correct post-type key** — the bulk tool still read the old scalar `source_filters['post_type']` and so would have scanned only the `post` type for the migrated rule types (which now store the plural `post_types` checkboxes). It now reads `post_types` (empty = all), falling back to the legacy key. (Not yet reachable via UI — see [#31](https://github.com/davidofchatham/meta-conductor/issues/31).)
- **Admin Columns Pro v7 edits reapply rules again** — the Admin Columns integration used pre-v7 hook names and signatures, so on AC/ACP v7+ its hooks never fired and inline/quick/bulk-edit changes stopped driving rule reapply. AC v7 writes ACF fields via `update_field()`, which fires `acf/update_value` only — never the save hooks the handlers listen on. A new `ac/editing/saved` fallback now reapplies every ACF-listening handler's sync (ACF reference, Related Term Mapping, Level Restriction, Propagation, Title & Slug) after an AC v7 edit; native taxonomy-column edits were already covered. ([#37](https://github.com/davidofchatham/meta-conductor/issues/37))
- **Admin Columns Pro is detected correctly on v7** — the "Admin Columns Pro" diagnostics status checked for a class that no longer exists in v7, so it reported "Not Active" even when ACP was active. It now checks the `ACP_VERSION` constant.

### Removed

- **Legacy `BWS_Handler_Base` class deleted** — all seven rule handlers now share `UnifiedHandlerBase`.
- **Redundant global save loop removed** — each handler registers its own hooks; the Date Window rule no longer runs twice per save.
- **Dead pre-v7 Admin Columns integration deleted** — the old `class-admin-columns-integration.php` (legacy hook names, an unreachable scheduled reapply event) is replaced by the `ac/editing/saved` fallback above.

### Known interactions (filed, not blocking)

- Propagation + hierarchical rules on the same taxonomy compose: children can gain one extra expansion level ([#35](https://github.com/davidofchatham/meta-conductor/issues/35)).
- Propagation has no inherited-vs-manual term tracking; a mode switch can strand a previously inherited term ([#34](https://github.com/davidofchatham/meta-conductor/issues/34)).
- Bulk "process existing posts" is inert for hook-driven handlers and has no UI trigger yet; systemic fix deferred to the Migration/Preview tool ([#31](https://github.com/davidofchatham/meta-conductor/issues/31)).
- Propagation treats a post's native terms and its ACF taxonomy field as one merged set and mirrors that set into **both** stores on the children. With the ACF field's Load/Save Terms ON (the default) the two stores are already identical, so this is invisible. With Load/Save Terms OFF — where the native and ACF values are intentionally kept separate — propagation collapses that separation on the children (a parent's native-only term appears in the child's ACF field and vice-versa). Propagation is not channel-preserving by design; if a "keep native and ACF separate" model is needed, file an issue.
- ACF Reference (Related Post Terms) does not strip a synced term when the **dependent** end drops a bidirectional relationship (e.g. clearing the relationship on the event rather than the schedule). The term-removal sever is missed on both the editor and Admin Columns paths; adding a relationship still syncs correctly ([#43](https://github.com/davidofchatham/meta-conductor/issues/43)).
- The Admin Columns v7 reapply is Admin-Columns-coupled. An AC-agnostic version driven from `acf/update_value` (covering bare `update_field()` and REST writes) is tracked separately ([#42](https://github.com/davidofchatham/meta-conductor/issues/42)).

## [0.5.0] — 2026-06-30

### Added

- **Disabled rules are flagged in their collapsed row title** — ACF reference and Related Term Mapping rules now show a `[Disabled]` prefix on the row label when switched off, so a disabled rule is recognizable without expanding it. (The marker updates when you save the rule.)

### Changed

#### ACF reference rules ("From referenced post") reworked

- **Direction is now selectable.** Each rule's ACF field pins the "field holder" post type; a new **Authoritative end** option says which end owns the terms — *Field holder is the source* pushes the holder's terms out to the related posts, *Related posts are the source* pulls their terms onto the holder (the old behavior). Existing rules keep the pull behavior automatically; only newly added rules default to push.
- **Single taxonomy.** The separate Source/Target taxonomy selectors are collapsed into one **Taxonomy** field. Copying terms across two *different* taxonomies never actually worked (terms are copied by ID, and an ID belongs to one taxonomy), so the second selector was a footgun. Existing rules keep their source taxonomy.
- **"Bidirectional" → "Keep in sync."** Clearer name for the same idea: when on, copied terms are removed from the target once the source no longer has them; when off, terms are only added, never removed.
- **Source publication-status filter.** New **Limit to source statuses** option — only copy terms from source posts with the chosen statuses (e.g. published only). Empty = any status. Gates the *source*, not the target.
- **Optional reverse relationship field** for faster two-way lookups; auto-detects ACF native bidirectional fields, falling back to a query when none is configured.
- **Rule row titles** rebuilt: e.g. "Copy Sport Connectors terms to Team schedule on Published".
- **Conflict handling option removed** — "Keep in sync" now controls add-only vs replace. Existing rules map automatically (merge → off, replace → on).

### Heads-up (existing ACF reference rules)

- Migration is automatic and behavior-preserving — existing rules continue to pull onto the field holder, in their source taxonomy, with the same add/remove behavior. **Re-save a rule to refresh its row title** and to adopt the new single-taxonomy/direction wording. A rule that previously used the rare `skip` conflict mode is migrated to add-only; re-check those.

### Internal

- ACF reference handler migrated to the unified handler base (Phase 3). Sync is now **declarative and source-authoritative**: a post's terms in the synced taxonomy are recomputed from its current related posts on every relevant save, rather than tracked incrementally — safer under multiple rules and reorders.
- **Removed** the legacy `AcfIntegration` term-sync engine — a parallel reimplementation of several rule types on the old rule schema. Redundant for taxonomy fields that load/save terms to the post (ACF mirrors native ↔ field, so the handlers reading native terms already see everything). The migrated handlers are the sole writers; engine-off parity was verified on real data before removal. The `bws_mc_acf_sync_engine_enabled` filter is gone with it.

## [0.4.3] — 2026-06-22

### Fixed

- **Fatal error when ACF or Admin Columns integrations read handler rules.** `get_enabled_rules()` was `protected` on both handler base classes but called cross-class by the ACF and Admin Columns integrations, throwing `Call to protected method ... from scope ... AcfIntegration`. Now `public`.

## [0.4.2] — 2026-06-20

### Changed

- **All rule rows now start collapsed** on the settings page, matching the Related Term Mapping rows from 0.4.1. Applies to every rule type (hierarchical, propagation, time/date window, ACF reference, level restriction, title/slug, and the general taxonomy overrides). Click a row to expand it. Improves orientation when many rules are configured.

## [0.4.1] — 2026-06-19

### Added

#### Phase 3b — multi-trigger Related Term Mapping + UX fixes

- **Related Term Mapping rules now accept multiple trigger terms.** Previously limited to one trigger term per rule; now any number can be listed and the rule fires if the post has **any** of them (OR semantics). Previously, if you had already entered a second trigger term in the UI, it was stored but silently ignored at runtime — after this update it fires. Check existing rules and remove any stray second terms if that isn't the intended behavior. Row labels now display all trigger terms joined by ", ".
- **Term picker now sorted alphabetically** — trigger-term and target-term dropdowns list taxonomies in label order, terms in name order. Previously taxonomies appeared in registration order, which looked arbitrary on sites with many taxonomies.
- **Related rule rows start collapsed** — all rules load collapsed on the settings page. Click to expand. Improves orientation when many rules are configured.
- **Search re-enabled after first term selection** — the prior `max: 1` cap on the trigger-term field disabled the search input after one pick; removing the cap restores continuous search.

## [0.4.0] — 2026-06-19

> Combined release: the Phase 2a PSR-4 restructure (internal, no behavior change) plus the Phase 3a multi-post-type Related Term Mapping work. Still the unstable `0.x` line — no migration path guaranteed pre-1.0.

### Changed

#### Phase 2a — PSR-4 namespacing (internal; no user-visible or behavior change)

- **All `includes/` classes namespaced under `BWS\MetaConductor\`** and loaded via a new root `autoload.php` (kebab `class-{name}.php` map). The 12 manual `require_once` chains in the main file — and surviving ones in method bodies — were removed; classes now autoload on demand.
- **Class + file renames**: dropped the `BWS_` prefix, CamelCase with acronyms lowered (`Acf`/`Cli`/`Ui`) to satisfy the autoloader's no-consecutive-caps rule. Subnamespaces map to directories: `Core\`, `Handlers\`, `Storage\`, `Conversion\`, `Admin\`/`Admin\Config\`, `Integrations\`, and `Support\`.
- **`includes/abstracts/` and `includes/lib/` eliminated**: abstract bases co-located into `handlers/`/`storage/`; the reusable modules (`BatchProcessor`, `TermMigrator`, `FieldConverter`, `ValueMapper` + interfaces) moved to `includes/support/` as their own `Support\` namespace (renamed from `lib/` to avoid collision with the vendored `libs/`).
- **Global classes leading-backslash qualified** under the new namespaces (`\WP_Query`, `\DateTime`, `catch (\Exception`, `\WP_CLI::`, …).

### Added

- **`tests/` dev harnesses** (export-ignored from the release ZIP): `verify-autoload.php` proves all class FQNs resolve without booting WordPress; `lint.php` runs a `php -l` sweep plus static checks that no manual plugin-file `require` survives and no global class is left unqualified in code.

#### Phase 3a — multi-post-type Related Term Mapping

- **Related Term Mapping rules now apply across multiple post types.** The single post-type dropdown became a "Limit to post types" checkbox set — leave all unchecked to apply to every post type using the taxonomy. One rule can now cover a cross-post-type term mapping instead of one rule per post type. **Existing related rules must be re-saved** (the stored scalar `post_type` key is no longer read; pre-1.0, no migration — see the `0.x` note above).
- **`RelatedHandler` migrated off the legacy `BWS_Handler_Base` onto `UnifiedHandlerBase`** (Phase 3 step 1). Post-type gating now flows through the unified base's `should_process_post`, which reads the Wireframe checkbox shape natively.

### Added

- **`ConfigHelpers::post_types_field()`** — the canonical "Limit to post types" checkboxes subfield, shared by every rule type that scopes by post type. Single source of truth for the field id, label, and empty-means-all semantics.
- **Term-utility primitives on `UnifiedHandlerBase`** (`apply_terms_to_post`, `remove_terms_from_post`, `post_has_terms`, `debug_log`), ported verbatim from `BWS_Handler_Base` so handlers migrated off the legacy base inherit them.
- **Readable related-rule row titles** — collapsed rows now read "_Taxonomy_: _Trigger term_ → _Taxonomy_: _Target term_" (e.g. "Shakers: Parent 1 → Breakers: Grandchild ii") instead of a bare term ID; an "any term from taxonomy" trigger shows just the taxonomy name. Rules limited to specific post types get a suffix (e.g. "… (Posts, Pages)"); rules applying to all post types show none. Names are snapshot into the rule at save (via the `wp-wireframe/save/payload` filter); renaming a term shows the old name until the rule is re-saved.

### Changed

#### Hierarchical rules UX

- **Post-types field relabeled** "Post types (optional)" → "Limit to post types" with a clearer empty-means-all description, and **moved up** to sit directly under Taxonomy (scope before behavior). Now uses the shared `ConfigHelpers::post_types_field()`.

## [0.3.1] — 2026-06-19

> Post-`0.3.0` review pass. Addresses correctness findings from the Phase 2c code review (PR #17).

### Added

- **`BWS_Rule_Storage::get_raw_settings()`** — exposes the raw cached settings option (including non-rule global keys like `conflict_handling_overrides` and `manual_processing_enabled`) so callers avoid a second `get_option()` round-trip. The interface now defines 16 methods; any future storage backend must implement it.

### Changed

- **`save_rule()` return-value contract** — now returns `-1` on failure and the zero-based rule index on success (previously `0` meant *both* failure and the first-ever rule). **External callers must guard with `>= 0`, not `> 0` or `!== 0`.**

### Fixed

- **`save_rule()` reported the first-ever rule as a failure** — index 0 is a valid first rule, so `import_rules()` (and any `> 0` caller) flagged the first imported/duplicated rule as failed. Failure now returns `-1`. Input guard added: any `rule_id < -1` is rejected (`-1` is the sole "create new" sentinel) so a failure return can't be silently round-tripped as a create.
- **`import_rules()` duplicate detection used a fuzzy substring match** — `search_rules()` (`strpos`-based) flagged a rule named "Foo" as a duplicate of an existing "Food" and skipped it. Now an exact-name comparison.
- **`{pub_*}` date tokens used PHP's server timezone, not WordPress's** — `get_pub_part()` built a `DateTime` from `post_date` (stored in WP's configured tz) without binding a timezone, so `{pub_year}`/`{pub_hour}` etc. were wrong on hosts where PHP tz ≠ WP tz. Now uses `new DateTimeImmutable($post->post_date, wp_timezone())`. **Behavior change:** computed titles/slugs for posts published near a day/hour boundary may differ from prior output on affected hosts; re-saving a post recomputes against the corrected timezone.
- **`log_results()` re-read the settings option on every rule run** — bypassed the storage cache with a fresh `get_option()` per processed rule. Now memoized once per request per handler instance.
- **`BWS_Settings::get_settings()` issued a second `get_option()`** for the non-rule global keys after the storage layer had already cached the option. Now reads those keys from the storage cache via `get_raw_settings()`.

## [0.3.0] — 2026-06-18

> Pre-1.0 development line. The `0.x` series is unstable: schema, option keys, and public API may change between any two pre-release versions without a major bump. First production-ready cut ships as `1.0.0`.
>
> This line absorbs the unified framework, conversion tooling, Title/Slug rules, the Wireframe UI swap, and the rename. `@since` tags track three dev generations: `0.1.0` (original legacy handlers), `0.2.0` (unified rewrite + storage/conversion), `0.3.0` (Wireframe admin + diagnostics).

### Added

#### Unified framework
- **`BWS_Entity`** — polymorphic wrapper over posts, terms, users, comments.
- **`BWS_Rule_Engine`** — orchestrator pipeline (source filters → conditions → targets → actions).
- **`BWS_Condition_Evaluator`** and **`BWS_Action_Executor`**.
- **`BWS_Unified_Handler_Base`** — abstract base for unified-framework handlers with storage abstraction methods (`get_enabled_rules`, `get_all_rules`, `get_rule`, `save_rule`, `delete_rule`).
- **PHP 8.1 enforcement** at activation, on `plugins_loaded`, and via syntax usage.

#### Storage
- **Storage abstraction layer** — `BWS_Rule_Storage` interface (15 methods), `BWS_Option_Rule_Storage` (wp_options implementation), `BWS_Storage_Factory` with migration tooling. Prepares plugin for a CPT-backed storage backend.
- **Six database tables** created on activation: `wp_bws_meta_manager_log`, `wp_bws_acf_conversion_preview`, `wp_bws_acf_conversion_sessions`, `wp_bws_relationship_log`, `wp_bws_batch_queue`, `wp_bws_taxonomy_manager_log`. Validation + admin notice on failure.

#### Rules and handlers
- **Hierarchical Handler** rewrite with smart child expansion (3 explicit modes) and bidirectional hierarchy propagation.
- **Propagation Handler: term removal propagation** — `on_parent_terms_set()` diffs `$old_tt_ids` against `$tt_ids` and removes terms from children before re-applying current terms. New `propagate_term_removals_to_children()`.
- **Title & Slug Rules** — new rule type that customizes post titles and slugs from a pattern of tokens (`{meta:field}`, `{default_title}`, `{default_slug}`, `{date_year|month|day|hour|minute:field}`, `{pub_*}`, `{term:tax}`, `{terms:tax}`).
  - Token engine with separator auto-trimming for empty tokens (no dangling `(` / `:` / `-`) and a duplicate-insertion guard that skips tokens whose value already appears in the base title or slug.
  - Slug modes: `replace`, `prefix`, `suffix`. Server-side enforcement forces `replace` when slug pattern contains `{default_slug}` (prefix/suffix would double-insert).
  - Slug collision avoidance with optional date escalation ladder (year → month → day → hour → minute) before falling back to `wp_unique_post_slug()`. Escalated date parts insert adjacent to existing date tokens in the slug (e.g. `2026-post-1` → `2026-05-post-1` → `2026-05-27-post-1`).
  - Idempotency on re-save via `_bws_raw_title` / `_bws_applied_title` postmeta with inverse-strip recovery when the user edits the computed title.
  - Hybrid hook timing: non-meta rules compute title+slug in `wp_insert_post_data` (pre-write) so the editor shows correct values immediately; meta-dependent rules (`{meta:*}`, `{date_*:field}`) defer to `acf/save_post` (99) / `save_post` (99) with `redirect_post_location` cache flush for correct "View Post" link.
  - Single `wp_update_post()` call writes title and slug together; suppresses duplicate revisions. Pre-write path avoids the second write entirely.
  - Preview AJAX endpoint and bulk apply-to-existing endpoint with batched progress.
  - Last-applied status and capped warnings log stored in `bws_title_slug_rule_status` option.
- **`BWS_Title_Slug_Handler`** — first handler properly built on `BWS_Unified_Handler_Base`. Legacy `BWS_Handler_Base` handlers migrate during the 0.x cycle.

#### Conversion
- **ACF Conversion Tooling** — new `includes/conversion/` module (ConversionManager, DataProcessor, FieldMapper, PreviewSystem, ConversionCLI) plus dedicated `conversion-admin.css` / `conversion-admin.js`.
  - AJAX endpoints: `get_fields`, `get_taxonomies`, `get_taxonomy_terms`, `get_options`, `estimate_size`, `process_chunk`, `process`, `preview`.
- **Conversion Infrastructure (`includes/lib/`)** — BatchProcessor, FieldConverter, ValueMapper, TermMigrator with interfaces. Slated for absorption into the `Conversion\` namespace.

#### Admin UI rewrite (Phase 2c — Wireframe swap)
- **WP Wireframe library** (`tdrayson/wp-wireframe ~1.0.5`) via Composer; vendor folder committed.
- **`BWS_Wireframe_Bootstrap`** boots Wireframe under top-level `meta-conductor` menu slug, option key `bws_meta_conductor_settings`, REST namespace `bws-meta-conductor/v1`.
- **Per-tab config builders** under `includes/admin/config/`: hierarchical implemented; remaining 6 rule types in progress.
- **Data Conversion** registered as subpage under Meta Conductor menu (not a settings tab).
- **`BWS_Diagnostics`** — permanent dev/user-level diagnostics subpage. Storage section dumps option contents under `WP_DEBUG`; filter `bws_meta_conductor_show_diagnostics` exposes future user sections.
- **Generic rule AJAX helpers** — `ajax_toggle_rule_enabled` and `ajax_delete_rule` on the main manager class, both built on the storage abstraction.

#### Naming surface (Phase 2b — in progress)
- Public identity drops `BWS`: plugin folder, main file, text domain, admin page slug all become `meta-conductor`. Main file renamed `bws-taxonomy-manager.php` → `meta-conductor.php`; header `Plugin Name`/`Text Domain` updated. (Internal `__()` text-domain string sweep and PSR-4 namespace deferred to the full Phase 2a/2b pass.)
- Collision-safe layers keep `BWS\`/`bws_` prefix: PHP namespace, option keys, nonce action prefix, JS localized object, hook prefix.

#### Release infrastructure
- **Plugin Update Checker (YahnisElsts 5.7)** vendored at `libs/plugin-update-checker/`, booted in the main file against public GitHub releases in release-assets mode. Self-hosted updates pull the `meta-conductor.zip` asset attached to each release; PUC slug `meta-conductor` matches the installed folder.
- **GitHub Actions release workflow** (`.github/workflows/release.yml`): on a `v*` tag, verifies the plugin header `Version:` matches the tag, builds a distribution ZIP via `git archive` (root dir `meta-conductor/`, dev files dropped via `.gitattributes export-ignore`), and publishes a GitHub Release with the ZIP attached.
- `.gitattributes` `export-ignore` rules exclude dev-only paths (`.github`, `docs`, `debug`, `ROADMAP.md`, `CONTEXT.md`, `composer.*`) from distribution archives; `vendor/` and `libs/` ship (runtime-required).

### Changed
- Intermediate branding rename from "BWS Taxonomy Manager" to "BWS Meta Manager" to "Meta Conductor". Backward-compatible `BWS_TAX_MANAGER_*` constant aliases for any third-party code.
- Settings UI labels and descriptions reworked from user feedback.
- Date Window rule fields regrouped into Source → When → Effect order: taxonomy/term filters now sit directly under Post type instead of below the date boundaries, keeping all source-filter options together. Subfield reorder only; storage keys and saved rules unchanged.
- Expansion behavior simplified to three clear options.
- Admin CSS overhaul for rule list, header chrome, preview modal, and reorder buttons. (Legacy CSS deleted as Wireframe UI completes.)
- Strategic roadmap consolidated into [ROADMAP.md](ROADMAP.md).
- `.gitignore` ignores `CLAUDE.md`, `*.code-workspace`, and `/.claude` (the previously tracked `.claude/settings.local.json` remains tracked).
- **WP Wireframe upgraded 1.0.5 → 1.0.6** (constraint `~1.0.6`). Picks up three fixes for bugs reported during the Phase 2c swap: single-page `App::boot()` now honors `menu_slug` (#5), the admin-screen body class anchors on the `_page_{menu_slug}` suffix instead of a substring (#6), and the React root is wrapped in `SlotFillProvider` (#4, silences the console warning + fixes popover positioning). Also unlocks repeater-subfield `conditions` (#13) and the `action` field type for future use.

### Fixed
- Nonce mismatch in conversion AJAX (`verify_ajax_request()` now aligns with the nonce action the JS actually sends).
- Option-key bug in `BWS_Unified_Handler_Base::log_results()` that prevented the logging setting from ever being read. Final fix updates the lookup to `bws_meta_conductor_settings` after the option-key rename.
- Dead pre-1.1.0 upgrade stub (`bws_taxonomy_manager_upgrade()`) still read/wrote the legacy `bws_taxonomy_manager_settings` key. Replaced with a placeholder `bws_meta_conductor_version` tracker; no upgrade branches yet since no version has shipped to a deployment.
- Hierarchical handler `apply_rule()` re-added taxonomy_exists + hierarchical guard so invalid rules (deleted or non-hierarchical taxonomy) short-circuit cleanly instead of silently no-oping deep in the expansion logic.
- Wireframe boot gated to admin + REST contexts. Front-end requests no longer trigger `BWS_Config_Helpers::all_term_options()`, which does a full `get_terms()` scan across every public taxonomy. REST detection uses the URL prefix (`rest_get_url_prefix()`) because the `REST_REQUEST` constant isn't defined until `parse_request`, well after our `init`-priority-10 boot.
- Subpage `#wpcontent` padding workaround **removed** — the underlying Wireframe body-class substring bug (#6) is fixed in 1.0.6, so `BWS_Wireframe_Bootstrap::subpage_padding_fix()` and its `admin_enqueue_scripts` hook are gone. (Was: inline style restoring the standard 20px on non-Wireframe subpages mis-tagged `wireframe-admin`.)
- Activation admin notice + `plugin_action_links` pointed to the legacy `options-general.php?page=bws-taxonomy-manager` URL and used the old plugin name. Now reads "Meta Conductor" and links to `admin.php?page=meta-conductor`. Stale `bws-taxonomy-manager` text-domain calls in this file replaced with `bws-meta-manager`. Speculative docs/support links removed; GitHub link points to the real repo.
- Term dropdown loading for time-based rules (pre-load on rule add, proper Select2 refresh, nonce passing).
- Title/Slug preview/process/save errors — resolved by Wireframe REST + repeater pipeline replacing the legacy template-clone save path.
- Date escalation produced no ladder when slug was derived from title pattern (no explicit slug pattern) — now falls back to title pattern for date precision detection.
- Date escalation appended parts to end of slug instead of inserting adjacent to existing date tokens — rewrote to anchor-and-splice approach.
- Title/slug handler routed through generic `BWS_Rule_Engine` via base-class `process_post()`, causing `Undefined array key "action"` warnings — handler now overrides `process_post()` as no-op since it uses its own hook-based processing.
- **Hierarchical handler** rewritten to work directly with flat Wireframe fields instead of routing through the unified engine. Previous version silently failed because `validate_rule_internal()` required `action['type']` which Wireframe-stored rules never have.
- **Hierarchical handler** "both" direction flooded the entire tree — ancestors were added, then their children were expanded back down. Fixed by tracking auto-added terms in post meta (`_bws_auto_terms`) and expanding only from user-selected terms. Promotion logic handles terms that were auto-added but intentionally kept by the user.
- **Hierarchical handler** re-expansion on unrelated term changes (e.g., related handler removing a target) — per-request `$processed` guard plus auto-term tracking prevent cascading re-expansion from previously auto-added terms.
- **Related handler** removed target term on every save where the trigger term was absent, even if the trigger was never involved — `process_related_terms()` (called from ACF save path) ran removal without checking whether the trigger was actually removed vs just never present. Removal now only happens in `apply_related_terms()` which has the old/new term-taxonomy-ID diff.
- **`should_process_post()` checkbox normalization** — Wireframe checkboxes store `{slug: bool}` associative arrays; `in_array()` was checking values (booleans) instead of keys (slugs). Affects all handlers using checkbox post_types fields.

### Removed
- `ARCHITECTURE_DECISION_CPT_VS_OPTIONS.md` — superseded by the per-rule-type storage decision framework in the strategic roadmap.
- `TESTING_PLAN_V2.md` — superseded by per-feature plans under `.claude/plans/`.
- Relocated `test-conversion-integration.php` into `debug/` and out of the plugin runtime path.

## Pre-0.2.0 (legacy handlers, before the unified-framework rewrite)

History before the unified-framework rewrite is not catalogued in this changelog. This is the original BWS Taxonomy Manager generation (tagged `@since 0.1.0` in source). See `git log` prior to commit `08bce63` ("Phase 1: Unified Framework Foundation").
