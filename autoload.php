<?php
/**
 * PSR-4 Autoloader for Meta Conductor
 *
 * Maps the BWS\MetaConductor namespace to includes/ using WordPress file
 * naming conventions (class-{kebab}.php). Adapted from BWS Portal System.
 *
 * Kebab conversion only inserts a hyphen at a lower->upper boundary, so class
 * names MUST NOT contain consecutive capitals: use CptRuleStorage, not
 * CPTRuleStorage (the latter resolves to cptrule-storage.php and won't load).
 * Interfaces follow the same scheme — file is class-{kebab}.php, never
 * interface-{kebab}.php.
 *
 * @package Meta_Conductor
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
	exit;
}

spl_autoload_register(function ($class) {
	// Only load BWS\MetaConductor classes.
	$prefix     = 'BWS\\MetaConductor\\';
	$prefix_len = strlen($prefix);

	if (strncmp($prefix, $class, $prefix_len) !== 0) {
		return;
	}

	// Relative class name, e.g. "Core\RuleEngine" or "TaxonomyManager".
	$relative_class = substr($class, $prefix_len);

	// Namespace separators -> directory separators.
	$path_parts = explode('\\', $relative_class);

	// Last part is the class/interface name -> WordPress file naming.
	// Examples: RuleEngine -> class-rule-engine.php, AcfIntegration -> class-acf-integration.php
	$class_name = array_pop($path_parts);
	$filename   = 'class-' . strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name)) . '.php';

	// Build full path with lowercase directories.
	$directory = BWS_META_CONDUCTOR_PATH . 'includes/';
	if (!empty($path_parts)) {
		$directory .= strtolower(implode('/', $path_parts)) . '/';
	}

	$file = $directory . $filename;

	if (file_exists($file)) {
		require $file;
	}
});
