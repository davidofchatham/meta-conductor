<?php
/**
 * Class autoloader: Prefix\Sub\ClassName -> includes/sub/class-class-name.php
 *
 * Copy-in template: bws-reference templates/autoload.php @ 908efba.
 * When copying, set $prefix and $base below, @package and <commit> here.
 * Rules and reasons: the bws-reference practice "PHP class autoloading".
 *
 * - Acronyms and digits are spelled as words: CptRuleStorage, V2source.
 *   CPTRuleStorage maps to cptrule-storage.php and never loads.
 * - Every kind uses class-: interfaces, traits, abstracts and enums too.
 *
 * @package Meta_Conductor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BWS\\MetaConductor\\';
		$base   = __DIR__ . '/includes/';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		// PascalCase -> kebab-case, for directories and the file alike.
		$kebab = static fn( string $segment ): string => strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $segment ) );
		$parts = array_map( $kebab, explode( '\\', substr( $class_name, strlen( $prefix ) ) ) );
		$name  = array_pop( $parts );
		$file  = $base . ( $parts ? implode( '/', $parts ) . '/' : '' ) . 'class-' . $name . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
