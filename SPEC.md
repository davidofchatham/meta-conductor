# SPEC

No feature in flight.

This file is a placeholder. A `SPEC.md` lives at repo root only while a substantive feature is being built (see the `spec` skill). After ship, post-ship cleanup migrates load-bearing invariants into PHPDoc on the enforcing function (or `docs/architecture.md` when conceptual), files open bugs/refactors as GitHub Issues, and truncates this file.

## Last shipped

**0.5.0 — ACF-reference rework + Phase-3 handler migration** (branch `claude/acf-reference-p3`).
Declarative source-authoritative term sync between a post and its ACF-related posts (holder + `holder_role` direction; full bidirectional triggering; 3-tier reverse-field resolution; source-scoped status gate; idempotent short-circuit). Legacy `AcfIntegration` shadow engine deleted. Invariants now live in `includes/handlers/class-related-post-terms-handler.php` PHPDoc (V1/V3–V6/V10–V17), `includes/storage/class-option-rule-storage.php` (V2/V8/V16), and `docs/architecture.md` (V7/V16). Deferred enhancements: `docs/future-features.md` + GitHub Issues [#22](https://github.com/davidofchatham/meta-conductor/issues/22), [#23](https://github.com/davidofchatham/meta-conductor/issues/23). Design history: `.claude/plans/archive/acf-reference-rework.md`.
