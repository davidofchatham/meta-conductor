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
