<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * Last-resort numbering for a flat hierarchical type (FW-51 05), in place of
 * `wp_unique_post_slug()`'s per-parent numbering.
 *
 * PURE. The handler supplies the uniqueness lookup (per TYPE, not per parent)
 * and the rewrite's own feed list; nothing here reads WordPress, so
 * `tests/verify-flat-slug.php` pins the reserved names, the candidate order and
 * the truncation on host PHP.
 *
 * RESERVED NAMES. In a flat type a child's slug is a path segment straight
 * under the base, so it must not shadow the base's own URLs (`/base/feed/`,
 * `/base/page/2/`). Core's bad-slug check for a hierarchical type (confirmed
 * against `wp_unique_post_slug()` in WordPress 7.1) refuses exactly: every name
 * in `$wp_rewrite->feeds`, `embed`, and `^(<pagination_base>)?\d+$` (`2`,
 * `page2`). This adds the bare pagination base (`page`) and `attachment`, which
 * core leaves out because it never numbers across a base — a flat type does.
 */
final class FlatSlug {

    /** Core's slug column is 200 characters; the suffix is counted against it. */
    private const MAX = 200;

    /**
     * @param string   $slug      Candidate slug.
     * @param string[] $feeds     Feed names the rewrite serves (`$wp_rewrite->feeds`).
     * @param string   $page_base The rewrite's pagination base (`page`).
     */
    public static function reserved(string $slug, array $feeds = [], string $page_base = 'page'): bool {
        return in_array($slug, [...$feeds, 'embed', 'attachment', $page_base], true)
            || preg_match('/^(' . preg_quote($page_base, '/') . ')?\d+$/', $slug) === 1;
    }

    /**
     * The slug itself when free, else `slug-2`, `slug-3`, … — the first free one.
     *
     * @param string   $slug    Candidate, free or not.
     * @param callable $is_free string -> bool; false for a taken OR reserved slug.
     */
    public static function number(string $slug, callable $is_free): string {
        if ($is_free($slug)) {
            return $slug;
        }
        for ($suffix = 2;; $suffix++) {
            // A cut through a percent-encoded byte would leave a stray `%4`.
            $stem      = preg_replace('/%[0-9A-Fa-f]?$/', '', substr($slug, 0, self::MAX - strlen((string) $suffix) - 1));
            $candidate = $stem . '-' . $suffix;
            if ($is_free($candidate)) {
                return $candidate;
            }
        }
    }
}
