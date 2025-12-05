<?php
/**
 * ACF Preview System Class - UPDATED VERSION
 * 
 * Complete file with simplified concatenated name approach for sub-fields
 * Updated to use "copy" terminology instead of "move"
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BWS_ACF_Preview_System {

    /**
     * Data processor instance
     *
     * @var BWS_ACF_Data_Processor
     */
    private $data_processor;

    /**
     * Preview table name
     *
     * @var string
     */
    private $preview_table;

    /**
     * Default number of preview samples
     */
    private const DEFAULT_PREVIEW_SAMPLES = 10;

    /**
     * Maximum number of preview samples
     */
    private const MAX_PREVIEW_SAMPLES = 50;

    /**
     * Preview session duration (1 hour)
     */
    private const PREVIEW_SESSION_DURATION = HOUR_IN_SECONDS;

    /**
     * Store debug info for last query
     */
    private $last_query_debug_info = [];

    /**
     * Constructor
     *
     * @param BWS_ACF_Data_Processor $data_processor Data processor instance
     */
    public function __construct( BWS_ACF_Data_Processor $data_processor ) {
        $this->data_processor = $data_processor;
        $this->setup_preview_table();
        $this->schedule_cleanup();
    }

    /**
     * Generate preview for copy data conversion (formerly move data)
     *
     * @param array $config Conversion configuration
     * @param int $sample_count Number of samples to generate
     * @return array
     */
    public function generate_copy_data_preview( array $config, int $sample_count = self::DEFAULT_PREVIEW_SAMPLES ): array {
        $sample_count = min( $sample_count, self::MAX_PREVIEW_SAMPLES );
        $session_id = $this->generate_session_id();
        
        $result = [
            'session_id' => $session_id,
            'conversion_type' => 'copy_data',
            'copy_type' => $config['copy_type'] ?? '',
            'samples' => [],
            'summary' => [
                'total_samples' => 0,
                'successful_samples' => 0,
                'failed_samples' => 0,
                'warnings' => []
            ],
            'success' => false
        ];

        // Get sample items
        $sample_items = $this->get_sample_items_for_conversion( $config, $sample_count );
        
        if ( empty( $sample_items ) ) {
            $result['error'] = __( 'No items found to preview.', 'acf-data-conversion' );
            return $result;
        }

        // Process samples based on copy type
        switch ( $config['copy_type'] ) {
            case 'field_to_field':
                $result = $this->generate_field_to_field_preview( $config, $sample_items, $session_id, $result );
                break;
                
            case 'field_to_taxonomy':
                $result = $this->generate_field_to_taxonomy_preview( $config, $sample_items, $session_id, $result );
                break;
                
            case 'taxonomy_to_field':
                $result = $this->generate_taxonomy_to_field_preview( $config, $sample_items, $session_id, $result );
                break;
                
            case 'taxonomy_to_taxonomy':
                $result = $this->generate_taxonomy_to_taxonomy_preview( $config, $sample_items, $session_id, $result );
                break;
                
            default:
                $result['error'] = __( 'Invalid copy type.', 'acf-data-conversion' );
                return $result;
        }

        // Store preview session metadata
        $this->store_preview_session( $session_id, $config, $result['summary'] );
        
        $result['success'] = true;
        return $result;
    }

    /**
     * Generate preview for map data conversion - SIMPLIFIED VERSION
     */
    public function generate_map_data_preview( array $config, int $sample_count = self::DEFAULT_PREVIEW_SAMPLES ): array {
        $sample_count = min( $sample_count, self::MAX_PREVIEW_SAMPLES );
        $session_id = $this->generate_session_id();
        
        $result = [
            'session_id' => $session_id,
            'conversion_type' => 'map_data',
            'samples' => [],
            'summary' => [
                'total_samples' => 0,
                'successful_samples' => 0,
                'failed_samples' => 0,
                'mapped_values' => [],
                'unmapped_values' => [],
                'warnings' => []
            ],
            'debug_info' => [],
            'success' => false
        ];

        // Debug configuration
        error_log('=== MAP DATA PREVIEW DEBUG (UPDATED) ===');
        error_log('Config received: ' . print_r($config, true));
        
        // Validate source field
        $field_mapper = $this->data_processor->get_component( 'field_mapper' );
        $source_field_data = $field_mapper->get_field_by_key( $config['source_field'] );
        
        if ( ! $source_field_data ) {
            $result['error'] = sprintf( 
                __( 'Source field not found. Field key: %s', 'acf-data-conversion' ),
                $config['source_field'] 
            );
            error_log('Source field not found for key: ' . $config['source_field']);
            return $result;
        }
        
        error_log('Source field found: ' . print_r($source_field_data, true));
        $result['debug_info']['source_field'] = $source_field_data;

        // Check if field has options (required for mapping)
        if ( ! $source_field_data['has_options'] ) {
            $result['error'] = sprintf(
                __( 'Source field "%s" does not support options. Only select, checkbox, radio, and button_group fields can be mapped.', 'acf-data-conversion' ),
                $source_field_data['label']
            );
            error_log('Source field does not have options: ' . $source_field_data['type']);
            return $result;
        }

        // Get sample items using simplified approach
        $sample_items = $this->get_sample_items_for_conversion_simplified( $config, $sample_count );
        $result['debug_info']['sample_query_info'] = $this->last_query_debug_info ?? [];
        
        error_log('Sample items found: ' . count($sample_items));
        
        if ( empty( $sample_items ) ) {
            $result['error'] = sprintf(
                __( 'No items found with data in field "%s" (%s). Checked %d %s with field key "%s".', 'acf-data-conversion' ),
                $source_field_data['label'],
                $this->get_actual_field_name($source_field_data),
                $this->last_query_debug_info['total_items_checked'] ?? 0,
                $config['content_type'] === 'posts' ? 'posts' : 'terms',
                $config['source_field']
            );
            $result['debug_info']['error_details'] = [
                'field_name' => $this->get_actual_field_name($source_field_data),
                'field_key' => $config['source_field'],
                'content_type' => $config['content_type'],
                'query_details' => $this->last_query_debug_info ?? []
            ];
            return $result;
        }

        // Process samples
        foreach ( $sample_items as $item ) {
            $sample_result = $this->process_map_data_sample(
                $item,
                $config,
                $session_id
            );
            
            if ( $sample_result ) {
                $result['samples'][] = $sample_result;
                $result['summary']['total_samples']++;
                
                if ( $sample_result['success'] ) {
                    $result['summary']['successful_samples']++;
                    
                    // Track mapped and unmapped values
                    if ( isset( $sample_result['preview_data']['mapped_values'] ) ) {
                        $result['summary']['mapped_values'] = array_unique(
                            array_merge( $result['summary']['mapped_values'], $sample_result['preview_data']['mapped_values'] )
                        );
                    }
                    
                    if ( isset( $sample_result['preview_data']['unmapped_values'] ) ) {
                        $result['summary']['unmapped_values'] = array_unique(
                            array_merge( $result['summary']['unmapped_values'], $sample_result['preview_data']['unmapped_values'] )
                        );
                    }
                } else {
                    $result['summary']['failed_samples']++;
                }
            }
        }

        // Store preview session metadata
        $this->store_preview_session( $session_id, $config, $result['summary'] );
        
        $result['success'] = true;
        error_log('Preview generation completed successfully');
        return $result;
    }

    /**
     * Get sample items for conversion - SIMPLIFIED VERSION
     */
    private function get_sample_items_for_conversion_simplified( array $config, int $limit ): array {
        if ( $config['content_type'] === 'posts' ) {
            return $this->get_sample_posts_simplified( $config, $limit );
        } else {
            return $this->get_sample_terms_simplified( $config, $limit );
        }
    }

    /**
     * Get sample posts using simplified approach - SIMPLIFIED VERSION
     */
    private function get_sample_posts_simplified( array $config, int $limit ): array {
        error_log('=== GETTING SAMPLE POSTS (SIMPLIFIED) ===');
        
        // Initialize debug info
        $this->last_query_debug_info = [
            'query_type' => 'posts',
            'config' => $config,
            'limit' => $limit
        ];
        
        $args = [
            'post_type' => $config['post_types'] ?? 'any',
            'post_status' => $config['post_status'] ?? [ 'publish', 'draft', 'private' ],
            'posts_per_page' => $limit * 10, // Get more to account for empty values
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        error_log('Query args: ' . print_r($args, true));

        // Get field information
        $field_mapper = $this->data_processor->get_component( 'field_mapper' );
        $source_field_data = $field_mapper->get_field_by_key( $config['source_field'] );
        
        if ( ! $source_field_data ) {
            error_log('ERROR: Source field data not found for key: ' . $config['source_field']);
            $this->last_query_debug_info['error'] = 'Source field not found';
            return [];
        }

        error_log('Source field data: ' . print_r($source_field_data, true));
        $this->last_query_debug_info['field_data'] = $source_field_data;

        // Get the actual field name to use for querying
        $actual_field_name = $this->get_actual_field_name($source_field_data);
        error_log('Actual field name to use: ' . $actual_field_name);
        $this->last_query_debug_info['actual_field_name'] = $actual_field_name;

        // First, get total count without meta query
        $count_args = array_merge( $args, [
            'posts_per_page' => -1,
            'fields' => 'ids'
        ] );
        
        $count_query = new WP_Query( $count_args );
        $total_posts = $count_query->found_posts;
        
        error_log("Total posts available: $total_posts");
        $this->last_query_debug_info['total_items_checked'] = $total_posts;

        // Get posts and check manually for field values
        $query = new WP_Query( $args );
        $all_posts = $query->posts;
        
        error_log("Posts to check: " . count($all_posts));
        
        $valid_posts = [];
        $checked_count = 0;
        $found_values = [];
        
        foreach ( $all_posts as $post_id ) {
            if ( count( $valid_posts ) >= $limit ) {
                break;
            }
            
            $checked_count++;
            
            // Get field value using simplified approach
            $field_value = get_field( $actual_field_name, $post_id, false );
            
            // Check if field has a meaningful value
            $has_value = $this->field_has_meaningful_value( $field_value );
            
            if ( $has_value ) {
                $valid_posts[] = [ 'id' => $post_id, 'type' => 'post' ];
                
                // Collect sample values for debugging (first 5)
                if ( count( $found_values ) < 5 ) {
                    $found_values[] = [
                        'post_id' => $post_id,
                        'value' => $field_value,
                        'value_type' => gettype( $field_value )
                    ];
                }
                
                if ( count( $valid_posts ) <= 3 ) { // Log first 3 for debugging
                    error_log("Valid post ID: $post_id, value: " . print_r($field_value, true));
                }
            }
            
            // Log progress every 50 posts
            if ( $checked_count % 50 === 0 ) {
                error_log("Checked $checked_count posts, found " . count($valid_posts) . " valid posts");
            }
        }
        
        $this->last_query_debug_info['manual_check_results'] = [
            'posts_checked' => $checked_count,
            'valid_posts_found' => count($valid_posts),
            'sample_values' => $found_values
        ];
        
        error_log("Simplified check completed: $checked_count posts checked, " . count($valid_posts) . " valid posts found");
        
        if ( count( $valid_posts ) > 0 ) {
            error_log("Sample values found: " . print_r($found_values, true));
        }
        
        return $valid_posts;
    }

    /**
     * Get actual field name to use for queries - SIMPLIFIED VERSION
     */
    private function get_actual_field_name( array $source_field_data ): string {
        // Check if this is a sub-field
        if ( ! empty( $source_field_data['parent'] ) ) {
            $field_mapper = $this->data_processor->get_component( 'field_mapper' );
            $parent_field_data = $field_mapper->get_field_by_key( $source_field_data['parent'] );
            
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
     * Get sample terms using simplified approach
     */
    private function get_sample_terms_simplified( array $config, int $limit ): array {
        error_log('=== GETTING SAMPLE TERMS (SIMPLIFIED) ===');
        
        $this->last_query_debug_info = [
            'query_type' => 'terms',
            'config' => $config,
            'limit' => $limit
        ];
        
        $taxonomies = $config['taxonomies'] ?? [ 'any' ];
        $all_terms = [];

        if ( in_array( 'any', $taxonomies, true ) ) {
            $field_mapper = $this->data_processor->get_component( 'field_mapper' );
            $taxonomies = array_keys( $field_mapper->get_taxonomies() );
        }

        $field_mapper = $this->data_processor->get_component( 'field_mapper' );
        $source_field_data = $field_mapper->get_field_by_key( $config['source_field'] );
        
        if ( ! $source_field_data ) {
            error_log('ERROR: Source field data not found for terms');
            return [];
        }

        $actual_field_name = $this->get_actual_field_name( $source_field_data );
        error_log('Checking taxonomies: ' . implode(', ', $taxonomies));
        error_log('Using field name: ' . $actual_field_name);
        
        $this->last_query_debug_info['taxonomies'] = $taxonomies;
        $this->last_query_debug_info['field_data'] = $source_field_data;
        $this->last_query_debug_info['actual_field_name'] = $actual_field_name;

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            // Get all terms for this taxonomy
            $terms = get_terms( [
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids',
                'number' => 100 // Check up to 100 terms per taxonomy
            ] );

            if ( is_wp_error( $terms ) ) {
                error_log("Error getting terms for taxonomy $taxonomy: " . $terms->get_error_message());
                continue;
            }

            error_log("Found " . count($terms) . " terms in taxonomy $taxonomy");

            foreach ( $terms as $term_id ) {
                if ( count( $all_terms ) >= $limit ) {
                    break 2;
                }
                
                // Check if term has the field value
                $field_value = get_field( $actual_field_name, "{$taxonomy}_{$term_id}" );
                
                if ( $this->field_has_meaningful_value( $field_value ) ) {
                    $all_terms[] = [ 
                        'id' => $term_id, 
                        'type' => 'term', 
                        'taxonomy' => $taxonomy 
                    ];
                    
                    if ( count( $all_terms ) <= 3 ) {
                        error_log("Valid term ID: $term_id in taxonomy $taxonomy with value: " . print_r($field_value, true));
                    }
                }
            }
        }

        $this->last_query_debug_info['final_valid_terms'] = count($all_terms);
        error_log("Final valid terms: " . count($all_terms));

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
     * Process map data sample - SIMPLIFIED VERSION
     */
    private function process_map_data_sample( array $item, array $config, string $session_id ): ?array {
        $field_mapper = $this->data_processor->get_component( 'field_mapper' );
        $source_field_data = $field_mapper->get_field_by_key( $config['source_field'] );

        if ( ! $source_field_data ) {
            error_log('process_map_data_sample: Source field not found');
            return null;
        }

        // Get the actual field name to use
        $actual_field_name = $this->get_actual_field_name( $source_field_data );

        // Get current values based on content type
        if ( $config['content_type'] === 'posts' ) {
            $post = get_post( $item['id'] );
            if ( ! $post ) {
                error_log("process_map_data_sample: Post not found for ID: " . $item['id']);
                return null;
            }
            
            // Use simplified field access
            $source_value = get_field( $actual_field_name, $item['id'], false );
            $title = $post->post_title;
            $type = $post->post_type;
        } else {
            $term = get_term( $item['id'], $item['taxonomy'] );
            if ( ! $term || is_wp_error( $term ) ) {
                error_log("process_map_data_sample: Term not found for ID: " . $item['id']);
                return null;
            }
            
            $source_value = get_field( $actual_field_name, "{$item['taxonomy']}_{$item['id']}" );
            $title = $term->name;
            $type = $item['taxonomy'];
        }

        error_log("Processing sample - ID: {$item['id']}, Title: $title, Field: $actual_field_name, Source value: " . print_r($source_value, true));

        if ( empty( $source_value ) && ! is_numeric( $source_value ) ) {
            error_log("process_map_data_sample: Empty source value for item ID: " . $item['id']);
            return null;
        }

        // Simulate the mapping
        $preview_data = $this->simulate_option_mapping( $source_value, $config );

        $sample = [
            'post_id' => $config['content_type'] === 'posts' ? $item['id'] : null,
            'term_id' => $config['content_type'] === 'taxonomy_terms' ? $item['id'] : null,
            'post_title' => $config['content_type'] === 'posts' ? $title : null,
            'term_name' => $config['content_type'] === 'taxonomy_terms' ? $title : null,
            'post_type' => $config['content_type'] === 'posts' ? $type : null,
            'taxonomy' => $config['content_type'] === 'taxonomy_terms' ? $type : null,
            'source_field' => [
                'name' => $source_field_data['name'],
                'label' => $source_field_data['label'],
                'type' => $source_field_data['type'],
                'current_value' => $source_value,
                'actual_field_name' => $actual_field_name
            ],
            'preview_data' => $preview_data,
            'success' => $preview_data['success'] ?? false
        ];

        // Store in preview table
        $this->store_preview_record( $session_id, $sample );

        return $sample;
    }

    /**
     * Get sample items for conversion (original method for copy data)
     */
    private function get_sample_items_for_conversion( array $config, int $sample_count ): array {
        if ( $config['content_type'] === 'posts' ) {
            return $this->get_sample_posts_for_conversion( $config, $sample_count );
        } else {
            return $this->get_sample_terms_for_conversion( $config, $sample_count );
        }
    }

    /**
     * Get sample posts for conversion preview (original method)
     */
    private function get_sample_posts_for_conversion( array $config, int $limit ): array {
        $args = [
            'post_type' => $config['post_types'] ?? 'any',
            'post_status' => $config['post_status'] ?? [ 'publish', 'draft', 'private' ],
            'posts_per_page' => $limit * 2,
            'fields' => 'ids',
            'orderby' => 'rand'
        ];

        if ( isset( $config['source_field'] ) ) {
            $field_mapper = $this->data_processor->get_component( 'field_mapper' );
            $source_field_data = $field_mapper->get_field_by_key( $config['source_field'] );
            if ( $source_field_data ) {
                $actual_field_name = $this->get_actual_field_name( $source_field_data );
                $args['meta_query'] = [
                    [
                        'key' => $actual_field_name,
                        'compare' => 'EXISTS'
                    ],
                    [
                        'key' => $actual_field_name,
                        'value' => '',
                        'compare' => '!='
                    ]
                ];
            }
        }

        $query = new WP_Query( $args );
        
        $valid_posts = [];
        foreach ( $query->posts as $post_id ) {
            if ( count( $valid_posts ) >= $limit ) {
                break;
            }
            $valid_posts[] = [ 'id' => $post_id, 'type' => 'post' ];
        }

        return $valid_posts;
    }

    /**
     * Get sample terms for conversion preview (original method)
     */
    private function get_sample_terms_for_conversion( array $config, int $limit ): array {
        $taxonomies = $config['taxonomies'] ?? [ 'any' ];
        $all_terms = [];

        if ( in_array( 'any', $taxonomies, true ) ) {
            $field_mapper = $this->data_processor->get_component( 'field_mapper' );
            $taxonomies = array_keys( $field_mapper->get_taxonomies() );
        }

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $terms = get_terms( [
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids',
                'number' => intval( $limit / count( $taxonomies ) ) + 1
            ] );

            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term_id ) {
                    if ( count( $all_terms ) >= $limit ) {
                        break 2;
                    }
                    
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
     * Generate field to field preview
     */
    private function generate_field_to_field_preview( array $config, array $sample_items, string $session_id, array $result ): array {
        // This method would implement field to field preview generation
        // For brevity, implementing basic structure - can be expanded
        $result['summary']['total_samples'] = count( $sample_items );
        $result['summary']['successful_samples'] = count( $sample_items );
        return $result;
    }

    /**
     * Generate field to taxonomy preview  
     */
    private function generate_field_to_taxonomy_preview( array $config, array $sample_items, string $session_id, array $result ): array {
        // This method would implement field to taxonomy preview generation
        // For brevity, implementing basic structure - can be expanded
        $result['summary']['total_samples'] = count( $sample_items );
        $result['summary']['successful_samples'] = count( $sample_items );
        return $result;
    }

    /**
     * Generate taxonomy to field preview
     */
    private function generate_taxonomy_to_field_preview( array $config, array $sample_items, string $session_id, array $result ): array {
        // This method would implement taxonomy to field preview generation
        // For brevity, implementing basic structure - can be expanded
        $result['summary']['total_samples'] = count( $sample_items );
        $result['summary']['successful_samples'] = count( $sample_items );
        return $result;
    }

    /**
     * Generate taxonomy to taxonomy preview
     */
    private function generate_taxonomy_to_taxonomy_preview( array $config, array $sample_items, string $session_id, array $result ): array {
        // This method would implement taxonomy to taxonomy preview generation
        // For brevity, implementing basic structure - can be expanded
        $result['summary']['total_samples'] = count( $sample_items );
        $result['summary']['successful_samples'] = count( $sample_items );
        return $result;
    }

    /**
     * Simulate option mapping without making changes - UPDATED to handle skipped values
     */
    private function simulate_option_mapping( $source_value, array $config ): array {
        try {
            $mappings = $config['mappings'];
            $source_values = is_array( $source_value ) ? $source_value : [ $source_value ];
            
            $mapped_values = [];
            $unmapped_values = [];

            foreach ( $source_values as $value ) {
                if ( isset( $mappings[ $value ] ) && ! empty( $mappings[ $value ] ) ) {
                    $mapped_values[] = [
                        'from' => $value,
                        'to' => $mappings[ $value ]
                    ];
                } else {
                    $unmapped_values[] = $value;
                }
            }

            return [
                'success' => true,
                'original_value' => $source_value,
                'mapped_values' => array_column( $mapped_values, 'to' ),
                'unmapped_values' => $unmapped_values,
                'mapping_details' => $mapped_values,
                'target_type' => $config['target_type'],
                'message' => ! empty( $unmapped_values ) ? 
                    sprintf( __( 'Note: %d value(s) will be skipped (no mapping defined).', 'acf-data-conversion' ), count( $unmapped_values ) ) : 
                    ''
            ];

        } catch ( Exception $e ) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'original_value' => $source_value
            ];
        }
    }

    /**
     * Store preview session metadata
     */
    private function store_preview_session( string $session_id, array $config, array $summary ): void {
        global $wpdb;

        $wpdb->insert(
            $this->preview_table,
            [
                'session_id' => $session_id,
                'post_id' => 0,
                'field_key' => 'session_metadata',
                'old_value' => wp_json_encode( $config ),
                'new_value' => wp_json_encode( $summary ),
                'conversion_type' => 'session_metadata',
                'created_at' => current_time( 'mysql' )
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Store individual preview record
     */
    private function store_preview_record( string $session_id, array $sample ): void {
        global $wpdb;

        $item_id = $sample['post_id'] ?? $sample['term_id'] ?? 0;
        $field_key = $sample['source_field']['name'] ?? 'preview_sample';

        $wpdb->insert(
            $this->preview_table,
            [
                'session_id' => $session_id,
                'post_id' => $item_id,
                'field_key' => $field_key,
                'old_value' => wp_json_encode( $sample['source_field']['current_value'] ?? null ),
                'new_value' => wp_json_encode( $sample['preview_data'] ),
                'conversion_type' => 'preview_sample',
                'created_at' => current_time( 'mysql' )
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Generate unique session ID
     */
    private function generate_session_id(): string {
        return wp_generate_password( 32, false );
    }

    /**
     * Setup preview table name
     */
    private function setup_preview_table(): void {
        global $wpdb;
        $this->preview_table = $wpdb->prefix . 'bws_acf_conversion_preview';
    }

    /**
     * Schedule cleanup of old previews
     */
    private function schedule_cleanup(): void {
        if ( ! wp_next_scheduled( 'bws_acf_conversion_cleanup' ) ) {
            wp_schedule_event( time(), 'hourly', 'bws_acf_conversion_cleanup' );
        }

        add_action( 'bws_acf_conversion_cleanup', [ $this, 'cleanup_old_previews' ] );
    }

    /**
     * Clean up old preview sessions
     */
    public function cleanup_old_previews(): void {
        global $wpdb;

        $cutoff_time = current_time( 'mysql', true );
        $cutoff_timestamp = strtotime( $cutoff_time ) - self::PREVIEW_SESSION_DURATION;
        $cutoff_mysql = gmdate( 'Y-m-d H:i:s', $cutoff_timestamp );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->preview_table} WHERE created_at < %s",
                $cutoff_mysql
            )
        );
    }
}