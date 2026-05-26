<?php
/**
 * Top-level Wireframe config composer.
 *
 * Assembles all tabs + sections that make up the Meta Conductor settings
 * page. Tabs reflect the user-facing categorization:
 *
 *   - Auto-set Terms     — relationship-driven and date-driven term application
 *   - Format & Transform — title/slug + future field transformations
 *   - Restrict           — depth restrictions, future user-locked taxonomies
 *   - Personalize        — user-based term setting (future)
 *   - General            — global conflict handling, manual processing
 *
 * @package BWS_Meta_Manager
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Wireframe_Config {

    public static function build(): array {
        $dir = BWS_META_MANAGER_PLUGIN_DIR . 'includes/admin/config/';

        require_once $dir . 'class-config-helpers.php';
        require_once $dir . 'class-hierarchical-config.php';
        require_once $dir . 'class-propagation-config.php';
        require_once $dir . 'class-related-config.php';
        require_once $dir . 'class-related-post-terms-config.php';
        require_once $dir . 'class-time-based-config.php';
        require_once $dir . 'class-level-restriction-config.php';
        require_once $dir . 'class-title-slug-config.php';
        require_once $dir . 'class-general-config.php';
        require_once $dir . 'class-personalize-config.php';

        return [
            'title'    => __('Meta Conductor', 'bws-meta-manager'),
            'subtitle' => __('Unified meta and taxonomy management.', 'bws-meta-manager'),
            'tabs'     => [
                self::auto_set_tab(),
                self::format_transform_tab(),
                self::restrict_tab(),
                BWS_Personalize_Config::tab(),
                BWS_General_Config::tab(),
            ],
        ];
    }

    /**
     * Format & Transform tab — title/slug and future field transformations.
     */
    private static function format_transform_tab(): array {
        return [
            'id'       => 'format-transform',
            'title'    => __('Format & Transform', 'bws-meta-manager'),
            'sections' => [
                BWS_Title_Slug_Config::section(),
            ],
        ];
    }

    /**
     * Auto-Set Terms tab — five rule types ordered by deployment priority.
     */
    private static function auto_set_tab(): array {
        return [
            'id'       => 'auto-set',
            'title'    => __('Auto-Set Terms', 'bws-meta-manager'),
            'sections' => [
                // Group B: based on terms on a related post (highest deployment priority)
                BWS_Propagation_Config::section(),
                BWS_Related_Post_Terms_Config::section(),
                // Group C: based on date
                BWS_Time_Based_Config::section(),
                // Group A: based on terms already on this post
                BWS_Related_Config::section(),
                BWS_Hierarchical_Config::section(),
            ],
        ];
    }

    /**
     * Restrict tab — depth restrictions. User-based restrictions land in Personalize tab later.
     */
    private static function restrict_tab(): array {
        return [
            'id'       => 'restrict',
            'title'    => __('Restrict', 'bws-meta-manager'),
            'sections' => [
                BWS_Level_Restriction_Config::section(),
            ],
        ];
    }
}
