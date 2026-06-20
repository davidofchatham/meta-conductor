# SPEC

Active feature: **Phase 3b — multi-trigger-term Related Term Mapping + term-option ordering.**

## §G — goal

Related rule fire on ANY of N trigger terms (OR), not one. Fix term-picker order. Drop dead max-1 search lock.

## §C — constraints

- C1. Pre-1.0, related rule LIVE on author site. No migration code. CORRECTED: raw stored trigger array already survives round-trip → multi-trigger fires on all terms after update with NO re-save (behavior). Re-save refreshes stale LABEL only. CHANGELOG = heads-up note, not forced re-save. (V11)
- C2. Trigger MULTI. Target stays SINGLE (one mapping target/rule, `max=>1`).
- C3. OR semantics: rule trigger if post has ≥1 listed trigger term.
- C4. No client-side Wireframe dynamic-options API (CLAUDE.md don't #5) → cascading taxonomy-first picker DEFERRED, not this spec.
- C5. PHP 8.1 coercive (no strict_types in handler/storage). Leading-backslash globals under ns (don't #0).
- C6. Re-run H1 (`php tests/lint.php`) + H2 (`php tests/verify-autoload.php`) after class edits.

## §I — surfaces

- I.config = `includes/admin/config/class-related-config.php` — `trigger_term_id` subfield (`multiple=>true`, drop `max=>1`); repeater `args` (+`collapsed=>true`).
- I.storage = `includes/storage/class-option-rule-storage.php` `normalize_rule_shape()` — adapter boundary, currently array→int collapse.
- I.handler = `includes/handlers/class-related-handler.php` — 5 read sites scalar `trigger_term_id`.
- I.label = `includes/admin/class-wireframe-bootstrap.php` `term_label()` / `snapshot_related_labels()`.
- I.helpers = `includes/admin/config/class-config-helpers.php` `all_term_options()`.
- I.changelog = `CHANGELOG.md`, I.future = `docs/future-features.md`.

## §V — invariants

- V1. Raw stored shape already keeps full trigger array (round-trips in UI). Bug is at the NORMALIZE boundary: `normalize_rule_shape` (read-time, `get_rules`) collapses `trigger_term_id` `[a,b]`→`int a`, so handlers see only the first → 2nd+ trigger silently inert. Fix: normalized canonical = `int[]` (deduped, zeros dropped, order-insensitive). `target_term_id` stays scalar `int`. Split trigger OUT of the single-term collapse path. (I.storage L205-223)
- V2. Handler reads `trigger_term_id` as `int[]` everywhere. Empty array ⇒ rule inert (never fires). Never `get_term($rule['trigger_term_id'])` on the array.
- V3. OR fire: trigger satisfied if post has ANY listed trigger term (`get_trigger_terms`); change-detection fires if ANY listed term added/removed (`should_trigger_related_terms`, `apply_related_terms`).
- V4. Bidirectional remove only when ALL trigger terms gone from post (removing one of two triggers, other still present ⇒ target stays). Apply on ANY trigger present.
- V5. ACF path (`process_acf_related_terms`): trigger taxonomy set = union of taxonomies of all trigger terms. Fire if any trigger-term taxonomy field updated.
- V6. validate_rule_internal: term-type rule valid iff `trigger_term_id` non-empty AND every id resolves via `\get_term`. Any unresolvable id ⇒ invalid.
- V7. Label snapshot: `trigger_label` = all trigger term labels joined `", "` (taxonomy-type unchanged, single taxonomy label). Each `\esc_html`.
- V8. `all_term_options()`: taxonomies sorted by label ASC; terms `orderby=name order=ASC`. Stable, alpha, not ID-ish.
- V9. Target single-value collapse (`target_term_id` array→int) PRESERVED — do not regress when splitting V1.
- V10. Related repeater `args.collapsed=>true` ⇒ all rows start collapsed on load (Wireframe bundle: `g=l.collapsed`, init collapsed-index set = all rows). Config arg, NOT vendor patch. Rows still click-expand. Orientation aid for many-rule lists.
- V11. Re-save semantics: existing rules' raw stored `trigger_term_id` already holds full array → multi-trigger fires on ALL terms immediately after code update, NO re-save for behavior. Re-save refreshes stale row LABEL only. CHANGELOG note = heads-up (stray 2nd trigger now active), NOT a forced migration. C1 corrected.
- V14. "Any trigger still present" (bidirectional ALL-gone check) MUST query the post's real terms across every taxonomy (`get_trigger_terms` / `post_has_terms`), NOT the `set_object_terms` hook payload `$new_tt_ids`. The hook fires per-taxonomy and `$new_tt_ids` holds only that taxonomy's tt_ids; trigger terms span taxonomies (picker lists all). A payload check sees a cross-taxonomy trigger as absent ⇒ wrongly removes target ⇒ violates V4. (B2)
- V12. tt_id comparisons in RelatedHandler: `WP_Term->term_taxonomy_id` is int; `set_object_terms` `$tt_ids`/`$old_tt_ids` (+`array_diff` results) are strings. Strict `in_array(int, string[], true)` ⇒ always false ⇒ rule never fires. Int-normalize BOTH sides (`array_map('intval', …)` on tt_id arrays at fn entry; `(int) $term->term_taxonomy_id`) THEN strict-compare. Never strict-compare raw hook tt_ids against a cast term property. (B1)

## §T — tasks

| id | st | task | cites |
|----|----|------|-------|
| T1 | x | storage: split `trigger_term_id` into int[] normalizer (dedupe, drop 0); keep target int collapse | V1,V9,I.storage |
| T2 | x | config: drop `max=>1` on `trigger_term_id` (keep `multiple=>true`) | C2,I.config |
| T3 | x | handler get_trigger_terms: loop int[] triggers, OR collect | V2,V3,I.handler |
| T4 | x | handler should_trigger_related_terms + apply_related_terms: ANY trigger added/removed; apply-on-any | V3,V4,I.handler |
| T5 | x | handler apply_related_terms bidirectional: remove target only when ALL triggers absent | V4,I.handler |
| T6 | x | handler process_acf_related_terms: union trigger taxonomies | V5,I.handler |
| T7 | x | handler validate_rule_internal: non-empty + every id resolves | V6,I.handler |
| T8 | x | label term_label/snapshot: join all trigger labels ", " esc each | V7,I.label |
| T9 | x | helpers all_term_options: sort tax by label, terms name ASC | V8,I.helpers |
| T13 | x | config: repeater `args.collapsed=>true` (rows start collapsed) | V10,I.config |
| T10 | x | CHANGELOG: feature + heads-up (no forced re-save; stray 2nd trigger now fires; re-save only for label) | C1,V11,I.changelog |
| T11 | x | future-features: log deferred taxonomy-first cascading picker | C4,I.future |
| T12 | x | H1+H2 green; InstaWP sweep: single + multi-trigger OR + bidirectional partial-remove | C6 |
| T14 | x | handler: int-normalize tt_id arrays + cast term_taxonomy_id; strict compare on ints (should_trigger 2x, apply 3x) | V12,I.handler |
| T15 | x | handler apply_related_terms: any_trigger_present via get_trigger_terms (real post state), not hook payload | V14,I.handler |

## §B — bugs

| id | date | cause | fix |
|----|------|-------|-----|
| B1 | 2026-06-19 | Multi-trigger rewrite used strict `in_array(term_taxonomy_id, tt_ids, true)`; `WP_Term->term_taxonomy_id` int, `set_object_terms` `$tt_ids` strings → always false → NO related rule fires (incl. single-trigger that worked before). Old code loose `in_array`. | V12, T14 |
| B2 | 2026-06-19 | Bidirectional `any_trigger_present` checked hook payload `$new_tt_ids` (single taxonomy) instead of real post state. Cross-taxonomy trigger set → removing one trigger looks like ALL-gone → target wrongly removed though another trigger still on post. Found by code-review (CONFIRMED). | V14, T15 |
