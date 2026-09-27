<?php
/**
 * H16 — Rule-type registry harness (FW-39).
 *
 * `RuleTypes\Registry` is the single place rule types are enumerated; storage's
 * kind lists, the configs' type labels and TaxonomyManager's handler map are
 * all derived from it. This harness is the independent check on it:
 *
 * 1. **Order.** Registry order is hand-written below, not derived — it is the
 *    type-select order and the fixture authoring order, so a reshuffle is a
 *    deliberate change that must edit this file too.
 * 2. **Every stored type has a descriptor**, and every descriptor's `kind()`
 *    matches the list storage files it under.
 * 3. **Each descriptor is complete**: `type()` equals its registry key, a
 *    non-empty label, a handler class that exists and reads the SAME storage
 *    key (`get_rule_type()`), `has_subfields()` a bool.
 *
 * Run:  php tests/verify-rule-type-registry.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

foreach (['add_action', 'add_filter'] as $fn) {
    if (!function_exists($fn)) {
        eval("function {$fn}() {}");
    }
}
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Handlers\UnifiedHandlerBase;
use BWS\MetaConductor\RuleTypes\Registry;
use BWS\MetaConductor\RuleTypes\RuleType;
use BWS\MetaConductor\Storage\OptionRuleStorage;

$fail  = [];
$total = 0;
$check = function (string $name, bool $cond) use (&$fail, &$total) {
    $total++;
    if (!$cond) { $fail[] = $name; }
};

// Hand-written on purpose — see (1) above.
$expected = [
    OptionRuleStorage::KIND_TERM => [
        'propagation_rules',
        'related_post_terms_rules',
        'time_based_rules',
        'related_rules',
        'hierarchical_rules',
        'hierarchical_level_restriction_rules',
    ],
    OptionRuleStorage::KIND_FORMAT => [
        'title_slug_rules',
    ],
];
$expected_flat = array_merge(...array_values($expected));

$all = Registry::all();

// --- 1. Order. --------------------------------------------------------------

$check('registry order is the documented type order', array_keys($all) === $expected_flat);
$check('storage enumerates the registry, in order', OptionRuleStorage::all_types() === $expected_flat);
foreach ($expected as $kind => $types) {
    $check("Registry::of_kind($kind) is that kind's types, in order",
        array_keys(Registry::of_kind($kind)) === $types);
}
$check('an unknown kind has no types', Registry::of_kind('nope') === []);

// --- 2. Every stored type has a descriptor of the right kind. ---------------

$storage = new OptionRuleStorage();
foreach (OptionRuleStorage::all_types() as $type) {
    $d = Registry::get($type);
    $check("$type has a descriptor", $d instanceof RuleType);
    if ($d) {
        $check("$type: kind() matches the kind storage files it under",
            $d->kind() === $storage->get_kind_for_type($type)
            && in_array($type, $expected[$d->kind()] ?? [], true));
    }
}
$check('an unknown type has no descriptor', Registry::get('nope_rules') === null);

// --- 3. Each descriptor is complete. ----------------------------------------

foreach ($all as $key => $d) {
    $check("$key: type() equals its registry key", $d->type() === $key);
    $check("$key: label is a non-empty string", is_string($d->label()) && trim($d->label()) !== '');
    $check("$key: has_subfields() is a bool", is_bool($d->has_subfields()));

    $class = $d->handler_class();
    $check("$key: handler class $class exists", class_exists($class));
    if (class_exists($class)) {
        $check("$key: handler extends UnifiedHandlerBase", is_subclass_of($class, UnifiedHandlerBase::class));
        // The handler reads its rules by this key; a descriptor naming the
        // wrong handler would route a pass to a handler that reads other rows.
        $reads    = new ReflectionMethod($class, 'get_rule_type');
        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $check("$key: handler reads the same storage key", $reads->invoke($instance) === $key);
    }
}

// The runtime question the dispatchers ask before running a row (ticket 10).
$check('a registered type is known', Registry::known('hierarchical_rules'));
$check('a type with no descriptor is not known — the pass skips it', !Registry::known('nope_rules'));
$check('an untyped row is not known', !Registry::known(''));

$check('handler classes are one per type',
    count(array_unique(array_map(fn(RuleType $d) => $d->handler_class(), $all))) === count($all));

// --- Report. ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\nRULE-TYPE REGISTRY FAIL — " . count($fail) . "/$total:\n");
    foreach ($fail as $f) {
        fwrite(STDERR, "  ✗ $f\n");
    }
    exit(1);
}

fwrite(STDOUT, "RULE-TYPE REGISTRY OK — $total checks.\n");
exit(0);
