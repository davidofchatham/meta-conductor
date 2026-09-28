# Spec: Extract the token engine from TitleSlugHandler (FW-40)

> **Design history lifted 2026-09-28 from the private spec file. Never corrected.** This is the build contract for FW-40, written before the build and kept as written: why the title/slug pattern engine became its own `Tokens\` module with a source and a policy, and why date tokens moved to site time. It is **not** documentation of shipped code: see [architecture.md → Token engine](../architecture.md#token-engine), the PHPDoc on `Tokens\TokenEngine` and `Tokens\OutputPolicy`, and [CHANGELOG.md](../../CHANGELOG.md). "CLAUDE.md don't N" citations point at the maintainer's private notes. The five build tickets were not lifted. The orphaned-separator behavior found during the extraction is tracked as FW-44, and the `raw` policy and ACF-row source wait for FW-4, in [future-work.md](../future-work.md).

Status: shipped in [PR #78](https://github.com/davidofchatham/meta-conductor/pull/78), verified 2026-09-28: 18 static gates (H17 new) plus the golden check and the title/slug sweeps green.

Source: FW-40 in docs/future-work.md (candidate 3 of the 2026-09-24 architecture review); design settled in the 2026-09-28 grilling session. Branch `claude/token-engine-40`, PR, merge commit.

## Problem Statement

The pattern engine that turns `{meta:x} {term:tax} {pub_year}` into a title or slug lives inside `TitleSlugHandler`, threaded with title/slug specifics. A `'title'|'slug'` context string branches it at every level (month name vs number, term name vs slug, `", "` vs `"-"` joiner, per-token sanitizing, duplicate-guard comparison, dash collapsing in the final trim), and the title's idempotency guard is a resolver parameter. Three planned rule types want the same engine — `field_transformation` (FW-4, next up), `body_class` (FW-6) and term provisioning (FW-7) — and each would add another branch everywhere. The token grammar is also written twice in logic (the resolver's `match` and the slug escalation's precision regexes), so the two can drift. Date tokens read from numeric or `strtotime`-parsed meta use the server timezone instead of **site time**, against the CONTEXT.md rule that the server timezone is never the reasoning clock. And the engine is testable only by reflection on a private method inside a docker sweep.

## Solution

A `Tokens\` module owns the pattern → segments → resolve-or-drop → trim engine. It renders a pattern against a **token source** (where values come from) under an **output policy** (how values are shaped). `TitleSlugHandler` keeps only what is genuinely title/slug: the default-title idempotency (raw/applied meta, inverse strip), slug mode, uniqueness and date escalation. Title and slug output is byte-identical before and after the extraction, proven by a golden table captured from the current handler. The engine gets a host-PHP harness with a fake source. As a separate, deliberate commit, date parsing moves to site time.

## User Stories

1. As a site author with title/slug rules, I want every existing title and slug to come out exactly as it does today, so that a refactor never renames my posts or changes a published URL.
2. As a site author, I want the empty-token drop behavior preserved (a missing `{meta:name_post}` drops its preceding separator), so that composed names do not grow dangling dashes or commas.
3. As a site author, I want the duplicate-insertion guard preserved for patterns that fold `{default_title}` back in, so that re-saves never compound (`Title - x - x`).
4. As a site author, I want patterns that compose from scratch (no `{default_title}`) to stay unguarded, so that `{meta:first} {meta:last}` does not flip-flop between saves.
5. As a site author, I want slug mode `prefix`/`suffix`/`replace` and the `{default_slug}`-forces-replace rule unchanged, so that slug shapes stay what I configured.
6. As a site author, I want date escalation to keep adding precision next to the existing date portion, so that colliding slugs stay readable.
7. As a site author whose date meta is stored as a unix timestamp or a free-form date string, I want `{date_*:}` tokens to resolve in the site's timezone, so that a post near midnight does not get the neighboring day, month or year.
8. As a site author whose date meta is `Ymd` or `Y-m-d`, I want the timezone fix to change nothing for me, so that the fix is safe to ship onto my site.
9. As a site author, I want `{pub_*}` tokens to keep reading the site timezone as they have since 0.3.1, so that nothing regresses on the path already fixed.
10. As a site author with two title/slug rules in one format list, I want a later rule's tokens to see an earlier rule's computed title, so that the format seam's in-pass composition keeps working.
11. As a site author, I want unknown tokens to keep resolving to empty (and dropping), so that a typo degrades gracefully rather than printing `{mta:x}` into a title.
12. As a site author, I want a non-scalar meta value to keep resolving to empty with a logged note, so that an array field does not print `Array`.
13. As the plugin developer, I want the token engine to have one public entry point taking a pattern, a source and a policy, so that FW-4, FW-6 and FW-7 reuse it without touching title/slug code.
14. As the plugin developer, I want output shaping to be data on a policy object rather than branches on a context string, so that FW-4's `raw` policy is one new named constructor and zero new branches.
15. As the plugin developer, I want the token source to be a narrow interface (field, terms, publish date), so that FW-4's ACF-row source only has to answer those three questions and the grammar stays in one place.
16. As the plugin developer, I want `{default_title}`/`{default_slug}` handled as caller-bound variables, so that the engine knows nothing about title idempotency and FW-4 can bind row-level values the same way.
17. As the plugin developer, I want the duplicate guard to be an optional base string the caller passes, so that whether to guard stays a title/slug decision (`slug_keeps_default()` and the `{default_title}` check) and not an engine one.
18. As the plugin developer, I want the engine to report which tokens a pattern contains, so that slug escalation derives its date precision from the parsed pattern instead of a second copy of the grammar in regexes.
19. As the plugin developer, I want one date parser in the engine used by both date tokens and slug escalation, so that site time is enforced in exactly one place.
20. As the plugin developer, I want to test the engine on host PHP with no WordPress, so that token behavior is a static gate I run before every commit, not a docker sweep.
21. As the plugin developer, I want the slug sanitizer injected into the slug policy, so that a host-PHP test can stub it without faking WordPress.
22. As the plugin developer, I want a golden table of current outputs captured before any code moves, so that "no behavior change" is proven, not asserted.
23. As the plugin developer, I want the golden table to include the one live site's two patterns (read only, run against testbed fixture posts), so that the cases that matter in production are pinned.
24. As the plugin developer, I want the golden table to include a synthetic grid (every token kind × both policies × empty/non-empty × guard on/off × default variables bound), so that edge cases the fixture rules never hit are pinned too.
25. As the plugin developer, I want `sweep-59` to call the public engine instead of reflecting into a private method, so that the sweep survives future handler refactors.
26. As the plugin developer, I want the new classes on the H2 autoload list and following the PSR-4/kebab rules, so that the autoloader and lint gates cover them.
27. As the plugin developer, I want FW-4, FW-6 and FW-7's rows to say they consume `Tokens\`, so that the next spec starts from the real state of the code.
28. As the plugin developer, I want a short *Token engine* section in docs/architecture.md, so that where tokens are resolved is documented once.
29. As a reader of the changelog, I want only the user-visible timezone fix listed, so that an internal move does not read as a feature.
30. As a reviewer, I want the extraction and the timezone fix in separate commits, so that the no-behavior-change diff and the behavior-change diff can each be checked on their own terms.

## Implementation Decisions

- **Scope:** extraction plus the timezone fix. Ships the post source, a fake source (in the harness only) and the title and slug output policies. No `raw` policy and no ACF-row source — both wait for FW-4, which will be designed against a real consumer.
- **Module:** new `BWS\MetaConductor\Tokens\` namespace in its own directory, not `Core\` (which holds dispatchers). Four classes: `TokenEngine`, `TokenSourceInterface`, `PostTokenSource`, `OutputPolicy`. No consecutive-cap names; interface file uses the `class-` prefix; namespace first, globals leading-backslashed; all four added to H2.
- **`TokenEngine`:**
  - `render(string $pattern, TokenSourceInterface $source, OutputPolicy $policy, array $vars = [], ?string $guard_base = null): string` — parse into segments, resolve each token, drop empty tokens with their pending literals, apply the policy's final trim. Behavior identical to today's `resolve_pattern()` → `build_from_segments()` → `resolve_token()` → `trim_pattern_output()` chain.
  - `$vars` — bound tokens by name (title/slug binds `default_title` and `default_slug`). A bound token's value is returned as-is and is never duplicate-guarded, matching today.
  - `$guard_base` — null means no guard. When set, a resolved token whose value already appears in the base is dropped, compared as the policy says (title: case-insensitive substring of the raw value; slug: substring of the sanitized value).
  - `tokens(string $pattern): array` — the token names a pattern contains, from the same parser `render()` uses. Slug escalation's precision detection is rebuilt on it; the `detect_date_precision` regexes go.
  - A date parser (today's `parse_date_value()`), public so escalation's date parts go through it too.
- **`TokenSourceInterface`:** three reads — `field(string $key): mixed`, `terms(string $taxonomy): array` (term objects carrying name and slug; engine sorts by name as today), `published(): ?\DateTimeInterface`. The engine owns all token-name parsing and all date parsing/formatting; a source only fetches.
- **`PostTokenSource(int $post_id, array $data)`:** `published()` reads `post_date` from `$data` (the format seam's array, so an earlier rule's output is visible, as today); `field()` reads post meta by ID; `terms()` reads the post's terms by ID. The non-scalar-meta log note stays.
- **`OutputPolicy`:** a final value class with named constructors `::title()` and `::slug()`, holding six knobs: month format (name vs number), term label (name vs slug), term joiner (`", "` vs `"-"`), a value sanitizer callable (identity vs `sanitize_title`), the duplicate-guard comparison, and the final-trim variant (slug additionally collapses and trims dashes). The slug policy receives its sanitizer as a callable so host-PHP tests can inject a stub. No policy interface, no context string.
- **`TitleSlugHandler` keeps:** `resolve_rule_output()`, default-title resolution with `_bws_raw_title`/`_bws_applied_title`, `try_inverse_strip()` (its prefix/suffix resolves and verify-resolve now call the engine), `slug_keeps_default()`, `apply_slug_mode()`, `as_stored()`, uniqueness and date escalation. It builds a `PostTokenSource` per `apply_to_data()` / `commit_data()` call, binds the default variables, and decides the guard base. Its private token-resolution methods are deleted.
- **Timezone fix (separate commit):** the engine's date parser interprets wall-clock strings as **site time** and converts unix timestamps into site time (`wp_timezone()`), and escalation's publish-date fallback goes through the same parser. The only live site's date meta is all `Ymd`, so the fix is inert there. `{pub_*}` already used site time and is unchanged.
- **Not changed:** the *Available tokens* help table on the title/slug config stays hand-written (prose with examples; generating it costs more than it saves). No storage, config, dispatcher or descriptor change.
- **Delivery:** branch `claude/token-engine-40`, PR, merge commit. Commit order: golden capture → extraction (with H17 and the sweep-59 switch) → timezone fix → docs. Version bump decided at release, not here.
- **Docs on ship:** FW-40 marked done; FW-4's "~60–70% of the engine exists as TitleSlugHandler…" becomes "consumes `Tokens\`; net-new = `OutputPolicy::raw()` + row source + new token kinds"; FW-6/FW-7 "reuse the TitleSlug token engine" wording updated likewise; a short *Token engine* section in docs/architecture.md; CHANGELOG `[Unreleased]` → *Fixed* gets the timezone line only. Spec lifts to docs/design-history/ post-merge, sanitized.

## Testing Decisions

- **Good tests here assert rendered strings for given patterns, sources and policies** — the external behavior of `render()` — never segment arrays, private helpers or call order. The one seam under test is `TokenEngine::render()` (plus `tokens()`), fed by a fake source; handler-level behavior stays covered at the existing format seam (`apply_to_data()` / `FormatDispatcher`) by the docker sweeps. No new seam is introduced for testing.
- **Golden table first:** before any code moves, capture current outputs on the testbed from the existing handler (via the same reflection `sweep-59` uses today) for: the mc-rules fixture's title/slug rules; the live site's two patterns run against testbed fixture posts; and a synthetic grid — every token kind × title/slug × empty/non-empty value × guard on/off × default variables bound. Stored as a data fixture the harness reads.
- **H17 `tests/verify-token-engine.php`:** host PHP, no WordPress. Minimal local stubs (a `sanitize_title` stand-in injected through the slug policy; whatever else `render()` touches). A fake source backed by arrays. Asserts the golden table, plus targeted cases: empty-token drop with pending separators, trailing-literal handling, unmatched opening punctuation stripped, guard only when a base is passed, bound variables never guarded, `tokens()` output, non-scalar field → empty. Joins the "loop all `tests/*.php` before any commit" rule and the CLAUDE.md static-gates list.
- **Timezone commit** adds H17 cases for a unix-timestamp and a `strtotime` input near midnight with a non-UTC site timezone stub, asserting site-time parts, and a `Ymd` case asserting no change.
- **Docker gate:** `sweep-59` (switched to the public engine) and `sweep-64` i/j/k (idempotency: resolved-base recording, no compounding, `as_stored()`) green on the testbed. Check `post_name`s after any sweep restore (CLAUDE.md don't 6f(e)).
- **Prior art:** H12 `verify-format-rules-config.php` and H13 `verify-term-dispatcher.php` for host-PHP harness shape and local stubbing; `sweep-59-behaviour.php` §3 for the token expectations computed from real postmeta and terms; `sweep-64` for idempotency.

## Out of Scope

- `OutputPolicy::raw()` and HTML-preserving output (FW-4).
- An ACF repeater-row token source and row-scoped reads (FW-4).
- New token kinds: value filters, conditionals, format transforms (FW-4).
- `body_class` / term-provisioning consumers (FW-6, FW-7).
- Generating the *Available tokens* help table from the engine.
- The first-match-wins duplicate-rule warning (the TODO(UI) on `rule_matches()`).
- The ambiguous `d/m/Y` date format in the parser — kept as-is.
- Slug-change safety and provocation scoping (FW-36, FW-13).
- Deciding the release version.

## Further Notes

- The grilling session had planned a lighter `plan.md` instead of a spec; this spec replaces it.
- The live site's rules, for the golden table: a date-suffixed slug rule (`{date_year:…}` in suffix mode, escalating on the same date field), and a person-name rule composing its title from four name fields and its slug from three, in replace mode, escalating on the publish date. Read only; never patch its files or database.
- CONTEXT.md → **Site time** is the governing rule for the timezone fix.
