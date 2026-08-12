# SPEC — AC-agnostic ACF write queue + dependent-end sever capture

**In flight.** Branch `claude/acf-write-queue-42-43` → 0.7.0. Closes [#42], [#43].
Full spec (problem statement, user stories, locked decisions): **[issue #49]**.

[#42]: https://github.com/davidofchatham/meta-conductor/issues/42
[#43]: https://github.com/davidofchatham/meta-conductor/issues/43
[issue #49]: https://github.com/davidofchatham/meta-conductor/issues/49

Both fixes share one root cause: code that decides "something changed" never gets told.
`update_field()` fires `acf/update_value` and nothing else — never the `save_post` family
every handler gates on (#42). And the sever capture only ever learned about the holder end
of a relationship, never the dependent end (#43).

---

## §V — Invariants

**§V18 — the ACF write signal is `acf/update_value`, not the save hooks.**
Any mechanism that must react to "an ACF field changed" listens there. It is the only
signal common to the editor, AC v7 inline/bulk, bare `update_field()`, WP-CLI and REST.
Gating on `save_post`/`acf/save_post` silently misses everything but the editor.

**§V19 — record on the pre-write filter, apply after the write lands.**
`acf/update_value` fires BEFORE the value is persisted, so a listener there must never read
the post. `AcfWriteQueue::record` only records the ID; every apply happens later. This is
what keeps the queue clear of the pre-write hazard §V14 has to reason about — and it is why
the bounded mid-request flush MUST skip the post currently being recorded, which is the one
post still mid-write.

**§V24 — the reapply gate belongs on the apply step, not the listener.**
Every flush path — shutdown, bounded, and the Admin Columns one-post flush — funnels
through `apply()`, so that is the only place "turn the whole behaviour off" can be
honoured. Gating only the listener leaves `flush_post()` applying regardless, which
is precisely the path an admin is trying to silence. The listener keeps an early-out
so a disabled site does not accumulate a pending set it will never apply, but the
decision has ONE site. Corollary: the target gate runs BEFORE the filter, so
`meta_conductor_acf_reapply_enabled` is always handed a real post ID — the `int`
parameter type on `reapply_enabled()` is that contract.

**§V25 — anything that writes ACF fields in bulk must stand the queue down.**
The queue cannot tell a user edit from a bulk rewrite; both are `update_field()`.
So a bulk writer that does not want a recompute per post must say so. Three known
cases: WordPress imports (automatic, via `WP_IMPORTING`), the fixture seeder, and
the conversion tool — all via `meta_conductor_acf_reapply_enabled`, scoped to the
call rather than the request. The plugin's own bulk apply action is exempt because
it does not write through ACF.

**§V20 — gate on the ACF TARGET, never on the field type.**
A post is recorded only when ACF's `$post_id` resolves to a positive integer, which
naturally excludes the `options` / `user_N` / `term_N` pseudo-targets. Narrowing by field
type would reintroduce a smaller version of #42: the handlers key off relationship,
post-object, taxonomy and plain-text fields respectively, so any type filter is a new hole.

**§V21 — the ordinary save path claims its own posts.**
The queue's claim on `save_post` + `acf/save_post` sits at a priority ABOVE every handler
(currently 9999 vs TitleSlugHandler's 99). Below that, the claim would drop the post before
the handlers ran, so neither the normal path nor the flush would apply it. This is the
constant most likely to break silently in a refactor — guarded by H8.

**§V22 — every END of a relationship that can drop a link needs its own capture shape.**
Direction of the RULE and direction of the EDIT are independent. B8 added the pull-rule
source-end case; #43 adds the push-rule dependent-end case. The general principle is
recorded because this is the second omission of the same class.

**§V23 — `$severed` is keyed by the post whose save DRAINS the entry.**
Not by the severing source (the pre-#43 reading). `process_severed($saved_id)` only ever
runs for a post being written this request, so recording under any other post's key means
the entry never drains. On a dependent-end sever the dependent is both key and value.

---

## §T — Tasks

| # | Task | State |
|---|---|---|
| T1 | #43 third capture branch + `$severed` contract docblock | ✅ |
| T2 | `Core\AcfWriteQueue` + `TaxonomyManager` rewire; AC hook → `flush_post` | ✅ |
| T3 | H2 FQN entry; new H8 `tests/verify-acf-write-queue.php` | ✅ |
| T4 | Fixture v5: `mc_parent_section` (tier 1) + native-bidi pair (tier 2) | ✅ |
| T5 | Docker fixture sweeps (both tiers, multi-source, bare `update_field()`, pseudo-target, idempotence, holder-end regression) | ✅ |
| T6 | Athletics confirmation on the `hargrave` clone: original scenario, AC v7, REST, import suppression | ✅ |
| T7 | CHANGELOG 0.7.0 (orphan-wipe behavior change called out), version bump | ✅ |

## Athletics confirmation (T6) — `hargrave` clone, real data

Subject: schedule #77740 *Varsity Wrestling Schedule 2026-27* → game #77745
*BRAC Championships* (single source). The three live rules are push +
keep_in_sync on `athletics_schedule:schedule_games`, explicit reverse
`athletics_events:game_team_schedule_cpt`, taxonomies `sport` / `teams` /
`school_year`. **Both fields are also ACF-bidirectional**, so ACF keeps the two
sides consistent — the stale-explicit-reverse hazard below does NOT bite this
site's configuration. AC Pro 7.1.1, ACF Pro 6.8.6. Restored to baseline after.

| Step | Result |
|---|---|
| A — game clears its reverse field | all three taxonomies withdrawn; ACF also removed the game from the schedule side ✅ |
| A2 — re-link from the game end | terms reapplied ✅ |
| B — bare `update_field()`, no post save | terms reapplied by the shutdown flush ✅ |
| C — AC v7 `ac/editing/saved` | flushed IMMEDIATELY, same request, before shutdown ✅ |
| D — REST post update with an `acf` payload | applied ✅ (see note) |
| E — bare write under `WP_IMPORTING` | nothing applied ✅ |

**D does not isolate the queue.** A REST *post* update fires `save_post`, so the
handlers ran on the ordinary path and the claim correctly stopped the queue
applying it a second time at shutdown — a useful no-double-apply check, but the
REST case #42 actually targets is an ACF/meta-only endpoint that fires no post
save. Not reachable from this site's configuration; unproven either way.

**AC Pro is admin-only** (`if (!is_admin()) return;` before it defines its
version constant), so step C needs `wp --exec="define('WP_ADMIN', true);"`.
Under a plain WP-CLI run the constant is absent, the hook never registers, and
the step fails misleadingly.

## Deliberate deviations from #49

**The flush cap is a constant, not a filter.** #49's Implementation Decisions say
"a bounded flush past a filterable cap, defaulting to about one hundred". Shipped
as `FLUSH_CAP = 100` with no filter. The filter had no proven consumer — nothing
in any sweep tuned it — and adding one later is trivial and non-breaking, whereas
removing a published filter is not. Contrast `meta_conductor_acf_reapply_enabled`,
which earned its place: the fixture seeder needs it, and the failure that proves
it happened during this branch's work. Recorded on #49.

## §B — Bugs

None open on this branch.

## Known limits (accepted, carried into 0.7.0)

- **Tier-3 rules** (no explicit reverse field AND no ACF native bidi partner) have no reverse
  field NAME to match, so no relationship-EDIT sever can be captured for them, in either
  direction. Delete-time sever still covers them. Documented at `field_is_reverse_of`.
- **Imports do not sync.** By design (§V19 note / #42). Reconcile with "Apply to Existing
  Posts". Overridable via `meta_conductor_acf_reapply_enabled`.
- **REST same-request read-back** shows pre-sync terms: the flush runs on `shutdown`, after
  the response is built.
- **Orphan empty-replace wipes manually assigned terms** in the synced taxonomy. Pre-existing
  keep-in-sync contract, now reachable from the dependent end. Called out in CHANGELOG.
- **An explicit `reverse_acf_field_name` makes the reverse field the source of
  truth, and the plugin does not maintain it.** Reverse resolution reads that
  field instead of scanning, so a HOLDER-end edit alone leaves it stale: the
  dependent still resolves the holder as a source and nothing is stripped.
  Pre-existing (unchanged by this branch) but newly visible, because the fixture
  now configures such a rule — sweep step `s7` fails by design and documents it.
  Consistent-graph configurations are unaffected: ACF native bidirectional keeps
  both sides in step (`s8b`), as does editing both sides (`s7b`). Worth deciding
  separately whether the plugin should write the reverse side itself.
- **Rules can now fire AFTER a content write, not only during one.** Any script
  that writes ACF fields with the rules deliberately emptied — the fixture seeder
  is the known case — must also stand the queue down via
  `meta_conductor_acf_reapply_enabled`, or the shutdown flush applies the
  restored rules to everything it just wrote.
- **Three ACF-listening handlers unswept** (level-restriction, propagation, title-slug) — no
  live rule of those types exists yet. They are wired into the flush. Carried over from #37.
