# Meta Conductor: Roadmap

Pre-release `0.x` line — unstable until the first production-ready cut graduates to `1.0.0`. Runtime version is **not** tracked here (drifts too fast); source of truth is the plugin header + `META_CONDUCTOR_VERSION` in `meta-conductor.php`.

The refactor is an **incremental migration, not a rewrite** — the core business logic works. The structural work has landed: PSR-4 (2a), rename (2b), handler unification (3), and the ordered rule list + dispatchers (4). What remains is integrations (6a, 6b) and the migration / preview tool (7). This document tracks the phased plan and the decisions behind it.

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
| 7 | Unified migration / preview tool | queued | — (ungated; can run anytime) | `lib/` classes instantiated but never called; tab-aware save bug; conversion `error_log` spam; **rename remainder** (conversion JS global/cron/AJAX/transients — #13 closed, remainder tracked here) |
| 6a | Options-compatible integrations | queued | 3 | — |
| 6b | BWS User Based Terms (→ a `type` in `term_rules`) | queued | 4 | UBT merge; needs the unified rule list from P4 |
| ~~5~~ | ~~Settings refactor~~ | cancelled | — | absorbed by 2c; lib delegation folded into 7 |

**Recommended run order:** ~~2a~~ ✅ → ~~3~~ ✅ → ~~2b~~ ✅ → ~~4~~ ✅ → (6a, 7) → 6b. Phase 3 landed before 2b so the rename sweep touches already-migrated handlers once. Phase 7 is unblocked and can slot in whenever Conversion is needed.

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
| **lib/ integration** | Complete in Phase 7 | P7 | `Conversion\DataProcessor` delegates to `Support\` classes during the migration-tool build (was Phase 5, cancelled). |
| **Conversion tool** | Keep in this plugin | ✅ decided | Operates on same entities/fields |
| **CPT vs options** | ~~Options + page split~~; CPT deferred | ⚠️ superseded by the row below | Storage choice is **per Wireframe page**, not per rule type. Page split (P4) splits the blob; CPT only if a type needs a draft/test lifecycle. See [storage-model.md](docs/storage-model.md). |
| **CPT structure** | Deferred | — | `bws_mc_rule` shared-CPT design preserved in storage-model.md if/when a type needs it. Not scheduled. |
| **Config storage boundary** | Effect kind, not page | ✅ reassessed (2026-08-13) | Page split abandoned as the mechanism — it doesn't shrink the hot blob. One page, three tabs; storage is one ordered list per **effect kind**; clobber is a version-token guard. See [ADR 0003](docs/adr/0003-ordered-rule-list-and-dispatcher.md). |
| **Plugin file rename** | Yes | ✅ | All installs are controlled |
| **Option key rename** | Yes — with data migration, tested on InstaWP | ✅ (2c) | New key: `bws_meta_conductor_settings` |
| **Handler migration order** | Simplest first | P3 | Related → Level Restriction → Propagation → Related Post Terms → Time Based |
| **Legacy BWS_Handler_Base** | Delete after last handler migrates | P3 | No deprecation shim needed — private plugin |
| **Tab-aware save bug** | Fix during the Phase 7 tool build | P7 | Latent, not actively causing loss (was Phase 5, cancelled). |
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
- **Phase 2c** — the hand-rolled ~5,000-line settings UI replaced by WP Wireframe; option key migrated to `bws_meta_conductor_settings`; `normalize_rule_shape()` adapter landed. Descoped **for good**: custom client-side field types — Wireframe has no client-side extension API (CLAUDE.md don't 5). Deferred to P7: the Title/Slug inline Preview / Apply buttons.
- **0.3.1 review pass** — established the **site-time invariant** (`{pub_*}` tokens bound to `wp_timezone()`), now locked in CONTEXT.md → *Site time*.
- **Phase 2a (0.4.0)** — PSR-4 under `BWS\MetaConductor\`, root `autoload.php`, `includes/lib/` → `Support\`, abstracts co-located. The two traps it discovered (namespace before the ABSPATH guard; leading-backslash every global class ref) are CLAUDE.md don't 0, enforced by H1/H2.
- **Phase 2b (0.7.0)** — rename sweep: text domain, `META_CONDUCTOR_*` constants (no aliases), nonces, core hooks, log table + migration. Verified against real production data.
- **Phase 3 (0.6.0)** — all 7 handlers on `UnifiedHandlerBase`, `HandlerBase` deleted, the redundant `on_post_save` loop removed, and the `apply_to_post()` bulk seam introduced ([#31](https://github.com/davidofchatham/meta-conductor/issues/31)).

**Still open from these phases**, all carried by Phase 7 below: the conversion-subsystem rename remainder, its ~137 unconditional `error_log()` calls, and the tab-URL builder that targets `admin_url('tools.php')` when the menu registers elsewhere. Internal function names (`bws_meta_manager_init`, `bws_taxonomy_manager_activate/deactivate/uninstall`) stay deferred — not user-facing, no BC pressure, rename opportunistically.

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

Conversion integration completion (lib class delegation in `BWS_Data_Processor`) folds into **Phase 7 (Migration / Preview tool)** below.

---

### Phase 7: Unified Migration / Preview Tool

Reframes the existing ACF "Data Conversion" page as a general-purpose Migration / Preview tool that hosts any one-time data transformation.

**Why now:** Wireframe v1.0.5 has no JS-side field-type extension API. Inline Preview / Apply-to-Existing buttons inside a Wireframe repeater row are blocked. Routing those actions to a dedicated migration page sidesteps the blocker and provides a permanent home for bulk operations across rule types.

**Architecture:**

- Single admin subpage under Meta Conductor menu — replaces (or absorbs) the current Data Conversion subpage.
- Recipes registered via filter `bws_meta_conductor_migrations`. Each recipe declares:
  - `id`, `label`, `description`
  - `source_query` callback — yields post IDs in chunks
  - `transform` callback — computes the new state for one post
  - `preview` renderer — shows before/after
  - `commit` callback — writes the change
- UI: recipe picker → parameter form → preview sample → run with chunked progress bar → completion summary.
- Reuses existing infrastructure: `Support\BatchProcessor`, `Support\TermMigrator`, `Support\FieldConverter`, `Support\ValueMapper` (moved from `lib/` → `Support\` in 2a). Lib-class delegation (cancelled Phase 5 carry-over) happens here.

**Recipes to ship at launch:**

1. ACF → taxonomy term (current Copy Data flow)
2. Field A → Field B value mapping (current Map Data flow)
3. Apply Title/Slug rule to existing posts (replaces the inline button blocked in Phase 2c)

**Recipes for future phases:**

- Re-walk hierarchical inheritance against existing posts
- Enforce level restriction across existing posts
- Standardize date fields
- Merge name fields
- Format phone numbers

**Storage:** none new. Recipes are registered code, not user-saved config.

**Debug-log cleanup (deferred from the 0.7.0 conversion AJAX fix):** the conversion subsystem carries ~137 unconditional `error_log()` calls across `class-data-processor.php`, `class-preview-system.php`, `class-field-mapper.php`, `class-conversion-ui.php` — dev-tracing leftovers (`=== DEBUG ===`, `print_r` dumps, `(UPDATED)`/`(SIMPLIFIED)` tags) that fire on every op in production logs regardless of `WP_DEBUG`. Redundant: genuine errors already surface via `wp_send_json_error` / `$batch_result['errors']`. Strip them when this code is reworked. **Keep** the one operational log — the cron-cleanup summary at `class-conversion-manager.php` (`Meta Conductor Conversion Cleanup: Deleted…`). Also drop the `debug_info` block + `error_log` spam from `handle_estimate_conversion_size_ajax`.

**Rename remainder (carried from 2b; [#13](https://github.com/davidofchatham/meta-conductor/issues/13) is closed — this list is now the only tracker):** the conversion subsystem's identifiers were deferred here because renaming them in isolation would churn code this phase rewrites. When the conversion code is reworked, finish:
- JS global `bwsMetaManager` → `bwsMetaConductor` (26 refs in `assets/js/conversion-admin.js`) + the PHP `wp_localize_script()` object name
- Conversion cron `*_conversion_cleanup`, AJAX actions `wp_ajax_*_conversion_*`, transient keys `*_conversion_*`
- Verify conversion AJAX succeeds under the unified JS global + nonce. (The endpoint-shadowing bug behind the broken selectors was fixed separately in 0.7.0 — the remaining work here is naming, not correctness.)

**End of phase**: Update CLAUDE.md, drop legacy Data Conversion submenu in favor of the unified one.

---

### Phase 6a: Options-Compatible Integrations

**Ungated** — its Phase 3 gate closed in 0.6.0. These do not require CPT storage.

**ACF Post Relationship Manager**
- Sets hierarchical parent/child post relationships based on ACF post object/relationship fields
- Distinct from `related_post_terms_rules`: same data source (ACF relationship field), different output (post parent vs taxonomy terms)
- New rule type `acf_relationship_rules` → Options storage

**Date-Based Taxonomy Updater** — *folded into the in-flight Temporal State Rule (0.x), not a separate type.* The per-post ACF-date comparison this described is now an Options-storage extension of `time_based_rules`. See docs/future-features.md → `time_based_rules`.

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
- Stored in **options** (not CPT): role/user = *target*, not owner → single author. Per-user customization → **profile ACF field + one indirection rule**, not N per-user rules. See [ubt-merger plan](.claude/plans/ubt-merger.md), [storage-model.md](docs/storage-model.md).
- **Migration:** UBT CPT posts (`bws_user_term_rule`) → rows appended to `term_rules` with the appropriate `type` (dry-run). Note UBT carries its own `priority` field — map it onto **list position**, since position is now the ordering mechanism.
- Port UBT rule-engine / applicator / cache / ACF-integration into an MC handler extending `UnifiedHandlerBase`, exposing `apply_to_post()` like every other handler — it registers **no hooks of its own** (the dispatcher owns them, per ADR 0003). Drop the UBT CPT editor in favor of the Wireframe panel.
- ⚠️ **Type-key name unsettled**: `.claude/plans/ubt-merger.md` proposes `user_based_terms_rules`; `docs/future-features.md` and `docs/storage-model.md` say `user_based_rules`. Under the unified list this is a `type` value, not an option key — settle it when building.
- Largest integration; tackle last.

**End of phase**: Update CLAUDE.md

---

## Storage Model Decision Framework

**Moved → [docs/storage-model.md](docs/storage-model.md).** That doc is the source of truth + working doc for options-vs-CPT, the decision criteria, the per-type assignments table, concurrency/clobber preventions, the indirection escape hatch, and the Wireframe-page storage boundary.

**Run every new rule type through it before implementing.**

Key reassessments since the original inline framework (2026-06-23):
- `user_based_rules` (UBT): **CPT → Options** — role/user is the *target*, not the owner → single author, no concurrent writes; per-user explosion solved by indirection (profile field + one rule). See [ubt-merger plan](.claude/plans/ubt-merger.md).
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
