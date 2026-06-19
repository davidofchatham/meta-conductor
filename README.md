# Meta Conductor

WordPress plugin for unified meta and taxonomy management. Rule-based automation that auto-sets terms, formats fields, restricts taxonomy depth, and (planned) personalizes per user.

> **Status**: pre-release (`0.x`), **not production-ready**. Actively developed and in limited use by the author on their own sites — dogfooding to find what needs to change. The `0.x` line is unstable: schema, option keys, and public API may change between pre-releases, and **there is no guaranteed upgrade/migration path** — a breaking change may require you to re-enter rules. Use at your own risk. The first production-ready cut ships as `1.0.0`. See [ROADMAP.md](ROADMAP.md) for the phase plan.

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
cd meta-conductor
composer install --no-dev
```

Composer pulls `tdrayson/wp-wireframe` (~1.0.6) and `rakit/validation`. The `vendor/` folder is committed so deployments without Composer access still work.

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

- **PSR-4 namespacing**: all `includes/` classes live under `BWS\MetaConductor\…`, autoloaded via root `autoload.php` (kebab `class-{name}.php`). Reference global WordPress classes with a leading backslash (`\WP_Query`, `\WP_Post`).
- **Storage abstraction**: handlers read rules via `Storage\StorageFactory::get_instance()->get_rules(...)`. Never `get_option()` directly.
- **Entity abstraction**: handlers use `Core\Entity` for posts/terms/users/comments. Never `get_post()` / `get_term()` directly.
- **Settings UI**: lives in WP Wireframe-driven config classes under `includes/admin/config/`. Each rule type has its own `section()` method composed into tabs by `Admin\Config\WireframeConfig::build()`.
- **Schema stability is case-by-case (pre-1.0)**: no blanket backward-compatibility guarantee. For rule types not yet in real use, schema changes are fair game with no migration code. For a rule type the author is actively running on a live site, a breaking change is made deliberately — either with a migration step or an explicit manual re-save — to avoid silently destroying in-use data. Decide per change based on what's actually deployed.
- **PHP 8.1+ syntax** OK throughout (constructor promotion, enums, readonly properties, etc.).

## Project status

| Area | State |
|---|---|
| Unified framework (Entity, RuleEngine, Storage, ConditionEvaluator, ActionExecutor) | ✅ Built |
| Storage abstraction (wp_options) | ✅ Built (CPT impl pending Phase 4) |
| Handlers on unified base | 3 of 7 — hierarchical, title_slug, related. 4 legacy (level-restriction, propagation, related-post-terms, time-based) migrate in Phase 3. |
| Wireframe-driven settings UI (Phase 2c) | ✅ Shipped |
| PSR-4 namespacing (Phase 2a) | ✅ Shipped (0.4.0) |
| Naming rename to Meta Conductor (Phase 2b) | 🔄 Main file + headers renamed; `__()` text-domain string args still read `bws-*` (cosmetic, private plugin) |
| Multi-post-type Related rules (Phase 3a) | ✅ Shipped — Related rules span multiple post types |
| CPT storage backend (Phase 4) | ⏳ |
| Unified Migration/Preview tool (Phase 7) | ⏳ Hosts bulk operations: Title/Slug Apply, ACF conversion, future field transforms |

## License

GPL-2.0-or-later.
