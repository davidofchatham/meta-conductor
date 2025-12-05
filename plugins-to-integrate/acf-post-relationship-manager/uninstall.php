<?php
/**
 * Uninstall script for ACF Post Relationship Manager
 * 
 * This file is executed when the plugin is deleted from the WordPress admin.
 * 
 * @package ACF_Post_Relationship_Manager
 * @version 1.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up plugin data on uninstall
 * 
 * Note: This will NOT remove the actual post parent/child relationships
 * that were created, as those are part of WordPress core data structure.
 * Only plugin-specific data is removed.
 */
if (!function_exists('bws_acf_relationship_uninstall_cleanup')) {
    function bws_acf_relationship_uninstall_cleanup() {
        // Remove any transients
        delete_transient('bws_acf_relationship_activated');
        
        // Remove any options if we had stored any
        // delete_option('bws_acf_relationship_settings');
        
        // Clear any cached data
        wp_cache_flush();
        
        // Note: We intentionally do NOT remove the actual post relationships
        // as these are valuable data that users may want to keep even if
        // they uninstall the plugin temporarily.
    }
}

// Run cleanup
bws_acf_relationship_uninstall_cleanup();