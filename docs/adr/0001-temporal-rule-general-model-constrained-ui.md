---
status: accepted
---

# Temporal Rule: general interval-action model behind a constrained 0.x UI

The Temporal Rule could ship as a fixed three-state machine (before/during/after, one term each). Instead the **storage shape and handler implement the general model** — a rule holds optional **boundaries** and a list of **actions**, each an `{interval, mode, applied term}` scoped to a **phase**, with per-boundary **offsets** and **fallback durations**. The 0.x settings UI only *generates* a constrained subset of that model (a fixed set of phase slots plus about-to-start/just-started/about-to-end/just-ended presets); a later release will expose a free action builder writing the **same** `actions[]` array. We chose this because the storage shape is the hard-to-change contract: building the general model now means the future builder needs no data migration, at the cost of carrying handler complexity (overlap modes, phase reconciliation) that the shipped UI does not yet fully exercise.

## Considered options

- **Fixed 3-state model, migrate later** — simplest for 0.x, but the free builder would force a storage migration of live rules. Rejected: migration of user data is the expensive, error-prone path we're paying complexity now to avoid.
- **Defer about-to-start/end entirely to the post-1.0 builder** — drops a feature the user called highly desirable. Rejected.
- **Track provenance** (record which terms the rule applied, remove only those) — safer against manual/foreign tags, but adds per-rule-per-post bookkeeping meta, can't retroactively correct posts tagged before the rule existed, and needs cleanup on rule deletion. Rejected in favour of config-based ownership: the rule's configured applied-term set already enumerates the term-space it governs, so reconciliation needs no stored state.

## Consequences

- **No provenance.** A rule owns exactly its configured applied terms (its *phase term-space*) and recomputes correctness from boundaries + config each evaluation; it keeps no record of what it applied. Reconciling a post to its active phase removes any configured applied term belonging to a non-active phase — even a manual or pre-existing tag matching a configured term. Terms outside the configured set are never touched. (See ADR alternative below; provenance was considered and rejected.)
- This makes ownership **retroactive**: a rule corrects posts tagged before the rule existed, with no migration. The accepted cost is that a manual tag colliding with a configured applied term is swept.
- Two Temporal rules naming the same term whose source sets can match the same post may fight; this **collision** is detected and warned (non-blocking) at settings save, keyed on post_type overlap. Disjoint post types are not a collision.
- The handler must implement within-phase **stack/replace** semantics even though the 0.x UI keeps phase slots mutually exclusive, so behaviour is well-defined when the free builder later produces overlapping actions.
- Storage stays **Options** for the 0.x line (extended `time_based_rules` shape, normalised through the canonical-shape adapter, no dot-notation). The earlier plan to gate the per-post ACF-date feature on Phase 4 CPT storage is dropped; CPT migration happens later alongside the existing `time_based_rules` migration.
