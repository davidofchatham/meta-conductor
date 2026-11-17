<?php
/**
 * The one write primitive for a post meta value the plugin computes.
 *
 * `FormatDispatcher::write_fields()` is its caller today; plain-meta targets
 * (FW-5 06, FW-46) reuse it as is. It is the FW-46 acceptance bar in one place:
 *
 *   - an ACF field KEY, given or resolved from a name that matches an ACF field,
 *     writes through `update_field()`, so ACF keeps the field's reference row
 *     (`_<name>` => `field_…`) and reads the value back;
 *   - any other name is plain post meta;
 *   - a name starting with `_` is refused, whatever it matches: those rows are
 *     core's and other plugins' private meta. A caller that wants an ACF field
 *     passes its key, which never starts a name.
 *
 * The value is RAW and UNSLASHED, whatever its type; the helper slashes it once,
 * because `update_field()` and `update_post_meta()` both unslash. No cast: an
 * array, an ID list or a whole repeater value stays what it was.
 *
 * Fires no save hook and does not touch the dispatcher's suppression filters;
 * the caller owns those.
 *
 * @package Meta_Conductor
 * @since 0.12.0
 */

namespace BWS\MetaConductor\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class MetaWriter {

    /**
     * Whether a plain meta name clears the acceptance bar: non-empty and not
     * `_`-prefixed. The save-time check in the descriptor's config and the
     * handler's own guard both ask here, so there is one definition.
     */
    public static function acceptable(string $name): bool {
        return $name !== '' && $name[0] !== '_';
    }

    /**
     * @param int    $post_id Entity.
     * @param string $name    Meta name (ignored for the write when `$acf_key` is given).
     * @param mixed  $value   Raw, unslashed.
     * @param string $acf_key ACF field key to write by; '' to resolve from `$name`.
     * @return bool False when refused (a `_` or empty name with no key) or when
     *              a key was given and ACF is absent; true once a write was attempted.
     */
    public static function write(int $post_id, string $name, $value, string $acf_key = ''): bool {
        if ($acf_key === '') {
            if (!self::acceptable($name)) {
                return false;
            }
            $field = function_exists('acf_maybe_get_field') ? \acf_maybe_get_field($name, $post_id, false) : false;
            if (is_array($field) && !empty($field['key'])) {
                $acf_key = (string) $field['key'];
            }
        }

        if ($acf_key !== '') {
            if (!function_exists('update_field')) {
                return false;
            }
            \update_field($acf_key, wp_slash($value), $post_id);

            return true;
        }

        \update_post_meta($post_id, $name, wp_slash($value));

        return true;
    }
}
