<?php
/**
 * Term Migrator Interface
 *
 * Defines the contract for term migration operations.
 * This is a plugin-agnostic library for migrating terms between taxonomies.
 *
 * @package BWS_Meta_Manager
 * @subpackage Libraries
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for term migration operations
 *
 * Implementations of this interface should provide term-to-term migration
 * functionality without depending on any specific plugin architecture.
 */
interface BWS_Term_Migrator_Interface {

    /**
     * Migrate terms from one taxonomy to another
     *
     * @param string $source_taxonomy Source taxonomy slug
     * @param string $target_taxonomy Target taxonomy slug
     * @param array  $options {
     *     Optional. Migration options.
     *
     *     @type bool   $preserve_hierarchy Whether to preserve term hierarchy. Default true.
     *     @type string $conflict_strategy  How to handle existing terms: 'skip', 'overwrite', 'rename'. Default 'skip'.
     *     @type array  $term_ids          Specific term IDs to migrate. If empty, migrates all. Default empty.
     *     @type bool   $copy_description  Whether to copy term descriptions. Default true.
     *     @type bool   $copy_meta         Whether to copy term meta. Default false.
     * }
     * @return array {
     *     Migration result.
     *
     *     @type bool  $success       Whether migration was successful
     *     @type int   $migrated      Number of terms successfully migrated
     *     @type int   $skipped       Number of terms skipped
     *     @type int   $failed        Number of terms that failed
     *     @type array $term_map      Map of source term ID => target term ID
     *     @type array $errors        Array of error messages
     * }
     */
    public function migrate_terms( string $source_taxonomy, string $target_taxonomy, array $options = [] ): array;

    /**
     * Copy a single term to another taxonomy
     *
     * @param int    $term_id         Source term ID
     * @param string $source_taxonomy Source taxonomy slug
     * @param string $target_taxonomy Target taxonomy slug
     * @param array  $options         Copy options (same as migrate_terms)
     * @return array {
     *     Copy result.
     *
     *     @type bool     $success        Whether copy was successful
     *     @type int|null $target_term_id Target term ID if successful
     *     @type string   $error          Error message if failed
     * }
     */
    public function copy_term_to_taxonomy( int $term_id, string $source_taxonomy, string $target_taxonomy, array $options = [] ): array;

    /**
     * Get term hierarchy for a taxonomy
     *
     * @param string $taxonomy Taxonomy slug
     * @param array  $term_ids Optional. Specific term IDs. If empty, gets all terms.
     * @return array Hierarchical array of terms with 'children' key
     */
    public function get_term_hierarchy( string $taxonomy, array $term_ids = [] ): array;

    /**
     * Preserve hierarchy when migrating terms
     *
     * @param array  $terms           Array of term data with parent relationships
     * @param string $target_taxonomy Target taxonomy slug
     * @param array  $term_map        Map of source term ID => target term ID
     * @return array {
     *     Hierarchy preservation result.
     *
     *     @type bool  $success Whether hierarchy was preserved
     *     @type int   $updated Number of parent relationships updated
     *     @type array $errors  Array of error messages
     * }
     */
    public function preserve_hierarchy( array $terms, string $target_taxonomy, array $term_map ): array;

    /**
     * Check if a term name exists in a taxonomy
     *
     * @param string $term_name Term name to check
     * @param string $taxonomy  Taxonomy slug
     * @return int|false Term ID if exists, false otherwise
     */
    public function term_name_exists( string $term_name, string $taxonomy );

    /**
     * Generate unique slug for a term
     *
     * @param string $slug     Desired slug
     * @param string $taxonomy Taxonomy slug
     * @return string Unique slug
     */
    public function generate_unique_slug( string $slug, string $taxonomy ): string;
}
