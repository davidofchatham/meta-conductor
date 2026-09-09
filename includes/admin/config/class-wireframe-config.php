<?php
/**
 * Top-level Wireframe config composer.
 *
 * Assembles the tabs + sections that make up the Meta Conductor settings
 * page. THREE tabs since 0.8.0 (#57, ADR 0003 / spec #53 §2):
 *
 *   - Auto-Set & Restrict — the ordered term-rule list: everything whose
 *                           effect is "terms on a post", including the
 *                           restricting rules. Restrict stopped being a tab
 *                           of its own because a level-restriction rule
 *                           writes terms like every other rule here, and
 *                           putting it in the same ordered list is the point
 *                           of the model rather than a tidy-up.
 *   - Format & Transform  — the ordered format-rule list: everything whose
 *                           effect is "how a post's own fields read".
 *                           title/slug is its only member today; the list
 *                           shape landed in #59 so the second format rule
 *                           type is an addition, not a restructure.
 *   - General             — default claim per taxonomy, processing options.
 *
 * Both rule tabs are now one ordered repeater bound to their effect kind's
 * persisted list, which is the whole of ADR 0003 decision 1 on the config
 * side: no tab holds a per-type section any more.
 *
 * Gone with the same change: the empty Personalize placeholder (it described
 * rule types that do not exist yet, and an empty tab reads as a broken
 * feature), and the five per-type sections the ordered list replaced.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Admin\Config;

if (!defined('ABSPATH')) {
    exit;
}

class WireframeConfig {

    public static function build(): array {
        // Sibling config classes (ConfigHelpers, *Config) autoload via PSR-4
        // (autoload.php); no manual require chain (Phase 2a).
        return [
            'title'    => __('Meta Conductor', 'meta-conductor'),
            'subtitle' => __('Unified meta and taxonomy management.', 'meta-conductor'),
            'tabs'     => [
                TermRulesConfig::tab(),
                FormatRulesConfig::tab(),
                GeneralConfig::tab(),
            ],
        ];
    }
}
