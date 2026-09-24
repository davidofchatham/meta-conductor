# Meta Conductor

A WordPress plugin that applies taxonomy terms (and, later, other effects) to posts, terms, and users according to configured **rules**. A rule does something, or restricts something, based on a relationship or a condition — each **rule type** is one shape of that, and the axes below (**basis**, **effect target**, **claim**) are what distinguish them.

This glossary defines the domain language. It is not a spec — it says what terms *mean*, not how they are implemented.

## Language

### Core

**Rule**:
A single configured unit of behaviour belonging to a **rule type**. Reads as five things: a **filter gate** (which entities it considers), a **basis** (what supplies its input), an **effect target** (what it writes), a **jurisdiction** (which values within that target it governs), and a **claim** (what it does about values already there).
_Avoid_: setting, config entry.

**Rule type**:
A named family of rules with shared structure and a dedicated handler (e.g. Temporal Rule, Title/Slug, Hierarchical).

**Handler**:
The code that processes all rules of one rule type against entities.

**Entity**:
A post, term, or user a rule acts on. The dispatchers queue and pass over entities; today every entity a pass reaches is a post.

**Filter gate**:
A rule's optional restriction selecting which posts it considers at all — its **source**. Today: post type + **post status** + taxonomy/term filters, plus — for a Temporal rule with a **field**-source boundary — an automatic **boundary-presence clause** (the post must have the boundary's meta key set; an `EXISTS`-on-key condition). The post-status filter selects which statuses a rule considers (e.g. only `publish`, or `publish`+`future`); empty = all. It is a *gate*, distinct from a future "set post status" *effect* — one decides whether the rule looks at a post, the other would change the post's status. Independent of the rule's main logic — a post must pass the filter gate *and* the rule's own logic to be acted on. The boundary-presence clause does double duty: it is the same `meta_query` the cron sweep uses to find candidate posts for that field, and it is what makes two Temporal rules reading *different* boundary keys provably disjoint (see **Boundary-key disjointness**).
_Avoid_: filter, scope (say **reach** for what a rule can write).

**Basis**:
What supplies a rule's input, as a *set* of kinds — a rule may draw on more than one:

- **Relation** — the rule follows an edge to *other* entities or terms: post hierarchy, term hierarchy, an ACF relationship field, configured term pairs, post→author. A relation is **natural** (a structure WordPress already maintains — post parent, term parent) or **authored** (a link someone configured — an ACF relationship, a term pairing). The natural/authored distinction applies *only* to relations.
- **Intrinsic** — the rule reads the entity's own data: a field value, a title, a post status, a date field.
- **Ambient** — the rule reads request or session context that is attached to no entity and sits on no graph: the acting user and their role, or "now".

Two properties are derived from basis rather than declared per rule type:

- **Ambient basis requires a sweep.** An ambient value moves without the entity changing, so no save fires and nothing is re-evaluated on its own. This is why Temporal needs cron (see **Sweep cadence**) — it compares an intrinsic boundary against an ambient "now". Relation and intrinsic bases are event-driven. A rule whose ambient value only matters at the instant of a write (user-based rules read the acting user) still fires on save.
- **Only a relation basis gives cross-entity reach.** Intrinsic and ambient rules always write the entity in hand, so their **reach** is exactly their **filter gate**.

_Avoid_: condition, relationship (as an umbrella — *relation* is one basis kind, not the axis).

**Effect target**:
What a rule writes, as *(entity, target)* — today "terms in taxonomy T on post P"; later a field, a title, a body class, a field's editability. A target is **set-valued** (terms) or **scalar** (a field, a title). Set-valued targets can compose — several rules contributing to one set is meaningful. Scalar targets cannot: two rules writing one scalar can only be last-writer-wins, so they are always a **collision**.

**Effect kind**:
The class an **effect target** belongs to, ignoring which particular taxonomy, field or key is written: *terms*, *title/slug*, *field*, *post parent*, *post status*, *term vocabulary*, *rendered*. Coarser than effect target — "terms in taxonomy A" and "terms in taxonomy B" are two targets of one kind. The kind is the unit that matters for composition: a **collision** requires the same target and therefore the same kind, while a **dependency** runs *between* kinds. Rules are grouped, ordered and evaluated per kind.
_Avoid_: effect type (reads as the rule type), target class.

**Dependency**:
A directed edge from a rule that *writes* something to a rule that *reads* it — a term rule feeding a title rule's `{term:…}` token; a rule setting post parent that a hierarchy rule then walks; a rule setting post status that another rule's **filter gate** tests. Asymmetric, and unlike a **collision** it has a right answer: the producer runs first. Dependencies run **between effect kinds**, so they are derived rather than authored — the kinds are evaluated in a fixed order (terms → title/field → rendered) and the author is never asked. This holds only while the kind graph is **acyclic**; a rule that derived terms *from* a title would close a cycle that no ordering resolves, and must be refused rather than silently ordered.
_Avoid_: collision (that is the symmetric case), cascade (that is a write re-triggering rules, which is suppressed).

**Claim**:
What a rule does about values already present in its **jurisdiction**:

- **owning** — the jurisdiction's value *equals* what the rule derives. Any other value in the jurisdiction is removed, whether the rule placed it, another actor did, or it predates the rule. Values outside the jurisdiction are never touched.
- **contributing** — the rule's values are *present*; it never removes. A value that stops being derivable simply stays. This is the contract, not a defect.
- **deferring** — the rule applies its values only where the jurisdiction is *vacant*; if anything is already there it stands down entirely. It never removes.
- **restricting** — the rule applies nothing; it requires the jurisdiction to *satisfy a constraint*, and prunes whatever violates it regardless of which rule wrote what.

Removal is how a claim constrains what an editor can *durably* author, so the four split two-by-two. **owning** and **restricting** are the **enforcing** claims — under either, a manually-added value survives until the next **pass** and is then stripped — differing only in whether the rule names the one permitted set (owning) or a family of permitted sets (restricting). **contributing** and **deferring** enforce nothing: both add and neither removes, and they are separated not by what they write but by a **vacancy precondition** — a contributing rule's values are always present, a deferring rule's may never be applied at all.

A claim is settled by config and live recomputation, never by provenance: no rule records what it applied. One exception exists in code — see **Jurisdiction**. See ADR 0001, ADR 0002 and ADR 0004.
_Avoid_: ownership (retired — it named the axis after one of its own values, the fault that also retired *mode*), provenance (explicitly not tracked), mode (that is **overlap**), conflict handling (the storage key's name, not the concept's).

**Jurisdiction**:
The values within one **effect target** that a rule governs — the set its **claim** ranges over. Two rules sharing an effect target still do not contend if their jurisdictions are disjoint.

Jurisdiction is **derived, never authored alongside the claim**: choosing a claim is what widens or narrows it, so it is not a second control. (A *rule type* may still declare a narrower jurisdiction in its own config wherever that jurisdiction is statically enumerable — e.g. a level-restriction rule governing levels 3–4 only. That is a rule-type feature, not a general claim modifier.)

**Owning requires a statically enumerable jurisdiction.** A rule can only be *owning* over a value set its configuration names outright: Temporal's configured applied terms, level-restriction's whole taxonomy, a scalar target's single slot. A rule whose derivable set is data-dependent — post-hierarchy propagation's "the parent's terms" — cannot be owning over it without recording what it applied, so it must either widen its jurisdiction to the *whole* effect target or settle for *contributing*. This is why propagation's `replace` is blunt where Temporal's owning is not: both are owning, over jurisdictions of very different size. It is also why a **scalar** effect target is always a **collision** — its jurisdiction is the single slot, so any two rules writing it share the whole of it.

One rule type buys the forbidden cell with provenance: `HierarchicalHandler` records the terms it added in post meta, giving it a narrow, per-post, provenance-derived jurisdiction. It is the single exception, not a precedent.
_Avoid_: scope (ambiguous — see **reach** and **filter gate**), value space, term-space (superseded; *phase term-space* survives as Temporal's named instance).

**Reach**:
The statically-known post types × taxonomies a rule reads and writes. Distinct from **filter gate**: the gate says which entities a rule *looks at*, reach says what it can *touch* — a relation-basis rule writes entities its gate never selected (post ancestry writes descendants; post relationship writes related posts). Reach is computed at post-*type* granularity, not post ID: which descendants or related posts exist is data-dependent and not statically decidable, so reach is deliberately conservative — it over-reports interaction and never under-reports it. Distinct again from **jurisdiction**: reach says which *targets* a rule can touch, jurisdiction says which *values* within one of them it governs.
_Avoid_: scope (ambiguous with filter gate).

**Pass**:
One evaluation of a rule list against one entity: every rule of that **effect kind**, in **order**, each recomputing from live state and doing nothing if nothing changed. A pass is what any trigger starts — a save, an ACF write, a bulk re-apply, a cron sweep — and it is the same pass in every case, so a rule list produces the same result however it was provoked. Rules earlier in a pass are visible to later ones because each reads live state, which is what makes **order** the composition mechanism.
_Avoid_: run, cycle (a cycle is a defect in the kind graph — see **Dependency**).

**Cascade**:
Whether a write a rule performs triggers rules on the entity it wrote. A rule-initiated write is suppressed for peers on the **same effect kind**, and visible to rules of *other* kinds — so a term write can still drive a field or body-class rule, but cannot re-trigger term rules. Suppression is **pass-scoped**, keyed by *(entity, effect kind)*: it is held for the duration of the whole **pass**, never for the request. Request-scope would silence the author's own chain after its first rule wrote; pass-scope does not, because the chain runs *inside* the pass. Keying on the entity means a rule writing a *different* entity — a child, a related post — still starts that entity's own pass, and a genuine cycle terminates because the first entity's guard is still held. Consequence: rules compose *only* by running in sequence within one pass, each reading live state — which makes rule **order** the composition semantics rather than an implementation detail.

**Order**:
The sequence in which rules of one **effect kind** are evaluated in a **pass**. Order is **authored** within a kind — the author sequences the list, because two rules on one kind can legitimately want either sequence and nothing in the configuration says which. Order is **derived** between kinds, because those are **dependencies**, which have a right answer. So the author is asked exactly where the answer is genuinely theirs, and nowhere else.

**Collision**:
Two rules whose **reach** intersects on one **effect target**. The test is mechanical, not by fiat: an edge exists only where post types *and* taxonomies both overlap — either one being disjoint makes the rules independent. Collisions are **detected and warned at settings save, never resolved at runtime**: the plugin tells the author their rules contend, rather than silently picking a winner. Two rules that collide are not necessarily wrong — a collision means their combined result depends on **order**, which the author controls.

### Temporal Rule

The rule type that applies terms based on where "now" sits relative to one or two dates. Evolves the older fixed-window "Date Window" rule.

**Boundary**:
A dated edge of a rule's timeline — **start** or **end**. Either may be absent. A boundary's value resolves in precedence: its source value (typed, or read from an ACF/meta field) → a **derived boundary** → unset. A boundary carries a date and an optional time-of-day, interpreted in **site time**; an empty time is treated as 00:00. Time-of-day is available for *both* boundary sources — typed (Wireframe `date` + `time` subfields) and field-read — so a typed boundary is no longer date-only. A field-read boundary may hold its date and time in **one combined value** (`YYYY-MM-DDTHH:MM`, as Pie Calendar stores) parsed via `strtotime`; a typed boundary keeps date and time in separate subfields. A **boundary-time override** (see below) may rewrite the time-of-day after the value resolves.

**Site time**:
The single clock all Temporal reasoning happens in: WordPress's configured timezone (`wp_timezone()`), *not* the PHP server timezone. Every **boundary** date+time-of-day, the "now" a **phase** is computed against, and the day-edges of the **boundary-time override** are all interpreted in site time. A boundary value that is a wall-clock string (`YYYY-MM-DD`, `YYYY-MM-DDTHH:MM`) is *read as already being* in site time; a value that is an absolute instant (a Unix timestamp) is *converted into* site time before any date part is taken. This is a correctness floor: when the server and site timezones differ, comparing a site-local boundary against a server-local "now" misplaces a post by the offset — a post that is *during* by the site clock can read *after* at the boundary hour. All date construction routes through one helper bound to `wp_timezone()`; no `DateTime`/`strtotime` call in the temporal path is left timezone-naive.
_Avoid_: server time, UTC (UTC is only an intermediate for timestamp inputs, never the reasoning clock).

**Boundary source**:
Where a boundary's value comes from: **typed** (entered on the rule as date + optional time subfields) or **field** (read per-post from an ACF/meta field — a date, or a combined datetime). Independent of whether the value carries a time-of-day — both sources support time. A named **source preset** may pre-fill the field keys for a known plugin (see **Pie Calendar preset**); generic ACF/meta keys remain available.

**Boundary format**:
The stored string shape of a **field**-source boundary's value, which decides whether the cron sweep can range-query it in SQL or must scan. Not declared by the author and not fixed by a preset — it is **probed**: at the first sweep a few sample posts in the **filter gate** are read and the value matched against a regex ladder (`YYYY-MM-DD[ T]HH:MM(:SS)?` → datetime, `YYYY-MM-DD` → date, `YYYYMMDD` → `Ymd`, all-digits → unix timestamp, else opaque). The detected format is cached on the rule and reused. A format MySQL can cast to `DATETIME` (space separator, e.g. ACF's default `2026-06-15 14:30:00`) takes the windowed-`meta_query` path; one it cannot (Pie Calendar's `T` separator, `Ymd`, or `mixed`/opaque) falls back to a full scan that parses each value in the temporal path. Probe re-runs on rule re-save (covers an author swapping the underlying field config); a key holding more than one format across posts resolves to **mixed** → scan. The probe is cheap enough that no TTL or invalidation beyond re-save is kept.
_Avoid_: format descriptor (the author never declares it), schema.

**Boundary-time override**:
A per-rule rule that, when its configured boolean field (e.g. Pie Calendar's `_piecal_is_allday`) is truthy on a post, forces the time-of-day on *both* resolved boundaries to day-edges — start → 00:00:00, end → 23:59:59 — regardless of any time the boundary value carried. It is *not* a **duration** and *not* a **boundary source**: it sets no length and supplies no boundary value; it only rewrites time-of-day after both boundaries' dates have resolved. Same operation class as the "empty time = 00:00" default, just driven by a per-post flag and applied to both edges. When true it always wins over an explicit time in the field.
_Avoid_: all-day duration, all-day source (it is neither — see **Flagged ambiguities**).

**Pie Calendar preset**:
A named **source preset** mapping a rule's boundaries to Pie Calendar's event meta: start = `_piecal_start_date`, end = `_piecal_end_date` (both combined `datetime-local` strings), all-day = `_piecal_is_allday` (drives the **boundary-time override**), and optionally `_piecal_is_event` as a **filter gate** condition. Picking the preset pre-fills these keys so the author does not type them; the generic field source stays available for non-PieCal data.

**Applicability precondition**:
A Temporal rule acts on a post only if **at least one boundary resolves to a real date from a field**. With no resolved date on either boundary the rule does not apply at all — phase logic never runs and nothing is touched. A **fallback duration** can derive a *second* boundary from a present one, but can never manufacture a timeline from nothing; the "at least one real date" floor always holds first.

**Derived boundary**:
A boundary computed from its present sibling plus a **fallback duration** when the boundary's own field is empty (missing end = start + fallback duration; missing start = end − fallback duration).

**Fallback duration**:
The single, symmetric duration used to derive whichever **boundary** is missing from the one that is present. Applied only when the rule's **missing-boundary policy** is *derive*.
_Avoid_: offset (that is a different concept — see **Offset**).

**Missing-boundary policy**:
A per-rule choice for what happens when *one* boundary field is empty on a post (the other having satisfied the **applicability precondition**): *derive* (compute the missing boundary from the **fallback duration**, giving the post the full before/during/after treatment) or *collapse* (run the post as a single-boundary rule — before/after only, no *during* phase). The policy never applies when both boundaries are empty — the precondition already excludes that post.
UI label: "Missing date handling" (the word *boundary* stays domain-only).
_Avoid_: fallback mode, open-ended toggle (these are earlier rejected names).

**Phase**:
The exclusive temporal band a post occupies relative to a rule's boundaries. Exactly one phase is active per post per rule at any instant. A two-boundary rule has **before** / **during** / **after**; a single-boundary rule has **before** / **after** (no *during*). A post with no resolvable boundary for a rule has *no* phase under that rule and is left untouched.
_Avoid_: state, status (state is retired; status means WordPress `post_status`).

**Offset**:
A **duration** measured from a **boundary**, in a stated direction (before or after), that defines an **action**'s active window — e.g. "2 hours before end" (about-to-end) or "3 days after start" (just-started). An action window may not straddle a boundary; it lies wholly within one phase.
_Avoid_: lead, fallback duration.

**Duration**:
A number + time unit (minutes, hours, days) used by both **offset** and **fallback duration**. Hours/minutes are meaningful because boundaries carry time-of-day regardless of **boundary source** (typed or field).

**Sweep cadence**:
How often the cron re-evaluates a rule, **derived not configured**. That a Temporal rule needs a sweep *at all* is itself derived — from its **ambient** basis: "now" moves without the post changing, so no save fires and nothing would re-evaluate on its own. Cadence is then the finer of two signals: the rule's **boundary format** (date-only — `Ymd`, all-day override — flips only at midnight; time-bearing — typed time, datetime field, PieCal `T` — flips mid-day) and the smallest **offset** window on the rule. Date-only *and* no sub-hour offset → a **daily** sweep; anything time-bearing or carrying an offset → an **hourly** sweep. Hourly is the floor for the initial cut: sub-hourly cadence is deferred until the plugin can detect a real system cron, because WordPress's WP-Cron is *opportunistic* (fires on traffic, not wall-clock) and a 5-minute schedule silently degrades on a low-traffic site. A consequence the rule UI states in help text: an **offset** window smaller than the sweep tier (e.g. "15 minutes before start" under an hourly sweep) may be **missed** between runs — the window opens and closes inside one interval. That is a documented scope limit of the initial cut, not a silent bug; sub-minute/sub-hour offset precision arrives with the real-cron feature.
_Avoid_: polling interval (cadence is derived, not a user-set poll).

**Action**:
A unit within a rule scoped to one **phase**: an active window (the whole phase, or a boundary ± **offset**) plus an **overlap** and an effect. While "now" is in the window, the action's effect is in force.

**Overlap**:
How an active **action** relates to other actions active *at the same time* in the same phase: **stack** (its term applies alongside theirs) or **replace** (its term supersedes the other actions' terms in that phase). Named for the condition that makes it apply — it is only ever consulted when two actions overlap in time.
_Avoid_: mode (retired — it read as the same axis as **claim**, which it is not: overlap combines concurrent effects within one rule; a claim is what one rule does about pre-existing state).

**Applied term**:
The taxonomy term an **action** applies to a post while active.
_Avoid_: target term (existing UI label; the domain term is *applied term*), status term, phase term.

**Phase term-space**:
The set of terms a Temporal rule names as **applied terms** across all its phases — Temporal's named instance of a **jurisdiction**, and statically enumerable from config, which is what lets the rule be owning over it at all. A Temporal rule's **claim** over exactly this space is **owning**: for any post passing the **filter gate**, it reconciles the post to its active phase — the active phase's applied terms are present, and any *other* term in the phase term-space is removed, whether the rule placed it, another actor did, or it predates the rule. Terms outside the space are never touched. Correctness is recomputed from boundaries + config each evaluation; nothing is recorded.

**Boundary-key disjointness**:
An extra disjointness signal available to Temporal rules, narrowing the general **collision** test. A rule reading a **field**-source boundary carries an automatic **boundary-presence clause** (`EXISTS` on its key), so a post holding only key A is never processed by the key-B rule — two Temporal rules naming the same term do not contend if they read different boundary keys. This matters because a **claim** keeps no provenance: were the gates *not* disjoint, the key-B rule would find the shared term on a post in a phase where it does not apply and strip it, even though the key-A rule set it. Disjointness, not provenance, prevents the contention.

The residual real collision is a post carrying *both* keys — or two typed-boundary rules, which have no presence clause and share one timeline: both gates pass, both reconcile the shared term, and unlike most collisions this one **flaps** rather than merely depending on order. Detector signals: shared term + post-type overlap + intersecting boundary-key set (disjoint keys ⇒ no warn; typed boundaries contribute no key, so fall back to post-type + taxonomy/term-filter overlap).

### Flagged ambiguities

- **status**: reserved for WordPress `post_status` and the future "change post status" action effect. Never use it for a phase or a term.
- **offset vs fallback duration**: both are durations but distinct. *Offset* shifts an action's window from a boundary; *fallback duration* derives a missing boundary. Not interchangeable.
- **phase vs action**: a phase is the coarse exclusive band; an action is a finer effect *within* a phase. "about-to-end" is a *during*-phase action, not a fourth phase.
- **all-day is an override, not a duration or source**: it carries no number+unit (so it is not a **duration** — it can't feed offset or fallback) and supplies no boundary value (so it is not a **boundary source**). It only forces both boundaries' time-of-day to day-edges. Modelling it as a "third duration source" would force `duration`'s type to become `number+unit | true`, breaking every arithmetic consumer.
- **a claim is by config, not provenance**: a rule removes a configured applied term in a non-active phase even if it never applied it (e.g. a manual or pre-existing tag). Terms outside the rule's **jurisdiction** are safe.
- **overlap vs claim**: both were once called "mode". *Overlap* combines two effects active at the same instant *within one rule* (stack/replace). A *claim* is what one rule does about the pre-existing state of its **jurisdiction** (owning/contributing/deferring/restricting). Different axes; a rule has both.
- **contributing vs deferring**: neither removes and both add, so they cannot be told apart by their writes. The difference is a **vacancy precondition** — *contributing* always applies its values; *deferring* applies them only where the jurisdiction is empty, so on an occupied jurisdiction it applies nothing at all. `skip` was long filed as contributing on the strength of "never removes"; it fails contributing's other half, that the rule's values end up *present*.
- **reach vs jurisdiction vs claim**: three questions, easily mushed. **Reach** — which **effect targets** can this rule touch? **Jurisdiction** — which values *within* one of those targets does it govern? **Claim** — what does it do about values already sitting there? Two rules collide only where reach *and* jurisdiction both overlap.
- **owning is not restricting, though both enforce**: both constrain what an editor can durably author, and both do it by removal after the fact rather than by refusing the edit. *Owning* permits exactly one set — the one the rule derives. *Restricting* permits any set satisfying its constraint and supplies no values of its own.
- **relation is a basis kind, not the axis**: **basis** is what supplies a rule's input; *relation* is only the kind that traverses an edge. Field values are **intrinsic**, the acting user and "now" are **ambient**. Calling all three "relation" (or all three "condition") loses the two properties the split exists to derive — ambient ⇒ needs a sweep, relation ⇒ cross-entity reach.
- **natural vs authored applies only to relations**: a post hierarchy is natural, an ACF relationship is authored. The distinction is meaningless for intrinsic and ambient bases.
- **filter gate vs reach**: the gate is what a rule *looks at*; reach is what it can *touch*. They differ exactly when the basis includes a relation — post ancestry's gate selects parents, its reach covers descendants.
- **a collision is not an error**: it means two rules contend on one effect target, so the result depends on **order**. The plugin warns and lets the author order them; it never picks a winner at runtime.
- **collision vs dependency**: both are edges between rules, and they are opposites. A **collision** is symmetric, within one **effect kind**, and has no right answer — so the author orders it and the plugin warns. A **dependency** is asymmetric, *between* kinds, and has a right answer — so it is derived and the author is never asked. Conflating them either burdens the author with sequencing that is already determined, or asks the engine to guess at something only the author knows.
- **effect target vs effect kind**: the *target* is what a rule writes down to the specific taxonomy or key; the *kind* is its class. Two rules collide only on one target, but they are grouped, ordered and evaluated by kind. "Terms in taxonomy A" and "terms in taxonomy B" are two targets, one kind — which is why a cross-taxonomy chain composes by **order** within one **pass** rather than by re-triggering.
- **restricting-the-editor is still a restricting claim**: the user-based *lock* variant reads as a UI permission ("only these roles may edit this taxonomy"), but its **effect target** is terms and its **claim** is *restricting* — it applies nothing and requires the jurisdiction to satisfy a constraint, exactly like level-restriction. Filtering what the admin UI offers is the surface, not the effect. It therefore belongs with the other term rules and is ordered among them.
- **site time, not server time**: all boundary dates, the compared "now", and all-day day-edges are in **site time** (`wp_timezone()`). The PHP server timezone is never the reasoning clock — a server/site mismatch otherwise misplaces a post by the offset at boundary hours. Wall-clock string inputs are read as site time; Unix-timestamp inputs are converted to site time first. (The `{pub_*}` token fix in 0.3.1 set this precedent for the title/slug path; Temporal must hold it everywhere date parts are taken.)

## Example dialogue

> **Dev:** A post's event ended yesterday. What's it tagged?
> **Author:** Its rule has an *end* boundary from the `event_end` ACF field, so the post is in the **after** phase. The after-phase action applies the **applied term** "Ended."
> **Dev:** And the "Closing Soon" tag it had last week?
> **Author:** That was a **during**-phase **action** with a 3-day **offset** before end — about-to-end. When the post crossed *end* it entered the **after** phase. The rule reconciles the post to *after*: its only active applied term is "Ended," so any other configured term — "Closing Soon," "On Now" — is removed. The rule doesn't remember placing them; they're just non-active-phase terms in its term-space.
> **Dev:** What if an editor had manually added "On Now" to that post?
> **Author:** Same outcome — "On Now" is a configured applied term, so in the *after* phase it's removed regardless of who added it. The rule **owns** its phase term-space. A manual term that *isn't* in the rule's config, like "Sale," would be left alone.
> **Dev:** What if `event_end` was never filled in?
> **Author:** Then end has no field value. If the rule sets a **fallback duration**, end is a **derived boundary** = start + that duration. If not, the rule has no *end* boundary at all — it becomes a single-boundary rule with only **before** / **after**, and there's no *during* phase.
> **Dev:** Could a post be both "On Now" and "Closing Soon" at once?
> **Author:** Only if the about-to-end action's **overlap** is *stack*. In *replace* it supersedes "On Now" inside the **during** phase. Either way both are during-phase terms and both vanish when the post enters **after**.

### Example dialogue — two rules on one taxonomy

> **Dev:** A parent post gets Region, East and Coastal. There's a post-hierarchy rule copying a parent's terms down, and a term-hierarchy rule expanding one level down. What does the child end up with?
> **Author:** Region, East, Coastal — plus whatever the *parent's* expansion added, because the parent's chain ran both rules before anything was copied. The child gets the parent's settled set.
> **Dev:** Why doesn't the term-hierarchy rule expand again on the child? The copy is a term write.
> **Author:** **Cascade** is suppressed within one **effect target**. The copy is a rule-initiated write to terms-in-that-taxonomy, so peer rules on that same target don't see it. If it *did* re-expand, "expand one level" would produce two levels on the child.
> **Dev:** So a rule's write never triggers anything?
> **Author:** Only on the same effect target. A future body-class rule reading those terms *would* fire — different target. Suppression is per-target, not global.
> **Dev:** Then how do two rules ever combine?
> **Author:** By **order**, within one chain. Each runs in sequence and reads live state. That's the only composition mechanism left, which is why order is author-visible rather than falling out of hook priorities.
> **Dev:** Now add a level-restriction rule, one-per-level, same taxonomy. It strips East from the child.
> **Author:** Correct behaviour, not a bug. Its **claim** is *restricting*, and a restricting rule declares no narrower **jurisdiction**, so it prunes anything violating its constraint across the whole taxonomy — it doesn't care who wrote East. What's wrong is the *configuration*: one rule is told to place two same-level terms and another forbids it. That's a **collision**.
> **Dev:** So the plugin fixes it?
> **Author:** It **warns** at save and never resolves at runtime. The two rules collide only because their reach overlaps — same post types *and* same taxonomy. Put them on different post types and there's no edge and no warning.
> **Dev:** The post-hierarchy rule is in *merge*. The parent drops Coastal — does the child lose it?
> **Author:** No. *merge* is **contributing**: it adds and never removes. That's the contract, not a defect. Switch it to *replace* and it becomes **owning** — the child's terms then equal the parent's, and Coastal goes.
> **Dev:** Why can't *replace* just own the terms it copied, and leave the rest?
> **Author:** Because that jurisdiction isn't statically enumerable. To remove a term it copied *last* time it would have to remember copying it, and no rule records what it applied. So *replace*'s **jurisdiction** widens to the entire taxonomy — that's the only set it can own without provenance. It's the same reason a scalar target always collides.
> **Dev:** And the third option, *skip*?
> **Author:** That's **deferring**: it copies down only where the child has no terms in that taxonomy at all. Once anything is there it stands down. Easy to mistake for *merge* — both add and neither removes — but under *merge* the parent's terms are always present, and under *skip* they may never arrive.
