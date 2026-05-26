<?php
/**
 * Data Processor Class
 *
 * Coordinates data conversion operations, delegating core logic to libraries.
 * Acts as integration layer between WordPress/ACF and conversion libraries.
 *
 * ## Integration Strategy (Phase 1 - Shallow Integration)
 *
 * This class currently maintains most of its original conversion logic (1,744 lines)
 * while initializing library instances for future delegation. This "gradual refactor"
 * approach allows testing the integration without rewriting proven, complex code.
 *
 * ## Library Delegation Points (v2.1+ - Deep Refactor)
 *
 * Future refactoring will delegate to libraries:
 * - **BWS_Term_Migrator**: Taxonomy-to-taxonomy migrations (see process_taxonomy_to_taxonomy_batch)
 * - **BWS_Field_Converter**: Field type conversions (see process_field_to_field_batch)
 * - **BWS_Value_Mapper**: Option value mappings (see process_map_data_conversion)
 * - **BWS_Batch_Processor**: Memory-aware batching (see calculate_batch_size, should_stop_processing)
 *
 * ## Current Architecture
 *
 * - Handles WordPress/ACF API calls directly (get_field, update_field, wp_set_object_terms)
 * - Manages batch processing and resource monitoring
 * - Performs validation and error handling
 * - Stores conversion results and debug info
 *
 * @package BWS_Meta_Manager
 * @subpackage Conversion
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BWS_Data_Processor {

    /**
     * Field mapper instance
     *
     * @var BWS_Field_Mapper
     */
    private $field_mapper;

    /**
     * Term migrator library
     *
     * @var BWS_Term_Migrator
     */
    private $term_migrator;

    /**
     * Field converter library
     *
     * @var BWS_Field_Converter
     */
    private $field_converter;

    /**
     * Value mapper library
     *
     * @var BWS_Value_Mapper
     */
    private $value_mapper;

    /**
     * Batch processor library
     *
     * @var BWS_Batch_Processor
     */
    private $batch_processor;

    /**
     * Default batch size for processing
     * Note: Batch size is now managed by BWS_Batch_Processor library
     */
    private const DEFAULT_BATCH_SIZE = 25;

    /**
     * Maximum execution time per batch (seconds)
     * Note: Execution time monitoring is now in BWS_Batch_Processor library
     */
    private const MAX_EXECUTION_TIME = 15;

    /**
     * Memory usage threshold (percentage)
     * Note: Memory monitoring is now in BWS_Batch_Processor library
     */
    private const MEMORY_THRESHOLD = 80;

    /**
     * Conversion types
     */
    private const CONVERSION_TYPES = [
        'copy_data',
        'map_data'
    ];

    /**
     * Copy types (formerly move types)
     */
    private const COPY_TYPES = [
        'field_to_field',
        'field_to_taxonomy',
        'taxonomy_to_field',
        'taxonomy_to_taxonomy'
    ];

    /**
     * Store debug info for last conversion
     */
    private $last_conversion_debug_info = [];

    /**
     * Constructor
     *
     * @param BWS_Field_Mapper $field_mapper Field mapper instance
     */
    public function __construct( BWS_Field_Mapper $field_mapper ) {
        $this->field_mapper = $field_mapper;

        // Initialize library instances
        $this->term_migrator = new BWS_Term_Migrator();
        $this->field_converter = new BWS_Field_Converter();
        $this->value_mapper = new BWS_Value_Mapper();
        $this->batch_processor = new BWS_Batch_Processor();
    }

    /**
     * Process copy data conversion (formerly move data)
     *
     * @param array $config Conversion configuration
     * @param bool $dry_run Whether this is a dry run
     * @return array
     */
    public function process_copy_data_conversion( array $config, bool $dry_run = false ): array {
        $result = $this->initialize_processing_result();
        
        // Validate configuration
        $validation = $this->validate_copy_data_config( $config );
        if ( ! $validation['valid'] ) {
            $result['errors'] = $validation['errors'];
            return $result;
        }

        // Get items to process based on content type and copy type
        $items = $this->get_items_for_copy_conversion( $config );
        $result['total_items'] = count( $items );

        if ( empty( $items ) ) {
            $result['message'] = __( 'No items found to copy.', 'bws-meta-manager' );
            return $result;
        }

        // Process in batches
        $batches = array_chunk( $items, $this->calculate_batch_size( $config ) );
        $result['total_batches'] = count( $batches );

        foreach ( $batches as $batch_index => $batch_items ) {
            $batch_result = $this->process_copy_data_batch(
                $batch_items,
                $config,
                $dry_run,
                $batch_index + 1
            );

            $result['processed_items'] += $batch_result['processed'];
            $result['successful_conversions'] += $batch_result['successful'];
            $result['failed_conversions'] += $batch_result['failed'];
            $result['batch_results'][] = $batch_result;

            // Check memory and time limits
            if ( $this->should_stop_processing() ) {
                $result['stopped_early'] = true;
                $result['message'] = __( 'Processing stopped due to resource limits.', 'bws-meta-manager' );
                break;
            }
        }

        $result['success'] = $result['failed_conversions'] === 0;
        return $result;
    }

    /**
     * Process map data conversion - UPDATED VERSION
     */
    public function process_map_data_conversion( array $config, bool $dry_run = false ): array {
        $result = $this->initialize_processing_result();
        
        error_log('=== MAP DATA CONVERSION DEBUG (UPDATED) ===');
        error_log('Config: ' . print_r($config, true));
        error_log('Dry run: ' . ($dry_run ? 'yes' : 'no'));
        
        // Validate configuration
        $validation = $this->validate_map_data_config( $config );
        if ( ! $validation['valid'] ) {
            error_log('Validation failed: ' . print_r($validation['errors'], true));
            $result['errors'] = $validation['errors'];
            return $result;
        }

        // Enhanced field validation
        $field_validation = $this->validate_source_field_enhanced( $config );
        if ( ! $field_validation['valid'] ) {
            error_log('Field validation failed: ' . print_r($field_validation['errors'], true));
            $result['errors'] = array_merge( $result['errors'], $field_validation['errors'] );
            return $result;
        }

        // Get items to process using simplified approach
        $items = $this->get_items_for_map_conversion_simplified( $config );
        $result['total_items'] = count( $items );
        $result['debug_info'] = $this->last_conversion_debug_info ?? [];

        error_log('Items found for conversion: ' . count($items));

        if ( empty( $items ) ) {
            $error_message = sprintf(
                __( 'No items found with data in the source field. Checked field "%s" (%s) in %d %s.', 'bws-meta-manager' ),
                $this->last_conversion_debug_info['field_name'] ?? 'unknown',
                $this->last_conversion_debug_info['actual_field_name'] ?? 'unknown',
                $this->last_conversion_debug_info['total_items_checked'] ?? 0,
                $config['content_type'] === 'posts' ? 'posts' : 'terms'
            );
            
            $result['message'] = $error_message;
            $result['errors'][] = $error_message;
            
            error_log('No items found: ' . $error_message);
            return $result;
        }

        // Process in batches
        $batches = array_chunk( $items, $this->calculate_batch_size( $config ) );
        $result['total_batches'] = count( $batches );

        error_log('Processing ' . count($batches) . ' batches');

        foreach ( $batches as $batch_index => $batch_items ) {
            $batch_result = $this->process_map_data_batch(
                $batch_items,
                $config,
                $dry_run,
                $batch_index + 1
            );

            $result['processed_items'] += $batch_result['processed'];
            $result['successful_conversions'] += $batch_result['successful'];
            $result['failed_conversions'] += $batch_result['failed'];
            $result['batch_results'][] = $batch_result;

            error_log("Batch " . ($batch_index + 1) . " completed: {$batch_result['successful']} successful, {$batch_result['failed']} failed");

            // Check memory and time limits
            if ( $this->should_stop_processing() ) {
                $result['stopped_early'] = true;
                $result['message'] = __( 'Processing stopped due to resource limits.', 'bws-meta-manager' );
                break;
            }
        }

        $result['success'] = $result['failed_conversions'] === 0;
        
        error_log('Conversion completed. Success: ' . ($result['success'] ? 'yes' : 'no'));
        error_log('Total processed: ' . $result['processed_items'] . ', Successful: ' . $result['successful_conversions'] . ', Failed: ' . $result['failed_conversions']);
        
        return $result;
    }
    
	
	/**
	 * Process conversion with chunked AJAX approach for large datasets
	 *
	 * @param array $config Conversion configuration
	 * @param bool $dry_run Whether this is a dry run
	 * @param int $chunk_start Starting position for this chunk
	 * @param int $chunk_size Number of batches to process in this chunk
	 * @return array
	 */
public function process_conversion_chunk( array $config, bool $dry_run = false, int $chunk_start = 0, int $chunk_size = 5 ): array {
    $result = $this->initialize_processing_result();
    
    // Get or restore session data
    $session_id = $config['session_id'] ?? $this->generate_session_id();
    $session_data = $this->get_session_data( $session_id );
    
    if ( ! $session_data && $chunk_start === 0 ) {
        // First chunk - initialize session
        $session_data = $this->initialize_conversion_session( $config, $session_id );
        if ( ! $session_data ) {
            $result['errors'] = [ __( 'Failed to initialize conversion session.', 'bws-meta-manager' ) ];
            return $result;
        }
    }
    
    if ( ! $session_data ) {
        $result['errors'] = [ __( 'Invalid session. Please restart the conversion.', 'bws-meta-manager' ) ];
        return $result;
    }
    
    // Process this chunk of batches
    $batches = $session_data['batches'];
    $chunk_end = min( $chunk_start + $chunk_size, count( $batches ) );
    
    $result['session_id'] = $session_id;
    $result['chunk_start'] = $chunk_start;
    $result['chunk_end'] = $chunk_end;
    $result['total_batches'] = count( $batches );
    $result['total_items'] = $session_data['total_items'];
    
    // Process batches in this chunk
    for ( $batch_index = $chunk_start; $batch_index < $chunk_end; $batch_index++ ) {
        $batch_items = $batches[ $batch_index ];
        
        $batch_result = $this->process_conversion_batch_by_type(
            $batch_items,
            $config,
            $dry_run,
            $batch_index + 1
        );
        
        $result['processed_items'] += $batch_result['processed'];
        $result['successful_conversions'] += $batch_result['successful'];
        $result['failed_conversions'] += $batch_result['failed'];
        $result['batch_results'][] = $batch_result;
        
        // Update session with progress
        $this->update_session_progress( $session_id, $batch_index + 1, $batch_result );
        
        // Check if we should stop this chunk early
        if ( $this->should_stop_chunk_processing() ) {
            $result['chunk_stopped_early'] = true;
            break;
        }
    }
    
    // Calculate overall progress
    $session_progress = $this->get_session_progress( $session_id );
    $result['overall_processed'] = $session_progress['processed_items'] ?? 0;
    $result['overall_successful'] = $session_progress['successful_conversions'] ?? 0;
    $result['overall_failed'] = $session_progress['failed_conversions'] ?? 0;
    $result['completed_batches'] = $session_progress['completed_batches'] ?? 0;
    
    // Check if conversion is complete
    $result['is_complete'] = $result['completed_batches'] >= $result['total_batches'];
    $result['progress_percentage'] = round( ( $result['completed_batches'] / $result['total_batches'] ) * 100, 2 );
    
    if ( $result['is_complete'] ) {
        // Clean up session data
        $this->cleanup_session_data( $session_id );
        $result['success'] = $result['overall_failed'] === 0;
    } else {
        $result['success'] = true; // Chunk processed successfully, more chunks needed
        $result['next_chunk_start'] = $chunk_end;
    }
    
    return $result;
}
	
/**
 * Initialize conversion session for chunked processing
 */
private function initialize_conversion_session( array $config, string $session_id ): ?array {
    // Validate configuration
    $conversion_type = $config['conversion_type'] ?? '';
    
    if ( $conversion_type === 'copy_data' ) {
        $validation = $this->validate_copy_data_config( $config );
    } else if ( $conversion_type === 'map_data' ) {
        $validation = $this->validate_map_data_config( $config );
    } else {
        return null;
    }
    
    if ( ! $validation['valid'] ) {
        return null;
    }
    
    // Get all items for conversion
    if ( $conversion_type === 'copy_data' ) {
        $items = $this->get_items_for_copy_conversion( $config );
    } else {
        $items = $this->get_items_for_map_conversion_simplified( $config );
    }
    
    if ( empty( $items ) ) {
        return null;
    }
    
    // Create batches
    $batch_size = $this->calculate_batch_size( $config );
    $batches = array_chunk( $items, $batch_size );
    
    $session_data = [
        'session_id' => $session_id,
        'config' => $config,
        'total_items' => count( $items ),
        'batch_size' => $batch_size,
        'batches' => $batches,
        'created_at' => time(),
        'progress' => [
            'processed_items' => 0,
            'successful_conversions' => 0,
            'failed_conversions' => 0,
            'completed_batches' => 0,
            'batch_results' => []
        ]
    ];
    
    // Store session data
    $this->store_session_data( $session_id, $session_data );
    
    return $session_data;
}
	
/**
 * Process a batch based on conversion type
 */
private function process_conversion_batch_by_type( array $items, array $config, bool $dry_run, int $batch_number ): array {
    $conversion_type = $config['conversion_type'] ?? '';
    
    if ( $conversion_type === 'copy_data' ) {
        return $this->process_copy_data_batch( $items, $config, $dry_run, $batch_number );
    } else if ( $conversion_type === 'map_data' ) {
        return $this->process_map_data_batch( $items, $config, $dry_run, $batch_number );
    }
    
    return [
        'batch_number' => $batch_number,
        'processed' => 0,
        'successful' => 0,
        'failed' => count( $items ),
        'errors' => [ __( 'Invalid conversion type.', 'bws-meta-manager' ) ],
        'start_time' => microtime( true ),
        'end_time' => microtime( true ),
        'execution_time' => 0
    ];
}
	
/**
 * Check if chunk processing should stop (more lenient than full processing)
 */
private function should_stop_chunk_processing(): bool {
    // Check memory usage (higher threshold for chunks)
    $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
    $memory_usage = memory_get_usage();
    $memory_percentage = ( $memory_usage / $memory_limit ) * 100;
    
    if ( $memory_percentage > 85 ) { // Higher threshold for chunks
        return true;
    }
    
    // Check execution time for AJAX requests (longer time for chunks)
    if ( wp_doing_ajax() ) {
        $execution_time = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
        if ( $execution_time > 25 ) { // Longer time limit for chunks
            return true;
        }
    }
    
    return false;
}
	
/**
 * Store session data in database
 */
private function store_session_data( string $session_id, array $session_data ): void {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bws_acf_conversion_sessions';
    
    // Create table if it doesn't exist
    $this->create_sessions_table();
    
    $wpdb->replace(
        $table_name,
        [
            'session_id' => $session_id,
            'session_data' => wp_json_encode( $session_data ),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' )
        ],
        [ '%s', '%s', '%s', '%s' ]
    );
}

/**
 * Get session data from database
 */
private function get_session_data( string $session_id ): ?array {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bws_acf_conversion_sessions';
    
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT session_data FROM {$table_name} WHERE session_id = %s AND created_at > %s",
            $session_id,
            gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) // 1 hour expiry
        )
    );
    
    if ( ! $row ) {
        return null;
    }
    
    $data = json_decode( $row->session_data, true );
    return is_array( $data ) ? $data : null;
}

/**
 * Update session progress
 */
private function update_session_progress( string $session_id, int $completed_batches, array $batch_result ): void {
    $session_data = $this->get_session_data( $session_id );
    if ( ! $session_data ) {
        return;
    }
    
    $session_data['progress']['processed_items'] += $batch_result['processed'];
    $session_data['progress']['successful_conversions'] += $batch_result['successful'];
    $session_data['progress']['failed_conversions'] += $batch_result['failed'];
    $session_data['progress']['completed_batches'] = $completed_batches;
    $session_data['progress']['batch_results'][] = $batch_result;
    $session_data['updated_at'] = time();
    
    $this->store_session_data( $session_id, $session_data );
}

/**
 * Get session progress
 */
private function get_session_progress( string $session_id ): array {
    $session_data = $this->get_session_data( $session_id );
    return $session_data['progress'] ?? [];
}

/**
 * Generate session ID
 */
private function generate_session_id(): string {
    return wp_generate_password( 32, false );
}

/**
 * Create sessions table
 */
private function create_sessions_table(): void {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bws_acf_conversion_sessions';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id varchar(32) NOT NULL,
        session_data longtext NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY session_id (session_id),
        KEY created_at (created_at)
    ) {$charset_collate};";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Clean up session data
 */
private function cleanup_session_data( string $session_id ): void {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bws_acf_conversion_sessions';
    
    $wpdb->delete(
        $table_name,
        [ 'session_id' => $session_id ],
        [ '%s' ]
    );
}

/**
 * Clean up old sessions (call this periodically)
 */
public function cleanup_old_sessions(): void {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'bws_acf_conversion_sessions';
    
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) // 1 hour expiry
        )
    );
}

    /**
     * Get actual field name to use for queries - SIMPLIFIED VERSION
     */
    private function get_actual_field_name( array $source_field_data ): string {
        // Check if this is a sub-field
        if ( ! empty( $source_field_data['parent'] ) ) {
            $parent_field_data = $this->field_mapper->get_field_by_key( $source_field_data['parent'] );
            
            if ( $parent_field_data ) {
                error_log("Sub-field detected - Parent: {$parent_field_data['name']}, Sub-field: {$source_field_data['name']}");
                
                switch ( $parent_field_data['type'] ) {
                    case 'group':
                        // Group fields: parent_name_field_name
                        return $parent_field_data['name'] . '_' . $source_field_data['name'];
                        
                    case 'repeater':
                        // Repeater fields: parent_name_0_field_name (we'll check row 0 first)
                        return $parent_field_data['name'] . '_0_' . $source_field_data['name'];
                        
                    case 'flexible_content':
                        // Flexible content: parent_name_0_field_name (basic approach)
                        return $parent_field_data['name'] . '_0_' . $source_field_data['name'];
                        
                    default:
                        return $parent_field_data['name'] . '_' . $source_field_data['name'];
                }
            }
        }
        
        // Regular field
        return $source_field_data['name'];
    }

    /**
     * Get field value using simplified approach - SIMPLIFIED VERSION
     */
    private function get_field_value_simplified( array $source_field_data, int $item_id, string $content_type = 'posts', string $taxonomy = '' ): mixed {
        $actual_field_name = $this->get_actual_field_name( $source_field_data );
        
        if ( $content_type === 'posts' ) {
            // For repeater fields, check multiple rows
            if ( ! empty( $source_field_data['parent'] ) ) {
                $parent_field_data = $this->field_mapper->get_field_by_key( $source_field_data['parent'] );
                if ( $parent_field_data && $parent_field_data['type'] === 'repeater' ) {
                    return $this->get_repeater_field_value( $source_field_data, $parent_field_data, $item_id );
                }
            }
            
            return get_field( $actual_field_name, $item_id, false );
        } else {
            return get_field( $actual_field_name, "{$taxonomy}_{$item_id}" );
        }
    }

    /**
     * Get repeater field value by checking multiple rows
     */
    private function get_repeater_field_value( array $source_field_data, array $parent_field_data, int $item_id ): mixed {
        $parent_name = $parent_field_data['name'];
        $field_name = $source_field_data['name'];
        
        // Check first 10 rows for performance
        for ( $i = 0; $i < 10; $i++ ) {
            $row_field_name = $parent_name . '_' . $i . '_' . $field_name;
            $value = get_field( $row_field_name, $item_id, false );
            
            if ( $this->field_has_meaningful_value( $value ) ) {
                error_log("Found value in repeater row $i: $row_field_name = " . print_r($value, true));
                return $value;
            }
        }
        
        return null;
    }

    /**
     * Enhanced field validation
     */
    private function validate_source_field_enhanced( array $config ): array {
        $result = [
            'valid' => false,
            'errors' => []
        ];
        
        if ( empty( $config['source_field'] ) ) {
            $result['errors'][] = __( 'Source field is required.', 'bws-meta-manager' );
            return $result;
        }
        
        $source_field_data = $this->field_mapper->get_field_by_key( $config['source_field'] );
        
        if ( ! $source_field_data ) {
            $result['errors'][] = sprintf(
                __( 'Source field not found. Field key: %s', 'bws-meta-manager' ),
                $config['source_field']
            );
            return $result;
        }
        
        // For map data, check if field supports options
        if ( ! empty( $config['mappings'] ) && ! $source_field_data['has_options'] ) {
            $result['errors'][] = sprintf(
                __( 'Source field "%s" (%s) does not support options. Only select, checkbox, radio, and button_group fields can be mapped.', 'bws-meta-manager' ),
                $source_field_data['label'],
                $source_field_data['type']
            );
            return $result;
        }
        
        $result['valid'] = true;
        return $result;
    }

    /**
     * Get items for map conversion - SIMPLIFIED VERSION
     */
    private function get_items_for_map_conversion_simplified( array $config ): array {
        if ( $config['content_type'] === 'posts' ) {
            return $this->get_posts_for_conversion_simplified( $config );
        } else {
            return $this->get_terms_for_conversion_simplified( $config );
        }
    }

    /**
     * Get posts for conversion using simplified approach - SIMPLIFIED VERSION
     */
    private function get_posts_for_conversion_simplified( array $config ): array {
        error_log('=== GETTING POSTS FOR CONVERSION (SIMPLIFIED) ===');
        
        // Initialize debug info
        $this->last_conversion_debug_info = [
            'query_type' => 'posts',
            'config' => $config
        ];
        
        $args = [
            'post_type' => $config['post_types'] ?? 'any',
            'post_status' => $config['post_status'] ?? [ 'publish', 'draft', 'private' ],
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];

        // Get field information
        $source_field_data = $this->field_mapper->get_field_by_key( $config['source_field'] );
        
        if ( ! $source_field_data ) {
            error_log('ERROR: Source field not found for conversion');
            $this->last_conversion_debug_info['error'] = 'Source field not found';
            return [];
        }

        $actual_field_name = $this->get_actual_field_name( $source_field_data );
        
        $this->last_conversion_debug_info['field_name'] = $source_field_data['name'];
        $this->last_conversion_debug_info['field_key'] = $config['source_field'];
        $this->last_conversion_debug_info['field_type'] = $source_field_data['type'];
        $this->last_conversion_debug_info['actual_field_name'] = $actual_field_name;

        error_log('Source field: ' . $source_field_data['name'] . ' (' . $source_field_data['type'] . ')');
        error_log('Actual field name: ' . $actual_field_name);

        // First, get total count to see how many posts exist
        $count_query = new WP_Query( array_merge( $args, [ 'posts_per_page' => -1 ] ) );
        $total_posts = $count_query->found_posts;
        
        error_log("Total posts available: $total_posts");
        $this->last_conversion_debug_info['total_items_checked'] = $total_posts;

        if ( $total_posts === 0 ) {
            error_log('No posts found for the specified criteria');
            return [];
        }

        // Get posts and check for field values manually
        $query = new WP_Query( $args );
        $all_posts = $query->posts;
        
        error_log("Posts to check: " . count($all_posts));
        
        return $this->validate_posts_for_conversion_simplified( $all_posts, $source_field_data );
    }

    /**
     * Validate posts for conversion using simplified approach
     */
    private function validate_posts_for_conversion_simplified( array $all_posts, array $source_field_data ): array {
        $valid_posts = [];
        $checked_count = 0;
        $empty_count = 0;
        $sample_values = [];
        
        foreach ( $all_posts as $post_id ) {
            $checked_count++;
            
            // Get field value using simplified approach
            $field_value = $this->get_field_value_simplified( $source_field_data, $post_id, 'posts' );
            
            // Check if field has a meaningful value
            $has_value = $this->field_has_meaningful_value( $field_value );
            
            if ( $has_value ) {
                $valid_posts[] = [ 'id' => $post_id, 'type' => 'post' ];
                
                // Collect sample values for debugging (first 5)
                if ( count( $sample_values ) < 5 ) {
                    $sample_values[] = [
                        'post_id' => $post_id,
                        'value' => $field_value,
                        'value_type' => gettype( $field_value )
                    ];
                }
            } else {
                $empty_count++;
            }
            
            // Log progress every 100 posts
            if ( $checked_count % 100 === 0 ) {
                error_log("Checked $checked_count posts, found " . count($valid_posts) . " valid posts");
            }
        }

        $this->last_conversion_debug_info['posts_checked'] = $checked_count;
        $this->last_conversion_debug_info['posts_with_empty_fields'] = $empty_count;
        $this->last_conversion_debug_info['valid_posts_found'] = count($valid_posts);
        $this->last_conversion_debug_info['sample_values'] = $sample_values;

        error_log("Final results: $checked_count posts checked, " . count($valid_posts) . " valid posts found, $empty_count empty fields");
        
        if ( count( $sample_values ) > 0 ) {
            error_log("Sample values: " . print_r($sample_values, true));
        }

        return $valid_posts;
    }

    /**
     * Get terms for conversion using simplified approach
     */
    private function get_terms_for_conversion_simplified( array $config ): array {
        error_log('=== GETTING TERMS FOR CONVERSION (SIMPLIFIED) ===');
        
        $this->last_conversion_debug_info = [
            'query_type' => 'terms',
            'config' => $config
        ];
        
        $taxonomies = $config['taxonomies'] ?? [ 'any' ];
        $all_terms = [];

        if ( in_array( 'any', $taxonomies, true ) ) {
            $taxonomies = array_keys( $this->field_mapper->get_taxonomies() );
        }

        $source_field_data = $this->field_mapper->get_field_by_key( $config['source_field'] );
        
        if ( ! $source_field_data ) {
            error_log('ERROR: Source field not found for terms conversion');
            return [];
        }

        $actual_field_name = $this->get_actual_field_name( $source_field_data );

        $this->last_conversion_debug_info['field_name'] = $source_field_data['name'];
        $this->last_conversion_debug_info['field_key'] = $config['source_field'];
        $this->last_conversion_debug_info['actual_field_name'] = $actual_field_name;
        $this->last_conversion_debug_info['taxonomies'] = $taxonomies;

        $total_terms_checked = 0;
        $valid_terms_found = 0;

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $terms = get_terms( [
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids'
            ] );

            if ( is_wp_error( $terms ) ) {
                error_log("Error getting terms for taxonomy $taxonomy");
                continue;
            }

            error_log("Checking " . count($terms) . " terms in taxonomy $taxonomy");

            foreach ( $terms as $term_id ) {
                $total_terms_checked++;
                
                $field_value = get_field( $actual_field_name, "{$taxonomy}_{$term_id}" );
                
                // Check if field has a meaningful value
                $has_value = $this->field_has_meaningful_value( $field_value );
                
                if ( $has_value ) {
                    $all_terms[] = [ 
                        'id' => $term_id, 
                        'type' => 'term', 
                        'taxonomy' => $taxonomy 
                    ];
                    $valid_terms_found++;
                    
                    if ( $valid_terms_found <= 5 ) { // Log first 5 for debugging
                        error_log("Valid term ID: $term_id in taxonomy $taxonomy, value: " . print_r($field_value, true));
                    }
                }
            }
        }

        $this->last_conversion_debug_info['total_items_checked'] = $total_terms_checked;
        $this->last_conversion_debug_info['valid_terms_found'] = $valid_terms_found;

        error_log("Terms conversion: $total_terms_checked terms checked, $valid_terms_found valid terms found");

        return $all_terms;
    }

    /**
     * Check if a field value is meaningful (not empty)
     */
    private function field_has_meaningful_value( $field_value ): bool {
        if ( is_array( $field_value ) ) {
            return ! empty( $field_value ) && count( $field_value ) > 0;
        } elseif ( is_string( $field_value ) ) {
            return trim( $field_value ) !== '';
        } else {
            return ! empty( $field_value ) || is_numeric( $field_value );
        }
    }

    /**
     * Process a batch of copy data conversions (formerly move data)
     */
    private function process_copy_data_batch( array $items, array $config, bool $dry_run, int $batch_number ): array {
        $batch_result = [
            'batch_number' => $batch_number,
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'start_time' => microtime( true )
        ];

        foreach ( $items as $item ) {
            $batch_result['processed']++;

            try {
                $conversion_result = $this->execute_copy_data_conversion(
                    $item,
                    $config,
                    $dry_run
                );

                if ( $conversion_result['success'] ) {
                    $batch_result['successful']++;
                } else {
                    $batch_result['failed']++;
                    $batch_result['errors'][] = sprintf(
                        /* translators: %1$s: item type, %2$d: item ID, %3$s: error message */
                        __( '%1$s %2$d: %3$s', 'bws-meta-manager' ),
                        $config['content_type'] === 'posts' ? 'Post' : 'Term',
                        $item['id'],
                        $conversion_result['error']
                    );
                }
            } catch ( Exception $e ) {
                $batch_result['failed']++;
                $batch_result['errors'][] = sprintf(
                    /* translators: %1$s: item type, %2$d: item ID, %3$s: error message */
                    __( '%1$s %2$d: %3$s', 'bws-meta-manager' ),
                    $config['content_type'] === 'posts' ? 'Post' : 'Term',
                    $item['id'],
                    $e->getMessage()
                );
            }

            // Check if we should stop early
            if ( $this->should_stop_batch_processing( $batch_result['start_time'] ) ) {
                break;
            }
        }

        $batch_result['end_time'] = microtime( true );
        $batch_result['execution_time'] = $batch_result['end_time'] - $batch_result['start_time'];

        return $batch_result;
    }

    /**
     * Process a batch of map data conversions - SIMPLIFIED VERSION
     */
    private function process_map_data_batch( array $items, array $config, bool $dry_run, int $batch_number ): array {
        $batch_result = [
            'batch_number' => $batch_number,
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'warnings' => [],
            'start_time' => microtime( true )
        ];

        error_log("Processing batch $batch_number with " . count($items) . " items");

        foreach ( $items as $item ) {
            $batch_result['processed']++;

            try {
                $conversion_result = $this->execute_map_data_conversion(
                    $item,
                    $config,
                    $dry_run
                );

                if ( $conversion_result['success'] ) {
                    $batch_result['successful']++;
                } else {
                    $batch_result['failed']++;
                    $error_message = sprintf(
                        /* translators: %1$s: item type, %2$d: item ID, %3$s: error message */
                        __( '%1$s %2$d: %3$s', 'bws-meta-manager' ),
                        $config['content_type'] === 'posts' ? 'Post' : 'Term',
                        $item['id'],
                        $conversion_result['error'] ?? 'Unknown error'
                    );
                    $batch_result['errors'][] = $error_message;
                    error_log("Conversion failed for item {$item['id']}: " . ($conversion_result['error'] ?? 'Unknown error'));
                }
            } catch ( Exception $e ) {
                $batch_result['failed']++;
                $error_message = sprintf(
                    /* translators: %1$s: item type, %2$d: item ID, %3$s: error message */
                    __( '%1$s %2$d: %3$s', 'bws-meta-manager' ),
                    $config['content_type'] === 'posts' ? 'Post' : 'Term',
                    $item['id'],
                    $e->getMessage()
                );
                $batch_result['errors'][] = $error_message;
                error_log("Exception in conversion for item {$item['id']}: " . $e->getMessage());
            }

            // Check if we should stop early
            if ( $this->should_stop_batch_processing( $batch_result['start_time'] ) ) {
                $batch_result['warnings'][] = __( 'Batch stopped early due to resource limits.', 'bws-meta-manager' );
                break;
            }
        }

        $batch_result['end_time'] = microtime( true );
        $batch_result['execution_time'] = $batch_result['end_time'] - $batch_result['start_time'];

        error_log("Batch $batch_number completed: {$batch_result['successful']} successful, {$batch_result['failed']} failed in {$batch_result['execution_time']}s");

        return $batch_result;
    }

    /**
     * Execute copy data conversion for a single item (formerly move data)
     */
    private function execute_copy_data_conversion( array $item, array $config, bool $dry_run ): array {
        $copy_type = $config['copy_type'];

        switch ( $copy_type ) {
            case 'field_to_field':
                return $this->copy_field_to_field( $item, $config, $dry_run );
                
            case 'field_to_taxonomy':
                return $this->copy_field_to_taxonomy( $item, $config, $dry_run );
                
            case 'taxonomy_to_field':
                return $this->copy_taxonomy_to_field( $item, $config, $dry_run );
                
            case 'taxonomy_to_taxonomy':
                return $this->copy_taxonomy_to_taxonomy( $item, $config, $dry_run );
                
            default:
                return [
                    'success' => false,
                    'error' => __( 'Invalid copy type.', 'bws-meta-manager' )
                ];
        }
    }

    /**
     * Execute map data conversion for a single item - SIMPLIFIED VERSION
     */
    private function execute_map_data_conversion( array $item, array $config, bool $dry_run ): array {
        $source_field_data = $this->field_mapper->get_field_by_key( $config['source_field'] );

        if ( ! $source_field_data ) {
            return [
                'success' => false,
                'error' => __( 'Source field not found.', 'bws-meta-manager' )
            ];
        }

        // Get source value using simplified approach
        $source_value = $this->get_field_value_simplified( 
            $source_field_data, 
            $item['id'], 
            $config['content_type'], 
            $item['taxonomy'] ?? '' 
        );
        
        if ( empty( $source_value ) ) {
            return [
                'success' => true,
                'message' => __( 'No data to map.', 'bws-meta-manager' )
            ];
        }

        // Apply mappings based on target type
        if ( $config['target_type'] === 'field' ) {
            return $this->map_to_field( $item, $source_value, $config, $dry_run );
        } else {
            return $this->map_to_taxonomy( $item, $source_value, $config, $dry_run );
        }
    }

    /**
     * Copy data from field to field (formerly move_field_to_field)
     */
    private function copy_field_to_field( array $item, array $config, bool $dry_run ): array {
        $source_field_data = $this->field_mapper->get_field_by_key( $config['source_field'] );
        $target_field_data = $this->field_mapper->get_field_by_key( $config['target_field'] );

        if ( ! $source_field_data || ! $target_field_data ) {
            return [
                'success' => false,
                'error' => __( 'Field data not found.', 'bws-meta-manager' )
            ];
        }

        // Get source value using simplified approach
        $source_value = $this->get_field_value_simplified( 
            $source_field_data, 
            $item['id'], 
            $config['content_type'], 
            $item['taxonomy'] ?? '' 
        );
        
        if ( empty( $source_value ) && ! is_numeric( $source_value ) ) {
            return [
                'success' => true,
                'message' => __( 'No data to copy.', 'bws-meta-manager' )
            ];
        }

        if ( ! $dry_run ) {
            // Get target field name
            $target_field_name = $this->get_actual_field_name( $target_field_data );
            
            // Update the target field
            if ( $config['content_type'] === 'posts' ) {
                $update_result = update_field( $target_field_name, $source_value, $item['id'] );
            } else {
                $update_result = update_field( $target_field_name, $source_value, "{$item['taxonomy']}_{$item['id']}" );
            }
            
            if ( ! $update_result ) {
                return [
                    'success' => false,
                    'error' => __( 'Failed to update field value.', 'bws-meta-manager' )
                ];
            }
        }

        return [
            'success' => true,
            'source_value' => $source_value,
            'copied_value' => $source_value
        ];
    }

    /**
     * Copy data from field to taxonomy (formerly move_field_to_taxonomy)
     */
    private function copy_field_to_taxonomy( array $item, array $config, bool $dry_run ): array {
        $source_field_data = $this->field_mapper->get_field_by_key( $config['source_field'] );

        if ( ! $source_field_data ) {
            return [
                'success' => false,
                'error' => __( 'Source field not found.', 'bws-meta-manager' )
            ];
        }

        $source_value = $this->get_field_value_simplified( 
            $source_field_data, 
            $item['id'], 
            $config['content_type'], 
            $item['taxonomy'] ?? '' 
        );
        
        if ( empty( $source_value ) ) {
            return [
                'success' => true,
                'message' => __( 'No data to copy.', 'bws-meta-manager' )
            ];
        }

        // Convert value to term names
        $term_names = $this->extract_term_names_from_value( $source_value, $source_field_data['type'] );
        
        if ( empty( $term_names ) ) {
            return [
                'success' => true,
                'message' => __( 'No valid terms extracted.', 'bws-meta-manager' )
            ];
        }

        $term_ids = [];
        foreach ( $term_names as $term_name ) {
            if ( ! $dry_run ) {
                $term_result = $this->field_mapper->create_taxonomy_term( $config['target_taxonomy'], $term_name );
                
                if ( is_wp_error( $term_result ) ) {
                    return [
                        'success' => false,
                        'error' => $term_result->get_error_message()
                    ];
                }
                
                $term_ids[] = $term_result['term_id'];
            } else {
                // For dry run, simulate term creation
                $existing_term = get_term_by( 'name', $term_name, $config['target_taxonomy'] );
                $term_ids[] = $existing_term ? $existing_term->term_id : 'new';
            }
        }

        if ( ! $dry_run && ! empty( $term_ids ) && $config['content_type'] === 'posts' ) {
            // Set taxonomy terms for post
            $set_result = wp_set_post_terms( $item['id'], $term_ids, $config['target_taxonomy'], $config['append_terms'] ?? false );
            
            if ( is_wp_error( $set_result ) ) {
                return [
                    'success' => false,
                    'error' => $set_result->get_error_message()
                ];
            }
        }

        return [
            'success' => true,
            'source_value' => $source_value,
            'term_names' => $term_names,
            'term_ids' => $term_ids
        ];
    }

    /**
     * Copy data from taxonomy to field (formerly move_taxonomy_to_field)
     */
    private function copy_taxonomy_to_field( array $item, array $config, bool $dry_run ): array {
        $target_field_data = $this->field_mapper->get_field_by_key( $config['target_field'] );

        if ( ! $target_field_data ) {
            return [
                'success' => false,
                'error' => __( 'Target field not found.', 'bws-meta-manager' )
            ];
        }

        // Get source taxonomy terms
        if ( $config['content_type'] === 'posts' ) {
            $terms = wp_get_post_terms( $item['id'], $config['source_taxonomy'], [ 'fields' => 'names' ] );
        } else {
            // For taxonomy terms, this would be a relationship between taxonomies
            $terms = [];
        }

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [
                'success' => true,
                'message' => __( 'No terms to copy.', 'bws-meta-manager' )
            ];
        }

        // Convert terms to field value based on target field type
        $field_value = $this->convert_terms_to_field_value( $terms, $target_field_data['type'] );

        if ( ! $dry_run ) {
            // Get target field name
            $target_field_name = $this->get_actual_field_name( $target_field_data );
            
            // Update the target field
            if ( $config['content_type'] === 'posts' ) {
                $update_result = update_field( $target_field_name, $field_value, $item['id'] );
            } else {
                $update_result = update_field( $target_field_name, $field_value, "{$item['taxonomy']}_{$item['id']}" );
            }
            
            if ( ! $update_result ) {
                return [
                    'success' => false,
                    'error' => __( 'Failed to update field value.', 'bws-meta-manager' )
                ];
            }
        }

        return [
            'success' => true,
            'source_terms' => $terms,
            'field_value' => $field_value
        ];
    }

    /**
     * Copy data from taxonomy to taxonomy (formerly move_taxonomy_to_taxonomy)
     */
    private function copy_taxonomy_to_taxonomy( array $item, array $config, bool $dry_run ): array {
        if ( $config['content_type'] !== 'posts' ) {
            return [
                'success' => false,
                'error' => __( 'Taxonomy to taxonomy copying only supported for posts.', 'bws-meta-manager' )
            ];
        }

        // Get source taxonomy terms
        $source_terms = wp_get_post_terms( $item['id'], $config['source_taxonomy'] );

        if ( is_wp_error( $source_terms ) || empty( $source_terms ) ) {
            return [
                'success' => true,
                'message' => __( 'No terms to copy.', 'bws-meta-manager' )
            ];
        }

        $target_term_ids = [];
        
        foreach ( $source_terms as $source_term ) {
            if ( ! $dry_run ) {
                $term_result = $this->field_mapper->create_taxonomy_term( 
                    $config['target_taxonomy'], 
                    $source_term->name 
                );
                
                if ( is_wp_error( $term_result ) ) {
                    return [
                        'success' => false,
                        'error' => $term_result->get_error_message()
                    ];
                }
                
                $target_term_ids[] = $term_result['term_id'];
            } else {
                // For dry run, simulate term creation
                $existing_term = get_term_by( 'name', $source_term->name, $config['target_taxonomy'] );
                $target_term_ids[] = $existing_term ? $existing_term->term_id : 'new';
            }
        }

        if ( ! $dry_run && ! empty( $target_term_ids ) ) {
            // Set target taxonomy terms for post
            $set_result = wp_set_post_terms( $item['id'], $target_term_ids, $config['target_taxonomy'], $config['append_terms'] ?? false );
            
            if ( is_wp_error( $set_result ) ) {
                return [
                    'success' => false,
                    'error' => $set_result->get_error_message()
                ];
            }
        }

        return [
            'success' => true,
            'source_terms' => wp_list_pluck( $source_terms, 'name' ),
            'target_term_ids' => $target_term_ids
        ];
    }

    /**
     * Map field values to another field - UPDATED with skip unmapped values
     */
    private function map_to_field( array $item, $source_value, array $config, bool $dry_run ): array {
        $target_field_data = $this->field_mapper->get_field_by_key( $config['target_field'] );
        
        if ( ! $target_field_data ) {
            return [
                'success' => false,
                'error' => __( 'Target field not found.', 'bws-meta-manager' )
            ];
        }

        $mappings = $config['mappings'] ?? [];
        $applied = [];
        $skipped = [];

        // Handle single or multiple values
        $source_values = is_array( $source_value ) ? $source_value : [ $source_value ];
        $new_values = [];

        foreach ( $source_values as $value ) {
            if ( isset( $mappings[ $value ] ) && ! empty( $mappings[ $value ] ) ) {
                $new_values[] = $mappings[ $value ];
                $applied[] = [
                    'from' => $value,
                    'to' => $mappings[ $value ]
                ];
            } else {
                // Skip unmapped values
                $skipped[] = $value;
            }
        }

        if ( ! $dry_run && ! empty( $new_values ) ) {
            $final_value = $target_field_data['type'] === 'checkbox' ? $new_values : $new_values[0];
            $target_field_name = $this->get_actual_field_name( $target_field_data );
            
            if ( $config['content_type'] === 'posts' ) {
                $update_result = update_field( $target_field_name, $final_value, $item['id'] );
            } else {
                $update_result = update_field( $target_field_name, $final_value, "{$item['taxonomy']}_{$item['id']}" );
            }
            
            if ( ! $update_result ) {
                return [
                    'success' => false,
                    'error' => __( 'Failed to update target field.', 'bws-meta-manager' )
                ];
            }
        }

        return [
            'success' => true,
            'source_value' => $source_value,
            'applied_mappings' => $applied,
            'skipped_values' => $skipped
        ];
    }

    /**
     * Map field values to taxonomy terms - UPDATED with skip unmapped values
     */
    private function map_to_taxonomy( array $item, $source_value, array $config, bool $dry_run ): array {
        $mappings = $config['mappings'] ?? [];
        $applied = [];
        $skipped = [];

        // Handle single or multiple values
        $source_values = is_array( $source_value ) ? $source_value : [ $source_value ];
        $term_ids = [];

        foreach ( $source_values as $value ) {
            if ( isset( $mappings[ $value ] ) && ! empty( $mappings[ $value ] ) ) {
                $term_name = $mappings[ $value ];
                
                if ( ! $dry_run ) {
                    $term_result = $this->field_mapper->create_taxonomy_term( $config['target_taxonomy'], $term_name );
                    if ( ! is_wp_error( $term_result ) ) {
                        $term_ids[] = $term_result['term_id'];
                    }
                }
                
                $applied[] = [
                    'from' => $value,
                    'to' => $term_name
                ];
            } else {
                // Skip unmapped values
                $skipped[] = $value;
            }
        }

        if ( ! $dry_run && ! empty( $term_ids ) && $config['content_type'] === 'posts' ) {
            $set_result = wp_set_post_terms( $item['id'], $term_ids, $config['target_taxonomy'], $config['append_terms'] ?? false );
            
            if ( is_wp_error( $set_result ) ) {
                return [
                    'success' => false,
                    'error' => $set_result->get_error_message()
                ];
            }
        }

        return [
            'success' => true,
            'source_value' => $source_value,
            'applied_mappings' => $applied,
            'skipped_values' => $skipped
        ];
    }

    /**
     * Convert terms to field value based on field type
     */
    private function convert_terms_to_field_value( array $terms, string $field_type ) {
        switch ( $field_type ) {
            case 'checkbox':
                return $terms; // Array of values
                
            case 'select':
            case 'radio':
                return $terms[0] ?? ''; // Single value
                
            case 'text':
            case 'textarea':
                return implode( ', ', $terms ); // Comma-separated string
                
            default:
                return is_array( $terms ) && count( $terms ) === 1 ? $terms[0] : $terms;
        }
    }

    /**
     * Extract term names from field value
     */
    private function extract_term_names_from_value( $value, string $field_type ): array {
        $term_names = [];

        switch ( $field_type ) {
            case 'text':
            case 'textarea':
                if ( is_string( $value ) ) {
                    // Split by common delimiters
                    $terms = preg_split( '/[,;|\n\r]+/', $value );
                    foreach ( $terms as $term ) {
                        $term = trim( $term );
                        if ( ! empty( $term ) ) {
                            $term_names[] = $term;
                        }
                    }
                }
                break;
                
            case 'select':
            case 'radio':
                if ( ! empty( $value ) ) {
                    $term_names[] = $value;
                }
                break;
                
            case 'checkbox':
                if ( is_array( $value ) ) {
                    $term_names = array_filter( $value );
                }
                break;
                
            default:
                if ( is_array( $value ) ) {
                    $term_names = array_filter( $value );
                } elseif ( ! empty( $value ) ) {
                    $term_names[] = $value;
                }
        }

        return array_unique( $term_names );
    }

    /**
     * Get items for copy conversion (formerly get_items_for_move_conversion)
     */
    private function get_items_for_copy_conversion( array $config ): array {
        if ( $config['content_type'] === 'posts' ) {
            return $this->get_posts_for_conversion( $config );
        } else {
            return $this->get_terms_for_conversion( $config );
        }
    }

    /**
     * Get posts for conversion (original method for copy operations)
     */
    private function get_posts_for_conversion( array $config ): array {
        $args = [
            'post_type' => $config['post_types'] ?? 'any',
            'post_status' => $config['post_status'] ?? [ 'publish', 'draft', 'private' ],
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];

        // Add meta query for source field if applicable
        if ( isset( $config['source_field'] ) ) {
            $source_field = $this->field_mapper->get_field_by_key( $config['source_field'] );
            if ( $source_field ) {
                $actual_field_name = $this->get_actual_field_name( $source_field );
                $args['meta_query'] = [
                    [
                        'key' => $actual_field_name,
                        'compare' => 'EXISTS'
                    ]
                ];
            }
        }

        $query = new WP_Query( $args );
        
        return array_map( function( $post_id ) {
            return [ 'id' => $post_id, 'type' => 'post' ];
        }, $query->posts );
    }

    /**
     * Get terms for conversion
     */
    private function get_terms_for_conversion( array $config ): array {
        $taxonomies = $config['taxonomies'] ?? [ 'any' ];
        $all_terms = [];

        if ( in_array( 'any', $taxonomies, true ) ) {
            $taxonomies = array_keys( $this->field_mapper->get_taxonomies() );
        }

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $terms = get_terms( [
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids'
            ] );

            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term_id ) {
                    $all_terms[] = [ 
                        'id' => $term_id, 
                        'type' => 'term', 
                        'taxonomy' => $taxonomy 
                    ];
                }
            }
        }

        return $all_terms;
    }

    /**
     * Calculate optimal batch size based on configuration
     */
    public function calculate_batch_size( array $config ): int {
        $base_size = $config['batch_size'] ?? self::DEFAULT_BATCH_SIZE;
        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $available_memory = $memory_limit - memory_get_usage();
        
        // Adjust batch size based on available memory
        if ( $available_memory < ( $memory_limit * 0.3 ) ) {
            $base_size = max( 5, intval( $base_size * 0.5 ) );
        }
        
        return $base_size;
    }

    /**
     * Check if processing should stop due to resource limits
     */
    private function should_stop_processing(): bool {
        // Check memory usage
        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $memory_usage = memory_get_usage();
        $memory_percentage = ( $memory_usage / $memory_limit ) * 100;
        
        if ( $memory_percentage > self::MEMORY_THRESHOLD ) {
            return true;
        }
        
        // Check execution time (for AJAX requests)
        if ( wp_doing_ajax() ) {
            $execution_time = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
            if ( $execution_time > self::MAX_EXECUTION_TIME ) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if batch processing should stop
     */
    private function should_stop_batch_processing( float $start_time ): bool {
        $execution_time = microtime( true ) - $start_time;
        return $execution_time > ( self::MAX_EXECUTION_TIME * 0.8 ); // Leave some buffer
    }

    /**
     * Initialize processing result array
     */
    private function initialize_processing_result(): array {
        return [
            'success' => false,
            'total_items' => 0,
            'processed_items' => 0,
            'successful_conversions' => 0,
            'failed_conversions' => 0,
            'total_batches' => 0,
            'batch_results' => [],
            'errors' => [],
            'warnings' => [],
            'message' => '',
            'stopped_early' => false
        ];
    }

    /**
     * Validate copy data configuration (formerly validate_move_data_config)
     */
    private function validate_copy_data_config( array $config ): array {
        $errors = [];

        if ( empty( $config['content_type'] ) || ! in_array( $config['content_type'], [ 'posts', 'taxonomy_terms' ], true ) ) {
            $errors[] = __( 'Valid content type is required.', 'bws-meta-manager' );
        }

        if ( empty( $config['copy_type'] ) || ! in_array( $config['copy_type'], self::COPY_TYPES, true ) ) {
            $errors[] = __( 'Valid copy type is required.', 'bws-meta-manager' );
        }

        // Validate specific copy type requirements
        switch ( $config['copy_type'] ?? '' ) {
            case 'field_to_field':
                if ( empty( $config['source_field'] ) ) {
                    $errors[] = __( 'Source field is required.', 'bws-meta-manager' );
                }
                if ( empty( $config['target_field'] ) ) {
                    $errors[] = __( 'Target field is required.', 'bws-meta-manager' );
                }
                break;
                
            case 'field_to_taxonomy':
                if ( empty( $config['source_field'] ) ) {
                    $errors[] = __( 'Source field is required.', 'bws-meta-manager' );
                }
                if ( empty( $config['target_taxonomy'] ) ) {
                    $errors[] = __( 'Target taxonomy is required.', 'bws-meta-manager' );
                }
                break;
                
            case 'taxonomy_to_field':
                if ( empty( $config['source_taxonomy'] ) ) {
                    $errors[] = __( 'Source taxonomy is required.', 'bws-meta-manager' );
                }
                if ( empty( $config['target_field'] ) ) {
                    $errors[] = __( 'Target field is required.', 'bws-meta-manager' );
                }
                break;
                
            case 'taxonomy_to_taxonomy':
                if ( empty( $config['source_taxonomy'] ) ) {
                    $errors[] = __( 'Source taxonomy is required.', 'bws-meta-manager' );
                }
                if ( empty( $config['target_taxonomy'] ) ) {
                    $errors[] = __( 'Target taxonomy is required.', 'bws-meta-manager' );
                }
                break;
        }

        return [
            'valid' => empty( $errors ),
            'errors' => $errors
        ];
    }

    /**
     * Validate map data configuration
     */
    private function validate_map_data_config( array $config ): array {
        $errors = [];

        if ( empty( $config['content_type'] ) || ! in_array( $config['content_type'], [ 'posts', 'taxonomy_terms' ], true ) ) {
            $errors[] = __( 'Valid content type is required.', 'bws-meta-manager' );
        }

        if ( empty( $config['source_field'] ) ) {
            $errors[] = __( 'Source field is required.', 'bws-meta-manager' );
        }

        if ( empty( $config['mappings'] ) || ! is_array( $config['mappings'] ) ) {
            $errors[] = __( 'Option mappings are required.', 'bws-meta-manager' );
        }

        if ( empty( $config['target_type'] ) || ! in_array( $config['target_type'], [ 'field', 'taxonomy' ], true ) ) {
            $errors[] = __( 'Valid target type is required.', 'bws-meta-manager' );
        }

        if ( $config['target_type'] === 'field' && empty( $config['target_field'] ) ) {
            $errors[] = __( 'Target field is required when mapping to field.', 'bws-meta-manager' );
        }

        if ( $config['target_type'] === 'taxonomy' && empty( $config['target_taxonomy'] ) ) {
            $errors[] = __( 'Target taxonomy is required when mapping to taxonomy.', 'bws-meta-manager' );
        }

        return [
            'valid' => empty( $errors ),
            'errors' => $errors
        ];
    }

    /**
     * Get component instance
     */
    public function get_component( string $component ) {
        if ( $component === 'field_mapper' ) {
            return $this->field_mapper;
        }
        
        return null;
    }
}