# FW-61 — Set terms from a related post: merge propagation and Related Post Terms

> **Design history lifted 2026-11-13 from the private spec file. Never corrected.** This is the build contract and the settled-decision record for merging the parent-propagation rule and the ACF related-post-terms rule into one `terms_from_related_post` rule. It is **not** documentation of shipped code: see [architecture.md](../architecture.md) → *The relationship module*, [CONTEXT.md](../../CONTEXT.md) → *Relationship*, and [CHANGELOG.md](../../CHANGELOG.md) for the behavior delta.

Status: built; releases together with FW-14 and FW-63, with no tag between them.

Source: the FW-61 row of [future-work.md](../future-work.md) (design settled 2026-11-11) and a second grilling session, 2026-11-12, which settled the points the row left open.

## Problem Statement

Two live term rule types do the same job and differ only in where the source post comes from. *Propagation* ("From parent post — cascade terms to children") has a post take terms from its `post_parent`. *Related Post Terms* ("From referenced post (ACF) — copy terms across a relationship field") has a post take terms across an ACF relationship or post-object field.

The author sees two rule types to learn, two sets of controls, and two vocabularies for the same idea of what to do about terms already present: propagation says owning / contributing / deferring, Related Post Terms says "keep in sync" on or off. The two also disagree on details the author cannot see: whether ACF taxonomy mirror fields are read and written, whether a status limit applies to the written post or to the source, and whether a term the source lost is withdrawn.

The developer sees the same split in code. The relationship graph (holder, direction, dependents, sources, reverse-lookup tiers, the sever capture) is buried in one 1,000-line handler, and the parent logic is duplicated in another. FW-5 (copy a field across a relationship) and FW-62 (cross-post tokens) both need that graph and cannot reuse it where it sits.

## Solution

One rule type, **Set terms from a related post** (id `terms_from_related_post`), with a *Relationship* option: **parent** or **ACF field**. Everything else is shared: taxonomy, post types, post status, and a single *Claim on terms* control.

The relationship graph moves out of the handler into a small relationship module with a parent implementation and an ACF implementation. The merged handler asks the module for sources and dependents and does the claim and write work once. FW-5 and FW-62 build on the same module later.

Existing rows are rewritten once, in place, by an upgrade step. Authors see the same rules, with the new label, the new claim control and, on ACF rows, one deliberate behavior change: ACF taxonomy mirror fields are now read and written, as they already are for parent rows.

## User Stories

1. As a site author, I want one rule type for "this post takes terms from a related post", so that I learn one set of controls instead of two.
2. As a site author, I want a *Relationship* choice of Parent post or ACF relationship field, so that I pick where the source post comes from without picking a different rule type.
3. As a site author on a site without ACF, I want the Parent post relationship to work exactly as propagation does today, so that I need nothing installed to use it.
4. As a site author on a site with ACF off, I want a stored ACF-relationship rule to survive, run nothing and show a "requires ACF" notice, so that deactivating ACF does not destroy my configuration.
5. As a site author, I want the ACF relationship option to stay visible but labeled unavailable when ACF is off, so that saving the form does not drop my stored choice.
6. As a site author, I want one *Claim on terms* control (Owning, Contributing, Deferring) on both relationships, so that I state what the rule does about terms already present in one vocabulary.
7. As a site author with an existing "keep in sync" ACF rule, I want it to become Owning and my add-only rule to become Contributing, so that nothing changes behavior at upgrade.
8. As a site author using an ACF relationship, I want Deferring to be available, so that I can fill a post only when it holds no terms yet.
9. As a site author, I want the Contributing help text to say that on a parent relationship it also withdraws a term the parent just lost, so that I am not surprised by it.
10. As a site author, I want Owning to be the way to get removal on an ACF relationship, so that I can choose to keep a dependent exactly in step with its sources.
11. As a site author with a field mirroring a taxonomy, I want terms copied across an ACF relationship to reach that mirror field as well as the native terms, so that a related post looks the same whichever store a template reads.
12. As a site author, I want the mirror behavior to be identical for the parent and ACF relationships, so that there is one rule to remember.
13. As a site author, I want *Post types* available on an ACF-relationship rule as an optional narrowing of the receiving post, so that one meaning of "post types" holds for both relationships.
14. As a site author with an existing ACF row, I want an empty *Post types* to mean no extra narrowing, so that the row keeps acting on the posts it acts on today.
15. As a site author, I want the status limit on a parent rule to apply to the written post and its parent, as it does today, so that a publish-only rule still skips draft children.
16. As a site author, I want the status limit on an ACF rule to apply to the source post only, as it does today, so that a draft dependent of a published source still syncs.
17. As a site author, I want the settings that belong to the other relationship to be hidden, so that I am not offered inert controls.
18. As a site author who flips a row from ACF to Parent, I want the ACF-only settings to be cleared, so that the row carries nothing it does not use, the same as changing the rule type does.
19. As a site author, I want a row title that tells me the relationship, taxonomy, direction and claim, so that I can read the collapsed list.
20. As a site author, I want a parent row's title unchanged, so that a familiar list still reads the same.
21. As a site author, I want an ACF row's title to carry the claim and no "Sync" verb, so that the title matches the control that replaced "keep in sync".
22. As a site author, I want the rule's place in the ordered list kept, so that the order I authored still decides how rules compose.
23. As a site author with a propagation rule and an ACF rule on the same taxonomy, I want them to stay two rows in their authored positions, so that merging the type does not merge my rules.
24. As a site author, I want the collision warnings to still flag two rules writing the same terms over overlapping post types, so that merging the type does not hide a clash.
25. As a site author, I want Apply to Existing Posts to offer the merged rule and run it through the same bulk path, so that I can backfill it as I can any other.
26. As a site author reparenting a post, I want the behavior to be exactly what propagation does today, so that no destructive write appears on an unchanged rule.
27. As a site author, I want a deleted post or a changed relationship field on an ACF rule to still withdraw the gone source's terms from its dependents under Owning, so that the existing sever behavior is kept.
28. As a site author, I want existing propagation and Related Post Terms rows migrated automatically on upgrade, so that I do not re-enter anything.
29. As a site author, I want the migration to leave rows of other types, row order and every other stored key alone, so that it cannot damage what it does not own.
30. As a site author, I want the ACF field reference to survive the migration whole, so that the field picker still shows my field and the next save does not blank it.
31. As the maintainer, I want the migration to be idempotent and gated by its own flag, so that running it twice is harmless and a development build that has not bumped the version still migrates.
32. As the maintainer, I want the migration to run after the rule-type id migration, so that a site coming from any earlier state ends fully migrated.
33. As the maintainer, I want the migration deleted one minor release after it ships, so that the one-time code does not accumulate.
34. As the maintainer, I want no read-time fallback for the old type strings, so that storage keeps the one stored shape.
35. As the maintainer, I want the testbed behavior sweeps to pass before and after every ticket with identical expectations, so that the refactor is shown to be behavior-neutral.
36. As the maintainer, I want the mirror and claim-mapping changes covered by their own sweep, so that the two deliberate changes are proven apart from the refactor.
37. As the maintainer, I want the real-data clone swapped to the working copy and checked before release, so that an actual stored configuration is shown to migrate and keep working.
38. As the maintainer, I want FW-14, FW-63 and FW-61 to release together with no tag between them, so that no released version carries labels on old ids or a half-merged registry.
39. As the author of FW-5, I want the relationship graph and sever capture in a module I can call, so that a field copy reuses holder, direction and reverse lookup instead of copying them.
40. As the author of FW-62, I want to resolve a related post through the same module, so that a cross-post token and a term rule agree on what "related" means.
41. As a developer, I want the relationship contract to be neutral about the entity kind, so that a term-parent or post-to-term relationship can be added without reshaping it.
42. As a developer, I want only the post implementations built now, so that no abstraction is written around relationships that cannot run yet.
43. As a developer, I want the dispatcher to stay the only caller of an applier, so that the invariant that keeps passes ordered is untouched.
44. As a developer, I want the capture hooks to remain an allow-list on the descriptor, so that the dispatcher gate keeps failing on any hook not declared there.
45. As a developer, I want the claim logic to keep using the one shared end-state computation, so that merge, replace and skip are encoded in a single place.
46. As a developer reading the docs, I want the glossary to define *Relationship* and its kinds, so that "parent", "ACF field" and the draft "assignment" mean one thing each.
47. As a developer, I want the hierarchy and trigger-term rule types left on their own traversal, so that this change does not reach rule types that are not changing.
48. As a developer, I want a follow-up row for moving those rule types onto the module, so that the intent is recorded without being built.
49. As a reader of historic changelog entries and design-history documents, I want them left as written, so that they still describe what was true then.
50. As a maintainer, I want the descriptor docblocks that point at FW-61 to lose the pointer, so that stale markers do not outlive the work.

## Implementation Decisions

### The merged type

- **One descriptor and one handler replace two of each.** The id is `terms_from_related_post` (FW-63's scheme: the label minus its verb). The two old descriptors and handlers are deleted, not kept as aliases. The kind is the term kind; the registry's expected order changes deliberately.
- **A `relationship` subfield selects the implementation.** Stored values are `parent` and `acf`. They are deliberately the generic names: `parent` is the same edge whether the entity is a post or, later, a term, and `acf` is the same edge whether the field sits on a post, a term or a user. The rule type's name already says the receiver is a post.
- **The ACF relationship is offered only while ACF is active.** The control keeps both options stored and only labels the unavailable one, since a vanishing option drops the stored value on save. A stored ACF row with ACF off runs nothing and shows a "requires ACF" notice.
- **`post_types` scopes the receiving post, for both relationships.** It is optional on `acf`: empty means no extra narrowing. For `parent` a parent is gated only on being a valid source, not on `post_types`. The written post types the collision detector and the Apply dropdown read come from the descriptor and become row-aware.
- **Everything else the descriptor reads from the shared frame is unchanged:** taxonomy (a list expanded to one scalar row per taxonomy at read), post status, enabled, row title.

### The claim

- **One `conflict_handling` control with the existing shared builder, on both relationships.** `keep_in_sync` is removed. Migration maps `true` to `replace` (Owning) and `false` to `merge` (Contributing). ACF rows gain Deferring (`skip`) as an opt-in value, which the shared end-state computation already encodes.
- **Removal differs by relationship, deliberately, and the help text says so.** On `parent`, Contributing also withdraws a term the parent lost this request, using the existing removal capture on `deleted_term_relationships`. That subtraction is claim-independent today and stays so. On `acf`, Contributing is add-only and never removes; Owning is how an author gets removal. Making the two agree (a per-source lost-terms capture, with the several-source rule that a term lost by one source and held by another must stay) is a follow-up row, not part of this change.
- **The claim logic keeps using the one shared end-state computation.** It is not re-derived. The handler owns the claim, the mirror read and write, and the lost-terms capture; the module owns the graph.

### The ACF taxonomy mirror fields

- **Both relationships read and write the mirror stores.** A source's terms are the native terms union any ACF taxonomy field attached to the source for that taxonomy. The dependent's native terms and each attached ACF taxonomy field are written to the same end state, each computed against its own current value because the two stores can legitimately differ. Field discovery is by location rule, not by saved value, so an attached-but-empty field still receives its first write.
- **This changes live ACF rows** and is the one deliberate behavior change besides the claim mapping. It gets its own CHANGELOG entry under Changed and its own ticket and sweep.

### The relationship module

- **A `Relationship` interface in a new `Relationships` namespace, with a parent implementation and an ACF implementation.** The handler selects the implementation from the row's `relationship` value.
- **The contract is neutral about entity kind and post-only in this change.** It answers two questions over IDs: the sources of a dependent and the dependents of a source, under a rule row. It also answers the eligibility of a source and of a dependent for a row, the written post types for a row, and whether the row's status limit gates the source. The docblock states that each implementation fixes the entity kind of each end; both ends are posts for both implementations now. No kind method, no kind parameter, no term or user code: the dispatcher can only run a pass on a post, and an abstraction written before a second kind exists would be a guess.
- **The ACF implementation owns what is specific to ACF:** holder and direction (push or pull), the reverse-lookup tiers and their request-lifetime memos, the field-to-target-types memo, and the sever capture with its drain channel and rule-keyed record.
- **The parent implementation owns the parent query** (immediate children by status and post type, memoized per request) and has no sever capture. Reparenting a post, to another parent or to root, behaves as it does today: a post with no resolved source is not written, and old terms stay under Contributing.
- **The handler keeps:** the claim, the mirror read and write, the lost-terms capture (parent), the no-change short-circuit, the zero-source destructive-write gate, and the dispatcher entry points. The pull applier, the declared fan-out and the capture-and-drain split keep their current contract. The applier writes only the post it is handed; the fan-out declares and never writes.
- **Status gate per relationship, unchanged from today.** For `parent` the written post and the parent are both gated by the row's status list. For `acf` only the source is gated, and a gated-out source still counts as a resolved source so an owning dependent is emptied rather than skipped as unmanaged. The descriptor's source-gate flag and written-post-types method take the row.
- **The descriptor's capture-hook allow-list is the union** of the two old types' hooks: term removal, the two ACF update filters, and post deletion. The handler registers all of them; each is inert without a matching row. The descriptor still declares that it drains captures.
- **The collision detector and the Apply dropdown read direction and written types from the descriptor**, which delegates to the module. They no longer re-derive holder and direction.

### Config

- **A second gate dimension beside the rule `type`.** The ACF-only subfields (field picker, holder role, reverse field, the source-status note) gate on `relationship = acf`. The hierarchical-post-type note gates on `relationship = parent`. The shared builders that derive a gate from the descriptors learn to take an optional extra value condition; no hand-written type list is introduced.
- **Flipping a row from `acf` to `parent` drops the ACF-only settings on save.** This is the same trade changing `type` already makes and the type description already warns of it. The sweep proves nothing else drops, and the table of expected visibility in the config gate is updated deliberately.
- **The ACF field reference keeps its combined value** (post type, field name, field key) and round-trips whole through the repeater. The two-part legacy parser stays.
- **Row titles.** Parent: unchanged. ACF: copy, taxonomy, direction word, field label, optional status list, claim. The "Sync" verb goes with `keep_in_sync`.

### The migration

- **A separate migration class with its own schema flag**, run from the same upgrade routine, directly after the rule-type id migration. It is not folded into that migration: the testbed and the real-data clone have already run it with its flag set, so a new step under that flag would never run there, and its docblock already says a merge changes row shape and is a separate step.
- **Row-shape rewrite, in place, order preserved:**
  - `propagation_rules` becomes `terms_from_related_post` with `relationship = parent`, keeping `conflict_handling` and every other key.
  - `related_post_terms_rules` becomes `terms_from_related_post` with `relationship = acf`, keeping every other key (the field reference stored whole), with `keep_in_sync` mapped to `conflict_handling` and then removed.
  - Rows of other types, rows already on the new id and unrelated keys are returned unchanged. Both persisted kind lists are walked.
- **No read-time fallback for the old strings.** An unmigrated row reads as an unknown type and is skipped.
- **Deleted one minor release after it ships**, with its harness, its autoload-gate line and the upgrade call.

### Vocabulary and docs

- **CONTEXT.md gets a *Relationship* entry:** the edge a rule traverses, with a source, a dependent and the entity kind of each end. Kinds: *parent* (posts and terms), *ACF field* (posts, terms and users), and *assignment* (a post and the terms it holds), which is marked **draft** and not implemented. Implemented here: parent and ACF field, on posts only.
- **A follow-up FW row** records adopting the module for the hierarchy rule's term ancestry and the trigger-term rule's assignment traversal, and notes that FW-11's reverse index would live behind it. A second follow-up row records unifying removal on ACF Contributing. Neither is built here.
- **Tracked docs updated:** architecture (the relationship module, the invariant text that names the old handlers), CONTEXT, readme.txt upgrade notes, the fixture matrix and fixture README, and the FW rows that cite the old ids. CHANGELOG gets entries under Added/Changed for the merged type, the claim control, the ACF mirror change and the one-time rewrite. The FW-61 row is closed and the finished spec lifts into design history, sanitized, per the project's spec lifecycle.

### Release and process

- **FW-14, FW-63 and FW-61 release together with no tag between them.** FW-14 and FW-63 are merged and unreleased; this follows on main.
- **Branching:** large and multi-commit, so a per-feature branch and a pull request, merged as a merge commit, per the project convention.
- **Suggested ticket order (dependency order, blockers first):**
  1. Extract the relationship module behind the two existing types. Pure refactor, sweeps green before and after, no behavior change.
  2. Merged descriptor, handler, config gating, single claim control, row-aware status and written types, titles, and the config and registry gates. Old types deleted.
  3. The migration and its harness, with a before-and-after sweep and the real-data clone check.
  4. Mirror fields for ACF rows, with its own sweep.
  5. Docs, the glossary entry, the follow-up rows, and the descriptor docblock cleanup.

## Testing Decisions

- **A good test here asserts external behavior:** stored rules in, post terms out; stored rows in, stored rows out; the registry resolving the stored id to the right descriptor and handler. It does not assert class names, file layout or the module's internal shape beyond what the existing gates already pin.
- **Behavior seam: the testbed, through the dispatcher.** The existing sweeps (propagation, ACF reference, the config round-trip, collisions, post status, apply-existing) are re-pointed at the merged type and must produce identical results before and after tickets 1 and 2. One new sweep covers the two deliberate changes: mirror fields on ACF rows (read from the source's mirror store, written to the dependent's, both claims) and the `keep_in_sync` to claim mapping, including Deferring on an ACF row. Sweeps go through the sweep library's snapshot, isolate, sweep, restore flow; post names are checked after any restore, since the format pass can rename a post; sever steps are each their own evaluation because rules are read through a request-lifetime memo; both the native and the ACF mirror stores are set and cleared.
- **One new static seam: the migration.** A host-PHP harness copied from the rule-type id migration's, asserting: propagation maps to `parent`, related post terms to `acf`; `keep_in_sync` true and false map to `replace` and `merge` and the key is removed; the ACF field reference survives whole; `conflict_handling` on a propagation row is kept; other keys, row order, rows of other types and already-migrated rows are untouched; a second run is a no-op; a missing or non-array list is tolerated; both kind lists are walked; an unregistered type is left alone. Deleted with the migration.
- **Existing static gates, edited and not extended:** the autoload gate (new FQNs), the kind-list gate, the term-rules-config gate (its expected visibility table, including the new relationship gate), the term-dispatcher gate (capture-hook allow-list, the dispatcher as sole applier caller), the collision-detector gate, the apply-existing gate, the registry gate (its hand-written expected order), the propagation labels and inherit harnesses. The whole `tests/*.php` loop must pass with no assertion weakened.
- **Real-data check on the cloned production site.** Swap the clone to the working copy with the dev-plugin script, confirm the upgrade rewrites its stored rows, and spot-check that its rules still apply, including the mirror change on any ACF row. Roll back with the same script. No file is patched into the clone by hand.
- **Prior art:** the rule-type id migration and its harness; the slug-escalation migration harness; the fixture sweep library; the handler fixture matrix; the registry gate's hand-written order.

## Out of Scope

- Moving the hierarchy rule's term-ancestry traversal and the trigger-term rule's assignment traversal onto the relationship module (a follow-up row).
- Term-parent, user-field and assignment relationships. Only the post implementations of parent and ACF field are built, and the dispatcher stays post-only.
- Making removal agree across relationships (ACF Contributing subtracting terms a source lost). A follow-up row.
- A parent sever capture, so that reparenting to root clears under Owning. FW-5 adds the field case; terms keep today's behavior.
- FW-5 (field copy), FW-62 (cross-post tokens), FW-11 (reverse-lookup index), FW-1 (ACF post relationship manager), FW-19 (nested relationship fields). They build on the module later.
- Any change to a rule type's behavior other than the two stated: the single claim control and the ACF mirror stores.
- A read-time fallback accepting the old type strings, a bulk "re-save all rules" tool, and any admin UI for the migration.
- Renaming helper classes, historic changelog entries and design-history documents.

## Further Notes

- The two deliberate behavior changes are both on ACF rows: mirror fields now read and written, and Deferring newly available. The claim mapping at upgrade itself is behavior-neutral. Call them out in the CHANGELOG and the readme upgrade notes, and check them on the real-data clone.
- Extraction first (ticket 1) is the safety net: it proves the module boundary without the type merge, so a regression in tickets 2 to 4 can be bisected to a deliberate change.
- Rule rows have no stable id, so the sever record stays keyed by the fields that decide which end feeds which. The key must keep two rows that resolve identically sharing a record harmlessly and two rows that do not resolving apart; with the merged type it also has to include the relationship so a parent row and an ACF row never share one.
- `RuleTypeIdMigration`'s docblock says FW-61 adds its own step beside it; update that note when the new migration lands.
- Terms in this spec follow CONTEXT.md: rule, rule type, basis, effect target, claim (owning, contributing, deferring), reach, pass, collision. *Assignment* is marked draft and is not used in any code or stored value.
