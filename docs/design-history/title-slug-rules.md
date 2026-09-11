# Plan: Title and Slug Rules Feature

> **Design history — lifted 2026-09-11 from a private plan file. Never corrected.**
>
> This is the design record for `title_slug_rules`, written *before* the feature was built and kept as written. It is here for the reasoning that never became code — the token vocabulary that was considered, why idempotency is recorded the way it is, what slug-collision strategies were weighed. It is **not** documentation of the shipped code and it is never edited to match it: for what the code does today see [architecture.md](../architecture.md), [storage-model.md](../storage-model.md) and [CHANGELOG.md](../../CHANGELOG.md).
>
> Expect drift. The class and file names here predate the PSR-4 migration (`BWS_Title_Slug_Handler`, `bws-taxonomy-manager.php`), the two-phase pre-write/post-write split described under *Hook Timing* was later replaced by the format dispatcher ([ADR 0003](../adr/0003-ordered-rule-list-and-dispatcher.md)), and the accompanying phase-by-phase implementation plan was deliberately **not** lifted — it is a build log, not design.

## Context

Posts in WordPress get titles from user input and slugs derived from the title. For certain post types (e.g. Personnel, Events, Resources), both the title and the slug should be built from structured data — custom field values or taxonomy terms — rather than typed by the user. This new combined rule type ("Title and Slug Rules") automates both at save time.

**Key constraint driving the combined design**: ACF fields are not available during `wp_insert_post_data` (fires before DB write). Both title and slug rules must read field data, so both fire at `save_post` priority 99 (after ACF commits at priority 10). Combining them into one handler lets us issue a single `wp_update_post()` call with one recursion guard, avoiding fragile two-handler sequencing.

---

## Rule Structure (per rule)

```php
[
    'name'            => string,   // Display name (required)
    'post_type'       => string,   // Post type slug (required)
    'enabled'         => bool,

    // Title (optional — leave blank to skip)
    'title_pattern'   => string,   // e.g. '{meta:first_name} {meta:last_name}'

    // Slug (optional — leave blank to skip; implicit slug derived from title when title_pattern set)
    'slug_mode'       => string,   // 'replace' | 'prefix' | 'suffix'
    'slug_pattern'    => string,   // e.g. '{pub_year}-{meta:first_name}-{meta:last_name}'

    // Collision avoidance — rule-level, applies to both explicit and implicit slugs
    'date_escalation' => bool,
    'date_field'      => string,   // Field to read date from; blank = use post_date
]
```

---

## Token Vocabulary

Tokens are **context-aware**: in a title pattern they return human-readable values (original casing, term names, formatted dates); in a slug pattern they return slug-safe values (`sanitize_title()` applied, term slugs used).

**Duplicate-insertion guard**: Before inserting any token's resolved value, check whether it already appears in `{default_title}` (title context) or `{default_slug}` (slug context). If found, treat the token as empty — separator trimming handles cleanup. This prevents double-insertion when the user has manually included content that the rule would also add (e.g., typing "Easter 2026 Sunrise Service" when the pattern prefixes the year).

- Title check: `str_contains(mb_strtolower($default_title), mb_strtolower($token_value))`
- Slug check: `str_contains($default_slug, sanitize_title($token_value))`
- Applies to all tokens except `{default_title}` and `{default_slug}` themselves

### Field Access Tokens

| Token | Description |
|---|---|
| `{meta:field_name}` | `get_post_meta()` — works for any plugin (ACF, Meta Box, Pods, CMB2, etc.) |
| `{default_title}` | Original `post_title` before this rule runs (title pattern only) |
| `{default_slug}` | Slug from post title *after* title rule applied (slug pattern only) |

Note: No `{acf:*}` token. `{meta:field_name}` reads raw postmeta via `get_post_meta()` and works for any field provider. ACF date fields use `{date_*:field_name}` tokens which auto-detect the stored format.

### Date Extraction Tokens (auto-detect format, source-agnostic)

These work on any field that stores a date/datetime — ACF (`Ymd`), Meta Box (`Y-m-d`), Unix timestamps, etc. — by trying multiple `DateTime::createFromFormat()` formats.

| Token | Title context | Slug context |
|---|---|---|
| `{date_year:field_name}` | "2024" | "2024" |
| `{date_month:field_name}` | "March" | "03" |
| `{date_day:field_name}` | "15" | "15" |
| `{date_hour:field_name}` | "14" | "14" |
| `{date_minute:field_name}` | "30" | "30" |

Note: `{date_month:*}` returns the month name in title context and zero-padded number in slug context. All others are numeric in both contexts.

### Publication Date Tokens (from `post_date`)

All `{pub_*}` tokens use `post_date` (WordPress site local time), not `post_date_gmt`.

| Token | Title context | Slug context |
|---|---|---|
| `{pub_year}` | "2024" | "2024" |
| `{pub_month}` | "March" | "03" |
| `{pub_day}` | "15" | "15" |
| `{pub_hour}` | "14" | "14" |
| `{pub_minute}` | "30" | "30" |

### Taxonomy Term Tokens

| Token | Title context | Slug context |
|---|---|---|
| `{term:taxonomy}` | First term **name** (alpha by name) | First term **slug** |
| `{terms:taxonomy}` | Term names joined with `, ` | Term slugs joined with `-` |

---

## Separator Auto-Trimming

When a token resolves to empty, the system automatically cleans up surrounding separators so the output doesn't have dangling punctuation.

**Algorithm**: Parse the pattern into alternating `[literal, token]` segments. For each empty token, drop it and its **following** (right-side) literal. Then post-process the joined result:

```
a. Remove unmatched trailing opening punctuation: /\s*[\(\[\{<]+\s*$/
b. Remove leading separators:  /^[\s:,\-|\/]+/
c. Remove trailing separators: /[\s:,\-|\/]+$/
d. Collapse multiple spaces to single space
e. trim()
For slugs additionally: collapse -- → -, strip leading/trailing -
```

**Examples**:

| Pattern | Empty token | Result |
|---|---|---|
| `{default_title} ({pub_year})` | `{pub_year}` | "My Event" |
| `{term:category}: {default_title}` | `{term:category}` | "My Post" |
| `{acf:a} - {acf:b} - {acf:c}` | `{acf:b}` | "Smith - Jones" |
| `{acf:last_name}, {acf:first_name}` | `{acf:first_name}` | "Smith" |
| `{pub_year}-{date_year:event_date}` | `{date_year:event_date}` | "2024" |

---

## Date Auto-Detection (for `{date_*}` tokens)

```php
function parse_date_value(string $value): ?DateTime {
    foreach (['Ymd', 'Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'U'] as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt !== false) return $dt;
    }
    // Also try strtotime as fallback
    $ts = strtotime($value);
    return $ts !== false ? (new DateTime())->setTimestamp($ts) : null;
}
```

Always reads via `get_post_meta($id, $field, true)`. This is source-agnostic — ACF, Meta Box, Pods, CMB2, and Pie Calendar all store date values in postmeta. The auto-format detection handles each plugin's storage format without needing plugin-specific API calls.

---

## Slug Mode

- **replace**: slug = resolved slug pattern only
- **prefix**: slug = `{slug_pattern}-{default_slug}`
- **suffix**: slug = `{default_slug}-{slug_pattern}`

`{default_slug}` is always computed as `sanitize_title($new_title)` within the current pass — it is **never** read from the stored `post_name`. This means slug idempotency derives automatically from title idempotency; no separate `_bws_raw_slug` / `_bws_applied_slug` meta is needed. If this ever changes (e.g. `{default_slug}` is redefined to read from `post_name`), slug meta tracking would need to be added.

**Blank slug pattern with active title pattern**: When `slug_pattern` is left empty but `title_pattern` is set, the slug is implicitly derived from the computed title (`sanitize_title($new_title)`). This mirrors WordPress's normal behavior and prevents the slug from being stuck as the numeric post ID when the initial save had a blank title (a common setup when the title field is hidden and built entirely from ACF fields). Only skip slug updates when neither `title_pattern` nor `slug_pattern` is set.

---

## Multiple Rules / Priority

First matching rule by array index wins. Only one rule executes per post save.

**Rule reordering**: Each rule has up/down arrow buttons (↑ ↓) to shift its position in the list. The first rule cannot move up; the last cannot move down. UI disables the relevant button at the boundaries. This is consistent with the WordPress block editor's move controls. Admin UI includes a note: "Rules are evaluated top to bottom — the first matching rule for a post type wins."

Note: Up/down reordering should be retrofitted to other rule types when those tabs are next revisited.

---

## Idempotency: Avoiding Double-Application on Re-Save

**Problem**: On first save, pattern `{date_year:...} {default_title}` transforms "Easter Sunrise Service" → "2026 Easter Sunrise Service". On re-save, the user's editor shows the rule-applied title, so they re-submit "2026 Easter Sunrise Service". `{default_title}` would resolve to that, producing "2026 2026 Easter Sunrise Service".

**Solution**: Track the raw (pre-rule) title alongside the rule-applied version in post meta. Compare on each save to detect whether the user changed the title.

**Short-circuit**: If the title pattern contains no `{default_title}` token, skip the entire idempotency mechanism — all tokens read fresh from external sources and cannot compound across saves.

```
Meta stored after each rule application (only when pattern uses {default_title}):
  _bws_raw_title     = base title before rule was applied
  _bws_applied_title = title the rule produced
```

**Resolution algorithm for `{default_title}`**:
```
submitted = pending_submitted_titles[$post_id]
applied   = get_post_meta($post_id, '_bws_applied_title', true)
raw       = get_post_meta($post_id, '_bws_raw_title', true)

if submitted == applied:
    {default_title} = raw                     ← re-save or source field changed

else:
    candidate = try_inverse_strip(submitted, post_id, post, rule)
    if candidate not null:
        {default_title} = candidate           ← user edited the base portion of applied title
    else:
        {default_title} = submitted           ← user typed a completely new title
```

`try_inverse_strip` — single attempt with case-insensitive matching:

Resolve all pattern tokens except `{default_title}` using current field values to get the expected prefix and suffix. Case-insensitive strip both from `submitted`. Verify by re-applying the full rule with the candidate — if result matches `submitted`, return candidate; else return null.

Note: A second attempt using old prefix/suffix (derived from stored meta) was considered but is dead code — verification always fails when current token values differ from old ones, because the rule re-applies current values during verification.

**Example flows**:
```
Save 1: "Easter Sunrise Service" (new post, no meta)
  No stored applied → {default_title} = submitted = "Easter Sunrise Service"
  Rule: "2026 Easter Sunrise Service"
  Stores: raw="Easter Sunrise Service", applied="2026 Easter Sunrise Service"

Save 2: Re-save without touching title (submitted = "2026 Easter Sunrise Service")
  submitted == applied → {default_title} = raw = "Easter Sunrise Service"
  Rule: "2026 Easter Sunrise Service" → unchanged, no DB write

Save 3: Date field changes 2026→2027, title untouched
  submitted = "2026 Easter Sunrise Service" == applied
  → {default_title} = raw = "Easter Sunrise Service"
  {date_year:field} now = "2027" (read fresh from field)
  Rule: "2027 Easter Sunrise Service"
  Stores: raw="Easter Sunrise Service", applied="2027 Easter Sunrise Service"

Save 4: User edits title to "2027 Easter Early Service"
  submitted ≠ applied ("2027 Easter Sunrise Service")
  try_inverse_strip: prefix="2027 " → submitted starts with it → candidate="Easter Early Service"
  verify: rule("Easter Early Service") = "2027 Easter Early Service" == submitted ✓
  {default_title} = "Easter Early Service"
  Rule: "2027 Easter Early Service"
  Stores: raw="Easter Early Service", applied="2027 Easter Early Service"

Save 5: User types entirely new title "Good Friday Vigil"
  submitted ≠ applied → inverse strip fails (no "2027 " prefix)
  {default_title} = "Good Friday Vigil" (new raw base)
  Rule: "2027 Good Friday Vigil"
  Stores: raw="Good Friday Vigil", applied="2027 Good Friday Vigil"
```

**Implementation**: Add `wp_insert_post_data` filter (priority 1) to capture the submitted title into an instance property `$pending_submitted_titles[$post_id]` before the DB write. Skip capture when `$is_updating_post` is true (so our own `wp_update_post()` call doesn't overwrite the stored raw title).

---

## Hook Timing & Recursion Guards

```
wp_insert_post_data filter (priority 1, skipped if $is_updating_post):
  capture $pending_submitted_titles[$post_id] = $data['post_title']

save_post fires
  ACF at priority 10: saves fields, fires acf/save_post
    our acf/save_post at priority 99 → process title + slug
  our save_post at priority 99 → skipped if ACF active (already handled)
                                  OR processes if ACF not active

on_save_post() / on_acf_save_post() early-exit conditions:
  wp_is_post_autosave($post_id)      → return
  wp_is_post_revision($post_id)      → return
  $post->post_status == 'auto-draft' → return
  $post->post_status == 'trash'      → return
  $processed_in_request[$post_id]    → return  (double-hook guard)

process_for_post():
  1. find_matching_rule() — first rule where post_type matches
  2. Resolve {default_title}:
       if title_pattern contains {default_title}: run idempotency algorithm
       else: skip (all tokens read fresh, no compounding possible)
  3. Compute new_title from title_pattern (if set) using resolved {default_title}
  4. Compute new_slug:
       if slug_pattern set: resolve slug_pattern → apply slug mode → make_unique_slug()
       elif title_pattern set: sanitize_title($new_title) → make_unique_slug()  ← implicit default
       else: skip slug
       // {default_slug} = sanitize_title($new_title), always from current pass, never from post_name
  5. Early return if neither changed from current values
  6. $is_updating_post = true
  7. $processed_in_request[$post_id] = true
  8. add_filter('wp_save_post_revision_post_has_changed', '__return_false')
  9. wp_update_post(['post_title'=>$new_title, 'post_name'=>$new_slug])  // single call
  10. remove_filter('wp_save_post_revision_post_has_changed', '__return_false')
  11. if title_pattern contains {default_title}:
        update_post_meta($post_id, '_bws_raw_title', $raw_title_used)
        update_post_meta($post_id, '_bws_applied_title', $new_title)
  12. $is_updating_post = false

Guards:
  $is_updating_post                prevents re-entry from wp_update_post()'s triggered save_post
  $processed_in_request[]          prevents double-processing from both hooks
  wp_insert_post_data skip         prevents capturing rule-applied title as raw during own update
  wp_save_post_revision filter     suppresses extra revision created by our wp_update_post() call
```

---

## Collision Avoidance (Slug)

```
make_unique_slug(slug, post_id, post, rule):
  if slug_is_unique: return slug
  if date_escalation:
    date_field = rule.date_field (or blank → use post_date)
    parts = get_date_parts(date_field, post_id, post)
    precision = detect_date_precision(slug_pattern)
    return escalate(slug, precision, parts, post_id, post)
  return wp_unique_post_slug(...)  // WP numeric suffix

escalate(base, precision, parts, post_id, post):
  ladder from precision upward:
    year   → try +parts['month'] → +parts['day'] → +parts['hour'] → +parts['minute']
    month  → try +parts['day']   → +parts['hour'] → +parts['minute']
    day    → try +parts['hour']  → +parts['minute']
    hour   → try +parts['minute']
    minute → skip to numeric
  for each candidate: check slug_is_unique() → return if unique
  fallback: wp_unique_post_slug(last_candidate, ...)

detect_date_precision(pattern):
  check for {date_minute:*} or {pub_minute} → 'minute'
  check for {date_hour:*} or {pub_hour}     → 'hour'
  check for {date_day:*} or {pub_day}       → 'day'
  check for {date_month:*} or {pub_month}   → 'month'
  check for {date_year:*} or {pub_year}     → 'year'
  else → 'none'

slug_is_unique(slug, post_id, post_type):
  $wpdb->get_var: post_name=slug AND post_type=type
    AND post_status NOT IN ('trash','auto-draft') AND ID != post_id
  return count == 0
```

---

## Files to Create

### `includes/handlers/class-bws-title-slug-handler.php` (new, ~350 lines)

```php
class BWS_Title_Slug_Handler extends BWS_Unified_Handler_Base {
    private bool $is_updating_post = false;
    private array $processed_in_request = [];
    private array $pending_submitted_titles = [];  // post_id → submitted post_title from wp_insert_post_data

    protected function get_rule_type(): string { return 'title_slug_rules'; }
    public function get_handler_type(): string { return 'title_slug'; }
    protected function validate_rule_internal(array $rule): bool {
        return !empty($rule['enabled']);
        // Checks only 'enabled' — action['type'], source_type, target_type are not
        // applicable to title/slug rules (action is always implicit; source/target always post/self)
    }

    protected function init_hooks(): void {
        add_filter('wp_insert_post_data', [$this, 'capture_submitted_title'], 1, 2);
        add_action('save_post', [$this, 'on_save_post'], 99, 3);
        add_action('acf/save_post', [$this, 'on_acf_save_post'], 99);
    }

    // Hook callbacks with guards
    public function capture_submitted_title(array $data, array $postarr): array
    // Stores $data['post_title'] into $pending_submitted_titles[$id]; no-op if $is_updating_post
    public function on_save_post(int $post_id, WP_Post $post, bool $update): void
    public function on_acf_save_post($post_id): void

    // Core processing
    private function process_for_post(int $post_id, WP_Post $post): void
    private function find_matching_rule(WP_Post $post, array $rules): ?array

    // Pattern resolution
    private function resolve_pattern(string $pattern, int $post_id, WP_Post $post,
                                     string $context, string $computed_title = ''): string
    // context: 'title' or 'slug'
    private function parse_pattern_segments(string $pattern): array
    // Returns [['literal' => string, 'token' => string|null], ...]
    private function resolve_token(string $token, int $post_id, WP_Post $post,
                                    string $context, string $computed_title): string
    private function trim_pattern_output(string $result, string $context): string
    private function resolve_default_title(int $post_id, WP_Post $post, array $rule): string
    // Short-circuits if pattern has no {default_title}.
    // Otherwise: submitted==applied → use raw; else try_inverse_strip; else use submitted.
    private function try_inverse_strip(string $submitted, int $post_id, WP_Post $post,
                                        array $rule): ?string
    // Resolves all tokens except {default_title} (current field values) to get prefix/suffix.
    // Case-insensitive strip from submitted. Verify by re-applying full rule. Return candidate or null.

    // Field access
    private function get_field_value(string $field_name, int $post_id): string
    // Uses get_post_meta() only — source-agnostic, no get_field() dependency
    private function get_date_parts_from_field(string $field_name, int $post_id): array
    // Returns ['year','month','month_name','day','hour','minute']
    // Reads raw postmeta, auto-detects date format via parse_date_value()
    private function get_pub_date_parts(WP_Post $post): array
    private function parse_date_value(string $value): ?DateTime
    private function get_first_term(int $post_id, string $taxonomy, string $context): string
    private function get_all_terms(int $post_id, string $taxonomy, string $context): string

    // Slug handling
    private function apply_slug_mode(string $built, string $default_slug, string $mode): string
    private function make_unique_slug(string $slug, int $post_id, WP_Post $post, array $rule): string
    private function escalate_date_slug(string $slug, int $post_id, WP_Post $post, array $rule): string
    private function detect_date_precision(string $pattern): string
    private function get_date_parts_for_escalation(array $rule, int $post_id, WP_Post $post): array
    private function slug_is_unique(string $slug, int $post_id, string $post_type): bool

    // Public API
    public function validate_rule(array $rule_data): array
    public function process_existing_posts(int $batch_size = 50, int $offset = 0): array
    // Batch-processes existing posts matching a rule's post_type. Called via AJAX with offset.
    // Returns ['processed' => int, 'total' => int, 'done' => bool, 'errors' => array]
    public function preview_rule(array $rule): array
    // Dry-run against most recent published post of rule's post_type (no DB writes).
    // Returns ['post_id' => int, 'post_url' => string,
    //          'current_title' => string, 'preview_title' => string,
    //          'current_slug' => string,  'preview_slug' => string,
    //          'warnings' => string[]]     ← empty tokens, non-string field values, etc.
    // {default_title} falls back to current post_title if no _bws_raw_title stored yet.
    private function write_rule_status(int $rule_index, int $post_id, string $new_title,
                                       string $new_slug, array $warnings): void
    // Always overwrites 'bws_title_slug_rule_status'[$rule_index]['last_applied']:
    //   ['timestamp', 'post_id', 'title', 'slug']
    // Only appends to ['warnings'] when $warnings non-empty; capped at 10 entries (FIFO)
}
```

**`parse_pattern_segments()` output structure**:
```php
// For pattern "{term:category}: {default_title} ({pub_year})"
[
    ['literal' => '',          'token' => 'term:category'],
    ['literal' => ': ',        'token' => 'default_title'],
    ['literal' => ' (',        'token' => 'pub_year'],
    ['literal' => ')',         'token' => null],  // trailing literal
]
```

**`resolve_pattern()` implementation**:
```php
$segments = $this->parse_pattern_segments($pattern);
$parts = [];
foreach ($segments as $seg) {
    $value = $seg['token'] ? $this->resolve_token($seg['token'], ...) : '';
    if ($value !== '' || $seg['token'] === null) {
        $parts[] = $seg['literal'];
        if ($value !== '') $parts[] = $value;
    }
    // When token empty: drop token + its following literal (handled by not appending)
}
// Append final trailing literal (last segment with token===null)
return $this->trim_pattern_output(implode('', $parts), $context);
```

---

## Files to Modify

### 1. `includes/storage/class-bws-option-rule-storage.php`
- **Line 50**: Add `'title_slug_rules'` to `$valid_types` (after `hierarchical_level_restriction_rules`)
- **`validate_rule()` switch**: Add `case 'title_slug_rules':` — require `post_type`; require at least one of `title_pattern`/`slug_pattern`; validate `slug_mode`

### 2. `bws-taxonomy-manager.php`
- **Line 110**: Add `'title_slug_rules' => array()` to `add_option()` defaults
- **Lines 124-126**: Add migration:
  ```php
  if (!isset($existing_settings['title_slug_rules'])) {
      $existing_settings['title_slug_rules'] = array();
  }
  ```

### 3. `includes/class-bws-taxonomy-manager.php`
- **Line 75**: Add `require_once` for title-slug handler
- **Line 157**: Add `'title_slug' => new BWS_Title_Slug_Handler($this->settings)` to `$handlers` array

### 4. `includes/class-bws-settings.php`
- **Line 29**: Add `'title_slug_rules' => array()` to `$defaults`
- **`$tab_to_keys`**: Add `'title-slug' => array('title_slug_rules')`
- **`sanitize_all_settings()`**: Add `sanitize_title_slug_rules()` call
- **New `sanitize_title_slug_rules()` method**: validate `post_type` + at least one pattern; `slug_mode` defaults to `'replace'`; `date_field` required if `date_escalation` but no error if blank (uses pub date)
- **Save messages**: Add `'title-slug'` entry
- **Nav tab**: Add "Title & Slug Rules" tab before "General Settings"
- **Tab panel**: Add `<div id="title-slug">` calling `render_title_slug_rules()`
- **New render methods** (`render_title_slug_rules()` + `render_title_slug_rule()`):
  - Empty state + "Add Rule" button
  - Form fields:
    - Rule Name (text)
    - Post Type (select — public post types)
    - **Title Pattern** (text + collapsible token reference, optional)
    - **Slug Pattern** (text, optional)
    - Slug Mode (select: replace/prefix/suffix) — visible when slug pattern non-empty
    - Date Escalation (checkbox) — visible when title_pattern or slug_pattern non-empty
    - Date Field (text, conditional on escalation checkbox) — note "Leave blank to use post publication date"
  - Token reference collapsible: full table of all tokens with title/slug column examples
  - Per-rule **last-applied** line: "Last applied: [date] on [post title]" (always shown after first run)
  - Per-rule **warnings log**: shown only when entries exist; last 10 warnings with timestamps, oldest rotated out
  - Per-rule **Preview** button: AJAX call → modal showing current vs. preview title/slug for most recent matching post, with per-token warnings

### 5. `assets/js/admin.js`
- Add-rule handler for title-slug tab (clone template pattern)
- Show/hide slug mode + escalation fields based on whether slug pattern field has content
- Slug mode smart default: if slug pattern contains `{default_slug}` → lock mode to `replace` + disable selector + show hint; else if mode not yet manually touched → default to `prefix`; else respect user's choice. "Touched" flag set on first manual mode change.
- Show/hide date field based on escalation checkbox
- "Leave blank = publication date" hint on date field
- "Apply to existing posts" button per rule: AJAX batch loop (offset 0, 50, 100...) with progress indicator, same pattern as existing manual processing buttons
- "Preview" button per rule: AJAX call → render modal with current vs. preview title/slug table + warnings
- Last-run status display: read from `bws_title_slug_rule_status` option and render inline under each rule

---

## Verification

1. **Title only**: `{acf:first_name} {acf:last_name}` → "John Smith"; `post_name` unchanged
2. **Slug only**: `{acf:first_name}-{acf:last_name}` → "john-smith"; `post_title` unchanged
3. **Both together**: title "John Smith" + slug "john-smith" in a single `wp_update_post()` call
4. **`{default_slug}` uses computed title**: Title pattern `{acf:first_name} {acf:last_name}`, Slug pattern `{default_slug}` → slug derives from rule-computed title
5. **Separator trimming — pub_year empty**: `{default_title} ({pub_year})` → "My Event" (no dangling `(`)
6. **Separator trimming — term empty**: `{term:category}: {default_title}` → "My Post" (no leading `: `)
7. **Separator trimming — middle empty**: `{acf:a} - {acf:b} - {acf:c}` with b empty → "Smith - Jones"
8. **Date month in title**: `{date_month:event_date}` in title context → "March" (not "03")
9. **`{meta:field}` source-agnostic**: ACF text field and Meta Box text field with same key → same result via `get_post_meta()`
10. **`{date_year:field}` auto-detection**: ACF date field (Ymd format stored) → "2024"; Meta Box date field (Y-m-d stored) → "2024"
11. **Prefix mode**: Slug pattern `{pub_year}`, mode=prefix, title "My Event" → "2024-my-event"
12. **`{terms:taxonomy}`**: Two terms "Finance", "News" → slug "finance-news", title "Finance, News"
13. **Collision → numeric**: Two posts same slug → second gets "-2"
14. **Date escalation — year**: Two posts same year → second: year+month
15. **Date escalation — hour**: Two same date+hour events → tries hour, minute, then numeric
16. **Date escalation blank field**: Uses `post_date` when date_field left empty
17. **Re-save idempotency**: "Easter Sunrise Service" → rule produces "2026 Easter Sunrise Service" → re-save without changes → title unchanged, no extra DB write
18. **Inverse strip — user edits base portion**: "2026 Easter Sunrise Service" → user edits to "2026 Easter Early Service" → rule produces "2026 Easter Early Service" (not "2026 2026 Easter Early Service")
19. **Inverse strip — user removes prefix**: "2026 Easter Sunrise Service" → user edits to "Easter Sunday Service" (strips year) → inverse strip fails → rule re-applies → "2026 Easter Sunday Service"
20. **Source field update**: Date field changes from 2025→2026, title untouched → rule produces "2026 Easter Sunrise Service" (not "2026 2025 Easter Sunrise Service")
21. **Blank title + implicit slug**: Post saved with empty title field, title pattern `{acf:first_name} {acf:last_name}`, no slug pattern → title = "John Smith", slug = "john-smith" (not "42" or "")
22. **Slug mode smart default**: slug pattern entered without `{default_slug}` → mode auto-selects `prefix`; pattern with `{default_slug}` → mode locked to `replace`, selector disabled
23. **First-match priority**: Two rules same post type → first applies
22. **MCP verification**: Use `metamanagermcp` to create test posts, inspect `post_title` and `post_name`
