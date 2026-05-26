# Meta Conductor

WordPress plugin for unified meta and taxonomy management. Rule-based automation that auto-sets terms, formats fields, restricts taxonomy depth, and (planned) personalizes per user.

> **Current version**: 2.0.0 (unreleased; never deployed). Phase 2c (Wireframe UI swap) in progress. See [ROADMAP.md](ROADMAP.md) for the full phase plan.

## What it does

Out of the box (or near it):

| Capability | Driven by |
|---|---|
| Auto-set terms from a hierarchical taxonomy tree | Hierarchical rules |
| Cascade terms from a parent post to its children | Propagation rules |
| Copy terms from a post referenced via ACF | Related Post Terms rules |
| Map a trigger term/taxonomy → a target term | Related Term rules |
| Apply a term during a date window | Time-Based rules |
| Restrict which depths of a hierarchical taxonomy a post may carry | Level Restriction rules |
| Generate post titles and slugs from a pattern of tokens | Title & Slug rules |
| Migrate ACF field data into taxonomy terms (one-shot wizard) | Conversion tool |

Planned: user-based rules, date-field comparisons, name/phone formatting, unified Migration/Preview tool (see [ROADMAP.md](ROADMAP.md)).

## Architecture at a glance

Rule engine pattern:

```
WordPress hooks → Handlers → Rule Engine → Entity operations
                     ↓
              Storage abstraction
                     ↓
              wp_options (current) / CPT (planned)
```

Deep dive: [docs/architecture.md](docs/architecture.md).

## Requirements

- PHP 8.1+ (strictly enforced — plugin deactivates on older PHP)
- WordPress 6.5+
- ACF Pro for ACF-driven rule types and the Conversion tool (optional otherwise)

## Install (development)

```bash
git clone <repo>
cd bws-meta-manager
composer install --no-dev
```

Composer pulls `tdrayson/wp-wireframe` (~1.0.5) and `rakit/validation`. The `vendor/` folder is committed so deployments without Composer access still work.

## Where the docs live

| Doc | Use it for |
|---|---|
| [README.md](README.md) | You are here. Landing + install. |
| [docs/architecture.md](docs/architecture.md) | How the rule engine, handlers, storage, and Wireframe UI fit together. |
| [docs/future-features.md](docs/future-features.md) | Ideas / planned / blocked features. Canonical list. |
| [ROADMAP.md](ROADMAP.md) | Phase plan, decisions, what comes next. |
| [CHANGELOG.md](CHANGELOG.md) | Release log. |
| [.claude/plans/](.claude/plans/) | Per-feature implementation plans. |
| `SPEC.md` (when present at repo root) | Active in-flight spec for the current feature. |

## Development conventions

- **Storage abstraction**: handlers read rules via `BWS_Storage_Factory::get_instance()->get_rules(...)`. Never `get_option()` directly.
- **Entity abstraction**: handlers use `BWS_Entity` for posts/terms/users/comments. Never `get_post()` / `get_term()` directly.
- **Settings UI**: lives in WP Wireframe-driven config classes under `includes/admin/config/`. Each rule type has its own `section()` method composed into tabs by `BWS_Wireframe_Config::build()`.
- **No legacy compatibility**: 2.0 was never deployed. Schema changes are fair game; no migration code needed until first deploy.
- **PHP 8.1+ syntax** OK throughout (constructor promotion, enums, readonly properties, etc.).

## Project status

| Area | State |
|---|---|
| Unified framework (Entity, RuleEngine, Storage, ConditionEvaluator, ActionExecutor) | ✅ Built |
| Storage abstraction (wp_options) | ✅ Built (CPT impl pending Phase 4) |
| Handlers on unified base | 2 of 7 — hierarchical + title_slug. 5 legacy migrate in Phase 3. |
| Wireframe-driven settings UI (Phase 2c) | 🔄 In progress on `claude/wireframe-swap-2c` |
| Naming rename to Meta Conductor (Phase 2b) | ⏳ Queued after 2c |
| PSR-4 namespacing (Phase 2a) | ⏳ Queued after 2b |
| CPT storage backend (Phase 4) | ⏳ |
| Unified Migration/Preview tool (Phase 7) | ⏳ Hosts bulk operations: Title/Slug Apply, ACF conversion, future field transforms |

## License

GPL-2.0-or-later.
