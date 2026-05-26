# Meta Conductor: Strategic Assessment & Roadmap

**Current Version**: 2.0.0 (unreleased; never deployed)
**Target Version**: 2.0.0 (absorbing Wireframe swap + rename + PSR-4 namespacing before first deploy)
**Branch**: `claude/wireframe-swap-2c`

## Context

The plugin has accumulated friction: incomplete branding rename, a bolted-on conversion tool, only 2 of 7 handlers using the "unified" framework, a ~2,000-line god-class settings file, and an unresolved storage architecture question. This document captures the assessment and agreed decisions for moving forward.

---

## Verdict: Incremental Major Refactor — Not a Rewrite

The core business logic is **working and worth keeping**. The problems are all structural (incomplete migration, naming chaos, god class) rather than algorithmic. The unified framework (Entity, RuleEngine, storage abstraction) is well-designed and worth finishing — it stalled at 2 of 7 handlers.

---

## Confirmed Decisions

| Decision | Choice | Notes |
|----------|--------|-------|
| **Plugin name** | **Meta Conductor** | Display name and slug both drop "BWS". See "Naming surface" table below. |
| **Naming surface** | Split by layer | Plugin folder + main file + text domain drop `bws-`; PHP namespace and option keys keep `BWS`/`bws_` for collision safety. |
| **PSR-4 namespacing** | Yes — Phase 2a | Custom `spl_autoload_register()` autoloader; namespace `BWS\MetaConductor\`; pattern from BWS Portal plugin |
| **Abstracts directory** | Co-locate with implementations | `Storage\RuleStorage`, `Handlers\UnifiedHandlerBase` — `includes/abstracts/` eliminated |
| **Interface file naming** | Use `class-` prefix for all | Autoloader generates `class-{name}.php`; interfaces follow same convention |
| **lib/ classes** | Absorb into `Conversion\` namespace | BatchProcessor, FieldConverter, ValueMapper, TermMigrator move to `includes/conversion/` |
| **lib/ integration** | Complete in Phase 5 | BWS_Data_Processor delegates to lib classes during conversion cleanup |
| **Conversion tool** | Keep in this plugin | Operates on same entities/fields |
| **CPT vs options** | Per-type routing | Storage factory routes by rule type; see framework below |
| **CPT structure** | Single shared CPT: `bws_mc_rule` | Differentiated by `rule_type` meta field; one list table filterable by type |
| **Plugin file rename** | Yes | All installs are controlled |
| **Option key rename** | Yes — with data migration, test on InstaWP first | New key: `bws_meta_conductor_settings` |
| **Handler migration order** | Simplest first | Related → Level Restriction → Propagation → Related Post Terms → Time Based |
| **Legacy BWS_Handler_Base** | Delete after last handler migrates | No deprecation shim needed — private plugin |
| **Tab-aware save bug** | Fix during Phase 5 settings refactor | Latent, not actively causing loss |
| **CLAUDE.md updates** | End of each phase | Reflects completed architecture, not planned work |
| **Version number** | 2.0.0 | Breaking changes: file rename, class names, option key |

### Naming Surface (2.0.0)

Split the rename by layer — public-facing identity drops `BWS`, code/storage layers keep `BWS`/`bws_` namespace for collision safety.

| Layer | Value (2.0.0) | Rationale |
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

## What Is Actually Broken

### MESSY — Not Breaking Runtime Behavior

| Issue | Addressed In |
|-------|-------------|
| Admin menu/page still says "Taxonomy Manager" | Phase 2b |
| 5 of 7 handlers on legacy BWS_Handler_Base | Phase 3 |
| BWS_Settings is a ~2,000-line god class | Phase 5 |
| Mixed JS globals (bwsTaxManager / bwsMetaManager) | Phase 5 |
| Mixed text domains | Phase 2b |
| BWS_Rule_Engine unused by legacy handlers | Phase 3 |
| lib/ classes instantiated but never called | Phase 5 |

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

**Gate for Phase 2**: Title/slug handler testing must be complete on InstaWP before starting Phase 2a.

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
  - `BWS\MetaConductor\Admin\` → `includes/admin/` (new directory, for Phase 5 settings split)
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

Follow the **Naming Surface (2.0.0)** table in the decisions section above for which layers drop `bws-` and which keep `bws_`/`BWS\`.

- Rename plugin folder: `bws-meta-manager` → `meta-conductor`
- Rename main file: `bws-taxonomy-manager.php` → `meta-conductor.php`
- Update plugin header: `Plugin Name: Meta Conductor`, `Text Domain: meta-conductor`
- Rename option key: `bws_taxonomy_manager_settings` → `bws_meta_conductor_settings`
  - Add data migration in activation/upgrade hook: read old key → write new key → delete old key
  - Test on InstaWP site before deploying
- Update all nonce action strings to `bws_meta_conductor_*` pattern
- Update admin menu label and page title
- Update settings page H1
- Unify text domain to `meta-conductor` throughout (~600 calls to convert)
- Update constants to `META_CONDUCTOR_*` (keep backward-compat aliases for `BWS_TAX_MANAGER_*` and `BWS_META_MANAGER_*`)
- Update JS localized object key to `bwsMetaConductor` in PHP enqueue
- Update hook/filter prefix to `bws_meta_conductor_*` (paired with option keys)

**Files**: `meta-conductor.php`, `includes/class-bws-taxonomy-manager.php`, `includes/class-bws-settings.php`, `includes/storage/class-option-rule-storage.php`, `includes/handlers/class-unified-handler-base.php`, plus every file containing `__()` / `_e()` / `_x()` / `_n()` calls

**End of phase**: Bump version to 2.0.0, update CLAUDE.md

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

**After last handler**: Delete `class-handler-base.php` (`BWS_Handler_Base`).

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

**Date-Based Taxonomy Updater**
- New rule type alongside (not replacing) existing `time_based_rules`
- New rule type `date_based_taxonomy_rules` → CPT storage (requires Phase 4)

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
| `date_based_taxonomy_rules` (new) | **CPT** | Date-window rules accumulate; benefit from list UI |
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

- Don't start Phase 2a until title/slug handler testing is complete on InstaWP
- Don't start Phase 2b until Phase 2a is stable on InstaWP
- Don't start Phase 6a integrations until Phase 3 handler migration is done
- Don't start `date_based_taxonomy_rules` or `field_transformation_rules` (CPT types) until Phase 4 CPT storage is working
- Don't start Phase 6b (UBT) until Phase 4 CPT storage is working
- Don't refactor BWS_Settings until handler migration is done (cleaner split once handlers own their logic)
- Update CLAUDE.md at the end of every phase
