# FW-63 — Rule-type ids that read like their labels

> **Design history lifted 2026-11-12 from the private spec file. Never corrected.** This is the build contract and the settled-decision record for renaming the seven rule-type ids to read like their labels. It is **not** documentation of shipped code: see [CONTEXT.md](../../CONTEXT.md) → *Rule type* for the id grammar, the PHPDoc on `Storage\RuleTypeIdMigration` for the rewrite, and [CHANGELOG.md](../../CHANGELOG.md) for the behavior delta.

Status: merged in [#88](https://github.com/davidofchatham/meta-conductor/pull/88); the migration is deleted one minor release after it ships.

Source: the FW-63 row of [future-work.md](../future-work.md) and a grilling session, 2026-11-12.

## Problem Statement

A rule type has a label the admin reads (FW-14 renamed them to "Verb effect from basis") and an internal id the developer reads. They no longer match. The label says *Set terms from a date window*; the stored type string says `time_based_rules`; the descriptor is `TimeBasedRules`; the handler is `TimeBasedHandler`. A developer working on a rule has to translate between the two vocabularies every time, and the names that remain (`hierarchical`, `related`, `time_based`) are the ones FW-14 retired as ambiguous: "hierarchical" and "propagation" read as near-synonyms, and so do "related" and "related_post_terms".

Two further rule types are about to be born (FW-5's field copy, FW-8's user-based terms) and a third pair merges (FW-61). If they arrive on final ids while the existing seven keep the old ones, the codebase carries two naming schemes at once.

## Solution

Every rule type's internal id is its label minus the leading verb, in snake_case, with no suffix. A *Restrict* type keeps its verb. The descriptor class, handler class, constants, filenames and test names that mirror an id are renamed to match. Sites that already store rules get a one-time, in-place rewrite of each stored row's type string on upgrade, so nothing they configured stops working and nothing is read through a fallback.

The id table (from FW-14, with the `_rules` suffix dropped):

| Stored today | Label | New id |
|---|---|---|
| `hierarchical_rules` | Set terms from the term hierarchy | `terms_from_term_hierarchy` |
| `related_rules` | Set terms from a trigger term | `terms_from_trigger_term` |
| `time_based_rules` | Set terms from a date window | `terms_from_date_window` |
| `term_count_rules` | Restrict how many terms a post holds | `restrict_term_count` |
| `hierarchical_level_restriction_rules` | Restrict terms by tree level | `restrict_terms_by_level` |
| `title_slug_rules` | Set title and slug from a pattern | `title_slug_from_pattern` |
| `field_transformation_rules` | Set a field from a pattern | `field_from_pattern` |

## User Stories

1. As a developer reading a rule's label in the admin, I want the stored type string to be the same words, so that I can find its code by searching for what I saw.
2. As a developer opening a descriptor class, I want its name to match its stored id, so that I never have to look up which class implements which type.
3. As a developer opening a handler class, I want its name to match its rule type's id, so that the handler for *Set terms from a date window* is the one called `TermsFromDateWindowHandler`.
4. As a developer reading the registry, I want every entry named after its id, so that the one place rule types are enumerated reads like the admin's type select.
5. As a developer grepping the codebase for a rule type, I want one name per type across storage, descriptor, handler, fixtures and tests, so that a single search finds everything.
6. As a developer adding the next rule type, I want one naming scheme to follow, so that I do not have to guess between the old and new styles.
7. As the author of FW-61, I want the merged term rule born on `terms_from_related_post` with no intermediate rename, so that its migration is written once.
8. As the author of FW-5, I want `field_from_related_post` to sit beside `field_from_pattern` under one scheme, so that the two field-writing types read as a family.
9. As the author of FW-8, I want `terms_from_acting_user` to sit beside `terms_from_trigger_term` and `terms_from_date_window`, so that the term-writing types read as a family.
10. As a site owner with rules already configured, I want an upgrade to leave every rule working exactly as before, so that renaming code never changes behavior I rely on.
11. As a site owner, I want the rewrite to happen once and automatically on upgrade, so that I do not have to re-save or re-enter any rule.
12. As a site owner with a hierarchy rule, I want it to keep its place in the rule order after the rewrite, so that it still runs before and after the same neighbors.
13. As a site owner, I want every other setting on each rule row (post types, taxonomies, terms, status gates, claim, enabled flag, title) to survive untouched, so that only the type string changes.
14. As a site owner who runs no rules of a given type, I want the upgrade to do nothing for that type, so that an empty or partial configuration is never altered.
15. As a site owner whose option has no rule lists at all, I want the upgrade to complete without error, so that a fresh install or a cleared option does not break.
16. As a site owner upgrading twice, or reloading during the upgrade, I want the rewrite to be safe to repeat, so that an interrupted or doubled run corrupts nothing.
17. As a site owner jumping from an older release, I want the earlier title/slug escalation rewrite to still run first, so that a site on 0.9.x ends up fully migrated whichever steps it missed.
18. As a site owner, I want the Apply page's choice of rule to keep pointing at the right row after the rewrite, so that a saved selection does not silently change.
19. As a site owner using the collision warning, I want it to report the same collisions before and after the rewrite, so that renaming does not hide or invent conflicts.
20. As a site owner with a daily date-window sweep scheduled, I want that sweep to keep running on its existing schedule, so that renaming the handler does not drop the cron event.
21. As a site owner viewing a collapsed rule row, I want its generated title unchanged, so that the admin looks the same after upgrade.
22. As a developer, I want a row whose type string matches no registered type to read as "(unknown type)" and be skipped, so that an unmigrated or hand-edited row is never half-processed.
23. As a developer, I want no read-time fallback that accepts both old and new ids, so that there is exactly one id per type and the rewrite is the only translation.
24. As a developer, I want the migration to carry a map table, so that FW-61 can add its own merge step without restructuring it.
25. As a developer, I want the migration deleted one minor release after it ships, so that the rewrite does not become permanent code.
26. As a developer running the static gates, I want H2's class list and H16's expected order to fail until they are updated deliberately, so that a rename cannot slip through unreviewed.
27. As a developer running the fixture sweeps, I want the same sweeps to pass before and after the rename with identical expectations, so that a pass proves behavior did not change.
28. As a developer, I want the golden token-engine table (H17) to carry the new ids and still produce the same output, so that the title/slug pattern engine is shown unaffected.
29. As the maintainer, I want the real-data clone checked before release, so that an actual stored configuration is shown to migrate and keep working.
30. As the maintainer, I want FW-14, FW-63 and FW-61 to release together with no tag between them, so that no released version carries the new labels on old ids or a half-renamed registry.
31. As a developer reading a descriptor docblock that points at FW-63, I want that pointer to become redundant and be removed, so that stale TODO markers do not outlive the work.
32. As a developer, I want helper classes that are not rule types (the term-count survivor chooser, the flat-slug helper) left under their current names, so that the diff holds only renames the id scheme requires.
33. As a developer, I want the scheduled-event hook name left alone here, so that this change stays mechanical and the prefix cleanup is its own tracked item.
34. As a reader of historic changelog entries and design-history documents, I want them left as written, so that they still describe what was true when they were written.
35. As the next maintainer, I want the tracked docs that cite an old id (readme, architecture, CONTEXT, the FW rows) updated, so that no live document names an id that no longer exists.

## Implementation Decisions

- **The id is the label minus the verb, with no suffix.** The kind lists (`term_rules`, `format_rules`) already say "rules", and the suffix is the only thing the seven old ids share that carries no information. Descriptor classes keep their `Rules` suffix because the kebab-case autoloader forbids consecutive capitals and the class must still read as a descriptor.
- **Scope is the seven types FW-14 labels.** The merged propagation/related-post-terms type (FW-61), the field copy (FW-5) and the user-based type (FW-8, with FW-54's lock and FW-55's grant riding on it) are born on final ids and are not renamed here.
- **Rename every class that mirrors an id:** the seven descriptors and seven handlers, their kebab-case filenames, the constants that hold the old strings, and the test and fixture names that embed an old type string. A class that does not mirror an id is not renamed.
- **Left alone, deliberately:** helper classes that are not rule types; the daily cron hook and its callback (persisted state, its own item in FW-64); numbered or feature-named sweep and test files that do not embed an old id; historic changelog entries, design-history documents and code comments.
- **One migration module rewrites stored rows.** It holds a map from old id to new id, a pure function that takes a list of stored rows and returns it with each row's type string swapped, and a runner that applies it to the stored rule lists once and records a schema flag. It copies the shape of the existing title/slug escalation migration.
- **The migration rewrites all seven ids unconditionally.** It is not gated on which types a site actually uses. The swap is idempotent and costs the same whether or not a site has rows of a given type.
- **No read-time fallback.** An unmigrated row reads as an unknown type and is skipped, never mis-processed. This is the standing rule for persisted kind lists: storage keeps the one stored shape and no reader accepts a second.
- **The migration touches only the type string.** Row order, every other key, rows of other types, and rows already carrying a new id are returned unchanged.
- **Both persisted kind lists are covered.** Term rules and format rules each hold some of the seven types; the runner walks both.
- **Sequencing with the earlier migration.** The title/slug escalation rewrite shipped in 0.10.0 and matches the old title/slug id. It must remain in this release and run before the id rewrite, so a site upgrading from 0.9.x ends up fully migrated. It is deleted one minor release later, after which the id migration is the only one of the pair left to delete.
- **Where it runs.** From the same upgrade routine that already calls the earlier migration, gated by its own schema flag rather than the plugin version compare, so a development build that has not bumped the version still migrates. The cache is cleared when rows changed.
- **Registry, gates and hand-written lists change deliberately.** The registry's descriptor list, the autoload gate's FQN list and the registry gate's hand-written expected order are edited to the new names; they are meant to fail until edited.
- **Dependent code that spells an old id** (the config classes' visibility maps, the collision detector, the dispatchers, the handler base, the apply-existing path) is updated to read the id from the descriptor where it can, and to the new string where it cannot. No new place that spells a type string is introduced.
- **Docs.** The tracked documents that cite an old id or an old internal class name (readme, README, architecture, CONTEXT, the FW rows, the fixture matrix and fixture README) are updated. A CHANGELOG entry is added under Unreleased, describing the id change and the one-time rewrite. The descriptor docblocks that point at FW-63 lose the pointer.
- **Release.** FW-14 is already merged and unreleased. FW-63 and FW-61 follow on main with no tag between the three, so no released version has labels and ids out of step.
- **Branching.** Large and multi-commit, so a per-feature branch and a pull request, merged as a merge commit, per the project convention.

## Testing Decisions

- **A good test here asserts external behavior:** stored rows in, stored rows out; the registry resolving a stored id to the right descriptor and handler; a sweep producing the same posts and terms before and after. It does not assert class names or file layout beyond what the gates already pin.
- **One new seam: the migration.** A host-PHP harness with the option getters stubbed over an array, copied from the earlier migration's harness. It asserts each of the seven mappings, that other rows and already-migrated rows are untouched, that a second run is a no-op, that order and unrelated keys survive, that a missing or non-array list is tolerated, and that both kind lists are walked. It is deleted with the migration.
- **Existing seams, updated and not extended:**
  - The registry gate (H16) for ids and order, the autoload gate (H2) for class resolution, the fixture manifest gate (H7) for fixture names.
  - The kind-list, term-rules-config, format-rules-config, term-dispatcher, collision-detector and apply-existing gates (H10 to H15) for the places they embed an old id.
  - The token-engine golden table (H17) for the format ids it embeds.
  - The whole `tests/*.php` loop must pass with no assertion weakened.
- **Behavior sweep on the local testbed, before and after.** Seed the rules fixture, run the existing sweeps, record results, apply the rename, run them again. Expectations are identical; only the type strings in setup differ. Post names must be checked after any restore, since the format pass can rename a post.
- **Real-data check on the cloned production site.** Swap the clone to this working copy with the dev-plugin script, confirm the upgrade rewrites its stored rows, and spot-check that its rules still apply. Roll back with the same script. No file is patched into the clone by hand.
- **Prior art:** the title/slug escalation migration and its harness; the fixture sweep library's snapshot, isolate, sweep, restore flow; the registry gate's hand-written expected order.

## Out of Scope

- Retiring the legacy `bws_taxonomy_manager*` prefix, including the daily cron hook: FW-64.
- The merge of propagation and related-post-terms and its row-shape migration: FW-61.
- FW-5's field copy, FW-8's user-based type, and the lock and edit-access additions: born on final ids in their own rows.
- Any change to a rule type's behavior, label, schema or stored fields other than the type string.
- Renaming helper classes, numbered sweep files, historic changelog entries, design-history documents or code comments.
- A read-time fallback accepting old ids.
- A bulk "re-save all rules" tool or any admin UI for the migration.

## Further Notes

- The migration's map table should be written so FW-61 can add entries and a row-shape step without changing its structure. The merge changes row shape, not just the id, so it is a separate step, not another map entry.
- If a cloned production site carries a rule type whose id is missing from the map, the unknown-type behavior is the safety net: the row is skipped and shown as unknown, never applied wrongly. The harness should include a row with an unregistered type to pin that the migration leaves it alone.
- The descriptor docblocks currently reference FW-63 as the reason the names are stale. Removing those references is part of the change, not a follow-up.
- FW-64 (the legacy prefix) deliberately waits: renaming the cron hook needs an unschedule-and-reschedule step, and folding it in would turn a mechanical rename into a change with persisted-state risk.
