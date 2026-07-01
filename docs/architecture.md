# Architecture

How Meta Conductor's pieces fit together. For project status and phase plan see [ROADMAP.md](../ROADMAP.md). For release log see [CHANGELOG.md](../CHANGELOG.md).

> **Scope note.** This file is intentionally conceptual. Per-class detail (exact class names, method lists, file paths) drifts every phase and is NOT mirrored here — the code is the source of truth for that. PHPDoc on the enforcing class carries the load-bearing invariants.

## Three-layer rule engine

```
WordPress hooks (save_post, etc.)
    ↓
Handlers (one per rule type)
    ↓
Rule Engine (orchestrator)  ←→  Condition Evaluator + Action Executor
    ↓
Entity Abstraction (Core\Entity)
    ↓
WordPress core (posts, terms, users, comments)
```

Key boundaries (the rules that matter, regardless of class names):

- **Handlers never touch `get_post()` / `get_term()` / `get_user_meta()` directly** — they go through `Core\Entity`, the polymorphic wrapper over WP entities. Rule logic stays agnostic about the underlying WP storage.
- **Handlers never call `get_option()` directly** — they read/write through the storage layer (`Storage\StorageFactory`), which is also the canonical-shape adapter (see below).
- **One `wp_options` key** (`bws_meta_conductor_settings`) holds every rule type, each an array of rule rows, plus a few global keys (per-taxonomy conflict overrides, manual-processing toggle).
- **Two handler bases coexist** during the Phase-3 migration: `UnifiedHandlerBase` (typed PHP 8.1 helpers, storage-backed) and the legacy `HandlerBase`. Handlers migrate one at a time; the legacy base disappears when the last moves.

## Writing a rule handler — hard-won invariants

Distilled from the 0.5.0 ACF-reference rework and its eight review rounds. These
are cross-handler traps, not ACF-specific. Read before building or migrating a
handler (temporal-rule, status-mirroring, the remaining legacy migrations, and
the Phase-4 config page split — one blob → per-page option_keys — all hit
several).

1. **Wireframe reads the option RAW** (`get_option`, no filter seam), bypassing
   `normalize_rule_shape`. A read-time migration that RENAMES or REMOVES a key is
   invisible to the admin form → the form binds the new key, finds it absent,
   falls back to the config default, and a resave PERSISTS that default
   (corruption). Any key-renaming migration needs a one-time, flag-gated option
   REWRITE, not just read-time normalization. (B6) Directional adapters that
   reshape the SAME key (array↔scalar) are safe — the admin round-trips them.
   **Phase-4 page split is exactly this trap at the option-key level:** moving a
   rule type from the single `bws_meta_conductor_settings` blob to its own
   per-page option_key must REWRITE the rules into the new key before Wireframe
   reads that page raw, or the page renders empty and a resave wipes the type.

2. **Never gate a destructive write on post-type match alone.** A rule whose
   target type is `''`=any matches every post; combined with a replace/remove
   mode it wipes unrelated posts. Require positive evidence the rule MANAGES this
   object (e.g. a resolved source present) before any remove/replace. A "force"
   flag must be scoped to the exact case that needs it (the true orphan,
   `source_count===0`), never used as a blanket replace trigger — that strips
   sibling add-only rules' contributions. (B3/V13, R6#1)

3. **Cache DATA, never DECISIONS.** Memoizing a pure lookup (relationship graph
   read, ACF field config) is safe and request-lived. Memoizing a write/skip
   DECISION that depends on mutable state (status gate, time window) is a bug:
   the state can change between the two fires of one save, and the cached
   decision masks it. (B5/V15 — a recompute-result cache hid a publish→draft
   transition.) Invalidate a data cache on the mutation that changes its inputs.

4. **The save_post + acf/save_post double-fire is universal.** Every ACF-aware
   handler runs twice per admin save. Make writes idempotent (short-circuit on
   no-change) and cascade-guarded, scoped to (post, taxonomy); never assume
   "runs once". Conversely, a bare programmatic `update_field()` fires
   `acf/update_value` but NEITHER save hook — document that callers must fire
   `acf/save_post`/`wp_update_post` to flush deferred work. (V11, R8#2)

5. **Delete has no field-update hook.** Sever/cleanup on permanent delete needs
   `before_delete_post` (capture while the post still resolves) + `deleted_post`
   (act after it's gone, so it no longer counts as its own remaining source).
   Guard against revision/autosave IDs. (R2#4, R5#4, R7#4)

6. **ACF field identity is by KEY, not name.** `acf_get_field($name)` returns an
   arbitrary field when two groups share a bare name; `bidirectional_target` is a
   LIST of partner keys (resolve all); the old value at `acf/update_value` time
   is an impl-detail ordering (have a `get_post_meta` fallback); relationship
   values serialize as an INT array (`i:42;`, not `s:2:"42"`). Resolve by key;
   never assume serialization format. (#25, R2#2, R4#1, B4) **Group prefix is a
   name-matching landmine of the same class:** a field inside an ACF Group stores
   and reads by the GROUP-QUALIFIED name (`get_field('group_sub')` resolves), but
   its runtime "name" — `acf/update_value`'s `$field['name']` AND Admin Columns
   v7's `AC\Column\Context::get_meta_key()` — is the BARE subfield (`sub`, prefix
   stripped). Matching a stored qualified name against either by `===` silently
   misses. `related_post_terms` matches by name and is verified for TOP-LEVEL
   relationship fields only; `ConfigHelpers::acf_relationship_field_options()`
   lists top-level fields only (`acf_get_fields($group_key)` doesn't recurse
   Group/Repeater/Flex), so the picker can't offer a nested field and the config
   UI warns as much. Any nested-field support must match by field KEY or reconcile
   bare↔qualified. The AC v7 `ac/editing/saved` reapply fallback (#37) matches
   `get_meta_key()` — correct for top-level, revisit if nested is ever supported.
   (#37; future-features.md → grouped relationship fields)

7. **Storage write results must propagate; cache must mirror storage.**
   `update_option` returns false for BOTH a no-op-equal write AND a real failure
   — never ignore the bool, and don't let the request cache adopt data that
   didn't persist (it ghost-persists on the next save). Distinguish equal-vs-fail
   by re-reading. (R5#5/R6#4/R8#1/R8#3; tracked as issue #27) — the Phase-4 page
   split multiplies this: a rule_type→option_key router writes to several
   options, each with its own cache, every one bound by the same contract.

8. **Pre-filter site-wide hooks in BOTH directions.** Global `save_post` /
   `set_object_terms` / `acf/update_value` hooks fire for every post on the site;
   gate eligibility (is this post a plausible source AND/OR dependent of any
   rule?) before any expensive reverse lookup or query. (B7/V17)

9. **Sibling-conditional subfields use real `conditions`, not description text.**
   Wireframe 1.0.6 (#13) evaluates subfield-level `conditions` against the other
   subfields in the same repeater row, client- AND server-side
   ([Conditions.php](../vendor/tdrayson/wp-wireframe/src/Framework/Conditions.php),
   `RepeaterField` honors it). A subfield only meaningful when a sibling holds a
   given value gets a `conditions` node (`{field, operator, value}`, or `all`/`any`
   combinators; `in`/`not_in` for multi-value) — NOT a "Only used when X…"
   description. **When migrating or revisiting any rule type, convert its
   description-text conditionals to real `conditions`.** Caveat: a condition-hidden
   subfield is dropped from the save payload (not persisted), so the handler reads
   it absent (falsy/default) — make sure that's the intended hidden-state value.
   First conversion: level-restriction `include_ancestors` (shows only in
   deepest_only / one_per_level). Several configs still carry the old workaround;
   convert as each is touched. (don't #3, SPEC §V11)

## Settings UI — WP Wireframe

The settings UI is a React app provided by `tdrayson/wp-wireframe`. Each rule type has a config class under [includes/admin/config/](../includes/admin/config/) exposing a `section()` method. The top-level composer assembles tabs from sections:

| Tab | Sections |
|---|---|
| Auto-Set Terms | Propagation, Related Post Terms (ACF), Time-Based, Related Terms, Hierarchical |
| Format & Transform | Title & Slug. Future: date / name / phone field transforms |
| Restrict | Hierarchical Level Restrictions |
| Personalize by User | Placeholder (UBT merge pending) |
| General | Per-taxonomy conflict handling overrides, manual processing toggle |

Boot path: [class-wireframe-bootstrap.php](../includes/admin/class-wireframe-bootstrap.php) calls `\Wireframe\App::boot()` on `init` priority 10 with the assembled config.

### Why Wireframe

Replaces a ~2,000-line hand-rolled god-class settings file plus 1,700-line jQuery template-cloning admin.js. Wireframe gives us:

- React-based repeater fields with sortable/collapsible/duplicate
- Server-side validation via Rakit
- REST endpoints for save/load
- `@wordpress/components` UI consistency

### Wireframe quirks worth knowing

- **No client-side field type extension API** (stock Wireframe). Custom field types declared via `wp-wireframe/field_types` filter handle sanitize/validate server-side but don't render in React. Use stock field types only — *unless* we ship our own Wireframe fork that adds the JS registry (Gap A), which is buildable and fork-releasable: see [.claude/plans/wireframe-js-field-type-extension-blocker.md](../.claude/plans/wireframe-js-field-type-extension-blocker.md).
- **Dot-notation field IDs are skipped by Sanitizer** ([src/Framework/Sanitizer.php](../vendor/tdrayson/wp-wireframe/src/Framework/Sanitizer.php)). Don't use `field.subkey` syntax for save fields — use repeaters with subfields instead.

Fixed upstream in 1.0.6 (no longer quirks): single-page `App::boot()` honors `menu_slug` (#5); subfield-level `conditions` evaluate client-side (#13); admin-screen body class anchors on the `_page_{menu_slug}` suffix instead of a substring (#6).

## Canonical shape adapter

Wireframe writes some fields differently than handlers expect (e.g. a `multiple+max=1` FormTokenField writes `[id]` where a handler wants `int`; an ACF field select writes `"post_type:field_name"` where a handler wants the bare name plus a separate post type). The storage layer's `normalize_rule_shape()` coerces these on read.

Storage is the adapter boundary between writers (current: Wireframe REST) and handlers — future writers (CLI, import) plug in at the same boundary. **Caveat:** a key-RENAMING migration here is read-time-only and the Wireframe admin reads the option RAW, so a renamed/removed key must ALSO be persisted (one-time rewrite) or the admin renders defaults and corrupts on resave. (See the ACF-reference migration; SPEC §V16 while active.)

## Data conversion tool

[includes/conversion/](../includes/conversion/)

Multi-step wizard for ACF → taxonomy data migration. Lives at the `meta-conductor-conversion` admin subpage under the Meta Conductor menu. Phase 7 of the [ROADMAP](../ROADMAP.md) absorbs this into a unified Migration / Preview tool that also hosts Title/Slug bulk-apply and future field transforms.

## Diagnostics page

[includes/admin/class-diagnostics.php](../includes/admin/class-diagnostics.php)

Subpage under Meta Conductor menu. Visible when `WP_DEBUG` is on, or via filter `bws_meta_conductor_show_diagnostics`. Dev-only Storage section dumps the raw option contents; future user-level sections (rule counts, handler status) will hang here without dev mode.

## SPEC lifecycle

When a substantive feature is in flight, a `SPEC.md` at repo root captures invariants and tasks (see the `spec` skill). After the feature ships, post-ship cleanup is mandatory:

1. Load-bearing invariants migrate into PHPDoc on the enforcing function (closest to the code), or into this file when conceptual.
2. Closed/deferred tasks are deleted.
3. Bugs migrate to GitHub Issues.
4. SPEC.md is truncated to a one-line placeholder.

Active SPECs are the source of truth only while in flight. After ship, PHPDoc + this file + CHANGELOG + Issues take over.
