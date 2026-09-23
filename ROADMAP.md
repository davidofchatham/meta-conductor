# Meta Conductor: Roadmap

Pre-release `0.x` line — unstable until the first production-ready cut graduates to `1.0.0`. Runtime version is **not** tracked here (drifts too fast); source of truth is the plugin header + `META_CONDUCTOR_VERSION` in `meta-conductor.php`.

The refactor is an **incremental migration, not a rewrite** — the core business logic works. The structural work has landed: PSR-4 (2a), rename (2b), handler unification (3), and the ordered rule list + dispatchers (4). What remains is integrations (6a, 6b) and the apply-rules-to-existing-posts page (7). This document tracks the phased plan and the decisions behind it.

---

## Status board

Phase numbers are **stable IDs, not execution order** — work has landed out of numeric sequence for pragmatic reasons (e.g. the Wireframe swap "2c" and release infra shipped before 2a/2b). This table is the single source of *where we are*; the numbered sections below keep their original IDs so cross-references (code `TODO(Phase N)`, CLAUDE.md, commits, PR #17) stay valid.

| Phase | What | Status | Gated on | Open items it closes |
|-------|------|--------|----------|----------------------|
| 0 | Title/Slug rules + conversion tooling | ✅ done | — | — |
| 1 | Bug fixes | ✅ done | — | — |
| 2c | Wireframe UI swap | ✅ done | — | god-class `BWS_Settings` → 60-line shell; mixed JS globals (legacy JS deleted) |
| 0.3.1 | PR #17 review pass | ✅ done | — | — |
| 2a | PSR-4 namespacing (+ `lib/`→`Support\`, abstracts co-located, `tests/` harness) | ✅ done (0.4.0) | — | manual `require_once` chains; `includes/abstracts/` + `includes/lib/` |
| 3 | Migrate 5 legacy handlers → UnifiedHandlerBase. All 7 handlers on `UnifiedHandlerBase`; legacy `BWS_Handler_Base` deleted; redundant `on_post_save` loop removed | ✅ done (0.6.0) | 2a ✅ | legacy handler base; dual-base divergence; `on_post_save` loop double-run |
| 2b | Rename sweep — text domain, constants, nonces, core hooks, log table + migration all done (PR #48; real-Athletics verified). JS object + conversion cron/AJAX/transients deferred to P7; internal fn names deferred | ✅ done (shipped 0.7.0) | 2a ✅ | mixed text domains |
| 4 | Ordered rule list + central dispatcher — *was config page split; was CPT storage before that* | ✅ done (shipped 0.8.0) | 3 ✅ | #35; both instantiation-order dependencies; 7-array storage shape; ~~dead `class-settings.php` + AJAX bodies~~ ✅ #55 |
| 7 | Apply rules to existing posts — *was the unified migration / preview tool* | queued | — (ungated; can run anytime) | #31's missing UI trigger; the unused `Support\` classes, conversion `error_log` spam and **rename remainder** (conversion JS global/cron/AJAX/transients) all close by deletion with the Data Conversion page |
| 6a | Options-compatible integrations | queued | 3 | — |
| 6b | BWS User Based Terms (→ a `type` in `term_rules`) | queued | 4 | UBT merge; needs the unified rule list from P4 |
| ~~5~~ | ~~Settings refactor~~ | cancelled | — | absorbed by 2c; its lib delegation went to 7, then dropped with the 7 restart |

**Recommended run order:** ~~2a~~ ✅ → ~~3~~ ✅ → ~~2b~~ ✅ → ~~4~~ ✅ → (6a, 7) → 6b. Phase 3 landed before 2b so the rename sweep touches already-migrated handlers once. Phase 7 is unblocked and can slot in whenever a bulk apply is needed.

**Not phases — shipped bugfix waves.** 0.6.0 fixed the Admin Columns Pro **v7** integration ([#37](https://github.com/davidofchatham/meta-conductor/issues/37)) via a shared `reapply_for_post` seam. 0.7.0 landed both AC v7 follow-ups — [#42](https://github.com/davidofchatham/meta-conductor/issues/42) (`Core\AcfWriteQueue` drives term sync from ACF's own `acf/update_value`, covering bare `update_field()`, WP-CLI/cron and REST; guard H8) and [#43](https://github.com/davidofchatham/meta-conductor/issues/43) (dependent-end sever) — plus [#31](https://github.com/davidofchatham/meta-conductor/issues/31) (bulk apply no longer inert; **still no UI trigger**, that stays with P7), [#45](https://github.com/davidofchatham/meta-conductor/issues/45)/[#47](https://github.com/davidofchatham/meta-conductor/issues/47) (propagation removals stick on descendants), and the Data Conversion AJAX endpoint-shadowing fix. Full detail in [CHANGELOG.md](CHANGELOG.md).

Live defects not yet scheduled to a phase are tracked in GitHub Issues; the "Open items it closes" column above is the at-a-glance index.

---

## Confirmed Decisions

Status column: ✅ = actioned · Pn = pending in that phase · standing = ongoing policy.

| Decision | Choice | Status | Notes |
|----------|--------|--------|-------|
| **Plugin name** | **Meta Conductor** | ✅ | Display name and slug both drop "BWS". See "Naming surface" table below. |
| **Naming surface** | Split by layer | ✅ (P2b, shipped 0.7.0) | Folder/main-file/text-domain/constants/nonces/core-hooks/log-table all done. JS object + conversion cron/AJAX/transients + internal fn names deferred to P7/tidy. |
| **PSR-4 namespacing** | Yes | ✅ (0.4.0) | Custom `spl_autoload_register()` autoloader (root `autoload.php`); namespace `BWS\MetaConductor\` |
| **Abstracts directory** | Co-locate with implementations | ✅ (0.4.0) | `Storage\RuleStorage`, `Handlers\UnifiedHandlerBase` — `includes/abstracts/` eliminated |
| **Interface file naming** | Use `class-` prefix for all | ✅ (0.4.0) | Autoloader generates `class-{name}.php`; interfaces follow same convention |
| **lib/ classes** | Absorb into `Support\` namespace | ✅ (0.4.0) | BatchProcessor, FieldConverter, ValueMapper, TermMigrator → `includes/support/` (renamed from `lib/` to avoid collision with vendored `libs/`; not `Conversion\` as originally planned) |
| **lib/ integration** | ~~Complete in Phase 7~~ Dropped | ✅ superseded (2026-09-23) | Nothing outside `includes/conversion/` uses `includes/support/`; both are deleted with the Data Conversion page in the Phase 7 restart rather than wired together. |
| **Conversion tool** | ~~Keep in this plugin~~ Replace with rules | ✅ superseded (2026-09-23) | Copy / Map come back as rule types (`related`, `field_transformation`) applied through the Phase 7 page, not as one-shot recipes. |
| **CPT vs options** | ~~Options + page split~~; CPT deferred | ⚠️ superseded by the row below | Storage choice is **per Wireframe page**, not per rule type. Page split (P4) splits the blob; CPT only if a type needs a draft/test lifecycle. See [storage-model.md](docs/storage-model.md). |
| **CPT structure** | Deferred | — | `bws_mc_rule` shared-CPT design preserved in storage-model.md if/when a type needs it. Not scheduled. |
| **Config storage boundary** | Effect kind, not page | ✅ reassessed (2026-08-13) | Page split abandoned as the mechanism — it doesn't shrink the hot blob. One page, three tabs; storage is one ordered list per **effect kind**; clobber is a version-token guard. See [ADR 0003](docs/adr/0003-ordered-rule-list-and-dispatcher.md). |
| **Plugin file rename** | Yes | ✅ | All installs are controlled |
| **Option key rename** | Yes — with data migration, tested on InstaWP | ✅ (2c) | New key: `bws_meta_conductor_settings` |
| **Handler migration order** | Simplest first | P3 | Related → Level Restriction → Propagation → Related Post Terms → Time Based |
| **Legacy BWS_Handler_Base** | Delete after last handler migrates | P3 | No deprecation shim needed — private plugin |
| **CLAUDE.md updates** | End of each phase | standing | Reflects completed architecture, not planned work |
| **Version number** | 0.x → 1.0.0 | ✅ in effect | Pre-release line is `0.x`; breaking changes (file rename, class names, option key) are free pre-1.0. First production-ready cut is `1.0.0`. |

### Naming Surface (0.x)

Split the rename by layer — public-facing identity drops `BWS`, code/storage layers keep `BWS`/`bws_` namespace for collision safety.

| Layer | Value (0.x) | Rationale |
|-------|---------------|-----------|
| Plugin display name | `Meta Conductor` | Public identity |
| Plugin folder | `meta-conductor` | WP convention: folder = slug |
| Main file | `meta-conductor.php` | Matches folder |
| Text domain | `meta-conductor` | WP convention: text domain = plugin slug; matters if plugin ever publishes to WP.org |
| Plugin constants | `META_CONDUCTOR_*` | Code-internal. The planned `BWS_META_MANAGER_*` / `BWS_TAX_MANAGER_*` back-compat aliases were **not** kept — 2b confirmed no external consumer and dropped them outright. |
| PHP namespace | `BWS\MetaConductor\` | Collision safety in autoloaded global namespace |
| Option keys | `bws_meta_conductor_*` | Collision safety in shared `wp_options` table |
| Nonce action prefix | `bws_meta_conductor_*` | Pairs with option keys |
| JS localized object | `bwsMetaConductor` | Pairs with PHP constants/namespace |
| Hook/filter prefix | `bws_meta_conductor_*` | Consistent with stored data + JS |

**Rule of thumb**: anything users / translators / the WP admin UI sees → drop `BWS`. Anything stored in a global PHP/JS/DB namespace where another plugin could collide → keep `BWS`.

---

## Phased Roadmap

### ✅ Completed phases: 0, 1, 2c, 0.3.1, 2a, 2b, 3

Per-phase detail lives in [CHANGELOG.md](CHANGELOG.md) and the PRs; the invariants each one established are in [docs/architecture.md](docs/architecture.md), [CONTEXT.md](CONTEXT.md) and CLAUDE.md's don'ts. What the status board's one-liners leave out:

- **Phase 0** — Title/Slug rules (token engine, idempotency, slug collision avoidance) + the ACF conversion tooling (ConversionManager, DataProcessor, FieldMapper, PreviewSystem, ConversionCLI). First handler on the unified base.
- **Phase 1** — three bug fixes (commit `f16091e`).
- **Phase 2c** — the hand-rolled ~5,000-line settings UI replaced by WP Wireframe; option key migrated to `bws_meta_conductor_settings`; `normalize_rule_shape()` adapter landed. Descoped **for good**: custom client-side field types — Wireframe has no client-side extension API (CLAUDE.md don't 5). Deferred: the Title/Slug inline Preview / Apply buttons — P7 ships them page-level, the rule-adjacent form is FW-31.
- **0.3.1 review pass** — established the **site-time invariant** (`{pub_*}` tokens bound to `wp_timezone()`), now locked in CONTEXT.md → *Site time*.
- **Phase 2a (0.4.0)** — PSR-4 under `BWS\MetaConductor\`, root `autoload.php`, `includes/lib/` → `Support\`, abstracts co-located. The two traps it discovered (namespace before the ABSPATH guard; leading-backslash every global class ref) are CLAUDE.md don't 0, enforced by H1/H2.
- **Phase 2b (0.7.0)** — rename sweep: text domain, `META_CONDUCTOR_*` constants (no aliases), nonces, core hooks, log table + migration. Verified against real production data.
- **Phase 3 (0.6.0)** — all 7 handlers on `UnifiedHandlerBase`, `HandlerBase` deleted, the redundant `on_post_save` loop removed, and the `apply_to_post()` bulk seam introduced ([#31](https://github.com/davidofchatham/meta-conductor/issues/31)).

**Still open from these phases**, all closed by Phase 7 deleting the conversion subsystem: its rename remainder, its ~137 unconditional `error_log()` calls, and its tab-URL builder that targets `admin_url('tools.php')` when the menu registers elsewhere. Internal function names (`bws_meta_manager_init`, `bws_taxonomy_manager_activate/deactivate/uninstall`) stay deferred — not user-facing, no BC pressure, rename opportunistically.

---

### ✅ Phase 4: Ordered Rule List + Central Dispatcher — DONE (0.8.0)

**Twice re-scoped** (was CPT storage, then config page split). Rationale: **[ADR 0003](docs/adr/0003-ordered-rule-list-and-dispatcher.md)**, partially superseding [ADR 0002](docs/adr/0002-cross-rule-composition.md). Vocabulary: [CONTEXT.md](CONTEXT.md) → *Effect kind*, *Dependency*, *Pass*, *Order*.

**Why the page split stopped being the point.** Splitting 5 tabs into 4 pages doesn't shrink the hot blob — Auto-Set & Restrict would host 6 of 7 rule types today — and cutting further would have to cut *inside* the term group, exactly where rules interact and the one place a storage boundary hurts. Page count is therefore a pure UX choice: **one page, three tabs.**

**What shipped** — per-ticket detail in [CHANGELOG.md](CHANGELOG.md) `[0.8.0]`; the invariants it left behind are in [docs/architecture.md](docs/architecture.md) and CLAUDE.md don'ts 6 / 6b–6g:

| | |
|---|---|
| **Storage** (#56 expand → #66 contract) | 7 type-keyed arrays → 2 kind-keyed ordered lists. `get_kind_rules()` is the one kind read and serves the stored list **verbatim**; the type-facing API is a view over it, with the per-type `$rule_id` translated to a list position. Legacy shapes migrate on **read** (handlers read on front-end and cron, where the admin bootstrap never runs). Rode along: **#27**. |
| **Config** (#57, #58, #59) | 5 tabs → 3 (Personalize removed). All six term types became rows in `term_rules`, `title_slug` the sole row type of `format_rules`; all eight per-type config classes deleted. Two schema decisions taken in the free window: **#16** (hierarchy pair → one `inheritance_behavior` outcome selector) and **#32** (`include_ancestors` redefined to one additive meaning; `remove_conflicting_ancestors()` deleted as dead). |
| **Dispatch** (#60 → #64) | `Core\TermDispatcher` — trigger union, dirty-entity queue, one full ordered pass per entity, pass-scoped lock keyed *(entity, kind)* — plus `Core\FormatDispatcher` run from the same drain step, which turns the cross-kind order into one statement instead of an instantiation accident. All 7 handlers are pure appliers; four `private $processing` booleans and the taxonomy-scoped cascade guard are gone. Every entry point routes through it (`AcfWriteQueue` #42, the AC v7 `reapply_for_post` seam #37, bulk apply, time_based's cron). |
| **Collision advisory** (#65) | Advisory, never a resolver: same effect target + overlapping written post types ⇒ warn, on both rule tabs and on demand. Re-scopes **#39**. |
| **Claim wording** | Four values, not three — `replace`/`merge`/`skip` = owning/contributing/deferring. Closes **#34**; UI wording only, stored values untouched. [ADR 0004](docs/adr/0004-claim-axis-and-jurisdiction.md). |
| **Ride-alongs** (#55) | `class-settings.php` deleted, taking the dormant blunt `array_merge` clobber; `flatten_conflict_overrides()` rehomed to `Storage\OptionRuleStorage`; unreachable AJAX bodies removed. |

**Closed:** #35 and **both** latent instantiation-order dependencies (propagation-before-hierarchical, `TitleSlugHandler` constructed last).

**Gates — all four passed before the `v0.8.0` tag:** migration harness (H10), dispatch-order source inspection (H13), full testbed sweep of all 7 types incl. explicit cross-type ordering, and the mandatory **Athletics copy** run against real data ([#67](https://github.com/davidofchatham/meta-conductor/issues/67)) — the two live rule types only exist there.

⚠️ A condition-hidden subfield is **dropped server-side at sanitize**, so a wrong gate is silent data loss. H11/H12 assert the visible set per type through Wireframe's own evaluator, and every show/hide is swept on the testbed. CLAUDE.md don't 3.

**Carry-overs — real work, not scheduled to any phase:**

- **The per-taxonomy claim default is stored and flattened but read by nobody** — a rule that omits its own claim still falls back to a hard-coded `merge` in each handler. Deciding rule-level-vs-taxonomy-level precedence and wiring the lookup is what's left. Two orphans wait on it: `RuleStorage::get_raw_settings()` (the seam the wiring reads through) and write-only `manual_processing_enabled` (gates the bulk-apply buttons, which are P7).
- **PHPUnit for the snapshot label helpers** — [#68](https://github.com/davidofchatham/meta-conductor/issues/68). `WireframeBootstrap::term_label()` / `scope_label()` / `taxonomy_label()` / `snapshot_related_labels()` are near-pure functions of WP data covered only by manual sweeps; `composer.json` still has no `require-dev`. Standing PHPUnit up also gives the reach/collision work somewhere to land unit tests.
- **Deferred by design:** full reach/component collision detector (define reach once the non-term effect kinds are real); stable rule `_id` (order is array position — ADR 0002 rejected provenance); CPT storage; rule-type renaming; sub-scope for restricting rules. The format dispatcher goes two-phase when `field_transformation` lands (`wp_insert_post_data` vs `acf/save_post` p20) — CLAUDE.md don't 6f(c).

---

### ~~Phase 5: Refactor Settings & Complete Conversion Integration~~ — CANCELLED

Cancelled by **Phase 2c (Wireframe swap)**. The legacy `BWS_Settings` god class is fully replaced by Wireframe-driven config classes under `includes/admin/config/`; the old `class-bws-settings.php`, `admin.js`, and `admin.css` are scheduled for deletion. JS unification moot — the new UI has no custom JS to namespace.

Conversion integration completion (lib class delegation in `BWS_Data_Processor`) folded into Phase 7, then was dropped when Phase 7 restarted as a rule-apply page that deletes the conversion subsystem.

---

### Phase 7: Apply Rules to Existing Posts

A dedicated admin page that runs any configured rule over the posts that already exist. Rules otherwise act only when a post is saved, so a new or changed rule leaves every existing post as it was until someone re-saves it. Restarted 2026-09-23 from the "Unified Migration / Preview tool" plan, which hosted one-shot recipes behind a `bws_meta_conductor_migrations` filter. That recipe engine is dropped: it existed to host the Data Conversion flows, and those come back as rules.

**Why a page:** Wireframe 1.0.6's `action` field renders a real button that posts to a server hook, so page-level Preview / Apply needs no custom JS. What it cannot do yet is say which repeater row fired it, so a button *inside* a rule row stays deferred ([FW-31](docs/future-work.md#fw-31--rule-adjacent-preview--apply)). The page is the first entry point, not the only one.

**Shape:**

- One Wireframe subpage, "Apply to existing posts", taking the Data Conversion submenu slot.
- A **rule dropdown** listing every row of both kind lists (`term_rules`, `format_rules`) by row title, disabled rows included and marked "(disabled)", plus **"All enabled rules"**. The option value carries the row's position and a content fingerprint; a run refuses and asks for a reload if the stored list no longer matches, because a row has no stable id.
- **A run is a full ordered pass, not a single-rule apply.** The chosen rule picks the posts; the pass applies every enabled rule to them, in authored order — the same one-caller invariant H13 holds for `process_existing_posts()`. Bulk is one more provocation, so a bulk run and a save over the same rules cannot diverge.
- **Scope:** the post types from `CollisionDetector::written_post_types()`, narrowed by the rule's `post_status` where it has one, else publish / draft / private / future. Never trash or auto-draft.
- **A disabled rule runs as a one-time run:** it is treated as enabled for that pass only, at its authored position, and stays disabled in storage. Later saves neither maintain nor undo what it wrote. For format rules this doubles as "preview before enabling" — which is also why a disabled row can change which `title_slug` rule wins first-match on a post.
- **The applier takes a rule array, not a page request,** so a future in-row button (FW-31) is a second entry point onto the same code.

**Preview and safety:**

- Format rules: a before/after title/slug sample, computed without writing — `apply_to_data()` already returns data rather than writing it.
- Term rules: in-scope post count and a sample list only. A true dry-run needs a compute-only path through every term applier ([FW-32](docs/future-work.md#fw-32--term-rule-dry-run)).
- An optional **limit** (first N in-scope posts), a **change report** (per-post terms before/after, captured during the real run; fan-out writes to other posts are not included and the report says so), and the `action` field's built-in `confirm` stating the run writes and cannot be undone.

**Progress:** time-boxed batches — each click processes ~20s of posts, stores a cursor per user + rule, and returns "412 / 1,300 — Continue". No cron dependency. A background WP-Cron job is [FW-33](docs/future-work.md#fw-33--background-bulk-apply), wanted only if Continue proves tedious; if Wireframe gains action continuation upstream, the Continue click goes away instead.

**Retires the Data Conversion page.** In the same release: delete `includes/conversion/`, its assets, its `wp_ajax_*` registrations in `TaxonomyManager`, and `includes/support/` (nothing else uses it). That closes, by deletion, the conversion rename remainder (`bwsMetaManager` JS global, `*_conversion_*` cron / AJAX / transients), the ~137 unconditional `error_log()` calls, and the `tools.php` tab-URL bug. Copy Data and Map Data return as rule types — term-to-term copy is `related`, field-to-field copy and value mapping are `field_transformation` (Phase 6a) — applied to existing posts through this page. If a site needs either before 6a ships, restore it from git.

**Not in this phase:** the post type converter ([FW-15](docs/future-work.md#fw-15--post-type-converter)). It is a true one-shot transform with no rule behind it, so it gets its own tool.

**Storage:** none new.

**End of phase**: Update CLAUDE.md.

---

### Phase 6a: Options-Compatible Integrations

**Ungated** — its Phase 3 gate closed in 0.6.0. These do not require CPT storage.

**ACF Post Relationship Manager**
- Sets hierarchical parent/child post relationships based on ACF post object/relationship fields
- Distinct from `related_post_terms_rules`: same data source (ACF relationship field), different output (post parent vs taxonomy terms)
- New rule type `acf_relationship_rules` → Options storage

**Date-Based Taxonomy Updater** — *folded into the in-flight Temporal State Rule (0.x), not a separate type.* The per-post ACF-date comparison this described is now an Options-storage extension of `time_based_rules`. See [FW-3](docs/future-work.md#fw-3--time_based_rules-temporal-state-rule).

**Field Transformation Rules** (from existing snippet)
- Combines multiple fields into a formatted output field (e.g. athlete stats → bio string, date + time → sortable datetime). Must also work inside ACF repeater rows (per-row compose/write).
- New rule type `field_transformation_rules` → storage TBD via [storage-model.md](docs/storage-model.md) (likely Options + indirection; CPT only if a per-recipe lifecycle is wanted). **Not gated on Phase 4** (done 0.8.0 anyway) — but it lands as a row type in the existing `format_rules` list, and the format dispatcher goes two-phase when it does (CLAUDE.md don't 6f(c)).

**End of phase**: Update CLAUDE.md

---

### Phase 6b: BWS User Based Terms (→ a `type` in `term_rules`)

**Ungated** — the Phase 4 rule list it needed landed in 0.8.0.

**Re-scoped 2026-08-13** by [ADR 0003](docs/adr/0003-ordered-rule-list-and-dispatcher.md). There is no Personalize *page* and no `bws_mc_personalize` option — the page split was abandoned. UBT lands as **`type` values inside the unified `term_rules` list**, ordered among the other term rules.

- **Both variants are term rules.** The auto-set variant writes terms (`wp_set_object_terms($post_id, $term_ids, $taxonomy, false)` in the source plugin — replace, i.e. *owning*). The lock/restrict variant is **not** a separate effect: its effect target is terms and its **claim** is *restricting*, exactly like level-restriction. Filtering what the admin UI offers is the surface, not the effect. See CONTEXT.md → *restricting-the-editor is still a restricting claim*.
- **Consequence: UBT is ordered against the other term rules.** It sits in the same ordered list and the same **pass**, so "does the user default win over the propagated parent term?" becomes an author-visible position rather than an emergent accident. It also participates in the collision warning.
- **Basis is ambient but needs no sweep** — the acting user only matters at the instant of a write, so the dispatcher's `save_post` trigger is sufficient. Contrast Temporal, whose ambient "now" moves on its own. (CONTEXT.md → *Basis*.)
- Stored in **options** (not CPT): role/user = *target*, not owner → single author. Per-user customization → **profile ACF field + one indirection rule**, not N per-user rules. See [FW-8](docs/future-work.md#fw-8--user_based_rules-user-based-term-setting--restriction), [storage-model.md](docs/storage-model.md).
- **Migration:** UBT CPT posts (`bws_user_term_rule`) → rows appended to `term_rules` with the appropriate `type` (dry-run). Note UBT carries its own `priority` field — map it onto **list position**, since position is now the ordering mechanism.
- Port UBT rule-engine / applicator / cache / ACF-integration into an MC handler extending `UnifiedHandlerBase`, exposing `apply_to_post()` like every other handler — it registers **no hooks of its own** (the dispatcher owns them, per ADR 0003). Drop the UBT CPT editor in favor of the Wireframe panel.
- ⚠️ **Type-key name unsettled**: the UBT merger plan proposes `user_based_terms_rules`; [FW-8](docs/future-work.md#fw-8--user_based_rules-user-based-term-setting--restriction) and [storage-model.md](docs/storage-model.md) say `user_based_rules`. Under the unified list this is a `type` value, not an option key — settle it when building.
- Largest integration; tackle last.

**End of phase**: Update CLAUDE.md

---

## Storage Model Decision Framework

**Moved → [docs/storage-model.md](docs/storage-model.md).** That doc is the source of truth + working doc for options-vs-CPT, the decision criteria, the per-type assignments table, concurrency/clobber preventions, the indirection escape hatch, and the Wireframe-page storage boundary.

**Run every new rule type through it before implementing.**

Key reassessments since the original inline framework (2026-06-23):
- `user_based_rules` (UBT): **CPT → Options** — role/user is the *target*, not the owner → single author, no concurrent writes; per-user explosion solved by indirection (profile field + one rule). See [FW-8](docs/future-work.md#fw-8--user_based_rules-user-based-term-setting--restriction).
- **Superseded 2026-08-13 ([ADR 0003](docs/adr/0003-ordered-rule-list-and-dispatcher.md)):** the storage boundary is the **effect kind**, not the Wireframe page — two ordered lists (`term_rules`, `format_rules`), lost-update clobber handled by a version token. The page split is abandoned, and with it both "storage choice is per Wireframe page" and the "CPT re-opened for `title_slug` / `time_based` under the split" reassessment. **CPT stays deferred for every type.**

---

## Critical Files Reference

Paths reflect post-2a reality (PSR-4, 0.4.0): kebab `class-{name}.php`, `BWS\MetaConductor\` namespace, main file `meta-conductor.php`.

| File | Role | Phase |
|------|------|-------|
| `includes/core/class-term-dispatcher.php` | Term-kind dispatcher: trigger union, dirty queue, ordered pass, pass-scoped lock | 4 ✅ |
| `includes/core/class-format-dispatcher.php` | Format-kind pass, run from the term drain step (the cross-kind order) | 4 ✅ |
| `includes/handlers/class-unified-handler-base.php` | Shared handler base; the `apply_to_post` / `apply_to_data` / `fan_out` / `drain_captures` seams | 3 ✅ |
| `includes/storage/class-option-rule-storage.php` | Kind-list storage + legacy fan-in migration | 4 ✅ |
| `includes/admin/config/class-term-rules-config.php` | The ordered term repeater (all 6 term types) | 4 ✅ |
| `includes/admin/config/class-format-rules-config.php` | The ordered format repeater (`title_slug` today) | 4 ✅ |
| `includes/admin/class-collision-detector.php` | Collision advisory, both surfaces | 4 ✅ |
| `includes/conversion/class-data-processor.php` | Delegates to `Support\` classes during the tool build | 7 |
| `assets/js/conversion-admin.js` | Conversion JS — the rename remainder lives here | 7 |

---

## Hard Constraints

- Don't start `field_transformation_rules` until its storage is decided via [storage-model.md](docs/storage-model.md) (likely Options + indirection; CPT only if a per-recipe lifecycle is needed).
- Update CLAUDE.md at the end of every phase.

**Satisfied / dead constraints:** 6a's gate on Phase 3 (done 0.6.0) and 6b's gate on Phase 4 (done 0.8.0) — both remaining phases are now ungated. The 2a/2b InstaWP gates died with InstaWP itself (CLAUDE.md → *Test site*). "Don't refactor `BWS_Settings` until handler migration is done" — the class is deleted (#55).
