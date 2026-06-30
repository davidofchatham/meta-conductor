# SPEC

No feature in flight.

This file is a placeholder. A `SPEC.md` lives at repo root only while a substantive feature is being built (see the `spec` skill). After ship, post-ship cleanup migrates load-bearing invariants into PHPDoc on the enforcing function (or `docs/architecture.md` when conceptual), files open bugs/refactors as GitHub Issues, and truncates this file.

## Last shipped

**0.5.0 — ACF-reference rework + Phase-3 handler migration** (tagged `v0.5.0`, 2026-06-30; release ZIP published).
Declarative source-authoritative term sync between a post and its ACF-related posts (holder + `holder_role` direction; full bidirectional triggering; 3-tier reverse-field resolution; source-scoped status gate; idempotent short-circuit; both-direction sever on relationship-edit AND permanent-delete). Legacy `AcfIntegration` shadow engine deleted. Disabled rules flagged in the collapsed row title.

Invariants now live in `includes/handlers/class-related-post-terms-handler.php` PHPDoc (V1/V3–V6/V10–V17 + the pull-edit sever symmetry, B8) and `includes/storage/class-option-rule-storage.php` (V2/V8/V16), plus `docs/architecture.md` (V7/V16, and the 8 cross-handler invariants). Deferred enhancements + known gaps: GitHub Issues [#22](https://github.com/davidofchatham/meta-conductor/issues/22) (tier-3 perf), [#23](https://github.com/davidofchatham/meta-conductor/issues/23), [#25](https://github.com/davidofchatham/meta-conductor/issues/25), [#27](https://github.com/davidofchatham/meta-conductor/issues/27), [#28](https://github.com/davidofchatham/meta-conductor/issues/28), [#29](https://github.com/davidofchatham/meta-conductor/issues/29) (multi-taxonomy per rule), [#30](https://github.com/davidofchatham/meta-conductor/issues/30) (live disabled-rule indicator). Design history: `.claude/plans/archive/acf-reference-rework.md`.
