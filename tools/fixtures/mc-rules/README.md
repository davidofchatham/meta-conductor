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

## Term tree (mc_topic)

```
Region › East › Coastal › Harbor   (4 levels — hierarchical/level-restriction)
Region › East › Inland
Region › West
Status › Featured                  (related/time-based targets — separate root)
Status › Archived
```

`mc_flag` (flat): `Priority` — taxonomy-trigger fixture for related_rules.
