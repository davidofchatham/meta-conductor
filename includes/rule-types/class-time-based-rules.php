<?php
/**
 * Descriptor for `time_based_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Admin\Config\ConfigHelpers;
use BWS\MetaConductor\Handlers\TimeBasedHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `time_based_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class TimeBasedRules extends RuleType {

    public function type(): string {
        return 'time_based_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('Date window — apply a term while a date range is current', 'meta-conductor');
    }

    public function handler_class(): string {
        return TimeBasedHandler::class;
    }

    public function reads_shared_fields(): array {
        return ['post_types', 'target_term_id'];
    }

    /**
     * Date window — apply a term while the current date is inside a range.
     *
     * @return array[]
     */
    public function subfields(): array {
        return [
            [
                'id'          => 'filter_taxonomies',
                'type'        => 'checkboxes',
                'label'       => __('Filter by taxonomies (optional)', 'meta-conductor'),
                'description' => __('Only apply to posts with terms in these taxonomies. Leave empty for all posts.', 'meta-conductor'),
                'columns'     => 12,
                'args'        => [
                    'options' => ConfigHelpers::taxonomy_checkbox_options(),
                ],
            ],
            [
                'id'          => 'filter_terms',
                'type'        => 'select',
                'label'       => __('Filter by specific terms (optional)', 'meta-conductor'),
                'description' => __('Only apply to posts with these terms. Leave empty to use taxonomy filter only.', 'meta-conductor'),
                'columns'     => 12,
                'args'        => [
                    'multiple' => true,
                    'options'  => ConfigHelpers::all_term_options(),
                ],
            ],
            [
                'id'         => 'start_date',
                'type'       => 'date',
                'label'      => __('Start date', 'meta-conductor'),
                'required'   => true,
                'columns'    => 12,
            ],
            [
                'id'         => 'end_date',
                'type'       => 'date',
                'label'      => __('End date', 'meta-conductor'),
                'required'   => true,
                'columns'    => 12,
            ],
            // target_term_id — shared with the related-term rule — is a
            // shared subfield, listed in reads_shared_fields().
        ];
    }
}
