# Design history

Finished design documents, lifted out of the private working directory at merge and kept verbatim. Each one is the record of *how a decision was reached* — including the options that were rejected and the reasoning that never became code.

## Why this directory exists

Four records already carry a shipped feature forward: load-bearing invariants go into PHPDoc on the enforcing function (or [architecture.md](../architecture.md) when conceptual), the decision goes into [docs/adr/](../adr/), the behavior delta goes into [CHANGELOG.md](../../CHANGELOG.md), and the cause goes into the commit body. None of the four has room for an alternative that was considered and dropped, or for an argument that shaped the design without leaving a line of code behind. That is what lands here.

A file here is **not** an ADR. An ADR states a decision and its consequences in a page; a design document is the working-out, at whatever length it took.

## The contracts

**Never corrected.** A document here is frozen at the moment it was lifted. Later work is expected to contradict it, and that contradiction is not a defect to be patched — it is the history. Every file opens with a header saying so and pointing at the docs that *are* kept current. If you find a file here describing code that no longer exists, that is the directory working.

**Never lift an unbuilt plan.** Because nothing here is ever corrected, an unshipped intention filed here is indistinguishable from a shipped decision to the next reader. A plan that was rejected, or that is still live and unbuilt, stays in the private working directory and is tracked as an `FW-N` row in [future-work.md](../future-work.md) instead.

**A lifted file moves.** No copy stays behind in the private archive — two copies means the next edit lands in the wrong one. A partial lift leaves the remainder where it was and says in the lifted file that it is an extract.

**Sanitize on the lift.** This repo is public, and the lift is the first time a private file meets a reader who is not the author. Strip local paths, container names, host names and client site names as part of the move. This is a human read, not a script.

## Index

| Document | Subject | Lifted |
|---|---|---|
| [title-slug-rules.md](title-slug-rules.md) | `title_slug_rules` — token vocabulary, idempotency, slug collision | 2026-09-11 |
| [acf-reference-rework.md](acf-reference-rework.md) | `related_post_terms` rework + Phase 3 migration | 2026-09-11 |
