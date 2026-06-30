# Meta Conductor

A WordPress plugin that applies taxonomy terms (and, later, other effects) to posts, terms, and users according to configured **rules**. Each rule type encodes one way of deciding which entities get which terms.

This glossary defines the domain language. It is not a spec — it says what terms *mean*, not how they are implemented.

## Language

### Core

**Rule**:
A single configured unit of behaviour belonging to a **rule type**. Holds the conditions for acting and the effect to apply.
_Avoid_: setting, config entry.

**Rule type**:
A named family of rules with shared structure and a dedicated handler (e.g. Temporal Rule, Title/Slug, Hierarchical).

**Handler**:
The code that processes all rules of one rule type against entities.

**Entity**:
A post, term, or user a rule acts on. Wrapped by `BWS_Entity` so handlers stay entity-agnostic.

**Filter gate**:
A rule's optional restriction selecting which posts it considers at all — its **source**. Today: post type + **post status** + taxonomy/term filters, plus — for a Temporal rule with a **field**-source boundary — an automatic **boundary-presence clause** (the post must have the boundary's meta key set; an `EXISTS`-on-key condition). The post-status filter selects which statuses a rule considers (e.g. only `publish`, or `publish`+`future`); empty = all. It is a *gate*, distinct from a future "set post status" *effect* — one decides whether the rule looks at a post, the other would change the post's status. Independent of the rule's main logic — a post must pass the filter gate *and* the rule's own logic to be acted on. The boundary-presence clause does double duty: it is the same `meta_query` the cron sweep uses to find candidate posts for that field, and it is what makes two Temporal rules reading *different* boundary keys provably disjoint (see **Collision**).
_Avoid_: filter, scope (when ambiguous).

**Source / When / Effect**:
The three groups a rule's configuration reads as, in order: **source** (the filter gate — which posts), **when** (timing — boundaries and, for Temporal rules, phases), **effect** (what is applied — the applied terms). A correct rule UI keeps each group's fields together and in this order. Not a stored structure — a presentation and reasoning convention.

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
How often the cron re-evaluates a rule, **derived not configured**. The needed precision is the finer of two signals: the rule's **boundary format** (date-only — `Ymd`, all-day override — flips only at midnight; time-bearing — typed time, datetime field, PieCal `T` — flips mid-day) and the smallest **offset** window on the rule. Date-only *and* no sub-hour offset → a **daily** sweep; anything time-bearing or carrying an offset → an **hourly** sweep. Hourly is the floor for the initial cut: sub-hourly cadence is deferred until the plugin can detect a real system cron, because WordPress's WP-Cron is *opportunistic* (fires on traffic, not wall-clock) and a 5-minute schedule silently degrades on a low-traffic site. A consequence the rule UI states in help text: an **offset** window smaller than the sweep tier (e.g. "15 minutes before start" under an hourly sweep) may be **missed** between runs — the window opens and closes inside one interval. That is a documented scope limit of the initial cut, not a silent bug; sub-minute/sub-hour offset precision arrives with the real-cron feature.
_Avoid_: polling interval (cadence is derived, not a user-set poll).

**Action**:
A unit within a rule scoped to one **phase**: an active window (the whole phase, or a boundary ± **offset**) plus a **mode** and an effect. While "now" is in the window, the action's effect is in force.

**Mode**:
How an active **action** relates to other active actions in the same phase: **stack** (its term applies alongside theirs) or **replace** (its term supersedes the other actions' terms in that phase).

**Applied term**:
The taxonomy term an **action** applies to a post while active.
_Avoid_: target term (existing UI label; the domain term is *applied term*), status term, phase term.

**Ownership**:
A rule owns exactly the set of terms it names as **applied terms** across its phases — its *phase term-space*. For any post passing the **filter gate**, the rule reconciles that post to its active phase: the active phase's applied terms are present, and any *other* configured applied term found on the post is removed — whether the rule placed it, another actor did, or it predates the rule. Terms outside the rule's configured set are never touched. The rule keeps no record of what it applied; correctness is recomputed from boundaries + config each evaluation.
_Avoid_: provenance (explicitly not tracked).

**Collision**:
Two Temporal rules naming the same term as an applied term *and* whose **filter gates** can match the same post. The disjointness test is mechanical, not by-fiat: rules collide only where their gates intersect. Disjoint — no collision — when the rules apply to different post types, *or* when each reads a **field**-source boundary on a different meta key, because each such rule carries an automatic **boundary-presence clause** (`EXISTS` on its key) that excludes posts lacking that key. A post that has only key A is never processed by the key-B rule, so the key-B rule never strips key-A's term — disjointness, not provenance, prevents the contention. (This matters because **ownership** keeps no provenance: were the gates *not* disjoint, the key-B rule, finding the shared term on a post in a phase where it doesn't apply, would strip it even though the key-A rule set it.) The residual real collision is a post carrying *both* keys (or two typed-boundary rules, which have no presence clause and share one timeline): both gates pass, both reconcile the shared term, they flap. Detected and warned (non-blocking) at settings save; not prevented. Detector signals: shared term + post-type overlap + intersecting boundary-key set (disjoint keys ⇒ no warn; typed boundaries contribute no key, so fall back to post-type + taxonomy/term-filter overlap).

### Flagged ambiguities

- **status**: reserved for WordPress `post_status` and the future "change post status" action effect. Never use it for a phase or a term.
- **offset vs fallback duration**: both are durations but distinct. *Offset* shifts an action's window from a boundary; *fallback duration* derives a missing boundary. Not interchangeable.
- **phase vs action**: a phase is the coarse exclusive band; an action is a finer effect *within* a phase. "about-to-end" is a *during*-phase action, not a fourth phase.
- **all-day is an override, not a duration or source**: it carries no number+unit (so it is not a **duration** — it can't feed offset or fallback) and supplies no boundary value (so it is not a **boundary source**). It only forces both boundaries' time-of-day to day-edges. Modelling it as a "third duration source" would force `duration`'s value space to become `number+unit | true`, breaking every arithmetic consumer.
- **ownership is by config, not provenance**: a rule removes a configured applied term in a non-active phase even if it never applied it (e.g. a manual or pre-existing tag). Terms outside the rule's configured set are safe.
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
> **Author:** Only if the about-to-end action's **mode** is *stack*. In *replace* mode it supersedes "On Now" inside the **during** phase. Either way both are during-phase terms and both vanish when the post enters **after**.
