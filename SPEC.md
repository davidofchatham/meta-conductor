# SPEC

No active in-flight spec.

SPEC.md holds the active feature spec only while a feature is in flight (see CLAUDE.md → SPEC.md lifecycle). When empty, like now, the last feature has shipped and its spec was migrated out.

## Last shipped

**Phase 2a — PSR-4 namespacing** (merged to `main` via PR #18, `074ae57`; released under the combined `0.4.0`).

Where its content went:
- **Durable namespace rules** (namespace-before-guard, leading-backslash globals, no-consecutive-caps class names, `class-{kebab}.php` interface naming, run the harnesses after class changes) → CLAUDE.md → *Critical don'ts* #0.
- **Static enforcement** of those rules → `tests/lint.php` (V1: no manual plugin-file requires; V13: no unqualified global classes in code) + `tests/verify-autoload.php` (all FQNs resolve).
- **Bug history** (B1 namespace-after-guard; B2/B4/B5 unqualified globals in various positions; B3 require chains in method bodies) → fixed in git history, recurrence guarded by the two harnesses above. Not filed as issues (user files issues manually).
- **Phase status + discoveries** → ROADMAP.md → Phase 2a section.

## Next

Ruleset configuration change — spec to be written at the start of that work (new session). Start with the `spec` skill (NEW mode) once the change is described.
