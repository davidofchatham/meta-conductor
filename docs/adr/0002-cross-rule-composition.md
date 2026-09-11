---
status: accepted, partially superseded by ADR 0003, decision 3 amended by ADR 0004
---

# Cross-rule composition: suppress cascade, order explicitly, detect rather than resolve

> **Partially superseded by [ADR 0003](0003-ordered-rule-list-and-dispatcher.md).** The four decisions below stand. Five statements about *how* they land do not, once the vendored Wireframe's constraints and the sketched future rule types were checked:
> - "write-scoped" lock → **pass-scoped**, keyed `(entity, effect-target kind)` — a central dispatcher changed the unit.
> - "seven per-instance `private $processing` flags" → there are **four**, plus one taxonomy-scoped guard on `related_post_terms`.
> - "the collision detector and the ordering UI share one analysis" → no longer true; the repeater *is* the ordering UI, so components are advisory only.
> - "effect *kind* partitions cleanly — title/slug is a terminal sink" → **false in both directions**: title/slug reads `{term:TAX}` and `{meta:field}`, and `user_based` writes terms.
> - "the Phase 4 page split is a real interaction boundary" → **retired**; the boundary is the effect kind, not the page.
>
> **Decision 3 is amended by [ADR 0004](0004-claim-axis-and-jurisdiction.md).** The axis is **Claim**, not *ownership*; it has **four** values, not three — `skip` is *deferring*, not *contributing*, because it writes only into an empty target. ADR 0004 also names **jurisdiction** (the values within one effect target a rule governs) and establishes that *owning* requires a statically enumerable one.
>
> The rejected options recorded here — provenance, fixed type order, runtime resolution, partitioning — remain rejected for the reasons given.

Rules of different types write the same taxonomy on the same post, and until now nothing said what that means. Each handler hooks `set_object_terms` independently with a private re-entry guard, so a handler-initiated write re-enters the whole listener chain on whatever entity it wrote — producing results that depend on hook priority and class-instantiation order, neither of which is stated anywhere or visible to an author.

We settle it as four decisions:

1. **Cascade is suppressed within one effect target.** A rule-initiated write is invisible to peer rules on the *same* effect target, and visible to rules on *other* targets — a term write can still drive a future field or body-class rule, but cannot re-trigger term rules. Suppression is **write-scoped** (held for the duration of the handler's own write), not request-scoped.
2. **Order is the composition semantics, and must be author-visible.** Because cascade is suppressed, the only way two rules combine is by running in sequence within one chain, each reading live state. Order therefore stops being an implementation detail and becomes part of the model.
3. **Ownership is a three-value axis** — *owning* (target equals what the rule derives; removes anything else in its space), *contributing* (present, never removes), *restricting* (applies nothing, polices the full value space in its reach). Provenance remains untracked.
4. **Collisions are detected and warned at settings save, never resolved at runtime.** Two rules collide when their **reach** intersects on one effect target, tested conjunctively: post types *and* taxonomies must both overlap.

This closes three issues as a cluster: **#35** (propagation + hierarchical yields an extra expansion level) is a real bug fixed by (1); **#39** (level-restriction prunes propagated terms) is reclassified — the restricting rule is behaving correctly, and the rule set is a collision, so it is addressed by (4); **#34** (propagation mode switch strands terms) is not a bug — `merge`/`skip` are *contributing*, and contributing rules decline to remove by definition.

## Considered options

- **Shared request-scoped write lock only** (#39's option 2, as filed) — stops peers stomping each other but answers no semantic question, and the "request-scoped" framing is wrong: a request-lifetime lock suppresses the author's own chain the moment the first rule writes, so later rules never run at all. Rejected in favour of write-scoped suppression with a stated cascade rule behind it.
- **Track provenance** — record which rule wrote which term; peers read only author-set values. Fixes #34 and #35 exactly and is order-independent, the only genuinely robust answer. Rejected for the third time: ADR 0001 rejected it for Temporal, `related_post_terms` rejected it (§V3), and adopting it here would make per-rule-per-post tracking meta a plugin-wide primitive with write cost on every application, cleanup on rule deletion, and no retroactive correctness. `HierarchicalHandler`'s `_bws_auto_terms` remains a single-handler exception, not a precedent.
- **A fixed cross-type rule order** — declare one ordering of rule types and guard it with a source-inspection harness. Rejected as insufficient in principle, on two counter-examples. Across types: term-hierarchy `child_to_parent` *adds* ancestors and level-restriction `include_ancestors=false` *strips* them, so expand→restrict and restrict→expand are both legitimate author intents and a single fixed order serves only one. Within a type: two term-pairing rules A→B and B→C give different results by order, and type-level ordering cannot express the relative order of two rules of one type.
- **Resolve collisions at runtime** (pick a winner by priority, recency, or specificity) — rejected: every rule in a collision is individually correct, so any winner is arbitrary, and a silent resolution hides a configuration error the author can actually fix.
- **Partition rules so ordering never matters** — explored and rejected as impossible inside the term group. Taxonomy fails as a partition key because term-pairing is cross-taxonomy by construction (`trigger_taxonomy` and `target_term_id` are independent fields). Post type fails because relation-basis rules write *other* entities. Effect *kind* does partition cleanly — nothing in the term group reads a title, so title/slug is a terminal sink — which is why the Phase 4 page split is a real interaction boundary and not merely a UI grouping.

## Consequences

- **Ordering must be exposed.** Rules in the Auto-Set & Restrict group form one author-ordered list; per-rule position replaces hook priorities. Rule *types* stop being the ordering unit — a type-level order cannot express same-type sequencing.
- **The collision detector and the ordering UI share one analysis.** Build the rule interaction graph using the conjunctive reach predicate and take connected components. A component of size 1 needs no ordering control and produces no warning; only components larger than 1 surface either.
- **Reach is conservative, at post-type granularity.** Which descendants or related posts actually exist is data-dependent and not statically decidable, so the predicate over-reports interaction and never under-reports it. False positives (a warning where no real contention exists) are accepted; false negatives are not.
- **`inheritance_depth: immediate` starts meaning what it says.** Under (1), a propagated child mirrors the parent's already-expanded set — one expansion, applied on the parent — instead of being expanded a second time on arrival.
- **`merge` and `skip` will never reconcile.** Stranding after a parent drops a term, or after a mode switch, is documented contract for a contributing rule. An author who wants reconciliation uses `replace`, which is owning.
- **A latent ordering dependency exists until the ordered list ships.** #35's fix requires term-hierarchy to run before post-hierarchy *on the parent*, and today that falls out of instantiation order in `class-taxonomy-manager.php`. Swapping those two lines would silently reintroduce the bug in a new form. Since all of this lands together in Phase 4, no interim harness is added — but the dependency is real until then.
- **Scalar effect targets are always collisions.** When field, title and body-class effects land, two rules writing one scalar can only be last-writer-wins, so they warn unconditionally. Only set-valued targets compose.
- **Terminology changed.** Temporal's `Mode` (stack/replace) is renamed **Overlap**, freeing the ownership axis from a name collision. ADR 0001's prose predates this and still says "mode"; its substance is unaffected.

## Deferred

- **Rule-type names.** The current names conflate basis, effect and ownership into one string, which is why `hierarchical` (term graph) and `propagation` (post graph) read as near-synonyms, as do `related` (term↔term) and `related_post_terms` (post↔post). Renaming is deferred until the Effect axis has non-term values, so names can be composed from settled axes rather than minted twice.
- **A sub-scope field for restricting rules** (e.g. "governs levels 3–4 only"). Today a level-restriction rule declares no sub-scope, so its reach is its whole taxonomy and it necessarily collides with any rule touching the same one. A sub-scope would let such pairs be made genuinely disjoint rather than merely warned about. Tracked as [FW-12](../future-work.md#fw-12--sub-scope-field-for-restricting-rules).
- **The write-lock's implementation home.** A Phase 4 implementation detail, not a modelling decision.
