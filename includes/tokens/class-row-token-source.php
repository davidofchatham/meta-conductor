<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * Token values for one row of an ACF repeater.
 *
 * `field()` means "the sibling subfield of this row": `{meta:x}` and `{date_*:x}`
 * read `$row[x]` and nothing else — there is deliberately no fallback to post
 * meta, so a name the row lacks resolves empty rather than silently reading a
 * same-named post field. Terms and the publish date are the post's, so a row
 * line can still carry the season it belongs to.
 *
 * `$row` is name => raw value for the DIRECT scalar siblings; the caller leaves
 * out (or passes as arrays) anything nested, which the engine drops. The rule's
 * own target subfield resolves empty, so a pattern cannot compound across passes.
 */
final class RowTokenSource implements TokenSourceInterface {

    /**
     * @param TokenSourceInterface $post       Source for terms and the publish date.
     * @param array<string,mixed>  $row        Sibling subfield values by name.
     * @param string               $own_target The subfield this rule writes.
     */
    public function __construct(private TokenSourceInterface $post, private array $row, private string $own_target = '') {}

    public function field(string $key): mixed {
        if ($key === '' || $key === $this->own_target) {
            return '';
        }

        return $this->row[$key] ?? '';
    }

    public function terms(string $taxonomy): array {
        return $this->post->terms($taxonomy);
    }

    public function published(): ?\DateTimeInterface {
        return $this->post->published();
    }
}
