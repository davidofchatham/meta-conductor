# FW-62: Cross-post tokens for pattern rules

> **Design history lifted 2026-11-13 from the private spec file. Never corrected.** This is the build contract and settled-decision record for the `{parent:…}` and `{refs:…}` cross-post tokens and the format pass's parent fan-out. It is **not** documentation of shipped code: see [architecture.md](../architecture.md) → *Token engine* and invariant 18, [readme.txt](../../readme.txt) → *Cross-post tokens*, and [CHANGELOG.md](../../CHANGELOG.md) for the behavior delta.

Build notes, recorded at lift: the fan-out runs once per post after the format pass rather than after each rule (same set of children); the new H17 cases are hand-written contract cases in `tests/verify-token-engine.php`, not golden-JSON rows, because the golden file is a regenerated capture; `refs` skips a trashed related post (see Out of Scope); a non-scalar value in a `refs` source falls through to the next related post.

Status: built in [#90](https://github.com/davidofchatham/meta-conductor/pull/90), unreleased.

Design settled in the 2026-11-13 grilling session; the `FW-62` row in docs/future-work.md carries the same decisions. Follow-ups cut from this spec: FW-67 (source status gate), FW-68 (`refs` fan-out and reverse direction), FW-69 (cross-post tokens in repeater-row patterns).

## Problem Statement

A site author writes a field-from-pattern or title/slug rule and wants the text to include something that lives on a *different* post. A session's title should carry its venue's address. A child page's field should be built from its parent's season label. Today a pattern can read only the post it is saving, plus the parent's slug (`{parent:slug}`). To get anything else the author writes site PHP, or copies the value onto every child by hand and keeps it in sync themselves. When the parent changes, nothing tells the children, so titles and slugs built from it go stale with no sign.

## Solution

Two new token prefixes let a pattern read another post's value through a Relationship.

- `{parent:<inner>}` reads the post's parent post, the natural relationship.
- `{refs:<field>:<inner>}` reads the first post named by an ACF Relationship or Post Object field on the receiving post, the authored relationship.

`<inner>` is any token that already works on the post's own pass (a meta field, a term, the first of several terms, a publish or modified date part) plus a new bare `{slug}` that exists only behind a prefix. So `{parent:meta:address}`, `{parent:term:season}`, and `{refs:venue:meta:address}` all work, and `{parent:slug}` keeps working through the same path.

Freshness differs by relationship. When a parent changes, its children's title/slug and field-from-pattern rules re-render on their own, because the format pass now declares the parent's children as dependents and the existing queue drains them. A `refs` source is pull-only: the receiving post recomputes on its own save or when the author runs Apply to Existing Posts, and the field's help text says a source edit does not reach it. Nothing is written to the far post by the rule that reads it.

## User Stories

1. As a site author, I want to write `{parent:meta:address}` in a field-from-pattern rule, so that a child post's field is built from its parent's value without site PHP.
2. As a site author, I want to write `{parent:meta:address}` in a title pattern, so that each session's title carries its parent event's address.
3. As a site author, I want `{parent:term:<taxonomy>}` and `{parent:terms:<taxonomy>}`, so that a child's pattern can name the parent's term or terms.
4. As a site author, I want `{parent:pub_year}` and `{parent:mod_year}` (and the other date parts), so that a child can compose from the parent's dates.
5. As a site author, I want `{refs:venue:meta:address}` to read from the post an ACF Relationship or Post Object field points at, so that non-hierarchical content (a venue, a team) can feed a title or field.
6. As a site author, I want `{refs:<field>:…}` to take the first related post that holds a value, in the field's own order, so that a multi-value Relationship field gives one predictable answer.
7. As a site author, I want a missing source (no parent, an empty field, a related post that was deleted) to resolve empty through the engine's usual gap handling, so that a title never shows a stray separator or a literal token.
8. As a site author, I want a value that is not a scalar (an array, a repeater, an image) to resolve empty, so that a pattern never prints `Array`.
9. As a site author, I want the existing `{parent:slug}` to keep producing exactly what it does today, so that no live slug ladder or flat-URL rule changes.
10. As a site author, I want the new `{slug}` token to be meaningful only behind a prefix, so that a pattern naming its own slug cannot feed back on itself.
11. As a site author, I want a nested prefix such as `{parent:parent:meta:x}` to resolve empty rather than walk the chain, so that I never get a surprising grandparent value or a runaway lookup.
12. As a site author, I want a child's title or slug to update when I edit the parent's field, so that I do not have to re-save every child.
13. As a site author, I want that update to reach grandchildren whose own patterns read their parent, so that a deep hierarchy stays consistent.
14. As a site author, I want the child to read the parent's *stored* value after the parent's own rules ran, so that a parent field computed by another rule is what the child sees.
15. As a site author, I want a parent's edit to cost nothing on a site that has no rule using `{parent:…}`, so that adding the feature does not slow every save.
16. As a site author, I want only the children whose post type a `{parent:…}` rule covers to be queued, so that unrelated children are not re-evaluated.
17. As a site author, I want the help text for `refs` tokens to say a source edit does not reach receivers, so that I know to run Apply to Existing Posts after changing a venue.
18. As a site author, I want Apply to Existing Posts to recompute posts that use these tokens, so that I can backfill after adding a rule.
19. As a site author, I want a bulk run over a parent and its children to converge whatever order the posts are processed in, so that I do not have to run it twice for parent tokens.
20. As a site author, I want to know that a `refs` bulk run in the wrong order can leave a receiver stale until a second run, so that I can plan for it.
21. As a site author, I want `{parent:…}` and `{refs:…}` in a repeater-subfield pattern to resolve empty rather than error, so that a rule I author there fails quietly and the limit is documented.
22. As a site author, I want the rule's own output field never to feed its own pattern, so that a pattern naming its target cannot compound across passes.
23. As a site author, I want help text I can read in the rule editor without literal `{slug}` placeholders being swallowed, so that the token documentation survives the Wireframe description interpolation.
24. As a site author, I want a private or draft parent's value to be read the same as a published one in v1, so that behavior matches `{parent:slug}` today, with the leak risk stated in the help text.
25. As a developer extending the plugin, I want a token source to only fetch and the engine to own all token parsing, so that a new source (the related post) is one small read on the interface.
26. As a developer extending the plugin, I want the related-post read to be one method on the token-source interface, so that fake sources in static tests need one stub.
27. As a developer, I want the format pass's `fan_out()` to declare far entities and never write them, so that the dispatcher stays the single writer.
28. As a developer building FW-5 (field copy), I want to reuse the format-pass `fan_out()` this work adds, so that I only widen it to the relationships that rule declares.
29. As a developer, I want the amended invariant 18 and the H13 static check to state and pin the new hook, so that a later change cannot quietly add a trigger or a write to it.
30. As a developer, I want golden rows in the token-engine table for the prefixes, one-hop rejection, empty sources and `{slug}`, so that a parser change shows up as a diff.
31. As a developer, I want no new collision check and no cycle guard, so that the change stays small: the hierarchy is acyclic, `refs` never fans out, and a token that reads a field creates no write collision.
32. As a site owner upgrading, I want existing title/slug and field-from-pattern rules to behave exactly as before, so that nothing on a live rule type changes unless I add a new token.
33. As a maintainer, I want the follow-ups (status gate, `refs` fan-out and reverse direction, repeater-row sources) to be separate tracker rows, so that this spec stays shippable.

## Implementation Decisions

- **Token grammar.** The engine splits a token on its first colon today. `parent` and `refs` become relation prefixes. For `parent` the remainder is the inner token. For `refs` the second segment is the ACF field name (positional, so a field named like a token kind is not ambiguous) and the remainder after it is the inner token. The existing hard-coded `parent:slug` case is removed and served by the generic path with the new bare `{slug}` as inner. The name `refs` matches the token of that name in the author's dynamic-tags plugin.
- **Inner tokens.** Everything the engine resolves unprefixed: `meta:`, `term:`, `terms:`, `pub_*`, `mod_*`, plus bare `{slug}` (the related post's `post_name`). A bare `{slug}` with no prefix resolves empty on the post's own pass, so it cannot self-reference. Non-scalars drop to empty through the existing scalar check.
- **One hop.** A prefix inside an inner token (a chain) resolves empty. No depth counter, no cycle risk.
- **Missing source.** No parent, an empty field, a deleted related post, or a related post with no value for the inner token all resolve empty and flow through the engine's normal gap and separator cleanup.
- **Engine and sources.** The engine owns all parsing and delegation; sources only fetch. The token-source interface gains one read that returns a source for the related post, taking the relation kind and, for `refs`, the field. The post source implements it: `parent` follows the post's parent; `refs` reads the named ACF field's post IDs in field order and returns a source for the first related post whose inner value is non-empty. Resolution goes through the existing relationship module (the parent and ACF relationship implementations), so a token and a term rule agree on what "related" means. The repeater-row source returns nothing, so both prefixes resolve empty there (FW-69).
- **Stored values only.** The related post's source reads meta and terms from the database, with no `$data` overlay from a pass in flight. Cross-post ordering is guaranteed by the queue draining the source before its fanned-out dependents.
- **Format-pass `fan_out()` (parent only).** After each format rule, the dispatcher asks the rule's handler for dependents to declare, as the term pass does. It acts only when at least one enabled rule uses a `{parent:…}` token, found by scanning patterns for token names, memoized per request. It queues the post's children through the parent relationship's dependents lookup, filtered to the post types those rules cover. The existing queue recurses, so grandchildren follow through their own pass. It declares and never writes. Triggers are unchanged: any save that already marks the parent dirty. A bare meta write with no post save fires nothing, as for every other rule.
- **`refs` is pull-only.** No fan-out. The receiver recomputes on its own save or on Apply to Existing Posts. The help text states that a source edit does not reach receivers and that a bulk run can leave one stale until a second run.
- **Apply to Existing Posts.** Unchanged mechanism: it names posts through the dispatcher's drain and never calls an applier. A child processed before its parent converges because the parent's pass fans back out to it.
- **Architecture invariant 18** is amended: the format dispatcher still owns no trigger and no queue of its own, but its pass now has a `fan_out()` that declares far entities for the shared queue. FW-5 reuses and widens the hook.
- **No new guards.** The hierarchy is acyclic, `refs` has no fan-out, and a token that reads a field adds no write collision, so there is no cycle guard and the collision detector is unchanged.
- **No schema change.** Rules store the pattern text as today; the new tokens live inside it. No migration, no new stored key, no persisted-kind-list change.
- **Admin help text.** Pattern help documents the two prefixes, the one-hop limit, the `refs` staleness, the repeater-row limit and the no-status-gate behavior. It keeps bare `{slug}` and any bare `{word}` out of Wireframe `description` text, because that text interpolates against the row; colon tokens are safe.
- **Docs.** `CHANGELOG.md` records the new tokens and the parent fan-out. `readme.txt` documents the tokens. architecture.md's invariant 18 text and the token-engine section are updated.

## Testing Decisions

A good test checks external behavior: what a pattern renders, which posts end up re-rendered, and what the dispatcher writes or does not write. It does not assert on private helpers or call order.

Three existing seams, no new ones:

1. **Testbed sweep through the sweep library (the highest seam).** Author the rules with the library's rule-writing helpers, seed a parent and children (and a venue-style source for `refs`), save the parent with a changed field, drain, and assert the children's title, slug and fields. Covers: parent tokens end to end, grandchild recursion, no cost with no `{parent:…}` rule, post-type filtering, `refs` staying stale until the receiver's own save, Apply to Existing convergence in both processing orders, the empty-source cases, and the repeater-row empty case. Assertions go through `drain()` / `run_pass()`, since the pass is not synchronous with the write. Restore with the library's restore step and check post names afterward. Prior art: the existing title/slug and field-from-pattern sweeps.
2. **H17 token-engine check (no WP).** Extend its fake token source with the one new read and add golden rows for: each prefix with each inner kind, `refs` field positional parsing, one-hop rejection, missing source, non-scalar, bare `{slug}` behind a prefix and on its own, and `parent:slug` unchanged on the generic path. Rows are added deliberately in the golden table.
3. **H13 term-dispatcher check.** Pin the amended invariant 18: the format pass has a `fan_out()`, it acts only when a rule uses a `{parent:…}` token, it queues through the shared queue, and it never calls a write primitive. Prior art: the existing dispatcher assertions for the term pass.

Also run the full static-gate loop (all `tests/*.php`) before any commit, plus H1 lint and H2 autoload if a new class file is added.

## Out of Scope

- A source status gate (draft, pending, private, trashed): FW-67. v1 reads the source whatever its status, as `{parent:slug}` does, with one exception: `refs` skips a trashed related post (ticket 02 acceptance; kept by decision 2026-11-13). `{parent:…}` has no trash skip.
- Fan-out for `refs` and the reverse direction (the source holds the field): FW-68, which needs FW-11's reverse lookup.
- Cross-post tokens in repeater-row patterns: FW-69.
- Chains of relations (grandparent, nested `refs`).
- Joining several `refs` sources; v1 takes the first non-empty.
- Raw (non-scalar) copies across a relationship: FW-5.
- Value filters and conditional tokens: FW-49.
- Any change to existing rules' stored shape.

## Further Notes

- FW-5 depends on this: it extends the format-pass `fan_out()` built here, and its blocker is now `row:FW-62`.
- `refs` takes the ACF field's name only in v1 (decided 2026-11-13). A token carries no row, so it has no stored field key, and two groups that share a bare name can make the name ambiguous. A field inside an ACF Group must be written by its group-qualified name. The help text says to use a unique field name. Accepting a `field_` key as the second segment is a later, non-breaking addition if anyone hits a collision.
- The help text staleness wording matters more than the code for `refs`; the build should treat it as an acceptance item.
