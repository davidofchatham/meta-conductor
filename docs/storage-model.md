# Storage Model — Options vs CPT

**Source of truth + working doc for how Meta Conductor stores rules.** Tracked (human-facing). The ROADMAP "Storage Model Decision Framework" points here.

Run every new rule type through this before implementing it. Record the decision + reasoning in the [Assignments](#assignments) table.

---

## The two stores

| | Options | CPT (`bws_mc_rule`-style) |
|---|---|---|
| Shape | rules nested in one serialized array under one `wp_options` row (per Wireframe page — see [storage boundary](#the-storage-boundary-is-the-wireframe-page)) | one post per rule, `rule_type` meta differentiates |
| Read | single autoloaded option hit — fast at low N, *faster* than CPT at low N | `WP_Query` / `get_posts`, paginates; must cache to beat options at low N |
| Write | **read-modify-write the whole blob** every save | one `wp_update_post`; siblings untouched |
| List UI | custom (Wireframe panel) | native list table free (filter by `rule_type`) |
| Edit UI | Wireframe panel | classic editor metaboxes free — **but** drags in Publish box / author / date / trash chrome |
| Concurrency | last-write-wins; **silent clobber** on overlapping saves | per-row isolation + free post-edit lock (`wp_set_post_lock`) |
| Per-rule metadata | hand-rolled | author / date / revisions / status free |

---

## Decision criteria

Choose **CPT** if any of these hold:
- Rules of this type are **authored concurrently by multiple people** (options risks silent lost-update clobber — see [concurrency](#concurrency--lost-update-clobber)).
- Rules **accumulate unboundedly** as the site grows — not bounded by taxonomy/post-type count — **AND** the varying data can't be pushed onto an entity (see [the indirection escape hatch](#the-indirection-escape-hatch)).
- Rules genuinely need a **draft/test/active lifecycle**, per-rule author/date, or individual URLs.

Choose **Options** if all of these hold:
- Single admin (or few, serial) authors the rules — no concurrent writes.
- Count is **bounded** — roughly one rule per taxonomy/post type, OR unbounded data lives in entity meta via an indirection rule.
- The rule's per-rule chrome (author, date, trash, standalone edit URL) is **unwanted**.
- A Wireframe panel listing the rules never becomes unwieldy.

**The deciding axis is concurrency + write pattern, NOT raw rule count.** A single serial author is safe on options well into the low thousands. Multiple concurrent authors are at risk even at low count.

---

## The storage boundary is the Wireframe page

Wireframe binds **one `option_key` per page** (`Wireframe\App::boot(... 'pages' => [{ 'option_key' => … }])`). Consequences:

- **Tabs are cosmetic.** All tabs on one page share one option and one save. Today all 5 tabs = one `bws_meta_conductor_settings` blob.
- **One save rewrites that page's entire blob** — all rule types on the page. Two admins editing *different* types on the same page still clobber each other.
- **The page is the unit of storage choice.** A rule type can't quietly defect to CPT mid-page — Wireframe submits the whole-page array in one REST write. Mixing stores within a page means splitting that one submit into "options write + CPT reconcile."
- **To split storage, split pages** (`option_key` per page) — see [config-pages-split plan](../.claude/plans/config-pages-split.md). Splitting tabs→pages shrinks the clobber blast radius and lets each page pick its store independently, **whether or not** any page goes CPT.

### CPT-under-Wireframe reconcile cost

Wireframe saves the whole page array (one REST submit). To persist a page into CPT you must **diff that array against existing posts → insert new / update changed / delete removed**, every save. Options just `update_option(blob)`. CPT save path = N post writes + a reconcile pass — contained in the storage impl, but real.

---

## Concurrency — lost-update clobber

The options failure mode that matters is **not** slowness — it's **silent data loss** when two saves overlap (read blob A, read blob A, write A+x, write A+y → x lost). Fires only with **concurrent rule-authoring.**

### Standard preventions (weakest → strongest)

1. **Optimistic / version token** — store `_rev` in the blob; `save` checks the rev it loaded still matches, bumps on write, rejects stale → "reload, someone changed this." ~20 lines; degrades to a no-op when writers never collide. **The cheap insurance that keeps options.**
2. **Compare-and-swap** — write only if current stored value equals what was read. (WP `update_option` does NOT do this; wrap it.)
3. **ETag / If-Match at REST** — repeater submits the version it GET'd; server `412`s on mismatch. Needs Wireframe REST cooperation (limited client API — may not be extensible).
4. **Pessimistic lock** — lock-on-edit-open. **Free with CPT** (`wp_set_post_lock` + heartbeat "X is editing this"). Options have no equivalent; you'd build it.
5. **DB row lock / `SELECT … FOR UPDATE`** — serializes writers at the DB; only helps if data is per-row (CPT / custom table), not a single blob.
6. **Atomic per-row writes** — don't read-modify-write a blob at all; write only the changed row. This IS CPT / custom table. The blob shape is what *forces* the read-modify-write window.
7. **Single-writer / serialize** — funnel writes through one app-level lock.

**Today: single admin → no concurrent writers → none needed.** Version token (1) is the graceful upgrade if multi-author editing ever appears, no CPT required. Page-split (above) further shrinks the window by isolating each page's blob.

---

## The indirection escape hatch

When rules look like they'll **accumulate per-entity** (one rule per user, per author, per …), that's usually **data masquerading as config**. Don't mint N near-identical rules (`user:X → term:Y` × 24) — neither N options-array entries nor N CPT posts.

Instead:
- Put the varying value on the **entity** (user meta / ACF profile field, post meta).
- Keep **one indirection rule**: *"apply the value in each entity's field `X`."*

Result: rule count stays **O(1) in entities**; the per-entity data scales natively in `wp_usermeta` / `wp_postmeta` (indexed, one row per entity, no blob, no clobber). This dissolves the "unbounded accumulation → CPT" trigger for the whole class of per-entity rules. See [UBT merger plan](../.claude/plans/ubt-merger.md) for the worked example (per-user default terms).

---

## Crossover reference (single-author assumption)

| Rules per type | Options | CPT |
|---|---|---|
| < ~50 | fine | overkill |
| 50–300 | blob rewrite noticeable; concurrency risk **if** multiple editors | comfortable |
| 300+ | blob + autoload pain | clear win |

Page-split pushes the options ceiling further out (smaller per-page blobs, isolated saves).

---

## Assignments

> Document the storage decision + reasoning here before implementing any new rule type.

| Rule Type | Storage | Reasoning |
|-----------|---------|-----------|
| `hierarchical_rules` | Options | Behavior toggle per taxonomy; bounded; single author |
| `propagation_rules` | Options | Behavior toggle per post type; bounded |
| `related_rules` | Options | Cross-taxonomy config; bounded |
| `hierarchical_level_restriction_rules` | Options | One per taxonomy max |
| `related_post_terms_rules` | Options | ACF sync config; bounded |
| `acf_relationship_rules` (new) | Options | Parent/child relationship config; bounded |
| `title_slug_rules` | Options *(reassess)* | Named patterns per post type; *can* accumulate, but single-author + bounded-in-practice. Prior ROADMAP marked CPT/Phase-4 — **re-open**: no concurrent authoring, page-split covers blast radius. CPT only if a real draft/test lifecycle is wanted. |
| `time_based_rules` | Options *(reassess)* | Schedule rules *can* multiply, but single-author. Same re-open as title_slug. |
| `field_transformation_rules` (new) | TBD | Named computed-field recipes; could be numerous. Run the criteria when designed — likely Options + indirection unless a per-recipe lifecycle is needed. |
| `user_based_rules` (UBT) | **Options** | **Changed from CPT.** Role/user = *target*, not owner → single author, no concurrent writes. Per-user explosion solved by [indirection](#the-indirection-escape-hatch) (profile field + one rule), not N rules. CPT's entity chrome (author/date/trash) explicitly unwanted. Wireframe panel preferred over CPT editor. See [UBT merger plan](../.claude/plans/ubt-merger.md). |

---

## Change log

- **2026-06-23** — Initial doc. Reassessed UBT (CPT→Options) and flagged title_slug/time_based for re-open, based on: target-not-owner access pattern, indirection escape hatch, Wireframe page = storage boundary, page-split as cheap write-isolation. Supersedes the inline ROADMAP framework (now a pointer).
