<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

use BWS\MetaConductor\Relationships\AcfRelationship;
use BWS\MetaConductor\Relationships\ParentRelationship;

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

    /**
     * `post_modified` is site time too. Unlike the publish date, a draft that
     * never saved carries the zero date, which is "none", not year -0001.
     */
    public function modified(): ?\DateTimeInterface {
        $value = (string) ($this->data['post_modified'] ?? '');
        return ($value === '' || str_starts_with($value, '0000')) ? null : new \DateTimeImmutable($value, wp_timezone());
    }

    public function slug(): string {
        return (string) ($this->data['post_name'] ?? '');
    }

    /**
     * Both relations read through the relationship module (`ParentRelationship`,
     * `AcfRelationship`), so a token and a term rule agree on what "related" means.
     * `parent` answers with a source over the parent's stored row; `refs` reads the
     * ACF field `$field` on this post and answers with the related posts as one
     * first-non-empty source. Both read the STORED related post, never an overlay.
     */
    public function related(string $relation, string $field = ''): ?TokenSourceInterface {
        if ($relation === 'refs') {
            return $this->refs($field);
        }
        if ($relation !== 'parent' || $this->post_id <= 0) return null;
        $ids  = (new ParentRelationship())->sources_of($this->post_id, []);
        $post = $ids ? get_post($ids[0]) : null;
        return $post ? new self($ids[0], (array) $post) : null;
    }

    /** The related posts' sources in the field's order; a post that is gone or trashed is not one. */
    private function refs(string $field): ?TokenSourceInterface {
        if ($this->post_id <= 0 || $field === '') return null;
        $ids = (new AcfRelationship())->sources_of($this->post_id, ['holder_role' => 'target', 'acf_field_name' => $field]);
        $members = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post && $post->post_status !== 'trash') $members[] = new self($id, (array) $post);
        }
        return $members ? new FirstOfTokenSource($members) : null;
    }
}
