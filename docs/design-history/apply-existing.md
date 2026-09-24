# Spec: Apply rules to existing posts (Phase 7 / FW-16)

> **Design history lifted 2026-09-24 from the private spec file. Never corrected.** This is the build contract for the *Apply to existing posts* page, written before the build and kept as written. It records what the page does, plus the options that shaped it but never became code: the recipe engine it replaced, why a run is a full pass rather than a single-rule apply, and what was deferred to FW-31/32/33. It is **not** documentation of shipped code: see [architecture.md → Apply to existing posts](../architecture.md#apply-to-existing-posts) and [CHANGELOG.md](../../CHANGELOG.md). "CLAUDE.md don't N" citations point at the maintainer's private notes; the invariant each one names is in [architecture.md](../architecture.md) or on the enforcing class's PHPDoc. The nine build tickets were not lifted.

Status: shipped in [PR #75](https://github.com/davidofchatham/meta-conductor/pull/75) (verified 2026-09-24: 16 static gates + all 16 `sweep-apply-existing.php` steps green)

Settled in the 2026-09-23 grilling session; the decisions are recorded in FW-16 / FW-31 / FW-32 / FW-33 in [future-work.md](../future-work.md). This spec is the build contract.

## Problem Statement

Rules only act when something provokes a pass on a post — a save, an ACF write, a term change, the daily cron sweep. When an author adds a rule, changes one, or re-orders a kind list, every post that already exists keeps its old terms, title and slug until someone happens to re-save it. On a site with hundreds of posts that means either re-saving them by hand or living with content that silently disagrees with the configured rules.

The plugin already has a bulk primitive (#31 made it real), but it has no UI trigger: an author cannot reach it. The only bulk surface in the admin is the Data Conversion page, which is out of date, noisy (unconditional `error_log()` on every operation), half-renamed, and built around one-shot copy/map flows rather than the rules the author has actually configured. Authors are wary of touching it.

An author also has no way to see what a rule would do before turning it on, or to use a rule once as a clean-up tool without leaving it enabled.

## Solution

A new admin page, **Apply to existing posts**, under the Meta Conductor menu, replacing Data Conversion. The author picks a rule from a dropdown listing every configured rule of both effect kinds (term rules and format rules), disabled rules included, plus **All enabled rules**. They can preview, optionally limit the run to the first N posts, and apply.

Applying provokes a **full ordered pass** on every post in the chosen rule's reach — exactly the pass a save would run, so a bulk run and a save over the same rules can never disagree. A disabled rule takes part in that pass as though enabled, at its authored position, for this run only — a **one-time run**; it stays disabled afterwards. Large runs proceed in time-boxed batches with a Continue click, and each run reports which posts actually changed.

The Data Conversion page, its code and its unused support classes are deleted in the same release. Its Copy / Map jobs come back later as rule types applied through this page.

## User Stories

1. As a site author, I want a single admin page for applying rules to existing posts, so that I don't have to re-save posts one by one after changing a rule.
2. As a site author, I want the page under the Meta Conductor menu where Data Conversion used to be, so that I find it where I already look for bulk tools.
3. As a site author, I want a dropdown listing every configured rule by its row title, so that I pick the rule I mean without decoding ids or indexes.
4. As a site author, I want term rules and format rules grouped separately in the dropdown, so that I can tell which kind of change a rule makes.
5. As a site author, I want disabled rules listed too, marked "(disabled)", so that I can preview or run a rule before turning it on.
6. As a site author, I want to run a disabled rule once without enabling it, so that I can use a rule as a one-time clean-up.
7. As a site author, I want the confirm step to tell me that a disabled rule's result is a one-time run that later saves neither maintain nor undo, so that I am not surprised when a later save leaves its effect in place.
8. As a site author, I want an "All enabled rules" option, so that I can bring the whole site into line with my configuration after a large change or a re-order.
9. As a site author, I want "All enabled rules" to exclude disabled rules, so that the option does what its name says.
10. As a site author, I want a run on a rule to apply every enabled rule to the affected posts, in my authored order, so that the result is the same as if I had re-saved each post.
11. As a site author, I want the chosen rule to decide which posts are touched, so that running one narrow rule does not re-pass the whole site.
12. As a site author, I want a run to cover published, draft, private and scheduled posts but never trashed posts or auto-drafts, so that clean-up reaches real content only.
13. As a site author, I want a rule's post-status setting respected when choosing posts, so that a rule scoped to published posts is not run over drafts.
14. As a site author, I want a from-referenced-post (ACF) rule's post-status setting NOT used to filter the posts written, because on that rule it gates the source, so that a draft dependent of a published source is still kept in sync.
15. As a site author, I want a preview for a format rule showing current and resulting title and slug for a sample of posts, so that I can check a pattern before it renames anything.
16. As a site author, I want a format preview for a disabled rule to show what enabling it would produce, including when it would win first-match over another title/slug rule, so that I can decide whether to turn it on.
17. As a site author, I want a term-rule preview to show how many posts are in reach and a sample of them, so that I know the size of the run before I start it.
18. As a site author, I want the preview to write nothing, so that previewing is always safe.
19. As a site author, I want to limit a run to the first N posts, so that I can try a term rule on a handful of posts and inspect them before running the rest.
20. As a site author, I want a confirm step before any run that says it writes to posts, cannot be undone, and should follow a backup, so that I don't run a bulk write by accident.
21. As a site author, I want a run on a large site to proceed in batches that each fit in one request, so that the run never times out.
22. As a site author, I want each batch to report progress as "processed / total" and offer Continue, so that I can see how far along the run is and resume it.
23. As a site author, I want Continue to pick up exactly where the previous batch stopped, even if posts were added or edited in between, so that no post is skipped or processed twice.
24. As a site author, I want a way to start a run over from the beginning, so that I can re-run after fixing a rule.
25. As a site author, I want my in-progress run kept separate from another admin's run and from my run of a different rule, so that two runs don't corrupt each other's progress.
26. As a site author, I want a change report after a run showing how many posts actually changed and the first 20 before/after rows (terms, title, slug), so that I can verify the run did what I expected.
27. As a site author, I want the report to say it does not include changes made to other posts by fan-out (children, dependents), so that I know where else to look.
28. As a site author, I want the report to accumulate across Continue batches, so that the final summary covers the whole run.
29. As a site author, I want the page to refuse to run if the rules changed since I loaded it, and ask me to reload, so that I never run a different rule than the one I picked.
30. As a site author, I want a clear message instead of a false "done" when rule passes are switched off (importing, a filter, or the seeder), so that the page never claims to have applied something it did not.
31. As a site author, I want the Data Conversion page gone, so that I am not tempted to use an unmaintained tool on live data.
32. As a site author upgrading, I want the old conversion cron job cleaned up automatically, so that nothing fires into deleted code.
33. As a site author, I want the upgrade notes to say Data Conversion was removed and what replaces its Copy / Map jobs, so that I know how to get the same result.
34. As a site author, I want the page's own Save button, if shown, to affect nothing but the page's remembered choices, so that saving on this page never touches my rules.
35. As a site author, I want runs to not create a post revision per post, so that bulk runs do not flood revision history.
36. As the plugin developer, I want a single bulk path through the dispatcher's queue, so that bulk and save cannot diverge and H13's one-apply-caller invariant stays true.
37. As the plugin developer, I want the bulk applier to take a rule choice rather than a page request, so that the future in-row Preview / Apply button (FW-31) is a second entry point onto the same code.
38. As the plugin developer, I want the conversion subsystem, the support classes and the two title/slug AJAX endpoints deleted, so that the codebase carries one bulk mechanism instead of three.
39. As the plugin developer, I want fan-out from bulk-passed posts drained within the same batch, so that children and dependents reconcile before the batch reports.
40. As the plugin developer, I want a disabled-rule override that exists only for the duration of the run and cannot leak into a save in the same request, so that a one-time run is really one-time.

## Implementation Decisions

**Modules**

- **Existing-posts applier** (new, `Core`): the one module this spec adds logic to. Two public entry points, both taking a *rule choice* (not a request): `preview(choice)` and `run_batch(choice, run state, limit)`. Owns reach → post query, batching, the time box, the cursor, the change report and the disabled-row override lifetime. Knows nothing about Wireframe.
- **Apply page** (new, `Admin`): a second Wireframe page in the existing `App::boot()` `pages[]`, with `parent` = the Meta Conductor menu slug and its own option key (never `bws_meta_conductor_settings`). Holds the rule dropdown, the limit field, and one `action` field with Preview / Apply / Start over buttons (Wireframe multi-button mode). Its action filters (`bws-meta-conductor/action/<page>/<field>/<button>`, the `CollisionDetector::init()` pattern) decode the in-flight values, call the applier, and render the result as `{status, message, html}`. Apply uses the `action` field's built-in `confirm`.
- **Dispatchers** (modified): `TermDispatcher` and `FormatDispatcher` each read their kind list through `ordered_rules()`, filtered to enabled rows. Both gain one request-scoped override, "also include this row", set by the applier for the duration of a batch and cleared in `finally`. Keyed by kind + row fingerprint. `FormatDispatcher::run_pass()` splits into a compute step (returns before/after post data, writes nothing) and the existing write; the format preview calls the compute step.
- **Deleted**: the conversion subsystem (UI, manager, data processor, field mapper, preview system, CLI command `bws-conversion`), its assets, its `wp_ajax_*` registrations and `get_conversion_manager()` on `TaxonomyManager`, the Data Conversion submenu registration and render callback, `includes/support/`, `process_existing_posts()` on the base and its two overrides (time-based, title/slug), `TitleSlugHandler::preview_rule()`, and the `bws_title_slug_preview` / `bws_title_slug_process_existing` AJAX endpoints. The upgrade routine unschedules `bws_meta_manager_conversion_cleanup`.

**Rule choice**

- Dropdown options are built from the stored kind lists at page load. Each option value encodes kind, position in the kind list, and a **row fingerprint**: a hash of the row as `normalize_rule_shape()` returns it, minus the read-time `id`. A row has no stable id, so position + fingerprint is the identity. "All enabled rules" is a sentinel value.
- At run time the applier re-reads the kind list; if the row at that position no longer has that fingerprint, it refuses with "rules changed since this page loaded — reload". This covers edits, re-orders, deletes and enable/disable toggles.
- Labels are the row title (the same label the collapsed repeater row shows), prefixed "(disabled)" for disabled rows, grouped by kind.

**Reach → posts**

- A rule's post types come from `CollisionDetector::written_post_types()` — the **reach** at post-type granularity. Empty means every public post type. "All enabled rules" is the union over enabled rows of both kinds.
- Statuses: the rule's `post_status` where that field gates the written post; otherwise publish / draft / private / future. **Not** on `related_post_terms`, where `post_status` gates the source (CLAUDE.md don't 6e(b)). Never trash or auto-draft. For "All enabled rules", the default status set.
- Reach is deliberately conservative (CONTEXT.md → Reach), so a run may pass posts the rule's own gate then skips. That is correct; the pass does the gating.

**A run**

- Per post in a batch: `TermDispatcher::drain_post()` (term pass then format pass — `run_entity()`, the cross-kind order stays one statement). After the batch's posts, `drain()` once, so fan-out and capture marks settle before the batch reports; `MAX_PASSES_PER_DRAIN` bounds it.
- The applier never calls `TermDispatcher::apply()` / `FormatDispatcher::apply()` directly. After the deletions `run_pass()` is the only caller of each, which H13 then pins.
- If `pass_enabled()` would refuse (either off switch), the batch returns an error message and processes nothing — the #31 "lying button" rule.
- **Disabled-row override**: set before the batch, cleared in `finally` after the batch's `drain()`. A disabled row therefore runs at its authored position, and on format rules can win the per-type first match. The stored row is never written.

**Batching and run state**

- Time box: each batch processes posts until about 20 seconds have elapsed (a filterable default), then stops at a post boundary.
- Cursor: ascending post ID (`ID > last`), not an offset, so posts created, trashed or edited between batches neither shift nor duplicate the sequence. Total is counted once at the start of a run and shown as "processed / total".
- Run state (cursor, counts, the accumulated report sample, limit) lives in a transient keyed by user ID + choice value. The fingerprint in the choice value means a changed rule starts a fresh state. Completion deletes it; Start over deletes it; the transient expires after a day.
- Limit: optional N; the run completes after N posts processed in total across batches.

**Preview**

- Format kind: the compute step over a sample of up to 5 in-reach posts, most recent first, showing current / resulting title and slug. Uniqueness suffixing is not applied in preview (it depends on what else is stored at write time); the preview says so. The preview reads current terms, since the term pass cannot be dry-run.
- Term kind: in-reach count plus a sample of up to 10 post titles with edit links. No dry run (FW-32).
- "All enabled rules": the count, plus the format sample if any format rule is enabled.

**Change report**

- For each post the batch passes: snapshot title, slug and the post's terms in its object taxonomies before `drain_post()`, compare after. Count changed posts; keep the first 20 changed rows (per-taxonomy added / removed term names, title and slug before → after) across the run.
- Posts changed only by fan-out are not in the report, and the report says so.

**Page chrome**

- Wireframe shows Save whenever a page has editable fields; hiding it needs an upstream change (wp-wireframe#36 asks for one). Accepted: Save persists only the page's dropdown / limit values to the page's own option key. Runs read the in-flight values the action posts, never the stored ones.

## Testing Decisions

A good test here asserts what the author sees and what ends up stored on posts — terms, title, slug — after a preview or a batch. It does not assert how the applier walks its query or which private helper it calls. The two exceptions are invariants the repo already guards by source inspection, where the structure *is* the contract (one apply caller; bulk goes through the queue).

**Highest seam — the applier's two entry points, on the testbed.** A new sweep, `sweep-apply-existing.php`, using `sweep-lib.php`'s snapshot → isolate → sweep → restore and authoring rules with `mc_write_ordered_rules()`. Each step is its own eval (CLAUDE.md don't 6e request-memo gotcha). Steps:
- An enabled term rule applied to posts that predate it gets the same end state as re-saving each post.
- A disabled term rule applied once changes posts, stays disabled in storage, and a later save of one of those posts neither re-applies nor undoes it.
- A disabled title/slug row ordered before an enabled one wins first match in the preview and in the run; storage is unchanged.
- Format preview writes nothing: post rows and `_bws_raw_title` meta are byte-identical before and after.
- Limit N processes exactly N posts; Continue resumes by ID; a post created between batches with an ID below the cursor is not processed, one above it is.
- A stale choice (row edited, re-ordered or toggled after the choice was built) is refused and writes nothing.
- A pass switched off (`meta_conductor_term_pass_enabled` false) makes a batch report an error and write nothing.
- Propagation: applying to a parent reconciles its children within the same batch (fan-out drained), and the report lists only the parent.
- `related_post_terms`: a draft dependent of a published source is covered, i.e. `post_status` is not applied to dependents.
- Restore check: `wp post list --post_type=mc_item --fields=ID,post_name` after restore (the don't 6f(e) slug side effect).

Prior art: `sweep-60-dispatch.php` and `sweep-64-format.php` (both currently call `process_existing_posts()` and move onto the applier), `sweep-61-appliers.php` for the per-step structure.

**Pure pieces, on host PHP, no WP** — one new harness, `verify-apply-existing.php`, and a groups-only extension of H13:
- Choice codec: encode → decode round-trips; a fingerprint mismatch at a position is detected; the fingerprint ignores the read-time `id` and changes when `enabled` changes.
- The dispatcher override: over a projected kind list, "enabled only" plus an included fingerprint yields the enabled rows plus exactly that row, in authored order; with no override it equals today's filter.
- Reach → statuses: `related_post_terms` never narrows by `post_status`; other types do; the default set never includes trash or auto-draft.
- **H13**: `TermDispatcher::apply()` and `FormatDispatcher::apply()` each have exactly one call site (their `run_pass()`); the applier's body calls `drain_post` / `drain` and never `apply` / `apply_to_post` / `apply_to_data` or a write primitive; the override is cleared in a `finally`.
- **H2**: new FQNs added; every deleted conversion / support FQN removed from the list.

Prior art: `verify-kind-lists.php` (option shim, projection), `verify-collision-detector.php` (pure transforms over rows), `verify-term-dispatcher.php` (source-inspection groups).

## Out of Scope

- In-row Preview / Apply buttons inside rule rows — FW-31, blocked on Wireframe's action field carrying no repeater-row context (wp-wireframe#39).
- Term-rule dry run — FW-32.
- Background (WP-Cron) runs with no Continue clicking — FW-33. If wp-wireframe#39's continuation lands, Continue becomes automatic instead.
- Porting Copy Data / Map Data. They return as rule types (`related`, `field_transformation` — FW-4 / Phase 6a) applied through this page.
- The post type converter — FW-15, its own tool.
- Undo / rollback of a run. The confirm step recommends a backup instead.
- Choosing posts by hand, by taxonomy, or by arbitrary query. The chosen rule's reach is the only selector, plus the limit.
- Redirects for slugs a format run changes — already filed in FW-13.
- Hiding the Wireframe Save button (upstream wp-wireframe#36).

## Further Notes

- Terms follow CONTEXT.md: *pass*, *reach* (not "scope"), *effect kind*, *order*, *rule*. "One-time run" is new vocabulary for this page only; add it to CONTEXT.md if it spreads beyond this page.
- Release notes: readme.txt upgrade notice + CHANGELOG entry that Data Conversion and the `wp bws-conversion` CLI command are removed. Only the author runs the plugin, on their own sites; confirmed 2026-09-24 that nothing scripts the CLI command.
- CLAUDE.md follow-ups at end of phase: the "Deleted surfaces" list gains the conversion subsystem and `process_existing_posts()`; don't 6b(b) (bulk routes through `TermDispatcher::apply()`) and don't 7's mention of `process_existing_posts()` are rewritten for the applier; the H-gate list gains the new harness.
- Suggested ticket split for `/to-tickets`: (1) dispatcher override + format compute split + harness; (2) applier + H13 groups + sweep, deleting `process_existing_posts()` and the title/slug AJAX endpoints, and moving sweeps 60/64 onto the applier; (3) Apply page; (4) delete Data Conversion + support classes + cron cleanup + release notes.
