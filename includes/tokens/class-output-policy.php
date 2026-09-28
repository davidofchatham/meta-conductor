<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * How resolved token values are shaped for one kind of output. Data, not a
 * context string: a new output is one more named constructor and no new branch
 * in the engine.
 */
final class OutputPolicy {

    /**
     * @param string   $month_format `date()` format for month tokens.
     * @param string   $term_label   Term property a term token prints.
     * @param string   $term_joiner  Glue between `{terms:}` labels.
     * @param \Closure $sanitizer    string -> string, applied to every token value.
     * @param \Closure $guard        (base, value) -> bool: value already in base.
     * @param bool     $collapse_dashes Final trim also collapses and trims dashes.
     */
    private function __construct(
        public readonly string $month_format,
        public readonly string $term_label,
        public readonly string $term_joiner,
        private readonly \Closure $sanitizer,
        private readonly \Closure $guard,
        public readonly bool $collapse_dashes,
    ) {}

    /**
     * Post titles: month names, term names, comma-joined, values unchanged,
     * the guard a case-insensitive substring match on the raw value.
     */
    public static function title(): self {
        return new self('F', 'name', ', ',
            static fn(string $value): string => $value,
            static fn(string $base, string $value): bool => mb_stripos($base, $value) !== false,
            false);
    }

    /**
     * Post slugs: month numbers, term slugs, dash-joined, every value
     * sanitized, the guard a substring match on the sanitized value.
     *
     * @param callable|null $sanitize string -> string; `sanitize_title` when null.
     *                                Injectable so host-PHP tests can stub it.
     */
    public static function slug(?callable $sanitize = null): self {
        $sanitize = $sanitize
            ? \Closure::fromCallable($sanitize)
            : static fn(string $value): string => sanitize_title($value);
        return new self('m', 'slug', '-',
            $sanitize,
            static fn(string $base, string $value): bool => str_contains($base, $sanitize($value)),
            true);
    }

    public function sanitize(string $value): string {
        return ($this->sanitizer)($value);
    }

    public function in_base(string $base, string $value): bool {
        return ($this->guard)($base, $value);
    }
}
