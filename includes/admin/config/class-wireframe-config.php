<?php
/**
 * Top-level Wireframe config composer.
 *
 * Calls each per-tab config builder and returns the assembled config array
 * ready for Wireframe\App::boot().
 *
 * @package BWS_Meta_Manager
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Wireframe_Config {

    /**
     * Build the full settings config.
     *
     * @return array
     */
    public static function build(): array {
        require_once BWS_META_MANAGER_PLUGIN_DIR . 'includes/admin/config/class-hierarchical-config.php';

        return [
            'title'    => __('Meta Conductor', 'bws-meta-manager'),
            'subtitle' => __('Unified meta and taxonomy management.', 'bws-meta-manager'),
            'tabs'     => [
                BWS_Hierarchical_Config::tab(),
            ],
        ];
    }
}
