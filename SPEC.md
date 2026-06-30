# SPEC — ACF-reference rework + Phase-3 (0.5.0, IN FLIGHT)

Status: **testing-to-merge**. Branch `claude/acf-reference-p3`, PR #24 open. NOT shipped — real-data
test sweep on athletics copy still failing (B8 found). Reopened lean (§V/§T/§B only) post premature
truncation. §G/§C/§I live in docs/architecture.md + handler PHPDoc. Truncate again on merge+tag.

## §V invariants (live; full text in PHPDoc — V1/V3–V17 on handler + storage)

Only the ones this test sweep touches or adds. Others stay in PHPDoc.

- V13 dependent written ONLY if ≥1 resolved source under some enabled rule. 0 sources ⇒ skip (leave terms). empty-replace permitted only when sources exist.
- V14 sever strip: source's relationship field drops a dependent ⇒ recompute that dependent while source still known. PUSH = old-vs-new capture in `capture_removed_dependents`. DELETE (both dir) = before_delete capture + deleted_post act.
- V17 pre-filter site-wide hooks BOTH directions (is_eligible_source_type / is_eligible_dependent_type) before reverse lookup.
- **V18 (NEW, B8). Pull-direction relationship-edit sever needs old-vs-new capture, symmetric to push.** A PULL rule's dependents = holders referencing the source. When the source's REVERSE relationship field drops a holder, recompute that holder while the source still knows it. `capture_removed_dependents` is PUSH-only (guard `holder_is_source`); a pull source-side edit removes the holder from the new graph, so `dependents_of_source` reads the new value and never recomputes the dropped holder ⇒ stale term survives. Capture removed holders by diffing the source's reverse field old-vs-new (acf/update_value), record under the holder-recompute set, recompute on save. Mirrors V14 push capture; the DELETE path already covers pull (before_delete PULL branch) — only the EDIT path was missing.

## §T tasks

| id | st | task | cites |
|----|----|------|-------|
| T1 | x | Pull-edit sever capture: `capture_removed_dependents` now matches both directions; `field_is_reverse_of` helper. Confirmed live: pull (reverse-field edit) + push (forward-field edit). | V18,V14 |
| T2 | x | Blocker tests ALL PASS. B8 pull-edit ✅ + push-edit ✅. #1 add-only sever = no strip ✅ (dual-rule fall-through accepted-by-inspection: empty-replace gated source_count===0, surviving add-only keeps count>0, capture skips add-only). #2 pull-delete-sever ✅: no-gate trash KEEPS term (source still resolves), empty-trash/permanent-delete STRIPS (before_delete capture + deleted_post). | V13,V14,V18 |

## §B bugs

| id | date | cause | fix |
|----|------|-------|-----|
| B8 | 2026-06-30 | Pull-direction relationship-edit sever unhandled. `capture_removed_dependents` guards `holder_is_source` (push-only); editing the source's reverse field to drop a holder leaves no capture. `sync_for_post(source)` → `dependents_of_source` reads the NEW graph, dropped holder absent, never recomputed → holder keeps stale pulled term. Self-corrected only when another live source of that holder was resaved. FIXED + live-confirmed (pull reverse-edit + push forward-edit). | V18 |
