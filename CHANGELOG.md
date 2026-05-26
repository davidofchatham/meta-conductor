# Changelog

All notable changes to BWS Meta Manager are documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Title & Slug Rules** — new rule type that customizes post titles and slugs from a pattern of tokens (`{meta:field}`, `{default_title}`, `{default_slug}`, `{date_year|month|day|hour|minute:field}`, `{pub_*}`, `{term:tax}`, `{terms:tax}`).
  - Token engine with separator auto-trimming for empty tokens (no dangling `(` / `:` / `-`) and a duplicate-insertion guard that skips tokens whose value already appears in the base title or slug.
  - Slug modes: `replace`, `prefix`, `suffix`. Smart UI default locks to `replace` when pattern contains `{default_slug}`.
  - Slug collision avoidance with optional date escalation ladder (year → month → day → hour → minute) before falling back to `wp_unique_post_slug()`.
  - Idempotency on re-save via `_bws_raw_title` / `_bws_applied_title` postmeta with inverse-strip recovery when the user edits the computed title.
  - Hook timing avoids double-application and respects ACF: captures raw title at `wp_insert_post_data` (priority 1), processes at `acf/save_post` (99), falls back to `save_post` (99) without ACF.
  - Single `wp_update_post()` call writes title and slug together; suppresses duplicate revisions.
  - Preview AJAX endpoint and bulk apply-to-existing endpoint with batched progress.
  - Settings UI: tab with template-based rule rows, conditional slug-mode/escalation visibility, token reference table, up/down reorder, preview modal, apply-to-existing progress.
  - Last-applied status and capped warnings log stored in `bws_title_slug_rule_status` option.
- **`BWS_Title_Slug_Handler`** — first handler properly built on `BWS_Unified_Handler_Base` (the legacy `BWS_Handler_Base` handlers will migrate in a future release).
- **ACF Conversion Tooling** — new `includes/conversion/` module (ConversionManager, DataProcessor, FieldMapper, PreviewSystem, ConversionCLI) plus dedicated `conversion-admin.css` / `conversion-admin.js`.
  - AJAX endpoints: `get_fields`, `get_taxonomies`, `get_taxonomy_terms`, `get_options`, `estimate_size`, `process_chunk`, `process`, `preview`.
- **Conversion Infrastructure (`includes/lib/`)** — BatchProcessor, FieldConverter, ValueMapper, TermMigrator with interfaces. Slated for absorption into the `Conversion\` namespace in a future refactor.
- **Generic rule AJAX helpers** — `ajax_toggle_rule_enabled` and `ajax_delete_rule` on the main manager class, both built on the storage abstraction.
- **Propagation Handler: term removal propagation** — `on_parent_terms_set()` now diffs `$old_tt_ids` against `$tt_ids` and removes terms from children before re-applying current terms. New `propagate_term_removals_to_children()`.

### Changed
- Admin CSS overhaul for rule list, header chrome, preview modal, and reorder buttons.
- `.gitignore` now ignores `CLAUDE.md`, `*.code-workspace`, and `/.claude` (the previously tracked `.claude/settings.local.json` remains tracked).
- Strategic roadmap consolidated into `.claude/plans/majestic-finding-owl.md`.

### Removed
- `ARCHITECTURE_DECISION_CPT_VS_OPTIONS.md` — superseded by the per-rule-type storage decision framework in the strategic roadmap.
- `TESTING_PLAN_V2.md` — superseded by per-feature plans under `.claude/plans/`.

### Fixed
- (See 2.0.0 — bundled in that release.)

## [2.0.0] — 2025-12-18

### Added
- **Unified Framework** — `BWS_Entity` polymorphic wrapper over posts, terms, users, comments; `BWS_Rule_Engine` orchestrator; `BWS_Condition_Evaluator` and `BWS_Action_Executor`.
- **Storage Abstraction Layer** — `BWS_Rule_Storage` interface (15 methods), `BWS_Option_Rule_Storage` (wp_options implementation), `BWS_Storage_Factory` with migration tooling. Prepares the plugin for a CPT-backed storage backend.
- **Six database tables** created on activation, with validation and an admin notice on failure: `wp_bws_meta_manager_log`, `wp_bws_acf_conversion_preview`, `wp_bws_acf_conversion_sessions`, `wp_bws_relationship_log`, `wp_bws_batch_queue`, `wp_bws_taxonomy_manager_log`.
- **`BWS_Unified_Handler_Base`** — abstract base for v2 handlers with storage abstraction methods (`get_enabled_rules`, `get_all_rules`, `get_rule`, `save_rule`, `delete_rule`).
- **Hierarchical Handler** rewrite with smart child expansion (3 explicit modes) and bidirectional hierarchy propagation.
- **PHP 8.1 enforcement** at activation, on `plugins_loaded`, and via syntax usage; deactivates with notice on older versions.

### Changed
- Branding migration toward "Meta Manager": new constants `BWS_META_MANAGER_*` alongside backward-compatible `BWS_TAX_MANAGER_*` aliases for third-party code.
- Settings UI labels and descriptions reworked from user feedback.
- Expansion behavior simplified to three clear options.

### Fixed
- Nonce mismatch in conversion AJAX (`verify_ajax_request()` now aligns with the nonce action the JS actually sends).
- Option-key bug in `BWS_Unified_Handler_Base::log_results()` that prevented the logging setting from ever being read.
- Term dropdown loading for time-based rules (pre-load on rule add, proper Select2 refresh, nonce passing).

### Removed
- Relocated `test-conversion-integration.php` into `debug/` and out of the plugin runtime path.

## [1.x]

Pre-2.0 history is not catalogued in this changelog. See `git log` prior to commit `08bce63` ("Phase 1: Unified Framework Foundation").
