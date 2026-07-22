<?php
/**
 * BWS Field Mapper Class
 *
 * Handles discovery and mapping of ACF fields, field options, and taxonomies.
 * Provides caching and validation for field compatibility.
 *
 * @package BWS_Meta_Manager
 * @subpackage Conversion
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Conversion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FieldMapper {

    /**
     * Cache duration for field data (1 hour)
     */
    private const CACHE_DURATION = HOUR_IN_SECONDS;

    /**
     * Supported field types for conversion
     */
    private const SUPPORTED_FIELD_TYPES = [
        'text',
        'textarea', 
        'number',
        'email',
        'url',
        'password',
        'select',
        'checkbox',
        'radio',
        'button_group',
        'true_false',
        'wysiwyg',
        'oembed',
        'image',
        'gallery',
        'file',
        'post_object',
        'page_link',
        'relationship',
        'taxonomy',
        'user',
        'google_map',
        'date_picker',
        'date_time_picker',
        'time_picker',
        'color_picker',
        'message',
        'accordion',
        'tab',
        'group',
        'repeater',
        'flexible_content',
        'clone'
    ];

    /**
     * Field types that support options
     */
    private const OPTION_FIELD_TYPES = [
        'select',
        'checkbox', 
        'radio',
        'button_group'
    ];

    /**
     * Field types that can be mapped to taxonomies
     */
    private const TAXONOMY_MAPPABLE_TYPES = [
        'text',
        'textarea',
        'select',
        'checkbox',
        'radio',
        'button_group',
        'post_object',
        'relationship',
        'taxonomy'
    ];

    /**
     * Get all ACF field groups
     *
     * @param bool $force_refresh Force refresh cache
     * @return array
     */
    public function get_field_groups( bool $force_refresh = false ): array {
        $cache_key = 'bws_meta_manager_conversion_field_groups';
        
        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $field_groups = [];

        if ( function_exists( 'acf_get_field_groups' ) ) {
            $groups = acf_get_field_groups();
            
            foreach ( $groups as $group ) {
                $field_groups[ $group['key'] ] = [
                    'key' => $group['key'],
                    'title' => $group['title'],
                    'location' => $group['location'] ?? [],
                    'active' => $group['active'] ?? true,
                    'fields' => $this->get_fields_for_group( $group['key'] ),
                    'context' => $this->determine_group_context( $group['location'] ?? [] )
                ];
            }
        }

        set_transient( $cache_key, $field_groups, self::CACHE_DURATION );
        return $field_groups;
    }

    /**
     * Get fields by context (posts, taxonomy terms, etc.)
     *
     * @param string $content_type Content type ('posts', 'taxonomy_terms')
     * @param array $post_types Post types filter
     * @param array $taxonomies Taxonomies filter  
     * @param string $field_type_filter Field type filter
     * @param bool $force_refresh Force refresh cache
     * @return array
     */
    public function get_fields_by_context( 
        string $content_type, 
        array $post_types = [], 
        array $taxonomies = [], 
        string $field_type_filter = '',
        bool $force_refresh = false 
    ): array {
        error_log("get_fields_by_context called with: content_type=$content_type, field_type_filter=$field_type_filter");
        
        $field_groups = $this->get_field_groups( $force_refresh );
        error_log("Total field groups available: " . count($field_groups));
        
        $filtered_groups = [];

        foreach ( $field_groups as $group_key => $group ) {
            $include_group = false;

            // Filter by content type and location rules
            if ( $content_type === 'posts' ) {
                $include_group = $this->group_applies_to_posts( $group, $post_types );
            } elseif ( $content_type === 'taxonomy_terms' ) {
                $include_group = $this->group_applies_to_taxonomies( $group, $taxonomies );
            } else {
                // If no specific content type, include all groups
                $include_group = true;
            }

            error_log("Group '{$group['title']}' applies to $content_type: " . ($include_group ? 'YES' : 'NO'));

            if ( $include_group ) {
                $filtered_group = $group;
                
                // Ensure fields are present and convert to array
                if ( ! isset( $filtered_group['fields'] ) || empty( $filtered_group['fields'] ) ) {
                    error_log("Group '{$group['title']}' has no fields, skipping");
                    continue;
                }
                
                // Convert associative array to indexed array for JavaScript
                $fields_array = array_values( $filtered_group['fields'] );
                error_log("Group '{$group['title']}' original fields count: " . count($filtered_group['fields']));
                
                // Convert sub_fields in all fields to arrays as well
                foreach ( $fields_array as &$field ) {
                    if ( isset( $field['sub_fields'] ) && ! empty( $field['sub_fields'] ) ) {
                        // Check if sub_fields is associative array (has string keys)
                        $is_indexed = function_exists( 'array_is_list' ) ? 
                            array_is_list( $field['sub_fields'] ) : 
                            array_keys( $field['sub_fields'] ) === range( 0, count( $field['sub_fields'] ) - 1 );
                        
                        if ( ! $is_indexed ) {
                            $field['sub_fields'] = array_values( $field['sub_fields'] );
                        }
                    }
                }
                unset( $field ); // Break the reference
                
                // Filter fields by type if specified
                if ( $field_type_filter ) {
                    $fields_array = $this->filter_fields_array_by_type( $fields_array, $field_type_filter );
                    error_log("Group '{$group['title']}' after type filtering: " . count($fields_array));
                }
                
                // Update the group with the filtered array
                $filtered_group['fields'] = $fields_array;

                // Only include group if it has fields after filtering
                if ( ! empty( $filtered_group['fields'] ) ) {
                    $filtered_groups[] = $filtered_group;
                    error_log("Group '{$group['title']}' added to results with " . count($filtered_group['fields']) . " fields");
                } else {
                    error_log("Group '{$group['title']}' excluded - no fields after filtering");
                }
            }
        }

        error_log("Returning " . count($filtered_groups) . " filtered groups");
        return $filtered_groups;
    }

    /**
     * Determine if group applies to posts
     *
     * @param array $group Field group data
     * @param array $post_types Post types filter
     * @return bool
     */
    private function group_applies_to_posts( array $group, array $post_types ): bool {
        $location_rules = $group['location'] ?? [];
        
        // If no location rules, assume it applies to posts (some groups have no location rules)
        if ( empty( $location_rules ) ) {
            return true;
        }

        // If post_types includes 'any', be more permissive
        if ( in_array( 'any', $post_types, true ) ) {
            // Check if any rule group could apply to posts
            foreach ( $location_rules as $rule_group ) {
                foreach ( $rule_group as $rule ) {
                    $param = $rule['param'] ?? '';
                    
                    // If it's a post-related rule, include it
                    if ( in_array( $param, [ 'post_type', 'page', 'post', 'page_template', 'post_status', 'post_format' ], true ) ) {
                        return true;
                    }
                }
            }
            // If no specific post rules found, still include it (could be general)
            return true;
        }

        // Check if any location rule applies to the specified post types
        foreach ( $location_rules as $rule_group ) {
            $applies_to_posts = false;
            
            foreach ( $rule_group as $rule ) {
                $param = $rule['param'] ?? '';
                $operator = $rule['operator'] ?? '==';
                $value = $rule['value'] ?? '';
                
                // Check post type rules
                if ( $param === 'post_type' ) {
                    if ( ( $operator === '==' && in_array( $value, $post_types, true ) ) ||
                         ( $operator === '!=' && ! in_array( $value, $post_types, true ) ) ) {
                        $applies_to_posts = true;
                    }
                }
                
                // Check other post-related rules
                if ( in_array( $param, [ 'page', 'post', 'page_template', 'post_status', 'post_format' ], true ) ) {
                    $applies_to_posts = true;
                }
            }
            
            if ( $applies_to_posts ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if group applies to taxonomy terms
     *
     * @param array $group Field group data
     * @param array $taxonomies Taxonomies filter
     * @return bool
     */
    private function group_applies_to_taxonomies( array $group, array $taxonomies ): bool {
        $location_rules = $group['location'] ?? [];
        
        if ( empty( $location_rules ) ) {
            return false;
        }

        // Check if any location rule applies to taxonomy terms
        foreach ( $location_rules as $rule_group ) {
            foreach ( $rule_group as $rule ) {
                $param = $rule['param'] ?? '';
                $operator = $rule['operator'] ?? '==';
                $value = $rule['value'] ?? '';
                
                // Check taxonomy rules
                if ( $param === 'taxonomy' ) {
                    if ( in_array( 'any', $taxonomies, true ) || 
                         ( $operator === '==' && in_array( $value, $taxonomies, true ) ) ||
                         ( $operator === '!=' && ! in_array( $value, $taxonomies, true ) ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Filter fields array by type (for indexed arrays)
     *
     * @param array $fields_array Fields array (indexed, not associative)
     * @param string $type_filter Filter type
     * @return array
     */
    private function filter_fields_array_by_type( array $fields_array, string $type_filter ): array {
        $filtered_fields = [];

        foreach ( $fields_array as $field ) {
            $include_field = false;
            $field_has_matching_subfields = false;

            // Check if the field itself matches the filter
            switch ( $type_filter ) {
                case 'option_fields':
                    $include_field = $field['has_options'] ?? false;
                    break;
                    
                case 'taxonomy_mappable':
                    $include_field = $field['taxonomy_mappable'] ?? false;
                    break;
                    
                default:
                    $include_field = true;
            }

            // For complex fields (group, repeater, flexible_content), also check sub-fields
            if ( ! $include_field && in_array( $field['type'], [ 'group', 'repeater', 'flexible_content' ], true ) ) {
                if ( isset( $field['sub_fields'] ) && ! empty( $field['sub_fields'] ) ) {
                    // Check if any sub-fields match the filter
                    foreach ( $field['sub_fields'] as $sub_field ) {
                        $sub_field_matches = false;
                        
                        switch ( $type_filter ) {
                            case 'option_fields':
                                $sub_field_matches = $sub_field['has_options'] ?? false;
                                break;
                                
                            case 'taxonomy_mappable':
                                $sub_field_matches = $sub_field['taxonomy_mappable'] ?? false;
                                break;
                                
                            default:
                                $sub_field_matches = true;
                        }
                        
                        if ( $sub_field_matches ) {
                            $field_has_matching_subfields = true;
                            break;
                        }
                    }
                }
            }

            // Include the field if it matches directly OR has matching sub-fields
            if ( $include_field || $field_has_matching_subfields ) {
                $filtered_field = $field;
                
                // Filter and convert sub-fields if they exist
                if ( isset( $field['sub_fields'] ) && ! empty( $field['sub_fields'] ) ) {
                    // Check if sub_fields is associative array (has string keys)
                    $is_indexed = function_exists( 'array_is_list' ) ? 
                        array_is_list( $field['sub_fields'] ) : 
                        array_keys( $field['sub_fields'] ) === range( 0, count( $field['sub_fields'] ) - 1 );
                    
                    if ( ! $is_indexed ) {
                        $sub_fields_array = array_values( $field['sub_fields'] );
                    } else {
                        $sub_fields_array = $field['sub_fields'];
                    }
                    
                    // Apply the same filter to sub-fields
                    $filtered_field['sub_fields'] = $this->filter_fields_array_by_type( 
                        $sub_fields_array, 
                        $type_filter 
                    );
                    
                    error_log("Field '{$field['label']}' filtered sub-fields: " . count($filtered_field['sub_fields']));
                }

                $filtered_fields[] = $filtered_field;
                error_log("Included field '{$field['label']}' - direct match: " . ($include_field ? 'yes' : 'no') . ", has matching sub-fields: " . ($field_has_matching_subfields ? 'yes' : 'no'));
            } else {
                error_log("Excluded field '{$field['label']}' - no matches");
            }
        }

        return $filtered_fields;
    }

    /**
     * Filter fields by type
     *
     * @param array $fields Fields array
     * @param string $type_filter Filter type
     * @return array
     */
    private function filter_fields_by_type( array $fields, string $type_filter ): array {
        $filtered_fields = [];

        foreach ( $fields as $field_key => $field ) {
            $include_field = false;

            switch ( $type_filter ) {
                case 'option_fields':
                    $include_field = $field['has_options'] ?? false;
                    break;
                    
                case 'taxonomy_mappable':
                    $include_field = $field['taxonomy_mappable'] ?? false;
                    break;
                    
                default:
                    $include_field = true;
            }

            if ( $include_field ) {
                $filtered_field = $field;
                
                // Also filter sub-fields
                if ( isset( $field['sub_fields'] ) ) {
                    $filtered_field['sub_fields'] = $this->filter_fields_by_type( 
                        $field['sub_fields'], 
                        $type_filter 
                    );
                }

                $filtered_fields[ $field_key ] = $filtered_field;
            }
        }

        return $filtered_fields;
    }

    /**
     * Determine field group context from location rules
     *
     * @param array $location_rules Location rules
     * @return string
     */
    private function determine_group_context( array $location_rules ): string {
        $contexts = [];

        foreach ( $location_rules as $rule_group ) {
            foreach ( $rule_group as $rule ) {
                $param = $rule['param'] ?? '';
                
                if ( in_array( $param, [ 'post_type', 'page', 'post', 'page_template', 'post_status', 'post_format' ], true ) ) {
                    $contexts[] = 'posts';
                } elseif ( $param === 'taxonomy' ) {
                    $contexts[] = 'taxonomy_terms';
                } elseif ( in_array( $param, [ 'user_form', 'user_role' ], true ) ) {
                    $contexts[] = 'users';
                } elseif ( in_array( $param, [ 'options_page' ], true ) ) {
                    $contexts[] = 'options';
                }
            }
        }

        return ! empty( $contexts ) ? implode( ',', array_unique( $contexts ) ) : 'all';
    }

    /**
     * Get fields for a specific field group
     *
     * @param string $group_key Field group key
     * @return array
     */
    private function get_fields_for_group( string $group_key ): array {
        $fields = [];

        if ( function_exists( 'acf_get_fields' ) ) {
            $acf_fields = acf_get_fields( $group_key );
            
            if ( is_array( $acf_fields ) ) {
                foreach ( $acf_fields as $field ) {
                    $fields[ $field['key'] ] = $this->process_field_data( $field );
                }
            }
        }

        return $fields;
    }

    /**
     * Process and normalize field data
     *
     * @param array $field Raw ACF field data
     * @return array
     */
    private function process_field_data( array $field ): array {
        $processed = [
            'key' => $field['key'],
            'name' => $field['name'],
            'label' => $field['label'],
            'type' => $field['type'],
            'parent' => $field['parent'] ?? '',
            'required' => $field['required'] ?? false,
            'conditional_logic' => $field['conditional_logic'] ?? false,
            'wrapper' => $field['wrapper'] ?? [],
            'instructions' => $field['instructions'] ?? '',
            'supported' => in_array( $field['type'], self::SUPPORTED_FIELD_TYPES, true ),
            'has_options' => in_array( $field['type'], self::OPTION_FIELD_TYPES, true ),
            'taxonomy_mappable' => in_array( $field['type'], self::TAXONOMY_MAPPABLE_TYPES, true )
        ];

        // Extract options for fields that support them
        if ( $processed['has_options'] ) {
            $processed['options'] = $this->extract_field_options( $field );
            error_log("Field '{$field['label']}' ({$field['type']}) has " . count($processed['options']) . " options");
        }

        // Handle sub-fields for complex field types
        if ( in_array( $field['type'], [ 'group', 'repeater', 'flexible_content' ], true ) ) {
            $processed['sub_fields'] = $this->get_sub_fields( $field );
            error_log("Field '{$field['label']}' ({$field['type']}) has " . count($processed['sub_fields']) . " sub-fields");
        }

        // Add taxonomy information for taxonomy fields
        if ( 'taxonomy' === $field['type'] ) {
            $processed['taxonomy_info'] = [
                'taxonomy' => $field['taxonomy'] ?? '',
                'field_type' => $field['field_type'] ?? 'select',
                'allow_null' => $field['allow_null'] ?? false,
                'multiple' => $field['multiple'] ?? false
            ];
        }

        return $processed;
    }

    /**
     * Extract options from option-based fields
     *
     * @param array $field ACF field data
     * @return array
     */
    private function extract_field_options( array $field ): array {
        $options = [];

        if ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
            foreach ( $field['choices'] as $value => $label ) {
                $options[] = [
                    'value' => $value,
                    'label' => $label
                ];
            }
        }

        return $options;
    }

    /**
     * Get sub-fields for complex field types
     *
     * @param array $field Parent field data
     * @return array
     */
    private function get_sub_fields( array $field ): array {
        $sub_fields = [];

        if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
            foreach ( $field['sub_fields'] as $sub_field ) {
                $sub_fields[ $sub_field['key'] ] = $this->process_field_data( $sub_field );
            }
        }

        // Handle flexible content layouts
        if ( 'flexible_content' === $field['type'] && isset( $field['layouts'] ) ) {
            foreach ( $field['layouts'] as $layout ) {
                if ( isset( $layout['sub_fields'] ) ) {
                    foreach ( $layout['sub_fields'] as $sub_field ) {
                        $sub_fields[ $sub_field['key'] ] = $this->process_field_data( $sub_field );
                    }
                }
            }
        }

        return $sub_fields;
    }

    /**
     * Get all fields organized by type
     *
     * @param bool $force_refresh Force refresh cache
     * @return array
     */
    public function get_fields_by_type( bool $force_refresh = false ): array {
        $cache_key = 'bws_meta_manager_conversion_fields_by_type';
        
        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $fields_by_type = [];
        $field_groups = $this->get_field_groups( $force_refresh );

        foreach ( $field_groups as $group ) {
            foreach ( $group['fields'] as $field ) {
                $type = $field['type'];
                
                if ( ! isset( $fields_by_type[ $type ] ) ) {
                    $fields_by_type[ $type ] = [];
                }
                
                $fields_by_type[ $type ][] = $field;

                // Include sub-fields
                if ( isset( $field['sub_fields'] ) ) {
                    foreach ( $field['sub_fields'] as $sub_field ) {
                        $sub_type = $sub_field['type'];
                        
                        if ( ! isset( $fields_by_type[ $sub_type ] ) ) {
                            $fields_by_type[ $sub_type ] = [];
                        }
                        
                        $fields_by_type[ $sub_type ][] = $sub_field;
                    }
                }
            }
        }

        set_transient( $cache_key, $fields_by_type, self::CACHE_DURATION );
        return $fields_by_type;
    }

    /**
     * Get all available taxonomies
     *
     * @param bool $force_refresh Force refresh cache
     * @return array
     */
    public function get_taxonomies( bool $force_refresh = false ): array {
        $cache_key = 'bws_meta_manager_conversion_taxonomies';
        
        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $taxonomies = [];
        $wp_taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

        foreach ( $wp_taxonomies as $taxonomy ) {
            $term_count = wp_count_terms( [ 
                'taxonomy' => $taxonomy->name, 
                'hide_empty' => false 
            ] );
            
            $taxonomies[ $taxonomy->name ] = [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label,
                'labels' => $taxonomy->labels,
                'hierarchical' => $taxonomy->hierarchical,
                'public' => $taxonomy->public,
                'show_ui' => $taxonomy->show_ui,
                'show_admin_column' => $taxonomy->show_admin_column,
                'object_type' => $taxonomy->object_type,
                'terms_count' => is_wp_error( $term_count ) ? 0 : $term_count
            ];
        }

        set_transient( $cache_key, $taxonomies, self::CACHE_DURATION );
        return $taxonomies;
    }

    /**
     * Get terms for a specific taxonomy
     *
     * @param string $taxonomy Taxonomy name
     * @param array $args Additional arguments for get_terms
     * @return array
     */
    public function get_taxonomy_terms( string $taxonomy, array $args = [] ): array {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return [];
        }

        $default_args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'number' => 500 // Limit for performance
        ];

        $args = wp_parse_args( $args, $default_args );
        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            return [];
        }

        $formatted_terms = [];
        foreach ( $terms as $term ) {
            $formatted_terms[] = [
                'term_id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'parent' => $term->parent,
                'count' => $term->count
            ];
        }

        return $formatted_terms;
    }

    /**
     * Get fields that are attached to taxonomy terms
     *
     * @param string $taxonomy Taxonomy name
     * @param bool $force_refresh Force refresh cache
     * @return array
     */
    public function get_taxonomy_fields( string $taxonomy, bool $force_refresh = false ): array {
        $cache_key = "bws_meta_manager_conversion_taxonomy_fields_{$taxonomy}";
        
        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $taxonomy_fields = [];
        $field_groups = $this->get_field_groups( $force_refresh );

        foreach ( $field_groups as $group ) {
            // Check if this group applies to the taxonomy
            if ( $this->group_applies_to_taxonomies( $group, [ $taxonomy ] ) ) {
                $taxonomy_fields[ $group['key'] ] = $group;
            }
        }

        set_transient( $cache_key, $taxonomy_fields, self::CACHE_DURATION );
        return $taxonomy_fields;
    }

    /**
     * Get field value from taxonomy term
     *
     * @param string $field_name Field name
     * @param int $term_id Term ID
     * @param string $taxonomy Taxonomy name
     * @return mixed
     */
    public function get_taxonomy_term_field_value( string $field_name, int $term_id, string $taxonomy ) {
        if ( ! function_exists( 'get_field' ) ) {
            return null;
        }

        return get_field( $field_name, "{$taxonomy}_{$term_id}" );
    }

    /**
     * Update field value for taxonomy term
     *
     * @param string $field_name Field name
     * @param mixed $value Field value
     * @param int $term_id Term ID
     * @param string $taxonomy Taxonomy name
     * @return bool
     */
    public function update_taxonomy_term_field_value( string $field_name, $value, int $term_id, string $taxonomy ): bool {
        if ( ! function_exists( 'update_field' ) ) {
            return false;
        }

        return update_field( $field_name, $value, "{$taxonomy}_{$term_id}" );
    }

    /**
     * Get posts that have a specific field
     *
     * @param string $field_key Field key
     * @param array $args Additional query arguments
     * @return array
     */
    public function get_posts_with_field( string $field_key, array $args = [] ): array {
        $field = $this->get_field_by_key( $field_key );
        if ( ! $field ) {
            return [];
        }

        $default_args = [
            'post_type' => 'any',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => $field['name'],
                    'compare' => 'EXISTS'
                ]
            ],
            'fields' => 'ids'
        ];

        $query_args = wp_parse_args( $args, $default_args );
        $query = new \WP_Query( $query_args );

        return $query->posts;
    }

    /**
     * Get taxonomy terms that have a specific field
     *
     * @param string $field_key Field key
     * @param string $taxonomy Taxonomy name
     * @param array $args Additional arguments
     * @return array
     */
    public function get_terms_with_field( string $field_key, string $taxonomy, array $args = [] ): array {
        $field = $this->get_field_by_key( $field_key );
        if ( ! $field ) {
            return [];
        }

        global $wpdb;

        // Get all terms for taxonomy
        $terms = get_terms( array_merge( [
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids'
        ], $args ) );

        if ( is_wp_error( $terms ) ) {
            return [];
        }

        // Filter terms that have the field
        $terms_with_field = [];
        foreach ( $terms as $term_id ) {
            $meta_key = "{$taxonomy}_{$term_id}_{$field['name']}";
            $has_field = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                $meta_key
            ) );

            if ( $has_field ) {
                $terms_with_field[] = $term_id;
            }
        }

        return $terms_with_field;
    }

    /**
     * Validate field conversion compatibility
     *
     * @param string $source_field_key Source field key
     * @param string $target_field_key Target field key
     * @return array Validation result
     */
    public function validate_field_conversion( string $source_field_key, string $target_field_key ): array {
        $source_field = $this->get_field_by_key( $source_field_key );
        $target_field = $this->get_field_by_key( $target_field_key );

        $result = [
            'valid' => false,
            'warnings' => [],
            'errors' => []
        ];

        if ( ! $source_field ) {
            $result['errors'][] = __( 'Source field not found.', 'meta-conductor' );
            return $result;
        }

        if ( ! $target_field ) {
            $result['errors'][] = __( 'Target field not found.', 'meta-conductor' );
            return $result;
        }

        // Check if both fields are supported
        if ( ! $source_field['supported'] ) {
            $result['errors'][] = sprintf(
                /* translators: %s: field type */
                __( 'Source field type "%s" is not supported for conversion.', 'meta-conductor' ),
                $source_field['type']
            );
        }

        if ( ! $target_field['supported'] ) {
            $result['errors'][] = sprintf(
                /* translators: %s: field type */
                __( 'Target field type "%s" is not supported for conversion.', 'meta-conductor' ),
                $target_field['type']
            );
        }

        // Check for risky conversions
        $this->check_conversion_risks( $source_field, $target_field, $result );

        // If no errors, conversion is valid
        $result['valid'] = empty( $result['errors'] );

        return $result;
    }

    /**
     * Check for potential risks in field conversion
     *
     * @param array $source_field Source field data
     * @param array $target_field Target field data
     * @param array &$result Result array to modify
     */
    private function check_conversion_risks( array $source_field, array $target_field, array &$result ): void {
        // Complex to simple field type warnings
        $complex_types = [ 'repeater', 'flexible_content', 'group', 'gallery' ];
        $simple_types = [ 'text', 'textarea', 'number' ];

        if ( in_array( $source_field['type'], $complex_types, true ) && 
             in_array( $target_field['type'], $simple_types, true ) ) {
            $result['warnings'][] = __( 'Converting from complex field type to simple type may result in data loss.', 'meta-conductor' );
        }

        // Multiple values to single value
        $multiple_types = [ 'checkbox', 'gallery', 'repeater' ];
        $single_types = [ 'text', 'select', 'radio' ];

        if ( in_array( $source_field['type'], $multiple_types, true ) && 
             in_array( $target_field['type'], $single_types, true ) ) {
            $result['warnings'][] = __( 'Converting from multiple-value field to single-value field may result in data loss.', 'meta-conductor' );
        }

        // Numeric to text conversions
        if ( 'number' === $source_field['type'] && 'text' !== $target_field['type'] ) {
            $result['warnings'][] = __( 'Converting from number field may require validation in target field.', 'meta-conductor' );
        }

        // Option field compatibility
        if ( $source_field['has_options'] && $target_field['has_options'] ) {
            $source_options = array_column( $source_field['options'] ?? [], 'value' );
            $target_options = array_column( $target_field['options'] ?? [], 'value' );
            
            $missing_options = array_diff( $source_options, $target_options );
            if ( ! empty( $missing_options ) ) {
                $result['warnings'][] = sprintf(
                    /* translators: %s: list of missing options */
                    __( 'Target field is missing these options: %s', 'meta-conductor' ),
                    implode( ', ', $missing_options )
                );
            }
        }
    }

    /**
     * Get field data by field key
     *
     * @param string $field_key Field key
     * @return array|null
     */
    public function get_field_by_key( string $field_key ): ?array {
        $field_groups = $this->get_field_groups();

        foreach ( $field_groups as $group ) {
            if ( isset( $group['fields'][ $field_key ] ) ) {
                return $group['fields'][ $field_key ];
            }

            // Check sub-fields
            foreach ( $group['fields'] as $field ) {
                if ( isset( $field['sub_fields'][ $field_key ] ) ) {
                    return $field['sub_fields'][ $field_key ];
                }
            }
        }

        return null;
    }

    /**
     * Create or update taxonomy term
     *
     * @param string $taxonomy Taxonomy name
     * @param string $term_name Term name
     * @param array $args Additional term arguments
     * @return array|WP_Error
     */
    public function create_taxonomy_term( string $taxonomy, string $term_name, array $args = [] ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new \WP_Error( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'meta-conductor' ) );
        }

        // Check if term already exists
        $existing_term = get_term_by( 'name', $term_name, $taxonomy );
        if ( $existing_term ) {
            return [
                'term_id' => $existing_term->term_id,
                'existing' => true
            ];
        }

        // Create new term
        $result = wp_insert_term( $term_name, $taxonomy, $args );
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [
            'term_id' => $result['term_id'],
            'existing' => false
        ];
    }

    /**
     * Clear all field mapper caches
     */
    public function clear_cache(): void {
        delete_transient( 'bws_meta_manager_conversion_field_groups' );
        delete_transient( 'bws_meta_manager_conversion_fields_by_type' );
        delete_transient( 'bws_meta_manager_conversion_taxonomies' );
        
        // Clear taxonomy-specific caches
        $taxonomies = get_taxonomies();
        foreach ( $taxonomies as $taxonomy ) {
            delete_transient( "bws_meta_manager_conversion_taxonomy_fields_{$taxonomy}" );
        }
        
        // Clear any object cache if available
        if ( function_exists( 'wp_cache_delete_group' ) ) {
            wp_cache_delete_group( 'bws_meta_manager_conversion' );
        }
    }

    /**
     * Get supported field types
     *
     * @return array
     */
    public function get_supported_field_types(): array {
        return self::SUPPORTED_FIELD_TYPES;
    }

    /**
     * Get option-supporting field types
     *
     * @return array
     */
    public function get_option_field_types(): array {
        return self::OPTION_FIELD_TYPES;
    }

    /**
     * Get taxonomy-mappable field types
     *
     * @return array
     */
    public function get_taxonomy_mappable_types(): array {
        return self::TAXONOMY_MAPPABLE_TYPES;
    }

    /**
     * Validate taxonomy mapping
     *
     * @param string $field_key Field key
     * @param string $taxonomy Taxonomy name
     * @return array
     */
    public function validate_taxonomy_mapping( string $field_key, string $taxonomy ): array {
        $result = [
            'valid' => false,
            'errors' => [],
            'warnings' => []
        ];

        $field = $this->get_field_by_key( $field_key );
        if ( ! $field ) {
            $result['errors'][] = __( 'Field not found.', 'meta-conductor' );
            return $result;
        }

        if ( ! taxonomy_exists( $taxonomy ) ) {
            $result['errors'][] = __( 'Taxonomy does not exist.', 'meta-conductor' );
            return $result;
        }

        if ( ! $field['taxonomy_mappable'] ) {
            $result['errors'][] = sprintf(
                /* translators: %s: field type */
                __( 'Field type "%s" cannot be mapped to taxonomy.', 'meta-conductor' ),
                $field['type']
            );
            return $result;
        }

        // Check if field has many values and taxonomy is non-hierarchical
        $taxonomy_obj = get_taxonomy( $taxonomy );
        $multiple_value_types = [ 'checkbox', 'repeater', 'gallery' ];
        
        if ( in_array( $field['type'], $multiple_value_types, true ) && ! $taxonomy_obj->hierarchical ) {
            $result['warnings'][] = __( 'Mapping multiple values to non-hierarchical taxonomy may create many terms.', 'meta-conductor' );
        }

        $result['valid'] = empty( $result['errors'] );
        return $result;
    }

    /**
     * Get field groups that apply to specific content
     *
     * @param string $content_type Content type
     * @param array $content_ids Content IDs
     * @return array
     */
    public function get_applicable_field_groups( string $content_type, array $content_ids ): array {
        $field_groups = $this->get_field_groups();
        $applicable_groups = [];

        foreach ( $field_groups as $group ) {
            if ( strpos( $group['context'], $content_type ) !== false ) {
                $applicable_groups[] = $group;
            }
        }

        return $applicable_groups;
    }
}