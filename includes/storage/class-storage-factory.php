<?php
/**
 * Storage Factory
 *
 * Hands out the one rule storage instance. The only backend is the options
 * table; FW-17 grows this back into a real factory if a second one lands.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Storage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage Factory Class
 */
class StorageFactory {

    /**
     * Singleton instance
     *
     * @var RuleStorage|null
     */
    private static $instance = null;

    /**
     * Get the rule storage instance
     *
     * @since 0.2.0
     * @return RuleStorage Storage implementation instance
     *
     * @example
     * $storage = StorageFactory::get_instance();
     * $rules = $storage->get_rules('hierarchical_rules');
     */
    public static function get_instance(): RuleStorage {
        return self::$instance ??= new OptionRuleStorage();
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup() {
        throw new \Exception('Cannot unserialize singleton');
    }
}
