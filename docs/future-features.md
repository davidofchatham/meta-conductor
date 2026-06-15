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

- **Status**: in-flight (targeting 2.0.0). Supersedes the previously-planned `date_based_taxonomy_rules` (Phase 6a) — folded in here rather than built as a separate rule type. See grill-with-docs session → CONTEXT.md / docs/adr/.
- **Motivation**: today's Date Window rule is binary (in-window → apply term, out → remove) with a fixed date-only window typed on the rule. Evolve it into a **three-state temporal machine** so one rule expresses future / current / past auto-tagging, and let the window come from a **per-post ACF/meta datetime field** instead of only a fixed typed value. Also absorbs the "post expires N days after its date meta" pattern via offsets.
- **Sketch**:
  - **Three states**, evaluated against a datetime window: `future` (now < start), `current` (start ≤ now ≤ end), `past` (now > end). Each state carries its own action (v1: a target term; optional per state).
  - **Window source** per rule: *typed* (date-only — Wireframe v1.0.5 has no datetime picker, don't #5) **or** *ACF/meta field* (full datetime; ACF provides the picker). Time-of-day precision is ACF-source-only by decision.
  - **Boundary offsets**: each boundary shiftable ±X (days/hours), e.g. `current` begins "3 days before start" to tag "closing soon." Offsets are how "trigger X time before/after a date" is expressed.
  - **Provenance**: per-rule applied-term tracked in post meta so state flips remove only *this rule's* prior term, never a manual tag. Fixes the existing blind-removal TODO in `class-bws-time-based-handler.php` (`cleanup_expired_rule` removes from all matching posts).
  - **Triggers**: `save_post` (immediate re-eval) + cron. Cron tier hourly (ACF datetime precision; flag if daily preferred). Per-post ACF-source rules sweep via `meta_query` on the source field; typed-source rules keep the cheap rule-date sweep. Batch via existing `process_existing_posts` pattern.
  - **Action dispatch**: term-apply now, designed for future action types (post status change, meta write) via an action-type switch.
- **Source**: `plugins-to-integrate/date-based-taxonomy-term-updater/` (deleted; reference prior commit).
- **Storage**: Options (current `time_based_rules` shape, extended subfields, normalized through the canonical-shape adapter — no dot-notation per don't #4). Migrates to CPT in Phase 4 alongside the existing migration plan; no longer gated *on* CPT.
- **Open risks**: hourly cron load on large post sets (batch); `meta_query` on non-indexed/serialized ACF datetime fields is slow at scale (acceptable v1, document); state mutual-exclusion + offset boundary math are the invariant-heavy parts → SPEC.md before build.

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
- **Still blocked** (need the missing JS extension API):
  - **Inline Apply-to-Existing *inside a rule row*** — `action` works as a repeater subfield, but `ActionButton` posts page-level `useSettings()` values and routes by `fieldId` only (`action/{pageId}/{fieldId}/{actionId}` — no row index). The handler can't tell which rule row fired. Workaround stays = route per-rule actions through the Phase 7 migration page.
  - **Cascading dependent selects** (taxonomy → narrowed term list). Workaround = static enumeration at boot.
  - **AJAX typeahead selects** for large datasets. Workaround = static enumeration at boot.
- **Sketch**: use `action` for all page-level buttons now. Revisit row-level + select cases when/if upstream adds the field-type extension API.
- **Tracking**: no upstream issue exists yet for (a) per-row `action` context or (b) the JS field-type extension API. File both. Upstream PR direction (≤ 2026-06-11) is *extending* the action mechanism (downloads #23, file upload #21), not adding the extension API — so don't expect the row/select cases soon.

### Conflict-handling option propagation

- **Status**: idea
- **Motivation**: per-rule `conflict_handling` overrides currently default to `merge` regardless of the General-tab per-taxonomy default. User expectation: rule-level should inherit from taxonomy-level unless explicitly set.
- **Sketch**: handler reads General-tab `conflict_handling[$taxonomy]` if rule's own `conflict_handling` is empty/unset.
- **Phase**: small enough to tackle ad-hoc during Phase 3 handler migration.

---

## Where this list comes from

- ROADMAP.md Phase 6+ assignments
- Plan file `.claude/plans/i-want-to-switch-lovely-wren.md` (Phase 2c session)
- Deleted standalone plugins under `plugins-to-integrate/` (captured here at deletion time so the intent isn't lost)
- Session discussion notes that didn't fit into a phase

When something here becomes work-in-flight, link its plan file or SPEC.md from the relevant entry. When it ships, move the entry to CHANGELOG (under the release that included it) and remove from this file.
