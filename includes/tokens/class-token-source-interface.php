<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * Where a pattern's token values come from. A source only FETCHES: the engine
 * owns every token name, all date parsing and formatting, and all output
 * shaping, so a new source answers these three questions and nothing else.
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
}
