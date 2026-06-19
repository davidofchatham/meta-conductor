# SPEC

**Feature:** Multi-post-type Related Term Mapping + hierarchical UX + post-types field module
**Phase:** 3a (first legacy-handler migration)
**Branch:** `claude/related-multi-pt-3a`

## §G — goal

Related Term Mapping rules apply across MANY post types (was: single). Migrate RelatedHandler off legacy HandlerBase → UnifiedHandlerBase (enables multi-PT via base's `should_process_post`). Modularize post-types config field so every rule type shares one definition. Fix hierarchical post-types UX (label, empty=all clarity, field order).

Driver: user maps 28 terms → 17 terms across taxonomies, spanning several post types. One rule per post-type pair = unworkable.

## §C — constraints

- C1. 0.x pre-release, no production deploy. NO migration of saved rules — schema change fair game. Old scalar `post_type` data re-saved manually on test site.
- C2. No legacy-compat code (per CLAUDE.md).
- C3. PSR-4 namespace rules apply (CLAUDE.md don't #0): namespace-before-guard, leading-backslash globals, kebab class files, run H1+H2 after class changes.
- C4. All rule reads go through `StorageFactory::get_instance()->get_rules()` (don't #1). No direct `get_option`.
- C5. No `normalize_rule_shape()` change — UnifiedHandlerBase eats Wireframe `{slug:bool}` map natively.
- C6. No rule-collision/overlap detector exists in codebase — none added. Apply-time model stays additive (all matching rules fire, terms merge). `conflict_handling_overrides` setting operates on resolved terms per-post, blind to rule scope — unchanged.
- C7. Scope = 2 rule types only: hierarchical (config UX) + related (full migration). Propagation / title-slug / etc. deferred to later Phase 3 steps.

## §I — surfaces

- I.related-cfg ! `includes/admin/config/class-related-config.php` — Related rule Wireframe config (RelatedConfig::section).
- I.related-h ! `includes/handlers/class-related-handler.php` — RelatedHandler.
- I.hier-cfg ! `includes/admin/config/class-hierarchical-config.php` — HierarchicalConfig::section.
- I.helpers ! `includes/admin/config/class-config-helpers.php` — ConfigHelpers (option factories).
- I.unified-base ! `includes/handlers/class-unified-handler-base.php` — UnifiedHandlerBase::should_process_post (multi-PT read logic, lines 388-399).
- I.storage ! `includes/storage/class-option-rule-storage.php` — normalize_rule_shape (NOT touched, C5).
- I.bootstrap ! `includes/admin/class-wireframe-bootstrap.php` — WireframeBootstrap::boot (admin/REST-gated). Wireframe owns the `bws_meta_conductor_settings` option save (REST → update_option). Hook point for save-time label snapshot.
- I.h1 ! `php tests/lint.php`. I.h2 ! `php tests/verify-autoload.php`.

## §V — invariants

- V1. Post-types config field defined ONCE: `ConfigHelpers::post_types_field(array $overrides=[])` returns the full `checkboxes` subfield. Rule configs call it, never inline the block. Field id = `post_types`, type `checkboxes`, NO `''` placeholder option (checkboxes need none).
- V2. Post-type-scoped rule reads `post_types` (array/map), NEVER scalar `post_type`. All gating goes through `UnifiedHandlerBase::should_process_post`. Empty/unchecked `post_types` ⇒ applies to ALL post types using the taxonomy.
- V3. RelatedHandler `extends UnifiedHandlerBase`, implements `get_handler_type()` (`'related'`) + `get_rule_type()` (`'related_rules'`). NO surviving `rule_applies_to_post` calls (that method dies with HandlerBase). Per-rule gating = `should_process_post($post_id, $rule)`.
- V4. RelatedHandler fires via its own hooks (`set_object_terms`, `acf/save_post`). `process_post()` body is a no-op (mirror HierarchicalHandler:46) — base `process_post` routes through RuleEngine which related does not use.
- V5. Related-specific validation (trigger/term/taxonomy checks) lives in `validate_rule_internal()` override, NOT a standalone `validate_rule()`. NO post_type required-check (multi now, empty=all per V2).
- V6. UI label for post-types field = "Limit to post types"; description states all-unchecked = every post type. (Was "Post types (optional)" + ambiguous desc.)
- V7. Hierarchical config `post_types` field positioned after `taxonomy`, before `hierarchy_direction` (scope before behavior).
- V8. After any class-file edit: H1 (lint) + H2 (autoload) both green before sync. RelatedHandler FQN present in H2 list.
- V9. Wireframe repeater `title_template` MUST NOT reference an array subfield token (`{post_types}` → renders "Array"). Use a scalar token, static text, or a persisted scalar label field (see V11).
- V11. Related-rule row title = "{trigger_label} → {target_label}" (no "Related:" prefix). Label shape mirrors `all_term_options()` ("Tax: Term"): a TERM resolves to "<taxonomy label>: <term name>"; a taxonomy trigger (trigger_type==='taxonomy', "any term") resolves to "<taxonomy label>" alone. target_label is always a term. e.g. "Shakers: Parent 1 → Breakers: Grandchild ii" or "Shakers → Breakers: Grandchild ii". When the rule is limited to specific post types (non-empty post_types), a snapshot `scope_label` suffix is appended: " (Posts, Pages)". Empty post_types (all) ⇒ scope_label = '' (no suffix). The leading " (" and trailing ")" decoration lives INSIDE scope_label so the template (`{trigger_label} → {target_label}{scope_label}`) stays a dumb concat and the all-types case renders no trailing space. Post-type labels resolved from the `{slug:bool}` checkbox map via get_post_type_object()->label. Wireframe `title_template` does raw value substitution only (`X()`: `tmpl.replace(/{(\w+)}/g, k => String(values[k] ?? ''))` — no label/ID resolution). So `trigger_label`/`target_label` MUST be persisted into each related_rules row at save time. Resolver hooks the Wireframe-provided `wp-wireframe/save/payload` filter (SettingsController, runs AFTER Sanitizer, purpose: "inject derived fields") — NOT update_option_* (cleaner; injecting post-sanitize means the labels survive even though they're not declared editable). Filter walks `$cleanValues['related_rules']` (a top-level repeater key, fully present each save — Wireframe merges only top-level keys), resolves the stored term ID → term name. MUST defensively accept both `[N]` (FormTokenField max=1 raw shape) and scalar `N`. For `trigger_type === 'taxonomy'`, trigger_label = taxonomy label, not a term. Labels are a SAVE-TIME SNAPSHOT — renaming a term shows the stale name until re-save (accepted, C1/0.x).
- V10. Generic term-utility primitives needed by migrated handlers — `apply_terms_to_post`, `remove_terms_from_post`, `post_has_terms`, `debug_log` — live on UnifiedHandlerBase (were HandlerBase-only). Any handler migrated off HandlerBase inherits them from the new base, not by copy. Behavior identical to the HandlerBase originals (conflict_handling merge/replace/skip; debug guarded by WP_DEBUG).

## §T — tasks

| id | st | task | cites |
|----|----|------|-------|
| T1 | x | Add `post_types_field()` + `post_types_checkbox_options()` to ConfigHelpers (no-placeholder variant of post_type_options) | V1,V6,I.helpers |
| T2 | x | HierarchicalConfig: replace inline post_types block w/ `ConfigHelpers::post_types_field()`; move field after taxonomy; drop private `get_post_type_options()` | V1,V6,V7,I.hier-cfg |
| T3 | x | RelatedConfig: swap `post_type` select → `ConfigHelpers::post_types_field()`; fix `title_template` off array token | V1,V9,I.related-cfg |
| T11 | x | **(run before T4)** Port 4 term-utility helpers (apply_terms_to_post, remove_terms_from_post, post_has_terms, debug_log) from HandlerBase → UnifiedHandlerBase, verbatim behavior. Leading-backslash any globals | V10,I.unified-base |
| T4 | x | RelatedHandler: `extends UnifiedHandlerBase`; add get_handler_type/get_rule_type; drop Phase-3 TODO comment | V3,V10,I.related-h,I.unified-base |
| T5 | x | RelatedHandler: replace 3× `rule_applies_to_post` (L51,75,104) → `should_process_post` | V2,V3,I.related-h |
| T6 | x | RelatedHandler: `process_post()` → no-op body | V4,I.related-h |
| T7 | x | RelatedHandler: move term/trigger checks into `validate_rule_internal()` override; drop scalar post_type validate + sanitize | V5,I.related-h |
| T8 | x | Run H1+H2; confirm RelatedHandler in H2 FQN list | V8,I.h1,I.h2 |
| T9 | x | Sync R:; InstaWP sweep: 2-PT related rule fires on both PTs; empty=all; hier relabel+reorder renders; old single-PT rule re-saved resolves; overlap (multi-PT related + hierarchical on one post) merges additively, conflict_handling unchanged; related row title shows "<tax>: <term> → <tax>: <term>" after save (term trigger) and "<tax> → <tax>: <term>" (taxonomy trigger); post-type-limited rule appends " (Posts, …)", all-types appends nothing; stale-after-rename caveat confirmed | V2,V6,V7,V11,C1,C6 |
| T12 | x | Declare `trigger_label` + `target_label` as `hidden` subfields in RelatedConfig (Wireframe HiddenField exists) — keeps title_template tokens valid + documents row shape. Persistence comes from T14's filter, not the field decl | V11,I.related-cfg |
| T13 | x | RelatedConfig title_template → `Related: {trigger_label} → {target_label}` (supersedes T3 placeholder) | V11,I.related-cfg |
| T15 | x | Append post-type scope: hidden `scope_label` subfield; resolver builds " (Label, Label)" from non-empty post_types map (get_post_type_object()->label), '' when all; template → `{trigger_label} → {target_label}{scope_label}` | V11,I.related-cfg,I.bootstrap |
| T14 | x | Label-snapshot resolver: add `wp-wireframe/save/payload` filter (registered in WireframeBootstrap::boot); walk `$cleanValues['related_rules']`; resolve term ID ([N] or N) → name into trigger_label/target_label; taxonomy-trigger → taxonomy label. Leading-backslash globals | V11,I.bootstrap |
| T10 | x | CHANGELOG 0.4.0 entry (replace the placeholder comment user flagged); ROADMAP Phase 3 step 1 marked done; CLAUDE.md branch-state note | C7 |

## §B — bugs

| id | date | cause | fix |
|----|------|-------|-----|
| B1 | 2026-06-19 | T4 migration: RelatedHandler calls 4 term-utility helpers (apply_terms_to_post, remove_terms_from_post, post_has_terms, debug_log) living only on legacy HandlerBase; `extends` swap would fatal. Spec missed shared-primitive dependency. | V10 + T11 (port helpers to base before migrating) |
