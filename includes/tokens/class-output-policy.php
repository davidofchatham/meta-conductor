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
     * @param bool     $sanitize_bound  Bound variables (`{default_title}`) pass through the
     *                                  sanitizer too. Off for title and slug, whose bound
     *                                  values are returned as-is (pinned by the golden table).
     */
    private function __construct(
        public readonly string $month_format,
        public readonly string $term_label,
        public readonly string $term_joiner,
        private readonly \Closure $sanitizer,
        private readonly \Closure $guard,
        public readonly bool $collapse_dashes,
        private readonly bool $sanitize_bound = false,
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

    /**
     * Plain text for a stored field: month names, term names, comma-joined —
     * the title choices, because a stored value is read by people, not routed
     * through a URL. Values are kept exactly as resolved: no sanitizing, no dash
     * collapsing, so `Smith & Jones` stays `Smith & Jones`. No duplicate guard:
     * a field pattern composes from scratch, with no surviving base to match.
     */
    public static function text(): self {
        return new self('F', 'name', ', ',
            static fn(string $value): string => $value,
            static fn(): bool => false,
            false);
    }

    /**
     * HTML for a stored field: the same choices as `text()` (month names, term
     * names, comma-joined, no duplicate guard), but every token value — a field
     * value, a term name, a bound variable — is HTML-escaped, quotes included and
     * already-escaped input escaped again. Pattern literals are never touched,
     * so authored markup survives and the token VALUE is the only trust boundary.
     *
     * The cleanup around an empty token is text-based, not markup-aware: an
     * empty token inside an element at the end of the pattern takes the literal
     * run in front of it, opening tag included. Keep optional tokens outside the
     * wrappers they would leave dangling.
     */
    public static function html(): self {
        return new self('F', 'name', ', ',
            static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            static fn(): bool => false,
            collapse_dashes: false,
            sanitize_bound: true);
    }

    public function sanitize(string $value): string {
        return ($this->sanitizer)($value);
    }

    /** A bound variable's value as the output wants it. */
    public function bound(string $value): string {
        return $this->sanitize_bound ? $this->sanitize($value) : $value;
    }

    public function in_base(string $base, string $value): bool {
        return ($this->guard)($base, $value);
    }
}
