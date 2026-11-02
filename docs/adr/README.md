# Architecture decision records

This directory holds no ADRs. The four that lived here (0001–0004) were retired in FW-48: each decision moved to the place that owns the code fact it explains, with its rejected alternatives beside it, and the file was deleted. A parallel record drifts; a decision beside the invariant it justifies is read whenever the invariant is.

**Do not mint an ADR as part of ordinary work.** Put the decision where the table below says. The numbers stay retired (the next would be 0005), so an old citation still resolves through the map.

## Where the retired ADRs went

| Old | What it decided | Home now |
|---|---|---|
| 0001 | Temporal rule: the general interval-action model behind a constrained UI | Unbuilt, so the [FW-3](../future-work.md) row (*Model decision*). Its no-provenance half is shipped: [architecture.md](../architecture.md) invariant 19, *Rejected: provenance*. |
| 0002 | Cross-rule composition: cascade suppressed, order explicit, collisions detected | Cascade and fixed type order: invariant 17 *Rejected*. Collisions detected not resolved, partitioning, provenance: invariant 19. Deferred rule-type renaming and restricting sub-scope: FW-14 and FW-12. |
| 0003 | One ordered rule list per effect kind, run by a central dispatcher | One repeater per kind and the rejected repeater shapes: [The ordered rule repeaters](../architecture.md#the-ordered-rule-repeaters). Dispatcher and pass lock: invariant 17. Derived cross-kind order: invariant 18. Stored shape: [Effect-kind rule lists](../architecture.md#effect-kind-rule-lists). The page-split rejection: [storage-model.md](../storage-model.md). The no-pre-write-phase outcome: [Field effects ride the `$data` seam](../architecture.md#field-effects-ride-the-data-seam). |
| 0004 | The claim axis has four values; jurisdiction is a first-class term | The glossary: [CONTEXT.md](../../CONTEXT.md) *Claim* and *Jurisdiction*, each with its `Rejected` list. The collision conjunct: invariant 19. The one-vocabulary rule: PHPDoc on `ConfigHelpers::CLAIM_NAMES`. |

## Where a decision lives

| Decision about | Home |
|---|---|
| A term, and why it beat the names that lost | the glossary entry in `CONTEXT.md`, as a `Rejected` line |
| An invariant, and why it is the rule | the anchor in `docs/architecture.md` that owns it, as a **Rejected** list |
| A per-class enforced invariant | PHPDoc on the enforcing class |
| A pattern with no rejected alternative | PHPDoc on the code that enforces it, and a pointer from the invariant |
| A build or integration recipe | a comment at the config or call it explains |
| A proposal not yet accepted | an `FW-N` row in [docs/future-work.md](../future-work.md) |
| An accepted debt (a deferral, not a decision) | its existing `FW-N` item |
| A process decision | `CLAUDE.md` and `docs/agents/`; `git log` on them is the record |
| A removal or a behavior change | the CHANGELOG entry |

Cite the anchor, not an ADR number, in code and docs.

## The bar, if one is ever written

An ADR is the exception and argues for itself. All six must hold:

1. **Hard to reverse.** Changing your mind costs real work: a storage shape, a wire format, a taxonomy slug. Not a naming or layout preference.
2. **Surprising without context.** A reader asks *"why this way?"*, not *"what is this called?"*
3. **A real trade-off.** Genuine alternatives existed, and you can name the one you rejected and why it lost.
4. **Not same-session.** The decision has survived at least one attempt to reopen it. Still being designed means spec content in `.scratch/`.
5. **Shipped.** No `proposed` ADRs. Unbuilt intent is an `FW-N` row.
6. **Never vocabulary.** A naming decision is a glossary entry with a `Rejected` line.

Process decisions never qualify, whatever they score. Clearing the bar makes an ADR eligible, not required: if an anchor's Rejected list can carry the decision, put it there, because a file beside it is a second owner. Keep a file only when no single anchor can hold the decision, for example because it spans several anchors or lives outside the code, and give it a row in the map above.
