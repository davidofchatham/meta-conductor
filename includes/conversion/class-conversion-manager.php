<?php
/**
 * Conversion Manager Class
 *
 * Coordinates all data conversion operations, managing components and providing
 * a unified API for the BWS Meta Manager integration.
 *
 * @package BWS_Meta_Manager
 * @subpackage Conversion
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Conversion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main conversion coordinator class
 *
 * Responsibilities:
 * - Initialize and coordinate conversion components
 * - Provide public API for conversion operations
 * - Manage session cleanup and maintenance
 * - Handle component lifecycle
 */
class ConversionManager {

	/**
	 * Field mapper instance
	 *
	 * @var FieldMapper
	 */
	private $field_mapper;

	/**
	 * Data processor instance
	 *
	 * @var DataProcessor
	 */
	private $data_processor;

	/**
	 * Preview system instance
	 *
	 * @var PreviewSystem
	 */
	private $preview_system;

	/**
	 * Session table name
	 *
	 * @var string
	 */
	private $session_table;

	/**
	 * Preview table name
	 *
	 * @var string
	 */
	private $preview_table;

	/**
	 * Constructor
	 *
	 * Initializes all conversion components and sets up cleanup hooks.
	 */
	public function __construct() {
		$this->init_tables();
		$this->init_components();
		$this->init_cleanup();
	}

	/**
	 * Initialize database table names
	 */
	private function init_tables(): void {
		global $wpdb;
		$this->session_table = $wpdb->prefix . 'bws_acf_conversion_sessions';
		$this->preview_table = $wpdb->prefix . 'bws_acf_conversion_preview';
	}

	/**
	 * Initialize conversion components
	 *
	 * Sets up the component chain:
	 * Field Mapper → Data Processor → Preview System
	 */
	private function init_components(): void {
		// Initialize Field Mapper
		$this->field_mapper = new FieldMapper();

		// Initialize Data Processor with Field Mapper
		$this->data_processor = new DataProcessor( $this->field_mapper );

		// Initialize Preview System with Data Processor
		$this->preview_system = new PreviewSystem( $this->data_processor );
	}

	/**
	 * Initialize cleanup hooks
	 *
	 * Registers hourly cron job for cleaning up expired sessions and previews.
	 */
	private function init_cleanup(): void {
		// Register cleanup cron job if not already scheduled
		if ( ! wp_next_scheduled( 'bws_meta_manager_conversion_cleanup' ) ) {
			wp_schedule_event( time(), 'hourly', 'bws_meta_manager_conversion_cleanup' );
		}

		// Hook into the scheduled event
		add_action( 'bws_meta_manager_conversion_cleanup', [ $this, 'cleanup_expired_sessions' ] );
	}

	/**
	 * Get field mapper instance
	 *
	 * @return FieldMapper
	 */
	public function get_field_mapper(): FieldMapper {
		return $this->field_mapper;
	}

	/**
	 * Get data processor instance
	 *
	 * @return DataProcessor
	 */
	public function get_data_processor(): DataProcessor {
		return $this->data_processor;
	}

	/**
	 * Get preview system instance
	 *
	 * @return PreviewSystem
	 */
	public function get_preview_system(): PreviewSystem {
		return $this->preview_system;
	}

	/**
	 * Generate preview for copy data conversion
	 *
	 * @param array $config Conversion configuration
	 * @param int   $sample_count Number of samples to generate
	 * @return array Preview results
	 */
	public function generate_copy_preview( array $config, int $sample_count = 10 ): array {
		return $this->preview_system->generate_copy_data_preview( $config, $sample_count );
	}

	/**
	 * Generate preview for map data conversion
	 *
	 * @param array $config Conversion configuration
	 * @param int   $sample_count Number of samples to generate
	 * @return array Preview results
	 */
	public function generate_map_preview( array $config, int $sample_count = 10 ): array {
		return $this->preview_system->generate_map_data_preview( $config, $sample_count );
	}

	/**
	 * Execute copy data conversion
	 *
	 * @param array $config Conversion configuration
	 * @param bool  $dry_run Whether to perform a dry run
	 * @return array Conversion results
	 */
	public function execute_copy_conversion( array $config, bool $dry_run = false ): array {
		return $this->data_processor->process_copy_data_conversion( $config, $dry_run );
	}

	/**
	 * Execute map data conversion
	 *
	 * @param array $config Conversion configuration
	 * @param bool  $dry_run Whether to perform a dry run
	 * @return array Conversion results
	 */
	public function execute_map_conversion( array $config, bool $dry_run = false ): array {
		return $this->data_processor->process_map_data_conversion( $config, $dry_run );
	}

	/**
	 * Get field groups
	 *
	 * @param bool $force_refresh Whether to force cache refresh
	 * @return array Field groups
	 */
	public function get_field_groups( bool $force_refresh = false ): array {
		return $this->field_mapper->get_field_groups( $force_refresh );
	}

	/**
	 * Get fields by type
	 *
	 * @param string $field_type Field type to filter by
	 * @param bool   $force_refresh Whether to force cache refresh
	 * @return array Fields matching the type
	 */
	public function get_fields_by_type( string $field_type, bool $force_refresh = false ): array {
		return $this->field_mapper->get_fields_by_type( $field_type, $force_refresh );
	}

	/**
	 * Get field by key
	 *
	 * @param string $field_key Field key
	 * @return array|null Field data or null if not found
	 */
	public function get_field_by_key( string $field_key ): ?array {
		return $this->field_mapper->get_field_by_key( $field_key );
	}

	/**
	 * Get taxonomies
	 *
	 * @param bool $force_refresh Whether to force cache refresh
	 * @return array Taxonomies
	 */
	public function get_taxonomies( bool $force_refresh = false ): array {
		return $this->field_mapper->get_taxonomies( $force_refresh );
	}

	/**
	 * Clean up expired conversion sessions and previews
	 *
	 * Removes sessions and preview data older than 1 hour.
	 */
	public function cleanup_expired_sessions(): void {
		global $wpdb;

		$cutoff_time = current_time( 'mysql', true );
		$cutoff_timestamp = strtotime( $cutoff_time ) - HOUR_IN_SECONDS;
		$cutoff_mysql = gmdate( 'Y-m-d H:i:s', $cutoff_timestamp );

		// Clean up sessions
		$deleted_sessions = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->session_table} WHERE created_at < %s",
				$cutoff_mysql
			)
		);

		// Clean up preview data
		$deleted_previews = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->preview_table} WHERE created_at < %s",
				$cutoff_mysql
			)
		);

		// Log cleanup if any records were deleted
		if ( $deleted_sessions || $deleted_previews ) {
			error_log(
				sprintf(
					'BWS Meta Manager Conversion Cleanup: Deleted %d sessions and %d preview records',
					$deleted_sessions,
					$deleted_previews
				)
			);
		}
	}

	/**
	 * Clear all conversion caches
	 *
	 * Forces refresh of all cached field and taxonomy data.
	 */
	public function clear_caches(): void {
		$this->field_mapper->clear_cache();
	}

	/**
	 * Validate conversion configuration
	 *
	 * @param array  $config Conversion configuration
	 * @param string $type Conversion type ('copy_data' or 'map_data')
	 * @return array Validation result with 'valid' and 'errors' keys
	 */
	public function validate_config( array $config, string $type ): array {
		$errors = [];

		// Common validation
		if ( empty( $config['content_type'] ) ) {
			$errors[] = __( 'Content type is required.', 'meta-conductor' );
		}

		if ( empty( $config['source_field'] ) && $type !== 'copy_data' ) {
			$errors[] = __( 'Source field is required.', 'meta-conductor' );
		}

		// Type-specific validation
		if ( $type === 'copy_data' ) {
			if ( empty( $config['copy_type'] ) ) {
				$errors[] = __( 'Copy type is required.', 'meta-conductor' );
			}
		} elseif ( $type === 'map_data' ) {
			if ( empty( $config['target_type'] ) ) {
				$errors[] = __( 'Target type is required.', 'meta-conductor' );
			}

			if ( empty( $config['mappings'] ) ) {
				$errors[] = __( 'Value mappings are required.', 'meta-conductor' );
			}
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}

	/**
	 * Get conversion statistics
	 *
	 * @return array Statistics about conversion sessions and data
	 */
	public function get_statistics(): array {
		global $wpdb;

		$active_sessions = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->session_table}
			WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
		);

		$total_previews = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->preview_table}
			WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
		);

		return [
			'active_sessions' => intval( $active_sessions ),
			'recent_previews' => intval( $total_previews ),
		];
	}

	/**
	 * Uninstall cleanup
	 *
	 * Removes scheduled cron events. Should be called on plugin deactivation.
	 */
	public function deactivate(): void {
		$timestamp = wp_next_scheduled( 'bws_meta_manager_conversion_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'bws_meta_manager_conversion_cleanup' );
		}
	}
}
