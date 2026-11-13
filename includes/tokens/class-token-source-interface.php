<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * Where a pattern's token values come from. A source only FETCHES: the engine
 * owns every token name, all date parsing and formatting, and all output
 * shaping, so a new source answers these questions and nothing else.
 */
interface TokenSourceInterface {

    /**
     * A stored field value by key, raw — the engine drops non-scalars.
     */
    public function field(string $key): mixed;

    /**
     * The entity's terms in one taxonomy: objects carrying `name` and `slug`,
     * in any order (the engine sorts). Empty when there are none.
     */
    public function terms(string $taxonomy): array;

    /**
     * The publication date, in site time.
     */
    public function published(): ?\DateTimeInterface;

    /**
     * The last-modified date, in site time. Null when the entity has none.
     */
    public function modified(): ?\DateTimeInterface;

    /**
     * This entity's own slug (`post_name`), or '' when it has none. The engine
     * asks only a RELATED source for it: a bare `{slug}` on the post's own pass
     * resolves empty there, so a pattern cannot read itself.
     */
    public function slug(): string;

    /**
     * A source for the post this one is related to, or null when there is none
     * (or the source cannot follow relations). Reads that source answers are the
     * STORED values, never an in-flight pass's overlay. The engine owns the
     * relation names and the one-hop rule; `$field` is the relation's own
     * argument where it takes one.
     */
    public function related(string $relation, string $field = ''): ?TokenSourceInterface;
}
