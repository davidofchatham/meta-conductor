# Architecture

Deep dive into how Meta Conductor's pieces fit together. For project status and phase plan see [ROADMAP.md](../ROADMAP.md). For release log see [CHANGELOG.md](../CHANGELOG.md).

## Three-layer rule engine

```
WordPress hooks (save_post, etc.)
    ↓
Handlers (one per rule type)
    ↓
Rule Engine (orchestrator)  ←→  Condition Evaluator + Action Executor
    ↓
Entity Abstraction (BWS_Entity)
    ↓
WordPress core (posts, terms, users, comments)
```

## Core components

### Entity abstraction — `BWS_Entity`

[includes/core/class-bws-entity.php](../includes/core/class-bws-entity.php)

Polymorphic wrapper over WP entities. Handlers and the rule engine never touch `get_post()` / `get_term()` / `get_user_meta()` directly — they go through `BWS_Entity`.

```php
$entity = new BWS_Entity('post', 123);
$entity->get_meta('field_name');
$entity->apply_terms([4, 5], 'category');
$entity->get_acf_field('relationship_field');
```

Why: keeps rule logic agnostic about the underlying WP storage. A rule that "applies a term" works the same whether the target is a post, a term, a user, or a comment.

### Rule engine — `BWS_Rule_Engine`

[includes/core/class-bws-rule-engine.php](../includes/core/class-bws-rule-engine.php)

Orchestrates the pipeline for any rule:

1. Resolve source entities from rule config (with filters)
2. Evaluate conditions via `BWS_Condition_Evaluator`
3. Resolve target entities (often self, sometimes children/parent/related)
4. Execute the action via `BWS_Action_Executor`

Currently only `BWS_Title_Slug_Handler` and (partially) `BWS_Hierarchical_Handler` use the unified engine. Phase 3 of the [ROADMAP](../ROADMAP.md) migrates the rest.

### Storage abstraction — `BWS_Storage_Factory` + `BWS_Rule_Storage`

[includes/storage/](../includes/storage/)

15-method interface (`BWS_Rule_Storage`) with one current implementation (`BWS_Option_Rule_Storage` → wp_options). A CPT implementation is planned for Phase 4.

Access pattern handlers use:

```php
$storage = BWS_Storage_Factory::get_instance();
$rules   = $storage->get_rules('hierarchical_rules', ['enabled' => true]);
```

**Critical**: handlers never call `get_option()` directly. The storage layer is also the canonical-shape adapter (see "Canonical shape" below).

### Handler base — `BWS_Unified_Handler_Base`

[includes/abstracts/class-bws-unified-handler-base.php](../includes/abstracts/class-bws-unified-handler-base.php)

New handlers extend this. Provides:

- `get_enabled_rules()` / `get_all_rules()` / `get_rule($id)` reading via storage
- `save_rule()` / `delete_rule()` mutating via storage
- Hook registration scaffolding for `save_post`, etc.

Legacy `BWS_Handler_Base` (in [includes/abstracts/class-bws-handler-base.php](../includes/abstracts/class-bws-handler-base.php)) still hosts 5 of 7 handlers. Phase 3 migrates them; this base class then disappears.

## Rule types and storage keys

All seven rule types serialize under a single `wp_options` key (currently `bws_meta_conductor_settings`). Each type stores an array of rule rows.

| Storage key | Handler | Hook | Source of truth |
|---|---|---|---|
| `hierarchical_rules` | `BWS_Hierarchical_Handler` | `save_post` | Term hierarchy walk |
| `propagation_rules` | `BWS_Propagation_Handler` | `save_post` (on parent) | Parent post → children |
| `related_rules` | `BWS_Related_Handler` | `save_post` | Term-to-term mapping |
| `time_based_rules` | `BWS_Time_Based_Handler` | `save_post` + cron | Date window |
| `related_post_terms_rules` | `BWS_Related_Post_Terms_Handler` | `save_post` | ACF relationship/post-object field |
| `hierarchical_level_restriction_rules` | `BWS_Hierarchical_Level_Restriction_Handler` | `save_post` | Term depth check |
| `title_slug_rules` | `BWS_Title_Slug_Handler` | `acf/save_post` (or `save_post` priority 99) | Token pattern expansion |

Plus global keys in the same option:

- `conflict_handling_overrides` — repeater of `{taxonomy, mode}` rows; storage adapter flattens to `{slug: mode}` dict for handler consumption
- `manual_processing_enabled` — toggle for "Apply to existing posts" UI

## Settings UI — WP Wireframe

[includes/admin/](../includes/admin/)

The settings UI is a React app provided by `tdrayson/wp-wireframe`. Each rule type has a config class under [includes/admin/config/](../includes/admin/config/) exposing a `section()` method. The top-level composer [class-wireframe-config.php](../includes/admin/config/class-wireframe-config.php) assembles tabs from sections:

| Tab | Sections |
|---|---|
| Auto-Set Terms | Propagation, Related Post Terms (ACF), Time-Based, Related Terms, Hierarchical |
| Format & Transform | Title & Slug. Future: date / name / phone field transforms |
| Restrict | Hierarchical Level Restrictions |
| Personalize by User | Placeholder (UBT merge pending) |
| General | Per-taxonomy conflict handling overrides, manual processing toggle |

Boot path: [includes/admin/class-wireframe-bootstrap.php](../includes/admin/class-wireframe-bootstrap.php) calls `\Wireframe\App::boot()` on `init` priority 10 with the assembled config.

### Why Wireframe

Replaces a ~2,000-line hand-rolled god class settings file plus 1,700-line jQuery template-cloning admin.js. Wireframe gives us:

- React-based repeater fields with sortable/collapsible/duplicate
- Server-side validation via Rakit
- REST endpoints for save/load
- `@wordpress/components` UI consistency

### Wireframe quirks worth knowing

- **Single-page `App::boot()` ignores `menu_slug`** ([bug, src/App.php:135](../vendor/tdrayson/wp-wireframe/src/App.php)). Workaround: use multi-page mode with one page.
- **No client-side field type extension API**. Custom field types declared via `wp-wireframe/field_types` filter handle sanitize/validate server-side but don't render in React. Use stock field types only.
- **Subfield conditions don't evaluate client-side** ([RepeaterEdit.js:162](../vendor/tdrayson/wp-wireframe/js/components/fields/RepeaterEdit.js)). Conditional subfields render always. Workaround: drop `conditions` on subfields, add description text explaining when each applies.
- **Dot-notation field IDs are skipped by Sanitizer** ([src/Framework/Sanitizer.php](../vendor/tdrayson/wp-wireframe/src/Framework/Sanitizer.php)). Don't use `field.subkey` syntax for save fields — use repeaters with subfields instead.

## Canonical shape adapter

Wireframe writes some fields differently than handlers expect:

| Field | Wireframe writes | Handlers expect |
|---|---|---|
| `related_rules.trigger_term_id`, `.target_term_id` | `[id]` array (from `multiple+max=1` FormTokenField) | int |
| `time_based_rules.target_term_id` | `[id]` array | int |
| `related_post_terms_rules.acf_field_name` | `"post_type:field_name"` prefixed | Bare `field_name` + separate `post_type` |

`BWS_Option_Rule_Storage::normalize_rule_shape()` coerces on read. Storage is the adapter boundary between writers (current: Wireframe REST) and handlers. Future writers (CLI? import?) plug in at the same boundary.

## Custom fields and helpers

[includes/admin/config/class-config-helpers.php](../includes/admin/config/class-config-helpers.php) centralizes option builders called at config-build time:

- `taxonomy_options()` / `hierarchical_taxonomy_options()`
- `post_type_options()` / `hierarchical_post_type_options()`
- `all_term_options()` — flat list of every term across public taxonomies, labelled `"Taxonomy: Term"`. Designed for sites with < ~500 terms.
- `acf_relationship_field_options()` — every ACF `relationship`/`post_object` field across all groups, labelled `"Post Type: Field Label (field_name)"`, keyed `"post_type:field_name"`.

## Data conversion tool

[includes/conversion/](../includes/conversion/)

Multi-step wizard for ACF → taxonomy data migration. Lives at the `meta-conductor-conversion` admin subpage under the Meta Conductor menu. Hooks unchanged from the legacy implementation; only the entry-point method (`render_page()` vs the old `render_tab_content()`) changed for Phase 2c.

Phase 7 of the [ROADMAP](../ROADMAP.md) absorbs this into a unified Migration / Preview tool that also hosts Title/Slug bulk-apply and future field transforms.

## Diagnostics page

[includes/admin/class-diagnostics.php](../includes/admin/class-diagnostics.php)

Subpage under Meta Conductor menu. Visible when `WP_DEBUG` is on, or via filter `bws_meta_conductor_show_diagnostics`. Dev-only Storage section dumps the raw option contents; future user-level sections (rule counts, handler status) will hang here without dev mode.

## File layout

```
bws-taxonomy-manager.php        Main plugin file (rename → meta-conductor.php in Phase 2b)
composer.json                   Composer dependencies
vendor/                         Composer-installed deps; committed for deploys without composer
README.md                       Repo landing
readme.txt                      WP plugin standard format
ROADMAP.md                      Phase plan
CHANGELOG.md                    Release log
docs/architecture.md            This file
includes/
  abstracts/                    Handler base + storage interface (relocate → handlers/ + storage/ in Phase 2a)
  admin/
    class-wireframe-bootstrap.php
    class-diagnostics.php
    config/                     Per-rule-type Wireframe config classes
  conversion/                   ACF conversion tool (Phase 7 reabsorb)
  core/                         Entity, RuleEngine, ConditionEvaluator, ActionExecutor
  handlers/                     Rule type handlers (Phase 3 migrates 5 of 7 to unified base)
  integrations/                 ACF, Admin Columns Pro
  lib/                          BatchProcessor, FieldConverter, ValueMapper, TermMigrator (Phase 7 absorbs into Conversion namespace)
  storage/                      Storage factory + options implementation
assets/
  js/conversion-admin.js        Conversion wizard JS
  css/conversion-admin.css      Conversion wizard CSS
.claude/plans/                  Per-feature implementation plans
```

## Conventions

- **PHP 8.1+ syntax** OK throughout (constructor promotion, enums, readonly, match expressions).
- **WordPress coding standards** are not strictly followed — modern PHP idioms preferred where they conflict.
- **No legacy compatibility code**: 2.0.0 was never deployed. Schema changes are fair game pending first deploy.
- **Hooks naming**: `bws_meta_conductor_{context}_{action}` (the `bws_` prefix stays for global-namespace collision safety; see [ROADMAP.md](../ROADMAP.md) Naming Surface table).
- **Option key**: `bws_meta_conductor_settings`. Legacy `bws_taxonomy_manager_settings` is dead and unread.

## SPEC lifecycle

When a substantive feature is in flight, a `SPEC.md` at repo root captures invariants and tasks (see the `spec` skill). After the feature ships, post-ship cleanup is mandatory:

1. Load-bearing invariants migrate into this file (or into PHPDoc on enforcing functions, whichever is closer to the code).
2. Closed/deferred tasks are deleted.
3. Bugs migrate to GitHub Issues.
4. SPEC.md is truncated to a one-line placeholder.

Active SPECs are the source of truth only while in flight. After ship, `docs/architecture.md` + PHPDoc + CHANGELOG + Issues take over.
