# Future work tracker

**Not a roadmap.** Nothing here carries a committed timeline. This is the single *visible* index of non-bug work — a tracked, reviewable surface over detail homes that are mostly private. It duplicates no detail: open the linked home for the full design and rationale. One heading block per **`FW-N` id**.

- **Bugs do NOT go here** → [GitHub Issues](https://github.com/davidofchatham/meta-conductor/issues). Issues hold bugs and anything an outsider is waiting on; everything else — enhancements, refactors, open design questions, testing debt, repo hygiene — lives here. See [docs/agents/issue-tracker.md](agents/issue-tracker.md) for the split.
- **Detail lives in its home** — a private plan file, a [design-history](design-history/) document, or an [ADR](adr/). An item states only *that the work exists, what gates it, what it touches, where it stands, and where to read more*.
- **This file is the only one allowed to cite a `.scratch/` or `.claude/` path.** Elsewhere such a link resolves for one person and dangles silently for every other reader, so `scripts/check-private-citations.sh` hard-fails on it in CI and exempts this file alone. Cite a live plan by its `FW-N` row here; cite a plan by path only once it is finished and lifted into [design-history](design-history/).
- **Cross-refs use ids, never prose**, so a reworded item never orphans a reference. `FW-7` is an item here · `#7` is a GitHub issue · `<slug>/03` is a local ticket. A bare integer is ambiguous between all three.
- **Ids are permanent.** A shipped or cut item's id retires to [Closed / retired](#closed--retired); it is never reused or reassigned.

New **rule type** entries should also state their **axes** — basis (relation / intrinsic / ambient), effect target, jurisdiction, and claim. The axes are defined in [CONTEXT.md](../CONTEXT.md); [ADR 0002](adr/0002-cross-rule-composition.md) and [ADR 0004](adr/0004-claim-axis-and-jurisdiction.md) explain what they constrain. They are worth stating early because two of them predict real build cost before any code is written: an **ambient** basis whose value moves on its own needs a cron sweep, and a **scalar** effect target (a field, a title) can never compose — two rules sharing one always collide.

## Index

- [Item shape](#item-shape)
- [Rule types](#rule-types)
- [Tools and infrastructure](#tools-and-infrastructure)
- [UX polish](#ux-polish)
- [Closed / retired](#closed--retired)
- [Maintenance](#maintenance)

## Item shape

Each item is a `#### FW-N — <title>` heading followed by a fixed set of labeled lines:

- A **description** paragraph (1-3 sentences): what the item IS. Stable, rarely re-edited — not current state, not history.
- **Detail home:** where the design and rationale live. Never duplicated here; open it for the full story. **This file is the one committed file allowed to name a private path** — concentrating those paths here is exactly what makes the rule enforceable everywhere else.
- **Progress:** where the work actually stands. Always present, even if just "Not started."
- **Open:** what is still undecided or unbuilt, when there is a real done/open split. Omitted when there is nothing beyond Progress worth stating.
- **Blocked by:** hard prerequisite, typed (below). **Interacts with:** soft coupling — reshapes, or is reshaped by — as `FW-N` ids, never a gate. Both on one line; `—` means none.
- **Phase:** only where [ROADMAP.md](../ROADMAP.md) has actually assigned one.

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

- **Detail home:** `.scratch/plans/temporal-rule.md` (scoping + open questions). Domain vocab: [CONTEXT.md](../CONTEXT.md). Model decision: [ADR 0001](adr/0001-temporal-rule-general-model-constrained-ui.md).
- **Progress:** Scoping. Absorbs the "post expires N after its date field" pattern and the previously-planned `date_based_taxonomy_rules`, folded in rather than built as a separate type. Storage settled: Options, normalized through the canonical-shape adapter, no dot-notation — **not** gated on CPT and not migrating to CPT ([ADR 0001](adr/0001-temporal-rule-general-model-constrained-ui.md) → Consequences). Since the ordered list shipped in 0.8.0 this is a `type` within `term_rules`, not its own array.
- **Open:** ⚠️ It must expose `apply_to_post()` and register **no hooks of its own** — including its cron sweep, which becomes a candidate-post query that hands each post to the dispatcher ([ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md)).
- **Blocked by:** — • **Interacts with:** FW-25
- **Phase:** targeting the 0.x line, before 1.0.0

#### FW-4 — `field_transformation_rules`: Computed Field Output

Combine multiple source fields into one formatted output field — merge first/middle/last into a display name, combine date + time into a sortable datetime, format a phone number, derive a bio string from stats. The rule declares an output meta key plus a template.

- **Axes:** basis **intrinsic** (reads the post's own fields), effect target **field** (scalar), claim *owning*.
- **Detail home:** `.scratch/plans/field-transformation-token-gap.md` — token-gap analysis of two real template helpers against the existing resolver, plus the repeater-scope blockers. Reachability ≈ 50% / 70% with the resolver alone (post-level); the shortfall is conditional/transform logic, markup emission, and row-scoped read/write.
- **Progress:** Not started. Already declared in `KIND_TYPES` ahead of its subfields — that separation is what lets it be fanned in and read before its config exists (CLAUDE.md don't 6). ~60–70% of the engine exists as the TitleSlugHandler token engine (`resolve_token()`, pattern→segments→resolve-or-drop→reassemble, with empty-token + dangling-separator dropping). Net-new: a **target-field write path** (arbitrary meta/ACF key, not just `post_title`/`post_slug`), a **raw-vs-sanitize output policy** flag so literal HTML survives, and new token classes (value-filter `{term:TAX|exclude:…}`, conditional `{if_term:…}`, optional format-transform). The repeater *write* is cheap and verified (2026-06-26): `update_sub_field(['rep', $row, 'sub'], $val, $post_id)` on `acf/save_post` pri 20 maintains ACF's field-key reference meta and needs **no re-entrancy guard** — it does not re-fire `acf/save_post`/`save_post`; only `wp_update_post` would.
- **Open:** must work **inside ACF repeater rows, not just post-level** — each row composes from its own sibling subfields into a per-row output subfield. The cost is **row-scoped token reads** (the flat resolver's `get_post_meta($post_id, KEY)` must become `get_sub_field()` in row context) plus a `have_rows()` loop. Storage TBD — run [storage-model.md](storage-model.md) when designed; likely Options + indirection unless a per-recipe draft/test lifecycle is wanted. ⚠️ **Scalar effect target**: per [ADR 0002](adr/0002-cross-rule-composition.md) two rules writing one field can only be last-writer-wins, so they **always** collide — there is no contributing mode for a scalar. Lands in the Format & Transform list where **ordering is real**: title/slug reads `{meta:field}`, so a field rule writing a key a title rule then reads is a producer→consumer pair *within* one list, and the reverse is equally possible, so the order is genuinely ambiguous and the author sequences it ([ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md)). This is also what returns the format dispatcher to **two-phase**: title/slug pre-write, this post-write on `acf/save_post` pri 20.
- **Blocked by:** — • **Interacts with:** FW-5, FW-13
- **Phase:** 6a

#### FW-5 — `relationship_field_rules`: Set a field across a relationship

`related_post_terms_rules` already copies *terms* across an ACF relationship; the same edge should carry a *field value* — an event's venue post supplying its address into each session, or a team post pushing a season label onto every player. Same holder/direction model (the ACF field pins the holder post type, `holder_role` says which end is authoritative), with the effect swapped from terms to a target meta/ACF key.

- **Axes:** basis **relation** (authored — an ACF relationship / post-object field), effect target **field** (scalar), claim TBD.
- **Detail home:** none. Raised 2026-08-12 in the cross-rule composition session.
- **Progress:** Not started. Most of the traversal, direction and reverse-lookup machinery already exists in `related_post_terms_rules`; the net-new work is the field write path — shared with FW-4 — and deciding the claim.
- **Open:** ⚠️ **Scalar effect target** — a scalar's **jurisdiction** is its single slot, so any two rules writing it share the whole of it and **always** collide. Unlike terms there is no contributing option: the claim is *owning* or nothing. Storage TBD — likely a `type` within `format_rules` alongside FW-4, since both write fields.
- **Blocked by:** `code:no field write path exists` — cleared by FW-4 • **Interacts with:** FW-4, FW-7

#### FW-6 — `body_class_rules`: Document classes from terms/fields

Let an editor declare the `body_class` branching that themes routinely hand-roll (`is-event`, `season-2026`, `status-cancelled`). The rule declares a source — taxonomy, term, or field value — and a class template; the handler hooks `body_class` and probably `post_class`, reusing the TitleSlug token engine with slug sanitization on output.

- **Axes:** basis **intrinsic** (reads the post's own terms/fields), effect target **body class** — **rendered, not stored**.
- **Detail home:** none. Raised 2026-08-12 in the cross-rule composition session.
- **Progress:** Not started. Partly settled by [ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md): *rendered* is a value on the **effect kind** axis ([CONTEXT.md](../CONTEXT.md) → *Effect kind*) and sits last in the derived cross-kind order — it reads terms and fields and is written by nothing, so it is a pure sink.
- **Open:** **a rendered effect behaves differently from every rule type built so far.** It is computed per request and never persisted, so: (a) **no claim question** — nothing is stored, so nothing can strand or need reconciling; (b) **no sweep** — it recomputes on every render, so even an ambient basis needs no cron; (c) **no collision in the stored sense** — two rules emitting classes simply both emit, since the target is a list the theme concatenates. Given (c), it is genuinely open whether it needs an ordered list at all: with no collisions possible there is nothing for an author to sequence. Storage is Options; the list is TBD — its own `display_rules`, or a `type` within `format_rules` even though it neither formats nor transforms stored data.
- **Blocked by:** — • **Interacts with:** FW-13

#### FW-7 — `term_provisioning_rules`: Create a term per post, then apply it

A CPT whose posts each need a matching term so *other* content can be tagged against them — every `team` post gets a `team` term, and player posts are tagged with it via the team relationship. Today that is hand-maintained and drifts the moment a post is renamed or added. For each post passing the filter gate, ensure a term exists in the target taxonomy (name/slug derived from the post, likely via the token engine), then apply it to related posts over a configured relation.

- **Axes:** basis **relation**, effect target **the taxonomy itself** (term existence) *plus* terms on entities.
- **Detail home:** none. Raised 2026-08-12 in the cross-rule composition session.
- **Progress:** Not started. Storage is Options. Worth scoping as **two rules composed** rather than one monolith — the "apply it via a relationship" half is already FW-5 or `related_post_terms_rules`, and composition is exactly what the ordered rule list ([ADR 0002](adr/0002-cross-rule-composition.md)) is meant to support.
- **Open:** ⚠️ **a genuinely new effect shape.** Every rule type so far writes a *value on an entity*; this one mutates the **term vocabulary**, creating rows other rules then reference. Three questions with no precedent in the current model. **Lifecycle**: post deleted → delete the term, orphan it, or leave it? Post renamed → rename the term, or leave the slug stable because permalinks and queries depend on it? **Claim over a term's existence**: is "this term should exist" *owning* (so removing the rule deletes terms) or *contributing* (create-only, never destroy)? Contributing is almost certainly right — destroying terms destroys other posts' assignments — but it is a real decision, not a default. **Collision**: two provisioning rules targeting one taxonomy contend over term *existence*, which the current reach/effect-target predicate does not model.
- **Blocked by:** `decision:claim semantics over term existence` • **Interacts with:** FW-5, FW-10

#### FW-8 — `user_based_rules`: User-Based Term Setting / Restriction

Pre-set terms in a taxonomy based on the current user (role or ID), or lock a taxonomy so only specific roles can edit it. Absorbs the existing standalone plugin `bws-user-based-terms` from its own repo: each rule maps user role or ID → taxonomy → term(s), auto-set applying on save, restrict filtering term lists in admin.

- **Axes:** basis **ambient** (the acting user and their role — attached to the request, not to the post), effect target **terms** for *both* variants, claim *owning* for auto-set and *restricting* for the lock variant.
- **Detail home:** `.scratch/plans/ubt-merger.md`. Storage rationale: [storage-model.md](storage-model.md). Source: the external `../bws-user-based-terms/` repo.
- **Progress:** Not started. Storage settled: Options, a `type` within `term_rules` — changed from CPT (2026-06-23), then from a dedicated `bws_mc_personalize` page option (2026-08-13, [ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md)). Role/user is the *target*, not the owner, so there is a single author and no concurrent writes; per-user explosion is solved by indirection (a profile field plus one rule), not N per-user rules. UBT's own `priority` field maps onto **list position**. The handler registers **no hooks of its own** — the dispatcher owns them; it exposes `apply_to_post()` like every other handler. **Ambient basis, but no sweep**: the acting user only matters at the instant of a write, so the dispatcher's save trigger suffices — contrast FW-3, whose ambient "now" moves on its own and therefore needs cron.
- **Open:** ⚠️ type-key name unsettled — `user_based_terms_rules` (ubt-merger plan) vs `user_based_rules` (here and [storage-model.md](storage-model.md)). Under the unified list this is a `type` value, not an option key, so it is cheap to settle at build time — but settle it deliberately.
- **Blocked by:** — • **Interacts with:** FW-25
- **Phase:** 6b

**Disambiguation resolved 2026-08-13.** The restrict variant was previously described as a *rendered* effect on "field editability". It is not. Its effect target is **terms** and its claim is **restricting** — it applies nothing and requires the target to satisfy a constraint, exactly like level-restriction. Filtering what the admin UI offers is the *surface*, not the effect, so "restricting the editor" and ADR 0002's restricting claim are the same sense after all ([ADR 0003](adr/0003-ordered-rule-list-and-dispatcher.md), [CONTEXT.md](../CONTEXT.md) → *restricting-the-editor is still a restricting claim*). Consequence: both variants order among the other term rules in the same **pass** and participate in the collision warning.

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
  - **Manual-survives mode** — let hand-added, non-source-derivable terms persist under Keep-in-sync; today the synced taxonomy is wholly rule-owned. **Blocked on a rejected primitive**: this needs provenance (rule-domain-vs-manual tracking), which [ADR 0002](adr/0002-cross-rule-composition.md) rejected for the third time, after ADR 0001 and §V3. Wanting it reopens that decision plugin-wide rather than being a local feature. Cheaper alternative inside the current model: expose the rule's **claim** as *contributing* instead of *owning*, which never removes anything — manual terms survive because nothing reconciles, at the cost of losing source-authoritative cleanup.
  - **True cross-taxonomy copy** — map terms by slug/name so source and target taxonomies can differ; the current copy is by ID, single taxonomy only.
  - **Multi-level chain propagation** — a post that is BOTH a dependent (of A) and a source (for C) does not propagate to C in the same save: its term-change is suppressed by the re-entrancy guard while it is being written. Chains deeper than 2 levels need a depth-bounded re-dispatch after each write. **Confirmed as the only path by [ADR 0002](adr/0002-cross-rule-composition.md)**: cascade is suppressed within one effect target, so a rule's own write will *never* re-trigger peer term rules — waiting for the cascade is not an option that was taken away, it is one that never worked. The design is an explicit depth-bounded re-dispatch inside the handler, mirroring how propagation already walks its whole subtree itself rather than relying on cascade. Build only if a 3+ level chain appears.
  - **Single-owner optimization** — skip the multi-source rule-union when a dependent provably has one owner (ACF `max=1` / native bidi). Negligible gain when a reverse field is configured; only matters for the meta_query fallback with large fan-out.
  - **Multiple taxonomies per rule.** `$rule['taxonomy']` is scalar, and so are `severed[post][taxonomy]`, the status gate and the capture — to sync several taxonomies across the *same* relationship you duplicate the rule once per taxonomy. Duplicating is **correct**, not a workaround: each taxonomy mirrors the source's full term set independently, so there is no correctness penalty. The only material win is **shared relationship-graph resolution** — N duplicated rules each run `dependents_of_source` / `resolve_reverse` per save, which is N cheap `get_field` reads under tier 2 (the common case) but N unindexed LIKE scans under tier 3, multiplying exactly the cost FW-11 flags. So the payoff scales with tier-3 usage and this is worth scoping *with* FW-11, not before it. Cost: scalar → array touches `recompute_dependent`, `capture_removed_dependents`, `process_severed`, every label snapshot and the storage shape — mechanical, since the per-taxonomy logic is already a clean loop boundary.
- **Blocked by:** `decision:reopen provenance` — manual-survives only; the rest are unblocked • **Interacts with:** FW-9, FW-11, FW-19

#### FW-11 — Tier-3 reverse-lookup is an unindexed query on every eligible save

A pull rule with NEITHER an explicit reverse field NOR a detectable ACF bidirectional field falls back to `find_holders_referencing` — an unindexed `meta_query` LIKE over all holder posts — on every eligible save. B7 (0.5.0) removed the spurious calls on ineligible saves; the legitimate case is still O(N).

- **Detail home:** [design-history/acf-reference-rework.md](design-history/acf-reference-rework.md) → the reverse-lookup tiers. The issue that framed it was #22.
- **Progress:** Not started. Mitigated in the UI: the config warns the admin to set a reverse or bidirectional field. A code fix — a reverse index maintained on relationship-field save, or a registry built at rule-save time mapping related-post → holders — would remove the warning. Deliberately kept out of #63 even though that ticket rewrote the handler: the fix is its own design decision with its own invalidation questions, and riding it along would have widened the largest ticket in the phase with work that had no dependency on the ordering model.
- **Open:** the reverse-lookup-resolution cluster is best decided in one sitting — this, FW-10's *multiple taxonomies per rule* (whose only material payoff is sharing exactly this resolution), and #25 (resolve fields by key, not bare name) all touch how the "other end" is resolved.
- **Blocked by:** — • **Interacts with:** FW-10

#### FW-12 — Sub-scope field for restricting rules

A rule whose **claim** is *restricting* (today only level-restriction) declares no narrower **jurisdiction**, so it governs its entire taxonomy and necessarily collides with any rule touching that taxonomy on overlapping post types. The archetype: term-hierarchy `child_to_parent` *adds* ancestors while level-restriction `include_ancestors=false` *strips* them — an unresolvable contradiction that can only be warned about. A sub-scope field ("governs levels 3–4 only", or "only under branch X") would let such pairs be made genuinely **disjoint** instead of merely warned: the collision dissolves rather than being reported.

- **Detail home:** [ADR 0002](adr/0002-cross-rule-composition.md), where it was deferred.
- **Progress:** Not started.
- **Blocked by:** `decision:is level_restriction live on a real site` — it needs config + storage + UI on a **shipped** rule type, so the live-rule-type schema rule applies before any schema change • **Interacts with:** FW-25

#### FW-13 — Scoping which provocations a format rule answers

#64 put `format_rules` on the term dispatcher's queue, so the format pass now runs for **every entity the drain reaches** — a propagation fan-out's children, a captured sever's dependent, anything the ACF write queue flushed — not only for posts that were saved. That is the correct reading of a rule that consumes terms, and it fixed real staleness: before #64 a post whose terms were rewritten by *another* post's rule kept its old `{term:...}` title until somebody re-saved it. It also carries a cost the ticket accepted deliberately rather than solved.

- **Detail home:** none yet. Context: CLAUDE.md don't 6f(e), [architecture.md](architecture.md) invariant 18, fixture matrix §7 restore gotcha.
- **Progress:** Not started. Documented as accepted behavior in [CHANGELOG.md](../CHANGELOG.md) under the live-rule-type policy.
- **Open:** two independent halves, worth separating.
  - **Scope.** A format rule has no way to say *which* provocations it answers — everything or nothing. The honest shape is **not** a per-rule "only on save" toggle: that recreates exactly the provocation-dependent behavior the dispatcher exists to remove ([CONTEXT.md](../CONTEXT.md) → **Pass**). More promising is making the **effect** conditional rather than the pass. A rule already recomputes from live state on every pass; what it could gain is a declared *stability* — "compute the slug once, then leave it" (`slug_locked_after_publish`) — which is a property of the rule's meaning rather than of how it was provoked, and composes with any provocation set. Title and slug want different answers: a title is cheap to change, a published slug is not.
  - **Slug safety.** A rule-driven `post_name` change has no redirect and no audit trail, whoever provoked it. A term edit on a parent can rename a published child's slug and **WordPress leaves no redirect behind**, so an indexed URL 404s from an edit made on a different post. The reach is wider than it looks: on the mc-rules testbed, `sweep-63-acf-reference.php restore` writes a relationship field on the holder, which marks the referenced item dirty, which gives it a format pass, which renames it — nothing there is a save. Fix: write a `301` when a rule changes a published post's `post_name`, into a redirect plugin's store where one exists or a small owned table. Also worth a **dry-run** — the diagnostics page listing what the current rule set *would* rename, which is what an author actually wants before enabling a slug pattern on a live site.
- **Blocked by:** — • **Interacts with:** FW-4, FW-6

**Why this is not a bug.** Both behaviors are documented, and the alternative — gating the format pass to save-shaped provocations — reintroduces the staleness #64 removed. This is a feature the model now has room for, not a regression to undo.

#### FW-14 — Rule-type renaming on the domain axes

Current rule-type names conflate **basis**, **effect target** and **claim** into one string, which is why `hierarchical` (term graph) and `propagation` (post graph) read as near-synonyms, as do `related` (term↔term) and `related_post_terms` (post↔post). Names should be composed from the axes once those have settled.

- **Detail home:** [ADR 0002](adr/0002-cross-rule-composition.md), where it was deferred. Axis definitions: [CONTEXT.md](../CONTEXT.md).
- **Progress:** Not started. Storage keys (`related_rules`, `time_based_rules`, …) are unaffected — this is domain and UI vocabulary only.
- **Blocked by:** `code:the Effect axis carries only term values` — renaming before it carries field, title and body-class values means minting names twice • **Interacts with:** FW-4, FW-5, FW-6

---

## Tools and infrastructure

#### FW-15 — Post type converter

Take a defined group of posts — all descendants of a page, or all posts in a taxonomy — and convert them to a different post type without losing meta, terms, parent/child relationships or attachments. The common scenario: a content tree created under `page` that should have been a custom post type, where manually re-creating loses ACF data and breaks internal links.

- **Detail home:** none.
- **Progress:** Not started. Feasibility mapped against what moves with the row: the `wp_posts.post_type` flip is the easy part; meta and ACF data stay (keyed by post_id, post-type-agnostic in the DB); term assignments stay only if the target type has the same taxonomies registered, else they need a mapping or drop strategy; `post_parent` survives the flip but only renders correctly if the target type is hierarchical; permalink structure changes, so old URLs need redirect entries.
- **Open:** UX shape is sketched — source picker (descendants-of-page / posts-in-taxonomy / `WP_Query` args / hand-picked IDs) → target post type → preview rows with per-taxonomy mapping decisions and warnings → dry-run report → commit with optional redirect generation. ⚠️ The flip is destructive to query results elsewhere, so preview plus dry-run are mandatory, not optional.
- **Blocked by:** — • **Interacts with:** FW-16
- **Phase:** candidate launch recipe inside FW-16 (Phase 7). The recipe shape fits cleanly: source_query, transform = post_type flip plus side-effects, preview = before/after row, commit = batched UPDATE plus redirect insert.

#### FW-16 — Unified Migration / Preview tool

A dedicated migration page hosting one-shot data transformations across rule types, with recipes registered via a `bws_meta_conductor_migrations` filter. Each recipe declares source_query / transform / preview / commit callbacks; the UI is recipe picker → parameter form → preview sample → run with chunked progress.

- **Detail home:** [ROADMAP.md](../ROADMAP.md) Phase 7.
- **Progress:** Not started. Storage: none — recipes are registered code. This page is what **sidesteps** the Wireframe client-side field-type blocker (FW-23) rather than waiting on it: inline Preview / Apply-to-Existing buttons in rule rows are blocked, so bulk operations route here instead. That is by design, not a stopgap.
- **Open:** launch recipes — ACF → taxonomy term (absorbs the current Copy Data flow), Field A → Field B value mapping (absorbs Map Data), apply a title/slug rule to existing posts (replaces the blocked inline button), and FW-15.
- **Blocked by:** — • **Interacts with:** FW-15, FW-23
- **Phase:** 7

#### FW-17 — CPT storage backend

Implement `Storage\CptRuleStorage` against the existing `Storage\RuleStorage` interface — a single shared CPT `bws_mc_rule` differentiated by `rule_type` meta, with per-type routing in `Storage\StorageFactory` — for a rule type that genuinely needs a list table, a draft/active lifecycle, or standard WP query power.

- **Detail home:** [storage-model.md](storage-model.md).
- **Progress:** **Deferred, unscheduled.** Was Phase 4; reassessed 2026-06-23, and Phase 4 became the ordered rule list instead. The blast-radius and clobber concerns CPT was originally reached for are covered by the version-token guard. Accumulation alone no longer triggers CPT — per-entity explosion is solved by indirection, not N rows. CPT remains a deferred *option* for a draft/test lifecycle, not a planned migration.
- **Blocked by:** `decision:a rule type actually needs a draft/test lifecycle` • **Interacts with:** FW-4

#### FW-18 — Text-domain string sweep

The main file is `meta-conductor.php` and the header `Text Domain:` is `meta-conductor`, but internal `__()` / `_e()` / `_x()` / `_n()` string arguments still read `'bws-meta-manager'` / `'bws-taxonomy-manager'`. No `.po` files exist, so the mismatch is cosmetic today — it becomes real the moment translation is wanted.

- **Detail home:** none.
- **Progress:** The *rename* half shipped in 2c; this is the remainder of Phase 2b. ~600 call sites — mechanical find/replace plus a lint pass to catch stragglers.
- **Open:** collision-safe layers (PHP namespace, option keys, hook prefix) keep `bws_` / `BWS\` per the Naming Surface decision. This sweep touches text-domain arguments **only** — widening it re-opens a settled decision.
- **Blocked by:** — • **Interacts with:** —
- **Phase:** 2b remainder

#### FW-19 — Grouped / nested relationship fields for Related Post Terms

`related_post_terms` is verified only for **top-level** ACF relationship/post-object fields. The field picker (`ConfigHelpers::acf_relationship_field_options()`) enumerates top-level fields only — `acf_get_fields($group_key)` does not recurse into Group / Repeater / Flexible-Content subfields — so a nested relationship field never appears as a choice.

- **Detail home:** [architecture.md](architecture.md) handler-invariant #6 documents the trap. Upstream context: #37.
- **Progress:** Not started; a known capability gap, not a live bug. No current rule uses a nested field, so nothing is broken today, and the UI warns that only top-level fields are supported.
- **Open:** **failure modes if a nested field is forced in via config.** ACF's `acf/update_value` `$field['name']` and Admin Columns v7's `get_meta_key()` both return the **bare** subfield name with the group prefix stripped, so the capture filter's `acf_field_name === $field_name` match and the planned AC-v7 reapply fallback silently miss. Additionally a **Repeater/Flex**-nested field breaks `read_relationship` entirely — `get_field('sub', $post_id)` has no row context. Group-nested `get_field('group_sub')` (qualified) does resolve, so the read is fine for Groups; only the name-matching is wrong. Sketch: (1) recurse Group subfields in the options builder, using the qualified name; (2) match by ACF **field key** (`field_xxxx`) instead of raw name everywhere `acf_field_name` is compared, or reconcile bare↔qualified; (3) Repeater/Flex support needs row-context resolution — larger, likely out of scope. Drop the top-level-only UI warning once (1)+(2) land for Groups.
- **Blocked by:** — • **Interacts with:** FW-10
- **Phase:** on demand — only when a real site needs a grouped relationship field

#### FW-27 — PHPUnit harness, starting with the snapshot label helpers

The repo's gates are plain-PHP `tests/verify-*.php` scripts run on bare host PHP, deliberately carrying no dev dependency. `WireframeBootstrap`'s `snapshot_*_labels` methods are the best first PHPUnit candidates in the codebase: static, pure `array → array`, hooked on `wp-wireframe/save/payload`, and load-bearing — they are what makes a collapsed repeater row readable instead of "Row 1".

- **Detail home:** none. Carved out of #53 §7, from the PR #19 review; the issue that framed it was #68.
- **Progress:** Not started. Partial coverage exists today from `verify-config-helpers.php` and `verify-propagation-labels.php`, but both assert on source strings and mappings rather than exercising the transform over real payload shapes.
- **Open:**
  - **The gap that matters** is the malformed or partially-populated payload. A condition-hidden subfield is *dropped* from the save payload server-side, so an absent subfield is the normal case, not an edge case — happy-path assertions do not catch a helper that fatals or silently blanks a title when one is missing.
  - **Three decisions before any code:** whether PHPUnit runs in CI (there is no test workflow today — only `claude.yml`, `claude-code-review.yml`, `release.yml`) or stays a local gate like H1–H14; whether WP calls are stubbed or a WP test bootstrap is pulled in (the former keeps the suite runnable on bare host PHP like the existing harnesses, the latter is a far heavier dependency); and whether the plain-PHP harnesses stay — they should, since several are *source-inspection* checks that PHPUnit fits poorly.
  - **Scope when it lands:** `require-dev` on PHPUnit plus a `/tests export-ignore` check so nothing new reaches the ZIP. Note the helpers are no longer uniform — four of the five now read the unified repeater's rows, while `snapshot_claim_override_labels` still reads the General tab's per-taxonomy default; fixtures must reflect that split rather than assume one shape.
- **Blocked by:** — • **Interacts with:** FW-20

#### FW-29 — `trigger_term_id`'s `int[]` invariant is declared but not enforced

`OptionRuleStorage::normalize_rule_shape()` declares `related_rules.trigger_term_id` to be `int[]`, but nothing guarantees it at the boundary, so every consumer re-coerces defensively — `(array)`, `(int)`, `is_wp_error` guard. Forgetting one is silent: the term lookup fails and the rule quietly misbehaves, which is the class of mistake that produced B1.

- **Detail home:** none. The issue that framed it was #20.
- **Progress:** Mostly closed by attrition rather than by decision. #61 rewrote `RelatedHandler` into a pure applier and deleted `should_trigger_related_terms` / `apply_related_terms` / `process_acf_related_terms`, and the integration call sites are gone; of the original ~8 re-coercion sites, **3 remain**, all in `class-related-handler.php` (`trigger_resolvable`, `get_trigger_terms`, `validate_rule_internal`).
- **Open:** the three survivors ask genuinely different questions — *any* id resolves, which resolved ids are on the post, *every* id resolves — so they are not one extracted helper's worth of duplication any more. What is actually left is the decision the issue's point 3 named and #61 never settled: **is `normalize_rule_shape()` the guaranteed `int[]` boundary or not?** If yes, the casts come out and the guarantee gets stated where the shape is declared; if no, that is worth one comment saying why a consumer must still re-coerce. Doing neither is what leaves the invariant declared and unenforced.
- **Blocked by:** — • **Interacts with:** —

---

## UX polish

#### FW-20 — Standardized repeater row-title schema

Each rule type builds its collapsed-row title independently — a per-handler `snapshot_*_labels` callback in `WireframeBootstrap` assembling an ad-hoc string. They share primitives (`term_label`, `taxonomy_label`, `disabled_prefix`, post-type scope, en-dash windows) but no common grammar, so each is revised individually and drifts.

- **Detail home:** none. Raised during the Phase 3 handler migration, 2026-07-01.
- **Progress:** Not started. Titles stay per-handler and are revised one at a time.
- **Open:**
  - **The schema itself** — extract a shared title-builder with a small declarative schema (tokens, separators, conditional clauses) that each config declares, so the row title is data rather than a bespoke callback per handler, folding the existing `snapshot_*_labels` into it.
  - **A live enabled/disabled indicator in the collapsed header.** A disabled rule looks identical to an enabled one until expanded. 0.5.0 shipped a stopgap — a baked `[Disabled] ` prefix prepended to the leading title token at save (`WireframeBootstrap::disabled_prefix`) — which is accurate for *persisted* state but is a snapshot, not live, and covers only the two rule types whose labels were reworked. Stock Wireframe blocks the clean version from every side: `title_template` is raw token substitution with no client-side conditional (a live `{enabled}` token renders `true`/`false`), the repeater row header is hardcoded in the React bundle with a fixed `row-actions` set and no slot to inject a status pill or toggle, and a CSS-only dim is a dead end because a collapsed row does not render its body, so the `enabled` input is not in the DOM to drive a `:has()` rule. The real fix is a header-action slot from the **FW-23 Gap A** fork; until then the cheap consistency win is extending the baked prefix to the remaining rule types' title snapshots. Drop the prefix when the slot lands.
- **Blocked by:** `code:per-handler titles have not stabilized through use` — doing it before they settle means designing the grammar against a moving target. The live indicator additionally needs `row:FW-23` (Gap A); the baked-prefix extension needs neither • **Interacts with:** FW-21, FW-23

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

⚠️ **A condition-hidden subfield DROPS from the save payload.** Verify each show/hide on the test site and check that the storage adapter tolerates the absent key — subfield conditions evaluate against sibling subfields in the same row. This is the silent-data-loss trap in CLAUDE.md don't 3, which H11 exists to catch.

#### FW-23 — Client-side custom field types

Wireframe has no JS-side field-type *extension* API: a custom type declared via the `wp-wireframe/field_types` filter registers server-side sanitize/validate but renders as nothing in React.

- **Detail home:** `.scratch/plans/wireframe-js-field-type-extension-blocker.md` (full plan).
- **Progress:** **Partially unblocked in 1.0.6.** Its `action` field is a built-in escape hatch for the button case — a real React button that posts in-flight form values to a server hook and returns `{status, message, html}` — so the button case no longer needs the extension API. Page-level Title/Slug Preview and bulk Apply are implementable now via `action` on the FW-16 migration page. Upstream PR direction as of 2026-06-11 is *extending* the action mechanism, not adding the extension API, which is exactly why the fork path matters.
- **Open:** two gaps.
  - **Gap A — no JS field-type extension API (the root)** — **buildable by us, fork-releasable.** Three read sites all do `customEditComponents[type]` (`SettingsSection.js`, `mapConfig.js`, `RepeaterEdit.js`); the PR adds a registry plus a `registerFieldType()` global mirroring the existing PHP `field_types` filter. Additive, low risk. The **fork-release path** — fork Wireframe, build, tag, repoint our composer VCS dep, vendor the built fork — ships it without waiting on the upstream maintainer to merge.
  - **Gap B — the `action` field has no repeater-row context** — **file upstream, do not build.** `ActionButton` posts page-level `useSettings()` values and routes by `fieldId` only (`action/{pageId}/{fieldId}/{actionId}`, no row index), so a button in row N cannot tell the handler which row fired. Changing the payload or route is a JS+PHP data-contract change the maintainer owns, and it collides with in-flight action PRs. **We do not need it**: inline Apply-to-Existing is covered by FW-16 *by design*.
- **Blocked by:** — • **Interacts with:** FW-16, FW-26

#### FW-24 — Claim option propagation

Per-rule claim overrides (stored `conflict_handling`) default to `merge` regardless of the General-tab per-taxonomy default, but authors reasonably expect rule-level to inherit from taxonomy-level unless explicitly set. Sharper now that the two surfaces share wording — both read "Claim on terms" / "Default claim on terms", so one appears to feed the other.

- **Detail home:** none. Claim semantics: [ADR 0004](adr/0004-claim-axis-and-jurisdiction.md).
- **Progress:** Not started. Small enough to take ad-hoc.
- **Open:** the handler reads the General-tab default for `$taxonomy` when the rule's own value is empty. Wants an explicit "inherit" placeholder option rather than a silent fallback, so the config shows which default is in force.
- **Blocked by:** — • **Interacts with:** FW-25

#### FW-25 — Authorable claim on every rule type

Only `propagation` lets the author choose a claim. `time_based` and `related` are hardcoded **owning** (they remove their target term when the trigger stops holding), `hierarchical` is contributing, `level_restriction` restricting, `title_slug` owning, and `related_post_terms` is owning-or-contributing under the name `keep_in_sync`. This item is about letting the author *change* it.

- **Axes:** no change to basis or effect target — this is the **claim** axis becoming author-set where it is currently hardcoded.
- **Detail home:** [ADR 0004](adr/0004-claim-axis-and-jurisdiction.md) for the law it must obey. The concrete, no-behavior-change half — *stating* each type's claim in its config — is FW-28.
- **Progress:** Not started. `ConfigHelpers::claim_field()` already exists and is id-agnostic, so adding the control is cheap. The ordered rule list shipped in 0.8.0, so the configs are already one repeater with `conditions`-gated subfields — the claim field would be gated on rule `type`, and the "building it twice" concern that deferred this is now resolved.
- **Open:** ⚠️ **constrained by ADR 0004's law** — *owning requires a statically enumerable jurisdiction*. `time_based` and `related` qualify (one configured target term each), so owning↔contributing is a genuine choice for them. `hierarchical` does **not**: its derivable set is data-dependent, which is why it already buys the forbidden cell with `_bws_auto_terms` provenance meta — offering it *owning* would need that meta generalized or a silent widening to the whole taxonomy. `level_restriction` is restricting by construction with no meaningful alternative. So this is **not one uniform dropdown**; it is a per-rule-type legality question, and that is the real work.
- **Blocked by:** — • **Interacts with:** FW-3, FW-8, FW-12, FW-24

#### FW-26 — Taxonomy-first cascading term picker

The trigger-term and target-term dropdowns list all terms across all taxonomies. Selecting a taxonomy first, then showing only its terms, would sharply reduce noise on sites with many taxonomies and many terms.

- **Detail home:** `.scratch/plans/wireframe-js-field-type-extension-blocker.md` — this is the one named, wanted use case that justifies doing the Gap A PR.
- **Progress:** Not started; deferred from Phase 3b. Static boot-time enumeration holds until then. **The design question is settled**: a custom Edit component receives full row context — `RepeaterEdit` passes `data={row}` to each subfield — so a `taxonomy_term_picker` field type reads its sibling taxonomy straight from `data` and fetches its term list via REST. **No sibling-update API is needed**; the earlier "must update a sibling select → blocked" framing was wrong, because one component owns both the taxonomy choice and the dependent term list. The `action`-field round-trip idea is a dead end — an action cannot update a sibling. Roughly a one-day build once unblocked.
- **Blocked by:** `row:FW-23` — specifically Gap A landing in our vendored Wireframe, by our own fork or an upstream release • **Interacts with:** FW-23

#### FW-28 — State each rule type's fixed claim in its config

[ADR 0004](adr/0004-claim-axis-and-jurisdiction.md) named the **claim** axis (*owning* / *contributing* / *deferring* / *restricting*) and made it author-visible — but only on `propagation`, the one rule type that already had a control for it. Every other type's claim is fixed in code and stated nowhere in its config. This item is purely *saying what the code already does*, in the vocabulary already on screen elsewhere: no new control, no storage change, no behavior change. FW-25 is the separate, much larger question of letting the author *change* it.

- **Detail home:** [ADR 0004](adr/0004-claim-axis-and-jurisdiction.md) for the vocabulary. The issue that framed it was #54, which carries the per-type evidence table (file and line for each type's apply and remove sites).
- **Progress:** Not started. Deliberately sequenced *after* the ordered rule list, which reshaped every config — a wording pass written before it would have been redone. That list shipped in 0.8.0, so this is now unblocked.
- **Open:**
  - **The wording, per type.** The two hardcoded **owning** types are the ones that most need it, because owning is the claim that *removes* — e.g. on a time-based rule: "This rule **owns** the target term: it adds the term inside the window and removes it outside, whether this rule placed it or someone added it by hand. Terms other than the target are never touched." `hierarchical` deserves a second sentence, because its jurisdiction is **provenance-derived** (`_bws_auto_terms`) and it is the only type where that is true — it means hand-added terms are safe there in a way they are not under `time_based`.
  - **One naming overlap to settle with it:** `related_post_terms` already exposes this axis under a different name (`keep_in_sync`), so stating the claim there without reconciling the two names adds a second vocabulary rather than removing one.
- **Blocked by:** — • **Interacts with:** FW-24, FW-25

---

## Closed / retired

Shipped or cut items retire here, densely — a closed item is read in bulk and never worked, so the table beats a heading block. **Ids are never reused.**

| Id | Item | Outcome |
|---|---|---|
| — | — | Nothing retired yet; this ledger starts at the 2026-09-11 migration from `docs/future-features.md`. |

---

## Maintenance

**Where this list comes from.** [ROADMAP.md](../ROADMAP.md) Phase 6+ assignments; Phase 2c session planning (the strategic roadmap it produced now lives in ROADMAP.md, its working plan file is gone); deleted standalone plugins under `plugins-to-integrate/`, captured at deletion time so the intent was not lost; session discussion that did not fit a phase; and — since 2026-09-11 — the non-bug GitHub issues, which moved here wholesale when the split in [docs/agents/issue-tracker.md](agents/issue-tracker.md) took effect. An issue that names an item is cited as the thing that *framed* it, not as a live home: it is closed, and this file is the home now.

**When an item becomes work in flight**, point its `Detail home:` at the spec and put the real build state in `Progress:` — do not move it to a separate section, and do not write a percentage.

**When an item ships**, move the row to [Closed / retired](#closed--retired), leave the id retired, and let [CHANGELOG.md](../CHANGELOG.md) carry the behavior delta. If it had a spec, the spec lifts into [design-history/](design-history/) at merge — see that directory's README for the contracts, in particular that an **unbuilt** plan is never lifted.

**Private paths are allowed here and nowhere else.** A `Detail home:` naming `.scratch/plans/*.md` is written as inline code rather than a link, because the path resolves for one person and a link would render as broken. Every other committed file cites an `FW-N` id instead.
