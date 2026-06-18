<?php
/**
 * Storage Factory
 *
 * Provides a centralized way to get the configured rule storage implementation.
 * Uses a singleton pattern to ensure only one storage instance exists.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage Factory Class
 *
 * Creates and manages the rule storage implementation based on configuration.
 */
class BWS_Storage_Factory {

    /**
     * Singleton instance
     *
     * @var BWS_Rule_Storage|null
     */
    private static $instance = null;

    /**
     * Storage configuration
     *
     * @var array
     */
    private static $config = [
        'type' => 'options', // 'options' or 'cpt' (future)
        'cache_enabled' => true,
    ];

    /**
     * Get the rule storage instance
     *
     * @since 0.2.0
     * @return BWS_Rule_Storage Storage implementation instance
     *
     * @example
     * $storage = BWS_Storage_Factory::get_instance();
     * $rules = $storage->get_rules('hierarchical_rules');
     */
    public static function get_instance(): BWS_Rule_Storage {
        if (self::$instance === null) {
            self::$instance = self::create_storage();
        }

        return self::$instance;
    }

    /**
     * Create storage instance based on configuration
     *
     * @return BWS_Rule_Storage Storage implementation
     */
    private static function create_storage(): BWS_Rule_Storage {
        // Allow configuration via constant
        if (defined('BWS_RULE_STORAGE_TYPE')) {
            self::$config['type'] = BWS_RULE_STORAGE_TYPE;
        }

        // Allow filtering the storage type
        $storage_type = apply_filters('bws_meta_manager_storage_type', self::$config['type']);

        switch ($storage_type) {
            case 'cpt':
                // Future CPT implementation
                // if (class_exists('BWS_CPT_Rule_Storage')) {
                //     return new BWS_CPT_Rule_Storage();
                // }
                // Fall back to options if CPT not available
                // translators: %s is the storage type
                error_log(sprintf(__('BWS Meta Manager: CPT storage not yet implemented, falling back to options storage', 'bws-meta-manager')));
                return new BWS_Option_Rule_Storage();

            case 'options':
            default:
                return new BWS_Option_Rule_Storage();
        }
    }

    /**
     * Set storage configuration
     *
     * @since 0.2.0
     * @param array $config Configuration array:
     *                      - 'type' (string): Storage type ('options', 'cpt')
     *                      - 'cache_enabled' (bool): Whether to enable caching
     * @return void
     *
     * @example
     * BWS_Storage_Factory::configure(['type' => 'cpt']);
     */
    public static function configure(array $config): void {
        self::$config = array_merge(self::$config, $config);

        // Reset instance to apply new configuration
        self::reset();
    }

    /**
     * Reset the singleton instance
     *
     * Useful for testing or when switching storage backends.
     *
     * @since 0.2.0
     * @return void
     */
    public static function reset(): void {
        if (self::$instance !== null) {
            self::$instance->clear_cache();
            self::$instance = null;
        }
    }

    /**
     * Get current storage configuration
     *
     * @since 0.2.0
     * @return array Current configuration
     */
    public static function get_config(): array {
        return self::$config;
    }

    /**
     * Get information about the current storage backend
     *
     * @since 0.2.0
     * @return array Storage info with keys:
     *               - 'type': Storage type identifier
     *               - 'class': Storage class name
     *               - 'supports': Array of supported features
     *
     * @example
     * $info = BWS_Storage_Factory::get_storage_info();
     * if (in_array('native_export', $info['supports'])) {
     *     // Storage supports native export/import
     * }
     */
    public static function get_storage_info(): array {
        $storage = self::get_instance();

        $info = [
            'type' => $storage->get_storage_type(),
            'class' => get_class($storage),
            'supports' => [],
        ];

        // Detect supported features based on storage type
        switch ($storage->get_storage_type()) {
            case 'options':
                $info['supports'] = [
                    'search',
                    'filters',
                    'bulk_operations',
                    'export',
                    'import',
                ];
                break;

            case 'cpt':
                $info['supports'] = [
                    'search',
                    'filters',
                    'bulk_operations',
                    'export',
                    'import',
                    'native_export', // WordPress Tools → Export
                    'revisions',
                    'trash',
                    'rest_api',
                    'wp_cli',
                ];
                break;
        }

        return apply_filters('bws_meta_manager_storage_info', $info);
    }

    /**
     * Check if a feature is supported by current storage
     *
     * @since 0.2.0
     * @param string $feature Feature name (e.g., 'native_export', 'revisions')
     * @return bool True if supported, false otherwise
     *
     * @example
     * if (BWS_Storage_Factory::supports('revisions')) {
     *     // Show revision history in UI
     * }
     */
    public static function supports(string $feature): bool {
        $info = self::get_storage_info();
        return in_array($feature, $info['supports'], true);
    }

    /**
     * Get statistics about stored rules
     *
     * @since 0.2.0
     * @return array Statistics with keys:
     *               - 'total_rules': Total number of rules
     *               - 'enabled_rules': Number of enabled rules
     *               - 'by_type': Rules count by type
     *               - 'storage_size': Approximate storage size (bytes)
     *
     * @example
     * $stats = BWS_Storage_Factory::get_statistics();
     * echo "Total rules: {$stats['total_rules']}";
     */
    public static function get_statistics(): array {
        $storage = self::get_instance();
        $stats = [
            'total_rules' => 0,
            'enabled_rules' => 0,
            'by_type' => [],
            'storage_size' => 0,
        ];

        foreach ($storage->get_rule_types() as $type) {
            $all_rules = $storage->get_rules($type);
            $enabled = $storage->get_rules($type, ['enabled' => true]);

            $stats['total_rules'] += count($all_rules);
            $stats['enabled_rules'] += count($enabled);
            $stats['by_type'][$type] = [
                'total' => count($all_rules),
                'enabled' => count($enabled),
            ];
        }

        // Estimate storage size
        if ($storage->get_storage_type() === 'options') {
            $option_value = get_option(BWS_Option_Rule_Storage::OPTION_NAME, []);
            $stats['storage_size'] = strlen(serialize($option_value));
        }

        return $stats;
    }

    /**
     * Migrate rules from one storage backend to another
     *
     * @since 0.2.0
     * @param string $from_type Source storage type ('options' or 'cpt')
     * @param string $to_type Target storage type
     * @param array  $options Migration options:
     *                        - 'backup' (bool): Create backup before migration
     *                        - 'delete_source' (bool): Delete from source after migration
     *                        - 'dry_run' (bool): Test migration without saving
     * @return array Migration results with 'success', 'migrated', 'errors' keys
     *
     * @example
     * // Migrate from options to CPT
     * $results = BWS_Storage_Factory::migrate_storage('options', 'cpt', [
     *     'backup' => true,
     *     'dry_run' => false
     * ]);
     */
    public static function migrate_storage(string $from_type, string $to_type, array $options = []): array {
        $results = [
            'success' => false,
            'migrated' => 0,
            'errors' => [],
            'backup_created' => false,
        ];

        // Validate storage types
        if (!in_array($from_type, ['options', 'cpt'], true) ||
            !in_array($to_type, ['options', 'cpt'], true)) {
            $results['errors'][] = 'Invalid storage type';
            return $results;
        }

        if ($from_type === $to_type) {
            $results['errors'][] = 'Source and target storage types are the same';
            return $results;
        }

        // Create backup if requested
        if ($options['backup'] ?? true) {
            $backup_key = 'bws_storage_backup_' . time();
            $current_data = get_option(BWS_Option_Rule_Storage::OPTION_NAME);
            update_option($backup_key, $current_data);
            $results['backup_created'] = true;
            $results['backup_key'] = $backup_key;
        }

        try {
            // Get source storage
            $original_config = self::$config;
            self::configure(['type' => $from_type]);
            $source = self::get_instance();

            // Export all rules from source
            $exported = $source->export_rules();

            // Get target storage
            self::configure(['type' => $to_type]);
            $target = self::get_instance();

            // Import to target (dry run if requested)
            if (!($options['dry_run'] ?? false)) {
                $import_results = $target->import_rules($exported['rules'], [
                    'overwrite' => true,
                ]);

                $results['migrated'] = $import_results['imported'];
                $results['errors'] = array_merge($results['errors'], $import_results['errors']);

                // Delete from source if requested
                if ($options['delete_source'] ?? false) {
                    // For options storage, clear the option
                    if ($from_type === 'options') {
                        delete_option(BWS_Option_Rule_Storage::OPTION_NAME);
                    }
                    // For CPT, would delete all posts (implement when CPT ready)
                }
            } else {
                $results['migrated'] = count($exported['rules'] ?? []);
                $results['errors'][] = 'Dry run - no changes made';
            }

            $results['success'] = empty($results['errors']) || ($options['dry_run'] ?? false);

            // Restore original configuration
            self::configure($original_config);

        } catch (Exception $e) {
            $results['errors'][] = 'Migration failed: ' . $e->getMessage();

            // Restore from backup if migration failed
            if ($results['backup_created']) {
                $backup_data = get_option($results['backup_key']);
                update_option(BWS_Option_Rule_Storage::OPTION_NAME, $backup_data);
                $results['errors'][] = 'Restored from backup';
            }
        }

        return $results;
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}
