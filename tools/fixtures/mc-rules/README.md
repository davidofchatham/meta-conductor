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
| `lookup.php` | Shared fixture-post lookup. Read its header before touching any post query here — see the trap below. |
| `resolve.php` | Shared rule token resolver (`{TERM:}` / `{TODAY±N}`). Used by both seed.php and sweep-lib.php so seed-time and restore-time rules never diverge. |
| `sweep-lib.php` | Behavior-sweep helper library (isolate / read / assert / restore without a full re-seed). See Sweep discipline. |
| `schema.php` | CPT/taxonomy registration + ACF groups. Loaded by mu-plugin stub seed.php installs. |
| `seed.php` | Idempotent applier. Order matters: schema → terms → posts → post fields → **rules last** (rules fire on save hooks; posts must land before rules exist). |
| `verify.php` | Post-seed smoke + negative-control assertions. Not a behavior-sweep replacement. |

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

### Seed order is load-bearing

`seed.php` **empties the MC rule arrays before writing any content** and
restores the baselines last. Every upsert fires `save_post` /
`set_object_terms` / `acf/save_post`; with a prior seed's rules live, handlers
would rewrite terms mid-seed and the result wouldn't match the manifest. The
storage request-cache is cleared on both sides of that window (the handlers
hold a `StorageFactory` instance from plugin boot, so a raw `update_option`
alone leaves them serving stale rules).

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
