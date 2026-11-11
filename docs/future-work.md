# Future work tracker

**Not a roadmap.** Nothing here carries a committed timeline. This is the single *visible* index of non-bug work — a tracked, reviewable surface over detail homes that are mostly private. It duplicates no detail: open the linked home for the full design and rationale. One heading block per **`FW-N` id**.

- **Bugs do NOT go here** → [GitHub Issues](https://github.com/davidofchatham/meta-conductor/issues). Issues hold bugs and anything an outsider is waiting on; everything else — enhancements, refactors, open design questions, testing debt, repo hygiene — lives here. See [docs/agents/issue-tracker.md](agents/issue-tracker.md) for the split.
- **Detail lives in its home** — a private plan file, a [design-history](design-history/) document, or an [architecture.md](architecture.md) anchor. An item states only *that the work exists, what gates it, what it touches, where it stands, and where to read more*.
- **This file is the only one allowed to cite a `.scratch/` or `.claude/` path.** Elsewhere such a link resolves for one person and dangles silently for every other reader, so `scripts/check-private-citations.sh` hard-fails on it in CI and exempts this file alone. Cite a live plan by its `FW-N` row here; cite a plan by path only once it is finished and lifted into [design-history](design-history/).
- **Cross-refs use ids, never prose**, so a reworded item never orphans a reference. `FW-7` is an item here · `#7` is a GitHub issue · `<slug>/03` is a local ticket. A bare integer is ambiguous between all three.
- **Ids are permanent.** A shipped or cut item's id retires to [Closed / retired](#closed--retired); it is never reused or reassigned.

New **rule type** entries should also state their **axes** — basis (relation / intrinsic / ambient), effect target, jurisdiction, and claim. The axes are defined in [CONTEXT.md](../CONTEXT.md); [architecture.md](architecture.md) invariant 19 explains what they constrain. They are worth stating early because two of them predict real build cost before any code is written: an **ambient** basis whose value moves on its own needs a cron sweep, and a **scalar** effect target (a field, a title) can never compose — two rules sharing one always collide.

## Index

- [Item shape](#item-shape)
- [Rule types](#rule-types)
- [Correctness, Consistency, Architecture](#correctness-consistency-architecture)
- [Admin tools and diagnostics](#admin-tools-and-diagnostics)
- [UX polish](#ux-polish)
- [Infrastructure and testing](#infrastructure-and-testing)
- [Closed / retired](#closed--retired)
- [Maintenance](#maintenance)

## Item shape

Each item is a `#### FW-N — <title>` heading followed by a fixed set of labeled lines:

- A **description** paragraph (1-3 sentences): what the item IS. Stable, rarely re-edited — not current state, not history.
- **Detail home:** where the design and rationale live. Never duplicated here; open it for the full story. **This file is the one committed file allowed to name a private path** — concentrating those paths here is exactly what makes the rule enforceable everywhere else.
- **Progress:** where the work actually stands. Always present, even if just "Not started."
- **Open:** what is still undecided or unbuilt, when there is a real done/open split. Omitted when there is nothing beyond Progress worth stating.
- **Blocked by:** hard prerequisite, typed (below). **Interacts with:** soft coupling — reshapes, or is reshaped by — as `FW-N` ids, never a gate. Both on one line; `—` means none.
- **Phase:** a scheduling preference, never a gate. The phase ids come from the retired `ROADMAP.md` (2026-09-24): **6a** = options-compatible integrations (FW-1, FW-4); **6b** = the UBT merge (FW-8), the largest integration, taken after 6a; **7** = FW-16. Omitted when nothing has been scheduled.

### Progress, not status

A `Progress:` line is restricted to statements that **stay true forever once true**: "half shipped in 0.8.0", "8 tickets framed, all open", "Not started." A phase name or a percent-done figure is stale the moment work continues, whether or not anyone edits it, and a tracker nobody re-touches is worse than none. `Phase 2 of 3, ~60% done` is banned; `the gate itself was retired in 0.8.0` is fine.

There is deliberately **no "in flight" section**. Moving an item between sections is a step nobody remembers to take, and a stale-empty section reads as a claim ("nothing is in flight") that nobody checked. `Progress:` carries the same bit better, because it can name the branch or the unreleased CHANGELOG entry that owns the real build state. Lifecycle is two-valued: in a tracker section, or in Closed / retired.

### Typed blockers

A blocker is written `<type>:<referent>`, because this field is agent-maintained and the type is what tells an agent whether it may act on the cell:

| Type | Means | Clears when |
|---|---|---|
| `row:FW-N` | Another item must land first | That item reaches Closed / retired — **an agent may clear it unasked** |
| `ship:X.Y.Z` | A version must ship first | That version is released — **an agent may clear it unasked** |
| `code:<condition>` | A stated fact about the code | The condition no longer holds — **an agent may clear it, but must say what it checked** |
| `decision:<what>` | A human choice | **Never auto-clears.** Never auto-start an item gated on one — an agent that resolves the decision in order to unblock itself has made the call the gate existed to reserve |

A blocker states a **code fact**, never a scheduling preference: "this cannot land until X", not "do this after X". A preference filed as a blocker gets silently rewritten by the next rescan, which is why scheduling belongs on the `Phase:` line instead.

---

## Rule types

New rule types, and new capabilities or behavior on an existing one.

#### FW-1 — `acf_relationship_rules`: ACF Post Relationship Manager

Set a post's `post_parent` from a populated ACF relationship or post-object field — or set the referenced posts' parent to this one, depending on direction. Distinct from `related_post_terms_rules`: same data source, different output (a real WP hierarchy edge instead of taxonomy terms).

- **Detail home:** none yet. Source reference: `plugins-to-integrate/acf-post-relationship-manager/` (deleted; reference the prior commit).
- **Progress:** Not started. Storage decided: Options.
- **Open:** circular-reference prevention, and how multi-post-type configs behave.
- **Blocked by:** — • **Interacts with:** FW-2
- **Phase:** 6a

#### FW-2 — Cross-type parent/child propagation

Let propagation cascade terms down a `post_parent` hierarchy whose parent and child are *different* post types — e.g. an `event` parent propagating to `session` children. Today parent and child are always the same type, because WP's editor parent dropdown is type-scoped, so the propagation handler's two post-type gates always agree and the distinction is moot.

- **Detail home:** none. The review note in `inherit_terms_from_parent` carries the open gate question.
- **Progress:** Not started. Identified during the Phase 3 propagation review, 2026-07-01.
- **Open:** the blocker is **UI, not logic** — the handler could support it trivially, but editors have no native way to *create* a cross-type `post_parent`. Two paths: (1) if only the TERMS need to cross types, `related_post_terms_rules` already does that over an ACF relationship field and may cover the real need without touching propagation; (2) FW-1 sets `post_parent` from an ACF field, which IS cross-type-capable and gives editors a UI — compose it with propagation and the cascade falls out. If pursued, two decisions: settle what `post_types` means for propagation (recommendation: scope the TARGET/child — "which posts receive terms" — and gate the parent only on "is a valid source", i.e. has terms), and rework `get_all_child_posts`, which today queries children BY the rule's `post_types`, so it can walk cross-type children.
- **Blocked by:** `code:editors cannot set a cross-type post_parent` — cleared by FW-1 • **Interacts with:** FW-1

#### FW-3 — `time_based_rules`: Temporal State Rule

Evolve the binary Date Window rule (in-window → apply, out → remove, fixed date-only window typed on the rule) into one rule expressing before/during/after auto-tagging across a datetime window, with boundaries readable from per-post ACF/meta fields — including a single combined `datetime` value as Pie Calendar stores — plus "trigger X time before/after a date". Ships a **Pie Calendar source preset** (start/end/all-day meta keys pre-filled) and honors an **all-day boolean field** as a boundary-time override.

- **Detail home:** `.scratch/plans/temporal-rule.md` (scoping + open questions). Domain vocab: [CONTEXT.md](../CONTEXT.md).
- **Model decision:** build the storage shape and handler as the **general** model, not a fixed before/during/after machine. A rule holds optional **boundaries** and a list of **actions**, each an `{interval, overlap, applied term}` scoped to a **phase**, with per-boundary **offsets** and **fallback durations**. The 0.x UI generates only a constrained subset (fixed phase slots plus about-to-start / just-started / about-to-end / just-ended presets); a later release exposes a free action builder writing the same `actions[]`. The storage shape is the hard-to-change contract, so the builder then needs no migration; the cost is handler complexity (within-phase stack/replace, phase reconciliation) the shipped UI does not exercise. Storage stays Options (`time_based_rules`, canonical-shape adapter, no dot-notation). Two such rules naming one term over overlapping post types may fight, which is a collision (architecture.md invariant 19). **Rejected:** a fixed 3-state model with migration later (migrating live user data is the expensive, error-prone path); deferring about-to-start/end to the post-1.0 builder (drops a feature wanted now); provenance (architecture.md invariant 19, *Rejected: provenance*).
- **Progress:** Scoping. Absorbs the "post expires N after its date field" pattern and the previously-planned `date_based_taxonomy_rules`, folded in rather than built as a separate type. Storage settled: Options, normalized through the canonical-shape adapter, no dot-notation — **not** gated on CPT and not migrating to CPT (see *Model decision* above). Since the ordered list shipped in 0.8.0 this is a `type` within `term_rules`, not its own array.
- **Open:** the dispatcher contract is already met by the Date Window rule it evolves: since #61 that handler exposes `apply_to_post()`, registers no hooks of its own, and its cron sweep (`expired_rule_posts()`) only selects candidate posts and hands them to the dispatcher ([architecture.md](architecture.md) invariant 17). Keep it that way — the before/during/after states add boundaries the sweep must select on, not a second write path.
- **Before closing:** move the *Model decision* and its **Rejected** list out of this row into [storage-model.md](storage-model.md), leaving a one-line pointer here; then update the `docs/adr/README.md` 0001 row, which cites this row as the decision's home.
- **Blocked by:** — • **Interacts with:** FW-25
- **Phase:** targeting the 0.x line, before 1.0.0

#### FW-5 — `relationship_field_rules`: Set a field across a relationship

`related_post_terms_rules` already copies *terms* across an ACF relationship; the same edge should carry a *field value* — an event's venue post supplying its address into each session, or a team post pushing a season label onto every player. Same holder/direction model (the ACF field pins the holder post type, `holder_role` says which end is authoritative), with the effect swapped from terms to a target meta/ACF key.

- **Axes:** basis **relation** (authored — an ACF relationship / post-object field), effect target **field** (scalar), claim TBD.
- **Detail home:** none. Raised 2026-08-12 in the cross-rule composition session.
- **Progress:** Not started. Most of the traversal, direction and reverse-lookup machinery already exists in `related_post_terms_rules`; the field write path now exists (built for FW-4: `FormatDispatcher::write_fields()`), so the net-new work is the relationship traversal into it and deciding the claim.
- **Open:** ⚠️ **Scalar effect target** — a scalar's **jurisdiction** is its single slot, so any two rules writing it share the whole of it and **always** collide. Unlike terms there is no contributing option: the claim is *owning* or nothing. Storage TBD — likely a `type` within `format_rules` alongside FW-4, since both write fields. **Split the relationship graph out first:** holder/direction/dependents/sources/reverse-lookup tiers and their memos move out of `RelatedPostTermsHandler` into a per-rule graph module that both the terms effect and this field effect read, and that `CollisionDetector` and `RuleChoice` stop re-deriving direction from. FW-11's reverse index would live behind it. The sever capture bookkeeping stays with each effect but needs the graph to diff old and new links. Premature while terms are the only effect (candidate 5 of the 2026-09-24 architecture review; see [design-history/rule-type-descriptor.md](design-history/rule-type-descriptor.md) → *Out of Scope*).
- **Blocked by:** — (`code:no field write path exists` cleared 2026-10-16: `FormatDispatcher::write_fields()` writes ACF fields and repeater subfields by key, built for FW-4) • **Interacts with:** FW-4, FW-7, FW-11, FW-46

#### FW-6 — `body_class_rules`: Document classes from terms/fields

Let an editor declare the `body_class` branching that themes routinely hand-roll (`is-event`, `season-2026`, `status-cancelled`). The rule declares a source — taxonomy, term, or field value — and a class template; the handler hooks `body_class` and probably `post_class`, rendering through `Tokens\TokenEngine` under `OutputPolicy::slug()`.

- **Axes:** basis **intrinsic** (reads the post's own terms/fields), effect target **body class** — **rendered, not stored**.
- **Detail home:** none. Raised 2026-08-12 in the cross-rule composition session.
- **Progress:** Not started. Partly settled by [architecture.md](architecture.md) invariant 18: *rendered* is a value on the **effect kind** axis ([CONTEXT.md](../CONTEXT.md) → *Effect kind*) and sits last in the derived cross-kind order — it reads terms and fields and is written by nothing, so it is a pure sink.
- **Open:** **a rendered effect behaves differently from every rule type built so far.** It is computed per request and never persisted, so: (a) **no claim question** — nothing is stored, so nothing can strand or need reconciling; (b) **no sweep** — it recomputes on every render, so even an ambient basis needs no cron; (c) **no collision in the stored sense** — two rules emitting classes simply both emit, since the target is a list the theme concatenates. Given (c), it is genuinely open whether it needs an ordered list at all: with no collisions possible there is nothing for an author to sequence. Storage is Options; the list is TBD — its own `display_rules`, or a `type` within `format_rules` even though it neither formats nor transforms stored data.
- **Blocked by:** — • **Interacts with:** FW-13

#### FW-7 — `term_provisioning_rules`: Create a term per post, then apply it

A CPT whose posts each need a matching term so *other* content can be tagged against them — every `team` post gets a `team` term, and player posts are tagged with it via the team relationship. Today that is hand-maintained and drifts the moment a post is renamed or added. For each post passing the filter gate, ensure a term exists in the target taxonomy (name/slug derived from the post, likely rendered through `Tokens\TokenEngine`), then apply it to related posts over a configured relation.

- **Axes:** basis **relation**, effect target **the taxonomy itself** (term existence) *plus* terms on entities.
- **Detail home:** none. Raised 2026-08-12 in the cross-rule composition session.
- **Progress:** Not started. Storage is Options. Worth scoping as **two rules composed** rather than one monolith — the "apply it via a relationship" half is already FW-5 or `related_post_terms_rules`, and composition is exactly what the ordered rule list ([architecture.md](architecture.md) invariants 17 and 19) is meant to support.
- **Open:** ⚠️ **a genuinely new effect shape.** Every rule type so far writes a *value on an entity*; this one mutates the **term vocabulary**, creating rows other rules then reference. Three questions with no precedent in the current model. **Lifecycle**: post deleted → delete the term, orphan it, or leave it? Post renamed → rename the term, or leave the slug stable because permalinks and queries depend on it? **Claim over a term's existence**: is "this term should exist" *owning* (so removing the rule deletes terms) or *contributing* (create-only, never destroy)? Contributing is almost certainly right — destroying terms destroys other posts' assignments — but it is a real decision, not a default. **Collision**: two provisioning rules targeting one taxonomy contend over term *existence*, which the current reach/effect-target predicate does not model.
- **Blocked by:** `decision:claim semantics over term existence` • **Interacts with:** FW-5, FW-10

#### FW-8 — `user_based_rules`: User-Based Term Setting / Restriction

Preset terms in a taxonomy when a targeted user (role or ID) starts a new post. Absorbs the preset half of the standalone plugin `bws-user-based-terms`; its lock half is [FW-54](#fw-54--user_based_rules-lock-and-editor-surface) and its edit-access half is [FW-55](#fw-55--user_based_rules-term-granted-edit-access).

- **Axes:** basis **ambient** (the acting user, not the post), effect target **terms**, claim *deferring*. The rule's status gate is fixed to `auto-draft`, so it fires once, on the new-post screen, and never re-seeds.
- **Detail home:** `.scratch/plans/ubt-merger.md` (reassessment, port scope, migration). Storage rationale: [storage-model.md](storage-model.md).
- **Progress:** Not started. Storage settled: Options, type `user_based_rules` within `term_rules`. Reassessed 2026-11-02 against UBT 1.0.3: the preset needs no editor JS, REST route or ACF load filter, because the dispatcher writes it at the auto-draft insert and every editor reads stored state. UBT's merge layer and `priority` are not ported; user-specific rows sit above role rows, so "override" is deferring plus list order. A pass with no matching user stands down and never removes. UBT retires when this ships, for sites with only unlocked rules; migration is manual re-entry, with a field-mapping table in the readme upgrade note.
- **Open:** a testbed sweep must confirm, before build, that the dispatcher fires on an auto-draft and that categories are vacant at that stage. A direct REST or programmatic create has no auto-draft stage and gets no preset; a `wp_insert_post` capture is the upgrade path if that matters. The Apply page does nothing for this type.
- **Blocked by:** — • **Interacts with:** FW-25, FW-54, FW-55
- **Phase:** 6b

#### FW-54 — `user_based_rules` lock and editor surface

Lock a targeted user's taxonomy to the rule's exact terms, with the editor UI that makes the lock honest: locked controls in the Classic, Block, ACF and ACFE editors, validation, and a display-time preset. Absorbs UBT's lock variant and its open tickets 10 (a locked field is not locked on screen) and 11 (a rule edit does not reach existing posts).

- **Axes:** basis **ambient**, effect target **terms**, claim *owning* — the rule's terms are the value, forced on every write ([CONTEXT.md](../CONTEXT.md) → *restricting-the-editor is not a separate claim*).
- **Detail home:** `.scratch/plans/ubt-merger.md` → *Lock and editor surface*.
- **Progress:** Not started. Lock is not live, so its schema is free. Server enforcement is an owning applier, replacing UBT's `force_locked_value` and `reassert_locked_terms`. The UI is lock-only work in UBT (about 225 JS lines, 55 CSS lines, the Gutenberg route class, ACF `prepare_field` and `validate_value`). Design it against ticket 10's renderer matrix rather than porting verbatim. The display-time preset is editor-only, and the Apply page works for it because it has no status gate.
- **Open:** lock mechanism per renderer; the native tag box's delete buttons reappearing; a single-choice field showing one term of a two-term rule.
- **Blocked by:** `row:FW-8` • **Interacts with:** FW-25, FW-55

#### FW-55 — `user_based_rules` term-granted edit access

Let a user matched by a rule edit and delete other people's posts that hold one of the rule's terms, without an Editor role. The post is treated as the user's own, so their role is the ceiling. Additive, so nothing is locked out. Replaces the retired deny-only FW-53.

- **Axes:** basis **ambient**. An **access** grant through `map_meta_cap` on `edit_post` / `delete_post` — evaluated per request, nothing stored, so no claim and no place in the pass order.
- **Detail home:** `.scratch/plans/ubt-merger.md` → *Edit access*.
- **Progress:** Not started. Settled 2026-11-03: a default-off subfield on a `user_based_rules` row, reusing the row's user match and terms, independent of the preset. The grant maps to the post type's own-post caps, so the role is the ceiling (publish included when the role has it). Any term on the post counts, whoever set it; the readme warns that a rule which tags posts also grants access to them. The row must name at least one post type. Matching is any-of, descendants covered. Read-only from `runtime_hooks()`, so no retention guard: removing your term from a post ends your access to it.
- **Open:** granted drafts and pending posts are probably absent from the list table for a user without `edit_others_posts` (inferred from `WP_Query`, not run); confirm on the testbed, then add a `pre_get_posts` widening in the first cut. Granted published posts already list. REST follows the `edit_post` meta cap (core checks the primitive only on author and sticky changes). Quick edit and bulk edit are unchecked.
- **Blocked by:** `row:FW-8` • **Interacts with:** FW-6, FW-8, FW-54
- **Phase:** 6b

#### FW-9 — Status mirroring for ACF-reference rules

Set referenced posts' publication status from the owner — an unpublished owner making its events private, so editors can preview while the frontend hides them. Replaces the standalone script's term-withholding hack, and shares a `set_managed_status()` status-effect primitive with FW-3's future "set post status" action.

- **Detail home:** `.scratch/plans/status-mirroring.md` (full scope + open questions).
- **Progress:** Not started; has its own plan. Deferred from the 0.5.0 ACF-reference rework.
- **Blocked by:** — • **Interacts with:** FW-3, FW-10

#### FW-10 — ACF-reference enhancements (deferred from 0.5.0)

The refinements deliberately left out of the `related_post_terms_rules` rework. Each is independent; none is a bug.

- **Detail home:** [design-history/acf-reference-rework.md](design-history/acf-reference-rework.md) — the design record for the rework these were cut from.
- **Progress:** Not started. The rule type was converted to the term dispatcher in #63 (0.8.0), which changed its removal semantics; re-read the design history against that before scoping any of these.
- **Open:**
  - **Tier filter** — sync only a particular hierarchy level of a taxonomy (e.g. only 2nd-level terms).
  - **Manual-survives mode** — let hand-added, non-source-derivable terms persist under Keep-in-sync; today the synced taxonomy is wholly rule-owned. **Blocked on a rejected primitive**: this needs provenance (rule-domain-vs-manual tracking), which is rejected plugin-wide ([architecture.md](architecture.md) invariant 19, *Rejected: provenance*). Wanting it reopens that decision plugin-wide rather than being a local feature. Cheaper alternative inside the current model: expose the rule's **claim** as *contributing* instead of *owning*, which never removes anything — manual terms survive because nothing reconciles, at the cost of losing source-authoritative cleanup.
  - **True cross-taxonomy copy** — map terms by slug/name so source and target taxonomies can differ; the current copy is by ID, single taxonomy only.
  - **Multi-level chain propagation** — a post that is BOTH a dependent (of A) and a source (for C) used not to propagate to C in the same save, and the planned fix was a depth-bounded re-dispatch inside the handler. **Probably dissolved by #63**: the handler's `fan_out()` marks each dependent dirty, each dependent gets its own full ordered pass, and a dependent that is a source under another row fans out in turn; the drain's one-pass-per-entity bound terminates it. Confirm with a 3-level sweep, then drop this bullet.
  - **Single-owner optimization** — skip the multi-source rule-union when a dependent provably has one owner (ACF `max=1` / native bidi). Negligible gain when a reverse field is configured; only matters for the meta_query fallback with large fan-out.
  - **Multiple taxonomies per rule** — moved to FW-56. The case for scoping it with FW-11 did not hold: `find_holders_referencing` memoizes per request without the taxonomy in its key, so rules duplicated per taxonomy already share one tier-3 scan.
- **Blocked by:** `decision:reopen provenance` — manual-survives only; the rest are unblocked • **Interacts with:** FW-9, FW-11, FW-19, FW-56

#### FW-12 — Sub-scope field for restricting rules

A rule whose **claim** is *restricting* (level-restriction and Term count limit) declares no narrower **jurisdiction**, so it governs its entire taxonomy and necessarily collides with any rule touching that taxonomy on overlapping post types. The archetype: term-hierarchy `child_to_parent` *adds* ancestors while level-restriction `include_ancestors=false` *strips* them — an unresolvable contradiction that can only be warned about. A sub-scope field ("governs levels 3–4 only", or "only under branch X") would let such pairs be made genuinely **disjoint** instead of merely warned: the collision dissolves rather than being reported.

- **Detail home:** none; this row carries it. Vocabulary: [CONTEXT.md](../CONTEXT.md) → *Claim*, *Jurisdiction*. Term count limit shares the gap: it also governs its whole taxonomy and has no sub-scope field, so "cap level 3 only" is inexpressible and a count rule still collides with every other rule on its taxonomy.
- **Progress:** Not started.
- **Blocked by:** `decision:is level_restriction live on a real site` — it needs config + storage + UI on a **shipped** rule type, so the live-rule-type schema rule applies before any schema change • **Interacts with:** FW-25

#### FW-13 — Scoping which provocations a format rule answers

#64 put `format_rules` on the term dispatcher's queue, so the format pass now runs for **every entity the drain reaches** — a propagation fan-out's children, a captured sever's dependent, anything the ACF write queue flushed — not only for posts that were saved. That is the correct reading of a rule that consumes terms, and it fixed real staleness: before #64 a post whose terms were rewritten by *another* post's rule kept its old `{term:...}` title until somebody re-saved it. It also carries a cost the ticket accepted deliberately rather than solved.

- **Detail home:** none yet. Context: [architecture.md](architecture.md) invariant 18, fixture matrix §7 restore gotcha.
- **Progress:** Not started. Documented as accepted behavior in [CHANGELOG.md](../CHANGELOG.md) under the live-rule-type policy.
- **Open:** a format rule has no way to say *which* provocations it answers — everything or nothing. The honest shape is **not** a per-rule "only on save" toggle: that recreates exactly the provocation-dependent behavior the dispatcher exists to remove ([CONTEXT.md](../CONTEXT.md) → **Pass**). More promising is making the **effect** conditional rather than the pass. A rule already recomputes from live state on every pass; what it could gain is a declared *stability* — "compute the slug once, then leave it" (`slug_locked_after_publish`) — which is a property of the rule's meaning rather than of how it was provoked, and composes with any provocation set. Title and slug want different answers: a title is cheap to change, a published slug is not. What a slug change costs once it happens is FW-36.
- **Blocked by:** — • **Interacts with:** FW-4, FW-6, FW-36

**Why this is not a bug.** The behavior is documented, and the alternative — gating the format pass to save-shaped provocations — reintroduces the staleness #64 removed. This is a feature the model now has room for, not a regression to undo.

#### FW-19 — Grouped / nested relationship fields for Related Post Terms

`related_post_terms` is verified only for **top-level** ACF relationship/post-object fields. The field picker (`ConfigHelpers::acf_relationship_field_options()`) enumerates top-level fields only — `acf_get_fields($group_key)` does not recurse into Group / Repeater / Flexible-Content subfields — so a nested relationship field never appears as a choice.

- **Detail home:** [architecture.md](architecture.md) handler-invariant #6 documents the trap. Upstream context: #37.
- **Progress:** Not started; a known capability gap, not a live bug. No current rule uses a nested field, so nothing is broken today, and the UI warns that only top-level fields are supported.
- **Open:** **failure modes if a nested field is forced in via config.** ACF's `acf/update_value` `$field['name']` and Admin Columns v7's `get_meta_key()` both return the **bare** subfield name with the group prefix stripped, so the capture filter's `acf_field_name === $field_name` match and the planned AC-v7 reapply fallback silently miss. Additionally a **Repeater/Flex**-nested field breaks `read_relationship` entirely — `get_field('sub', $post_id)` has no row context. Group-nested `get_field('group_sub')` (qualified) does resolve, so the read is fine for Groups; only the name-matching is wrong. Sketch: (1) recurse Group subfields in the options builder, using the qualified name; (2) match by ACF **field key** (`field_xxxx`) instead of raw name everywhere `acf_field_name` is compared, or reconcile bare↔qualified — rows have stored `acf_field_key` since #25, but the capture filter still compares names; (3) Repeater/Flex support needs row-context resolution — larger, likely out of scope. Drop the top-level-only UI warning once (1)+(2) land for Groups.
- **Blocked by:** — • **Interacts with:** FW-10
- **Phase:** on demand — only when a real site needs a grouped relationship field

#### FW-25 — Authorable claim on every rule type

Only `propagation` lets the author choose a claim. `time_based` and `related` are hardcoded **owning** (they remove their target term when the trigger stops holding), `hierarchical` is contributing, `level_restriction` restricting, `title_slug` owning, and `related_post_terms` is owning-or-contributing under the name `keep_in_sync`. This item is about letting the author *change* it.

- **Axes:** no change to basis or effect target — this is the **claim** axis becoming author-set where it is currently hardcoded.
- **Detail home:** [CONTEXT.md](../CONTEXT.md) → *Jurisdiction* for the law it must obey. The concrete, no-behavior-change half — *stating* each type's claim in its config — is FW-28.
- **Progress:** Not started. `ConfigHelpers::claim_field()` already exists and is id-agnostic, so adding the control is cheap. The ordered rule list shipped in 0.8.0, so the configs are already one repeater with `conditions`-gated subfields — the claim field would be gated on rule `type`, and the "building it twice" concern that deferred this is now resolved.
- **Open:** ⚠️ **constrained by the jurisdiction law** (CONTEXT.md → *Jurisdiction*) — *owning requires a statically enumerable jurisdiction*. `time_based` and `related` qualify (one configured target term each), so owning↔contributing is a genuine choice for them. `hierarchical` does **not**: its derivable set is data-dependent, which is why it already buys the forbidden cell with `_bws_auto_terms` provenance meta — offering it *owning* would need that meta generalized or a silent widening to the whole taxonomy. `level_restriction` is restricting by construction with no meaningful alternative. So this is **not one uniform dropdown**; it is a per-rule-type legality question, and that is the real work.
- **Blocked by:** — • **Interacts with:** FW-3, FW-8, FW-12, FW-24

#### FW-36 — Slug-change safety for format rules

A format rule that changes a published post's `post_name` moves its URL. Since #64 that can follow from an edit to a *different* post — a parent's terms, a holder's relationship field — and since FW-16 from one bulk run across every post in a rule's reach. Every moved URL has to keep resolving, and the author has to be able to see what will move before it does.

- **Detail home:** none yet.
- **Progress:** Not started. Redirects are mostly covered by WordPress core already: the format dispatcher writes through `wp_update_post()`, so `wp_check_for_changed_slugs()` records the old slug as `_wp_old_slug` and `wp_old_slug_redirect()` answers the old URL's 404 with a `301`. Verified on the testbed (2026-09-24) for renames no save provoked. No redirect store and no redirect-plugin dependency are needed. The Apply page's format preview already shows the resulting slug for sample posts in one rule's reach.
- **Open:**
  - **Hierarchical post types.** Core skips them in `wp_check_for_changed_slugs()`, so a rule renaming a page or a hierarchical CPT leaves no redirect. Worth code only if a slug rule actually targets one — record and resolve the old slug the way core does. Until then a redirect plugin's slug monitor, enabled per post type, covers it per site.
  - **Old-slug ambiguity.** `_wp_old_slug` is not unique: a pattern whose output churns can leave the same old slug on several posts, and core redirects to whichever it finds first. A diagnostics check at most, unless it shows up on a real site.
  - **Full dry-run.** The Apply preview is a sample for one chosen rule. What an author wants before enabling a slug pattern on a live site is the whole list the current rule set *would* rename, including posts that only an indirect provocation would reach.
  - **Audit trail.** A rule-driven rename leaves only the old-slug meta; nothing records which rule did it, or what provoked the pass.
- **Blocked by:** — • **Interacts with:** FW-13, FW-16

#### FW-46 — Plain meta target for Computed field rules

Let a Computed field rule write a plain post meta key, not only an ACF field picked by key. FW-4's v1 is ACF-only, so its type appears only while ACF is active.

- **Axes:** as FW-4 — basis **intrinsic**, effect target **field** (scalar), claim *owning*.
- **Detail home:** [design-history/field-transformation.md](design-history/field-transformation.md) → *Out of Scope*.
- **Progress:** Not started. Left out of FW-4's v1 on purpose.
- **Open:** the acceptance bar: a name that matches an ACF field still routes through ACF's write (so its field-key reference stays intact), and a `_`-prefixed key is refused, because those are core's and other plugins' private meta. Also whether the type stays hidden without ACF once it can work without it.
- **Blocked by:** — • **Interacts with:** FW-4, FW-5

#### FW-49 — Value-filter and conditional tokens

New token kinds for the pattern engine: a value filter (`{term:TAX|exclude:…}`) and a conditional (`{if_term:…}`). They are the part of FW-4's two real use cases that plain substitution cannot reach.

- **Detail home:** [design-history/field-transformation.md](design-history/field-transformation.md) → *Out of Scope* and *Further Notes*; the earlier gap analysis is `.scratch/plans/field-transformation-token-gap.md`.
- **Progress:** Not started. Deferred from FW-4's v1, which was judged on the plain-substitution part landing cleanly.
- **Open:** whether the real use cases still need them once v1 runs on them, or whether the remainder stays in site PHP alongside format transforms. Any new token kind lands in `Tokens\` so title/slug and Computed field rules both get it, and adds rows to the H17 golden table deliberately.
- **Blocked by:** `decision:the real use cases still need them after v1` • **Interacts with:** FW-4, FW-44

#### FW-52 — Split the title/slug rule into a title rule and a slug rule

Separate `title_slug_rules` into a title type and a slug type in `format_rules`, the slug rule reading the title rule's output through the `$data` seam in list order. Today one row couples them: `{default_slug}` is `sanitize_title()` of the computed title, a title-only row renames the slug implicitly, and a row is a lookup keyed by `post_type` where the first match wins, so a second row on one type never runs. Splitting lets a slug-only rule (flat URL, disambiguation) and a title rule scope independently.

- **Axes:** as the title/slug rule — basis **intrinsic**, effect target **field**, claim `fields|title_slug` split into a title claim and a slug claim.
- **Detail home:** `.scratch/plans/flat-hierarchical-urls.md` → *Split*.
- **Progress:** Deferred (2026-11-02), not started. Raised during FW-51's design as a cleaner shape, not a requirement of it. FW-51 builds on the combined row and its new fields all partition to the slug row, so the split later costs only carrying fields across. A live site already runs a slug-only row and a title-plus-slug row, so the split is a migration on a **live rule type**: it needs a migration step or an explicit manual re-save, never a silent break.
- **Open:**
  - **The implicit slug.** Whether a title row alone still renames the slug. The cleaner answer is no, with the migration emitting a `{default_slug}` slug row for any title-only row to preserve behavior.
  - **Migration shape.** The fields partition cleanly: `title_pattern` to the title row; `slug_pattern`, `slug_mode`, `slug_escalation`, `escalation_placement`, `flat_urls` to the slug row; `post_type`, `enabled` and the row title shared.
  - **Order.** The slug row must follow its title row, which is a consumer-before-producer case for FW-47.
- **Blocked by:** — • **Interacts with:** FW-13, FW-36, FW-47, FW-51

#### FW-56 — Multiple taxonomies per rule

A rule names one taxonomy, so applying the same rule across several means keeping one near-identical row per taxonomy. Let a row name a list and expand it at read time into one scalar row per taxonomy, so no handler ever sees an array.

- **Detail home:** [architecture.md → A row names several taxonomies](architecture.md#a-row-names-several-taxonomies-fw-56). It began as a bullet under FW-10.
- **Progress:** Built on `feature/multi-taxonomy-rules`; see CHANGELOG *Added*. The case was authoring, not speed: rules duplicated per taxonomy already share the tier-3 reverse-lookup scan (`find_holders_referencing` memoizes per request on `related_id:holder_type:field_name`), so this row makes no performance claim.
- **Blocked by:** — • **Interacts with:** FW-10, FW-57, FW-59

---

## Correctness, Consistency, Architecture

Nothing new for an author. Code or behavior that works but is wrong-shaped: an inconsistency between two surfaces, a dead surface, a seam in the wrong place, or a cost paid on every save. Most of the refactor rows are candidates the 2026-09-24 architecture review (`.scratch/plans/architecture-review-2026-09-24.html`) left out of FW-39.

#### FW-11 — Tier-3 reverse-lookup is an unindexed query on every eligible save

A pull rule with NEITHER an explicit reverse field NOR a detectable ACF bidirectional field falls back to `find_holders_referencing` — an unindexed `meta_query` LIKE over all holder posts — on every eligible save. B7 (0.5.0) removed the spurious calls on ineligible saves; the legitimate case is still O(N).

- **Detail home:** [design-history/acf-reference-rework.md](design-history/acf-reference-rework.md) → the reverse-lookup tiers. The issue that framed it was #22.
- **Progress:** Not started. Mitigated in the UI: the config warns the admin to set a reverse or bidirectional field. A code fix — a reverse index maintained on relationship-field save, or a registry built at rule-save time mapping related-post → holders — would remove the warning. Deliberately kept out of #63 even though that ticket rewrote the handler: the fix is its own design decision with its own invalidation questions, and riding it along would have widened the largest ticket in the phase with work that had no dependency on the ordering model.
- **Open:** the reverse-lookup-resolution cluster is best decided in one sitting — this and FW-19's key-based field matching both touch how the "other end" is resolved. #25 already made the field key each row's stored identity. FW-10's *multiple taxonomies per rule* (now FW-56) was part of this cluster until its shared-resolution payoff turned out to be already memoized.
- **Blocked by:** — • **Interacts with:** FW-10

#### FW-14 — Rule-type renaming on the domain axes

Current rule-type names conflate **basis**, **effect target** and **claim** into one string, which is why `hierarchical` (term graph) and `propagation` (post graph) read as near-synonyms, as do `related` (term↔term) and `related_post_terms` (post↔post). Names should be composed from the axes once those have settled.

- **Detail home:** none; this row carries it. Axis definitions: [CONTEXT.md](../CONTEXT.md).
- **Progress:** Not started. Storage keys (`related_rules`, `time_based_rules`, …) are unaffected — this is domain and UI vocabulary only. Since FW-39, each rule type's descriptor `label()` is the single rename site; descriptor class names mirror the storage keys and do not change.
- **Blocked by:** `code:the Effect axis carries only term values` — renaming before it carries field, title and body-class values means minting names twice • **Interacts with:** FW-4, FW-5, FW-6

#### FW-24 — Claim option propagation

Per-rule claim overrides (stored `conflict_handling`) default to `merge` regardless of the General-tab per-taxonomy default, but authors reasonably expect rule-level to inherit from taxonomy-level unless explicitly set. Sharper now that the two surfaces share wording — both read "Claim on terms" / "Default claim on terms", so one appears to feed the other.

- **Detail home:** none. Claim semantics: [CONTEXT.md](../CONTEXT.md) → *Claim*.
- **Progress:** Not started. Small enough to take ad-hoc. The per-taxonomy default is already stored and flattened, but nothing reads it: a rule that omits its claim falls back to a hard-coded `merge` in each handler. `RuleStorage::get_raw_settings()` is the seam for the lookup and has no other reader.
- **Open:** the handler reads the General-tab default for `$taxonomy` when the rule's own value is empty. Wants an explicit "inherit" placeholder option rather than a silent fallback, so the config shows which default is in force.
- **Blocked by:** — • **Interacts with:** FW-25

#### FW-37 — What a date-window rule's filter scopes

A date-window rule's filter (`filter_taxonomies` / `filter_terms`) gates only the apply. Removal outside the window ignores it, so an in-range post that stops matching the filter keeps the target term until the window closes, then loses it whether or not it ever matched. That fits neither consistent reading. If the filter scopes **jurisdiction**, the rule should never touch a non-matching post, and the out-of-range removal reaches too far. If it is a **condition**, an in-range non-matching post should lose the term, and the rule should remove it.

- **Detail home:** none yet. Code: `TimeBasedHandler::apply_time_based_rule()` (the apply and remove branches) and `expired_rule_posts()` (the cron selection, which ignores the filter too). Ownership model: [architecture.md](architecture.md) invariant 19, [CONTEXT.md](../CONTEXT.md) → *Claim*.
- **Progress:** Not started. Found in the 2026-09-24 architecture review. The current behavior is sweep-asserted (matrix §6c/§6d), so either answer changes a sweep expectation, and changes behavior if a live site runs a filtered date-window rule.
- **Blocked by:** `decision:jurisdiction or condition` • **Interacts with:** FW-3, FW-24

#### FW-41 — Term appliers return an end state; the dispatcher writes

Make term appliers return the terms they want and let `TermDispatcher` apply the claim, diff and write — the shape the format pass already has (`apply_to_data()` returns data, `FormatDispatcher` writes). Claim would be decided in one place, and FW-32's dry run would fall out of it.

- **Detail home:** [design-history/rule-type-descriptor.md](design-history/rule-type-descriptor.md) → *Out of Scope* (candidate 4 of the 2026-09-24 architecture review, rated *worth exploring*).
- **Progress:** Not started.
- **Open:** three appliers resist it. Level restriction's native read depends on ACF's sync reacting to its own field write; propagation writes the ACF mirror before it reads native terms; hierarchical writes `_bws_auto_terms` provenance meta mid-apply. `compute_end_state()` alone is too narrow a seam — it cannot express provenance or restriction. A whole-pass dry run also needs a state overlay, so later rules see earlier rules' results.
- **Blocked by:** — • **Interacts with:** FW-8, FW-24, FW-25, FW-32

#### FW-42 — Fold the ACF write queue into the dispatcher's queue

`AcfWriteQueue`'s only job now is to call `TermDispatcher::mark_dirty()` later, from its own pending set flushed at `shutdown` p10, ahead of the drain at p20. Marking the entity dirty straight from ACF's write filter would leave one queue, and the pass lock would drop the pass's own ACF echoes.

- **Detail home:** [design-history/rule-type-descriptor.md](design-history/rule-type-descriptor.md) → *Out of Scope* (candidate 6 of the 2026-09-24 architecture review, rated *worth exploring*).
- **Progress:** Not started. `record()` has no pass-lock check (confirmed in code), so a pass's own ACF write is probably recorded and flushed after the lock is released, costing one extra full pass. Inferred, not reproduced on the testbed; passes are idempotent, so it wastes work rather than producing wrong output.
- **Open:** what goes: the pending set, `FLUSH_CAP` and the priority ordering against the drain. The Admin Columns v7 immediate flush (#37) routes through the queue today and needs a new caller.
- **Blocked by:** — • **Interacts with:** —

---

## Admin tools and diagnostics

Author-facing surfaces outside the rule editor: the Apply page and its follow-ups, validation, diagnostics, and standalone tools.

#### FW-15 — Post type converter

Take a defined group of posts — all descendants of a page, or all posts in a taxonomy — and convert them to a different post type without losing meta, terms, parent/child relationships or attachments. The common scenario: a content tree created under `page` that should have been a custom post type, where manually re-creating loses ACF data and breaks internal links.

- **Detail home:** none.
- **Progress:** Not started. Feasibility mapped against what moves with the row: the `wp_posts.post_type` flip is the easy part; meta and ACF data stay (keyed by post_id, post-type-agnostic in the DB); term assignments stay only if the target type has the same taxonomies registered, else they need a mapping or drop strategy; `post_parent` survives the flip but only renders correctly if the target type is hierarchical; permalink structure changes, so old URLs need redirect entries.
- **Open:** UX shape is sketched — source picker (descendants-of-page / posts-in-taxonomy / `WP_Query` args / hand-picked IDs) → target post type → preview rows with per-taxonomy mapping decisions and warnings → dry-run report → commit with optional redirect generation. ⚠️ The flip is destructive to query results elsewhere, so preview plus dry-run are mandatory, not optional.
- **Blocked by:** — • **Interacts with:** FW-16
- **Phase:** none. It was a candidate launch recipe inside FW-16 until that restarted as a rule-apply page (2026-09-23); a post type flip has no rule behind it, so it needs its own tool.

#### FW-30 — Rule validation has no author-visible surface

Every handler carries a `validate_rule_internal()`, and `UnifiedHandlerBase` wraps it in a public `validate_rule()` that returns `['valid' => bool, 'errors' => string[]]`. Nothing in the plugin calls either on the save path: the settings page sanitizes through Wireframe's config-driven Sanitizer, and a dispatcher pass never validates — it just resolves what the row names and does nothing when that fails. So a rule that cannot work is stored, listed and run exactly like one that can, and the author is told nothing.

- **Detail home:** none. Surfaced fixing [#52](https://github.com/davidofchatham/meta-conductor/issues/52), where the validator's own term resolution was too lax; tightening it changed no live behavior precisely because nothing calls it.
- **Progress:** Not started. The validators themselves are live code and are kept correct — #52 tightened `related`'s — but their only caller today is the #52 sweep step (the RuleEngine path that also called them was deleted as dead code). The base default checks `enabled` only, so the types without an override (`hierarchical_level_restriction`, `propagation`, `related_post_terms`, `time_based`) validate nothing type-specific.
- **Open:**
  - **Decide what the surface is before wiring anything.** A REST-time rejection is the wrong instinct: a half-configured row is the normal state of a repeater being filled in, and failing the save would make the page unusable. The candidates are an advisory panel — the *Rule collisions* panel is the shipped precedent and already renders per-row warnings against a row number — or a per-row badge, or an admin notice on load.
  - **Decide whether a failed rule stays silent at runtime.** A rule whose target term was deleted currently no-ops forever with nothing in the log at default settings. A debug-level line is cheap; a persistent per-row "last pass could not resolve X" is more useful and needs somewhere to store it.
  - **The validators are not uniform.** They were written per handler against the pre-0.8.0 shapes; several predate `post_types` becoming an array and the ordered list. Any surface that shows their output will expose that unevenness, so an audit pass belongs in the same piece of work, not after it.
- **Blocked by:** — • **Interacts with:** FW-29

#### FW-31 — Rule-adjacent Preview / Apply

A Preview / Apply-to-existing button inside each rule row, so an author can check or apply a rule without leaving it for the FW-16 page. Deferred, not out of scope: FW-16 ships the page first, and this is a second entry point onto the same applier, which takes a rule array rather than a page request so either caller can supply one.

- **Detail home:** [design-history/apply-existing.md](design-history/apply-existing.md) (FW-16's spec, *Out of Scope*); the Wireframe gap is FW-23 → Gap B.
- **Progress:** Not started.
- **Open:** when it starts — use Gap B if upstream has shipped row context (wp-wireframe#39), or fall back to one `action` button beside the repeater with a rule dropdown (saved rules only; stale after a reorder until reload). The in-row form has to handle unsaved edits: the button posts in-flight values, so it either previews those or refuses until saved.
- **Blocked by:** `code:Wireframe's action field carries no repeater-row context` • **Interacts with:** FW-16, FW-23

#### FW-32 — Term-rule dry run

A compute-only path through the term pass, so FW-16 can preview what a term rule would write before it writes. The format pass already has one — `apply_to_data()` returns data and the dispatcher writes — but every term applier writes as it goes.

- **Detail home:** [design-history/apply-existing.md](design-history/apply-existing.md) → *Preview* (FW-16's spec).
- **Progress:** Not started. FW-16 launches without it: term rules get an in-scope count, a sample list, a post limit and a post-run change report instead.
- **Open:** `TermOperations::compute_end_state()` already encodes merge / replace / skip for every write, but alone it is too narrow a seam — FW-41 is the refactor that would give this row its compute-only path. A rolled-back DB transaction was considered and set aside — other plugins' hooks and the object cache fire during the pass and do not roll back.
- **Blocked by:** — • **Interacts with:** FW-16, FW-41

#### FW-33 — Background bulk apply

Run an FW-16 apply as a chunked WP-Cron job — state in an option, a lock, cancel and status controls — so a large run survives closing the tab and needs no repeated Continue clicks.

- **Detail home:** none.
- **Progress:** Not started; deliberately not in FW-16's launch, which uses time-boxed Continue batches with no cron dependency.
- **Open:** wanted only if Continue proves tedious on a real site. An upstream action-continuation feature ([wp-wireframe#39](https://github.com/tdrayson/wp-wireframe/issues/39), FW-23) would remove the clicking without a background job, and would make this row moot.
- **Blocked by:** `decision:Continue batches prove tedious in real use` • **Interacts with:** FW-16, FW-23

#### FW-34 — Full reach-based collision detector

Replace the minimal pairwise collision warning (#65) with an analysis over **reach**: build the rule-interaction graph from the conjunctive predicate — reaches intersect on an effect target *and* jurisdictions overlap (architecture.md invariant 19) — and warn per connected component instead of per pair. This would catch the pairings the shipped detector skips on purpose. Example: a taxonomy-wide rule and a term-pairing rule whose term is in that taxonomy, like a restricting rule pruning a term another rule applied.

- **Detail home:** none; the work is deferred until the non-term effect kinds are real enough to define reach once; components are advisory only (the repeater is the ordering UI, so a component of size 1 needs no ordering control and no warning). See [architecture.md](architecture.md) invariant 19. The *What it deliberately does NOT do* PHPDoc on `Admin\CollisionDetector` says where the current detector stops. Vocabulary: [CONTEXT.md](../CONTEXT.md) → *Reach*, *Collision*.
- **Progress:** Not started. The pairwise advisory shipped in 0.8.0: equal effect targets plus overlapping written post types, claim not consulted. `RuleType::written_post_types()` (each descriptor states its own) already gives post-type-level reach, and FW-16 reuses it.
- **Open:** defining reach once for **every** effect kind, not just terms. FW-4 supplies the first non-term effect with a field target (one slot per field or repeater subfield), so reach can now be defined over terms, title/slug and fields. Also whether to consult the claim, since two purely contributing rules cannot actually fight. With the repeater as the ordering UI, the result is advisory only: components no longer decide which rules get ordering control.
- **Blocked by:** — (`code:the only effect kinds are term and title/slug` cleared 2026-10-16: the `field_transformation_rules` descriptor adds a field effect, built for FW-4) • **Interacts with:** FW-4, FW-6, FW-12, FW-25, FW-39, FW-47

#### FW-35 — Diagnostics page rework, and whether to log

The Diagnostics page (`Admin\Diagnostics`) is a 0.3.0 stub: hidden unless `WP_DEBUG` or a filter is on, it dumps the raw settings option plus a legacy option key no build writes any more, and otherwise says "User-level diagnostics coming soon". Rework it into something an author can use, and decide alongside it whether the plugin should keep any record of what its rules did — because that record is what such a page would mostly show.

- **Detail home:** none.
- **Progress:** Not started. The plugin has no logging today: the run log and its `enable_logging` read went with the dead rule engine (#26), and the upgrade drops every table the plugin ever created, none of which had a writer left. What remains is `debug_log()` to the PHP error log under `WP_DEBUG`. Title/slug's last-result record (`bws_title_slug_rule_status`) was deleted after 0.9.0: nothing read it, and it was keyed on a positional rule id.
- **Open:**
  - **What the page is for.** Candidates: per-rule health (a rule whose target term or taxonomy no longer resolves — FW-30's runtime question), the ordered kind lists as the dispatcher reads them, recent pass activity, and the dev dumps kept behind `WP_DEBUG`. Drop the legacy-option dump either way.
  - **Whether to log, and what.** A per-rule "last pass result" for every type is cheap and answers most "did my rule run" questions; a per-write audit trail is what a rule-driven slug change with no redirect (FW-36) would want, and needs a table, retention and a cleanup job. Decide the level before building storage for it — the deleted log table is the precedent for storage built ahead of a reader.
  - **Visibility.** Whether the page stays gated behind `WP_DEBUG` / a filter or becomes a normal submenu once it has author-facing content.
  - **Optional-plugin status.** The activation notice no longer warns that Admin Columns Pro is missing (quick-edit integration is simply absent without it); this page is the natural home for that status instead, as an environment section listing each optional integration (Admin Columns Pro, ACF Pro) as active or not and what it enables. The activation notice still carries the ACF Pro warning; decide whether it moves here too.
- **Blocked by:** — • **Interacts with:** FW-13, FW-30, FW-38

#### FW-38 — Whether to finish the title/slug rule's admin feedback

The title/slug design planned three per-rule admin surfaces that never got built: a *last applied* line (when, on which post, the resulting title and slug), a warnings log (the last 10, e.g. a token that resolved empty or a field value that is not a string), and a Preview button showing current vs. resulting title/slug with per-token warnings. Decide whether any of them are still wanted, and in what form.

- **Detail home:** [design-history/title-slug-rules.md](design-history/title-slug-rules.md) → the render-method and `admin.js` sections.
- **Progress:** Not started. The Preview is mostly covered already: the Apply page's format preview shows the resulting title and slug for sample posts in one rule's reach, without per-token warnings. Nothing produces warnings today. The only status record ever written (`bws_title_slug_rule_status`) was write-only, keyed on the positional rule id so a reorder moved it to the wrong rule, and was deleted after 0.9.0.
- **Open:**
  - **Scope.** A title/slug-only surface, or the per-rule "last pass result" FW-35 weighs for every type. Deciding FW-35's logging level first avoids building a title/slug store that the general answer would replace.
  - **Identity.** Any stored per-rule record needs a key that survives reordering and deletion. The rule `id` does not.
- **Blocked by:** `decision:whether the feedback is still wanted` • **Interacts with:** FW-35, FW-36

#### FW-47 — Consumer-before-producer order lint

Warn when a format rule reading `{meta:x}` is ordered before the Computed field rule that writes `x` for an overlapping post type. The order is legal — the reader then sees the stored value, not this pass's — but it is usually a mistake, and nothing tells the author.

- **Detail home:** [design-history/field-transformation.md](design-history/field-transformation.md) → *Out of Scope*.
- **Progress:** Not started. The composition it lints shipped with FW-4: a later rule reads an earlier rule's computed value in the same pass.
- **Open:** whether it is its own advisory or a case of FW-34's reach analysis, since both walk the same reach and order. Row targets read their own row's siblings, so a row-rule pair is the same check one level down.
- **Blocked by:** — • **Interacts with:** FW-4, FW-34

#### FW-57 — Apply page: choose a scope, report what changed

The Apply page asks for a rule, but a run applies every enabled rule to each post in the chosen rule's reach; the choice only decides which posts are passed. Rework it so the control says what it does and the report says what happened.

- **Detail home:** [design-history/apply-existing.md](design-history/apply-existing.md) (FW-16's spec).
- **Progress:** Not started. Raised by running a bulk apply on a live site, where one run of a single rule applied every applicable enabled rule, as designed; the dropdown's wording is what misled. A dismiss control on each result card shipped as an interim for the stale-card bullet.
- **Open:**
  - **Scope selector.** Post type(s) to bring up to date, running every enabled rule that applies, replacing the rule dropdown and "All enabled rules". An optional "also run this disabled rule once" keeps the one-time run.
  - **Honest change count.** Count observed writes (the old and new term sets `set_object_terms` reports) instead of only the posts the run passed; today fan-out writes are noted, not counted, and a run that writes other posts can read "0 changed". For a run containing format rules, report posts processed only until their writes are observed too.
  - **Attribution.** Each report row names the rule that caused the change and shows the post's status, since a pass writes drafts when a gate allows it. Check first that the dispatcher can name the row an applier is running.
  - **Reach count.** "N posts these rules can affect", from FW-58, not every post of the type.
  - **Stale result cards.** Wireframe 1.1.0 keeps one result slot per button and only that button's next run replaces it, so a Preview card outlives an Apply and the first batch's card outlives every Continue. The real fix is a shared result region upstream; that is an ask for the maintainer to file, not one to file from here. A CSS-only auto-hide was rejected because without recency it can hide a fresh result and keep a stale one.
  - **Button state.** Preview and Apply stay enabled during a run while Continue and Start over are the ones that apply, and the reverse once the scope changes. Wireframe 1.1.0 offers only a global disable while any button is busy; there is no per-button `disabled` and no way for the response to set one. An upstream ask for state-driven buttons, next to the shared result region, for the maintainer to file. A script disabling by button position was rejected as fragile. Interim: Apply during a run returns a "press Continue or Start over" card instead of silently resuming.
- **Blocked by:** — • **Interacts with:** FW-23, FW-31, FW-33, FW-56, FW-58


#### FW-58 — Rule-declared reach narrowing for Apply

Apply's reach is every post of the written post types in the default statuses, deliberately conservative, so a rule that can only change some of them still passes all of them. Let a rule type declare a narrower reach, defaulting to none.

- **Detail home:** [design-history/apply-existing.md](design-history/apply-existing.md); [CONTEXT.md](../CONTEXT.md) → *Reach*.
- **Progress:** Not started. A live site's propagation run passes about 6,000 posts when only the ones with a parent can change, which is most of its batch count.
- **Open:**
  - **Interface.** An optional narrowing on the rule-type descriptor beside `written_post_types()`. Across several rules the reach is the union of their narrowings, and a rule without one reaches its whole post type.
  - **First type.** Propagation: `post_parent > 0`, with the parent gate staying in the applier. Every other type stays conservative until it has a safe narrowing; related post terms is a candidate (dependents holding the reverse field).
  - **Safety.** A narrowing that leaves out a post the pass would have changed under-applies silently, so it must be provably a superset. Pin it with a sweep that compares narrowed and conservative runs on the fixture.
- **Blocked by:** — • **Interacts with:** FW-57


#### FW-59 — Flag identical rules

Two rows identical in type, taxonomy, scope, claim and config raise no distinct warning, so an author cannot tell a true duplicate from an overlap. The collision advisory sits above the rule list, where it was missed.

- **Detail home:** none; this row carries it. Raised alongside FW-34 (the minimal detector is #65).
- **Progress:** Not started. A live site had two identical rows after one was meant to be pointed at another taxonomy. The advisory was there but not noticed, and nothing said these were the same rule.
- **Open:** whether exact-duplicate detection is a case of the pairwise warning with its own message or of FW-34's reach analysis, and where the advisory should sit so it is not missed (beside the offending rows). FW-56 removes the commonest cause, not the possibility.
- **Blocked by:** — • **Interacts with:** FW-34, FW-56

#### FW-60 — Apply page: show unfinished runs on load

A stored run waits up to a day for its next batch, but nothing on the Apply page says it exists until a button is pressed. Apply on a choice with a stored run returns the "in progress" card with its tally; the same information should be there on arrival, with a way to discard the run.

- **Detail home:** none; this row carries it. Interacts with FW-57's button-state bullet.
- **Progress:** Not started. Raised when a stale run from an earlier test made Apply report "in progress" before any batch of the current session had run.
- **Open:**
  - **Finding runs.** The transient key is an md5 of user and choice, so runs cannot be listed from storage. Build the page's choice options as now and call `ExistingPostsApplier::progress()` (returns the stored tally or null) for each, including "All enabled rules". A run whose rule row changed gets a new fingerprint and so a new key; it is not listed and expires with its 24-hour transient.
  - **Surface.** Wireframe has no static text or notice field and a custom field type renders nothing, so a standard `admin_notices` notice on the Apply page only: one line per run with its label and tally.
  - **Dismiss.** A nonce-protected `admin-post` link calls `start_over()` and redirects back. "Permanently" means the stored run is deleted; no separate dismissed flag. Per user, like the runs.
  - **No Continue link.** Continue needs the dropdown set to that rule and Wireframe has no client-side API to set it (FW-23), so the author picks the rule and presses Continue.
- **Blocked by:** — • **Interacts with:** FW-23, FW-57

---

## UX polish

The rule editor itself: labels, row titles, field visibility, pickers.

#### FW-20 — Standardized repeater row-title schema

Each rule type builds its collapsed-row title independently — a per-handler `snapshot_*_labels` callback in `WireframeBootstrap` assembling an ad-hoc string. They share primitives (`term_label`, `taxonomy_label`, `disabled_prefix`, post-type scope, en-dash windows) but no common grammar, so each is revised individually and drifts.

- **Detail home:** none. Raised during the Phase 3 handler migration, 2026-07-01.
- **Progress:** Not started. Titles stay per-handler and are revised one at a time.
- **Open:**
  - **The schema itself** — extract a shared title-builder with a small declarative schema (tokens, separators, conditional clauses) that each config declares, so the row title is data rather than a bespoke callback per handler, folding the existing `snapshot_*_labels` into it.
  - **A live enabled/disabled indicator in the collapsed header.** A disabled rule looks identical to an enabled one until expanded. 0.5.0 shipped a stopgap — a baked `[Disabled] ` prefix prepended to the leading title token at save (`WireframeBootstrap::disabled_prefix`) — which is accurate for *persisted* state but is a snapshot, not live, and covers only the two rule types whose labels were reworked. Stock Wireframe blocks the clean version from every side: `title_template` is raw token substitution with no client-side conditional (a live `{enabled}` token renders `true`/`false`), the repeater row header is hardcoded in the React bundle with a fixed `row-actions` set and no slot to inject a status pill or toggle, and a CSS-only dim is a dead end because a collapsed row does not render its body, so the `enabled` input is not in the DOM to drive a `:has()` rule. The real fix is a header-action slot, which a Gap A registry would not give by itself — it is an upstream ask in the same family as **FW-23**; until then the cheap consistency win is extending the baked prefix to the remaining rule types' title snapshots. Drop the prefix when the slot lands.
- **Blocked by:** `code:per-handler titles have not stabilized through use` — doing it before they settle means designing the grammar against a moving target. The live indicator additionally needs `code:Wireframe's repeater row header has no slot` (an upstream ask; a Gap A registry alone does not provide it); the baked-prefix extension needs neither • **Interacts with:** FW-21, FW-23

#### FW-21 — Hierarchical rule label rework

The three Child Expansion Behavior options ("Smart", "Always", "Manual only") describe the **mechanism**, but users think in **outcome**. The same problem applies to a few other label sets across the rule UI.

- **Detail home:** none. Deferred from the Phase 2c discussion; candidate reframes are in that session's commit.
- **Progress:** Not started. Intended as one holistic pass across all seven rule-type configs rather than per-rule edits.
- **Blocked by:** — • **Interacts with:** FW-20

#### FW-22 — Subfield conditional visibility

Wireframe 1.0.6 (#13) added the conditions DSL to repeater subfields client-side, so the old workaround — always render both conditional subfields and explain the difference in description text — can be replaced with real show/hide `conditions`.

- **Detail home:** none. Upstream #13 is closed; this is plain implementation work now.
- **Progress:** **Converting opportunistically as each config class is touched.** Done: `level_restriction.include_ancestors` (0.6.0 — the gate itself was then retired in 0.8.0/#32 once the flag gained one meaning in every mode); the whole ordered term-rule repeater (0.8.0/#57), where the `type` select gates every type-specific subfield.
- **Open:** remaining description-text workarounds to convert — `related_rules.trigger_term_id` / `.trigger_taxonomy` (operator on the `trigger` select), and `title_slug`'s "Only used when…" subfields. The hierarchical `expansion_behavior` + help-text pair is gone; #16 collapsed it into one outcome selector.
- **Blocked by:** — • **Interacts with:** —

⚠️ **A condition-hidden subfield DROPS from the save payload.** Verify each show/hide on the test site and check that the storage adapter tolerates the absent key — subfield conditions evaluate against sibling subfields in the same row. This is the silent-data-loss trap in [architecture.md → The ordered rule repeaters](architecture.md#the-ordered-rule-repeaters), which H11 exists to catch.

#### FW-26 — Taxonomy-first cascading term picker

The trigger-term and target-term dropdowns list all terms across all taxonomies. Selecting a taxonomy first, then showing only its terms, would sharply reduce noise on sites with many taxonomies and many terms.

- **Detail home:** none; the Wireframe gap is FW-23 → Gap A. This is the one named, wanted use case that justifies the Gap A PR.
- **Progress:** Not started; deferred from Phase 3b. Static boot-time enumeration holds until then. **The design question is settled**: a custom Edit component receives full row context — `RepeaterEdit` passes `data={row}` to each subfield — so a `taxonomy_term_picker` field type reads its sibling taxonomy straight from `data` and fetches its term list via REST. **No sibling-update API is needed**; the earlier "must update a sibling select → blocked" framing was wrong, because one component owns both the taxonomy choice and the dependent term list. The `action`-field round-trip idea is a dead end — an action cannot update a sibling. Roughly a one-day build once unblocked.
- **Blocked by:** `row:FW-23` — specifically Gap A landing in an upstream Wireframe release • **Interacts with:** FW-23

#### FW-28 — State each rule type's fixed claim in its config

[CONTEXT.md](../CONTEXT.md) → *Claim* names the **claim** axis (*owning* / *contributing* / *deferring* / *restricting*) and made it author-visible — but only on `propagation`, the one rule type that already had a control for it. Every other type's claim is fixed in code and stated nowhere in its config. This item is purely *saying what the code already does*, in the vocabulary already on screen elsewhere: no new control, no storage change, no behavior change. FW-25 is the separate, much larger question of letting the author *change* it.

- **Detail home:** [CONTEXT.md](../CONTEXT.md) → *Claim* for the vocabulary. The issue that framed it was #54, which carries the per-type evidence table (file and line for each type's apply and remove sites).
- **Progress:** Not started. Deliberately sequenced *after* the ordered rule list, which reshaped every config — a wording pass written before it would have been redone. That list shipped in 0.8.0, so this is now unblocked.
- **Open:**
  - **The wording, per type.** The two hardcoded **owning** types are the ones that most need it, because owning is the claim that *removes* — e.g. on a time-based rule: "This rule **owns** the target term: it adds the term inside the window and removes it outside, whether this rule placed it or someone added it by hand. Terms other than the target are never touched." `hierarchical` deserves a second sentence, because its jurisdiction is **provenance-derived** (`_bws_auto_terms`) and it is the only type where that is true — it means hand-added terms are safe there in a way they are not under `time_based`.
  - **One naming overlap to settle with it:** `related_post_terms` already exposes this axis under a different name (`keep_in_sync`), so stating the claim there without reconciling the two names adds a second vocabulary rather than removing one.
- **Blocked by:** — • **Interacts with:** FW-24, FW-25

#### FW-50 — Generate tokens for the fields a rule can actually use

The *Available tokens* reference lists token shapes with placeholders (`{meta:field}`, `{term:taxonomy}`, `{date_year:field}`), so an author has to know their own field names and taxonomy slugs and type them in. Offer the concrete tokens for the post types the rule is scoped to, ready to click and copy.

- **Detail home:** none yet.
- **Progress:** Not started. Raised while moving the reference's intro text out of the accordion title (unreleased, FW-4 branch).
- **Open:**
  - **Where it lives.** The reference is one shared block above both rule types' list, but the useful set depends on a rule's own *Post types*, so a per-rule generated list (or a picker that inserts into the pattern field) is a different surface from the shared table. Click-to-copy is already in place; whether to add click-to-insert depends on what Wireframe offers (see FW-23).
  - **What counts as available.** Registered taxonomies for the rule's post types, ACF fields on their field groups (including repeater subfields, which a Computed field rule can already target), and plain meta keys seen in use. Which of those are worth enumerating on a site with many fields is the real question.
  - **Date fields.** One date field yields several tokens (`{date_year:}`, `{date_month:}`, …), so the list needs grouping, not one row per token.
- **Blocked by:** — • **Interacts with:** FW-4, FW-23, FW-49

---

## Infrastructure and testing

Storage backends, the vendored Wireframe, and the test harness.

#### FW-17 — CPT storage backend

Implement `Storage\CptRuleStorage` against the existing `Storage\RuleStorage` interface — a single shared CPT `bws_mc_rule` differentiated by `rule_type` meta, with per-type routing in `Storage\StorageFactory` — for a rule type that genuinely needs a list table, a draft/active lifecycle, or standard WP query power.

- **Detail home:** [storage-model.md](storage-model.md).
- **Progress:** **Deferred, unscheduled.** Was Phase 4; reassessed 2026-06-23, and Phase 4 became the ordered rule list instead. The blast-radius and clobber concerns CPT was originally reached for are covered by the version-token guard. Accumulation alone no longer triggers CPT — per-entity explosion is solved by indirection, not N rows. CPT remains a deferred *option* for a draft/test lifecycle, not a planned migration.
- **Blocked by:** `decision:a rule type actually needs a draft/test lifecycle` • **Interacts with:** FW-4

#### FW-23 — Client-side custom field types

Wireframe has no JS-side field-type *extension* API: a custom type declared via the `wp-wireframe/field_types` filter registers server-side sanitize/validate but renders as nothing in React.

- **Detail home:** `libraries/wp-wireframe.md` in the maintainers' shared reference (private) — Gap A and Gap B mechanics, re-read against 1.1.0. The Meta Conductor use cases are FW-26 (Gap A), FW-31 (Gap B) and AJAX typeahead selects for large datasets (Gap A, theoretical: static boot-time enumeration holds, and no rule type is at that scale).
- **Progress:** **Partially unblocked in 1.0.6.** Its `action` field is a built-in escape hatch for the button case — a real React button that posts in-flight form values to a server hook and returns `{status, message, html}` — so the button case no longer needs the extension API. Page-level Preview and bulk Apply are implementable now via `action` on the FW-16 page. **Still open in 1.1.0** (2026-10-12): the release added no JS field registry. **The fork-release path is retired** (2026-10-12): from 1.1.0 the newest bundled Wireframe on a site serves every plugin's classes, so a fork shipped in our `vendor/` either loses to a newer upstream copy in another plugin — and our custom types silently render nothing — or overrides every other plugin's Wireframe.
- **Open:** two gaps, both upstream.
  - **Gap A — no JS field-type extension API (the root).** An additive registry plus `registerFieldType()` over the three `customEditComponents[type]` read sites. Next step: an upstream issue proposing it, drafted by us and filed by the maintainer of this repo; build the PR only if upstream agrees.
  - **Gap B — the `action` field has no repeater-row context.** Filed as [wp-wireframe#39](https://github.com/tdrayson/wp-wireframe/issues/39) (2026-09-23): an optional request `context` carrying both row identity and action continuation — the latter is what would retire FW-33. FW-16 ships without it; the in-row entry point it would enable is FW-31.
- **Blocked by:** — • **Interacts with:** FW-16, FW-26, FW-31, FW-33, FW-45, FW-59

#### FW-45 — Assess Wireframe 1.1 features

Wireframe 1.1.0 added features nothing here uses yet. Weigh each against a real need; one that is chosen splits into its own row.

- **Detail home:** none. Raised by the upgrade to Wireframe 1.1.0.
- **Progress:** Not started. The upgrade itself adopted nothing, by decision.
- **Open:**
  - **The JSON Schema (or the config linter) as an H-gate.** Upstream ships two checks. The runtime `ConfigValidator` (under `WP_DEBUG`) ran clean over both of our pages on the testbed at the upgrade, but it does not check condition operators. An unknown one such as `'=='` evaluates false and silently drops its subfield on every save, and the linter reports nothing (measured on 1.1.0). The shipped `schema/config.schema.json` does list the valid operators, so a host-PHP gate that checks our configs against the schema would catch that trap, along with other config mistakes the hand-written H11/H12 maps miss. Prefer the schema over the linter.
  - **`can_save` on the Apply page.** Its rule choice and limit persist as settings, though a run keeps the limit it started with. Is the Save button there meaningful?
  - **`header_actions`** — would the collision check or diagnostics read better as a header button?
  - **`download` payloads and table CSV export** — e.g. a collision report or diagnostics export.
- **Blocked by:** — • **Interacts with:** FW-16, FW-23

#### FW-27 — PHPUnit harness, starting with the snapshot label helpers

The repo's gates are plain-PHP `tests/verify-*.php` scripts run on bare host PHP, deliberately carrying no dev dependency. `WireframeBootstrap`'s `snapshot_*_labels` methods are the best first PHPUnit candidates in the codebase: static, pure `array → array`, hooked on `wp-wireframe/save/payload`, and load-bearing — they are what makes a collapsed repeater row readable instead of "Row 1".

- **Detail home:** none. Carved out of #53 §7, from the PR #19 review; the issue that framed it was #68.
- **Progress:** Not started. Partial coverage exists today from `verify-config-helpers.php` and `verify-propagation-labels.php`, but both assert on source strings and mappings rather than exercising the transform over real payload shapes.
- **Open:**
  - **The gap that matters** is the malformed or partially-populated payload. A condition-hidden subfield is *dropped* from the save payload server-side, so an absent subfield is the normal case, not an edge case — happy-path assertions do not catch a helper that fatals or silently blanks a title when one is missing.
  - **Three decisions before any code:** whether PHPUnit runs in CI (there is no test workflow today — only `claude.yml`, `claude-code-review.yml`, `release.yml`) or stays a local gate like H1–H14; whether WP calls are stubbed or a WP test bootstrap is pulled in (the former keeps the suite runnable on bare host PHP like the existing harnesses, the latter is a far heavier dependency); and whether the plain-PHP harnesses stay — they should, since several are *source-inspection* checks that PHPUnit fits poorly.
  - **Scope when it lands:** `require-dev` on PHPUnit plus a `/tests export-ignore` check so nothing new reaches the ZIP. Note the helpers are no longer uniform — four of the five now read the unified repeater's rows, while `snapshot_claim_override_labels` still reads the General tab's per-taxonomy default; fixtures must reflect that split rather than assume one shape.
- **Blocked by:** — • **Interacts with:** FW-20

---

## Closed / retired

Shipped or cut items retire here, densely — a closed item is read in bulk and never worked, so the table beats a heading block. **Ids are never reused.**

| Id | Item | Outcome |
|---|---|---|
| FW-4 | `field_transformation_rules`: Computed Field Output | **Merged in [#80](https://github.com/davidofchatham/meta-conductor/pull/80)** (2026-10-22, Phase 6a). A *Computed field* row in `format_rules` writes a pattern into an ACF field by key — post-level, or one subfield across every row of a repeater from that row's siblings — through `FormatDispatcher::write_fields()` on the `$data` seam, with `text()` / `html()` output policies. It composes by list order with title/slug rules and joins the collision check, the preview and *Apply to Existing Posts*. No pre-write phase was reintroduced ([architecture.md → Field effects ride the `$data` seam](architecture.md#field-effects-ride-the-data-seam)). Spec: [design-history/field-transformation.md](design-history/field-transformation.md). Left out of v1: FW-46, FW-47, FW-49; format transforms stay in site PHP. |
| FW-16 | Apply rules to existing posts | **Merged in [#75](https://github.com/davidofchatham/meta-conductor/pull/75)** (2026-09-24, Phase 7). Added the *Apply to Existing Posts* page: a bulk run is a full ordered pass over the chosen rule's reach, and a disabled rule can run once. Data Conversion and `includes/support/` were deleted. Spec: [design-history/apply-existing.md](design-history/apply-existing.md). Deferred parts are still open as FW-31 (in-row buttons), FW-32 (term dry run) and FW-33 (background runs); Copy / Map return as rule types through FW-4. |
| FW-18 | Text-domain string sweep | **Shipped in 0.7.0** (Phase 2b rename sweep, [#48](https://github.com/davidofchatham/meta-conductor/pull/48)) — the row survived the 2026-09-11 migration describing work already done. Every `__()` / `_e()` / `_x()` / `_n()` call site now passes `'meta-conductor'`; `'bws-meta-manager'` survives only as the Composer package name in `vendor/`. The *conversion* subsystem's identifiers (JS object, cron / AJAX / transient names) were never part of this row — they were the 2b remainder, closed by deletion with the Data Conversion page in FW-16. |
| FW-29 | `trigger_term_id`'s `int[]` invariant is declared but not enforced | **Settled by FW-39's storage PR** (`rule-type-descriptor/01`, branch `claude/storage-projection-39`): yes, `normalize_rule_shape()` is the guaranteed boundary. Checkbox gates arrive as slug lists, `target_term_id` as `int`, `trigger_term_id` / `filter_terms` as `int[]`; every consumer re-decode and re-cast is deleted, checkbox decoding moved out of `ConfigHelpers` into storage, and H10 asserts the shape for every rule type. |
| FW-39 | Rule-type descriptor + canonical storage projection | **Merged in [#76](https://github.com/davidofchatham/meta-conductor/pull/76) and [#77](https://github.com/davidofchatham/meta-conductor/pull/77)** (2026-09-26, 2026-09-28). #76 made storage's read projection the guaranteed canonical rule shape and deleted the pre-0.8.0 migrations behind an admin notice; #77 gave each rule type one descriptor in `RuleTypes\Registry`, the only place rule types are listed. Spec: [design-history/rule-type-descriptor.md](design-history/rule-type-descriptor.md). The review candidates it left out are FW-40, FW-41, FW-42, FW-43 and FW-5's graph split. |
| FW-40 | Extract the token engine from TitleSlugHandler | **Merged in [#78](https://github.com/davidofchatham/meta-conductor/pull/78)** (2026-09-28). The pattern engine moved into `Tokens\` ([architecture.md → Token engine](architecture.md#token-engine)); title and slug output is pinned byte-for-byte by a golden table under H17. Date tokens now parse in site time (CHANGELOG *Fixed*). Left for FW-4: the ACF-row source (the `text()` / `html()` output policies are built). Spec: [design-history/token-engine.md](design-history/token-engine.md). |
| FW-43 | Delete the dead `TaxonomyManager` and handler surface | **Merged in [#84](https://github.com/davidofchatham/meta-conductor/pull/84)** (2026-11-05). Deleted the four `wp_ajax_bws_*` endpoints and their level-restriction preview helpers, the dashboard-era `get_handlers_summary` / `get_plugin_status` / `check_system_requirements` / `get_handlers()` / `add_admin_menu`, the handler-side `get_rules_summary` / `get_active_rules` / `get_upcoming_rules` / `preview_changes`, the tracked `admin.js~` / `admin.css~` backups, and `reapply_for_post` with the `AcfWriteQueue` handler loop and constructor argument it fed. `TaxonomyManager` is now the composition root: handler construction, dispatcher wiring, the cleanup cron and the Admin Columns flush. |
| FW-44 | Orphaned separators around empty tokens | **Merged in [#80](https://github.com/davidofchatham/meta-conductor/pull/80)** (2026-10-22), with FW-4. `Tokens\TokenEngine` drops the punctuation around a middle or bracketed empty token, porting the rules (not the code) of the `{{join}}` tag's template mode; edge gaps keep the old reading. No gate: existing titles correct on their next format pass (CHANGELOG *Changed*). The golden table was regenerated deliberately. Spec: [design-history/field-transformation.md](design-history/field-transformation.md) → *Separator cleanup*. |
| FW-48 | Retire this repo's ADRs into the architecture anchors | **Merged in [#82](https://github.com/davidofchatham/meta-conductor/pull/82)** (2026-11-02). Each ADR's decision and rejected alternatives moved to the anchor that owns the code fact: [architecture.md](architecture.md) invariants 17–19, *The ordered rule repeaters* and *Effect-kind rule lists*, the [CONTEXT.md](../CONTEXT.md) *Claim* and *Jurisdiction* entries, and the FW-3 row. `docs/adr/` is now a redirect map with the bar for any future ADR; live citations were repointed to the anchors. |
| FW-51 | Flat URLs for hierarchical post types, and a general slug disambiguation ladder | **Merged in [#81](https://github.com/davidofchatham/meta-conductor/pull/81)** (2026-11-02). Collision avoidance on the title/slug rule is an ordered ladder of rung tokens (`slug_escalation` + `escalation_placement`, replacing `date_escalation` and `date_field`) walked by the pure `SlugLadder`, with `{mod_*}` and `{parent:slug}` tokens added to the engine; a one-shot upgrade rewrites stored rows. A `flat_urls` option serves a hierarchical type's posts at `/base/child/` through a `request` filter and `post_type_link`, makes `post_name` unique per type with reserved names numbered, 301s the nested form only when its ancestor chain matches, and raises an advisory notice for existing duplicate names. Read-time hooks are a descriptor fact (`runtime_hooks()`, [architecture.md invariant 20](architecture.md)). Spec and decision record: [design-history/flat-urls-slug-ladder.md](design-history/flat-urls-slug-ladder.md). Left out, additive if wanted: a slot token and a "don't splice dates" toggle for mid-slug placement; a whole-chain `{ancestors:slug}` token for types deeper than one level; slug-rename redirects on hierarchical types stay FW-36. The title/slug split is FW-52. |
| FW-53 | `user_based_rules` edit-scope: edit only posts holding your terms | **Retired 2026-11-03, superseded by [FW-55](#fw-55--user_based_rules-term-granted-edit-access).** Deny-only scoping needed an Editor role to reach other people's posts, which left untagged posts, other post types and the rest of the role open; the strict variant locked users out of their legacy untagged posts. FW-55 grants access instead of restricting it. |

---

## Maintenance

**Where this list comes from.** The Phase 6+ assignments of `ROADMAP.md`, retired 2026-09-24 once Phase 7 was built. Its completed-phase record is in [CHANGELOG.md](../CHANGELOG.md) and git history, its naming convention in [architecture.md](architecture.md#naming-surface), and its open items were folded into rows here. Other sources: deleted standalone plugins under `plugins-to-integrate/`, captured at deletion time so the intent was not lost; session discussion that did not fit a phase; and — since 2026-09-11 — the non-bug GitHub issues, which moved here wholesale when the split in [docs/agents/issue-tracker.md](agents/issue-tracker.md) took effect. An issue that names an item is cited as the thing that *framed* it, not as a live home: it is closed, and this file is the home now.

**When an item becomes work in flight**, point its `Detail home:` at the spec and put the real build state in `Progress:` — do not move it to a separate section, and do not write a percentage.

**When an item ships**, move the row to [Closed / retired](#closed--retired), leave the id retired, and let [CHANGELOG.md](../CHANGELOG.md) carry the behavior delta. If it had a spec, the spec lifts into [design-history/](design-history/) at merge — see that directory's README for the contracts, in particular that an **unbuilt** plan is never lifted.

**Private paths are allowed here and nowhere else.** A `Detail home:` naming `.scratch/plans/*.md` is written as inline code rather than a link, because the path resolves for one person and a link would render as broken. Every other committed file cites an `FW-N` id instead.
