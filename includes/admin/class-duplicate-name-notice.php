<?php
/**
 * Duplicate-name notice for flat hierarchical types (FW-51 06).
 *
 * A type served flat (`flat_urls`) addresses a child as `/base/child/`, so two
 * posts of the type with the same name under different parents compete for one
 * URL. Resolution copes at runtime (published first, then lowest ID), so this
 * is advisory: it tells the author which posts to rename.
 *
 * COST. Computed only while the Format & Transform tab is being rendered —
 * `WireframeConfig::build()` runs on every admin and REST request, so the field
 * gates itself on the settings page's own screen and returns nothing anywhere
 * else. One grouped query, limited to the types that have an enabled flat row.
 * Nothing runs on the front end.
 *
 * @package Meta_Conductor
 * @since 0.9.0
 */

namespace BWS\MetaConductor\Admin;

use BWS\MetaConductor\TaxonomyManager;

if (!defined('ABSPATH')) {
    exit;
}

class DuplicateNameNotice {

    /** The settings page's menu slug (WireframeBootstrap::boot). */
    private const PAGE = 'meta-conductor';

    /**
     * Rows read, so a site with thousands of same-named posts renders a bounded
     * notice. The cut can split the last group (it shows fewer posts than it has).
     * ponytail: a flat cap; page the list if a real site ever hits it.
     */
    private const LIMIT = 500;

    /**
     * The notice as a Wireframe `html` field, or null when there is nothing to
     * say or this is not the settings screen.
     */
    public static function field(): ?array {
        // Read-only screen check; nothing is saved from a query string.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (($_GET['page'] ?? '') !== self::PAGE || !is_admin()) {
            return null;
        }

        $rows = self::duplicates(self::flat_types());
        if ($rows === []) {
            return null;
        }

        return [
            'id'      => 'flat_duplicate_names',
            'type'    => 'html',
            'columns' => 12,
            'args'    => [
                'variant' => 'warning',
                'content' => self::html($rows),
            ],
        ];
    }

    /**
     * Post types with an enabled flat row, from the handler that serves them so
     * the notice and the URLs can never disagree on which types are flat.
     *
     * @return string[]
     */
    private static function flat_types(): array {
        $handler = TaxonomyManager::get_instance()->get_handler('title_slug_rules');

        return $handler ? $handler->flat_post_types() : [];
    }

    /**
     * Every post sharing its type and name with another, in one query.
     *
     * @param string[] $types
     * @return object[] ID, post_type, post_name, post_title, post_parent.
     */
    private static function duplicates(array $types): array {
        global $wpdb;

        if ($types === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($types), '%s'));
        $live  = "post_status NOT IN ('trash','auto-draft')";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $sql = "SELECT p.ID, p.post_type, p.post_name, p.post_title, p.post_parent
                FROM {$wpdb->posts} p
                JOIN (SELECT post_type, post_name FROM {$wpdb->posts}
                      WHERE post_type IN ($marks) AND post_name <> '' AND $live
                      GROUP BY post_type, post_name HAVING COUNT(*) > 1) d
                  ON d.post_type = p.post_type AND d.post_name = p.post_name
                WHERE p.$live
                ORDER BY p.post_type, p.post_name, p.ID
                LIMIT " . self::LIMIT;

        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...$types));
        // phpcs:enable

        return $rows;
    }

    /**
     * @param object[] $rows
     * @return string Escaped HTML.
     */
    private static function html(array $rows): string {
        // One query for every title and parent below, instead of one per row.
        _prime_post_caches(array_unique(array_merge(
            array_map(static fn($row) => (int) $row->ID, $rows),
            array_filter(array_map(static fn($row) => (int) $row->post_parent, $rows))
        )), false, false);

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->post_type . '/' . $row->post_name][] = $row;
        }

        // A post title is author text, and the client may interpolate a `{word}`
        // in `html` content as it does in descriptions (CLAUDE.md don't 4b);
        // the entity renders as the brace and is not matched.
        $safe = static fn(string $text): string => str_replace(['{', '}'], ['&#123;', '&#125;'], esc_html($text));

        $html  = '<p><strong>' . esc_html(sprintf(
            /* translators: %d: number of duplicated names. */
            _n('%d name is shared by several posts in a flat-URL post type.', '%d names are shared by several posts in a flat-URL post type.', count($groups), 'meta-conductor'),
            count($groups)
        )) . '</strong> '
            . esc_html__('Their pages share one flat URL, so the published one (then the oldest) is served. Rename the others.', 'meta-conductor') . '</p><ul>';

        foreach ($groups as $key => $group) {
            $html .= '<li><code>' . esc_html($key) . '</code><ul>';
            foreach ($group as $row) {
                $parent = (int) $row->post_parent;
                $html  .= '<li><a href="' . esc_url((string) get_edit_post_link((int) $row->ID)) . '">'
                    . $safe($row->post_title !== '' ? $row->post_title : __('(no title)', 'meta-conductor')) . '</a> — '
                    . ($parent
                        ? $safe(sprintf(
                            /* translators: %s: parent post title. */
                            __('under %s', 'meta-conductor'),
                            get_the_title($parent)
                        ))
                        : esc_html__('top level', 'meta-conductor'))
                    . '</li>';
            }
            $html .= '</ul></li>';
        }

        return $html . '</ul>';
    }
}
