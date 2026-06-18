<?php
/**
 * Term Migrator Class
 *
 * Core term migration logic for copying/migrating terms between taxonomies.
 * This is a plugin-agnostic library with zero dependencies on BWS Meta Manager
 * or any specific plugin architecture.
 *
 * @package BWS_Meta_Manager
 * @subpackage Libraries
 * @since 0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Term Migrator implementation
 *
 * Provides core functionality for migrating terms between taxonomies with support for:
 * - Hierarchy preservation
 * - Conflict resolution (skip, overwrite, rename)
 * - Term meta copying
 * - Description copying
 * - Slug management
 */
class BWS_Term_Migrator implements BWS_Term_Migrator_Interface {

    /**
     * Default migration options
     *
     * @var array
     */
    private $default_options = [
        'preserve_hierarchy' => true,
        'conflict_strategy'  => 'skip',     // 'skip', 'overwrite', 'rename'
        'term_ids'           => [],
        'copy_description'   => true,
        'copy_meta'          => false,
    ];

    /**
     * Migrate terms from one taxonomy to another
     *
     * @param string $source_taxonomy Source taxonomy slug
     * @param string $target_taxonomy Target taxonomy slug
     * @param array  $options         Migration options
     * @return array Migration result
     */
    public function migrate_terms( string $source_taxonomy, string $target_taxonomy, array $options = [] ): array {
        // Merge with defaults
        $options = wp_parse_args( $options, $this->default_options );

        // Validate taxonomies
        if ( ! taxonomy_exists( $source_taxonomy ) || ! taxonomy_exists( $target_taxonomy ) ) {
            return [
                'success'  => false,
                'migrated' => 0,
                'skipped'  => 0,
                'failed'   => 0,
                'term_map' => [],
                'errors'   => [ __( 'One or both taxonomies do not exist.', 'bws-meta-manager' ) ],
            ];
        }

        // Get terms to migrate
        $term_args = [
            'taxonomy'   => $source_taxonomy,
            'hide_empty' => false,
        ];

        if ( ! empty( $options['term_ids'] ) ) {
            $term_args['include'] = $options['term_ids'];
        }

        $source_terms = get_terms( $term_args );

        if ( is_wp_error( $source_terms ) ) {
            return [
                'success'  => false,
                'migrated' => 0,
                'skipped'  => 0,
                'failed'   => 0,
                'term_map' => [],
                'errors'   => [ $source_terms->get_error_message() ],
            ];
        }

        if ( empty( $source_terms ) ) {
            return [
                'success'  => true,
                'migrated' => 0,
                'skipped'  => 0,
                'failed'   => 0,
                'term_map' => [],
                'errors'   => [ __( 'No terms found to migrate.', 'bws-meta-manager' ) ],
            ];
        }

        // Initialize result tracking
        $result = [
            'success'  => true,
            'migrated' => 0,
            'skipped'  => 0,
            'failed'   => 0,
            'term_map' => [],
            'errors'   => [],
        ];

        // First pass: Migrate all terms (without hierarchy)
        foreach ( $source_terms as $source_term ) {
            $copy_result = $this->copy_term_to_taxonomy(
                $source_term->term_id,
                $source_taxonomy,
                $target_taxonomy,
                $options
            );

            if ( $copy_result['success'] ) {
                if ( isset( $copy_result['target_term_id'] ) ) {
                    $result['migrated']++;
                    $result['term_map'][ $source_term->term_id ] = $copy_result['target_term_id'];
                } else {
                    $result['skipped']++;
                }
            } else {
                $result['failed']++;
                $result['errors'][] = sprintf(
                    __( 'Failed to migrate term "%s": %s', 'bws-meta-manager' ),
                    $source_term->name,
                    $copy_result['error'] ?? __( 'Unknown error', 'bws-meta-manager' )
                );
            }
        }

        // Second pass: Preserve hierarchy if requested
        if ( $options['preserve_hierarchy'] && ! empty( $result['term_map'] ) ) {
            $hierarchy_result = $this->preserve_hierarchy(
                $source_terms,
                $target_taxonomy,
                $result['term_map']
            );

            if ( ! $hierarchy_result['success'] ) {
                $result['errors'] = array_merge( $result['errors'], $hierarchy_result['errors'] );
            }
        }

        // Overall success if no failures
        $result['success'] = $result['failed'] === 0;

        return $result;
    }

    /**
     * Copy a single term to another taxonomy
     *
     * @param int    $term_id         Source term ID
     * @param string $source_taxonomy Source taxonomy slug
     * @param string $target_taxonomy Target taxonomy slug
     * @param array  $options         Copy options
     * @return array Copy result
     */
    public function copy_term_to_taxonomy( int $term_id, string $source_taxonomy, string $target_taxonomy, array $options = [] ): array {
        // Merge with defaults
        $options = wp_parse_args( $options, $this->default_options );

        // Get source term
        $source_term = get_term( $term_id, $source_taxonomy );

        if ( is_wp_error( $source_term ) || ! $source_term ) {
            return [
                'success'        => false,
                'target_term_id' => null,
                'error'          => __( 'Source term not found.', 'bws-meta-manager' ),
            ];
        }

        // Check if term already exists in target taxonomy
        $existing_term_id = $this->term_name_exists( $source_term->name, $target_taxonomy );

        if ( $existing_term_id ) {
            // Handle conflict based on strategy
            switch ( $options['conflict_strategy'] ) {
                case 'skip':
                    return [
                        'success'        => true,
                        'target_term_id' => $existing_term_id,
                        'skipped'        => true,
                    ];

                case 'overwrite':
                    // Update existing term
                    $update_args = [];
                    if ( $options['copy_description'] ) {
                        $update_args['description'] = $source_term->description;
                    }

                    if ( ! empty( $update_args ) ) {
                        $update_result = wp_update_term( $existing_term_id, $target_taxonomy, $update_args );
                        if ( is_wp_error( $update_result ) ) {
                            return [
                                'success'        => false,
                                'target_term_id' => null,
                                'error'          => $update_result->get_error_message(),
                            ];
                        }
                    }

                    return [
                        'success'        => true,
                        'target_term_id' => $existing_term_id,
                        'updated'        => true,
                    ];

                case 'rename':
                    // Generate unique name and slug
                    $new_name = $this->generate_unique_name( $source_term->name, $target_taxonomy );
                    $new_slug = $this->generate_unique_slug( $source_term->slug, $target_taxonomy );
                    break;

                default:
                    return [
                        'success'        => false,
                        'target_term_id' => null,
                        'error'          => __( 'Invalid conflict strategy.', 'bws-meta-manager' ),
                    ];
            }
        } else {
            // Use original name and slug if no conflict
            $new_name = $source_term->name;
            $new_slug = $source_term->slug;
        }

        // Create new term in target taxonomy
        $insert_args = [
            'slug' => $new_slug,
        ];

        if ( $options['copy_description'] ) {
            $insert_args['description'] = $source_term->description;
        }

        // Note: parent is set in second pass (preserve_hierarchy)

        $new_term = wp_insert_term( $new_name, $target_taxonomy, $insert_args );

        if ( is_wp_error( $new_term ) ) {
            return [
                'success'        => false,
                'target_term_id' => null,
                'error'          => $new_term->get_error_message(),
            ];
        }

        $target_term_id = $new_term['term_id'];

        // Copy term meta if requested
        if ( $options['copy_meta'] ) {
            $this->copy_term_meta( $term_id, $target_term_id );
        }

        return [
            'success'        => true,
            'target_term_id' => $target_term_id,
            'created'        => true,
        ];
    }

    /**
     * Get term hierarchy for a taxonomy
     *
     * @param string $taxonomy Taxonomy slug
     * @param array  $term_ids Optional. Specific term IDs.
     * @return array Hierarchical array of terms
     */
    public function get_term_hierarchy( string $taxonomy, array $term_ids = [] ): array {
        $term_args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ];

        if ( ! empty( $term_ids ) ) {
            $term_args['include'] = $term_ids;
        }

        $terms = get_terms( $term_args );

        if ( is_wp_error( $terms ) ) {
            return [];
        }

        return $this->build_term_tree( $terms );
    }

    /**
     * Build hierarchical tree from flat term array
     *
     * @param array $terms  Flat array of term objects
     * @param int   $parent Parent term ID (0 for root)
     * @return array Hierarchical array
     */
    private function build_term_tree( array $terms, int $parent = 0 ): array {
        $tree = [];

        foreach ( $terms as $term ) {
            if ( $term->parent == $parent ) {
                $term->children = $this->build_term_tree( $terms, $term->term_id );
                $tree[] = $term;
            }
        }

        return $tree;
    }

    /**
     * Preserve hierarchy when migrating terms
     *
     * @param array  $terms           Source terms with parent info
     * @param string $target_taxonomy Target taxonomy slug
     * @param array  $term_map        Map of source term ID => target term ID
     * @return array Hierarchy preservation result
     */
    public function preserve_hierarchy( array $terms, string $target_taxonomy, array $term_map ): array {
        $result = [
            'success' => true,
            'updated' => 0,
            'errors'  => [],
        ];

        foreach ( $terms as $source_term ) {
            // Skip if term wasn't migrated
            if ( ! isset( $term_map[ $source_term->term_id ] ) ) {
                continue;
            }

            // Skip if term has no parent
            if ( empty( $source_term->parent ) ) {
                continue;
            }

            // Check if parent was also migrated
            if ( ! isset( $term_map[ $source_term->parent ] ) ) {
                $result['errors'][] = sprintf(
                    __( 'Parent term not migrated for "%s"', 'bws-meta-manager' ),
                    $source_term->name
                );
                continue;
            }

            $target_term_id   = $term_map[ $source_term->term_id ];
            $target_parent_id = $term_map[ $source_term->parent ];

            // Update parent in target taxonomy
            $update_result = wp_update_term( $target_term_id, $target_taxonomy, [
                'parent' => $target_parent_id,
            ] );

            if ( is_wp_error( $update_result ) ) {
                $result['success'] = false;
                $result['errors'][] = sprintf(
                    __( 'Failed to set parent for "%s": %s', 'bws-meta-manager' ),
                    $source_term->name,
                    $update_result->get_error_message()
                );
            } else {
                $result['updated']++;
            }
        }

        return $result;
    }

    /**
     * Check if a term name exists in a taxonomy
     *
     * @param string $term_name Term name to check
     * @param string $taxonomy  Taxonomy slug
     * @return int|false Term ID if exists, false otherwise
     */
    public function term_name_exists( string $term_name, string $taxonomy ) {
        $existing_term = get_term_by( 'name', $term_name, $taxonomy );

        return $existing_term ? $existing_term->term_id : false;
    }

    /**
     * Generate unique slug for a term
     *
     * @param string $slug     Desired slug
     * @param string $taxonomy Taxonomy slug
     * @return string Unique slug
     */
    public function generate_unique_slug( string $slug, string $taxonomy ): string {
        $original_slug = $slug;
        $counter       = 1;

        while ( term_exists( $slug, $taxonomy ) ) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Generate unique name for a term
     *
     * @param string $name     Desired name
     * @param string $taxonomy Taxonomy slug
     * @return string Unique name
     */
    private function generate_unique_name( string $name, string $taxonomy ): string {
        $original_name = $name;
        $counter       = 1;

        while ( $this->term_name_exists( $name, $taxonomy ) ) {
            $name = $original_name . ' (' . $counter . ')';
            $counter++;
        }

        return $name;
    }

    /**
     * Copy term meta from source to target term
     *
     * @param int $source_term_id Source term ID
     * @param int $target_term_id Target term ID
     * @return void
     */
    private function copy_term_meta( int $source_term_id, int $target_term_id ): void {
        $meta_keys = get_term_meta( $source_term_id );

        if ( empty( $meta_keys ) ) {
            return;
        }

        foreach ( $meta_keys as $meta_key => $meta_values ) {
            // Skip internal meta keys
            if ( strpos( $meta_key, '_' ) === 0 ) {
                continue;
            }

            foreach ( $meta_values as $meta_value ) {
                add_term_meta( $target_term_id, $meta_key, maybe_unserialize( $meta_value ) );
            }
        }
    }
}
