# Spec: Computed field output and separator cleanup (FW-4 + FW-44)

> **Design history lifted 2026-10-22 from the private spec file. Never corrected.** This is the build contract for FW-4 and FW-44, written before the build and kept as written: why computed field output became a format row type that rides the dispatcher's `$data` seam instead of reviving a pre-write phase, and how the token engine learned to clean up punctuation around empty tokens. It is **not** documentation of shipped code: see [architecture.md → Field effects ride the `$data` seam](../architecture.md#field-effects-ride-the-data-seam), [architecture.md → Token engine](../architecture.md#token-engine), the PHPDoc on `Core\FormatDispatcher` and `Tokens\TokenEngine`, and [CHANGELOG.md](../../CHANGELOG.md). "CLAUDE.md don't N" citations point at the maintainer's private notes. The seven build tickets were not lifted. What v1 left out is tracked as FW-46 (plain meta target), FW-47 (consumer-before-producer order lint), FW-48 (retire the ADRs) and FW-49 (value-filter and conditional tokens) in [future-work.md](../future-work.md).

Status: shipped in [PR #80](https://github.com/davidofchatham/meta-conductor/pull/80), merged 2026-10-22.

Covers two `FW-N` rows built as one effort: **FW-44** (orphaned separators around empty tokens) lands first as an engine-only change, then **FW-4** (`field_transformation_rules`) at post level, then FW-4 inside ACF repeater rows. Branch `feature/field-transformation`, PR, merge commit. Changelog entries go under `[Unreleased]` (the plugin header is already 0.9.0). Supersedes the pre-#64 token-gap scoping note for FW-4, whose hook-driven write design no longer fits the dispatcher.

## Problem Statement

Site authors keep composed display strings — a person's display name from first/middle/last, a roster line like "Forward / 6'2" / 180 lbs.", an event title fragment from its terms — in template PHP that recomputes them on every render. That logic is invisible to the editor, duplicated across templates, and cannot be queried or sorted because the composed value is never stored. Meta Conductor already has a pattern engine that composes titles and slugs from fields, terms and dates, but it can only write `post_title` and `post_name`; there is no way to store a composed value in a field, and none at all inside an ACF repeater row, where roster-style data actually lives.

Separately, the pattern engine leaves stray punctuation when a token in the middle of a pattern is empty: `{a} - {blank} - {b}` renders `a - - b`, and `{a} ({blank})` renders `a)`. Live title rules produce these titles today, and a field rule that composes from optional fields would hit the same defect far more often.

## Solution

A new rule type in the Format & Transform list, **Computed field**, writes a pattern's output into an ACF field on each post in scope, using the same tokens as title/slug rules. A rule can target a post-level field or a subfield of every row of a repeater; inside a row, field tokens read that row's sibling subfields. The output is either plain text (values stored verbatim) or HTML (pattern markup kept, token values escaped). Because it sits in the same ordered list as title/slug rules, a title rule ordered after a field rule can read the field it just computed, in the same pass.

Before that, the pattern engine learns to remove the punctuation around an empty token properly — separators between tokens, bracket pairs around an empty token, floating connectives — so every pattern, title, slug and field alike, renders cleanly. Existing titles with orphaned separators correct themselves on their next pass.

## User Stories

1. As a site author, I want a title pattern with an empty middle token to render without a doubled separator, so that `Smith - - 2026` becomes `Smith - 2026`.
2. As a site author, I want a bracketed token that resolves empty to disappear with its brackets, so that `Smith ()` and `Smith )` never appear in a title.
3. As a site author, I want existing posts whose titles carry orphaned separators to correct themselves on their next pass, so that I do not have to re-save every post by hand.
4. As a site author, I want slugs to be unaffected by the separator fix in practice, so that published URLs do not change underneath me.
5. As a site author, I want the separator fix to apply to every output (title, slug, field), so that one pattern behaves the same wherever I use it.
6. As a site author, I want to add a Computed field rule to the Format & Transform list, so that a composed value is stored on the post instead of recomputed in a template.
7. As a site author, I want to choose the target field from a list of the ACF fields on the rule's post type, so that I cannot mistype a field name and silently write to nothing.
8. As a site author, I want the target list to offer only fields that can hold a composed string (text, textarea, wysiwyg, number, email, url), so that I cannot point a pattern at a relationship or gallery field.
9. As a site author, I want the target to keep working after I rename a field's label or name in ACF, so that a rule does not break on a cosmetic edit (the rule stores the field key).
10. As a site author, I want to write the pattern with the same tokens title/slug rules use (`{meta:x}`, `{term:tax}`, `{terms:tax}`, `{date_*:x}`, `{pub_*}`, `{default_title}`), so that I do not learn a second syntax.
11. As a site author, I want a plain-text output mode that stores values exactly as resolved, so that "Smith & Jones" is stored as typed and not as `Smith &amp; Jones`.
12. As a site author, I want an HTML output mode where the markup I write in the pattern is kept, so that I can emit `<span class="captain">Captain</span>` into a wysiwyg field.
13. As a site author, I want token values in HTML mode to be escaped, so that a term or field value containing markup cannot inject HTML into the stored output.
14. As a site author, I want the rule to apply on every pass over a post, so that the stored value stays in step when a source field or term changes.
15. As a site author, I want the stored value cleared when every token in the pattern resolves empty, so that a stale composed value does not outlive its sources.
16. As a site author, I want a post whose computed value is unchanged to see no write, so that saves stay cheap and no field history churns.
17. As a site author, I want a title rule ordered after a Computed field rule to read the value that rule just computed, so that I can compose a title from a composed field in one save.
18. As a site author, I want to control which runs first by ordering the rows in the list, so that producer/consumer pairs behave the way I arranged them.
19. As a site author, I want two Computed field rules for different fields on the same post type to both apply, so that one post can carry several composed fields.
20. As a site author, I want two Computed field rules targeting the same field to resolve by list order (the later write wins), so that the outcome is predictable.
21. As a site author, I want the collision check to warn me when two rules target the same field on overlapping post types, so that I notice an accidental overwrite.
22. As a site author, I want title/slug rules to keep their first-match-wins behavior, so that adding Computed field rules changes nothing about my existing title rules.
23. As a site author, I want a pattern that names its own target field to treat that token as empty and to be told about it, so that the value does not compound on every save.
24. As a site author, I want a Computed field rule to target a subfield in every row of a repeater, so that each roster row gets its own composed display line.
25. As a site author, I want `{meta:x}` inside a repeater-row rule to read the sibling subfield `x` of the same row, so that the pattern reads like a post-level one.
26. As a site author, I want `{date_*:x}` inside a row rule to read the row's sibling subfield too, so that date tokens follow the same context rule.
27. As a site author, I want term, publish-date and default-title tokens in a row rule to read the post, so that a row line can include the season or event the post belongs to.
28. As a site author, I want a token naming a nested repeater or group subfield to resolve empty, so that an unsupported reference fails visibly and harmlessly rather than writing garbage.
29. As a site author, I want rows I add, remove or reorder to get correct composed values on the next save, so that a row's output always matches its own siblings.
30. As a site author, I want the format preview to show which fields a pass would change, before and after, so that I can check a rule before running it.
31. As a site author, I want Apply to Existing Posts to fill a Computed field across existing posts, so that a new rule covers my back catalogue.
32. As a site author, I want Apply to Existing Posts to reach exactly the rule's post type, so that a bulk run does not touch unrelated content.
33. As a site author on a site without ACF active, I want the Computed field type to stay inert and not offered, so that nothing errors or half-writes.
34. As a site author, I want a disabled Computed field rule to do nothing on save, so that I can park a rule without deleting it.
35. As a developer reading the rules page, I want each Computed field row's title to say what field it writes and on which post type, so that a long list stays scannable.
36. As a maintainer, I want the new rule type declared through one registry descriptor, so that storage, editor, passes, reach and collisions all read it from one place.
37. As a maintainer, I want the field write performed by the format dispatcher, not the handler, so that "appliers return data, the dispatcher writes" stays a property the static gates can hold.
38. As a maintainer, I want the dispatcher docs and architecture docs to say why no pre-write phase was reintroduced, so that nobody rebuilds it on the strength of an outdated promise.

## Implementation Decisions

**Ticket order.** FW-44 engine fix → FW-4 post-level → FW-4 repeater rows. The engine fix lands first so the new output policies' contract cases are written against the corrected engine.

**Separator cleanup (FW-44).**
- Lives entirely in the token engine's render-and-trim step, so every output policy gets it.
- Ports the ordered removal rules of the public `{{join}}` tag's template mode (bws-gb-dynamic-tags-extensions): attached vs connective punctuation, bracket pairs around an empty token, floating separators, whitespace and orphan-connective repair. Whole-group excision is **not** ported — this grammar has no groups. Rules and the join test matrix are reused; code is not.
- No gate and no legacy mode: live titles re-render on their next pass. Slugs are close to neutral because core sanitizes `post_name` and the slug policy collapses dashes; the regenerated golden diff is the record of exactly what changed. Recorded under *Changed* in the changelog as a deliberate behavior change.

**Rule type.**
- Stored type string `field_transformation_rules`, effect kind **format**, effect target **field** (scalar), claim **owning**. Its jurisdiction is the single slot, so two rules writing one field always collide (ADR 0002, CONTEXT.md → *Jurisdiction*).
- Storage: Options, as a row type in the `format_rules` ordered kind list. No CPT: recipes are bounded per site, single author, no draft/test lifecycle. The storage-model assignments table is updated, clearing FW-4's `decision:` blocker.
- Declared by a `RuleTypes\` descriptor listed in the registry, after `title_slug_rules`. `has_subfields()` is true at the point it enters a persisted kind list (CLAUDE.md don't 6).
- Inert without ACF: the descriptor is not offered and the pass skips its rows.

**Editor row.**
- Shared format frame unchanged and ungated: `type`, `enabled`, `name`, `post_type`, `row_title`.
- Type-gated subfields via the descriptor's `subfields()` (stamped by the existing type-subfield helper): `target_field`, `pattern`, `output`, (one select that also lists each repeater's direct writable subfields), `pattern`, `output`. No `post_status` gate (title/slug has none).
- `target_field` is a select of the ACF fields on the rule's post type, restricted to text, textarea, wysiwyg, number, email, url; the stored value is the **field key**. A repeater target is stored in `target_field` as `row|<repeater key>|<subfield key>`.
- `output` is a select: **text** (default) or **html**.
- Descriptions contain no bare `{word}` (CLAUDE.md don't 4b).

**Output policies.**
- FW-40's planned `raw()` splits into two named constructors on the output policy: `text()` — values verbatim, no sanitizing, no dash collapsing; `html()` — pattern literals verbatim, every token value passed through HTML escaping. Escaping happens only on token values, the trust boundary.
- Neither applies a duplicate-insertion guard (a field pattern composes from scratch; there is no surviving base).

**Token sources.**
- Post rules use the existing post token source.
- Row rules use a new row token source implementing the existing token source interface: `field()` reads the current row's sibling subfield by name; `terms()` and `published()` delegate to the post. Direct siblings only — a nested repeater or group subfield resolves empty.
- `{meta:x}` and `{date_*:x}` therefore mean "field `x` in this rule's context": the post for a post rule, the row for a row rule. No fallback from row to post meta, and no `{post:x}` escape token yet.
- The pass's source reads **pending** field values before stored meta: a field computed earlier in the same pass is what a later rule's `{meta:x}` sees.
- Self-reference: while a rule applies, a token naming that rule's own target resolves empty. The rules page shows an advisory on such a row.

**The `$data` seam.**
- The format applier contract is unchanged in shape: `apply_to_data($data, $post_id, $rule)` returns the next data array, or null when the rule does not apply to this entity.
- The data array gains a `fields` map, keyed by field **name** (because tokens name fields by name). A post-level rule sets `fields[name] = value`. A row rule sets a per-row entry the dispatcher can address by repeater key, 1-based row index and subfield key.
- The dispatcher's write step persists the `fields` diff before the post-row update: post-level through ACF's field write by key, rows through ACF's subfield write with the 1-based row index (verified 2026-06-26 to maintain ACF's field-key reference meta and to re-fire neither `acf/save_post` nor `save_post`). A field whose computed value equals the stored value is not written; an all-empty result writes `''`.
- The handler registers no hooks and writes nothing (CLAUDE.md don't 6b/7).

**Composition within the format list.**
- `compute()`'s first-match-per-type gate becomes a descriptor fact (e.g. a `first_match_wins()` flag), true only for `title_slug_rules`. Field rules skip the gate: every applicable field rule runs in authored order and the last write to a field wins.
- The format pass stays **post-write only**. The pre-write phase promised by ADR 0003 and the dispatcher docblock is not reintroduced: field writes are meta, which `wp_insert_post_data` never carries, and post-write already sees the current pass's terms.

**Reach, collisions, bulk, preview.**
- `written_post_types()` returns the rule's `post_type`.
- The pairwise collision detector gains the field effect target: same target field key (or same repeater + subfield) on overlapping post types → advisory. Claim not consulted.
- Apply to Existing Posts needs no new path: a bulk run is a full ordered pass.
- The format preview's before/after diff includes the `fields` map (and rows).

**Docs.**
- No new ADR. The "fields ride the `$data` seam; no pre-write phase" decision is recorded in the format dispatcher's class PHPDoc and on a `docs/architecture.md` anchor, with *pre-write phase* listed as a rejected alternative. ADR 0003 gets one dated status line pointing there; its text is not rewritten. The dispatcher docblock's two-phase claim is corrected.
- CONTEXT.md gains nothing new unless the build mints a term; *Computed field* is the editor label, `field_transformation_rules` the stored string.
- New `FW-N` rows (no phase): **plain meta target** (acceptance bar: route a name that matches an ACF field through ACF's write; refuse `_`-prefixed keys); **consumer-before-producer order lint** (a rule reading `{meta:x}` ordered before the rule writing `x`), interacting with FW-34; **retire this repo's ADRs into the architecture anchors** (decision content moves to the anchor it justifies, with its rejected alternatives; a strict bar for any future ADR). FW-34's blocker clears when this lands. FW-4 and FW-44 move to *Done* on merge.
- No real site names, hostnames or field keys in committed text.

## Testing Decisions

A good test asserts rendered output and stored post state — what an author would see — never segment arrays, internal maps or call order. Two behavioral seams, both existing:

- **Token engine over a fake source (H17, host PHP).** FW-44's rules as contract cases taken from the join test matrix; the golden table regenerated deliberately with the diff reviewed row by row, never regenerated to make H17 pass. `text()` and `html()` contract cases, including escaping of token values but not literals. A fake row source exercising `{meta:x}`/`{date_*:x}` against sibling subfields, post-level term/date tokens in row context, and nested references resolving empty. Prior art: the existing H17 fake source and golden table.
- **Format pass on the docker testbed (real WP + ACF), one new sweep** using the sweep library's snapshot → isolate → sweep → restore. Cases: post-level write; unchanged value → no write; all-empty → `''`; two field rules on different fields both apply; same field → last in order wins; title/slug still first-match-wins; a title rule after a field rule reads the pending value; self-reference resolves empty; repeater rows written per row, including add/remove/reorder; preview diff shows fields; an Apply to Existing run fills existing posts. The mc-rules fixture gains an ACF group with text and wysiwyg targets and a roster-style repeater. Prior art: the #64 format sweep and its restore gotcha (check `post_name`s after restore).

Static gates extended, not new: H12 (exact visible subfield set for the new type, no bare `{word}`), H16 (registry order), H14 (same-target field collision), H13 (the handler registers nothing, `apply_to_data` keeps its single call site, no write primitive in the handler). All `tests/*.php` loop green before each commit.

## Out of Scope

- Plain (non-ACF) meta targets — FW row.
- Value-filter tokens (`{term:TAX|exclude:…}`) and conditional tokens (`{if_term:…}`) — later tickets only if the real use cases still need them after v1.
- Format transforms (height from feet + inches, boolean → label) — stay in site PHP as a documented boundary.
- A `{post:x}` escape token for row rules; reads into nested repeaters or groups.
- Consumer-before-producer order lint, and the full reach-based collision detector (FW-34).
- Retiring the existing ADRs.
- A pre-write format phase.
- Any change to slug-change safety (FW-36) beyond what the FW-44 golden diff shows.

## Further Notes

- The two real use cases behind FW-4 are a roster position/height/weight display line (repeater-native, about 70% reachable with plain substitution) and an event title fragment from terms (about 50%; the rest is conditional and helper logic that stays in PHP). v1 is judged by whether the plain-substitution part of both lands cleanly, not by full coverage.
- The format pass runs for every entity a drain reaches, not only saved ones (CLAUDE.md don't 6f(e)). A Computed field rule therefore reconciles when a parent's terms change too. That is intended; the sweep should confirm it does not write to posts outside the rule's post type.
- Revision suppression in the dispatcher's write covers the row update; ACF field writes do not create revisions.
