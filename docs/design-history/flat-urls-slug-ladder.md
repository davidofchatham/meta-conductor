# Spec: Disambiguation ladder and flat URLs for hierarchical post types (FW-51)

> **Design history lifted 2026-11-02 from the private spec and decision-record files. Never corrected.** This is the build contract and the settled-decision record for FW-51, written before the build and kept as written: why slug collision avoidance became an ordered ladder of tokens instead of a date toggle, and why flat URLs for hierarchical types are a title/slug rule option served by a `request` filter. It is **not** documentation of shipped code: see [architecture.md → invariant 20](../architecture.md) (read-time hooks are a descriptor fact), the PHPDoc on the slug ladder, flat-slug and duplicate-name classes, and [CHANGELOG.md](../../CHANGELOG.md). "CLAUDE.md don't N" citations point at the maintainer's private notes. The six build tickets were not lifted. What was left out is tracked as FW-52 (split the title/slug rule) and the *Open* bullets of the retired FW-51 row in [future-work.md](../future-work.md). The decision record follows the spec as a second part.

Status: shipped in [PR #81](https://github.com/davidofchatham/meta-conductor/pull/81), merged 2026-11-02.


## Problem Statement

Slug collision avoidance on the title/slug rule is date-only, and it silently does nothing unless the slug pattern already contains a date token, so an author who turns it on and expects a year to be appended gets `-2` numbering instead (Site E row 2). It cannot use the parent, a taxonomy term or a meta value, and its date can only be the publication date or a named meta field, never the modified date. Separately, a hierarchical post type serves every child under its parent's path (`/benefit/parent/child/`). A site where the child must be addressable without its parent's slug (Site P, `benefit`) has no way to get that: core resolves a flat path through `get_page_by_path()`, which requires the full ancestor chain, so `/benefit/child/` returns 404, and nothing makes slugs unique per type instead of per parent.

## Solution

Collision avoidance becomes a **disambiguation ladder**: an ordered list of rung tokens, tried cumulatively on a collision, with WP numbering as the last resort. Rungs are tokens from the existing `TokenEngine` vocabulary (`{pub_year}`, `{mod_year}`, `{date_year:KEY}`, `{parent:slug}`, `{term:TAX}`, `{meta:KEY}`), so there is no second language. A new `flat_urls` option on the title/slug row serves a hierarchical type's children at `/base/child/`, makes slug uniqueness per type, and 301s the old nested URL. Six tickets: 01 tokens, 02 ladder, 03 migration of stored rows, 04 flat URL serving, 05 per-type slug uniqueness, 06 duplicate-name notice. Real blocking edges: 02 needs 01, 03 needs 02, 05 needs 02 and 04, 06 needs 04; 01 and 04 can start immediately.

## User Stories

1. As a site author, I want turning on collision avoidance to append a year without my having to put a date token in the slug pattern, so that it does what its label says.
2. As a site author, I want to list the extra information to add on a collision, in order (a parent slug, then a term, then a date), so that colliding slugs stay readable instead of falling to `-2`.
3. As a site author, I want to use the post's modified date as a disambiguator, and be told it makes the slug depend on the last save, so that I choose it with open eyes.
4. As a site author, I want to use `{mod_year}` in a title or slug pattern, symmetric with `{pub_year}`, so that modified and published dates read the same way.
5. As a site author, I want date parts to keep splicing next to the date already in my slug, so that `event-2026-03` escalates to `event-2026-03-14`, not `14-event-2026-03`.
6. As a site author, I want to choose whether the other added parts go before or after the slug, so that a parent slug can read as a prefix.
7. As a site author, I want a date rung to be able to skip straight to day (`{date_day:start_date}`), so that events colliding within a year do not pass through pointless year and month candidates.
8. As the Site E owner, I want my two existing rows migrated automatically to the new field, so that escalation never silently stops.
9. As a site author with a hierarchical type, I want children addressable at `/benefit/child/` while the parent stays at `/benefit/parent/`, so that a child's URL does not carry its parent's slug.
10. As a site author, I want slugs in a flat type unique per type rather than per parent, so that two flat URLs can never collide.
11. As a visitor following an old `/benefit/parent/child/` link, I want a permanent redirect to the flat URL, so that bookmarks and search results survive the switch.
12. As a visitor, I want a junk or stale nested path to stay a 404, so that the redirect does not guess.
13. As a site author with existing duplicate names under different parents, I want one visible notice naming them, so that I can rename them before they matter.
14. As a site author, I want a child named `page`, `feed` or a bare number to be renamed by numbering, so that it never shadows the base's paging or feed URLs.
15. As the plugin developer, I want the ladder walk to be a pure function with its own host-PHP gate, so that ordering, skip-empty and placement rules are a static check, not a docker sweep.
16. As the plugin developer, I want read-time hooks to be a descriptor fact checked by H13, so that "handlers register nothing" stays a property the harness can read.

## Implementation Decisions

- **Tokens (ticket 01, D12).** `TokenSourceInterface` gains `modified()` and a parent read. The engine owns all token-name parsing: `date_part_of()` learns the `mod_<part>` prefix beside `pub_<part>` (bare, no argument); `parent:slug` resolves from the source's parent. Sources only fetch. `RowTokenSource` answers empty for both. New H17 golden rows are added deliberately.
- **Ladder (ticket 02, D11, D13-D16).**
  - Stored field `slug_escalation` (text, blank = off) and select `escalation_placement` (`prefix` | `suffix`, default `suffix`) replace `date_escalation` and `date_field`. Edits are in the descriptor's `subfields()` (H11/H12 derive their gates from it) and H12's expected sets.
  - Walk: candidate N = base slug plus rungs 1..N. An empty rung is skipped. After the last rung, fall through to numbering.
  - Date rung: the token's part is the finest precision to skip ahead to. The walk starts at the later of that part and the first part finer than what the slug already shows; with no date in the slug it starts at year. Date parts always splice after the date token's text in the slug; with no date token to splice against they follow `escalation_placement`.
  - Every non-date rung goes to the `escalation_placement` edge.
  - The walk is a pure function, taking the base slug, the rung list, the resolved rung values, the placement and a uniqueness callback, so `tests/verify-slug-ladder.php` can pin it with no WordPress. The handler supplies the callback and the token values.
- **Migration (ticket 03, D17).** A one-shot, version-gated upgrade routine in `meta-conductor.php`'s upgrade path rewrites the stored `title_slug_rules` rows in the `format_rules` kind list: `date_escalation` on becomes `{pub_year}` (no `date_field`) or `{date_year:<date_field>}`, off becomes blank; the two old keys are dropped. No read-time shim. The routine is deleted one minor later. A post-migration read of Site E must give the same collision behavior for row 1 and the changed (year-appended) behavior for row 2.
- **Flat URLs (ticket 04 serving, 05 uniqueness, 06 notice; D1-D10).**
  - Boolean `flat_urls` on the row, shown for every type, ignored for non-hierarchical types.
  - `post_type_link` returns the flat URL for a type that has an enabled flat row.
  - A `request` filter resolves `/base/child/` (and `/base/child/N/`, attachments follow `get_permalink`) to `p=ID` + `post_type`. Lookup order: published first, then lowest ID, then any status.
  - The same filter 301s a nested path to the flat URL only when its ancestor chain exactly matches the post's real chain.
  - The flat uniqueness loop is per type, reserves `page`, `feed`, `embed`, `attachment` and all-digit slugs (the list is confirmed against core's bad-slug check during the build), and replaces `wp_unique_post_slug()`'s per-parent numbering for flat types.
  - New descriptor method `runtime_hooks()`; `TaxonomyManager::init_handlers()` registers its hooks next to the dispatcher. A new H13 branch forbids write primitives in the callbacks.
  - A duplicate-name admin notice on the Format rules settings screen, computed on that screen's load with one grouped query for post types with an enabled flat row.
- **No rewrite flush** is needed: the type's existing `(.+?)` rule already matches flat paths (verified 2026-11-02).
- **`flat_urls` leaves the ladder as authored (D10).** No auto-added parent rung.
- **Version.** One minor bump across the six tickets, decided at release; ticket 02 must not release without 03. `readme.txt` upgrade notes and CHANGELOG record the stored-field change.

## Testing Decisions

- **Static gates.** H17 (token engine, golden table) for ticket 01. New `tests/verify-slug-ladder.php` for 02, a migration-table check for 03. H11/H12 expected sets updated deliberately for the new subfields. H13 gains the `runtime_hooks()` branch in 04. H2 gets the new class FQNs. All `tests/*.php` pass before each commit.
- **Behavior sweeps (testbed).** 02: the title/slug sweeps (`sweep-59`, `sweep-64`) cover the new shape; matrix §7b/§7c collision ladder re-run and recorded. 04-06: a hierarchical fixture type with parent/child pairs and a same-name collision, a new sweep for resolve / redirect / duplicate lookup, and `wp post list --post_type=… --fields=ID,post_name` checked after every restore (CLAUDE.md 6f(e)).
- **Real-data gate.** Site E migration is verified on the cloned site via `bin/dev-plugin.sh --site <site> meta-conductor --dev` before release; do not hand-patch a file into it.

## Out of Scope

- The title/slug split (FW-52).
- A "don't splice dates" toggle and a slot token (`{escalation}`) for mid-slug placement; both are additive later changes.
- A whole-ancestor-chain token (`{ancestors:slug}`); `benefit` is one level deep.
- A nested-repeater UI for the ladder; it can compile to the same stored token list later.
- Hiding the parent's own URL from a group's users (access control, a separate per-group visibility feature).
- Slug-change safety and redirects for renames on hierarchical types (FW-36); only the nested-to-flat redirect is here, built so FW-36 can share it.
- Scoping the format pass to saved entities (FW-13).

---

# Part 2: Decision record

The decision record for FW-51 (flat URLs and the disambiguation ladder) and FW-52 (the deferred title/slug split). Paused 2026-10-31 after a first grilling session, reopened and fully settled 2026-11-02 by a second (25 questions, all answered). The numbered items under *Settled decisions* are D1-D20.

## Origin

The user found a snippet (carbondigitalus/wordpress-acf-flat-url-structure, two hooks, ~30 lines) that flattens a CPT's URLs. It is not a design: it resolves with `get_page_by_path($name, OBJECT, $type)`, which compares against the full `parent/child` URI, so a nested child never matches; it has no redirects, no uniqueness handling and a hardcoded type list. Its only transferable idea is the pair `post_type_link` (rewrite the permalink) plus a request resolver.

## Driving case

Site P. Post type `benefit`: hierarchical, rewrite base `/benefit/`, 28 posts, 6 children, one level deep, zero duplicate `post_name` today. A parent benefit supplies e.g. the featured image; only a child may be visible to a given group View, and the child's URL must not carry the parent's slug.

## Settled decisions

### Flat URLs

1. **Flat means ancestors only.** `/benefit/child/`, not `/benefit/parent/child/`. The type base stays, so the resolver only looks up the last segment inside one post type and nothing can collide with pages or other types.
2. **Hiding the parent's own URL is out of scope.** The parent stays at `/benefit/parent/` and the child keeps `post_parent`. Keeping the parent from a View's users is access control (a separate per-group visibility feature).
3. **Home is the title/slug rule row**, one boolean `flat_urls`, shown for every post type and ignored by the handler for non-hierarchical ones (a Wireframe gate cannot see hierarchy, and a gate on the shared frame risks deleting the field, don't 3). Flattening switches uniqueness from per-parent to per-type in the same code that owns slug uniqueness.
4. **Resolver is a `request` filter** that rewrites the query to `p=ID` + `post_type`. Runs once, main query only, no `is_main_query()` guard. No rewrite flush is needed: the type's existing `(.+?)` rule already matches flat paths.
5. **Lookup order is deterministic and ours:** prefer a published post, then lowest ID, then any status. Core's `name`/`pagename` lookup orders by `post_date DESC` with no tiebreak and 404s a logged-out visitor when the newest twin is a draft.
6. **Old nested URLs 301 to the flat URL**, in the same `request` filter, only when the path's ancestor chain exactly matches the post's real chain (a stale or junk chain stays a 404). Derivable from `post_parent`, so no stored map. Core does NOT redirect singles on its own (only the paged `/child/2/` form). Designed shared with FW-36.
7. **Seam:** new descriptor method `runtime_hooks()`, registered by `TaxonomyManager::init_handlers()` next to the dispatcher (as the cron sweep is). A new H13 branch holds the callbacks free of write primitives. Do not reuse `capture_hooks()`: it would blur "capture applies nothing".
8. **Our own uniqueness loop in flat mode.** `wp_unique_post_slug()` scopes numbering by parent for hierarchical types, so it cannot enforce per-type uniqueness. Reserved names `page`, `feed`, `embed`, `attachment` and all-digit slugs are bumped to numbering (they collide with the base's paging/feed/attachment rules, registered at 'top'). Confirm the exact list against core's own bad-slug check before the spec closes.
9. **Existing duplicates:** an admin notice on the Format rules settings screen only, computed on that screen's load with one grouped query limited to post types that have an enabled flat row. Runtime resolution (decision 5) already copes, so the notice is advisory.
10. **No magic parent default.** Turning `flat_urls` on leaves the escalation ladder as authored; the field description suggests `{parent:slug}` for flat rows.

### Disambiguation ladder

11. **One stored field, `slug_escalation`** (text): rung tokens in order, blank = off (WP numbering only). It **replaces** `date_escalation` and `date_field`; there is no toggle. Placeholder `{pub_year}` and an *Available tokens* `html` table keep it discoverable. Description obeys the H12 bare-`{word}` lint.
12. **Rungs are tokens from the existing vocabulary:** `{parent:slug}` (immediate parent only; a whole-chain token is a later additive rung), `{term:TAX}`, `{meta:KEY}`, and the date family `{pub_year}` (published), `{mod_year}` (modified, NEW), `{date_year:KEY}` (meta field), plus the month/day/hour/minute parts. **Naming corrected 2026-11-02:** the grilling round wrote the published token as `{date_year}`, but the engine's published token is `{pub_<part>}` and `date_<part>:KEY` is the meta-field form (`TokenEngine::date_part_of()`); a bare `{date_year}` is not a token. `{mod_<part>}` is the parallel to `{pub_<part>}`, so the "published and modified symmetric" intent holds. New tokens in `Tokens\` add H17 golden rows deliberately; `modified()` is added beside `published()` on `TokenSourceInterface`, `PostTokenSource`, `RowTokenSource` and the H17 fake.
13. **Cumulative walk.** Candidate N = base slug plus rungs 1..N, first unique wins, `wp_unique_post_slug()`-style numbering last. A rung that resolves empty is skipped.
14. **Date rung semantics.** The named part is the finest precision to *skip ahead to*: the walk starts at the later of that part and the first part finer than what the slug already shows. With no date in the slug it starts at year, so escalation works without a date token in the pattern (the UX trap found 2026-11-02). Consequence: Site E row 2 (`personnel`, escalation on, no date token, empty date field) changes from `-2` numbering to the publication year on collision. Accepted.
15. **Placement.** Date parts always splice next to a date token in the slug pattern. A new select `escalation_placement` (Prefix | Suffix, default Suffix) places every non-date rung and an anchorless date rung. A "don't splice dates" toggle and a slot token like `{escalation}` (mid-slug placement) are deferred, additive.
16. **Modified date is allowed**, with an instability warning in the field description: the slug becomes a function of the last save time, and the pass's own `wp_update_post()` bumps `post_modified`. Recommend ordering it last.
17. **Migration.** A one-shot, version-gated upgrade routine rewrites stored rows: toggle on becomes `{pub_year}` or `{date_year:<date_field>}`, toggle off becomes blank, old keys dropped; delete the routine a minor later. Site E (two `title_slug_rules` rows, key lives only in the `format_rules` kind list) can be verified by hand afterward. This is a stored-row rewrite on a live rule type, deliberate per CLAUDE.md schema stability; do not reintroduce a read-time shim.
18. **Static gate.** The ladder walk is extracted as a pure function (order, skip-empty, cumulative, splice vs edge placement, date start rule) with a new standalone `tests/verify-slug-ladder.php`, joining the loop-before-commit gates. The handler supplies only the slug-uniqueness lookup and the token values.
19. **Order, one minor version bump.** Re-split 2026-11-02 from three tickets to six after checking against the to-tickets skill (slices too large, one horizontal, one false edge). Tickets:
    - **01** `{mod_*}` + `{parent:slug}` tokens, H17 rows, end-to-end rule check. Blocked by none.
    - **02** ladder pure function + gate, `slug_escalation` + `escalation_placement` replacing `date_escalation`/`date_field`, handler on the walk, sweep. Blocked by 01.
    - **03** one-shot migration of stored rows + the real-data clone check. Blocked by 02; 02 must not release without it.
    - **04** flat URL serving: `flat_urls`, permalink, `request` resolver, nested-URL 301, `runtime_hooks()` + H13 branch, hierarchical fixture type. Blocked by none (flat serving does not need the ladder).
    - **05** per-type uniqueness loop + reserved names + Site P clone check. Blocked by 02, 04.
    - **06** duplicate-name notice. Blocked by 04.

### Split (FW-52)

20. **Deferred (2026-11-02), not a prerequisite.** If it ever lands, `slug_escalation`, `escalation_placement` and `flat_urls` partition to the slug row, so building on the combined row costs only carrying fields across. Notes preserved below.

## Facts established

Code:
- `slug_is_unique()` ([class-title-slug-handler.php](../../includes/handlers/class-title-slug-handler.php)) already ignores `post_parent`, but the fallback `wp_unique_post_slug(..., $post->post_parent)` does not, and for a hierarchical type permits duplicates across parents. Flat mode must close this inconsistency.
- Escalation today is date-only (`PRECISION_ORDER`, `escalate_date_slug()`), cumulative, splices parts after the date anchor found in the slug, and goes inert when the pattern has no date token. `TokenEngine` has no parent or modified token; kinds are `meta`, `term`, date parts, `default_*`.
- `{default_slug}` is always `sanitize_title($new_title)` of the current pass; a title pattern with no slug pattern derives the slug implicitly.
- `rule_matches()` is a lookup keyed by `post_type`, first match wins; a second row on one type never runs (TODO(UI) already in the handler).
- Descriptor claim is one combined `fields|title_slug`.
- Handlers register nothing except descriptor-declared capture hooks; H13 enforces it.
- Core records no old slug for hierarchical types (FW-36's open item), so a rule-driven rename there leaves no redirect.
- Live data: Site E (cloned site, plugin 0.9.0), two `title_slug_rules` rows. Row 1: slug-only, `event`, `slug_pattern={date_year:start_date}`, suffix, escalation on, `date_field=start_date`. Row 2: `personnel`, title from `{meta:name_*}`, `slug_pattern={meta:name_first}-{meta:name_last}-{meta:name_post}`, `replace`, escalation on but no date token and empty `date_field`. Neither row is title-only.
- No nested-repeater validation is measured in the shared Wireframe reference, which is why the ladder is a token text field, not a repeater of rung rows. A repeater can compile to the same stored token list later.

WordPress 7.1.4 core on the testbed (read-only check, 2026-11-02, type `mc_section`):
- `/base/child/` for a hierarchical type yields `post_type` + `name`; `WP_Query::get_posts` (class-wp-query.php:2164-2169) moves it to `pagename` and calls `get_page_by_path()` (post.php:6281), which requires the full ancestor chain, so a flat child gets 0 posts and a 404. A `request` filter resolving to `p=ID` works for `/x/child/` and `/x/child/2/`.
- `redirect_canonical()` has no generic compare-to-permalink branch for singles; `/nested/child/` returns NULL even with `post_type_link` flat and a resolver. The paged nested form does 301. `redirect_guess_404_permalink` only runs on a 404 (a loop risk only if the resolver is missing).
- `<!--nextpage-->` works. Attachment links follow `get_permalink(parent)`, so they follow the flat link. Archive and feed rules sit at 'top'; a child named `page` or `feed` is shadowed (rule-order hypothesis, no URL requested).
- Through `post_type_link`: `get_permalink`, sitemaps, REST `link`, feeds, ACF relationship/post_object output, attachment links. Bypass: `wp_get_shortlink` (`?p=ID`, which redirects to `get_permalink`).
- Duplicate `post_name`: singular `name` lookup is not status-filtered, has no LIMIT, orders by `post_date DESC`; `get_page_by_path` has no ORDER BY and no status filter.

## Still to confirm during the spec

- The reserved-name list in decision 8 against core's bad-slug check.
- Rule-row UI: field order and `columns` for `slug_escalation` / `escalation_placement` / `flat_urls` on the format frame, plus H12 expectations.
- Fixtures: an `mc_item`-style hierarchical type with parent/child pairs and a same-name collision (the matrix section), and the sweep gotcha that a restore can rename posts (CLAUDE.md 6f(e)).
- Version number for the minor bump (decide at release, not in the spec).

## Split (FW-52) notes

- Fields partition: `title_pattern` to the title row; `slug_pattern`, `slug_mode`, `slug_escalation`, `escalation_placement`, `flat_urls` to the slug row; `post_type`, `enabled`, `name`, `row_title` shared.
- Open: does a title row alone still rename the slug? Recommended no; migration emits a `{default_slug}` slug row for title-only rows to preserve behavior.
- The slug row must follow its title row (FW-47 lint candidate). Idempotency meta `_bws_raw_title` belongs to the title side.
- Live rule type: migration step or explicit manual re-save, never silent. Prefer a one-shot version-gated upgrade routine over a read-time upgrade.
- Descriptor claim `fields|title_slug` splits into two claims; check the collision detector and `verify-claim-overrides` (H9).
