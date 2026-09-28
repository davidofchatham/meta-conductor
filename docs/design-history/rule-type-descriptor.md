# Spec: Rule-type descriptor + canonical storage projection (FW-39)

> **Design history lifted 2026-09-28 from the private spec file. Never corrected.** This is the build contract for FW-39, written before the build and kept as written: why rule types got one descriptor each and a single registry, and why storage's read projection became the guaranteed rule shape first. It is **not** documentation of shipped code: see [architecture.md](../architecture.md), the PHPDoc on `RuleTypes\Registry` and `RuleTypes\RuleType`, and [CHANGELOG.md](../../CHANGELOG.md). "CLAUDE.md don't N" citations point at the maintainer's private notes; the invariant each one names is in [architecture.md](../architecture.md) or on the enforcing class's PHPDoc. The twelve build tickets were not lifted. The review candidates listed under *Out of Scope* are tracked as FW-40 (token engine), FW-41 (term appliers return an end state), FW-42 (ACF write queue fold) and FW-43 (dead `TaxonomyManager` surface), and the relationship graph is folded into FW-5, in [future-work.md](../future-work.md).

Status: shipped in [PR #76](https://github.com/davidofchatham/meta-conductor/pull/76) (storage) and [PR #77](https://github.com/davidofchatham/meta-conductor/pull/77) (descriptors), verified 2026-09-28: 17 static gates plus the roundtrip and dispatcher sweeps green.

Origin: 2026-09-24 architecture review, candidates 1 + 2, grilled 2026-09-25. Two PRs, storage first.

## Problem Statement

A **rule type** is a domain concept with no module. Its facts are restated as hand-kept lists across storage, admin config, the collision detector, the Apply page, both dispatchers, the handler map and the activation seed: adding one term-rule type touches ~15 production sites in 9 files, plus 5 harnesses and 3 fixture tools. Three facts are each restated in 3–4 places ("has a taxonomy target", "term-pairing type", "`post_status` gates the source, not the written post"). A type missing from a list falls through **silently**: the collision detector's `target_key` returns null, and `written_post_types` falls back to "every post type" so the Apply page sweeps everything.

Underneath, `OptionRuleStorage::normalize_rule_shape()` is declared the canonical boundary but coerces three fields. Checkbox maps (`post_types`, `post_status`, `filter_taxonomies`) pass through raw, so 9 runtime call sites re-decode them through `Admin\Config\ConfigHelpers::selected_checkbox_slugs` — runtime depending on an Admin config class — and term-id fields are re-cast by their consumers (FW-29). The storage interface advertises 16 methods, ~5 are called; `StorageFactory` guards a seam with one adapter. About 450 lines of one-time migration code remain, and the activation seed still writes the pre-#66 type-keyed arrays, so every fresh install runs the legacy upgrade path.

Phase 6a adds rule types in new effect kinds (FW-1 post parent, FW-4 field), then FW-5 and FW-8. Each would pay the full scatter cost.

## Solution

**PR 1 — storage projection.** Storage's read projection becomes the guaranteed canonical rule shape. Checkbox decoding moves into storage; term-id types are guaranteed. The storage interface shrinks to what is called. Migration code for pre-0.8.0 data is deleted, the activation seed writes the current shape, and a site still holding legacy rows gets an admin notice instead of silently running no rules.

**PR 2 — descriptor.** One descriptor class per rule type states that type's facts once. An ordered registry derives every list the code keeps by hand today. A stored type with no descriptor fails loud in admin and in the harnesses, and is skipped-and-logged at runtime.

## User Stories

1. As a developer adding a rule type (FW-1, FW-4, FW-5, FW-8), I write one descriptor and one handler, and every type list, gate, title and reach table picks it up.
2. As a developer, I can't forget a site: a type in a kind with no descriptor fails H16.
3. As a handler author, a rule I read from storage is already canonical — slug lists, `int` / `int[]` term ids — and I never re-coerce.
4. As a site author on a pre-0.8.0 install that updates straight past 0.9.x, I see a notice telling me to update through 0.9.x first, instead of my rules silently stopping.
5. As an author, a row with an unknown type is refused visibly on the Apply page and in the collision check rather than treated as reaching every post type.
6. As a maintainer of FW-14, renaming a rule type is one `label()` edit.

## Implementation Decisions

### PR 1 — storage projection (branch `claude/storage-projection-39`)

- **Canonical read shape.** After `normalize_rule_shape()`:
  - `post_types`, `post_status`, `filter_taxonomies` → `string[]` (slug list), empty = all.
  - `target_term_id` → `int` (a target is one term). `trigger_term_id` → `int[]`. The FormTokenField's stored `[N]` is a *form* shape the projection converts; it is never a runtime shape. This settles FW-29 as "yes, the boundary is guaranteed"; every consumer re-cast is deleted.
  - The projection is read-only — nothing writes it back — so widening it needs no migration, on live rule types included.
- **`selected_checkbox_slugs` moves into storage.** Runtime callers read the projected value; admin callers that hold raw form values call `project_kind_rules()` (the on-demand collision path already does). Deleted from `ConfigHelpers`. Runtime stops importing `Admin\Config`.
- **Storage interface.** Keep the names `RuleStorage` and `StorageFactory` (call sites and CLAUDE.md don't 1 unchanged). `RuleStorage` shrinks to the methods with callers (`get_kind_rules`, `get_rules`, `get_rule`, `get_raw_settings`, `clear_cache` — confirm by grep at build time). `StorageFactory` shrinks to `get_instance()`; FW-17 grows it back if ever needed. Delete the uncalled type-addressed CRUD (`position_of` … `import_rules`) and the base-class `save_rule` / `delete_rule` wrappers.
- **Delete the one-time migrations**: `upgrade_legacy_shape`, `maybe_migrate_acf_ref_storage` + `backfill_acf_field_keys` + `resolve_acf_field_key`, `maybe_migrate_kind_lists`, and their flags' write paths (the flags become dead options; delete them in the upgrade routine). Gate cleared: `tools/fixtures/legacy-shape-check.php` reported CLEAN on both production sites (2026-09-25; one site's ACF-ref schema flag still reads `1`, but every stored field value is already three-part).
- **Delete the every-boot shape repairs**: `migrate_inheritance_behavior`, `migrate_related_term_shape`, and the read-time + admin `migrate_related_post_terms_shape`. **Keep** `repair_stored_rules`'s **title backfill** — it is not a migration. **Keep** the two-part `post_type:field` ACF value parser: ambiguous-name rows are left two-part on purpose (CLAUDE.md don't 6).
- **`fan_in` / `fan_out`.** `fan_in` moves into `tools/fixtures/mc-rules/sweep-lib.php` as fixture code (the by-type authoring `seed.php` and `mc_write_rule_types` use), ordered from `KIND_TYPES`. `fan_out` and H10's losslessness proof are deleted — with no legacy data to convert, the proof proves nothing.
- **Activation seed** writes `term_rules => []`, `format_rules => []` (plus `conflict_handling`), not the seven legacy keys.
- **Pre-0.8.0 guard.** If any legacy `*_rules` top-level key holds **rows** and the kind lists are absent, show an admin notice: update through 0.9.x first. Empty legacy arrays with no kind lists (a 0.7.x site with no rules — seen locally) are NOT a trigger; storage treats absent kind lists as empty. `readme.txt` gets an upgrade note. No migration code.
- **Release**: CHANGELOG entry for the deletions + the notice; minor bump at release.

### PR 2 — descriptor (branch `claude/rule-type-descriptor-39`)

- **Home and naming.** `includes/rule-types/`, namespace `BWS\MetaConductor\RuleTypes\`. One class per type, **named after its storage key**: `PropagationRules`, `RelatedPostTermsRules`, `TimeBasedRules`, `RelatedRules`, `HierarchicalRules`, `HierarchicalLevelRestrictionRules`, `TitleSlugRules`. The class docblock says the name mirrors the storage key and is not a domain name — FW-14 renames `label()` only. (Kebab autoloader: no consecutive capitals — checked.)
- **Separate from the handler.** A handler is a runtime object whose constructor registers capture hooks; a descriptor is static facts read by admin and runtime alike. The descriptor names its handler class. Admin-only methods (`subfields()`, `row_title()`) reference `Admin\Config` only inside their bodies, so lazy autoloading keeps a front-end request from resolving it (don't 4).
- **What a descriptor owns**:
  - `type()` (storage key), `kind()`, `label()`, `handler_class()`
  - `has_subfields()` — keeps don't 6's split: a type may sit in a kind before its config exists
  - `normalize(array $row): array` — its branch of `normalize_rule_shape`
  - `reads_shared_fields(): string[]` — which of the shared subfields its handler reads; `TermRulesConfig` / `FormatRulesConfig` build every `only(...)` gate from these
  - `subfields(): array` — its type-specific subfield builder
  - `row_title(array $row): string` — moved unchanged from `WireframeBootstrap`; shared label helpers move to `RuleTypes\Labels`; `snapshot_*_labels` stays the save-payload hook and delegates
  - reach: `target_key(array $rule): ?string`, `written_post_types(array $rule): array`, `status_gates_source(): bool`
  - capture: `capture_hooks(): array` and `drains_captures(): bool`
  - **Not** validation — slot reserved for FW-30.
- **Registry.** `RuleTypes\Registry`: an explicit ordered array of descriptor classes. Order is meaningful (type-select order, fixture authoring order). Derives: `KIND_TYPES` and `CONFIG_MIGRATED_TYPES`, `type_labels`, the config gates, the handler map, the collision detector's three target tables, `RuleChoice::SOURCE_STATUS_TYPES`, the dispatcher's capture lists.
- **One type key.** Everything is keyed by the stored `type` string. `get_handler_type()` and the short `'time_based'` keys are deleted; `TaxonomyManager::get_handler()` takes the stored string (update `sweep-59-behaviour.php`, `sweep-61-appliers.php`). The dispatcher's re-keying goes.
- **Dispatcher constants.** `CONVERTED_TYPES` / `UNCONVERTED_TYPES` deleted (every type is converted; a future hook-driven type restores one descriptor field). `CAPTURE_HOOKS` / `CAPTURE_QUEUE_TYPES` become descriptor fields read through the registry.
- **Reach.** `CollisionDetector` and `ExistingPostsApplier` call the descriptor through the registry — core stops depending on an Admin class. The SQL half of `ExistingPostsApplier::reach()` stays there (query assembly, not a type fact). FW-34 later extends the same descriptor methods per effect kind.
- **Unknown stored type.** Dispatchers: skip the row + `debug_log`, never fatal on the front end. Apply page + collision check: refuse the row with a visible message. H16: fails when a `KIND_TYPES` entry has no descriptor.
- **Activation seed and `meta-conductor.php`** read nothing from the registry (storage class isn't loaded at activation) — the seed writes only the two kind keys, so it no longer enumerates types.

## Testing Decisions

- All 16 `tests/*.php` stay green on host PHP; run the full loop before each commit.
- **H11 `$expected_visible` stays hand-written.** It is the independent check that a descriptor's `reads_shared_fields()` is right; deriving it would make a wrong declaration pass and silently drop saved data (don't 3).
- **H13** asks the registry instead of regex-reading type-list constants. Its method-body checks (run_pass, drain, capture callbacks write nothing, fan-out marks not executes) stay — those are behavioral invariants, not lists.
- **H10** loses the fan_in/fan_out round trip and the migration cases; gains canonical-shape assertions over `project_kind_rules()` (slug lists, `int` / `int[]`).
- **New H16** `tests/verify-rule-type-registry.php`: every `KIND_TYPES` type has a descriptor; every descriptor's `kind()` matches its list; labels non-empty; `handler_class()` exists; registry order == `KIND_TYPES` order.
- **H14** collision tests keep their behavior assertions; its reflection over private target tables becomes a registry call.
- **Testbed sweeps**: re-run the §58/§59 roundtrip sweeps (save through the real sanitize) and the §60–§64 dispatcher sweeps after PR 2; after any restore check `wp post list --post_type=mc_item --fields=ID,post_name` (don't 6f(e)).
- **Pre-0.8 guard**: one sweep step seeds legacy rows with no kind lists and asserts the notice; one seeds empty legacy arrays and asserts no notice.

## Out of Scope

- Validation surface (FW-30) — descriptor gets the slot later.
- Shared row-title grammar (FW-20) — titles move unchanged.
- Rule-type renaming (FW-14) — `label()` becomes the rename site, nothing renamed now.
- Full reach analysis per effect kind (FW-34).
- Candidates 3–6 of the review (token engine, term appliers return end state, relationship graph, ACF write queue fold).
- Deleting the `TaxonomyManager` dead surface beyond the handler map — separate cleanup.

## Further Notes

- `docs/future-work.md` FW-4 says `field_transformation` is "already declared in `KIND_TYPES`"; it is not (checked 2026-09-25). With the registry, "declared before its subfields" becomes a descriptor with `has_subfields() === false`.
- CLAUDE.md don'ts 6, 6b, 7 name `KIND_TYPES`, `CONFIG_MIGRATED_TYPES`, `UNCONVERTED_TYPES`, `CAPTURE_HOOKS`, `CAPTURE_QUEUE_TYPES`, `fan_in`/`fan_out`, `upgrade_legacy_shape`; rewrite those passages as each PR lands.
