# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`CONTEXT.md`** at the repo root: the glossary. Its entries carry `Rejected` lines for the names and alternatives that lost.
- **`docs/architecture.md`**: the numbered invariants and the sections that own each decision, with their **Rejected** lists. This is where a design decision lives, next to the code fact it explains.

There is **no `docs/adr/` to read**: the four ADRs were retired into those two files (FW-48), and `docs/adr/README.md` is only a redirect map from the old numbers plus the bar for a future one. **Do not mint an ADR as part of ordinary work**, and do not offer one when a decision is resolved: write it onto the glossary entry or the architecture anchor it justifies. `docs/adr/README.md` owns the table of where each kind of decision goes.

If a file above doesn't exist, **proceed silently**. The `/domain-modeling` skill creates glossary entries lazily when terms get resolved.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `CONTEXT.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag conflicts with a recorded decision

If your output contradicts a **Rejected** entry or an invariant in `docs/architecture.md`, surface it explicitly rather than silently overriding:

> _Contradicts architecture.md invariant 19 (provenance is rejected), but worth reopening because…_
