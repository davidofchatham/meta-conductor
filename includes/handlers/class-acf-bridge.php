<?php
/**
 * ACF Bridge Trait
 *
 * Standalone ACF taxonomy-field read/write/discover helpers shared by the
 * ACF-listening handlers (propagation, level-restriction) on
 * UnifiedHandlerBase. Each guards on function_exists so a site without ACF
 * degrades to a safe no-op. Unrelated to the removed AcfIntegration engine.
 *
 * Composed into UnifiedHandlerBase (see its `use`). Extracted here so the ACF
 * helpers have an obvious named home — the B4/§V14 latent undefined-method
 * fatal traced to these methods being buried in the fat base.
 *
 * @package BWS_Meta_Manager
 * @since 0.6.3
 */

namespace BWS\MetaConductor\Handlers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

trait AcfBridge {

    /**
     * Read an ACF taxonomy field's value as a flat array of term IDs.
     *
     * Ported from legacy HandlerBase (B4/V14) — used by the propagation and
     * level-restriction ACF code paths, which now extend this base. Standalone
     * get_field() wrapper; unrelated to the removed AcfIntegration engine.
     * Returns [] when ACF is absent or the field is empty.
     *
     * @param int    $post_id
     * @param string $field_name
     * @param string $taxonomy   Accepted for signature parity; ACF returns the value directly.
     * @return int[]
     */
    protected function get_acf_taxonomy_value($post_id, $field_name, $taxonomy) {
        if (!function_exists('get_field')) {
            return array();
        }

        $value = get_field($field_name, $post_id);

        if (empty($value)) {
            return array();
        }

        // Handle different ACF taxonomy field return formats
        if (is_array($value)) {
            $term_ids = array();
            foreach ($value as $item) {
                if (is_object($item) && isset($item->term_id)) {
                    $term_ids[] = $item->term_id;
                } elseif (is_numeric($item)) {
                    $term_ids[] = absint($item);
                }
            }
            return $term_ids;
        } elseif (is_object($value) && isset($value->term_id)) {
            return array($value->term_id);
        } elseif (is_numeric($value)) {
            return array(absint($value));
        }

        return array();
    }

    /**
     * Write term IDs to an ACF taxonomy field.
     *
     * Ported from legacy HandlerBase (B4/V14). Standalone update_field()
     * wrapper; unrelated to the removed AcfIntegration engine.
     *
     * @param int       $post_id
     * @param string    $field_name
     * @param int[]|int $term_ids
     * @return mixed update_field() result, or false when ACF is absent.
     */
    protected function set_acf_taxonomy_value($post_id, $field_selector, $term_ids) {
        if (!function_exists('update_field')) {
            return false;
        }

        if (!is_array($term_ids)) {
            $term_ids = array($term_ids);
        }

        // $field_selector should be the ACF field KEY (field_xxxx), not the name,
        // when writing a field that may have NO prior value on this post. On a
        // first write ACF needs the key to register the hidden _{name} reference
        // row; passing the name falls back to a bare update_post_meta with no
        // reference, so get_field() can't later resolve/format the value.
        // (0.6.0 ACF B-sweep — get_acf_taxonomy_fields yields keys.)
        return update_field($field_selector, $term_ids, $post_id);
    }

    /**
     * Discover a post's ACF taxonomy fields for a taxonomy, INDEPENDENT of
     * whether the post has any saved field values.
     *
     * get_field_objects($post_id) enumerates from stored meta and returns FALSE
     * for a post with no ACF values yet — so a never-populated child could never
     * receive its first propagated/restricted ACF write (chicken-and-egg). This
     * resolves fields from field-group LOCATION rules instead (the same engine
     * the ACF admin uses), so attached-but-empty fields are found.
     *
     * Recurses sub_fields so a taxonomy field nested in a Group/Repeater is seen.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return array[] List of ['name' => string, 'key' => string] for each
     *                 matching taxonomy field. Empty when ACF is absent or none match.
     */
    protected function get_acf_taxonomy_fields($post_id, $taxonomy) {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return array();
        }

        $matches = array();

        $walk = function ($fields) use (&$walk, $taxonomy, &$matches) {
            foreach ((array) $fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (($field['type'] ?? '') === 'taxonomy'
                    && ($field['taxonomy'] ?? null) === $taxonomy) {
                    $matches[] = array(
                        'name' => $field['name'] ?? '',
                        'key'  => $field['key'] ?? '',
                    );
                }
                if (!empty($field['sub_fields'])) {
                    $walk($field['sub_fields']);
                }
            }
        };

        foreach (acf_get_field_groups(array('post_id' => $post_id)) as $group) {
            $walk(acf_get_fields($group['key']));
        }

        return $matches;
    }
}
