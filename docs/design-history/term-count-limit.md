# Spec: Term count limit — split the count cap out of level restriction

> **Design history lifted 2026-11-05 from the private spec file. Never corrected.** This is the build contract and the settled-decision record for splitting the count cap out of level restriction: why *Term count limit* is its own rule type, and why the terms an editor just added survive an over-cap post. It is **not** documentation of shipped code: see [architecture.md → invariant 17](../architecture.md) (the delta capture, and why it is not provenance), the PHPDoc on `Core\TermCountSurvivors` and `TermCountHandler`, and [CHANGELOG.md](../../CHANGELOG.md). The five build tickets were not lifted. What was left out is tracked in [future-work.md](../future-work.md) (FW-12, the sub-scope field; FW-14, renames).

Status: built on `feature/term-count-limit`; ships in the next release.

Vocabulary: [CONTEXT.md](../../CONTEXT.md) — **claim**, **jurisdiction**, **pass**, **cascade**, **effect kind**. Settled in a grilling session; every decision below was an explicit answer unless marked *(derived)*.

## Problem Statement

An author who wants "a post may hold at most one term in this taxonomy" has to know that the only way to say it is the *One term per level* option inside *Level restriction — limit which tree depths may hold terms*. That is hard to find: the capability is named for a depth rule and sits as a radio button under a type whose label describes something else. It is also gated to hierarchical taxonomies by a "this rule only acts on a hierarchical taxonomy" note, although a cap on how many terms a post holds means the same thing on a flat taxonomy.

The behavior itself is also under-specified. When a post is over the cap the survivor is chosen by `end()` of a list that `wp_get_object_terms` returns in name order, so the survivor is effectively the alphabetically-last term at that level. The code comment claims "the last one added", which the plugin cannot know — it stores no provenance (architecture.md invariant 19). An author who tags a post and watches a different term survive has no way to predict it.

## Solution

Count and depth become two rule types.

- **Level restriction** keeps its depth job: *only terms at the deepest level used* and *only terms at the shallowest level used*, plus *Keep ancestor terms*. `One term per level` is removed from it.
- A new **Term count limit** type — "Term count limit — cap how many terms a post may hold" — caps the number of terms a post holds in a taxonomy. It works on flat and hierarchical taxonomies. It has a maximum (default 1) and a *per level* switch (default on) that applies the cap to each tree depth instead of to the taxonomy as a whole.
- When a post is over the cap, **the terms the author just added survive**, where that can be detected; otherwise the deepest terms survive, then the lowest term IDs. The rule's description says so in those words.

Level restriction is not live on any real site, so the removal needs no migration and no manual re-save.

## User Stories

1. As a site author, I want to find "limit how many terms a post can have" in the rule-type picker by that name, so that I do not have to know it is hidden inside a depth rule.
2. As a site author, I want to cap a flat taxonomy at one term, so that a post can have only one category-like value without making the taxonomy hierarchical.
3. As a site author, I want to cap a taxonomy at N terms for any N ≥ 1, so that "at most three topics" is expressible without three rules.
4. As a site author, I want the cap to default to one term, so that the most common case needs no configuration.
5. As a site author with a hierarchical taxonomy, I want the cap to apply per tree depth by default, so that a post can keep one region, one city and one venue.
6. As a site author with a hierarchical taxonomy, I want to switch per-level off, so that "at most two terms in total" counts the whole tree.
7. As a site author with a flat taxonomy, I want the per-level switch to do no harm, so that I do not have to understand it to use the rule.
8. As a site author, I want the per-level switch's description to say it only changes anything on a hierarchical taxonomy, so that I am not left guessing.
9. As an editor, I want the term I just ticked to be the one that survives when I exceed the cap, so that the rule agrees with what I did.
10. As an editor adding several terms in one save, I want all of the added ones preferred over older ones, so that my edit is not overruled by what was already there.
11. As an editor, I want a term another rule added during the pass not to be treated as my edit, so that the rule order the author wrote is respected and my choice wins over rule output.
12. As a site author running bulk apply or the daily cron over posts already over the cap, I want a deterministic outcome with no edit to go on, so that re-running the sweep never reshuffles survivors.
13. As a site author, I want that fallback to keep the deepest terms before the lowest IDs, so that a specific term is never dropped in favor of its own ancestor because the ancestor is older.
14. As a site author, I want a hierarchy-inheritance rule (child to parent) placed after the count rule to stay stable across repeated passes, so that inherited ancestors are not stripped and re-added on every save.
15. As a site author, I want a count rule combined with a parent-to-child or both-direction inheritance rule on one taxonomy to be warned about, so that I learn those two contradict each other.
16. As a site author, I want two count rules on one taxonomy with overlapping post types warned about, so that I notice the caps conflict.
17. As a site author, I want a count rule and a level rule on one taxonomy warned about as order-sensitive, so that I know the list order decides the result.
18. As a site author, I want the count rule to prune an ACF taxonomy field before the native terms, so that the field and the post agree afterwards.
19. As a site author, I want a field-only ACF write with no native sync to fall back to the deterministic ordering, so that it still gets a valid result.
20. As a site author, I want the rule to limit by post type and post status like every other rule, so that I can scope it the same way.
21. As a site author, I want the rule's row title to read like "Limit Shakers to 2 terms per level", so that I can tell rows apart in a long list.
22. As a site author, I want the count rule to have no *Keep ancestor terms* switch, so that a cap is a hard cap and never silently exceeds itself.
23. As a site author who wants ancestors kept, I want to get them from an inheritance rule placed in the list, so that the two jobs stay separate.
24. As a site author, I want level restriction to offer only deepest and shallowest, so that its label and its options describe the same thing.
25. As a site author, I want *Keep ancestor terms* on level restriction to describe only what it does there, so that the text no longer mentions a mode that is gone.
26. As a site maintainer, I want the new type to appear in the one rule-type registry and in the persisted term-rule list, so that it is ordered with every other term rule.
27. As a site maintainer, I want saving the settings page with a count rule on it to round-trip every field, so that no value is silently dropped by a condition gate.
28. As a plugin developer, I want survivor selection to be one pure function, so that its ordering can be tested in a table without WordPress.
29. As a plugin developer, I want the rule to hold no re-entrancy flag and register only capture hooks through its descriptor, so that it follows the dispatcher's contract.
30. As a plugin developer, I want the new class names to autoload under the plugin namespace, so that no runtime fatal reaches a real site.

## Implementation Decisions

**Rule type.** A new descriptor with stored type string `term_count_rules`, effect kind *terms*, claim *restricting*, label "Term count limit — cap how many terms a post may hold". It is a member of the persisted `term_rules` list, ordered with the other term types, and enters that list only once its descriptor reports subfields (otherwise the next save guts its rows). Handler extends the unified handler base and applies one rule to one post behind the standard apply entry point; it keeps no re-entrancy flag, registers no runtime hooks, and gates post type and post status through the base's should-process check. A pass runs it whether or not its trigger fired, so it is idempotent.

**Subfields.** `max_terms` (integer ≥ 1, default 1) and `per_level` (toggle, default on). Shared fields read: taxonomy, post types, post statuses. It does **not** read the hierarchical-taxonomy note, so the note is not shown for it. No ancestors switch. Field IDs are flat. Every gate derives from the descriptor, so `max_terms` and `per_level` sit inside the type's gate and `type`, `enabled`, `post_status`, `row_title` stay ungated.

**Per-level semantics.** With per-level on, the cap applies within each tree depth; with it off, to the taxonomy as a whole. On a flat taxonomy every term is at depth 0, so the two are identical and the switch has no effect *(derived)*. The switch's description states that it only matters for hierarchical taxonomies.

**Survivor selection — one pure function.** Inputs: the post's current terms with their depths, the captured delta (terms added by an outside write in this request, if any), N, and the per-level flag. Output: the surviving terms. Order of preference: (1) terms in the delta; (2) deepest terms; (3) lowest term ID. Within the delta, ties break by deepest then lowest ID. With per-level on the function runs once per depth; with it off, once over the whole set. The function is where this rule's behavior lives and is the module S3 tests.

**Delta capture.** The type's descriptor declares a capture hook on the native term-write action. The capture records, per object and per request, `added = new terms − old terms` as term IDs, in a request-lifetime memo. It records **only writes made outside a pass** — editor, REST, ACF sync, third-party calls. A write made by a rule during a pass (including another rule above this one) is rule output and is not recorded; this is how the author's edit wins over rule output while the rule order the author wrote is still respected. The memo is read by the applier and never consumed, so a second pass in the same request yields the same survivors. No state is persisted: this is not provenance and does not reopen invariant 19.

**No-delta cases.** Bulk apply, the daily cron, a save that touched no terms, and a field-only ACF write with no native sync have an empty delta and take the deepest-then-lowest-ID fallback. A rule saved after the over-cap state arose behaves the same.

**ACF half.** Mirrors level restriction's order: restrict the ACF taxonomy field first, then the native terms, using the same survivor function against the field's term IDs and the same memo. The field half writes only the field, never the post's native terms, so it cannot replace terms an earlier rule just added.

**Collision detection.** The descriptor declares a whole-taxonomy target, so the detector pairs it with any other rule on the same taxonomy and overlapping post types. Two count rules, a count rule with a level rule, and a count rule with a hierarchy rule are warned with the generic "lower rule acts last" message; the combination with a parent-to-child or both-direction hierarchy rule is the one that genuinely contradicts (the count rule trims descendants that inheritance re-adds on every pass). A count rule *above* a child-to-parent hierarchy rule needs no warning: with per-level on a kept term's ancestors occupy one term per depth and cannot exceed the cap, and with per-level off the deepest-first fallback keeps the specific term.

**Level restriction changes.** Remove `one_per_level` from the mode choice; its radio keeps *deepest* and *shallowest*, default *deepest*. Its row title drops the "one term per level" phrase. The *Keep ancestor terms* description loses its "one term per level" sentence and is rewritten for the two remaining modes. Because the type is not live on a real site, no migration code and no re-save notice is added. Stored rows carrying `one_per_level` are not read-time-upgraded (the plugin adds none, per the persisted-kind-list rule); the handler treats an unrecognized mode as it already treats a missing one.

**Config and registry.** The ordered term-rule repeater's type choice gains the new type; the descriptors remain the single source for every gate. The registry's hand-written expected order and the term-rules config's expected-visible map are updated deliberately. The conflict-override and format-rule configs are untouched.

**Documentation.** CHANGELOG entry (user-visible: new type, removed option, fallback behavior). `docs/architecture.md` — the delta capture belongs on the invariant that owns captures and the claim list. CONTEXT.md — a *Term count limit* entry, and the existing level-restriction example sentence adjusted. FW-12 (sub-scope field for restricting rules) notes the count rule now shares it. The phrase "last added" in the old handler comment is deleted with the mode.

**Versioning.** New rule type; bump the minor version and add the changelog entry before the commit that completes the work. `@since` follows the current generation.

## Testing Decisions

A good test here asserts external behavior — the terms a post ends with, the warnings shown, the survivors a pure function returns — never which private method ran.

**S1 — testbed behavior sweep (highest seam).** A new sweep alongside the existing ones, built on the shared sweep library (snapshot → isolate → sweep → restore) and the mc-rules fixture, authoring rules only through the library's writers. It covers: cap of one and of N on flat and hierarchical taxonomies; per-level on and off; the author's edit wins over an older term on an outside write; a term added by another rule in the same pass does not count as the edit; bulk apply over already-over-cap posts takes the deepest-then-lowest-ID fallback and is stable on a second run; a child-to-parent hierarchy rule after the count rule is stable across two passes; the ACF field half; level restriction offers only the two depth modes and a stored `one_per_level` row degrades like a missing mode. After any restore, check `mc_item` slugs, since the format pass runs for every drained entity. Prior art: the dispatch, appliers and cross-order sweeps.

**S2 — existing static gates, updated not added.** H1 lint, H2 autoload (new FQNs listed), H10 kind lists, H11 term-rules config (expected-visible map updated deliberately), H14 collision detector (new pairings), H16 registry (hand-written expected order updated), H13 dispatcher (the new type's capture hook and no-hook-otherwise contract), plus the propagation-label and fixture-manifest checks if they enumerate types. Run the whole `tests/*.php` loop before the commit; a base helper a handler calls must exist on the base.

**S3 — `verify-term-count-survivors` (new, pure PHP, no WP).** A golden table over the survivor function: delta alone, delta larger than N, no delta, deepest preferred over an older ancestor, lowest-ID tiebreak, per-level on and off, flat input, N larger than the set, empty set. Prior art: the token-engine golden table (H17). It requires extracting selection out of the handler as a pure function with no WordPress calls.

## Out of Scope

- A sub-scope field for restricting rules ("governs levels 3–4 only") — FW-12; the count rule is a new consumer of it, not part of this work.
- Renaming the level-restriction label (FW-14 owns renames).
- Persisted provenance of any kind (architecture.md invariant 19, *Rejected: provenance*). The delta is request-lifetime only.
- Any migration, read-time upgrade or notice for `one_per_level` rows.
- A choice of survivor strategy exposed on the rule; the ordering is fixed.
- A *Keep ancestor terms* switch on the count rule.
- Choosing a collision-detector reach model beyond equal targets (the detector's documented non-goal).

## Further Notes

- A `child_to_parent` hierarchy rule **before** the count rule with per-level off and a small N will have its inherited ancestors trimmed by the count rule and re-added each pass; this is the author's ordering and is covered by the order-sensitivity warning, not prevented.
- The old survivor was "alphabetically last at the level" by accident of `wp_get_object_terms` default ordering. The new fallback intentionally changes that tie-break; this is free under the not-live assumption. If any real site turns out to run a level-restriction rule in `one_per_level`, stop and re-open the migration question before shipping.
- Flat taxonomies were never actually blocked by the handler, only by the UI note; the new type removes the note from its row rather than adding a taxonomy-conditional gate.
