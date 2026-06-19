<?php
/**
 * H2 — Autoload resolution harness (Phase 2a).
 *
 * Proves the PSR-4 autoloader wiring resolves WITHOUT booting WordPress:
 * stub ABSPATH + minimal WP funcs, require the composer autoloader (so classes
 * extending vendor types resolve) then the plugin autoload.php, then assert
 * class_exists()/interface_exists() for every expected FQN. Exits non-zero on
 * any miss. Works because plugin classes only DECLARE (not execute) at load.
 *
 * Catches: namespace<->dir mismatch, kebab-converter break on consecutive caps,
 * interface file still named interface-*.php, missed file moves (SPEC §V11).
 * Does NOT catch runtime behavior — that is the manual InstaWP sweep (§I.test).
 *
 * Run:  php tests/verify-autoload.php   (local PHP CLI, no WP needed)
 *
 * Reusable pattern across Phase 2b/3 and other BWS plugins — adapt the FQN list.
 *
 * @package Meta_Conductor
 */

// --- Minimal WP shim so class files that reference WP at load don't fatal. ---
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

// A few includes/ files run top-level bootstrap code at load (e.g. an
// add_action() after the class body). Stub the WP functions that such
// declare-time code calls, as global no-ops, so requiring the file to test
// class resolution doesn't fatal on an undefined WP function. These are only
// the funcs reachable at file scope — method bodies are never executed here.
foreach ([
    'add_action', 'add_filter', 'is_admin', 'did_action', 'doing_action',
    'register_activation_hook', 'register_deactivation_hook', 'add_shortcode',
] as $fn) {
    if (!function_exists($fn)) {
        eval("function {$fn}() {}");
    }
}

$root = dirname(__DIR__);

// Composer autoloader first (Wireframe, rakit) — namespaced classes that extend
// or implement vendor types need these resolvable at declare time.
$vendor = $root . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    fwrite(STDERR, "WARN: vendor/autoload.php missing — run composer install. Continuing.\n");
}

// The plugin PSR-4 autoloader under test.
require $root . '/autoload.php';

// --- Expected FQNs (SPEC §I.ns / §I.move / §I.support). ---
$classes = [
    // root
    'BWS\\MetaConductor\\TaxonomyManager',
    'BWS\\MetaConductor\\Settings',
    // Core\
    'BWS\\MetaConductor\\Core\\Entity',
    'BWS\\MetaConductor\\Core\\ConditionEvaluator',
    'BWS\\MetaConductor\\Core\\ActionExecutor',
    'BWS\\MetaConductor\\Core\\RuleEngine',
    // Storage\
    'BWS\\MetaConductor\\Storage\\OptionRuleStorage',
    'BWS\\MetaConductor\\Storage\\StorageFactory',
    // Handlers\
    'BWS\\MetaConductor\\Handlers\\HandlerBase',
    'BWS\\MetaConductor\\Handlers\\UnifiedHandlerBase',
    'BWS\\MetaConductor\\Handlers\\HierarchicalHandler',
    'BWS\\MetaConductor\\Handlers\\PropagationHandler',
    'BWS\\MetaConductor\\Handlers\\RelatedHandler',
    'BWS\\MetaConductor\\Handlers\\TimeBasedHandler',
    'BWS\\MetaConductor\\Handlers\\RelatedPostTermsHandler',
    'BWS\\MetaConductor\\Handlers\\HierarchicalLevelRestrictionHandler',
    'BWS\\MetaConductor\\Handlers\\TitleSlugHandler',
    // Conversion\
    'BWS\\MetaConductor\\Conversion\\ConversionManager',
    'BWS\\MetaConductor\\Conversion\\ConversionCli',
    'BWS\\MetaConductor\\Conversion\\ConversionUi',
    'BWS\\MetaConductor\\Conversion\\FieldMapper',
    'BWS\\MetaConductor\\Conversion\\DataProcessor',
    'BWS\\MetaConductor\\Conversion\\PreviewSystem',
    // Integrations\
    'BWS\\MetaConductor\\Integrations\\AcfIntegration',
    'BWS\\MetaConductor\\Integrations\\AdminColumnsIntegration',
    // Admin\
    'BWS\\MetaConductor\\Admin\\Diagnostics',
    'BWS\\MetaConductor\\Admin\\WireframeBootstrap',
    // Admin\Config\
    'BWS\\MetaConductor\\Admin\\Config\\ConfigHelpers',
    'BWS\\MetaConductor\\Admin\\Config\\GeneralConfig',
    'BWS\\MetaConductor\\Admin\\Config\\HierarchicalConfig',
    'BWS\\MetaConductor\\Admin\\Config\\PropagationConfig',
    'BWS\\MetaConductor\\Admin\\Config\\RelatedConfig',
    'BWS\\MetaConductor\\Admin\\Config\\RelatedPostTermsConfig',
    'BWS\\MetaConductor\\Admin\\Config\\TimeBasedConfig',
    'BWS\\MetaConductor\\Admin\\Config\\LevelRestrictionConfig',
    'BWS\\MetaConductor\\Admin\\Config\\TitleSlugConfig',
    'BWS\\MetaConductor\\Admin\\Config\\PersonalizeConfig',
    'BWS\\MetaConductor\\Admin\\Config\\WireframeConfig',
    // Support\ (concrete)
    'BWS\\MetaConductor\\Support\\BatchProcessor',
    'BWS\\MetaConductor\\Support\\ValueMapper',
    'BWS\\MetaConductor\\Support\\FieldConverter',
    'BWS\\MetaConductor\\Support\\TermMigrator',
];

$interfaces = [
    'BWS\\MetaConductor\\Storage\\RuleStorage',
    'BWS\\MetaConductor\\Support\\BatchProcessorInterface',
    'BWS\\MetaConductor\\Support\\ValueMapperInterface',
    'BWS\\MetaConductor\\Support\\FieldConverterInterface',
    'BWS\\MetaConductor\\Support\\TermMigratorInterface',
];

$missing = [];
foreach ($classes as $fqn) {
    if (!class_exists($fqn)) {
        $missing[] = "class     $fqn";
    }
}
foreach ($interfaces as $fqn) {
    if (!interface_exists($fqn)) {
        $missing[] = "interface $fqn";
    }
}

$total = count($classes) + count($interfaces);
if ($missing) {
    fwrite(STDERR, "\nAUTOLOAD FAIL — " . count($missing) . "/$total did not resolve:\n");
    foreach ($missing as $m) {
        fwrite(STDERR, "  ✗ $m\n");
    }
    fwrite(STDERR, "\nCheck: namespace matches dir, class-{kebab}.php filename, no consecutive caps.\n");
    exit(1);
}

fwrite(STDOUT, "AUTOLOAD OK — all $total FQNs resolved (" . count($classes) . " classes, " . count($interfaces) . " interfaces).\n");
exit(0);
