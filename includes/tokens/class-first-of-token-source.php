<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * Several related posts read as one: every read answers with the first member,
 * in the order given, that holds a value for it. How `{refs:<field>:<inner>}`
 * gets "the first related post that holds a value for the inner token" out of
 * the interface's single `related()` read: the engine asks once, and this
 * source does the falling through per read.
 *
 * Never follows a relation itself: the engine's one-hop rule already stops a
 * prefix inside a prefix, and a list of sources has no single related post.
 */
final class FirstOfTokenSource implements TokenSourceInterface {

    /** @param TokenSourceInterface[] $members In the order the field lists them. */
    public function __construct(private array $members) {}

    public function field(string $key): mixed {
        foreach ($this->members as $member) {
            $value = $member->field($key);
            // A non-scalar (a nested array or object) is no value: it would win here and then resolve empty.
            if (is_scalar($value) && $value !== '' && $value !== false) return $value;
        }
        return '';
    }

    public function terms(string $taxonomy): array {
        foreach ($this->members as $member) {
            if ($terms = $member->terms($taxonomy)) return $terms;
        }
        return [];
    }

    public function published(): ?\DateTimeInterface {
        foreach ($this->members as $member) {
            if ($date = $member->published()) return $date;
        }
        return null;
    }

    public function modified(): ?\DateTimeInterface {
        foreach ($this->members as $member) {
            if ($date = $member->modified()) return $date;
        }
        return null;
    }

    public function slug(): string {
        foreach ($this->members as $member) {
            if ($slug = $member->slug()) return $slug;
        }
        return '';
    }

    public function related(string $relation, string $field = ''): ?TokenSourceInterface {
        return null;
    }
}
