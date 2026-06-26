# Meta Conductor

WordPress plugin for unified meta and taxonomy management. Rule-based automation that auto-sets terms, formats fields, restricts taxonomy depth, and (planned) personalizes per user.

> **Status**: pre-release (`0.x`), **not production-ready**. Actively developed and in limited use by the author on their own sites — dogfooding to find what needs to change. The `0.x` line is unstable: schema, option keys, and public API may change between pre-releases, and **there is no guaranteed upgrade/migration path** — a breaking change may require you to re-enter rules. Use at your own risk.

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

## Where the docs live

| Doc | Use it for |
|---|---|
| [README.md](README.md) | You are here. Landing. |
| [docs/architecture.md](docs/architecture.md) | How the rule engine, handlers, storage, and Wireframe UI fit together. |
| [docs/future-features.md](docs/future-features.md) | Ideas / planned / blocked features. Canonical list. |
| [ROADMAP.md](ROADMAP.md) | Phase plan, decisions, what comes next. |
| [CHANGELOG.md](CHANGELOG.md) | Release log. |
| [.claude/plans/](.claude/plans/) | Per-feature implementation plans. |
| `SPEC.md` (when present at repo root) | Active in-flight spec for the current feature. |

## Requirements

- PHP 8.1+ (strictly enforced — plugin deactivates on older PHP)
- WordPress 6.5+
- ACF Pro for ACF-driven rule types and the Conversion tool (optional otherwise)

## License

GPL-2.0-or-later.

## Acknowledgements

### Libraries

- Settings and admin UI are built with [WP Wireframe](https://github.com/tdrayson/wp-wireframe) by [Taylor Drayson](https://github.com/tdrayson) (GPL-2.0-or-later), installed via Composer at [`vendor/tdrayson/wp-wireframe/`](vendor/tdrayson/wp-wireframe/). Wireframe's form validation is provided by [rakit/validation](https://github.com/rakit/validation) by [Muhammad Syifa](https://github.com/rakit) (MIT-licensed), pulled in as a transitive dependency at [`vendor/rakit/validation/`](vendor/rakit/validation/).
- In-WordPress update notices and one-click updates are powered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by [Yahnis Elsts](https://github.com/YahnisElsts) (MIT-licensed), bundled at [`libs/plugin-update-checker/`](libs/plugin-update-checker/).
