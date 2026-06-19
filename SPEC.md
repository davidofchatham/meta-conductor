# SPEC — Phase 2a: PSR-4 Namespacing

> In-flight spec. Caveman encoding. Post-ship: migrate §V to PHPDoc/docs/architecture.md, close §T, §B→Issues, truncate.

## §G — Goal

Replace manual `require_once` chains with PSR-4 autoloader. All `includes/` classes get `BWS\MetaConductor\` namespace, drop `BWS_` prefix. Pure structure. No behavior change. No user-visible change. Independently revertable in one commit.

## §C — Constraints

- C1. PHP 8.1+ (header `Requires PHP: 8.1`). Namespaces, `::class` fine.
- C2. No behavior change. Same hooks fire, same option key, same admin pages, same REST save endpoint. Diff is structure only.
- C3. `vendor/` (composer: wp-wireframe, rakit) + `libs/` (vendored PUC) UNTOUCHED. Not first-party, not namespaced, not PSR-4.
- C4. No PHPUnit/CI yet. BUT local PHP 8.5.1 CLI available on dev box → static harnesses runnable (lint + autoload-resolution). Behavior verification still manual on InstaWP. See §I.test, §I.harness.
- C5. No WP runtime on dev box. `r:` = remote mount, no WP-CLI/no PHP exec against live. Sync is manual robocopy. Harnesses run WITHOUT WP (classes declare, don't execute, on load) — stub `ABSPATH` + few WP funcs, require `vendor/autoload.php` for vendor-iface/base resolution.
- C6. Autoloader kebab converter `preg_replace('/([a-z])([A-Z])/','$1-$2',$x)` breaks on consecutive caps. Class `CptRuleStorage` ok; `CPTRuleStorage` → `cptrule-storage.php` (wrong). Forbid consecutive-cap class names.
- C7. Autoloader hardcodes `class-` file prefix for ALL types incl interfaces. Interface files MUST be `class-{name}.php`, not `interface-{name}.php`.
- C8. WP loads main plugin file at known path; everything else autoloaded. `meta-conductor.php` stays global-namespace (WP hook funcs, activation hooks).
- C9. Phase 2a does NOT migrate legacy handlers off `BWS_Handler_Base` (that = Phase 3). It DOES namespace them uniformly now.
- C10. Phase 2a does NOT do text-domain / nonce / constant-alias sweep (that = Phase 2b). Only structural rename.

## §I — Surfaces

- I.autoload — new `autoload.php` at plugin root. Adapted from `D:\...\bws-portal-system\autoload.php`. Changes: prefix `BWS\Portal\`→`BWS\MetaConductor\`, const `BWS_PORTAL_PATH`→`BWS_META_CONDUCTOR_PATH`, base dir `includes/`. Registered via `spl_autoload_register` in main file.
- I.const — new `BWS_META_CONDUCTOR_PATH` = `plugin_dir_path(__FILE__)` in `meta-conductor.php`. (Distinct from existing `BWS_META_MANAGER_PLUGIN_DIR`; both may coexist this phase.)
- I.ns — root namespace `BWS\MetaConductor\`. Subns map to dirs:
  - `Core\` → `includes/core/`
  - `Handlers\` → `includes/handlers/` (incl abstract bases)
  - `Storage\` → `includes/storage/` (incl interface)
  - `Conversion\` → `includes/conversion/`
  - `Admin\` → `includes/admin/`
  - `Admin\Config\` → `includes/admin/config/`
  - `Integrations\` → `includes/integrations/`
  - `Support\` → `includes/support/` (was `includes/lib/`; renamed to avoid `libs/` collision)
  - root `BWS\MetaConductor\` → `includes/` (main class `TaxonomyManager`, compat `Settings`)
- I.move — `includes/abstracts/` ELIMINATED:
  - `BWS_Unified_Handler_Base` → `includes/handlers/class-unified-handler-base.php` → `Handlers\UnifiedHandlerBase`
  - `BWS_Handler_Base` → `includes/handlers/class-handler-base.php` → `Handlers\HandlerBase`
  - `BWS_Rule_Storage` iface → `includes/storage/class-rule-storage.php` → `Storage\RuleStorage`
- I.support — `includes/lib/` → `includes/support/`. 8 files flatten (drop the per-module subdirs OR keep — see T). Class+iface names drop `BWS_`, gain `Support\`:
  - `BWS_Batch_Processor` → `Support\BatchProcessor`; iface `BWS_Batch_Processor_Interface` → `Support\BatchProcessorInterface` (file `class-batch-processor-interface.php`)
  - `BWS_Term_Migrator` → `Support\TermMigrator` (+ `TermMigratorInterface`)
  - `BWS_Field_Converter` → `Support\FieldConverter` (+ `FieldConverterInterface`)
  - `BWS_Value_Mapper` → `Support\ValueMapper` (+ `ValueMapperInterface`)
- I.harness — committed dev harnesses in `tests/` (export-ignored from ZIP). Run via local PHP 8.5.1, no WP:
  - `tests/lint.php` (or shell): `php -l` every changed `.php`. (H1)
  - `tests/verify-autoload.php`: stub `ABSPATH`+minimal WP funcs, define `BWS_META_CONDUCTOR_PATH`, require `vendor/autoload.php` then `autoload.php`, then assert `class_exists`/`interface_exists` for EVERY expected FQN (full list per I.ns/I.move/I.support). Exit nonzero on any miss. Reusable in Phase 2b/3 + future plugins. (H2)
- I.test — verification surfaces (manual, post-sync, on InstaWP https://metamanager.instawp.co/):
  - plugin activates clean (no fatal, no autoload warning)
  - settings page loads, all tabs/sections render (Wireframe config chain resolves)
  - save a rule via REST endpoint → persists
  - diagnostics subpage loads (WP_DEBUG on)
  - one rule of each handler type fires on post save (hierarchical, propagation, related, time-based, related-post-terms, level-restriction, title-slug)
  - ACF conversion UI loads + dry-run preview
  - WP-CLI conversion command resolves classes (if CLI available on host)

## §V — Invariants

- V1. After phase: ZERO `require_once`/`include` of `includes/*` class files anywhere except autoloader. (`meta-conductor.php` keeps `vendor/autoload.php`, PUC `load-v5p7.php` requires — those are C3 vendored.)
- V2. Every file under `includes/` declares `namespace BWS\MetaConductor\…;` matching its dir per I.ns. No global-namespace class left under `includes/`.
- V3. Every cross-class reference resolves: each file referencing another plugin class has a matching `use` stmt OR FQN. No bareword `BWS_*` survives anywhere (greppable check: `grep -rn '\bBWS_[A-Z]' includes/` → 0 class hits; constants like `BWS_META_*` allowed only in main file).
- V4. No class name has consecutive capitals (C6). Audit: `RuleStorage` not `RuleStorage`-ok, watch acronyms (ACF→`Acf`, CLI→`Cli`, UI→`Ui`, CPT→`Cpt`). E.g. `BWS_ACF_Integration` → `Integrations\AcfIntegration`, `BWS_Conversion_CLI` → `Conversion\ConversionCli`, `BWS_Conversion_UI` → `Conversion\ConversionUi`.
- V5. Every class/interface file named `class-{kebab}.php` where `{kebab}` = autoloader transform of class name (C7). Interface files use `class-` prefix, NOT `interface-`.
- V6. `includes/abstracts/` dir deleted. `includes/lib/` dir deleted (→ `includes/support/`).
- V7. Behavior parity (C2): manual §I.test sweep all green. Same hooks registered (compare `grep -c add_action\|add_filter` shape before/after).
- V8. Revertable: phase lands as commits on a `claude/psr4-2a` branch; single revertable merge. No interleaved Phase 2b/2c edits.
- V9. `meta-conductor.php` stays global namespace; only change = swap require chain for `require autoload.php` + remove the 12 manual `require_once includes/*` lines (keep vendor + PUC + bootstrap requires that gate on `class_exists`). Bootstrap/diagnostics requires (lines 71,77) → drop, autoloaded.
- V10. String-class safety: re-grep `['"]BWS_[A-Z]` found 2 LIVE quoted class strings (Explorer missed) — BOTH must become FQN-string on rename:
  - `includes/admin/class-wireframe-bootstrap.php:52` — `class_exists('BWS_Conversion_UI')` → `class_exists(Conversion\ConversionUi::class)` (or FQN string); `class_exists('BWS_Taxonomy_Manager')` → `class_exists(TaxonomyManager::class)`.
  - `includes/storage/class-bws-storage-factory.php:76` — `class_exists('BWS_CPT_Rule_Storage')` is COMMENTED OUT (dead). No action now; FUTURE V4 watch: when un-commented use `CptRuleStorage` not `CPTRuleStorage` (C6 kebab break).
  - Non-class `'BWS_*'` literals are constants (`BWS_RULE_STORAGE_TYPE`, `BWS_META_MANAGER_VERSION`) — safe, leave.
  - No `call_user_func`/`$class()` dynamic instantiation on plugin classes. `get_class()`/`::class`/`[self::class,...]` reference-safe under rename. Re-grep before commit: only the constants + (intentionally) FQN'd refs remain.
- V11. `tests/verify-autoload.php` (H2) passes: every expected FQN per I.ns/I.move/I.support resolves via `autoload.php` (class_exists/interface_exists true), exit 0. Catches V2/V4/V5/V6 mechanically. `php -l` (H1) clean on all changed files.

## §T — Tasks

```
id  | st | task                                                                 | cites
T1  | x  | create autoload.php from Portal template; prefix+const swap          | I.autoload,C6,C7,V5
T2  | x  | add BWS_META_CONDUCTOR_PATH + spl_autoload_register in meta-conductor.php | I.const,V9
T3  | .  | rename includes/lib/ -> includes/support/ (git mv, keep/flatten subdirs) | I.support,V6
T4  | .  | move abstracts: unified-base+handler-base->handlers/, rule-storage iface->storage/; rename files class- prefix | I.move,V5,V6
T5  | .  | namespace + rename Support\ classes+ifaces (8 files); drop BWS_ prefix | I.support,V2,V4,V5
T6  | .  | namespace Core\ (Entity,RuleEngine,ConditionEvaluator,ActionExecutor) + internal use stmts | I.ns,V2,V3
T7  | .  | namespace Storage\ (RuleStorage iface, OptionRuleStorage, StorageFactory) | I.ns,V2,V3,V4
T8  | .  | namespace Handlers\ (2 bases + 7 handlers incl 5 legacy); extends via use | I.ns,C9,V2,V3
T9  | .  | namespace Conversion\ (Manager,Cli,Ui,FieldMapper,DataProcessor,PreviewSystem); ref Support\ via use | I.ns,V2,V3,V4
T10 | .  | namespace Integrations\ (AcfIntegration,AdminColumnsIntegration)       | I.ns,V2,V4
T11 | .  | namespace Admin\ (Diagnostics,WireframeBootstrap) + Admin\Config\ (11 config classes) | I.ns,V2,V3
T12 | .  | namespace root TaxonomyManager + Settings; fix all `new BWS_*`/type hints via use | I.ns,V2,V3
T13 | .  | update meta-conductor.php: drop 12 require_once includes/* ; drop bootstrap/diag requires; FQN the class refs (TaxonomyManager, WireframeBootstrap, Diagnostics) | V1,V9
T14 | .  | build tests/verify-autoload.php (H2): WP stubs + vendor+autoload require + class_exists/interface_exists every FQN; build tests/lint helper (H1) | I.harness,V11
T15 | .  | run H1+H2 (local php 8.5.1); grep checks V1/V3/V10; iterate til green     | V1,V3,V10,V11
T16 | .  | add tests/ to .gitattributes export-ignore                              | C3,I.harness
T17 | .  | USER: robocopy sync to R: + manual InstaWP sweep per I.test (Claude never auto-syncs; V7 verified by user)  | I.test,V7
T18 | .  | confirm revertable single-merge shape; update ROADMAP 2a (Support\ divergence, harness) + CLAUDE.md | V8
```

## §B — Bugs

```
id | date | cause | fix
```
