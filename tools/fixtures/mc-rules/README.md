# mc-rules blueprint

Fixture blueprint for meta-conductor behavioral testing on the local
wp-litespeed testbed. **Composes on** the GBDTE `core-structures` blueprint
(pins manifest `version: 4`) — seed core-structures first, then this.

Requirements source: [`../handler-fixture-matrix.md`](../handler-fixture-matrix.md).

## Composition contract

- Never redefines core-structures `defines` keys (post_types `staff`,
  taxonomy `department`, `group_bwsfx_*` ACF groups, registered meta, users).
- MC-owned namespace: CPTs `mc_item` / `mc_section`, taxonomies `mc_topic` /
  `mc_flag`, ACF group `group_mc_fields`, option keys inside
  `bws_meta_conductor_settings` only.
- core-structures content serves as **negative controls** — see verify.php.
- **Isolation invariant: every seeded rule pins `post_types` to mc_* types.**
  Empty `post_types` on a propagation rule resolves to ALL hierarchical public
  types (incl. `page`) and would rewrite GBDTE matrix pages. Never seed that.

## Files

| File | Role |
|---|---|
| `manifest.php` | Data contract — terms tree, posts, ACF values, rule baselines. Consumers pin `version`. |
| `lookup.php` | Shared fixture-post lookup. Read its header before touching any post query here — see the trap below. Two status sets: `mc_fixture_post_statuses()` (upsert, no trash) vs `mc_fixture_readable_statuses()` (reads of posts another blueprint owns, trash included). |
| `resolve.php` | Shared rule token resolver (`{TERM:}` / `{TODAY±N}`). Used by both seed.php and sweep-lib.php so seed-time and restore-time rules never diverge. |
| `sweep-lib.php` | Behavior-sweep helper library (isolate / read / assert / restore without a full re-seed). See Sweep discipline. |
| `schema.php` | CPT/taxonomy registration + ACF groups. Loaded by mu-plugin stub seed.php installs. |
| `seed.php` | Idempotent applier. Order matters: schema → terms → posts → post fields → **rules last** (rules fire on save hooks; posts must land before rules exist). |
| `verify.php` | Post-seed smoke + negative-control assertions. Not a behavior-sweep replacement, with one exception: **A7 is behavioural and MUTATES** — it drives the time_based cron cleanup through the handler and restores. Safe to re-run against a seeded site; requires `item-solo-a` at seed state. See matrix §6e-bis. |
| `sweep-related-post-terms-sever.php` | §4 sever + write-queue sweep (#42/#43), still the regression suite for this handler after the #63 conversion. Stepped, one step per eval — the #42 flush runs on `shutdown`, so a bare `update_field()` can only be asserted in a later request. Read its header before running: step `s7` fails by design, and `s9` drains explicitly because the pass is no longer synchronous with the delete. |
| `sweep-58-roundtrip.php` | #58 dynamic sweep: admin-load storage sequence, then every stored term-rule row through Wireframe's real `RepeaterField::sanitize` — asserts no value a live rule type reads is dropped by the unified repeater's gates. |
| `sweep-59-roundtrip.php` | #59 dynamic sweep, format kind: admin-load sequence, then every stored `format_rules` row through the real `RepeaterField::sanitize` — asserts the title/slug rule survives the move to the ordered repeater with its patterns and slug mode intact. |
| `sweep-60-dispatch.php` | #60 dispatcher behaviour sweep (Phase 4 Gate 2). Stepped: `order` proves swapping two rules' list positions changes the result, `provoke` proves a term write, a post save and bulk apply all land on the same state, `s1`+`s2` are a two-eval pair proving the `shutdown` drain runs the pass. **Run `restore` when done** — it drops the hand-authored `term_rules` list as well as rebuilding the rule arrays. Saves no post, so the §7 rename trap does not apply. |
| `sweep-61-appliers.php` | #61 behaviour sweep: `time_based` + `related` as pure appliers. Stepped — `related` covers the native and ACF trigger paths plus both bidirectional removal cases (including the live-state one that is a deliberate behaviour change), `cron` proves the daily sweep drains a FULL ORDERED PASS in which a later rule consumes a date-window rule's write, `cron-swap` is its order control, `publish` proves a publish transition still provokes the date-window path with no explicit drain, and `ghost-setup` + `ghost` (#52 — two evals, since `register_taxonomy()` is request-scoped and the term row is not) prove a rule pointing at a term whose taxonomy is no longer registered is rejected by validation instead of validating clean off a truthy `WP_Error`. **Run `restore` when done** — it drops the hand-authored `term_rules` list, republishes `item-solo-a`, clears the ACF field it wrote and deletes the ghost term. |
| `sweep-62-propagation.php` | #62 behaviour sweep: `propagation` as a PULL applier + declared fan-out. Stepped — `claim` covers the three claims incl. the empty-parent case that must NOT strip a child's own terms, `pull` proves a parent's terms reach a multi-level chain (publish + draft) and that each level gets **exactly one** pass, counted off the `meta_conductor_term_pass_enabled` filter; `remove` (run it after `pull`) proves a `wp_remove_object_terms` on the grandparent still reaches both levels, which is the case the capture hook exists for; `order` + `order-swap` are the #35 pair — propagation above hierarchical yields one extra expansion level on the child, hierarchical above propagation yields none. **Run `restore` when done** — it drops the hand-authored `term_rules` list and clears the four `mc_section` chain posts. Saves no post, so the §7 rename trap does not apply. |
| `sweep-63-acf-reference.php` | #63 behaviour sweep: `related_post_terms` as a per-rule applier. Stepped — `order` / `order-swap` are the headline pair (two ACF-reference rows straddling a `related` rule produce different results by position, which was inexpressible while the type held one slot in the list), `sever-a`/`sever-b` prove a sever still withdraws AND that the rule after it runs against the withdrawn state, `bare-a`/`bare-b`/`bare-c` prove a BARE `update_field()` sever now reconciles (the pre-#63 KNOWN LIMIT), `pull` covers `holder_role=target` + the tier-3 reverse lookup reached only through `fan_out()`, and `cross-a`/`cross-b` prove a sever licenses ONLY the row whose link was cut to bypass its zero-source gate (with a taxonomy-keyed record an unrelated row empties the taxonomy over an earlier row's write). **Every sever step runs in its own eval** — the capture path's request-lifetime rule memo is filled with the empty set by the staging step's silenced writes. **Run `restore` when done** — it puts both holders' relationship fields back, resets holder terms and drops the authored list. |
| `sweep-64-format.php` | #64 behaviour sweep: `title_slug` as a FORMAT applier, run from the term dispatcher's drain. Stepped — `terms` is the headline (a `{term:mc_topic}` title token picks up an ancestor the hierarchy rule wrote in the SAME pass: `…Coastal`, where the pre-#64 ordering would have produced the equally plausible `…Harbor`), `provoke` proves an editor save, an ACF save and a bare term write all land on the same title, `bulk` proves bulk apply runs the format pass (armed with term state already settled — bulk is deliberately per-kind), `idempotent` proves a second pass changes nothing and that `_bws_raw_title` holds the BASE title rather than the applied one, `s1`+`s2` prove the `shutdown` drain runs the format pass. **⚠️ This sweep RENAMES posts** (§7 gotcha): the subject's ID is cached in the `mc64_subject_id` option, and `bulk` snapshots and repairs every other `mc_item`. **Run `restore` when done** — it renames the subject back from the manifest, clears the idempotency meta and drops both kind lists. |
| `sweep-65-collisions.php` | #65 behaviour sweep: the collision advisory, both surfaces. Stepped — `seeded` scans the REAL fixture rule set through `get_kind_rules()` and pins all eight findings by list position (including the fixture's two ready-made cases: the #51 hierarchy/level-restriction contradiction, NAMED, and the #69 date-window pair on `topic-archived`), `config` is the #39 configuration plus its two clears (disjoint post type, disjoint taxonomy) and the currency control (sliding a date window does NOT clear a warning; re-targeting does), `surfaces` fires the REAL `bws-meta-conductor/settings_saved` action and the REAL `…/action/settings/{kind}_collision_check/run` filter and proves the on-demand check persists nothing. **Writes no term and saves no post** — that is §65h, and it means the §7 rename trap does not apply. `restore` rebuilds the rule lists and deletes the findings option. |
| `sweep-59-behaviour.php` | #59 behaviour sweep: authors two title/slug rules on one post type through the FULL save path (sanitize → row-title snapshot → projection), proves reordering them swaps which one the handler applies, and resolves `{meta:}` / `{term:}` / `{terms:}` / `{pub_*}` against a real post. **Saves no post**, so it renames nothing and the §7 restore gotcha does not apply; restores the settings option in a `finally`. |
| `sweep-67-cross-order.php` | #67 behaviour sweep (Phase 4 Gate 3): `hierarchical` vs `level_restriction`, both list orders — the second instance of the #35/§62c-d shape, and the runtime proof for H14's static `ancestors_stripped` (#51) finding. Stepped — `order` (level_restriction then hierarchical: full ancestor lineage survives) / `order-swap` (hierarchical then level_restriction: ancestors stripped) / `restore`. Clears BOTH the native terms AND the `mc_topics` ACF mirror on `item-solo-a` before each step — a stale mirror value silently changed which term a prune kept, with no error. |
| `sweep-67-post-status.php` | #67 behaviour sweep (Phase 4 Gate 3, closing #23): the `post_status` gate actually skipping and then firing, not just existing in config. Stepped — `standard` (the `should_process_post()` shape, live-tested on `HierarchicalHandler`, code-confirmed identical on level_restriction/propagation/time_based/related) / `source-gate` (`RelatedPostTermsHandler`'s exception — gates the SOURCE post, not the dependent) / `restore`. **`restore` re-seeds** — it empties `section-holder`'s relationship field, which is post data `mc_restore()` does not touch. |

## Seeding

Prereqs: core-structures already seeded (needs ACF Pro active; GB Pro not
required for MC), meta-conductor plugin active on the site.

Check the manifest statically first — no WP needed, catches dangling slugs and
isolation violations before anything touches a site:

```bash
php tests/verify-fixture-manifest.php     # H7
```

Then seed and smoke-test:

```bash
bin/wp.sh <site> eval-file <mc-repo-path>/tools/fixtures/mc-rules/seed.php
bin/wp.sh <site> eval-file <mc-repo-path>/tools/fixtures/mc-rules/verify.php
```

### Never look up a fixture post with `get_posts( name=..., 'any' )`

That shape returns **nothing** for a non-published post when run
unauthenticated, which is how WP-CLI runs. It made the seeder blind to the
existing `mc-draft-child` draft, so every run inserted another copy — four
accumulated before the failure surfaced.

It is not the status SQL. The query does match the drafts and the DB returns
them; `WP_Query` then discards them *after* the query in the single-post
permission re-check (`class-wp-query.php` ~3509–3525): `name` sets
`is_single`, which arms that block, and `post_status => 'any'` leaves
`$q_status` as the literal `['any']` so the "specifically requested" escape
hatch never matches a draft. Non-public status + logged-out ⇒ results wiped.
Verified by flipping only the auth state: `[]` at uid=0, four rows at uid=1.

Use `mc_fixture_find_post()` from `lookup.php`. It queries by `post_name__in`
(which never sets `is_single`) with explicit statuses, and returns the *oldest*
match so the seeder converges on the surviving post where duplicates exist.

`verify.php` asserts exactly one post per fixture slug, so any regression here
fails loudly instead of growing silently.

Those statuses exclude `trash` by design, which makes B3 fail on
core-structures' deliberately trashed `gate-trashed` fixture —
[#70](https://github.com/davidofchatham/meta-conductor/issues/70).

### Seed order is load-bearing

`seed.php` **empties the MC rule arrays before writing any content** and
restores the baselines last. Every upsert fires `save_post` /
`set_object_terms` / `acf/save_post`; with a prior seed's rules live, handlers
would rewrite terms mid-seed and the result wouldn't match the manifest. The
storage request-cache is cleared on both sides of that window (the handlers
hold a `StorageFactory` instance from plugin boot, so a raw `update_option`
alone leaves them serving stale rules).

### …and the ACF write queue must stand down for it

As of 0.7.0 rules can also fire *after* a content write, not only during one:
`AcfWriteQueue` records every `update_field()` and applies the handlers on
`shutdown` — which lands after the seeder has restored the rules. Seeding
`item-alpha`'s `mc_event_date` would then trip the title_slug rule and rename the
post out from under the next run's lookup, growing duplicates. `seed.php`
therefore filters `meta_conductor_acf_reapply_enabled` to false for the whole
seed. Any other script that writes ACF fields with rules deliberately disabled
must do the same. (#42)

## Sweep discipline

MC tests mutate term state by design. Reseed is additive and does NOT reset
mutated terms. Cycle: **snapshot → seed → sweep → restore**. Never trust a
reseed to clean up after a behavior sweep.

Cron scenarios: `bin/wp.sh <site> cron event run bws_taxonomy_manager_cleanup`.

### sweep-lib.php — helper library

`sweep-lib.php` collapses the per-eval boilerplate. Load it at the top of a
sweep eval:

```php
require_once '<mount>/tools/fixtures/mc-rules/sweep-lib.php';
mc_isolate( 'hierarchical_rules' );                 // empty every OTHER rule type + clear cache
$solo = mc_pid( 'item-solo-a' );                    // fixture slug → live post ID (draft-safe)
mc_reset_subject( $solo );                           // clear terms + _bws_auto_terms
wp_set_object_terms( $solo, array( mc_tid( 'topic-harbor' ) ), 'mc_topic' );
mc_assert( '§1a', mc_terms( $solo ),                 // sorted, order-insensitive PASS/FAIL
    array( mc_tid('topic-region'), mc_tid('topic-east'), mc_tid('topic-coastal'), mc_tid('topic-harbor') ) );
mc_restore( array( $solo ) );                        // rebuild ALL rules from manifest + reset the subject
```

Helpers: `mc_isolate($keep)`, `mc_restore($reset_ids=[])`, `mc_reset_subject($id)`,
`mc_pid($slug)`, `mc_tid($slug)`, `mc_terms($id,$tax)`, `mc_acf($id,$key)`,
`mc_assert($label,$got,$want)`.

**`mc_restore()` avoids a full re-seed** — it rewrites the rule arrays
(token-resolved, shared with seed.php via `resolve.php`) and resets named
subjects, skipping the post upserts + rewrite flush. Fall back to the full
`seed.php` ONLY when a sweep deleted a post (§4c delete-holder) or renamed one
(§7 title_slug — restore the `post_name` first, else the by-name lookup
duplicates it). What sweep-lib does NOT change: isolation is still required
(handlers hook at boot), and handler dedup is still per-request (one user-edit
per eval).

## Term tree (mc_topic)

```
Region › East › Coastal › Harbor   (4 levels — hierarchical/level-restriction)
Region › East › Inland
Region › West
Status › Featured                  (related/time-based targets — separate root)
Status › Archived
```

`mc_flag` (flat): `Priority` — taxonomy-trigger fixture for related_rules.
