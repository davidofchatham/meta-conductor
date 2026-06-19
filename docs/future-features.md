# Future features

Canonical list of features that aren't built yet. Each entry:

- **Status** — `idea` (unscoped), `planned` (assigned to a phase in [ROADMAP.md](../ROADMAP.md)), `in-flight` (active SPEC.md or branch).
- **Motivation** — why it matters.
- **Sketch** — enough to start scoping; not a spec.
- **Phase** — where it lands in the [ROADMAP.md](../ROADMAP.md) phase plan, if assigned.

When an idea is promoted to in-flight, add a link to its plan file under `.claude/plans/` or its SPEC.md.

---

## Rule types

### `acf_relationship_rules` — ACF Post Relationship Manager

- **Status**: planned (Phase 6a)
- **Motivation**: distinct from `related_post_terms_rules`. Same data source (ACF relationship / post-object field), different output: **post parent/child relationship** instead of taxonomy terms.
- **Sketch**: when a post is saved with a populated ACF relationship field, set this post's `post_parent` to the referenced post (or set referenced posts' `post_parent` to this one, depending on direction). Prevent circular references; handle multi-post-type configs.
- **Source**: `plugins-to-integrate/acf-post-relationship-manager/` (deleted in this commit; reference the prior commit if needed).
- **Storage**: Options.

### `time_based_rules` — Temporal State Rule (evolution of Date Window)

- **Status**: in-flight (targeting the 0.x line, before 1.0.0), scoping.
- **Motivation**: today's Date Window rule is binary (in-window → apply term, out → remove) with a fixed date-only window typed on the rule. Evolve it so one rule expresses before/during/after auto-tagging across a datetime window, with boundaries that may be read from per-post ACF/meta fields (including a single combined `datetime` value as Pie Calendar stores), and "trigger X time before/after a date" support. Ships a **Pie Calendar source preset** (start/end/all-day meta keys pre-filled) and honours an **all-day boolean field** as a boundary-time override. Also absorbs the "post expires N after its date field" pattern and the previously-planned `date_based_taxonomy_rules` (Phase 6a), folded in rather than built as a separate type.
- **Detail lives elsewhere** (this entry stays a pointer to avoid drift):
  - Scoping plan + open questions: [.claude/plans/temporal-rule.md](../.claude/plans/temporal-rule.md)
  - Domain vocab + invariants: [CONTEXT.md](../CONTEXT.md)
  - Model decision (general model / constrained UI, no provenance): [docs/adr/0001](adr/0001-temporal-rule-general-model-constrained-ui.md)
- **Source**: `plugins-to-integrate/date-based-taxonomy-term-updater/` (deleted; reference prior commit).
- **Storage**: Options. Migrates to CPT in Phase 4 alongside the existing plan; no longer gated *on* CPT.

### `field_transformation_rules` — Computed Field Output

- **Status**: planned (Phase 6a, requires Phase 4 CPT storage)
- **Motivation**: combine multiple source fields into a formatted output field. Examples: merge first/middle/last name into a display name, combine date + time into a sortable datetime, format a phone number, derive a bio string from athlete stats.
- **Sketch**: rule declares output meta key + a template/transformer. Reference snippet: `plugins-to-integrate/format-game-date-time-fields.php` (site-specific athletics_events example showing batching pattern; not directly reusable).
- **Storage**: CPT.

### `user_based_rules` — User-Based Term Setting / Restriction

- **Status**: planned (Phase 6b, requires Phase 4 CPT storage)
- **Motivation**: pre-set terms in a taxonomy based on the current user (role or ID), or lock a taxonomy so only specific roles can edit it. Spans both auto-set and restrict actions, which is why both flavors live under the **Personalize by User** tab in the settings UI.
- **Sketch**: absorb the existing standalone plugin `bws-user-based-terms` (separate repo). Each rule maps user role or ID → taxonomy → term(s). Auto-set variant applies on `save_post`; restrict variant filters term lists in admin.
- **Source**: external — `../bws-user-based-terms/`. Currently uses its own CPT (`bws_user_term_rule`); merge into the unified `bws_mc_rule` CPT planned in Phase 4.
- **Storage**: CPT.

---

## Tools and infrastructure

### Post type converter

- **Status**: idea
- **Motivation**: take a defined group of posts (e.g. all descendants of a specific page, or all posts in a taxonomy) and convert them to a different post type without losing meta, terms, parent/child relationships, or attachments. Common scenario: a content tree was created under `page` but should have been a custom post type; manually re-creating loses ACF data + breaks internal links.
- **Sketch**: feasibility hinges on what can move with the row:
  - `wp_posts.post_type` flip is the easy part
  - Meta + ACF data stay (keyed by post_id, post-type-agnostic in DB)
  - Term assignments stay if the target post type has the same taxonomies registered, else need a mapping/drop strategy
  - Parent/child via `post_parent` survives the post-type flip but only renders correctly if the target type is hierarchical
  - Permalink structure changes — old URLs need redirect entries
- **UX**: source picker (descendants-of-page / posts-in-taxonomy / WP_Query args / hand-picked IDs) → target post type → preview rows with mapping decisions per taxonomy + warnings (term incompatibility, hierarchy mismatch) → dry-run report → commit with optional redirect generation.
- **Phase**: candidate launch recipe in the Phase 7 Migration / Preview tool below. Recipe shape fits cleanly: source_query, transform = post_type flip + side-effects, preview = before/after row, commit = batched UPDATE + redirect insert.
- **Risk**: post-type flip is destructive to query results elsewhere; preview + dry-run mandatory.

### Unified Migration / Preview tool

- **Status**: planned (Phase 7)
- **Motivation**: Wireframe v1.0.5 has no client-side field-type extension API, so inline Preview / Apply-to-Existing buttons in rule rows are blocked. Routing bulk operations to a dedicated migration page sidesteps the blocker and creates a permanent home for one-shot data transformations across rule types.
- **Sketch**: see ROADMAP.md Phase 7. Recipes registered via filter `bws_meta_conductor_migrations`. Each recipe declares source_query / transform / preview / commit callbacks. UI: recipe picker → parameter form → preview sample → run with chunked progress.
- **Launch recipes**:
  - ACF → taxonomy term (absorbs the current Copy Data flow)
  - Field A → Field B value mapping (absorbs the current Map Data flow)
  - Apply Title/Slug rule to existing posts (replaces blocked inline button)
  - Post type converter (see entry above)
- **Storage**: none — recipes are registered code.

### CPT storage backend

- **Status**: planned (Phase 4)
- **Motivation**: rules that accumulate (`title_slug_rules`, `time_based_rules`, future CPT-type rules) outgrow a single wp_options row. CPT storage gives a list table, draft/active lifecycle, and standard WP query power.
- **Sketch**: implement `BWS_CPT_Rule_Storage` against the existing `BWS_Rule_Storage` interface. Single shared CPT `bws_mc_rule`, differentiated by `rule_type` meta. Per-type routing in `BWS_Storage_Factory`. Migration tool: options → CPT for the two existing rule types that benefit.

### PSR-4 namespacing

- **Status**: planned (Phase 2a)
- **Motivation**: simplify file loading, modernize class layout, eliminate `includes/abstracts/` as a separate directory.
- **Sketch**: see ROADMAP.md Phase 2a. Custom autoloader, namespace `BWS\MetaConductor\`, file renames, namespace declarations.

### Plugin rename + text-domain sweep

- **Status**: planned (Phase 2b)
- **Motivation**: align public identity (plugin folder, main file, text domain, admin slug, menu label) on "Meta Conductor". Collision-safe layers (PHP namespace, option keys, hook prefix) keep `bws_`/`BWS\` per Naming Surface decision.
- **Sketch**: see ROADMAP.md Phase 2b. ~600 `__()` / `_e()` / `_x()` / `_n()` calls; one-shot option-key data migration on activation; back-compat constant aliases.

### Handler migration to unified base

- **Status**: planned (Phase 3)
- **Motivation**: 5 of 7 handlers still extend the legacy `BWS_Handler_Base` and access settings via the passed-in `$settings` object. Migrating them to `BWS_Unified_Handler_Base` removes the compat shell, lets storage-layer canonical-shape normalization apply uniformly, and prepares for CPT storage.
- **Sketch**: see ROADMAP.md Phase 3. Migration order: Related → Level Restriction → Propagation → Related Post Terms → Time Based.

---

## UX polish (deferred)

### Hierarchical rule label rework

- **Status**: idea (deferred from Phase 2c discussion)
- **Motivation**: the three Child Expansion Behavior options ("Smart", "Always", "Manual only") describe the **mechanism**, but users think in **outcome**. Same problem applies to a few other label sets across the rule UI.
- **Sketch**: revisit labels after all 7 rule type configs are built and tested, look at the pattern holistically rather than per-rule. Candidate reframes captured in this commit's session log.
- **Phase**: unscoped — quality-of-life work for whichever phase has UI polish capacity.

### Subfield conditional visibility

- **Status**: unblocked — ready to build
- **Motivation**: Wireframe 1.0.6 (#13) added the conditions DSL to repeater subfields client-side. The current workaround (always-render both conditional subfields, explain via description text) can now be replaced with real show/hide `conditions`.
- **Sketch**: convert description-text workarounds to `conditions` for: `related_rules.trigger_term_id` / `.trigger_taxonomy` (operator on `trigger` select), `level_restriction.include_ancestors`, `hierarchical_rules.expansion_behavior` + help text, `propagation`/`time_based`/`title_slug` "Only used when…" subfields. Verify each show/hide rule on the test site (subfield conditions evaluate against sibling subfields in the same row).
- **Tracking**: upstream #13 closed; this is now plain implementation work.

### Client-side custom field types

- **Status**: partially unblocked (1.0.6)
- **Motivation**: Wireframe still has no JS-side field-type *extension* API — a custom type declared via the `wp-wireframe/field_types` filter registers server-side sanitize/validate but renders as nothing in React. 1.0.6's `action` field is a built-in escape hatch for the button case (real React button, posts in-flight form values to a server hook, returns `{status, message, html}`), so it doesn't need the extension API.
- **Unblocked in 1.0.6**:
  - **Page-level Title/Slug Preview / bulk Apply** — implementable now via the `action` field on the dedicated migration page (Phase 7). No custom field type needed.
- **Gap A — no JS field-type extension API (root)** — **buildable by us, fork-releasable.** Three read sites all do `customEditComponents[type]` ([SettingsSection.js:49], [mapConfig.js:54], [RepeaterEdit.js:172]); the PR adds a registry + `registerFieldType()` global mirroring the existing PHP `field_types` filter. Additive, low risk. The **fork-release path** (fork Wireframe → build → tag → repoint our composer VCS dep → vendor the built fork) ships it without waiting on the upstream maintainer to merge. Once Gap A is in our vendored Wireframe it unblocks:
  - **Taxonomy-first cascading term picker** (the named, wanted case — see Phase 3b follow-ups above). Custom field type reads its sibling taxonomy from row `data`; no sibling-update API needed.
  - **AJAX typeahead selects** for large datasets (theoretical — static enumeration works; no rule type hits the scale yet).
- **Gap B — `action` field has no repeater-row context** — **file upstream issue, don't build.** `ActionButton` posts page-level `useSettings()` values and routes by `fieldId` only (`action/{pageId}/{fieldId}/{actionId}` — no row index), so a button in row N can't tell the handler which row fired. Changing payload/route is a JS+PHP data-contract change the maintainer owns, and collides with in-flight action PRs (#19/#21/#23). **We don't need it** — inline Apply-to-Existing is covered by the Phase 7 migration page *by design*, not as a stopgap.
- **Sketch**: use `action` for all page-level buttons now. For the cascading picker, do the Gap A PR + fork-release, then build a `taxonomy_term_picker` field type (~1 day). File the Gap B issue as goodwill.
- **Tracking**: no upstream issue exists yet for (a) per-row `action` context or (b) the JS field-type extension API. PR Gap A (+ optionally self-release via fork); file Gap B as an issue. Full plan: [.claude/plans/wireframe-js-field-type-extension-blocker.md](../.claude/plans/wireframe-js-field-type-extension-blocker.md). Upstream PR direction (≤ 2026-06-11) is *extending* the action mechanism, not adding the extension API — which is exactly why the fork path matters for Gap A.

### Conflict-handling option propagation

- **Status**: idea
- **Motivation**: per-rule `conflict_handling` overrides currently default to `merge` regardless of the General-tab per-taxonomy default. User expectation: rule-level should inherit from taxonomy-level unless explicitly set.
- **Sketch**: handler reads General-tab `conflict_handling[$taxonomy]` if rule's own `conflict_handling` is empty/unset.
- **Phase**: small enough to tackle ad-hoc during Phase 3 handler migration.

### Phase 3a code-review follow-ups (PR #19)

Tracked from the PR #19 review (Related multi-PT + UnifiedHandlerBase migration). None were merge-blockers; all are pre-existing patterns carried forward or deferred test work.

- **Re-entrancy window in `RelatedHandler::on_terms_set`** (review #1)
  - **Status**: idea
  - **Motivation**: the `$this->processing` guard is set/reset *per rule* inside the loop (correctly, via try/finally). Between two rules the flag is `false`, so a `set_object_terms` call from *outside* the handler in that window isn't guarded. Real only when multiple related rules fire in one request. Pre-existing (old `process_post` had it too) — not a regression.
  - **Sketch**: set `processing` once around the whole rule loop, or use a re-entrancy depth counter. Verify it doesn't suppress legitimate cascades.
  - **Phase**: revisit during Phase 3 handler migration when the loop is touched.

- **`validate_rule_internal` term check ignores taxonomy** (review #2)
  - **Status**: idea
  - **Motivation**: `\get_term($id)` with no taxonomy returns a term from any taxonomy. A stale `trigger_term_id`/`target_term_id` could match a live term in the *wrong* taxonomy and silently pass validation, firing against it. The handler reads `trigger_taxonomy` separately, so the data to check against exists. Pre-existing.
  - **Sketch**: validate the resolved term's `->taxonomy` matches the rule's expected taxonomy (trigger_taxonomy for trigger; target term's stored taxonomy for target).
  - **Phase**: ad-hoc, low effort.

- **`scope_label` bakes display delimiters into stored value** (review #4)
  - **Status**: idea (or accept-as-is pre-1.0)
  - **Motivation**: the stored `scope_label` is ` (Posts, Pages)` — a display string with `(`, `)`, `, ` baked in, not a raw slug list. If the row-title format ever changes, the persisted value won't match the new format until each rule is re-saved (same snapshot-staleness class as the term labels, V11).
  - **Sketch**: if a structured value is ever needed, store raw slugs and format at render — but render-time formatting is exactly what Wireframe's `title_template` can't do (the reason the snapshot exists). Likely stays as-is; add a one-line comment noting the baked-in format.
  - **Phase**: comment now if touched; structural change unlikely.

- **Unit tests for `WireframeBootstrap` snapshot helpers** (review #6)
  - **Status**: idea
  - **Motivation**: `term_label()`, `scope_label()`, `taxonomy_label()`, `snapshot_related_labels()` are the highest-risk new code (two term-ID shapes, taxonomy-trigger path, empty-vs-populated post_types map, unresolvable → ''), covered only by manual InstaWP sweep. They're near-pure functions of WP data — testable with mock term/post-type objects.
  - **Sketch**: add a unit harness (the project has no PHPUnit yet — only the static `tests/lint.php` + `tests/verify-autoload.php`). Worth standing up alongside CPT-storage work when schema stabilizes.
  - **Phase**: Phase 4+ (per reviewer — when schema stability increases).

### Phase 3b follow-ups

- **Taxonomy-first cascading term picker for Related Term Mapping** (deferred from Phase 3b)
  - **Status**: idea
  - **Motivation**: the trigger-term and target-term dropdowns list all terms across all taxonomies. Selecting a taxonomy first (e.g. "Athletics Team Connectors") then showing only its terms would dramatically reduce noise on sites with many taxonomies and many terms.
  - **Sketch**: build a custom `taxonomy_term_picker` Wireframe field type. A custom Edit component receives full row context — RepeaterEdit passes `data={row}` to each subfield ([RepeaterEdit.js:189-193]), so the picker reads its sibling taxonomy value straight from `data` and fetches/filters its term list via REST. **No sibling-update API is needed** (the earlier "must update a sibling select → blocked" framing was wrong — one component owns both the taxonomy choice and the dependent term list, or reads the sibling from the row). The `action`-field round-trip idea is a dead end (action can't update a sibling).
  - **Depends on**: Gap A — Wireframe's missing JS field-type extension API. See [.claude/plans/wireframe-js-field-type-extension-blocker.md](../.claude/plans/wireframe-js-field-type-extension-blocker.md). Gap A is small/additive and buildable by us; the **fork-release path** (fork → build → tag → repoint our composer VCS dep → vendor the built fork) lets us ship it without waiting on the upstream maintainer to merge. This is the one named, wanted use case that justifies doing the Gap A PR.
  - **Phase**: unblocked once Gap A lands in our vendored Wireframe (own fork or upstream release). ~1-day build on our side after that. Until then: static boot-time enumeration holds.

---

## Where this list comes from

- ROADMAP.md Phase 6+ assignments
- Phase 2c session planning (the strategic roadmap that session produced now lives in ROADMAP.md; its working plan file is gone)
- Deleted standalone plugins under `plugins-to-integrate/` (captured here at deletion time so the intent isn't lost)
- Session discussion notes that didn't fit into a phase

When something here becomes work-in-flight, link its plan file or SPEC.md from the relevant entry. When it ships, move the entry to CHANGELOG (under the release that included it) and remove from this file.
