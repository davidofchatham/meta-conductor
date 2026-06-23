# Meta Conductor: Roadmap

**Current Version**: 0.4.3 (pre-release; `0.x` unstable until `1.0.0`)
**Target Version**: 1.0.0 (first production-ready cut, after rename + PSR-4 namespacing land)
**Branch**: `main`

The refactor is an **incremental migration, not a rewrite** — the core business logic works; the remaining work is structural (finish the unified-framework handler migration, complete the rename, PSR-4 namespacing, config page split). This document tracks the phased plan and the decisions behind it.

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
| **3** | Migrate 5 legacy handlers → UnifiedHandlerBase — **1 of 5 done** (Related, 0.4.0/0.4.1); **▶ NEXT: step 2 (level-restriction)** | ◐ partial | 2a ✅ | 4 of 7 handlers still on legacy `BWS_Handler_Base`; `BWS_Rule_Engine` unused by legacy handlers |
| 2b | Rename sweep — *user-facing rename done* (folder, file, header, option key, menu/title/H1); only code-internal strings left (`__()` sweep, constants, hooks, JS) | ◐ partial | 2a ✅ | mixed text domains |
| 4 | Config page split (storage blast-radius) — *was CPT storage; CPT deferred* | queued | 3 | one-blob clobber radius; per-page autoload; gives UBT its own option |
| 7 | Unified migration / preview tool | queued | — (ungated; can run anytime) | `lib/` classes instantiated but never called; tab-aware save bug; Conversion subpage taxonomy selectors |
| 6a | Options-compatible integrations | queued | 3 | — |
| 6b | BWS User Based Terms (→ Options, Personalize page) | queued | 4 | UBT merge; needs Personalize page option from P4 |
| ~~5~~ | ~~Settings refactor~~ | cancelled | — | absorbed by 2c; lib delegation folded into 7 |

**Recommended run order:** ~~2a~~ ✅ → **3 (finish steps 2–5)** → 2b → 4 → (6a, 7) → 6b. Phase 3 before 2b so the rename sweep touches already-migrated handlers once. Phase 7 is unblocked and can slot in whenever Conversion is needed.

Live defects not yet scheduled to a phase are tracked under each phase section's **Known issues**; the "Open items it closes" column above is the at-a-glance index.

---

## Confirmed Decisions

Status column: ✅ = actioned · Pn = pending in that phase · standing = ongoing policy.

| Decision | Choice | Status | Notes |
|----------|--------|--------|-------|
| **Plugin name** | **Meta Conductor** | ✅ | Display name and slug both drop "BWS". See "Naming surface" table below. |
| **Naming surface** | Split by layer | ◐ partial (P2b) | Folder/main-file/text-domain done; constants, hooks, JS, `__()` sweep remain in 2b. |
| **PSR-4 namespacing** | Yes | ✅ (0.4.0) | Custom `spl_autoload_register()` autoloader (root `autoload.php`); namespace `BWS\MetaConductor\` |
| **Abstracts directory** | Co-locate with implementations | ✅ (0.4.0) | `Storage\RuleStorage`, `Handlers\UnifiedHandlerBase` — `includes/abstracts/` eliminated |
| **Interface file naming** | Use `class-` prefix for all | ✅ (0.4.0) | Autoloader generates `class-{name}.php`; interfaces follow same convention |
| **lib/ classes** | Absorb into `Support\` namespace | ✅ (0.4.0) | BatchProcessor, FieldConverter, ValueMapper, TermMigrator → `includes/support/` (renamed from `lib/` to avoid collision with vendored `libs/`; not `Conversion\` as originally planned) |
| **lib/ integration** | Complete in Phase 7 | P7 | `Conversion\DataProcessor` delegates to `Support\` classes during the migration-tool build (was Phase 5, cancelled). |
| **Conversion tool** | Keep in this plugin | ✅ decided | Operates on same entities/fields |
| **CPT vs options** | Options + page split; CPT deferred | ✅ reassessed (2026-06-23) | Storage choice is **per Wireframe page**, not per rule type. Page split (P4) splits the blob; CPT only if a type needs a draft/test lifecycle. See [storage-model.md](docs/storage-model.md). |
| **CPT structure** | Deferred | — | `bws_mc_rule` shared-CPT design preserved in storage-model.md if/when a type needs it. Not scheduled. |
| **Config storage boundary** | Wireframe page = `option_key` | P4 | Split 5 tabs → 4 pages → 4 options. Rule-type → option_key router. See [config-pages-split plan](.claude/plans/config-pages-split.md). |
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
| Plugin constants | `META_CONDUCTOR_*` (new) + `BWS_META_MANAGER_*`, `BWS_TAX_MANAGER_*` (back-compat aliases) | Code-internal; drop prefix on canonical names, keep aliases |
| PHP namespace | `BWS\MetaConductor\` | Collision safety in autoloaded global namespace |
| Option keys | `bws_meta_conductor_*` | Collision safety in shared `wp_options` table |
| Nonce action prefix | `bws_meta_conductor_*` | Pairs with option keys |
| JS localized object | `bwsMetaConductor` | Pairs with PHP constants/namespace |
| Hook/filter prefix | `bws_meta_conductor_*` | Consistent with stored data + JS |

**Rule of thumb**: anything users / translators / the WP admin UI sees → drop `BWS`. Anything stored in a global PHP/JS/DB namespace where another plugin could collide → keep `BWS`.

---

## Phased Roadmap

### ✅ Phase 0: Title/Slug Rules + Conversion Tooling (COMPLETED)

Pre-refactor feature work landed on this branch.

- Title/Slug Rules: new rule type with token engine, idempotency, slug collision avoidance, preview, bulk apply, full settings UI. First handler on `BWS_Unified_Handler_Base`.
- ACF Conversion Tooling: ConversionManager, DataProcessor, FieldMapper, PreviewSystem, ConversionCLI + dedicated JS/CSS.
- Propagation Handler: term-removal propagation to children.
- Generic AJAX rule helpers built on storage abstraction.

### ✅ Phase 1: Fix Bugs (COMPLETED — commit `f16091e`)

1. ✅ Fix nonce mismatch in `class-bws-conversion-ui.php::verify_ajax_request()`
2. ✅ Fix option key in `class-bws-unified-handler-base.php:305`
3. ✅ Move `test-conversion-integration.php` → `debug/test-conversion-integration.php`

---

### ✅ Phase 2c: Wireframe UI Swap (COMPLETED — branch `claude/wireframe-swap-2c`)

Full admin UI replacement. Hand-rolled settings UI (~5,000 lines across `class-bws-settings.php`, `admin.js`, `admin.css`) replaced by WP Wireframe (`tdrayson/wp-wireframe ~1.0.5`).

**Completed:**
- Wireframe boots under top-level `meta-conductor` menu; settings save via REST to `bws_meta_conductor_settings`
- Per-tab config builders for all 7 rule types + General, organized into 5 user-facing tabs (Auto-Set Terms, Format & Transform, Restrict, Personalize by User, General)
- Data Conversion promoted to subpage under Meta Conductor menu
- Diagnostics dev subpage (gated on `WP_DEBUG`)
- Storage option key migrated from `bws_taxonomy_manager_settings` to `bws_meta_conductor_settings`
- `normalize_rule_shape()` canonical-shape adapter in storage layer
- Hierarchical handler rewritten: flat-field processing, auto-term tracking via `_bws_auto_terms` post meta, promotion logic for user-kept terms
- Related handler fix: spurious target-term removal on unrelated saves
- `should_process_post()` checkbox normalization for Wireframe's `{slug: bool}` format
- Title/Slug handler: hybrid pre-write processing, date escalation fixes, engine bypass
- Dead AJAX methods removed (6 methods, ~250 lines)
- Legacy `admin.js` and `admin.css` deleted; `class-bws-settings.php` reduced to ~60-line compat shell
- Doc reorganization: README.md, readme.txt, docs/architecture.md, docs/future-features.md
- Subpage padding workaround for Wireframe body class bug (upstream: wp-wireframe#6) — **removed in 1.0.6 upgrade**

**Descoped / deferred:**
- Custom client-side field types (`bws_wp_select`, `bws_action_button`) — Wireframe has no client-side extension API. Replaced with stock selects + server-side option builders.
- Title/Slug inline Preview/Apply buttons → Phase 7 Migration tool
- Subfield conditional visibility → **unblocked in Wireframe 1.0.6 (#13)**; conversion of description-text workarounds to real `conditions` queued (see docs/future-features.md)

**Known issues:**
- Conversion subpage: taxonomy selectors not populating (AJAX endpoints likely broken under new menu structure). Resolve in Phase 7 migration tool rewrite or earlier if Conversion is needed before then.
- `BWS_Option_Rule_Storage::update_settings()` does a blunt top-level `array_merge`. If a legacy handler writes `['hierarchical_rules' => $rules]` it clobbers every other rule array. Live code path for 5 of 7 handlers. Resolve when those handlers migrate to `BWS_Unified_Handler_Base` in Phase 3.
- Hierarchical handler `$this->processed` accumulates indefinitely within a request and silently skips legitimate double-saves. Replace with a clear-after-apply pattern in Phase 3 when the handler is touched again.

**Untested on InstaWP:**
- Propagation, Level Restriction, Related Post Terms handler runtime

**Plan file:** deleted post-ship; see commit history on `claude/wireframe-swap-2c` and PR #17.

---

### ✅ Phase 2c review pass — 0.3.1 (COMPLETED — commit `4f1a439`)

Correctness fixes from the PR #17 review (full list in CHANGELOG `[0.3.1]`). Roadmap-relevant carry-overs:

- Established the **site-time invariant** (`{pub_*}` tokens bound to `wp_timezone()`) — later locked for Temporal rules (CONTEXT.md → *Site time*); the shared `parse_date_value` pull-up in the Temporal work must honor it.
- `TODO(Phase 3)` markers added to all 5 legacy `BWS_Handler_Base` handlers flagging dual-base divergence — visible debt for the Phase 3 migration below.

---

### Phase 2a: PSR-4 Namespacing ✅ DONE (branch `claude/psr4-2a`)

Pure structural change — no behavior changes, no user-visible changes. Independently revertable (single merge). **Static verification (lint + autoload-resolution harness) green; behavior parity pending the manual InstaWP sweep before merge.**

- ✅ `autoload.php` at plugin root, adapted from a sibling BWS plugin's loader (`BWS\MetaConductor\`, `BWS_META_CONDUCTOR_PATH`)
- ✅ `autoload.php` required in main file; all 12 manual `require_once includes/*` lines removed
- **Namespace structure**:
  - `Core\` → `includes/core/`, `Handlers\` → `includes/handlers/` (incl abstract bases), `Storage\` → `includes/storage/` (incl interface), `Conversion\` → `includes/conversion/`, `Admin\` + `Admin\Config\` → `includes/admin/` + `/config/`
  - **`Support\` → `includes/support/`** — DIVERGENCE from original plan: the `includes/lib/` reusable modules (BatchProcessor, TermMigrator, FieldConverter, ValueMapper + interfaces) became their own top-level `Support\` namespace, NOT absorbed into `Conversion\`. They are interface-driven and zero-coupled, so the namespace advertises that. `lib/` renamed to `support/` to avoid collision with vendored `libs/` (PUC).
- ✅ `includes/abstracts/` + `includes/lib/` eliminated; bases co-located in `handlers/`/`storage/`; `HandlerBase` stays until Phase 3
- ✅ Files `class-{kebab}.php`, classes drop `BWS_`, `use`/FQN for cross-ns refs
- **Naming**: `CptRuleStorage` not `CPTRuleStorage`; acronyms `Acf`/`Cli`/`Ui` (kebab converter breaks on consecutive caps); interface files use `class-` prefix

**Discoveries (logged as SPEC §V/§B, carry into Phase 2b/3):**
- **§V12** — `namespace` must be the FIRST statement, *before* the `if(!defined('ABSPATH'))exit;` guard (only `declare()` may precede). Namespace-after-guard = php -l fatal.
- **§V13** — under a namespace, every GLOBAL class ref must be leading-backslash qualified (`new \WP_Query`, `catch (\Exception`, `\WP_CLI::`, `new \DateTime`). Unqualified resolves into the plugin namespace → RUNTIME fatal, invisible to `php -l` AND the autoload harness. Global *function* calls (`get_post`, `__`) are fine — PHP auto-falls-back functions, not classes.
- **Harnesses** (`tests/`, export-ignored): `verify-autoload.php` (H2) asserts all 47 FQNs resolve with no WP boot; `lint.php` (H1) is a `php -l` sweep. Reusable pattern for Phase 2b/3.

**End of phase**: ✅ CLAUDE.md updated. Merge gated on user's manual InstaWP behavior sweep.

---

### Phase 2b: Rename & Branding

Visible change — rename the plugin, migrate the option key, update all strings.

Follow the **Naming Surface (0.x)** table in the decisions section above for which layers drop `bws-` and which keep `bws_`/`BWS\`.

> **Largely landed early:** the user-facing rename is effectively done — folder, main file, header, option key, menu/page titles, and settings H1 all shipped across Phase 2c + the release-infra branch (some ahead of schedule to unblock the self-hosted release pipeline). **Remaining 2b work** is code-internal: the ~600-call `__()` text-domain sweep (Wireframe args still pass `'bws-meta-manager'`), constant aliases, nonce/hook prefix rename, JS object rename — all gated on Phase 2a (PSR-4) per Hard Constraints.

- ~~Rename plugin folder: `bws-meta-manager` → `meta-conductor`~~ ✅ done (repo, GitHub, local dev folder, test-site install all renamed)
- ~~Rename main file: `bws-taxonomy-manager.php` → `meta-conductor.php`~~ ✅ done
- ~~Update plugin header: `Plugin Name: Meta Conductor`, `Text Domain: meta-conductor`~~ ✅ done
- ~~Rename option key: `bws_taxonomy_manager_settings` → `bws_meta_conductor_settings`~~ ✅ done in Phase 2c (Wireframe boots against the new key; tested on InstaWP)
- ~~Update admin menu label and page title~~ ✅ done in Phase 2c (`class-wireframe-bootstrap.php` `page_title`/`menu_title`/`menu_slug`)
- ~~Update settings page H1~~ ✅ done in Phase 2c (`class-wireframe-config.php` `title`)
- Update all nonce action strings to `bws_meta_conductor_*` pattern
- Unify text domain to `meta-conductor` throughout (~600 calls to convert) — **the bulk of remaining 2b work.** Note: Wireframe `__()` args still pass `'bws-meta-manager'`.
- Update constants to `META_CONDUCTOR_*` (keep backward-compat aliases for `BWS_TAX_MANAGER_*` and `BWS_META_MANAGER_*`)
- Update JS localized object key to `bwsMetaConductor` in PHP enqueue
- Update hook/filter prefix to `bws_meta_conductor_*` (paired with option keys)

**Files**: `meta-conductor.php`, `includes/class-taxonomy-manager.php`, `includes/class-settings.php`, `includes/storage/class-option-rule-storage.php`, `includes/handlers/class-unified-handler-base.php`, plus every file containing `__()` / `_e()` / `_x()` / `_n()` calls (paths post-2a)

**End of phase**: Keep version on the `0.x` line; graduate to `1.0.0` only when production-ready. Update CLAUDE.md

---

### Phase 3: Migrate Legacy Handlers (One at a Time)

Migrate each handler from `BWS_Handler_Base` to `UnifiedHandlerBase`. Template: `includes/handlers/class-hierarchical-handler.php`.

**Order** (simplest first):
1. ~~`class-related-handler.php`~~ ✅ **done** (branch `claude/related-multi-pt-3a`) — also gained multi-post-type support (single `post_type` select → shared `post_types` checkboxes via `ConfigHelpers::post_types_field()`). Ported 4 term-utility helpers (`apply_terms_to_post`, `remove_terms_from_post`, `post_has_terms`, `debug_log`) from `BWS_Handler_Base` → `UnifiedHandlerBase`; **steps 2–5 now inherit these from the new base** rather than the legacy one.
2. `class-hierarchical-level-restriction-handler.php`
3. `class-propagation-handler.php`
4. `class-related-post-terms-handler.php`
5. `class-time-based-handler.php`

**For each**: Change `extends` → implement `get_rule_type()` + `get_handler_type()` → replace `process_post()` with `init_hooks()` → replace direct settings reads with `$this->get_enabled_rules()` → test on InstaWP before next.

**After last handler**:
- Delete `class-handler-base.php` (`BWS_Handler_Base`).
- Remove `on_post_save()` loop in `class-taxonomy-manager.php` — it calls `process_post()` on every handler, but unified-base handlers register their own hooks and don't need it. Currently causes `process_post()` no-op overrides in hierarchical + title_slug handlers to prevent the base class from routing flat Wireframe rules through `BWS_Rule_Engine`.

**End of phase**: Update CLAUDE.md

---

### Phase 4: Config Page Split (storage blast-radius)

**Replaces the former "Implement CPT Storage" phase** (2026-06-23 reassessment). CPT storage is **not** a scheduled deliverable — it stays a deferred option only for a rule type that genuinely needs a draft/test lifecycle. The real priority is splitting the single Wireframe settings page into multiple pages so the options blob splits with it. Rationale + tradeoffs: [docs/storage-model.md](docs/storage-model.md). Full plan: [config-pages-split plan](.claude/plans/config-pages-split.md).

**Why this instead of CPT:** Wireframe binds one `option_key` per page → all rule types currently share one blob, one save rewrites everything, cross-type clobber is possible. Splitting tabs → pages shrinks the save blast radius, gives per-page autoload control, and lets each page choose its storage independently later — delivering most of CPT's write-isolation benefit at near-zero cost, no new storage engine. UBT no longer needs CPT (role/user = target, not owner → single author; per-user data → profile field + one indirection rule).

**Pages (4 — Restrict merges into Auto-Set; they interact):**

| Page | `option_key` | Hosts |
|------|-------------|-------|
| Auto-Set & Restrict | `bws_mc_auto_set` | propagation, related_post_terms, time_based, related, hierarchical, level_restriction |
| Format & Transform | `bws_mc_format` | title_slug, (future field_transformation) |
| Personalize by User | `bws_mc_personalize` | user_based (UBT lands here) |
| General | `bws_mc_general` | conflict_handling, manual_processing globals |

**Work:**

- Split `WireframeConfig::build()` into 4 page-config composers; `WireframeBootstrap::boot` `pages[]` gains 4 entries (reuse existing `*Config::section()` classes — just regroup which page hosts them; in-page **tabs** separate rule types).
- Add a **rule_type → option_key router** (the job `Storage\StorageFactory` was meant to own). `OptionRuleStorage::get_all_settings()` resolves the right page option per type; cross-type reads (`search_rules`, diagnostics, export/import) iterate the N page options.
- Rescope save-payload hooks (`snapshot_related_labels` → Auto-Set page save).
- **Migration:** one-time fan-out of the single `bws_meta_conductor_settings` blob → 4 page options, dry-run first, old key readable during transition, delete after verify.
- Re-run H1 (lint) + H2 (autoload harness) after class moves.

**Deferred (not this phase):** CPT storage (`class-cpt-rule-storage.php`, `bws_mc_rule` CPT). Revisit only if a type needs a draft/test lifecycle — see storage-model.md. Lost-update clobber, if concurrent authoring ever appears, is handled by a version-token guard on the page blob (cheaper than CPT), not by this phase.

**End of phase**: Update CLAUDE.md

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

**End of phase**: Update CLAUDE.md, drop legacy Data Conversion submenu in favor of the unified one.

---

### Phase 6a: Options-Compatible Integrations (After Phase 3)

These do not require CPT storage.

**ACF Post Relationship Manager**
- Sets hierarchical parent/child post relationships based on ACF post object/relationship fields
- Distinct from `related_post_terms_rules`: same data source (ACF relationship field), different output (post parent vs taxonomy terms)
- New rule type `acf_relationship_rules` → Options storage

**Date-Based Taxonomy Updater** — *folded into the in-flight Temporal State Rule (0.x), not a separate type.* The per-post ACF-date comparison this described is now an Options-storage extension of `time_based_rules`. See docs/future-features.md → `time_based_rules`.

**Field Transformation Rules** (from existing snippet)
- Combines multiple fields into a formatted output field (e.g. athlete stats → bio string, date + time → sortable datetime)
- New rule type `field_transformation_rules` → CPT storage (requires Phase 4)

**End of phase**: Update CLAUDE.md

---

### Phase 6b: BWS User Based Terms (Requires Phase 4 page split)

- User-based term setting as a new rule type, landing on the **Personalize page** (its own `bws_mc_personalize` option — created in Phase 4).
- Stored in **options** (not CPT): role/user = *target*, not owner → single author. Per-user customization → **profile ACF field + one indirection rule**, not N per-user rules. See [ubt-merger plan](.claude/plans/ubt-merger.md), [storage-model.md](docs/storage-model.md).
- Migration: UBT CPT posts (`bws_user_term_rule`) → MC Personalize-page option array (dry-run).
- Port UBT rule-engine / applicator / cache / ACF-integration into an MC handler extending `UnifiedHandlerBase`; drop the UBT CPT editor in favor of the Wireframe panel.
- Largest integration; tackle last.

**End of phase**: Update CLAUDE.md

---

## Storage Model Decision Framework

**Moved → [docs/storage-model.md](docs/storage-model.md).** That doc is the source of truth + working doc for options-vs-CPT, the decision criteria, the per-type assignments table, concurrency/clobber preventions, the indirection escape hatch, and the Wireframe-page storage boundary.

**Run every new rule type through it before implementing.**

Key reassessments since the original inline framework (2026-06-23):
- `user_based_rules` (UBT): **CPT → Options** — role/user is the *target*, not the owner → single author, no concurrent writes; per-user explosion solved by indirection (profile field + one rule). See [ubt-merger plan](.claude/plans/ubt-merger.md).
- `title_slug_rules` / `time_based_rules`: **CPT (Phase 4) re-opened** — no concurrent authoring; page-split covers blast radius. Options unless a real draft/test lifecycle is wanted.
- Storage choice is **per Wireframe page**, not per rule type — see [config-pages-split plan](.claude/plans/config-pages-split.md).

---

## Critical Files Reference

| File | Role | Phase |
|------|------|-------|
> Paths reflect post-2a reality (PSR-4 done in 0.4.0): no `BWS_` prefix, kebab `class-{name}.php`, `BWS\MetaConductor\` namespace. `meta-conductor.php` main-file rename landed in 2c.

| File | Role | Phase |
|------|------|-------|
| `includes/handlers/class-unified-handler-base.php` | Shared handler base; gains migrated handlers | 3 |
| `meta-conductor.php` | Main file (renamed 2c); constants/hooks `__()` sweep | 2b |
| `includes/class-taxonomy-manager.php` | Menu registration, AJAX hooks | 2b |
| `includes/class-settings.php` | ~60-line compat shell; deletable after Phase 3 | 2b, 3 |
| `includes/storage/class-option-rule-storage.php` | Option storage; gains rule_type → option_key routing | 4 |
| `includes/storage/class-storage-factory.php` | Rule-type → option_key router (page split) | 4 |
| `includes/storage/class-rule-storage.php` | RuleStorage interface | 4 |
| `includes/handlers/class-hierarchical-handler.php` | Migration template | 3 |
| `includes/handlers/class-title-slug-handler.php` | Unified handler (Phase 0) | — |
| `includes/conversion/class-data-processor.php` | Delegates to `Support\` classes during the tool build | 7 |
| `assets/js/conversion-admin.js` | Conversion JS | 7 |

---

## Hard Constraints

- ~~Don't start Phase 2a until title/slug handler testing is complete on InstaWP~~ (Phase 2c completed; Title/Slug tested)
- ~~Don't start Phase 2b until Phase 2a is stable on InstaWP~~ (2a done in 0.4.0; static-verified H1+H2, InstaWP sweep done)
- Don't start Phase 6a integrations until Phase 3 handler migration is done
- Don't start `field_transformation_rules` until its storage is decided via [storage-model.md](docs/storage-model.md) (likely Options + indirection; CPT only if a per-recipe lifecycle is needed)
- Don't start Phase 6b (UBT) until Phase 4 page split is done (UBT needs the Personalize page option)
- Don't refactor BWS_Settings until handler migration is done (cleaner split once handlers own their logic)
- Update CLAUDE.md at the end of every phase
