# Triage labels

The skills speak in terms of five canonical triage roles. **One vocabulary, two carriers**: on a GitHub issue the role is a *label*; on a local ticket under `.scratch/<slug>/issues/` it is a `Status:` line in the file. Same five words either way — do not grow a second set for local tickets.

| Role in mattpocock/skills | GitHub label | Local ticket `Status:` | Meaning |
| --- | --- | --- | --- |
| `needs-triage` | `needs-triage` | `Status: needs-triage` | Maintainer needs to evaluate this |
| `needs-info` | `needs-info` | `Status: needs-info` | Waiting on the reporter for more information |
| `ready-for-agent` | `ready-for-agent` | `Status: ready-for-agent` | Fully specified, ready for an AFK agent |
| `ready-for-human` | `ready-for-human` | `Status: ready-for-human` | Requires human implementation |
| `wontfix` | `wontfix` | `Status: wontfix` | Will not be actioned |

When a skill names a role ("apply the AFK-ready triage label"), pick the carrier from the surface you are on, not from the phrasing: a `#n` gets `gh issue edit <n> --add-label ready-for-agent`, a `<slug>/03` gets the `Status:` line rewritten in place. Routing between the surfaces is [issue-tracker.md](issue-tracker.md); this file only says what the role is called once you are there.

**`FW-N` rows carry no role.** [docs/future-work.md](../future-work.md) has its own `Progress:` / `Open:` / typed `Blocked by:` shape, which says more than a triage role could. Do not add a `Status:` line to a tracker row.

**The wayfinder's `Status:` is a different axis.** A `.scratch/<effort>/issues/NN-*.md` child ticket carries `claimed` / `resolved` — a *state*, not a triage role. The two never appear on the same file: wayfinder children are self-assigned work, build tickets are triaged work. If a file needs both, it is two files.

Edit the carrier columns to match whatever vocabulary you actually use; keep the role column as the skills' names.
