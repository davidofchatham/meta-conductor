# SPEC — Admin Columns v7 integration fix (0.6.0, in flight)

Issue [#37](https://github.com/davidofchatham/meta-conductor/issues/37). Interim **Option 3** (AC-coupled, scoped). AC-agnostic **Option 2** = [#42](https://github.com/davidofchatham/meta-conductor/issues/42), NOT this spec.
NOT done: code unbuilt, InstaWP AC-v7 test gate unrun. §G/§C/§I/§V/§T/§B all present.
Source-verified against AC Pro v7.1 (`Resources/WordPress plugins/admin-columns-pro-7-1/`) + live config. Truncate on merge+tag.

## §G — goal

Make MC term-sync reapply on Admin Columns **v7** inline/bulk edits of ACF fields, across ALL ACF-listening handlers. Delete dead legacy-hook integration. Live-data safe (live site runs `related_post_terms` + `related` rules).

## §C — constraints

- C1. **Live data.** Live site runs `related_post_terms` PUSH rules (top-level fields `schedule_games` / `game_team_schedule_cpt`) AND `related` rules. No silent break, no re-save requirement. (CLAUDE.md schema-stability)
- C2. **PHP 8.1 coercive, no strict_types in handler files.** Leading-backslash every GLOBAL class under the plugin ns (`\AC\Column\Context`, `\ACP\Plugin`). (CLAUDE.md don't #0/§V13)
- C3. **Top-level ACF fields only.** Match by `get_meta_key()` name — valid for top-level; group-nested fields report the bare subfield name + aren't picker-listed. Nested = out of scope. (arch.md handler-invariant #6)
- C4. **AC-coupled is deliberate interim.** Fallback hooks AC's `ac/editing/saved` v7 API. AC-agnostic apply-on-`acf/update_value` = deferred (Option 2, pre-write timing hazard). (#37 plan)
- C5. **Gated test harness.** Behavior verify = manual InstaWP sweep w/ installed AC Pro v7 (inline + bulk). H1 (`php tests/lint.php`) + H2 (`php tests/verify-autoload.php`) after any class-file change. (project_test_environments)

## §I — surfaces

- I.integration = `includes/integrations/class-admin-columns-integration.php` — 476-line legacy-hook integration. **Delete.** Registers pre-v7 `acp/editing/saved` (3-arg), `acp/editing/bulk_saved`, `ac/column_types`, `ac/column/taxonomy*` — all dead on v7. Reapply purpose subsumed (native taxonomy self-covers; ACF path fixed via I.fallback).
- I.boot = `includes/class-taxonomy-manager.php:127-130` — `if (class_exists('AC\Plugin')) new AdminColumnsIntegration(...)`. Replace with I.fallback registration.
- I.base = `includes/handlers/class-unified-handler-base.php` — add `public function reapply_for_post(int $post_id): void {}` **no-op default** (near `process_post`, L799). The fallback calls this on EVERY handler blindly; only ACF-listening handlers override. Each override self-gates (post-type, rule-match) — a no-op for irrelevant edits.
- I.handlers = the 5 ACF-listening handlers OVERRIDE `reapply_for_post` = call their existing `on_acf_save_post($post_id)` (already the post-type/rule-gated, reentrancy-guarded apply body): `related-post-terms` (L143), `related` (L81), `hierarchical-level-restriction` (L93), `propagation` (L144), `title-slug` (L134). NOT the no-op `process_post`.
- I.fallback = NEW registration of `ac/editing/saved` (v7 4-arg). On an ACF-field column edit (`$column instanceof \AC\Column\CustomFieldContext`), iterate ALL handlers → `reapply_for_post($id)`. Guarded on `\ACP\Plugin` present + AC ≥ 7. Location: a thin registration (own small file or taxonomy-manager boot), given it spans all handlers — NOT inside one handler.
- I.event = `bws_process_post_after_column_update` scheduled-event registration (bottom of I.integration) — **delete** with I.integration.

## §V — invariants

- V1. **AC v7 native taxonomy edit self-covers; only ACF-field edit needs the fallback.** AC v7 `Editing\Storage\Post\Taxonomy::set_terms` → `wp_set_object_terms`/`wp_set_post_categories`/`wp_set_post_tags` (all fire `set_object_terms`) + `wp_update_post`. Handlers' own `set_object_terms`/`save_post` listeners catch it → NO AC code for native paths. ACF-field edit routes `FieldStorage::update` → `update_field` → fires `acf/update_value` ONLY (no save_post-family) → any `acf/save_post`-gated apply never runs. Fallback scope = ACF-field columns exclusively. (I.fallback, I.handlers)
- V6. **The gap is generic across ALL `acf/save_post`-gated handlers, not just related_post_terms.** `update_field` skipping `acf/save_post` breaks the apply of EVERY handler that gates on it: `related_post_terms`, `related`, `hierarchical-level-restriction`, `propagation`, `title-slug`. The fallback fixes ALL five uniformly via `reapply_for_post` (I.base default + I.handlers override) — no per-column-type→handler dispatch; each handler self-filters, mirroring how `acf/save_post` already fires for every post. Live-broken: `related_post_terms` (B1) + `related` (B2, live rules). Latent (no live rule yet): level-restriction, propagation, title-slug — wired now, swept on demand. (I.base, I.fallback)
- V2. **`reapply_for_post` = the handler's `on_acf_save_post` body, post-persist.** `ac/editing/saved` fires AFTER `$service->update` (AC `InlineSave.php:117` / `BulkSave.php:160`) → reads see the NEW value → each handler's `on_acf_save_post` (already sync/severed/gated) runs correctly. Do NOT hook `acf/update_value` for apply — it's pre-write (capture path deliberately reads OLD there); that's Option 2's hazard. (I.handlers)
- V3. **Fallback matches ACF-field columns by `$column instanceof \AC\Column\CustomFieldContext`, top-level only.** v7 ACF `$column` is `\ACA\ACF\Column\Context` (extends `\AC\Column\CustomFieldContext`). The fallback need NOT match field name/type — it hands `$id` to every handler's `reapply_for_post`, which re-reads the post's own fields + rules. Rules already store `acf_field_name`; each handler's apply matches internally. Top-level field ⇒ `get_meta_key()`/`$field['name']` == stored name; group-nested ⇒ bare name ⇒ silent miss — unsupported (arch.md #6, UI-warned). (I.fallback, C3)
- V4. **Delete leaves no dangling reference.** Removing I.integration must also remove I.boot instantiation + I.event registration + the `use BWS\MetaConductor\Integrations\AdminColumnsIntegration;` import (`class-taxonomy-manager.php:17`). Grep-confirm zero refs before delete. H1+H2 green. (I.integration, I.boot, I.event)
- V5. **Fallback is v7-gated and AC-absent-safe.** Register only when `\ACP\Plugin` exists AND AC ≥ 7 (legacy `acp/editing/saved` differs; site is pre-cutover on v6 NOW). On AC absent: no hook, no fatal. The old `class_exists('AC\Plugin')` boot gate (I.boot) becomes the fallback's gate. (I.fallback, C4)
- V7. **`reapply_for_post` must stay guard-safe under the AC path.** Each override delegates to `on_acf_save_post`, which self-guards (`$processing`/`$in_sync` reentrancy, `is_numeric`, autosave/revision, `should_process_post`). The fallback adds NO extra state — it iterates handlers once per edit. No double-apply vs. the editor path (that fires `acf/save_post`; the AC path does NOT, they never both fire for one edit). (I.handlers, I.fallback)

## §T — tasks

| id | st | task | cites |
|----|----|------|-------|
| T1 | . | Add `public function reapply_for_post(int $post_id): void {}` no-op default to `UnifiedHandlerBase` (near `process_post`). H1+H2. | V6,I.base |
| T2 | . | Override `reapply_for_post` in the 5 ACF-listening handlers = `$this->on_acf_save_post($post_id);`. (related-post-terms, related, level-restriction, propagation, title-slug.) H1+H2. | V2,V6,V7,I.handlers |
| T3 | . | Register `ac/editing/saved` (4-arg `\AC\Column\Context $column, $id, $value, \AC\TableScreen $table`), v7+ACP gated. On `$column instanceof \AC\Column\CustomFieldContext` → for each handler call `reapply_for_post($id)`. | V1,V3,V5,I.fallback |
| T4 | . | Delete `class-admin-columns-integration.php` (I.integration) + `bws_process_post_after_column_update` event (I.event). Remove instantiation + import in `class-taxonomy-manager.php` (L17 use, L127-130 boot). Grep-confirm zero refs. H1+H2. | V4,I.integration,I.boot,I.event |
| T5 | . | InstaWP AC-Pro-v7 sweep, LIVE handlers: inline + bulk Quick-Edit `schedule_games` (schedule) + `game_team_schedule_cpt` (event) → `related_post_terms` rules re-sync. Quick-Edit a `related`-rule ACF trigger field → `related` re-syncs. Regression: editor save still syncs, no double-apply. | V1,V2,V6,C5 |
| T6 | - | Sweep latent handlers (level-restriction, propagation, title-slug) via AC ACF-column edit — deferred, on demand when a live rule of that type exists. Wired (T2) but unswept. | V6,C5 |

## §B — bugs

| id | date | cause | fix |
|----|------|-------|-----|
| B1 | 2026-07-01 | AC v7 ACF-relationship inline/bulk edit writes via `update_field` → fires `acf/update_value` only; `related_post_terms` apply (sync_for_post/process_severed) gated on `acf/save_post`/`save_post`/`set_object_terms` → captures diff, never applies → athletics sync silently dead on v7 Quick-Edit. Editor saves unaffected. | V1,V2 (reapply_for_post via post-persist `ac/editing/saved`) |
| B2 | 2026-07-01 | Same root cause as B1, generalized: EVERY `acf/save_post`-gated handler's apply is skipped on AC v7 ACF-column edit — `related` (LIVE), `hierarchical-level-restriction`, `propagation`, `title-slug` (latent, no live rule yet). #37 fixes all via the shared `reapply_for_post` seam; latent ones wired but swept on demand. Broader AC-agnostic fix (apply-on-`acf/update_value`, all paths incl. `update_field()`/REST) = Option 2, [#42](https://github.com/davidofchatham/meta-conductor/issues/42). | V6 + #42 (Option 2) |
