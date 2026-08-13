---
status: accepted
amends: ADR 0002 decision 3
---

# The claim axis has four values, and jurisdiction is a first-class term

[ADR 0002](0002-cross-rule-composition.md) decision 3 declared a three-value **ownership** axis — *owning*, *contributing*, *restricting* — and filed the propagation config's `merge` and `skip` both as *contributing*. Reading the handlers against that definition falsifies it, and fixing it turns out to name a concept the model was missing.

## What was wrong

`Ownership` defined *contributing* as "the rule's values are **present**; it never removes". `skip` satisfies only the second half. [`TermOperations::apply_terms_to_post()`](../../includes/handlers/class-term-operations.php) writes under `skip` **only when `empty($existing_terms)`** — so on a post that already carries any term in the taxonomy, the rule's values are never applied at all. Propagation's own change-detector agrees: `case 'skip': return empty($current);`. Two behaviours were sharing one axis value.

Naming the axis **Ownership** was a second fault of the same kind that retired Temporal's `Mode`: three of the values are not ownership — *contributing* disclaims the rest of the target, *restricting* owns nothing and applies nothing — so one value wore the axis's name. CONTEXT.md's own definition already gave the correct genus: "the **claim** a rule makes on its effect target".

A third gap surfaced while separating them. Temporal is *owning* over its configured applied terms, so a manually-added "Sale" survives. Propagation `replace` is also *owning*, but over the **entire taxonomy** — `wp_set_object_terms($post_id, $term_ids, $taxonomy)` — so "Sale" is destroyed. Same axis value, wildly different blast radius, and the model had no way to state the difference. *Restricting* was even defined by borrowing **reach**, a term about which *targets* a rule touches, to say something about which *values within one* it polices.

## Decisions

1. **The axis is renamed `Ownership` → `Claim`.** Values: *owning*, *contributing*, *deferring*, *restricting*. The old name is added to CONTEXT.md's avoid-list.
2. **`skip` is reclassified from *contributing* to a fourth value, *deferring*** — applies its values only where the jurisdiction is vacant, and never removes. *Contributing* and *deferring* are separated by a **vacancy precondition**, not by their writes.
3. **`Jurisdiction` becomes a first-class term** — the values within one **effect target** that a rule governs, the set its claim ranges over. `phase term-space` is retained as Temporal's named instance of it. *Restricting* no longer borrows **reach** for its definition.
4. **Owning requires a statically enumerable jurisdiction.** A rule can only be owning over a value set its configuration names outright. A rule whose derivable set is data-dependent cannot own it without provenance, so it must widen its jurisdiction to the whole effect target or settle for *contributing*.
5. **Jurisdiction is derived from the claim, never authored alongside it.** No second control.

Storage is untouched: `conflict_handling` and its values `merge`/`replace`/`skip` stay exactly as stored. This is a vocabulary and UI change, so there is no migration and no in-use-rule-type question.

## Considered options

- **Keep three values, weaken *contributing* to "never removes"** — drop the presence guarantee so `skip` fits. Rejected: it makes one axis value cover two materially different post outcomes ("always present" vs "possibly never applied"), which is the exact fuzziness that produced the misfiling. It would also blind the collision detector, since a deferring rule contends far less than a contributing one.
- **Treat `skip` as an applicability guard, not a claim** — a **filter gate** tested on the effect target rather than the source, leaving the axis at three values. Genuinely arguable, and rejected on merit once the value count stopped being a constraint: *skip* answers the same question the other three answer — what to do about values already present — and it yields to them, which is a claim. Keeping it on the axis also preserves a 1:1 mapping to the three stored config values, so nothing migrates.
- **Split the config control into jurisdiction + claim** — let the author set "governs: only the terms it places / every term in the taxonomy" independently of the claim. Explored and rejected: of the six cells, *owning × only-the-terms-it-places* is impossible without provenance (decision 4), *deferring × only-the-terms-it-places* is meaningless because the vacancy test is over the whole taxonomy, and *contributing × every-term* is byte-identical in post state to the merge cell. The three live cells are exactly the three existing values, so the split adds a control the author must understand and no behaviour.
- **Rename `Reach` to be this axis and find a new word for reach** — rejected: the four values are stances (*owning*, *contributing*, …) so the axis noun must be a stance genus; "the rule's reach is contributing" does not parse. Making it work would need four value renames plus a new noun for the old reach, where **scope** is already banned as ambiguous.
- **Collapse the two vocabularies — make the canonical terms be `replace`/`merge`/`seed`** — maximal correlation by construction. Rejected because the axis must outlive terms: a scalar target cannot "merge", and *restricting* has no config word to borrow (it is fixed by rule type and appears in no dropdown), so one value would be minted abstractly anyway, leaving a mixed vocabulary.
- **`yielding` / `ceding` / `abstaining` for the fourth value** — *yielding* collides with `yield`-as-produce, which ADR 0002 itself uses ("propagation + hierarchical **yields** an extra expansion level"). *Abstaining* overstates: the rule does act, on a vacant jurisdiction. *Ceding* is unambiguous but rarer. **`deferring`** was chosen for legibility; its residual clash with `defer`-as-postpone is handled by a convention, not a rename — value names may be qualified in code comments as `// deferring-claim rule`.

## Consequences

- **Three documented oddities become consequences of decision 4 rather than separate cases.** Why #34's `merge`/`skip` never reconcile; why `replace` is blunt; why a **scalar** effect target is always a collision (its jurisdiction is the single slot, so any two rules writing it share all of it).
- **`HierarchicalHandler`'s `_bws_auto_terms` is now stateable.** It buys the forbidden cell — a narrow, data-dependent jurisdiction — with per-post provenance. It remains the single exception, but it can now be described in the model's own words instead of flagged as an anomaly.
- **The collision test gains a second conjunct.** Two rules contend where their **reaches** intersect on one effect target *and* their **jurisdictions** overlap. Disjoint jurisdictions on a shared target are independent. Temporal's **boundary-key disjointness** is an existing instance of this shape.
- **ADR 0002's deferred restricting sub-scope survives** — "governs levels 3–4 only" is statically enumerable, so it is a legitimate *declared* jurisdiction. Decision 5 constrains it to rule-type config, not a general claim modifier.
- **`owning` and `restricting` are siblings, not one-plus-an-outlier.** Removal is how a claim constrains what an editor can durably author; the two enforcing claims differ only in whether the rule names the one permitted set or a family of them. Both enforce *after the fact* — a manual value survives until the next **pass**, then is stripped — which the UI wording must not disguise as edit-time refusal.
- **UI labels lead with the domain word.** Two vocabularies are kept (a dropdown option and an axis value have different jobs), but correlated by construction: `Owning: only this rule's terms are allowed here; anything else is removed`. The former label said "Replace existing terms", which hid the jurisdiction widening — the most consequential thing the author could not see. This closes #34's deferred wording pass.
- **One vocabulary, one place.** `ConfigHelpers::CLAIM_NAMES` is the single source of truth: it builds both config dropdowns via `ConfigHelpers::claim_field()`, and `WireframeBootstrap::claim_label()` delegates to `claim_name()` for both row-title snapshots. Renaming a claim is a one-line change that cannot leave a surface stale. Locked by `tests/verify-config-helpers.php` (the option strings, the `<claim>: ` lead-in, the mapping) and `tests/verify-propagation-labels.php` (both row titles). Unlike `post_types_field()`, `claim_field()` deliberately does NOT force its `id` — the two surfaces genuinely store under different keys (`conflict_handling` and `mode`).
