# Changelog

All notable changes to Meta Conductor (formerly BWS Meta Manager, formerly BWS Taxonomy Manager) are documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.3] — Unreleased

### Changed

- **Phase 2b rename sweep (internal identifiers).** Completes the branding pass begun in 2c:
  - Text domain unified to `meta-conductor` across all `__()`/`_e()` calls (510 args, 29 files). Cosmetic
    (private plugin, no `.po` files) but removes the mixed `bws-meta-manager`/`bws-taxonomy-manager` domains.
  - Plugin constants renamed `BWS_META_MANAGER_*` → `META_CONDUCTOR_*` (`VERSION`/`PLUGIN_DIR`/`PLUGIN_URL`).
    The 3 dead `BWS_TAX_MANAGER_*` defines (zero references) were dropped. No back-compat aliases — no external
    consumer references them.
  - Nonce action `bws_taxonomy_manager_nonce` → `bws_meta_conductor_nonce` (21 sites).
  - Core hooks (rule-engine, condition/action, storage-factory, unified-base) renamed `bws_meta_manager_*` →
    `bws_meta_conductor_*`, including the dynamic `before/after_process_{type}` and `clear_{type}_cache`
    actions and paired transient key. No aliases (no external listeners). Conversion-subsystem hooks
    (cron/AJAX/transients) and the JS localized object deferred to Phase 7.
  - Fixed a latent stale admin-page slug (`bws-meta-manager` → `meta-conductor`) in the conversion tab-URL
    builder.

### Migrated

- **Core log table renamed** `{prefix}bws_meta_manager_log` → `{prefix}bws_meta_conductor_log` via an
  idempotent `RENAME TABLE` in the `admin_init` version-check seam (fires on the 0.6.2→0.6.3 bump; guarded so
  it runs at most once and preserves existing rows). Uninstall now drops all three historical table names.

### Fixed

- **Bulk "process existing posts" now works for the hook-driven handlers (was an inert, over-reporting
  button).** `process_existing_posts()` drove bulk re-apply through `process_post()`, which the hook-driven
  handlers (related, propagation, level-restriction) override as a no-op — so bulk did nothing yet reported
  every scanned post as processed. The base now routes bulk through a new `apply_to_post(int, array): bool`
  primitive that each hook-driven handler overrides from its own per-post logic (level-restriction wires the
  long-kept-ready `apply_level_restrictions()`; related re-uses its add-only `process_related_terms`;
  propagation runs its down/up walk; time-based its date-range apply). `apply_to_post` returns whether the
  post's terms *actually changed* (measured by a before/after taxonomy fingerprint — target-term taxonomy for
  related/time-based, self∪descendants for propagation), and the batch message now reports changed-of-scanned
  per batch, so the count is honest instead of "Processed N of N" while writing nothing. (#31)
- **Propagation: removing a term from a parent now sticks on its descendants.** When a parent carried an ACF
  taxonomy mirror field (the normal case), a down-removal was undone within the same request: the removal
  pass stripped the term from every descendant, then the add pass re-read the parent as
  native∪ACF and — because the parent's ACF mirror still held the pre-removal value — re-pushed the
  just-removed term back onto them. The add pass now excludes the same-request removals from its source, so
  removals persist. Method-independent (`wp_set_object_terms([])` and `update_field([])` both hit it). (#45)
- **Propagation: `wp_remove_object_terms()` on a parent now propagates the removal down.** That function fires
  `deleted_term_relationships`, not `set_object_terms`, so the removal-propagation path was never reached and
  descendants silently kept the term (sibling gap to #45, which fixed the `set_object_terms` path). A new
  `deleted_term_relationships` hook runs the removal walk under the same reentrancy guard. On the plain
  `wp_set_object_terms` path — where WordPress removes dropped terms via an internal `wp_remove_object_terms`
  and both hooks would see the same removal — the delete hook records the handled term-taxonomy IDs so the
  set hook does not walk them a second time. (#47)

## [0.6.1] — 2026-07-17

### Fixed

- **Admin settings page now loads on symlinked installs.** When the plugin is symlinked into
  `wp-content/plugins` (a common local-dev layout), PHP's `realpath()` resolves the Wireframe package to a
  path outside `WP_PLUGIN_DIR`, so Wireframe's asset-URL derivation failed and emitted a broken script/style
  base (e.g. `https://site.testindex.js`) — the admin UI never rendered. The Wireframe bootstrap now passes an
  explicit `assets_url` derived from `plugins_url()` (which honors the symlink), so the React bundle and
  stylesheet load correctly. Non-symlinked installs are unaffected.

## [0.6.0] — 2026-07-10

Phase 3 complete: the last three legacy handlers migrate to the unified base, the legacy base class and
the redundant save loop are removed, and each migrated rule type gets a config/label pass. Also fixes the
Admin Columns integration for Admin Columns Pro v7.

### Changed

- **Level Restriction, Propagation, and Date Window (time-based) rules migrated to the unified handler base.**
  These were the last three rule types still on the old handler base. Behavior is unchanged for existing
  rules, with the fixes and polish below.
- **"Limit to post types" is now multi-select on every rule type.** Propagation, Level Restriction, and Date
  Window rules previously took a single post type; they now use the shared post-type checkboxes (empty =
  all). Propagation offers only hierarchical post types (it needs a parent/child relationship). *Existing
  single-post-type rules of these three types need a one-time re-save to pick up the new field.*
- **Clearer collapsed row titles:**
  - Propagation: e.g. "Pages: Copy Breakers terms to children (replace)" — post-type scope shown only when
    restricted.
  - Date Window: e.g. "2026-05-26–2026-05-27: Apply Shakers: Grandchild ii to posts with Breakers: Term A" —
    date window first, then the target term, scope, and any post filter.
- **Level Restriction "Keep ancestor terms"** (was "Include ancestors") now has an accurate description of
  what it does in each mode and only appears in the modes where it has an effect.

### Fixed

- **New child posts now inherit their parent's terms on their own save** (propagation), honoring the rule's
  conflict handling (merge / replace / skip). Previously a new child did not receive inherited terms until
  the parent was re-saved.
- **Propagation no longer writes/logs a redundant term update** when a post already has the terms — both
  directions (a child inheriting from its parent, and a parent cascading to its children). A no-op parent
  save no longer re-writes terms to every descendant.
- **Propagation now populates a child's ACF taxonomy field even when the child had no ACF value yet.** Field
  discovery used `get_field_objects()`, which returns nothing for a post with no saved ACF meta, so a
  never-populated child could never receive its first ACF write (native terms applied, ACF field left empty).
  Fields are now resolved from ACF location rules (value-independent), and the first write uses the field key
  so ACF registers the field reference correctly. The child-inheriting and parent-cascading paths, the
  ACF-only source read, and the term-removal path all use the same discovery.
- **Prevented a latent crash**: propagation and level-restriction rules that act on an ACF taxonomy field
  would have hit an undefined-method error after the base migration; the ACF read/write helpers are now on
  the unified base. (Only reachable with an ACF taxonomy field configured; native-taxonomy rules were
  unaffected.)
- **Date Window daily cleanup no longer runs twice** — the scheduled expired-term cleanup was registered
  both directly by the handler and via a redundant relay; the relay is removed.
- **Date Window "Filter by taxonomies" now works** — the taxonomy filter read the checkbox field in the
  wrong shape, so a rule with a taxonomy filter set never matched any post. Filtering by specific terms was
  unaffected.
- **Post-status gating hardened** — the shared post-status filter didn't normalize its checkbox value, so a
  status gate could be silently bypassed. (No rule type gates on status via this path yet; fixed proactively.)
- **Post-type gating normalized consistently** — the shared post-type gate hand-rolled its checkbox
  extraction while the post-status gate used the canonical helper; both now go through the same extractor,
  removing a drift risk. No behavior change for correctly-saved rules.
- **Level Restriction ACF saves no longer re-enter the handler** — saving a post whose ACF taxonomy field is
  under a level-restriction rule wrote terms without setting the reentrancy guard, so the handler ran a
  second (redundant) restriction pass in the same request. The guard is now symmetric with the native-terms
  path. (Idempotent before; the fix removes the wasted work and an edge-case extra write.)
- **Bulk "process existing posts" reads the correct post-type key** — the bulk tool still read the old scalar
  `source_filters['post_type']` and so would have scanned only the `post` type for the migrated rule types
  (which now store the plural `post_types` checkboxes). It now reads `post_types` (empty = all), falling back
  to the legacy key. (Not yet reachable via UI — see [#31](https://github.com/davidofchatham/meta-conductor/issues/31).)
- **Admin Columns Pro v7 edits reapply rules again** — the Admin Columns integration used pre-v7 hook names
  and signatures, so on AC/ACP v7+ its hooks never fired and inline/quick/bulk-edit changes stopped driving
  rule reapply. AC v7 writes ACF fields via `update_field()`, which fires `acf/update_value` only — never the
  save hooks the handlers listen on. A new `ac/editing/saved` fallback now reapplies every ACF-listening
  handler's sync (ACF reference, Related Term Mapping, Level Restriction, Propagation, Title & Slug) after an
  AC v7 edit; native taxonomy-column edits were already covered. ([#37](https://github.com/davidofchatham/meta-conductor/issues/37))
- **Admin Columns Pro is detected correctly on v7** — the "Admin Columns Pro" diagnostics status checked for a
  class that no longer exists in v7, so it reported "Not Active" even when ACP was active. It now checks the
  `ACP_VERSION` constant.

### Removed

- **Legacy `BWS_Handler_Base` class deleted** — all seven rule handlers now share `UnifiedHandlerBase`.
- **Redundant global save loop removed** — each handler registers its own hooks; the Date Window rule no
  longer runs twice per save.
- **Dead pre-v7 Admin Columns integration deleted** — the old `class-admin-columns-integration.php` (legacy
  hook names, an unreachable scheduled reapply event) is replaced by the `ac/editing/saved` fallback above.

### Known interactions (filed, not blocking)

- Propagation + hierarchical rules on the same taxonomy compose: children can gain one extra expansion level
  ([#35](https://github.com/davidofchatham/meta-conductor/issues/35)).
- Propagation has no inherited-vs-manual term tracking; a mode switch can strand a previously inherited term
  ([#34](https://github.com/davidofchatham/meta-conductor/issues/34)).
- Bulk "process existing posts" is inert for hook-driven handlers and has no UI trigger yet; systemic fix
  deferred to the Migration/Preview tool ([#31](https://github.com/davidofchatham/meta-conductor/issues/31)).
- Propagation treats a post's native terms and its ACF taxonomy field as one merged set and mirrors that set
  into **both** stores on the children. With the ACF field's Load/Save Terms ON (the default) the two stores
  are already identical, so this is invisible. With Load/Save Terms OFF — where the native and ACF values are
  intentionally kept separate — propagation collapses that separation on the children (a parent's native-only
  term appears in the child's ACF field and vice-versa). Propagation is not channel-preserving by design; if a
  "keep native and ACF separate" model is needed, file an issue.
- ACF Reference (Related Post Terms) does not strip a synced term when the **dependent** end drops a
  bidirectional relationship (e.g. clearing the relationship on the event rather than the schedule). The
  term-removal sever is missed on both the editor and Admin Columns paths; adding a relationship still syncs
  correctly ([#43](https://github.com/davidofchatham/meta-conductor/issues/43)).
- The Admin Columns v7 reapply is Admin-Columns-coupled. An AC-agnostic version driven from `acf/update_value`
  (covering bare `update_field()` and REST writes) is tracked separately
  ([#42](https://github.com/davidofchatham/meta-conductor/issues/42)).

## [0.5.0] — 2026-06-30

### Added

- **Disabled rules are flagged in their collapsed row title** — ACF reference and Related Term Mapping rules
  now show a `[Disabled]` prefix on the row label when switched off, so a disabled rule is recognizable without
  expanding it. (The marker updates when you save the rule.)

### Changed

#### ACF reference rules ("From referenced post") reworked

- **Direction is now selectable.** Each rule's ACF field pins the "field holder" post type; a new
  **Authoritative end** option says which end owns the terms — *Field holder is the source* pushes the holder's
  terms out to the related posts, *Related posts are the source* pulls their terms onto the holder (the old
  behavior). Existing rules keep the pull behavior automatically; only newly added rules default to push.
- **Single taxonomy.** The separate Source/Target taxonomy selectors are collapsed into one **Taxonomy**
  field. Copying terms across two *different* taxonomies never actually worked (terms are copied by ID, and an
  ID belongs to one taxonomy), so the second selector was a footgun. Existing rules keep their source taxonomy.
- **"Bidirectional" → "Keep in sync."** Clearer name for the same idea: when on, copied terms are removed from
  the target once the source no longer has them; when off, terms are only added, never removed.
- **Source publication-status filter.** New **Limit to source statuses** option — only copy terms from source
  posts with the chosen statuses (e.g. published only). Empty = any status. Gates the *source*, not the target.
- **Optional reverse relationship field** for faster two-way lookups; auto-detects ACF native bidirectional
  fields, falling back to a query when none is configured.
- **Rule row titles** rebuilt: e.g. "Copy Sport Connectors terms to Team schedule on Published".
- **Conflict handling option removed** — "Keep in sync" now controls add-only vs replace. Existing rules map
  automatically (merge → off, replace → on).

### Heads-up (existing ACF reference rules)

- Migration is automatic and behavior-preserving — existing rules continue to pull onto the field holder, in
  their source taxonomy, with the same add/remove behavior. **Re-save a rule to refresh its row title** and to
  adopt the new single-taxonomy/direction wording. A rule that previously used the rare `skip` conflict mode is
  migrated to add-only; re-check those.

### Internal

- ACF reference handler migrated to the unified handler base (Phase 3). Sync is now **declarative and
  source-authoritative**: a post's terms in the synced taxonomy are recomputed from its current related posts
  on every relevant save, rather than tracked incrementally — safer under multiple rules and reorders.
- **Removed** the legacy `AcfIntegration` term-sync engine — a parallel reimplementation of several rule types
  on the old rule schema. Redundant for taxonomy fields that load/save terms to the post (ACF mirrors native ↔
  field, so the handlers reading native terms already see everything). The migrated handlers are the sole
  writers; engine-off parity was verified on real data before removal. The `bws_mc_acf_sync_engine_enabled`
  filter is gone with it.

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
