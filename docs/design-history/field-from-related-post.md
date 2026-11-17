# FW-5: Set a field from a related post

> **Design history lifted 2026-11-17 from the private spec file. Never corrected.** This is the build contract and settled-decision record for the `field_from_related_post` rule type, the shared zero-source gate, and the widened field write path. It is **not** documentation of shipped code: see [architecture.md](../architecture.md) (invariants 17 and 18, *The relationship module*), [readme.txt](../../readme.txt), and [CHANGELOG.md](../../CHANGELOG.md) for the behavior delta.

Build notes, recorded at lift: the write helper shipped as `Core\MetaWriter` and the zero-source gate as `Relationships\SourceGate`, beside the handler (`Handlers\FieldFromRelatedPostHandler`) and the descriptor (`RuleTypes\FieldFromRelatedPostRules`). Ticket 12 landed as specified: `Handlers\AcfSeverCapture` and `ConfigHelpers::acf_relationship_subfields()`. H13 reads a handler's bare `use Trait;` files along with its own, so the capture hooks the trait supplies are still checked against `capture_hooks()`. The *Copy into* picker is a static list: a type-mismatched target is refused at run time, not filtered in the config, until FW-26's dependent picker lands. A deferring copy's collision is `severity => low` with its own `info` notice. Testbed baseline reds `s2a` and `s7` in `sweep-related-post-terms-sever.php` predate this work and are unchanged.

Status: built in [#91](https://github.com/davidofchatham/meta-conductor/pull/91), unreleased.

Design settled in the 2026-11-11 grilling session and amended in the 2026-11-16 one; the `FW-5` row in docs/future-work.md carried the same decisions. Follow-ups cut from this spec: FW-71 (clear on detach, as an Owning option on both relationship rule types). Repeater-subfield targets are out of v1 and get a row only when a use case appears.

## Problem Statement

A site author has content where one post naturally supplies a value to others. An event's venue post has the address every session should show. A team post has a season label every player should carry. A parent page has a field its child pages should inherit. Today the author copies the value onto every receiving post by hand and keeps it in sync themselves, or writes site PHP. When the source changes, nothing updates the copies. When a post moves under a different parent or is linked to a different venue, its copy is wrong until someone notices.

The plugin already solves this shape for terms (*Set terms from a related post*) and lets a pattern *read* a related post's value into a title or computed field (FW-62's `{parent:…}` and `{refs:…}` tokens). Nothing copies a field's raw value across a relationship: a pattern renders a string, so an array, a post ID list, an image or a whole repeater cannot travel that way.

Separately, the existing *Set terms from a related post* rule treats a source that fails its status gate inconsistently between its two relationships. Through a parent, a draft parent is skipped and the child keeps its terms. Through an ACF relationship, a dependent whose sources are all drafts has its terms emptied under Owning. Authors running Owning ACF rows on live sites can lose terms because a source was unpublished for editing.

## Solution

A new rule type, **Set a field from a related post** (`field_from_related_post`), in the ordered format rule list beside title/slug and field-from-pattern rules.

The author picks a relationship (the parent post, or an ACF relationship / post-object field when ACF is active), a source (one field, or one whole ACF field group), a target (defaults to *Same*), and a claim (Owning, Contributing, or Deferring). On every pass over a receiving post, the rule resolves its eligible source, reads the source value raw, and writes it to the target according to the claim. When a source post changes, its receivers are queued and recompute; their own passes reach the next generation.

The status-gate behavior is unified for all relationship rules: a source failing the row's status gate counts as absent, and a post with no eligible source is left alone unless this row captured a sever. *Set terms from a related post*'s ACF relationship adopts this as the first, separately committed step of the build; it is a behavior change called out in the changelog and readme.

## User Stories

1. As a site author, I want to copy a field from a post's parent into the post, so that child pages inherit a value without site PHP.
2. As a site author, I want to copy a field from a post named by an ACF relationship field, so that each session shows its venue's address.
3. As a site author, I want to push a field from a holder post onto every post its ACF field lists, so that a team's season label lands on each player.
4. As a site author, I want the ACF relationship option to keep the same holder/direction model as *Set terms from a related post*, so that I configure both rule types the same way.
5. As a site author, I want to pick the source field by ACF field key, so that the rule survives a field rename.
6. As a site author, I want to pick a plain meta key as the source or target, so that I can copy values that are not ACF fields.
7. As a site author, I want a plain meta name that matches an ACF field to still write through ACF, so that the field-key reference stays intact and ACF keeps reading the value.
8. As a site author, I want a `_`-prefixed meta key refused, so that I cannot overwrite core's or another plugin's private meta.
9. As a site author, I want the target to default to *Same*, so that the common case needs no second pick.
10. As a site author, I want *Same* offered for an ACF field only when its field group is shown on the receiving post type, so that I never copy into a field that does not exist there.
11. As a site author, I want to pick a different target field, so that a value can land under another name on the receiving post.
12. As a site author, I want an ACF target restricted to the source's field type, so that an image does not land in a text field.
13. As a site author, I want values copied raw, so that arrays, post ID lists, images and files survive the copy intact.
14. As a site author, I want a repeater, group or flexible-content field copied as one value, so that the whole structure moves at once.
15. As a site author, I want to copy a whole ACF field group, so that one rule carries every field of the group.
16. As a site author, I want group mode to read the group's members live at pass time, so that a field I add to the group later is copied without re-saving the rule.
17. As a site author, I want group mode to copy into the same group on the receiving post, so that there is no guesswork pairing fields by name.
18. As a site author, I want each member of a group copy to be its own claim slot, so that one empty member does not decide the others.
19. As a site author, I want Contributing as the default claim, so that a copy never erases a value I typed.
20. As a site author, I want Owning, so that the target always equals the source, including when the source is emptied.
21. As a site author, I want Deferring, so that the copy only fills an empty target and never overrides an edit.
22. As a site author, I want a post whose source is a draft to keep its current value, so that unpublishing a venue for editing does not blank every session.
23. As a site author, I want a root post (no parent) left alone, so that a rule scoped to a post type does not touch top-level posts.
24. As a site author, I want a post detached to root to keep its value in v1, so that detaching is not destructive (clearing on detach is FW-71).
25. As a site author, I want removing a post from an ACF relationship to clear its value under Owning, so that the copy does not outlive the link.
26. As a site author, I want a post removed from a holder's push relationship to recompute, so that it stops carrying the holder's value when the claim says so.
27. As a site author, I want a deleted source handled like a sever, so that its receivers do not keep its value under Owning.
28. As a site author, I want, on an ACF relationship with several sources, the first source holding a value to win, so that the result is predictable.
29. As a site author, I want pull-direction sources ranked in the field's own order, so that I control the winner by reordering the field.
30. As a site author, I want push-direction sources ranked by the reverse field's order when one exists, else by lowest post ID, so that the winner does not change on an unrelated save.
31. As a site author, I want the config to say only one source counts and how the winner is picked, so that I am not surprised.
32. As a site author, I want editing a source post to update its receivers, so that copies stay in sync without me re-saving each one.
33. As a site author, I want a grandchild updated when its grandparent changes, so that a parent chain stays in sync end to end.
34. As a site author, I want a computed-field or title rule ordered after the copy to see the copied value in the same pass, so that rules compose by list order.
35. As a site author, I want to order the copy among my other format rules, so that I decide which writes first when two touch related fields.
36. As a site author, I want a collision warning when another rule writes the same target field over overlapping post types, so that I know list order decides.
37. As a site author, I want a group copy's members included in collision checks, so that a group rule warns against a single-field rule on one of its members.
38. As a site author, I want a Deferring rule's collision shown at lower severity, so that it matches the term side.
39. As a site author, I want a notice when a bidirectional ACF field links posts of one type, so that I know two posts may source each other.
40. As a site author, I want *Apply to Existing Posts* to run this rule, so that I can backfill posts that existed before the rule.
41. As a site author, I want an ACF-relationship row to survive ACF being deactivated, so that I do not lose its config.
42. As a site author, I want that row to run nothing and show a "requires ACF" notice while ACF is off, so that I know why it is idle.
43. As a site author, I want the relationship control to keep both options stored while ACF is off, so that the stored value is not dropped on the next save.
44. As a site author, I want the row's status gate to decide which source posts count, so that only published venues supply addresses if I choose.
45. As a site author running an Owning *Set terms from a related post* ACF row, I want a dependent whose sources are all drafts to keep its terms, so that unpublishing a source does not strip tags.
46. As a site author upgrading, I want the changelog and readme to tell me about that behavior change, so that I can check my Owning ACF rows.
47. As a developer, I want the zero-source and status-gate rule written once in the relationship module, so that the two rule types and both relationships cannot drift apart again.
48. As a developer, I want one field write site, so that FW-4's computed fields and this rule share unchanged-value detection, ACF routing and the save-hook guard.
49. As a developer, I want FW-4's string fields to behave exactly as before after the write path widens, so that no existing computed field changes.

## Implementation Decisions

**Ticket order.** The prefactor (the shared gate and the *Set terms from a related post* ACF alignment) is ticket 01, on the feature branch, as its own commit with its own changelog entry, ahead of any FW-5 code. FW-5 then builds on the gate. Ticket 12 is a post-review refactor with no behavior change: it moves the two related-post rules' duplicated sever capture and relationship subfield block into one place each (below).

**Shared zero-source gate (prefactor).** The relationship module gains one encoding of: "the eligible sources of a dependent for this row are the resolved sources that pass the row's status gate; with zero eligible sources the row is skipped unless this row captured a sever." Both relationships of *Set terms from a related post* and the new type call it. The parent relationship's behavior does not change (it already skips a gated-out parent). The ACF relationship's does: a dependent whose resolved sources all fail the status gate is now skipped instead of emptied under Owning. A mixed set (some drafts, some published) behaves as before, since drafts contributed nothing either way. No stored shape changes, so no migration; changelog *Changed* entry plus a readme upgrade note naming Owning ACF rows.

**Rule type and storage.** `field_from_related_post` is a `type` within `format_rules`, registered in `RuleTypes\Registry` with a descriptor whose `subfields()` are complete before the type enters the persisted list (don't #6). It reads shared subfields (`post_types`, `post_status`, relationship and the ACF holder/direction subfields) through the descriptor `readers()` mechanism; every gate derives from descriptors (don't #3). The relationship subfield STRUCTURE is built once by `ConfigHelpers::acf_relationship_subfields()` and shared with *Set terms from a related post*; each type passes its own copy strings, so a gate cannot drift between the two. H12's expected visible fields and H16's registry order are updated deliberately.

**Relationships.** `parent` (parent → child only) and `acf`, both through the existing `Relationship` implementations. The `relationship` control keeps both options stored when ACF is off and only labels the unavailable one. A stored `acf` row with ACF off runs nothing and shows a "requires ACF" notice. The applier does not call `should_process_post()` for the `acf` relationship; `post_status` gates the source there (don't #6e's rule carries over).

**Source and target.** A source is one field (an ACF field by key, or a plain meta key) or, ACF only, one field group. The target defaults to *Same*: the same ACF key, offered only when its group's location rules show it on the receiving post type, or the same meta key. Otherwise the author picks a target; an ACF target must match the source's field type, and no deeper field-config check is made. A meta name matching an ACF field routes through ACF's write; a `_`-prefixed key is refused at save. This acceptance bar is FW-46's, built here as the shared helper FW-46 will reuse.

**Value.** Read raw: unformatted `get_field` for ACF, `get_post_meta(…, true)` for plain meta (a key with several meta rows copies its first value only). Repeater, group and flexible content move as one value. Group mode copies every top-level member of the group into the same group on the receiving post, members read live at pass time.

**Several sources.** Parent: one. ACF: the first eligible source holding a value wins. Pull direction ranks by the holder field's order; push direction ranks by the reverse field's order when the reverse lookup resolves through a bidirectional or reverse field, else ascending post ID. The config description says only one source counts and how it is chosen.

**Claim, per field slot.** Owning: target = the winning source's value, including empty; with zero eligible sources it is skipped like every claim, and it clears only on a sever captured against this row or when an eligible source holds an empty value. Contributing (default): writes a non-empty source value, never clears. Deferring: writes only into an empty target. Group mode: each member field is its own slot.

**Applier contract.** The handler extends `UnifiedHandlerBase`, registers only its descriptor's hooks, keeps no processing flag, and puts its apply behind the format applier seam: it returns `$data` with `fields[<name>]` and `field_keys[<name>]` set (or a plain-meta marker), or `null` for "does not apply here" (invariant 18, don't #6f). It is idempotent. A value it sets is visible to later format rules in the same pass through the existing `$data['fields']` read path.

**Write path.** `FormatDispatcher::write_fields()` stays the one write site, widened: values are passed raw (no string cast), the unchanged check compares the unformatted stored value strictly rather than as strings, and a plain-meta branch writes via the FW-46 helper. An all-empty Owning result still writes empty. The save-hook suppression around the write stays. FW-4's string values must produce byte-identical writes and identical skip decisions.

**Sever and capture.** A dependent dropped from a holder's push relationship, or a deleted source, reaches the queue through the ACF relationship's existing sever capture and the handler's `drain_captures()`, as on the term side, keyed per row by `sever_key()`. The memo of enabled `acf` rows, the two capture callbacks and `drain_captures()` are one `Handlers\AcfSeverCapture` trait that both related-post handlers compose; each handler keeps its own hook registration, which H13 checks against `capture_hooks()`. The parent relationship gains no capture in v1 (FW-71).

**Fan-out.** The format dispatcher's `fan_out()` widens from FW-62's `{parent:…}` scan to also return the dependents of every enabled `field_from_related_post` row through that row's relationship (`dependents_of()`), filtered to the row's written post types. Zero-cost when no such row is enabled. Grandchildren follow through the receivers' own passes; `drain()`'s per-entity bound ends cycles. This is row-declared fan-out and does not deliver FW-68 (token-declared `refs` fan-out).

**Cycles.** Allowed. The config shows a notice when an `acf` row's field is bidirectional and the holder type equals the related type.

**Collisions.** `CollisionDetector` treats the target as a scalar: any other rule writing the same field name over overlapping post types collides (field-from-pattern, another copy, any member of a group copy, with group members resolved at detection time). Warns, never blocks; list order decides; a Deferring rule contends at lower severity.

**Bulk.** *Apply to Existing Posts* runs the type through `ExistingPostsApplier` (`drain_post()` + `drain()`); the handler has no bulk loop.

## Testing Decisions

A good test here drives real saves and relationship edits through the dispatcher and asserts the stored field values afterward; it does not call the applier directly or assert on internal arrays. Static gates pin module boundaries and contracts that a sweep cannot see.

- **Behavior seam: testbed sweep through the drain.** A new mc-rules sweep authored through `sweep-lib.php` (`mc_write_ordered_rules(…, 'format')`, `mc_restore()`), snapshot → isolate → sweep → restore, recycling `lsphp` before asserting. Covers: each claim on each relationship; pull and push; source ranking in both directions (including the tier-3 ID fallback); group mode including a member added after the rule; a plain-meta target and the `_`-prefix refusal; the ACF-routed meta name; draft sources, root posts, detach to root (keeps value); sever and source deletion under Owning; fan-out to children and grandchildren; in-pass composition with a later field-from-pattern rule; a bidirectional same-type cycle terminating; ACF off (row idle, notice, value preserved on save); Apply to Existing Posts. Prior art: `sweep-computed-field.php`, `sweep-fw62-parent-fan-out.php`, `sweep-related-post-terms-sever.php`. After restore, check `mc_item` `post_name`s (don't #6f).
- **Prefactor behavior.** Rerun `sweep-related-post-terms-sever.php` and `sweep-fw61-mirror.php` as a baseline, then add the all-sources-draft Owning ACF case asserting terms are kept, and a mixed draft/published case asserting unchanged behavior.
- **Static gates, updated not added.** H13 reads a handler's composed traits along with its file, and fails on a named trait with no file; it pins that both term relationships and the new type route through the shared gate and nothing reimplements it, and pins the widened format `fan_out()`. `verify-relationships.php` pins the gate as a pure fact of the module, beside the sever key. H12 for the new type's visible fields, H16 for registry order, H14 for per-field and group-member collisions, H2 for new FQNs, the `AcfSeverCapture` trait among them. FW-4's computed-field sweep reruns unchanged to prove the widened write path.
- Every `tests/*.php` loops clean before each commit.

## Out of Scope

- Clearing on detach (`post_parent` → 0, or an emptied ACF field) as an Owning option: FW-71.
- Repeater-subfield targets (one subfield across a repeater's rows).
- Cross-group mapping in group mode; any name-based pairing.
- Plain meta keys with several meta rows.
- Field-config compatibility checks beyond field type.
- Child → parent copying on the parent relationship.
- Token-declared `refs` fan-out and reverse direction: FW-68.
- A status gate on cross-post tokens: FW-67.
- Fixing FW-11's unindexed tier-3 reverse lookup; this rule uses it as the term side does, with the same config warning.

## Further Notes

- Axes: basis relationship, effect target field (scalar, so it always collides), claim author's choice.
- The prefactor changes live behavior; the readme upgrade note should tell authors with Owning ACF *Set terms from a related post* rows that a dependent whose sources are all unpublished now keeps its terms.
- Interacts with FW-4, FW-7, FW-11, FW-14, FW-26, FW-46, FW-62, FW-63, FW-71. FW-46 reuses the meta write helper built here.
