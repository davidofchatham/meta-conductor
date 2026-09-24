# Meta Conductor

WordPress plugin for unified meta and taxonomy management. Rule-based automation that auto-sets terms, formats fields, and restricts taxonomy depth.

> **Status**: pre-release (`0.x`), **not production-ready**. Actively developed and in limited use by the author on their own sites — dogfooding to find what needs to change. The `0.x` line is unstable: schema, option keys, and public API may change between pre-releases, and **there is no guaranteed upgrade/migration path** — a breaking change may require you to re-enter rules. Use at your own risk.

## What it does, requirements, install

See [readme.txt](readme.txt) — the plugin's own readme, and the canonical copy of the feature list, requirements, install steps, and per-release upgrade notes.

## Where the docs live

| Doc | Use it for |
|---|---|
| [readme.txt](readme.txt) | Features, requirements, install, upgrade notes. |
| [docs/architecture.md](docs/architecture.md) | How the rule engine, handlers, storage, and Wireframe UI fit together. |
| [docs/future-work.md](docs/future-work.md) | Non-bug work: ideas, planned features, refactors, open questions. Canonical `FW-N` index. |
| [CHANGELOG.md](CHANGELOG.md) | Release log. |
| [Issues](https://github.com/davidofchatham/meta-conductor/issues) | Bugs, refactors, and the spec for whatever feature is in flight. |

## Acknowledgements

### Libraries

- Settings and admin UI are built with [WP Wireframe](https://github.com/tdrayson/wp-wireframe) by [Taylor Drayson](https://github.com/tdrayson) (GPL-2.0-or-later), installed via Composer at [`vendor/tdrayson/wp-wireframe/`](vendor/tdrayson/wp-wireframe/). Wireframe's form validation is provided by [rakit/validation](https://github.com/rakit/validation) by [Muhammad Syifa](https://github.com/rakit) (MIT-licensed), pulled in as a transitive dependency at [`vendor/rakit/validation/`](vendor/rakit/validation/).
- In-WordPress update notices and one-click updates are powered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by [Yahnis Elsts](https://github.com/YahnisElsts) (MIT-licensed), bundled at [`libs/plugin-update-checker/`](libs/plugin-update-checker/).
