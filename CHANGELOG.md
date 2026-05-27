# Changelog

All notable changes to Meta Conductor (formerly BWS Meta Manager, formerly BWS Taxonomy Manager) are documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] — Unreleased

> 2.0 absorbs framework, conversion, Title/Slug, Wireframe UI swap, and rename. 2.0 was never shipped to any deployment, so all in-progress work consolidates under the single 2.0 release line.

### Added

#### Unified framework
- **`BWS_Entity`** — polymorphic wrapper over posts, terms, users, comments.
- **`BWS_Rule_Engine`** — orchestrator pipeline (source filters → conditions → targets → actions).
- **`BWS_Condition_Evaluator`** and **`BWS_Action_Executor`**.
- **`BWS_Unified_Handler_Base`** — abstract base for v2 handlers with storage abstraction methods (`get_enabled_rules`, `get_all_rules`, `get_rule`, `save_rule`, `delete_rule`).
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
- **`BWS_Title_Slug_Handler`** — first handler properly built on `BWS_Unified_Handler_Base`. Legacy `BWS_Handler_Base` handlers migrate during 2.0 cycle.

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
- Public identity drops `BWS`: plugin folder, main file, text domain, admin page slug all become `meta-conductor`.
- Collision-safe layers keep `BWS\`/`bws_` prefix: PHP namespace, option keys, nonce action prefix, JS localized object, hook prefix.

### Changed
- Intermediate branding rename from "BWS Taxonomy Manager" to "BWS Meta Manager" to "Meta Conductor". Backward-compatible `BWS_TAX_MANAGER_*` constant aliases for any third-party code.
- Settings UI labels and descriptions reworked from user feedback.
- Expansion behavior simplified to three clear options.
- Admin CSS overhaul for rule list, header chrome, preview modal, and reorder buttons. (Legacy CSS deleted as Wireframe UI completes.)
- Strategic roadmap consolidated into `.claude/plans/i-want-to-switch-lovely-wren.md`.
- `.gitignore` ignores `CLAUDE.md`, `*.code-workspace`, and `/.claude` (the previously tracked `.claude/settings.local.json` remains tracked).

### Fixed
- Nonce mismatch in conversion AJAX (`verify_ajax_request()` now aligns with the nonce action the JS actually sends).
- Option-key bug in `BWS_Unified_Handler_Base::log_results()` that prevented the logging setting from ever being read.
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

## [1.x]

Pre-2.0 history is not catalogued in this changelog. See `git log` prior to commit `08bce63` ("Phase 1: Unified Framework Foundation").
