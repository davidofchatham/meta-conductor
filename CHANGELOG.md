# Changelog

All notable changes to Meta Conductor (formerly BWS Meta Manager, formerly BWS Taxonomy Manager) are documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
