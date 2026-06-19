# SPEC

No active in-flight spec.

SPEC.md holds the active feature spec only while a feature is in flight (see CLAUDE.md → SPEC.md lifecycle). When empty, like now, the last feature has shipped and its spec was migrated out.

## Last shipped

**Phase 3a — multi-post-type Related Term Mapping + UnifiedHandlerBase migration** (merged to `main` via PR #19, `f71d695`; shipped under `0.4.0`).

Where its content went:
- **Handler-migration rules** (two bases coexist; `UnifiedHandlerBase` carries the typed term primitives; the `extends`-flip TypeError gotcha; `should_process_post` post-type gating) — SPEC §V10/§V12 → CLAUDE.md → *Critical don'ts* #6.
- **Self-documenting invariants** (the `post_types_field()` single-source factory §V1; the label-snapshot mechanism §V11; the Wireframe `title_template` raw-substitution constraint §V9) — left as the in-code comments that already carry them (`ConfigHelpers`, `WireframeBootstrap`, `RelatedConfig`).
- **Bug history** (B1 — RelatedHandler depended on 4 HandlerBase-only term helpers; the `extends`-swap would fatal) — fixed in PR #19 (helpers ported to the new base before migrating); recurrence guarded by CLAUDE.md don't #6.
- **Deferred review follow-ups** (re-entrancy window in `on_terms_set`; `validate_rule_internal` taxonomy-blind term check; `scope_label` baked delimiters; snapshot-helper unit tests) → docs/future-features.md → *Phase 3a code-review follow-ups (PR #19)*.
- **Phase status** → ROADMAP.md → Phase 3 (step 1 done).

## Next

Phase 3 step 2: migrate `class-hierarchical-level-restriction-handler.php` to `UnifiedHandlerBase` (see ROADMAP.md). Start with the `spec` skill (NEW mode) once the change is described.
