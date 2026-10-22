<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * Token values for one post.
 *
 * The publish date reads the format seam's ARRAY, not the post row, so a rule's
 * tokens see what an earlier rule in the same pass computed. Fields and terms
 * read by post ID.
 *
 * `field()` reads a value an earlier Computed field rule set in this pass
 * (`$data['fields']`) before stored meta, so a rule ordered after it sees what
 * it just computed. `$own_target` names one field that always resolves empty — the
 * rule's own target, so a pattern that names it cannot compound across passes.
 */
final class PostTokenSource implements TokenSourceInterface {

    /**
     * @param int   $post_id Entity the tokens read.
     * @param array $data    Post data as the format seam carries it.
     */
    public function __construct(private int $post_id, private array $data, private string $own_target = '') {}

    public function field(string $key): mixed {
        if ($key !== '' && $key === $this->own_target) {
            return '';
        }
        if (isset($this->data['fields']) && array_key_exists($key, $this->data['fields'])) {
            return $this->data['fields'][$key];
        }
        $value = get_post_meta($this->post_id, $key, true);
        if (is_array($value) || is_object($value)) {
            error_log(sprintf('BWS Tokens: Field returned non-string value (field: %s)', $key));
        }
        return $value;
    }

    public function terms(string $taxonomy): array {
        $terms = get_the_terms($this->post_id, $taxonomy);
        return (empty($terms) || is_wp_error($terms)) ? [] : $terms;
    }

    /**
     * `post_date` is stored in the site's timezone, not PHP's server zone —
     * bound explicitly so the two can differ.
     */
    public function published(): ?\DateTimeInterface {
        return new \DateTimeImmutable((string) ($this->data['post_date'] ?? ''), wp_timezone());
    }
}
