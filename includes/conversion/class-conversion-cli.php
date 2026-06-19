<?php
/**
 * WP-CLI Commands for Conversion Testing
 *
 * Provides command-line interface for testing conversion functionality.
 *
 * @package BWS_Meta_Manager
 * @subpackage Conversion
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Conversion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BWS\MetaConductor\Support\TermMigrator;
use BWS\MetaConductor\Support\FieldConverter;
use BWS\MetaConductor\Support\ValueMapper;
use BWS\MetaConductor\Support\BatchProcessor;

/**
 * WP-CLI commands for testing BWS Meta Manager conversion functionality.
 */
class ConversionCli {

	/**
	 * Conversion manager instance
	 *
	 * @var ConversionManager
	 */
	private $manager;

	/**
	 * Constructor
	 *
	 * @param ConversionManager $manager Conversion manager instance
	 */
	public function __construct( ConversionManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Test term migrator library functionality
	 *
	 * ## OPTIONS
	 *
	 * [--source=<taxonomy>]
	 * : Source taxonomy slug
	 *
	 * [--target=<taxonomy>]
	 * : Target taxonomy slug
	 *
	 * [--dry-run]
	 * : Perform a dry run without making changes
	 *
	 * ## EXAMPLES
	 *
	 *     wp bws-conversion test-term-migrator --source=category --target=custom_cat --dry-run
	 *
	 * @param array $args Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function test_term_migrator( $args, $assoc_args ) {
		$source = $assoc_args['source'] ?? 'category';
		$target = $assoc_args['target'] ?? 'post_tag';
		$dry_run = isset( $assoc_args['dry-run'] );

		\WP_CLI::line( 'Testing Term Migrator Library...' );
		\WP_CLI::line( "Source: $source" );
		\WP_CLI::line( "Target: $target" );
		\WP_CLI::line( 'Dry run: ' . ( $dry_run ? 'yes' : 'no' ) );
		\WP_CLI::line( '' );

		// Initialize term migrator
		$migrator = new TermMigrator();

		// Get source terms
		$source_terms = get_terms( [
			'taxonomy'   => $source,
			'hide_empty' => false,
			'number'     => 5, // Test with 5 terms
		] );

		if ( is_wp_error( $source_terms ) || empty( $source_terms ) ) {
			\WP_CLI::error( 'No terms found in source taxonomy' );
			return;
		}

		\WP_CLI::success( sprintf( 'Found %d terms in source taxonomy', count( $source_terms ) ) );

		// Test migration
		$options = [
			'preserve_hierarchy' => true,
			'copy_term_meta'     => true,
			'handle_conflicts'   => 'skip',
		];

		if ( ! $dry_run ) {
			$result = $migrator->migrate_terms( $source, $target, $options );

			if ( $result['success'] ) {
				\WP_CLI::success( sprintf( 'Migrated %d terms successfully', $result['migrated'] ) );
				\WP_CLI::line( 'Term mapping:' );
				foreach ( $result['term_map'] as $source_id => $target_id ) {
					\WP_CLI::line( "  $source_id → $target_id" );
				}
			} else {
				\WP_CLI::error( 'Migration failed: ' . implode( ', ', $result['errors'] ?? [] ) );
			}
		} else {
			\WP_CLI::line( 'Dry run - no changes made' );
			\WP_CLI::line( 'Would migrate:' );
			foreach ( $source_terms as $term ) {
				\WP_CLI::line( "  - {$term->name} (ID: {$term->term_id})" );
			}
		}
	}

	/**
	 * Test field converter library functionality
	 *
	 * ## OPTIONS
	 *
	 * [--from=<type>]
	 * : Source field type (default: text)
	 *
	 * [--to=<type>]
	 * : Target field type (default: textarea)
	 *
	 * [--value=<value>]
	 * : Test value (default: "test,value,data")
	 *
	 * ## EXAMPLES
	 *
	 *     wp bws-conversion test-field-converter --from=text --to=textarea --value="a,b,c"
	 *
	 * @param array $args Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function test_field_converter( $args, $assoc_args ) {
		$from = $assoc_args['from'] ?? 'text';
		$to = $assoc_args['to'] ?? 'textarea';
		$value = $assoc_args['value'] ?? 'test,value,data';

		\WP_CLI::line( 'Testing Field Converter Library...' );
		\WP_CLI::line( "From type: $from" );
		\WP_CLI::line( "To type: $to" );
		\WP_CLI::line( "Test value: $value" );
		\WP_CLI::line( '' );

		$converter = new FieldConverter();

		// Validate conversion
		$validation = $converter->validate_conversion( $from, $to );
		if ( ! $validation['valid'] ) {
			\WP_CLI::error( 'Invalid conversion: ' . $validation['message'] );
			return;
		}

		if ( ! empty( $validation['warning'] ) ) {
			\WP_CLI::warning( $validation['warning'] );
		}

		// Convert value
		$converted = $converter->convert_field_value( $value, $from, $to );

		\WP_CLI::success( 'Conversion successful' );
		\WP_CLI::line( 'Original value:' );
		\WP_CLI::line( '  ' . print_r( $value, true ) );
		\WP_CLI::line( 'Converted value:' );
		\WP_CLI::line( '  ' . print_r( $converted, true ) );

		// Test term extraction
		if ( in_array( $from, [ 'text', 'textarea', 'select', 'checkbox' ], true ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( 'Testing term extraction...' );
			$terms = $converter->extract_terms_from_field( $value, [ 'field_type' => $from ] );
			\WP_CLI::line( 'Extracted terms:' );
			foreach ( $terms as $term ) {
				\WP_CLI::line( "  - $term" );
			}
		}
	}

	/**
	 * Test value mapper library functionality
	 *
	 * ## EXAMPLES
	 *
	 *     wp bws-conversion test-value-mapper
	 *
	 * @param array $args Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function test_value_mapper( $args, $assoc_args ) {
		\WP_CLI::line( 'Testing Value Mapper Library...' );
		\WP_CLI::line( '' );

		$mapper = new ValueMapper();

		// Set up test mappings
		$mapper->set_mapping( 'red', 'Red Category' );
		$mapper->set_mapping( 'green', 'Green Category' );
		$mapper->set_mapping( 'blue', 'Blue Category' );

		\WP_CLI::line( 'Created test mappings:' );
		$mappings = $mapper->get_all_mappings();
		foreach ( $mappings as $source => $target ) {
			\WP_CLI::line( "  $source → $target" );
		}

		// Test mapping
		$test_values = [ 'red', 'green', 'blue', 'yellow', 'orange' ];
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Testing value mapping...' );
		\WP_CLI::line( 'Input values: ' . implode( ', ', $test_values ) );

		$result = $mapper->map_values( $test_values );

		\WP_CLI::line( '' );
		\WP_CLI::line( 'Mapped values:' );
		foreach ( $result['mapped'] as $source => $target ) {
			\WP_CLI::line( "  $source → $target" );
		}

		if ( ! empty( $result['unmapped'] ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( 'Unmapped values:' );
			foreach ( $result['unmapped'] as $value ) {
				\WP_CLI::line( "  - $value" );
			}
		}

		// Test statistics
		\WP_CLI::line( '' );
		$stats = $mapper->get_statistics();
		\WP_CLI::line( 'Statistics:' );
		\WP_CLI::line( "  Total mappings: {$stats['total_mappings']}" );
		\WP_CLI::line( "  Used mappings: {$stats['used_mappings']}" );
		\WP_CLI::line( "  Unused mappings: {$stats['unused_mappings']}" );

		\WP_CLI::success( 'Value mapper test complete' );
	}

	/**
	 * Test batch processor library functionality
	 *
	 * ## OPTIONS
	 *
	 * [--items=<count>]
	 * : Number of items to process (default: 100)
	 *
	 * [--batch-size=<size>]
	 * : Batch size (default: 25)
	 *
	 * ## EXAMPLES
	 *
	 *     wp bws-conversion test-batch-processor --items=100 --batch-size=25
	 *
	 * @param array $args Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function test_batch_processor( $args, $assoc_args ) {
		$item_count = intval( $assoc_args['items'] ?? 100 );
		$batch_size = intval( $assoc_args['batch-size'] ?? 25 );

		\WP_CLI::line( 'Testing Batch Processor Library...' );
		\WP_CLI::line( "Items to process: $item_count" );
		\WP_CLI::line( "Batch size: $batch_size" );
		\WP_CLI::line( '' );

		$processor = new BatchProcessor();
		$processor->set_batch_size( $batch_size );
		$processor->set_memory_limit( 80 ); // 80% threshold

		// Create test items
		$items = range( 1, $item_count );

		// Process with callback
		$processed_count = 0;
		$result = $processor->process_batch(
			$items,
			function( $item ) use ( &$processed_count ) {
				$processed_count++;
				// Simulate work
				usleep( 1000 ); // 1ms per item
				return true;
			}
		);

		\WP_CLI::success( 'Batch processing complete' );
		\WP_CLI::line( 'Results:' );
		\WP_CLI::line( "  Items processed: {$result['processed_items']}" );
		\WP_CLI::line( "  Execution time: {$result['execution_time']}s" );
		\WP_CLI::line( "  Rate: {$result['items_per_second']} items/sec" );

		$stats = $processor->get_processing_stats();
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Statistics:' );
		\WP_CLI::line( "  Memory usage: " . size_format( $stats['memory_usage'] ) );
		\WP_CLI::line( "  Memory percent: {$stats['memory_percent']}%" );
		\WP_CLI::line( "  Items processed: {$stats['items_processed']}" );
	}

	/**
	 * Test conversion manager integration
	 *
	 * ## EXAMPLES
	 *
	 *     wp bws-conversion test-manager
	 *
	 * @param array $args Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function test_manager( $args, $assoc_args ) {
		\WP_CLI::line( 'Testing Conversion Manager Integration...' );
		\WP_CLI::line( '' );

		// Test field mapper integration
		\WP_CLI::line( 'Testing field discovery...' );
		$field_groups = $this->manager->get_field_groups();
		\WP_CLI::line( sprintf( 'Found %d field groups', count( $field_groups ) ) );

		$taxonomies = $this->manager->get_taxonomies();
		\WP_CLI::line( sprintf( 'Found %d taxonomies', count( $taxonomies ) ) );

		// Test statistics
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Getting conversion statistics...' );
		$stats = $this->manager->get_statistics();
		\WP_CLI::line( "  Active sessions: {$stats['active_sessions']}" );
		\WP_CLI::line( "  Recent previews: {$stats['recent_previews']}" );

		// Test validation
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Testing configuration validation...' );

		$invalid_config = [ 'content_type' => '' ];
		$validation = $this->manager->validate_config( $invalid_config, 'copy_data' );
		if ( ! $validation['valid'] ) {
			\WP_CLI::line( 'Validation correctly caught invalid config:' );
			foreach ( $validation['errors'] as $error ) {
				\WP_CLI::line( "  - $error" );
			}
		}

		\WP_CLI::success( 'Conversion manager integration test complete' );
	}

	/**
	 * Run cleanup of expired sessions
	 *
	 * ## EXAMPLES
	 *
	 *     wp bws-conversion cleanup
	 *
	 * @param array $args Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function cleanup( $args, $assoc_args ) {
		\WP_CLI::line( 'Running cleanup...' );
		$this->manager->cleanup_expired_sessions();
		\WP_CLI::success( 'Cleanup complete' );
	}

	/**
	 * Clear all conversion caches
	 *
	 * ## EXAMPLES
	 *
	 *     wp bws-conversion clear-cache
	 *
	 * @param array $args Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function clear_cache( $args, $assoc_args ) {
		\WP_CLI::line( 'Clearing conversion caches...' );
		$this->manager->clear_caches();
		\WP_CLI::success( 'Caches cleared' );
	}
}
