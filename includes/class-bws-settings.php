<?php
/**
 * Settings compatibility shell.
 *
 * Phase 2c (Wireframe swap) deleted the legacy rendering + sanitization
 * (~2,000 lines). This thin shell remains because 5 of 7 handlers still
 * extend the legacy `BWS_Handler_Base`, which calls
 * `$settings->get_settings()` and `$settings->update_settings()`. Phase 3
 * migrates those handlers onto `BWS_Unified_Handler_Base` (which reads
 * via `BWS_Storage_Factory` directly); this shell goes away with them.
 *
 * The shell reads from the new option key (`bws_meta_conductor_settings`)
 * via the storage layer so canonical-shape normalization stays consistent
 * between unified and legacy handlers.
 *
 * @package BWS_Meta_Manager
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BWS_Settings {

    /** @deprecated Storage layer owns the canonical option key. */
    const OPTION_NAME = 'bws_meta_conductor_settings';

    public function get_settings(): array {
        $storage = BWS_Storage_Factory::get_instance();

        $rule_types = [
            'hierarchical_rules',
            'propagation_rules',
            'related_rules',
            'time_based_rules',
            'related_post_terms_rules',
            'hierarchical_level_restriction_rules',
            'title_slug_rules',
        ];

        $out = [];
        foreach ($rule_types as $type) {
            $out[$type] = $storage->get_rules($type);
        }

        // Non-rule global settings live alongside rule arrays in the same option.
        $raw = get_option(self::OPTION_NAME, []);
        $out['conflict_handling']         = self::flatten_conflict_overrides($raw['conflict_handling_overrides'] ?? []);
        $out['manual_processing_enabled'] = $raw['manual_processing_enabled'] ?? true;

        return $out;
    }

    /**
     * Coerce the conflict-handling repeater rows [{taxonomy, mode}, ...]
     * into the canonical {taxonomy_slug: mode} dict that handlers consume.
     */
    private static function flatten_conflict_overrides(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (empty($row['taxonomy']) || empty($row['mode'])) {
                continue;
            }
            $out[$row['taxonomy']] = $row['mode'];
        }
        return $out;
    }

    public function get_setting(string $key, mixed $default = null): mixed {
        $all = $this->get_settings();
        return $all[$key] ?? $default;
    }

    public function update_settings(array $new_settings): bool {
        $existing = get_option(self::OPTION_NAME, []);
        $merged   = array_merge($existing, $new_settings);
        return update_option(self::OPTION_NAME, $merged);
    }
}
