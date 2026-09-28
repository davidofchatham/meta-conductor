<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * Renders `{meta:x} {term:tax} {pub_year}`-style patterns: split into literal +
 * token segments, resolve each token against a source, drop empty tokens with
 * the separators in front of them, then trim what is left.
 *
 * Knows nothing about titles or slugs. Where values come from is the
 * `TokenSourceInterface`; how they are shaped is the `OutputPolicy`; whether a
 * duplicate guard applies, and against what, is the caller's `$guard_base`.
 */
final class TokenEngine {

    /**
     * @param string               $pattern    Pattern to render.
     * @param TokenSourceInterface $source     Where token values come from.
     * @param OutputPolicy         $policy     How they are shaped.
     * @param array                $vars       Bound tokens by name, returned as-is and never guarded.
     * @param string|null          $guard_base Duplicate-insertion guard: a resolved token whose value
     *                                         already appears in this string is dropped, compared as
     *                                         the policy says. Null (or '') means no guard.
     * @return string
     */
    public static function render(string $pattern, TokenSourceInterface $source, OutputPolicy $policy,
                                  array $vars = [], ?string $guard_base = null): string {
        $result = '';
        $pending_literal = '';

        foreach (self::segments($pattern) as $seg) {
            if ($seg['token'] === null) {
                // Trailing literal: only append if we have non-empty result.
                if ($result !== '') $result .= $seg['literal'];
                break;
            }

            $value = self::resolve($seg['token'], $source, $policy, $vars, $guard_base);

            if ($value !== '') {
                $result .= $pending_literal . $seg['literal'] . $value;
                $pending_literal = '';
            } elseif ($result !== '') {
                // Token empty: its preceding literal is pending, emitted only if a
                // later token resolves — otherwise it is a trailing separator.
                $pending_literal .= $seg['literal'];
            }
        }

        return self::trim($result, $policy);
    }

    /**
     * The token names a pattern contains, in order, repeats included — from the
     * parser `render()` uses, so a caller never re-reads the grammar.
     *
     * @return string[]
     */
    public static function tokens(string $pattern): array {
        return array_values(array_filter(array_column(self::segments($pattern), 'token'), fn($t) => $t !== null));
    }

    /**
     * The date part a token renders — `date_<part>:field` or `pub_<part>` — or
     * null for any other token.
     */
    public static function date_part_of(string $token): ?string {
        [$kind, $arg] = self::split($token);
        $prefix = $arg === null ? 'pub_' : 'date_';
        return str_starts_with($kind, $prefix) ? substr($kind, strlen($prefix)) : null;
    }

    /**
     * Parse a meta date value. Tries the stored formats first, then a unix
     * timestamp, then anything `strtotime()` accepts.
     */
    public static function parse_date(string $value): ?\DateTime {
        foreach (['Ymd', 'Y-m-d', 'Y-m-d H:i:s', 'd/m/Y'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt !== false) return $dt;
        }
        // Unix timestamp fallback.
        if (is_numeric($value)) {
            return (new \DateTime())->setTimestamp((int)$value);
        }
        $ts = strtotime($value);
        return $ts !== false ? (new \DateTime())->setTimestamp($ts) : null;
    }

    /**
     * @return array<int, array{literal: string, token: ?string}> The last segment
     *         is the trailing literal, with a null token.
     */
    private static function segments(string $pattern): array {
        $segments = [];
        $offset = 0;
        preg_match_all('/\{([^}]+)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $i => $match) {
            $token_start  = $matches[0][$i][1];
            $segments[]   = ['literal' => substr($pattern, $offset, $token_start - $offset), 'token' => $match[0]];
            $offset       = $token_start + strlen($matches[0][$i][0]);
        }
        $segments[] = ['literal' => substr($pattern, $offset), 'token' => null];
        return $segments;
    }

    private static function resolve(string $token, TokenSourceInterface $source, OutputPolicy $policy,
                                    array $vars, ?string $guard_base): string {
        if (array_key_exists($token, $vars)) {
            return (string) $vars[$token];
        }

        [$kind, $arg] = self::split($token);
        $date_part    = self::date_part_of($token);

        $value = match (true) {
            $date_part !== null && $arg === null              => self::date_part($source->published(), $date_part, $policy),
            $date_part !== null                               => self::date_field($source->field($arg), $date_part, $policy),
            $arg === null                                     => '',
            $kind === 'meta'                                  => self::scalar($source->field($arg)),
            $kind === 'term'                                  => self::terms($source, $arg, $policy, true),
            $kind === 'terms'                                 => self::terms($source, $arg, $policy, false),
            default                                           => '',
        };

        if ($value === '') return '';

        // Duplicate-insertion guard: skip a token whose value already appears in
        // the base — but ONLY when the caller says the base survives into the
        // output. A pattern that composes from scratch discards it, and measuring
        // against it would delete exactly the tokens that resolved correctly last
        // pass ("John Smith" -> "Mr. III" -> "John Smith" on every save).
        if ($guard_base !== null && $guard_base !== '' && $policy->in_base($guard_base, $value)) {
            return '';
        }

        return $policy->sanitize($value);
    }

    /** @return array{0: string, 1: ?string} Token kind and its argument (null when bare). */
    private static function split(string $token): array {
        return str_contains($token, ':') ? explode(':', $token, 2) : [$token, null];
    }

    private static function scalar(mixed $value): string {
        return (is_array($value) || is_object($value)) ? '' : (string) $value;
    }

    private static function date_field(mixed $raw, string $part, OutputPolicy $policy): string {
        if (empty($raw) || is_array($raw) || is_object($raw)) return '';
        return self::date_part(self::parse_date((string) $raw), $part, $policy);
    }

    private static function date_part(?\DateTimeInterface $dt, string $part, OutputPolicy $policy): string {
        $format = match ($part) {
            'year'   => 'Y',
            'month'  => $policy->month_format,
            'day'    => 'd',
            'hour'   => 'H',
            'minute' => 'i',
            default  => '',
        };
        return ($dt && $format !== '') ? $dt->format($format) : '';
    }

    private static function terms(TokenSourceInterface $source, string $taxonomy, OutputPolicy $policy, bool $first): string {
        $terms = $source->terms($taxonomy);
        if (!$terms) return '';
        usort($terms, fn($a, $b) => strcmp($a->name, $b->name));
        $labels = array_column($terms, $policy->term_label);
        return $first ? (string) $labels[0] : implode($policy->term_joiner, $labels);
    }

    private static function trim(string $result, OutputPolicy $policy): string {
        // Strip unmatched trailing opening punctuation.
        $result = preg_replace('/\s*[\(\[\{<]+\s*$/', '', $result);
        // Strip leading separators.
        $result = preg_replace('/^[\s:,\-|\/]+/', '', $result);
        // Strip trailing separators.
        $result = preg_replace('/[\s:,\-|\/]+$/', '', $result);
        // Collapse multiple spaces.
        $result = preg_replace('/\s{2,}/', ' ', $result);
        $result = trim($result);

        if ($policy->collapse_dashes) {
            $result = preg_replace('/-{2,}/', '-', $result);
            $result = trim($result, '-');
        }

        return $result;
    }
}
