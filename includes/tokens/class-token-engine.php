<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * Renders `{meta:x} {term:tax} {pub_year}`-style patterns: split into literal +
 * token segments, resolve each token against a source, remove each empty token
 * together with the punctuation that only existed to join it, then trim what is
 * left. The removal rules are the ordered steps of bws-gb-dynamic-tags-extensions'
 * `{{join}}` template mode, minus its `~…~` groups (this grammar has none): see
 * `clean()` and `remove_gap()`.
 *
 * Knows nothing about titles or slugs. Where values come from is the
 * `TokenSourceInterface`; how they are shaped is the `OutputPolicy`; whether a
 * duplicate guard applies, and against what, is the caller's `$guard_base`.
 */
final class TokenEngine {

    /** Stands in for an empty token while the cleanup runs; LAST_GAP for the pattern's last token. */
    private const GAP      = "\x02";
    private const LAST_GAP = "\x03";

    /** Floating separators the cleanup removes beside an empty token. Multi-byte, so matched as substrings. */
    private const SEPARATORS = ['·', '•', '/', '|', '-', '–', '—'];

    /**
     * @param string               $pattern    Pattern to render.
     * @param TokenSourceInterface $source     Where token values come from.
     * @param OutputPolicy         $policy     How they are shaped.
     * @param array                $vars       Bound tokens by name, never guarded; returned as-is unless the policy escapes them (`html()`).
     * @param string|null          $guard_base Duplicate-insertion guard: a resolved token whose value
     *                                         already appears in this string is dropped, compared as
     *                                         the policy says. Null (or '') means no guard.
     * @return string
     */
    public static function render(string $pattern, TokenSourceInterface $source, OutputPolicy $policy,
                                  array $vars = [], ?string $guard_base = null): string {
        $segments = self::segments($pattern);
        $values   = [];
        $text     = '';
        $has_gap  = false;

        // Resolved values ride as \x01<n>\x01 placeholders until the cleanup is
        // done, so no step can mistake a value's own punctuation for the
        // pattern's. An empty token stays as a marker for the cleanup to remove.
        foreach ($segments as $seg) {
            $text .= $seg['literal'];
            if ($seg['token'] === null) break;

            $value = self::resolve($seg['token'], $source, $policy, $vars, $guard_base);
            if ($value === '') {
                $text   .= self::GAP;
                $has_gap = true;
            } else {
                $values[] = $value;
                $text    .= "\x01" . (count($values) - 1) . "\x01";
            }
        }

        // Nothing resolved: literal residue ("Mr.") must not render alone.
        if (!$values) return '';

        if ($has_gap) {
            $text = self::clean($text);
        }
        $text = preg_replace_callback('/\x01(\d+)\x01/', static fn(array $m): string => $values[(int) $m[1]], $text);

        return self::trim($text, $policy);
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
     * Parse a date value in SITE time (CONTEXT.md → Site time), never the
     * server zone. Tries the stored formats first, then a unix timestamp, then
     * anything `strtotime()` accepts. A wall-clock value reads as site time; a
     * timestamp or a string carrying its own zone converts into it. Date-only
     * formats zero the time (`!`) — without it the clock fills hour and minute.
     */
    public static function parse_date(string $value): ?\DateTimeImmutable {
        if (trim($value) === '') return null;
        $tz = wp_timezone();
        foreach (['!Ymd', '!Y-m-d', 'Y-m-d H:i:s', '!d/m/Y'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value, $tz);
            if ($dt !== false) return $dt;
        }
        try {
            $dt = new \DateTimeImmutable(is_numeric($value) ? '@' . (int) $value : $value, $tz);
        } catch (\Exception) {
            return null;
        }
        return $dt->setTimezone($tz);
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
            return $policy->bound((string) $vars[$token]);
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

    /**
     * Cleanup that runs once per pattern holding an empty token — a pattern with
     * none is left exactly as written. Removes every gap, then closes the space a
     * removal left in front of a `,`/`:`; the final trim handles the string's edges.
     */
    private static function clean(string $text): string {
        while (preg_match('/\x02/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = self::remove_gap($text, $m[0][1]);
        }
        return preg_replace('/\s+([,:])/', '$1', preg_replace('/\s{2,}/', ' ', $text));
    }

    /**
     * One empty token, at byte `$pos`, with what only existed to join it:
     *  1. Unit punctuation (`. ' "`) attached after it goes with it.
     *  2. A bracket pair around it (through whitespace) goes whole.
     *  3. Otherwise, by where it sits: before the first resolved token or after the
     *     last one (an EDGE gap), the literal run next to the edge goes with it — the
     *     whole run in front of a trailing gap (bar a quote mark attached to the
     *     survivor), so `Vol. {x}` leaves no orphan label,
     *     and in front of a leading one; a floating separator to the right of a
     *     leading gap goes too. A MIDDLE gap loses a floating separator to its right,
     *     and a `,`/`:` flanking it on both sides collapses to one (the left goes).
     */
    private static function remove_gap(string $text, int $pos): string {
        $len      = strlen($text);
        $start    = $pos;
        $end      = $pos + 1;
        $leading  = !str_contains(substr($text, 0, $pos), "\x01");
        $trailing = !str_contains(substr($text, $pos), "\x01");

        while ($end < $len && in_array($text[$end], ['.', "'", '"'], true)) $end++;

        $bl = $start;
        while ($bl > 0 && ctype_space($text[$bl - 1])) $bl--;
        $br = $end;
        while ($br < $len && ctype_space($text[$br])) $br++;
        $closers = ['(' => ')', '[' => ']'];

        if ($bl > 0 && $br < $len && isset($closers[$text[$bl - 1]]) && $text[$br] === $closers[$text[$bl - 1]]) {
            return substr($text, 0, $bl - 1) . substr($text, $br + 1);
        }

        if ($trailing) {
            $close = strrpos(substr($text, 0, $pos), "\x01");
            $start = $close === false ? 0 : $close + 1;
            while ($start < $pos && in_array($text[$start], ["'", '"'], true)) $start++; // A mark on the survivor (5') stays.
        } else {
            if ($leading) {
                $start = 0;
            } elseif ($bl > 0 && in_array($text[$bl - 1], [',', ':'], true)
                && $br < $len && in_array($text[$br], [',', ':'], true)) {
                $start = $bl - 1;
            }
            foreach (self::SEPARATORS as $sep) {
                if (substr($text, $br, strlen($sep)) === $sep) {
                    $end = $br + strlen($sep);
                    break;
                }
            }
        }

        return substr($text, 0, $start) . substr($text, $end);
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
