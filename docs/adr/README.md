# Architecture decision records

This directory holds no ADRs. The four that lived here (0001–0004) were retired in FW-48: each decision moved to the place that owns the code fact it explains, with its rejected alternatives beside it, and the file was deleted. A parallel record drifts; a decision beside the invariant it justifies is read whenever the invariant is.

**Do not mint an ADR as part of ordinary work.** Put the decision where the table below says. The numbers stay retired (the next would be 0005), so an old citation still resolves through the map.

## Where the retired ADRs went

| Old | What it decided | Why retired | Home now |
|---|---|---|---|
| 0001 | Temporal rule: the general interval-action model behind a constrained UI | Unbuilt at the time. | Unbuilt, so the [FW-3](../future-work.md) row (*Model decision*). Its no-provenance half is shipped: [architecture.md](../architecture.md) invariant 19, *Rejected: provenance*. |
| 0002 | Cross-rule composition: cascade suppressed, order explicit, collisions detected | Amended by 0003 and 0004 within days; a closed set of invariants. | Cascade and fixed type order: invariant 17 *Rejected*. Collisions detected not resolved, partitioning, provenance: invariant 19. Deferred rule-type renaming and restricting sub-scope: FW-14 and FW-12. |
| 0003 | One ordered rule list per effect kind, run by a central dispatcher | A closed set: one stored shape and the invariants around it. | One repeater per kind and the rejected repeater shapes: [The ordered rule repeaters](../architecture.md#the-ordered-rule-repeaters). Dispatcher and pass lock: invariant 17. Derived cross-kind order: invariant 18. Stored shape: [Effect-kind rule lists](../architecture.md#effect-kind-rule-lists). The page-split rejection: [storage-model.md](../storage-model.md). The no-pre-write-phase outcome: [Field effects ride the `$data` seam](../architecture.md#field-effects-ride-the-data-seam). |
| 0004 | The claim axis has four values; jurisdiction is a first-class term | Vocabulary (a glossary entry) plus one invariant. | The glossary: [CONTEXT.md](../../CONTEXT.md) *Claim* and *Jurisdiction*, each with its `Rejected` list. The collision conjunct: invariant 19. The one-vocabulary rule: PHPDoc on `ConfigHelpers::CLAIM_NAMES`. |

## Where a decision lives

| Decision about | Home |
|---|---|
| A term, and why it beat the names that lost | the glossary entry in `CONTEXT.md`, as a `Rejected` line |
| An invariant, and why it is the rule | the anchor in `docs/architecture.md` that owns it, as a **Rejected** list |
| A principle: a rule for designs not yet made | a Principles section of `docs/architecture.md` (added when the first one needs stating), as a **Rejected** list |
| A per-class enforced invariant | PHPDoc on the enforcing class |
| A pattern with no rejected alternative | PHPDoc on the code that enforces it, and a pointer from the invariant |
| A build or integration recipe | a comment at the config or call it explains |
| A proposal not yet accepted | an `FW-N` row in [docs/future-work.md](../future-work.md) |
| A decision accepted on work not yet built | the `FW-N` row, with its **Rejected** list and a line saying where that list moves at ship; it moves to an invariant or glossary entry before the row closes |
| An accepted debt (a deferral, not a decision) | its existing `FW-N` item |
| A process decision | `CLAUDE.md` and `docs/agents/`; `git log` on them is the record |
| A removal or a behavior change | the CHANGELOG entry |

Cite the anchor, not an ADR number, in code and docs.

## Before recording a decision anywhere

Four checks decide whether a decision is worth writing down. They do not decide where; the reach test does.

1. **Surprising without context.** A reader asks *"why this way?"*, not *"what is this called?"*
2. **A real trade-off.** Genuine alternatives existed, and you can name the one you rejected and why it lost.
3. **Shipped.** No `proposed` records. Unbuilt intent is an `FW-N` row.
4. **Not minted in the design session.** Later work touched the decision and left it standing: something was built on it, a review passed it, or a reopening was declined. A decision nobody has tested yet is early, not wrong, and it can wait.

A naming decision is a glossary entry with a `Rejected` line, whatever it scores. A process decision goes to `CLAUDE.md`. Hard to reverse (a storage shape, a wire format, a taxonomy slug) is not a gate; it says how costly an omission is, so write those first.

## The reach test

**Can you list today everything the decision governs?**

- **Yes, a closed set.** It justifies existing things: a term, an invariant, a class, one rule's meaning. It lives with them, in the table above. Most decisions, including most storage-shaped ones, are here, because an invariant can carry a Rejected list of any length.
- **No, it governs designs not yet made.** It is a principle, and it goes in the Principles section in the same Rejected form.

Spanning several invariants is not the test; it usually means the cross-cutting invariant has not been written yet, and that is the thing to fix.

## When a file is kept

A file under `docs/adr/` is kept only when the repo has no architecture home to hold the decision (the fix is to write the home), or the decision concerns something outside the repo's code that no invariant can state: a deployment, a cross-repo contract, a third party's behavior. A kept file gets a row in the map above and an `FW-N` row to retire it. A repo with a good architecture home should expect zero files, and this one has none.
