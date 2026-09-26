<?php
/**
 * Descriptor for `related_post_terms_rules` rows.
 *
 * @since 0.10.0
 */

namespace BWS\MetaConductor\RuleTypes;

use BWS\MetaConductor\Handlers\RelatedPostTermsHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named after the storage key `related_post_terms_rules`, not a domain name — FW-14 renames
 * `label()` only.
 */
final class RelatedPostTermsRules extends RuleType {

    public function type(): string {
        return 'related_post_terms_rules';
    }

    public function kind(): string {
        return OptionRuleStorage::KIND_TERM;
    }

    public function label(): string {
        return __('From referenced post (ACF) — copy terms across a relationship field', 'meta-conductor');
    }

    public function handler_class(): string {
        return RelatedPostTermsHandler::class;
    }

    /**
     * Split the combined ACF relationship-field values ("post_type:field_name:
     * field_key") into scalar post_type + bare field name + key (#25). A legacy
     * two-part value yields an empty key, which callers read as "resolve by
     * name", i.e. pre-#25 behavior.
     */
    public function normalize(array $row): array {
        if (!empty($row['acf_field_name'])) {
            [$pt, $bare, $key]     = OptionRuleStorage::split_acf_field_value((string) $row['acf_field_name']);
            $row['acf_field_name'] = $bare;
            $row['acf_field_key']  = $key;
            if ($pt !== null) {
                $row['post_type'] = $pt;
            } elseif (!isset($row['post_type'])) {
                $row['post_type'] = '';
            }
        }

        // Reverse field is stored in the same option format; the handler
        // wants the bare field name and, since #25, the key beside it.
        if (!empty($row['reverse_acf_field_name'])) {
            [, $rbare, $rkey]              = OptionRuleStorage::split_acf_field_value((string) $row['reverse_acf_field_name']);
            $row['reverse_acf_field_name'] = $rbare;
            $row['reverse_acf_field_key']  = $rkey;
        }

        return $row;
    }
}
