---
status: accepted
supersedes: partially supersedes ADR 0002
---

# One ordered rule list per effect kind, executed by a central dispatcher

[ADR 0002](0002-cross-rule-composition.md) settled *what* cross-rule composition means — cascade suppressed, order as the composition semantics, three-value ownership, collisions detected not resolved. It deferred *how*, and it assumed a settings UI it never checked against. Inspecting the vendored WP Wireframe 1.0.6 turned two of its assumptions into hard constraints, and a pass over `docs/future-features.md` falsified a third.

We settle the implementation as four decisions:

1. **Rules are stored as one ordered list per effect kind**, not as seven type-keyed arrays. `term_rules` and `format_rules`, each row carrying its own `type`. Order is array position.
2. **Within-kind order is authored; cross-kind order is derived.** The engine topologically orders effect kinds (terms → title/field → rendered). The author orders only what is genuinely ambiguous.
3. **A central dispatcher per effect kind is the sole entry point to rule execution.** It owns the trigger hooks, iterates its list in order, and calls handlers as pure appliers. Any trigger runs a **full ordered pass** over the entity, each rule recomputing from live state.
4. **Re-entrancy is a pass-scoped lock keyed `(entity, effect-target kind)`**, replacing the four per-handler `private $processing` booleans and `related_post_terms`' taxonomy-scoped cascade guard.

## Why the constraints forced this

**Wireframe has no cross-repeater ordering primitive.** Sanitization and value resolution are strictly per-top-level-field (`Framework/Sanitizer.php`, `Settings::resolvedFor`); conditions resolve against a single flat scope, and inside a repeater that scope is the row alone (`Framework/Conditions.php`). Each page has its own `option_key` and REST route. So an ordered list spanning rule types *must* be one repeater — there is no other way to express it.

**Wireframe has no flexible content.** A repeater's subfield set is fixed for all rows (`Framework/Fields/RepeaterField.php`); the only per-row variation is `conditions` on a fixed superset. So one repeater means one subfield superset gated by a `type` select — which is tolerable across the six closely-related term types (they already share `enabled`, `taxonomy`, `post_types`, `post_status`) and intolerable across all ten. Hence: one repeater per effect *kind*, not one overall.

**Undeclared row keys do not survive.** `RepeaterField::sanitize` rebuilds each row from declared subfields only, and it runs *before* the `wp-wireframe/save/payload` filter. A stable `_id` injected post-hoc is discarded; the supported route is declaring `_id` as a real subfield. We take neither — with one ordered list, order *is* array position, and ADR 0002 already rejected per-rule provenance, so nothing needs stable identity today.

**ADR 0002's effect-kind partition claim is false.** It held that "nothing in the term group reads a title, so title/slug is a terminal sink", and inferred that a page boundary was a real interaction boundary. Both halves fail:

- `title_slug` **reads** terms (`{term:TAX}`, `{terms:TAX}` via `get_the_terms()`) and meta (`{meta:field}`). It writes no terms, so it is a sink — but a sink with inbound edges that must be ordered.
- `user_based` (UBT) **writes** terms — `wp_set_object_terms($post_id, $term_ids, $taxonomy, false)`, i.e. an *owning* claim — so the planned Personalize page would have hosted a second term-effect group on a different page.
- `field_transformation` will read terms through the same token engine; `acf_relationship_rules` writes `post_parent`, which propagation reads; status mirroring writes `post_status`, which several rules use as a **filter gate** input.

The correct generalisation is that these are **producer→consumer** edges — asymmetric and derivable — as distinct from ADR 0002's **collision** edges, which are symmetric and need an author. Collisions require the same effect target, hence the same kind; producer→consumer edges cross kinds. That is why ordering is authored within a kind and derived between kinds, and why a page boundary turns out to carry no semantics at all.

## Why the page split is not the answer it was framed as

ADR 0002 and ROADMAP Phase 4 treated splitting the settings page into four pages as the mechanism for shrinking save blast radius. It is not: the Auto-Set & Restrict page would host six of seven rule types today and ten of twelve eventually, so the hot blob stays one blob. Splitting further would have to cut *inside* the term group — precisely where interaction lives, and therefore the one place a storage boundary is harmful.

The blast-radius concern is met instead by a **version-token guard** on the option (already named as the cheaper alternative in ROADMAP Phase 4), which works at any page count. The cross-type clobber path was already dead: every handler writes through `OptionRuleStorage::save_rule()`, a per-type merge. Page count therefore reverts to a pure UX choice — one page, three tabs.

## Considered options

- **Typed repeaters plus a separate ordering list** referencing stable rule IDs. Rejected: Wireframe offers no reference or relationship field and no referential-integrity primitive, so the order list silently goes stale as rules are added, and it needs the same `_id` work anyway. A reference list with no integrity enforcement is a new silent-corruption surface, not a safer one.
- **One repeater for all rule types.** Rejected: no flexible content means a ~50-subfield superset spanning unrelated types, every type-specific subfield gated by a condition — and a condition-hidden subfield is *dropped server-side* at sanitize (`RepeaterField.php:35-39`). A wrong condition is silent data loss. Per-kind repeaters keep the superset small and the conditions few.
- **Keep the seven type-keyed arrays and add a parallel order index.** Rejected: needs stable identity the storage layer does not have (`id` is the per-type array index, re-derived on read, never persisted) and yields strictly more machinery for a weaker model.
- **Per-handler hooks with priority derived from list position.** Rejected for the reason ADR 0002 already gave against type-level ordering: a handler has one priority but may own several rules at different positions, so any list where two rules of one type straddle a rule of another is inexpressible.
- **Trigger-filtered passes** (run only rules whose own trigger fired). Rejected: a rule later in the list that would consume an earlier rule's write never runs, so the authored order buys nothing in exactly the cases it exists for.
- **Lock keyed `(entity, effect target)` rather than `(entity, effect-target kind)`** — i.e. per taxonomy rather than per kind. Rejected: `related` rules are cross-taxonomy by construction, so a write to taxonomy B from a pass locked on taxonomy A starts a nested pass, re-running the whole list. This reintroduces cascade as a second composition mechanism competing with order, makes termination depend on the taxonomy graph being acyclic, and silently repairs a consumer-before-producer ordering mistake instead of surfacing it. Note this keying is what `related_post_terms` does today (`class-related-post-terms-handler.php:476-478`); the pass lock subsumes it, and the cross-taxonomy effect still occurs — by sequence within the pass rather than by re-entry.
- **Retaining the per-handler guards as defence-in-depth** alongside the pass lock. Rejected: a stale guard silently suppressing a legitimate rule is exactly the class of defect #35 was.

## Consequences

- **`related_post_terms` and `related` are live on a real site**, so the 7-arrays→2-lists change is a breaking schema change under the CLAUDE.md live-rule-type rule. It ships as a flag-gated one-time migration on admin load **plus** a read-time adapter — `WireframeBootstrap::boot` is gated to admin and REST, while handlers read storage on front-end and cron requests.
- **Handlers stop owning hooks.** This inverts handler-authoring invariant (a) — that hook-into-terms handlers register their own hooks and no-op `process_post()`. The applier seam already exists: `apply_to_post(int, array): bool`, added for #31 in 0.7.0.
- **Ordering is honoured on every path**, including bulk re-apply and cron, because the dispatcher is the sole entry. Previously those paths executed in handler-map order.
- **Both latent instantiation-order dependencies die**: propagation-before-hierarchical (flagged by ADR 0002) and `TitleSlugHandler` being constructed last, which is the only thing currently ordering term writes before title reads.
- **The collision detector is no longer coupled to the ordering UI.** ADR 0002 had components decide which rules get ordering control; with the repeater *being* the ordering UI, every rule is orderable unconditionally. The detector becomes purely advisory, so it ships as a minimal pairwise warning (shared taxonomy plus post-type overlap) and the full reach/component analysis is deferred until the non-term effect kinds are real enough to define reach once.
- **The derived cross-kind order is valid only while the kind graph is acyclic.** Today it is. A future rule that sets terms *from* a title would close a cycle that no ordering resolves; that must be a hard warning, not a silent pick.
- **The format dispatcher will need two phases.** `title_slug` runs on `wp_insert_post_data` (pre-write); `field_transformation` is specced for `acf/save_post` priority 20 (post-write). One dispatcher per kind holds today and splits when field rules land.
- **Changing a row's `type` discards its type-specific subfields**, because they become condition-hidden and are dropped at sanitize. This is correct — a different type is a different rule — but it is silent, so the UI should say so.
- **Rule-type renaming stays deferred**, per ADR 0002. The `type` select surfaces the current names; they are still composed from conflated axes and are still best minted once the Effect axis has settled.
