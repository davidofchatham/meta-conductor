# Meta Conductor: Roadmap

**Current Version**: 0.3.1 (pre-release; `0.x` unstable until `1.0.0`)
**Target Version**: 1.0.0 (first production-ready cut, after rename + PSR-4 namespacing land)
**Branch**: `main`

The refactor is an **incremental migration, not a rewrite** — the core business logic works; the remaining work is structural (finish the unified-framework handler migration, complete the rename, PSR-4 namespacing, CPT storage). This document tracks the phased plan and the decisions behind it.

---

## Status board

Phase numbers are **stable IDs, not execution order** — work has landed out of numeric sequence for pragmatic reasons (e.g. the Wireframe swap "2c" and release infra shipped before 2a/2b). This table is the single source of *where we are*; the numbered sections below keep their original IDs so cross-references (code `TODO(Phase N)`, CLAUDE.md, commits, PR #17) stay valid.

| Phase | What | Status | Gated on | Open items it closes |
|-------|------|--------|----------|----------------------|
| 0 | Title/Slug rules + conversion tooling | ✅ done | — | — |
| 1 | Bug fixes | ✅ done | — | — |
| 2c | Wireframe UI swap | ✅ done | — | god-class `BWS_Settings` → 60-line shell; mixed JS globals (legacy JS deleted) |
| 0.3.1 | PR #17 review pass | ✅ done | — | — |
| **2a** | **PSR-4 namespacing** | **▶ NEXT** | — | — |
| 3 | Migrate 5 legacy handlers → UnifiedHandlerBase | queued | 2a | 5 of 7 handlers on legacy `BWS_Handler_Base`; `BWS_Rule_Engine` unused by legacy handlers |
| 2b | Rename sweep — *user-facing rename done* (folder, file, header, option key, menu/title/H1); only code-internal strings left (`__()` sweep, constants, hooks, JS) | ◐ partial | 2a | mixed text domains |
| 4 | CPT storage | queued | 3 | — |
| 7 | Unified migration / preview tool | queued | — (ungated; can run anytime) | `lib/` classes instantiated but never called; tab-aware save bug; Conversion subpage taxonomy selectors |
| 6a | Options-compatible integrations | queued | 3 | — |
| 6b | BWS User Based Terms | queued | 4 | — |
| ~~5~~ | ~~Settings refactor~~ | cancelled | — | absorbed by 2c; lib delegation folded into 7 |

**Recommended run order:** 2a → 3 → 2b → 4 → (6a, 7) → 6b. Phase 3 before 2b so the rename sweep touches already-migrated handlers once. Phase 7 is unblocked and can slot in whenever Conversion is needed.

Live defects not yet scheduled to a phase are tracked under each phase section's **Known issues**; the "Open items it closes" column above is the at-a-glance index.

---

## Confirmed Decisions

Status column: ✅ = actioned · Pn = pending in that phase · standing = ongoing policy.

| Decision | Choice | Status | Notes |
|----------|--------|--------|-------|
| **Plugin name** | **Meta Conductor** | ✅ | Display name and slug both drop "BWS". See "Naming surface" table below. |
| **Naming surface** | Split by layer | ◐ partial (P2b) | Folder/main-file/text-domain done; constants, hooks, JS, `__()` sweep remain in 2b. |
| **PSR-4 namespacing** | Yes | P2a (next) | Custom `spl_autoload_register()` autoloader; namespace `BWS\MetaConductor\`; pattern from BWS Portal plugin |
| **Abstracts directory** | Co-locate with implementations | P2a | `Storage\RuleStorage`, `Handlers\UnifiedHandlerBase` — `includes/abstracts/` eliminated |
| **Interface file naming** | Use `class-` prefix for all | P2a | Autoloader generates `class-{name}.php`; interfaces follow same convention |
| **lib/ classes** | Absorb into `Conversion\` namespace | P2a | BatchProcessor, FieldConverter, ValueMapper, TermMigrator move to `includes/conversion/` |
| **lib/ integration** | Complete in Phase 7 | P7 | `BWS_Data_Processor` delegates to lib classes during the migration-tool build (was Phase 5, cancelled). |
| **Conversion tool** | Keep in this plugin | ✅ decided | Operates on same entities/fields |
| **CPT vs options** | Per-type routing | P4 | Storage factory routes by rule type; see framework below |
| **CPT structure** | Single shared CPT: `bws_mc_rule` | P4 | Differentiated by `rule_type` meta field; one list table filterable by type |
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

### Phase 2a: PSR-4 Namespacing

Pure structural change — no behavior changes, no user-visible changes. Independently revertable.

- Add `autoload.php` to plugin root — adapt from BWS Portal: change `BWS\\Portal\\` → `BWS\\MetaConductor\\`, `BWS_PORTAL_PATH` → `BWS_META_CONDUCTOR_PATH`
- Require `autoload.php` in main plugin file; remove all manual `require_once` chains
- **Namespace structure** (matches existing directories):
  - `BWS\MetaConductor\Core\` → `includes/core/`
  - `BWS\MetaConductor\Handlers\` → `includes/handlers/` (includes abstract base classes)
  - `BWS\MetaConductor\Storage\` → `includes/storage/` (includes interface)
  - `BWS\MetaConductor\Conversion\` → `includes/conversion/` + absorbs `includes/lib/` classes
  - `BWS\MetaConductor\Admin\` → `includes/admin/` (already exists — Wireframe config/bootstrap live here)
- **Abstracts co-located**: `includes/abstracts/` is eliminated
  - `BWS_Unified_Handler_Base` → `includes/handlers/class-unified-handler-base.php` (`Handlers\UnifiedHandlerBase`)
  - `BWS_Rule_Storage` interface → `includes/storage/class-rule-storage.php` (`Storage\RuleStorage`)
  - `BWS_Handler_Base` stays in `includes/handlers/` until deleted at end of Phase 3
- **File renames**: strip `bws-` prefix — `class-bws-hierarchical-handler.php` → `class-hierarchical-handler.php`
- **Class renames**: drop `BWS_` prefix — `BWS_Hierarchical_Handler` → `Handlers\HierarchicalHandler`
- Add `namespace` declaration to each file; add `use` statements where classes reference each other
- **Naming convention**: use `CptRuleStorage` not `CPTRuleStorage` — the autoloader's kebab converter breaks on consecutive capitals
- **Interface files**: use `class-` prefix (same as classes) — autoloader expects `class-{name}.php` for everything

**Files**: all PHP class files, `autoload.php` (new), main plugin file

**End of phase**: Update CLAUDE.md

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

**Files**: `meta-conductor.php`, `includes/class-bws-taxonomy-manager.php`, `includes/class-bws-settings.php`, `includes/storage/class-option-rule-storage.php`, `includes/handlers/class-unified-handler-base.php`, plus every file containing `__()` / `_e()` / `_x()` / `_n()` calls

**End of phase**: Keep version on the `0.x` line; graduate to `1.0.0` only when production-ready. Update CLAUDE.md

---

### Phase 3: Migrate Legacy Handlers (One at a Time)

Migrate each handler from `BWS_Handler_Base` to `UnifiedHandlerBase`. Template: `includes/handlers/class-hierarchical-handler.php`.

**Order** (simplest first):
1. `class-related-handler.php`
2. `class-hierarchical-level-restriction-handler.php`
3. `class-propagation-handler.php`
4. `class-related-post-terms-handler.php`
5. `class-time-based-handler.php`

**For each**: Change `extends` → implement `get_rule_type()` + `get_handler_type()` → replace `process_post()` with `init_hooks()` → replace direct settings reads with `$this->get_enabled_rules()` → test on InstaWP before next.

**After last handler**:
- Delete `class-handler-base.php` (`BWS_Handler_Base`).
- Remove `on_post_save()` loop in `class-bws-taxonomy-manager.php` — it calls `process_post()` on every handler, but unified-base handlers register their own hooks and don't need it. Currently causes `process_post()` no-op overrides in hierarchical + title_slug handlers to prevent the base class from routing flat Wireframe rules through `BWS_Rule_Engine`.

**End of phase**: Update CLAUDE.md

---

### Phase 4: Implement CPT Storage

Required before merging BWS User Based Terms. Also needed for `title_slug_rules` and `time_based_rules` migration from options.

- Implement `includes/storage/class-cpt-rule-storage.php` (implements `Storage\RuleStorage` interface, 15 methods)
- CPT: `bws_mc_rule`, differentiated by `rule_type` meta field — single list table filterable by type
- Update `Storage\StorageFactory` to route CPT-type rule types to CPT implementation, options-type to options implementation
- Build migration tool: options → CPT for `title_slug_rules` and `time_based_rules` (dry-run mode first)
- Test per-type routing with all handlers

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
- Reuses existing infrastructure: `Conversion\BatchProcessor`, `Conversion\TermMigrator`, `Conversion\FieldConverter`, `Conversion\ValueMapper`. Lib class delegation (cancelled Phase 5 carry-over) happens here.

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

### Phase 6b: BWS User Based Terms (Requires Phase 4 CPT)

- User-based term filtering as a new rule type
- Currently uses CPT `bws_user_term_rule` — data migrated into `bws_mc_rule` CPT on merge
- Largest integration; tackle last

**End of phase**: Update CLAUDE.md

---

## Storage Model Decision Framework

**Run every new rule type through this before implementation.**

### Criteria

Choose **CPT** if any of these are true:
- The rule defines a specific named recipe or pattern (not just enabling a behavior)
- Multiple rules of this type can apply to the same post type or taxonomy
- Rules accumulate as the site grows — not bounded by taxonomy/post type count
- Rules benefit from a draft/test/active lifecycle

Choose **Options** if all of these are true:
- The rule enables or configures a behavior for a specific taxonomy or post type
- Count is bounded — roughly one rule per taxonomy or post type
- The rule has no meaningful standalone identity or name
- Managing them in a settings form never becomes unwieldy

### Assignments

| Rule Type | Storage | Reasoning |
|-----------|---------|-----------|
| `hierarchical_rules` | Options | Behavior toggle per taxonomy; bounded count |
| `propagation_rules` | Options | Behavior toggle per post type; bounded count |
| `related_rules` | Options | Cross-taxonomy config; bounded count |
| `hierarchical_level_restriction_rules` | Options | One per taxonomy max |
| `related_post_terms_rules` | Options | ACF sync config; bounded count |
| `acf_relationship_rules` (new) | Options | Parent/child relationship config; bounded count |
| `title_slug_rules` | **CPT** | Named patterns per post type; accumulate; benefit from enable/disable per rule — migrate from options in Phase 4 |
| `time_based_rules` | **CPT** | Schedule rules multiply; benefit from individual management — migrate from options in Phase 4 |
| `field_transformation_rules` (new) | **CPT** | Named computed-field recipes; can be numerous per post type |
| `user_based_rules` (UBT) | **CPT** | User-specific; entity-like; was built on CPT |

> Document storage decision and reasoning here before implementing any new rule type.

---

## Critical Files Reference

| File | Role | Phase |
|------|------|-------|
| `includes/abstracts/class-bws-unified-handler-base.php` | Becomes `includes/handlers/class-unified-handler-base.php` | 2a |
| `bws-taxonomy-manager.php` | Main file; becomes `meta-conductor.php` | 2b |
| `includes/class-bws-taxonomy-manager.php` | Menu registration, AJAX hooks | 2b |
| `includes/class-bws-settings.php` | God class; branding strings | 2b, 5 |
| `includes/storage/class-bws-option-rule-storage.php` | Option key constant | 2b |
| `includes/storage/class-bws-storage-factory.php` | Per-type routing factory | 4 |
| `includes/abstracts/interface-bws-rule-storage.php` | Interface; becomes `includes/storage/class-rule-storage.php` | 2a, 4 |
| `includes/handlers/class-bws-hierarchical-handler.php` | Migration template | 3 |
| `includes/handlers/class-bws-title-slug-handler.php` | New unified handler (Phase 0) | — |
| `includes/conversion/class-bws-data-processor.php` | Delegates to lib classes in Phase 5 | 5 |
| `assets/js/admin.js` | JS namespace unification | 2b, 5 |
| `assets/js/conversion-admin.js` | Separate conversion JS | 5 |

---

## Hard Constraints

- ~~Don't start Phase 2a until title/slug handler testing is complete on InstaWP~~ (Phase 2c completed; Title/Slug tested)
- Don't start Phase 2b until Phase 2a is stable on InstaWP (Phase 2c is done; 2a is next)
- Don't start Phase 6a integrations until Phase 3 handler migration is done
- Don't start `field_transformation_rules` (CPT type) until Phase 4 CPT storage is working
- Don't start Phase 6b (UBT) until Phase 4 CPT storage is working
- Don't refactor BWS_Settings until handler migration is done (cleaner split once handlers own their logic)
- Update CLAUDE.md at the end of every phase
