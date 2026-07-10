# SPEC

No feature in flight.

This file is a placeholder. A `SPEC.md` lives at repo root only while a substantive feature is being built (see the `spec` skill). After ship, post-ship cleanup migrates load-bearing invariants into PHPDoc on the enforcing function (or `docs/architecture.md` when conceptual), files open bugs/refactors as GitHub Issues, and truncates this file.

## Last shipped

**0.6.0 — Admin Columns Pro v7 integration fix** (#37; merged to `main` via PR #44, 2026-07-10; released as part of 0.6.0).
The pre-v7 Admin Columns integration was dead on AC/ACP v7 (legacy hook names + a wrong `class_exists('ACP\Plugin')` gate that is always false on v7). Replaced by a shared `UnifiedHandlerBase::reapply_for_post(int)` seam (no-op default; the five ACF-listening handlers override it) driven by a v7 `ac/editing/saved` fallback in `TaxonomyManager`, gated on `defined('ACP_VERSION')`. The 476-line legacy integration + its scheduled reapply event were deleted. Verified on a live AC Pro v7 site (add-sync path).

Invariants migrated to `docs/architecture.md` ("Writing a rule handler — hard-won invariants") item **#13** (integration writes through the plugin's real write hook + detects the plugin by a verified surface, not an assumed class name — was §V1/§V5/§V6/§V7, B3). The ACF group-prefix name trap (discovered alongside) folded into item **#6**. Regression guard: `tests/verify-acp-gate.php` (H7) fails if the wrong `class_exists('ACP\Plugin')` gate reappears.

Open follow-ups filed as GitHub Issues: [#42](https://github.com/davidofchatham/meta-conductor/issues/42) (AC-agnostic apply-on-`acf/update_value`, retires the AC-coupled fallback), [#43](https://github.com/davidofchatham/meta-conductor/issues/43) (ACF Reference does not strip a synced term when the DEPENDENT end drops a bidirectional relationship — pre-existing, editor-reproducible). Deferred task: sweep the latent ACF-listening handlers (level-restriction, propagation, title-slug) via an AC ACF-column edit once a live rule of those types exists (was §T6).
