# Issue tracker: GitHub Issues for bugs, local files for specs

This repo runs **three** tracking surfaces, not one. Each holds a different kind of thing, and the split is what keeps any of them readable. Route first, then use the conventions for that surface.

| What you have | Where it goes | Id |
|---|---|---|
| A defect — something is broken, and it was broken before this session | GitHub issue | `#7` |
| Non-bug work — enhancement, refactor, open design question, testing debt, repo hygiene | a row in [docs/future-work.md](../future-work.md) | `FW-7` |
| A spec for work being built now, and the tickets that break it down | `.scratch/<slug>/` in the working copy | `<slug>/03` |

**Never a bare integer.** `FW-7`, `#7` and `<slug>/03` are three sequences and `7` is ambiguous between all three.

Two rules that decide the first column, both stricter than the skills' defaults:

- **A GitHub issue must be actionable *and* preexisting.** Debt this dev cycle created gets fixed, not filed. Speculative or "would be nice" work is not an issue — it is an `FW-N` row, or nothing.
- **File only after the user agrees.** Do not open an issue unasked, here or in any repo this one depends on.

## Bugs: GitHub Issues

Repo is `davidofchatham/meta-conductor` (public). Use the `gh` CLI; it infers the repo when run inside the clone.

- **Create**: `gh issue create --title "..." --body "..."`. Heredoc for multi-line bodies.
- **Read**: `gh issue view <number> --comments`.
- **List**: `gh issue list --state open --json number,title,body,labels,comments --jq '[.[] | {number, title, body, labels: [.labels[].name], comments: [.comments[].body]}]'` with `--label` / `--state` filters as needed.
- **Comment**: `gh issue comment <number> --body "..."`
- **Label**: `gh issue edit <number> --add-label "..."` / `--remove-label "..."`
- **Close**: `gh issue close <number> --comment "..."`
- **Blocking edges**: `gh api --method POST repos/<owner>/<repo>/issues/<child>/dependencies/blocked_by -F issue_id=<blocker-db-id>`, where `<blocker-db-id>` is the blocker's numeric **database id** (`gh api repos/<owner>/<repo>/issues/<n> --jq .id`) — not the `#number`, not the `node_id`. Read them back at `.../dependencies/blocked_by`. They live **only** in the API and **do not survive a close**: dump them before closing anything, or the graph is gone.

Triage labels are the five canonical roles; see [triage-labels.md](triage-labels.md).

## Non-bug work: the FW-N tracker

[docs/future-work.md](../future-work.md) is the single visible index of everything that is not a bug. It defines its own item shape, typed blockers, and lifecycle — read its preamble before adding a row. Two things matter from here:

- It is the **one committed file allowed to cite a private `.scratch/` or `.claude/` path**; `scripts/check-private-citations.sh` hard-fails every other file for it. Cite a live plan by its `FW-N` row, never by path.
- A finished plan **moves** into [docs/design-history/](../design-history/) and is sanitized on the way (this repo is public; the plans name testbed containers and client sites). An unbuilt plan is never lifted.

## Specs and build tickets: local files

The spec for in-flight work is `.scratch/<slug>/spec.md`. Its build tickets are `.scratch/<slug>/issues/NN-<slug>.md`, one file per ticket, numbered from `01` in **dependency order** (blockers first). Never a single combined tickets file.

- **`Blocked by: NN, NN`** near the top of a ticket — same-directory ticket numbers, no type prefix. This is *not* the typed `Blocked by:` of `future-work.md`, where the referent is ambiguous and the type carries the agent's permission to clear it.
- **`Status: <role>`** near the top of a ticket, from the same five-role vocabulary GitHub labels use ([triage-labels.md](triage-labels.md)). **One vocabulary, two carriers** — do not grow a second set of words for local tickets.
- Comments and conversation append at the bottom under `## Comments`.

**Why the spec is a file and not an issue.** A spec wants rewriting in place. An issue's visible surface is an append-only comment thread, so a spec that changed its mind reads as the spec plus all its contradictions, and the reader has to reconstruct which parts still hold. The file is the spec; its history is in git if anyone wants it. (Root `SPEC.md` stays retired as of 2026-08-12 — this is not a revival of it. Settled 2026-09-11; recorded here rather than as an ADR because all four ADRs are rule-domain decisions and this is process, and because this doc is what the skills actually read.)

**What survives the merge.** The finished spec **lifts** into [docs/design-history/](../design-history/) as the normal path — not only when some committed file happens to need the citation, and not archived by default. The PR body is the **second** record, not a substitute: it holds the review conversation and what changed under challenge, which the lifted spec does not. Where there is a PR, write both.

## Skill hooks

- **"publish to the issue tracker"** → route by the table at the top. A spec from `/to-spec` is a file at `.scratch/<slug>/spec.md`; tickets from `/to-tickets` are files under `.scratch/<slug>/issues/`; a bug is a `gh issue create`; anything else is an `FW-N` row.
- **"fetch the relevant ticket"** → read the file at the referenced path, or `gh issue view <number> --comments` for a `#n`. The user normally passes the path or number directly.
- **"apply the `ready-for-agent` label"** on a local ticket → write `Status: ready-for-agent`.

## Pull requests as a triage surface

**PRs as a request surface: no.** _(Set to `yes` if this repo starts treating external PRs as feature requests; `/triage` reads this flag.)_ When set to `yes`, PRs run the same roles and states as issues via the `gh pr` equivalents (`gh pr view --comments`, `gh pr diff`, `gh pr list --json ...,authorAssociation` keeping only `CONTRIBUTOR`/`FIRST_TIME_CONTRIBUTOR`/`NONE`, `gh pr comment`, `gh pr edit --add-label`, `gh pr close`). GitHub shares one number space across issues and PRs, so resolve a bare `#42` with `gh pr view 42` and fall back to `gh issue view 42`.

## Wayfinding operations

Used by `/wayfinder`. Local files, same directory as a spec — a map holds decisions, not defects, so it belongs where the spec belongs.

- **Map**: `.scratch/<effort>/map.md`, holding the Notes / Decisions-so-far / Fog body.
- **Child ticket**: `.scratch/<effort>/issues/NN-<slug>.md`, numbered from `01`. A `Type:` line records `research`/`prototype`/`grilling`/`task`; a `Status:` line records `claimed`/`resolved`.
- **Blocking**: a `Blocked by: NN, NN` line near the top. Unblocked when every file it lists is `resolved`.
- **Frontier**: scan the effort's `issues/` for files that are open, unblocked and unclaimed; lowest number wins.
- **Claim**: set `Status: claimed` and save before any work.
- **Resolve**: append the answer under `## Answer`, set `Status: resolved`, then append a context pointer to the map's Decisions-so-far.
