<?php

namespace BWS\MetaConductor\Handlers;

if (!defined('ABSPATH')) exit;

/**
 * Computes a post's title and slug from a pattern. A pure applier on the format
 * seam since #64 — it owns no hooks and no request state.
 *
 * WHAT WENT AWAY, AND WHY NONE OF IT IS NEEDED. The handler used to be five
 * request-scoped maps and four hooks straddling the DB write: a pre-write
 * `wp_insert_post_data` filter for rules reading no meta, an `acf/save_post`
 * p99 + `save_post` p99 pair for the rest, a `redirect_post_location` fix for
 * the second path, and dedup flags so the two paths could not both fire.
 * `FormatDispatcher` runs the whole thing once, after the term pass, so:
 *
 *   - the two paths collapse to one, and the flags that separated them
 *     (`$handled_pre_write`, `$processed_in_request`) have nothing to separate;
 *   - `$is_updating_post` guarded re-entry from our own `wp_update_post()`. The
 *     handler no longer writes — the dispatcher does, under the pass lock;
 *   - `$pending_submitted_titles` captured the submitted title BEFORE the row
 *     was written, because the pre-write filter had no other way to see it.
 *     Post-write it is simply `post_title`, which is what the pass reads;
 *   - `$final_slugs` and the redirect fix moved onto the dispatcher, with the
 *     write they exist to compensate for.
 *
 * THE PRE-WRITE PHASE COULD NOT SURVIVE. It ran before terms landed, so a
 * `{term:TAX}` pattern resolved against the PREVIOUS save's terms — and the
 * result is a plausible title, which is why nobody would have found it. The
 * two-phase split returns when `field_transformation` lands (ADR 0003), by
 * which point the pre-write half feeds this same data-in/data-out seam.
 */
class TitleSlugHandler extends UnifiedHandlerBase {

    // -------------------------------------------------------------------------
    // Required abstracts
    // -------------------------------------------------------------------------

    protected function get_rule_type(): string {
        return 'title_slug_rules';
    }

    public function get_handler_type(): string {
        return 'title_slug';
    }

    /**
     * Override base class validation. We only check 'enabled' —
     * action['type'], source_type, target_type are not applicable to title/slug rules.
     */
    protected function validate_rule_internal($rule): bool {
        return !empty($rule['enabled']);
    }

    // Title/slug rules run from the format dispatcher's ordered pass, on the
    // apply_to_data() seam. The base class process_post routes through the
    // generic rule engine which expects action/source_type keys we don't have.
    public function process_post($post_id, $post, $update) {}

    // -------------------------------------------------------------------------
    // Hook registration
    // -------------------------------------------------------------------------

    /**
     * Nothing. `FormatDispatcher` owns the pass and `TermDispatcher` owns the
     * triggers that provoke it, so registering anything here would run this
     * rule type twice — once out of authored order, and once from a hook whose
     * priority is the very ordering accident #64 removed. H13 fails on any
     * registration in this file.
     */
    protected function init_hooks(): void {}

    // -------------------------------------------------------------------------
    // The applier
    // -------------------------------------------------------------------------

    /**
     * Resolve this rule's title and slug patterns against the entity's live
     * state and hand the result on. THE format applier seam (#64).
     *
     * Writes no post row: the dispatcher performs the single update at the end
     * of the pass. It does write the two pieces of per-post state a title
     * pattern needs to stay idempotent, plus the rule's status record, because
     * both are consequences of THIS rule resolving and neither is post data.
     *
     * @param array $data    Post data as the previous rule left it.
     * @param int   $post_id Entity being passed over.
     * @param array $rule    One enabled title/slug rule.
     * @return array|null Post data, or null when the rule names another post type.
     */
    public function apply_to_data(array $data, int $post_id, array $rule): ?array {
        $post_type = (string) ($data['post_type'] ?? '');
        if (!$this->rule_matches($rule, $post_type)) {
            return null;
        }

        // A plain object over the SAME array the seam carries, so a token reads
        // the title an earlier rule computed rather than the row's stored one.
        $post          = (object) $data;
        $current_title = (string) ($data['post_title'] ?? '');
        $current_slug  = (string) ($data['post_name'] ?? '');

        // --- Title ---
        $new_title     = $current_title; // default: unchanged
        $default_title = $current_title;
        if (!empty($rule['title_pattern'])) {
            $default_title = $this->resolve_default_title($post_id, $post, $rule);
            $new_title = $this->resolve_pattern($rule['title_pattern'], $post_id, $post, 'title', $default_title);
            if ($new_title === '') $new_title = $current_title; // never blank a title
        }

        // --- Slug ---
        // {default_slug} is always sanitize_title($new_title) in the current pass —
        // NEVER read from post_name. Slug idempotency derives from title idempotency.
        $new_slug = null;
        $default_slug = sanitize_title($new_title);
        if (!empty($rule['slug_pattern'])) {
            $built = $this->resolve_pattern($rule['slug_pattern'], $post_id, $post, 'slug', $new_title);
            $new_slug = $this->apply_slug_mode($built, $default_slug, $rule['slug_mode'] ?? 'prefix', $rule['slug_pattern'] ?? '');
        } elseif (!empty($rule['title_pattern'])) {
            $new_slug = $default_slug; // implicit: derive slug from computed title
        }

        if ($new_slug !== null) {
            $new_slug = $this->make_unique_slug($new_slug, $post_id, $post, $rule);
        }

        $changed = ($new_title !== $current_title)
                   || ($new_slug !== null && $new_slug !== $current_slug);

        $data['post_title'] = $new_title;
        if ($new_slug !== null) {
            $data['post_name'] = $new_slug;
        }

        // Idempotency state, only when the pattern folds the existing title back
        // into itself. Stored UNCONDITIONALLY — the pass re-runs whether or not
        // anything moved, so what is recorded must be a function of this pass's
        // own resolution rather than of whether it happened to change the row.
        // Recording the *resolved base* rather than the submitted title is what
        // makes the second pass over an unchanged post write the same pair back:
        // the submitted title of a post this rule has already renamed IS the
        // applied title, and storing that as the base would compound it.
        //
        // Both go through as_stored(), because the comparison they exist for is
        // against a value read back OUT of the post row — see that method.
        if (!empty($rule['title_pattern']) && $this->pattern_uses_default_title($rule['title_pattern'])) {
            update_post_meta($post_id, '_bws_raw_title', $this->as_stored($default_title, $post_id));
            update_post_meta($post_id, '_bws_applied_title', $this->as_stored($new_title, $post_id));
        }

        // Status is a record of work done, so it is written only when the rule
        // actually moved something — an idempotent re-pass is not an event.
        if ($changed) {
            $rule_index = (int) ($rule['id'] ?? 0);
            $this->write_rule_status($rule_index, $post_id, $new_title, $new_slug ?? $current_slug, []);
        }

        return $data;
    }

    /**
     * Whether this rule governs the given post type.
     *
     * The dispatcher offers every rule in list order and stops at the first of
     * this type that answers — which is how first-match-wins survives the move
     * off `find_matching_rule()`. It is by design: a title/slug rule set is a
     * lookup table keyed by post type, not a set of independently-scoped rules,
     * so a second rule on one post type never runs (#59).
     *
     * TODO(UI): the config layer should warn when a user creates a second rule
     * for a post_type that already has one — silently ignored today. Add when
     * we next revisit the title/slug config screen.
     *
     * @param array  $rule      One rule.
     * @param string $post_type Entity's post type.
     * @return bool
     */
    private function rule_matches(array $rule, string $post_type): bool {
        return !empty($rule['post_type']) && $rule['post_type'] === $post_type;
    }

    private function pattern_uses_default_title(string $pattern): bool {
        return str_contains($pattern, '{default_title}');
    }

    /**
     * What the post row will actually hold for a title we are about to store.
     *
     * WHY THE IDEMPOTENCY META CANNOT RECORD THE COMPUTED VALUE. `_bws_raw_title`
     * and `_bws_applied_title` exist to answer one question — did the author
     * edit the title, or is this the same title the rule produced last time? —
     * and the value they are compared against is read back OUT of the post row.
     * `wp_insert_post()` runs `sanitize_post()` on the way in, which for a user
     * without `unfiltered_html` (every WP-CLI and cron write included) puts the
     * title through kses: `R&D` is stored as `R&amp;D`. Record the computed
     * form and the comparison misses on the very next read, `try_inverse_strip()`
     * cannot recover a base from a suffix that no longer matches, and the
     * pattern applies a second time — `R&amp;D - x - x`.
     *
     * That was survivable while the two passes were separate requests. Since
     * #64 the format write re-marks the entity and the drain passes it again in
     * the SAME request, so the compounding is immediate. Predicting the stored
     * form here is cheaper and more honest than reading the row back after the
     * write, which would need a post-write seam on an applier defined to write
     * nothing.
     *
     * Slashed in and unslashed out because that is the state `sanitize_post()`
     * sees inside `wp_insert_post()`: the kses filters `stripslashes` their
     * input and `addslashes` their output.
     *
     * @param string $title   Title as this pass computed it.
     * @param int    $post_id Entity the title belongs to.
     * @return string The same title as the row will hold it.
     */
    private function as_stored(string $title, int $post_id): string {
        return (string) wp_unslash(
            sanitize_post_field('post_title', wp_slash($title), $post_id, 'db')
        );
    }

    // -------------------------------------------------------------------------
    // Token resolution engine
    // -------------------------------------------------------------------------

    protected function resolve_pattern(string $pattern, int $post_id, object $post,
                                       string $context, string $computed_title = ''): string {
        $default_title = $computed_title;
        $default_slug  = sanitize_title($computed_title);
        $segments = $this->parse_pattern_segments($pattern);
        $out = $this->build_from_segments($segments, $post_id, $post, $context, $default_title, $default_slug);
        return $this->trim_pattern_output($out, $context);
    }

    private function parse_pattern_segments(string $pattern): array {
        $segments = [];
        $offset = 0;
        preg_match_all('/\{([^}]+)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $i => $match) {
            $token_start  = $matches[0][$i][1];
            $token_end    = $token_start + strlen($matches[0][$i][0]);
            $literal      = substr($pattern, $offset, $token_start - $offset);
            $segments[]   = ['literal' => $literal, 'token' => $match[0]];
            $offset       = $token_end;
        }
        $segments[] = ['literal' => substr($pattern, $offset), 'token' => null]; // trailing literal
        return $segments;
    }

    private function build_from_segments(array $segments, int $post_id, object $post,
                                          string $context, string $default_title,
                                          string $default_slug): string {
        $result = '';
        $pending_literal = '';

        foreach ($segments as $seg) {
            if ($seg['token'] === null) {
                // Trailing literal: only append if we have non-empty result.
                if ($result !== '') $result .= $seg['literal'];
                break;
            }

            $value = $this->resolve_token($seg['token'], $post_id, $post, $context,
                                          $default_title, $default_slug);

            if ($value !== '') {
                $result .= $pending_literal . $seg['literal'] . $value;
                $pending_literal = '';
            } else {
                // Token empty: accumulate preceding literal as pending; drop following literal
                // by not appending seg['literal'] — it gets swallowed with next empty or ignored.
                if ($result !== '') {
                    $pending_literal .= $seg['literal'];
                }
            }
        }

        // pending_literal is a trailing separator from empty tokens — discard it.
        return $result;
    }

    private function resolve_token(string $token, int $post_id, object $post,
                                    string $context, string $default_title,
                                    string $default_slug): string {
        // {default_title} and {default_slug} — never apply duplicate-insertion guard.
        if ($token === 'default_title') return $default_title;
        if ($token === 'default_slug')  return $default_slug;

        $value = match(true) {
            str_starts_with($token, 'meta:')        => $this->get_field_value(substr($token, 5), $post_id),
            str_starts_with($token, 'date_year:')   => $this->get_date_part(substr($token, 10), $post_id, 'year'),
            str_starts_with($token, 'date_month:')  => $this->get_date_part(substr($token, 11), $post_id, $context === 'title' ? 'month_name' : 'month'),
            str_starts_with($token, 'date_day:')    => $this->get_date_part(substr($token, 9), $post_id, 'day'),
            str_starts_with($token, 'date_hour:')   => $this->get_date_part(substr($token, 10), $post_id, 'hour'),
            str_starts_with($token, 'date_minute:') => $this->get_date_part(substr($token, 12), $post_id, 'minute'),
            $token === 'pub_year'    => $this->get_pub_part($post, 'year'),
            $token === 'pub_month'   => $context === 'title'
                                        ? $this->get_pub_part($post, 'month_name')
                                        : $this->get_pub_part($post, 'month'),
            $token === 'pub_day'     => $this->get_pub_part($post, 'day'),
            $token === 'pub_hour'    => $this->get_pub_part($post, 'hour'),
            $token === 'pub_minute'  => $this->get_pub_part($post, 'minute'),
            str_starts_with($token, 'term:')        => $this->get_first_term($post_id, substr($token, 5), $context),
            str_starts_with($token, 'terms:')       => $this->get_all_terms($post_id, substr($token, 6), $context),
            default                                  => '',
        };

        if ($value === '') return '';

        // Duplicate-insertion guard: skip token if its value already appears in the base title/slug.
        if ($context === 'title' && $default_title !== ''
            && mb_stripos($default_title, $value) !== false) {
            return '';
        }
        if ($context === 'slug' && $default_slug !== ''
            && str_contains($default_slug, sanitize_title($value))) {
            return '';
        }

        // In slug context, sanitize all token output.
        return $context === 'slug' ? sanitize_title($value) : $value;
    }

    private function trim_pattern_output(string $result, string $context): string {
        // Strip unmatched trailing opening punctuation.
        $result = preg_replace('/\s*[\(\[\{<]+\s*$/', '', $result);
        // Strip leading separators.
        $result = preg_replace('/^[\s:,\-|\/]+/', '', $result);
        // Strip trailing separators.
        $result = preg_replace('/[\s:,\-|\/]+$/', '', $result);
        // Collapse multiple spaces.
        $result = preg_replace('/\s{2,}/', ' ', $result);
        $result = trim($result);

        if ($context === 'slug') {
            $result = preg_replace('/-{2,}/', '-', $result);
            $result = trim($result, '-');
        }

        return $result;
    }

    private function get_field_value(string $field_name, int $post_id): string {
        $value = get_post_meta($post_id, $field_name, true);
        if (is_array($value) || is_object($value)) {
            error_log(sprintf('BWS Title/Slug Handler: Field returned non-string value (field: %s)', $field_name));
            return '';
        }
        return (string) $value;
    }

    private function parse_date_value(string $value): ?\DateTime {
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

    private function get_date_part(string $field_name, int $post_id, string $part): string {
        $raw = get_post_meta($post_id, $field_name, true);
        if (empty($raw)) return '';
        $dt = $this->parse_date_value((string) $raw);
        if (!$dt) return '';
        return $this->format_date_part($dt, $part);
    }

    private function get_pub_part(object $post, string $part): string {
        // post_date is stored in WP's configured timezone (not PHP's server
        // tz). Bind wp_timezone() so date tokens are correct on hosts where
        // the two differ.
        $dt = new \DateTimeImmutable($post->post_date, wp_timezone());
        return $this->format_date_part($dt, $part);
    }

    private function format_date_part(\DateTimeInterface $dt, string $part): string {
        return match($part) {
            'year'       => $dt->format('Y'),
            'month'      => $dt->format('m'),
            'month_name' => $dt->format('F'),
            'day'        => $dt->format('d'),
            'hour'       => $dt->format('H'),
            'minute'     => $dt->format('i'),
            default      => '',
        };
    }

    private function get_first_term(int $post_id, string $taxonomy, string $context): string {
        $terms = get_the_terms($post_id, $taxonomy);
        if (empty($terms) || is_wp_error($terms)) return '';
        usort($terms, fn($a, $b) => strcmp($a->name, $b->name));
        return $context === 'slug' ? $terms[0]->slug : $terms[0]->name;
    }

    private function get_all_terms(int $post_id, string $taxonomy, string $context): string {
        $terms = get_the_terms($post_id, $taxonomy);
        if (empty($terms) || is_wp_error($terms)) return '';
        usort($terms, fn($a, $b) => strcmp($a->name, $b->name));
        if ($context === 'slug') {
            return implode('-', array_column($terms, 'slug'));
        }
        return implode(', ', array_column($terms, 'name'));
    }

    // -------------------------------------------------------------------------
    // Idempotency: avoid double-application on re-save
    // -------------------------------------------------------------------------

    protected function resolve_default_title(int $post_id, object $post, array $rule): string {
        $pattern = $rule['title_pattern'] ?? '';

        // Short-circuit: if pattern has no {default_title}, all tokens are external —
        // no compounding possible, no meta tracking needed.
        if (!$this->pattern_uses_default_title($pattern)) {
            return (string) $post->post_title;
        }

        // The title the author last submitted. Under the pass this is simply
        // what the row holds: the pass runs AFTER the row is written and before
        // anything of ours has touched it, so the pre-write capture the
        // `wp_insert_post_data` filter needed has nothing left to capture (#64).
        $submitted = (string) $post->post_title;
        $applied   = (string) get_post_meta($post_id, '_bws_applied_title', true);
        $raw       = (string) get_post_meta($post_id, '_bws_raw_title', true);

        // Branch 1: user didn't touch the title field (or source field changed) — use stored raw.
        if ($applied !== '' && $submitted === $applied) {
            return $raw !== '' ? $raw : $submitted;
        }

        // Branch 2: user edited the title — try to recover the base by stripping the
        // rule's computed prefix/suffix (single attempt, case-insensitive).
        $candidate = $this->try_inverse_strip($submitted, $post_id, $post, $rule);
        if ($candidate !== null) {
            return $candidate;
        }

        // Branch 3: entirely new title (or inverse strip failed).
        return $submitted;
    }

    private function try_inverse_strip(string $submitted, int $post_id, object $post,
                                        array $rule): ?string {
        $pattern = $rule['title_pattern'] ?? '';

        // Split pattern at {default_title} to get prefix/suffix portions.
        $dt_pos = mb_strpos($pattern, '{default_title}');
        if ($dt_pos === false) return null;

        $prefix_pattern = mb_substr($pattern, 0, $dt_pos);
        $suffix_pattern = mb_substr($pattern, $dt_pos + mb_strlen('{default_title}'));

        // Resolve prefix/suffix using current field values (no {default_title} token here).
        $computed_prefix = $prefix_pattern !== ''
            ? $this->resolve_pattern($prefix_pattern, $post_id, $post, 'title', '')
            : '';
        $computed_suffix = $suffix_pattern !== ''
            ? $this->resolve_pattern($suffix_pattern, $post_id, $post, 'title', '')
            : '';

        // Case-insensitive strip both ends.
        $candidate = $submitted;
        if ($computed_prefix !== '' && mb_stripos($candidate, $computed_prefix) === 0) {
            $candidate = mb_substr($candidate, mb_strlen($computed_prefix));
        }
        if ($computed_suffix !== '') {
            $lower_candidate = mb_strtolower($candidate);
            $lower_suffix    = mb_strtolower($computed_suffix);
            $suffix_pos      = mb_strlen($candidate) - mb_strlen($computed_suffix);
            if ($suffix_pos >= 0 && mb_substr($lower_candidate, $suffix_pos) === $lower_suffix) {
                $candidate = mb_substr($candidate, 0, $suffix_pos);
            }
        }
        $candidate = trim($candidate);

        if ($candidate === '' || $candidate === $submitted) return null;

        // Verify: re-apply full rule with candidate — result must match submitted.
        $verified = $this->resolve_pattern($pattern, $post_id, $post, 'title', $candidate);
        return ($verified === $submitted) ? $candidate : null;
    }

    // -------------------------------------------------------------------------
    // Slug collision avoidance
    // -------------------------------------------------------------------------

    protected function apply_slug_mode(string $built, string $default_slug, string $mode, string $slug_pattern = ''): string {
        if ($slug_pattern !== '' && str_contains($slug_pattern, '{default_slug}')) {
            $mode = 'replace';
        }
        return match($mode) {
            'prefix'  => trim($built . '-' . $default_slug, '-'),
            'suffix'  => trim($default_slug . '-' . $built, '-'),
            default   => $built, // replace
        };
    }

    protected function make_unique_slug(string $slug, int $post_id, object $post, array $rule): string {
        if ($this->slug_is_unique($slug, $post_id, $post->post_type)) {
            return $slug;
        }
        if (!empty($rule['date_escalation'])) {
            return $this->escalate_date_slug($slug, $post_id, $post, $rule);
        }
        return wp_unique_post_slug($slug, $post_id, $post->post_status, $post->post_type, $post->post_parent);
    }

    private function slug_is_unique(string $slug, int $post_id, string $post_type): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_name = %s AND post_type = %s
               AND post_status NOT IN ('trash','auto-draft') AND ID != %d",
            $slug, $post_type, $post_id
        ));
        return $count === 0;
    }

    private function detect_date_precision(string $pattern): string {
        if (preg_match('/\{date_minute:|pub_minute\}/', $pattern)) return 'minute';
        if (preg_match('/\{date_hour:|pub_hour\}/', $pattern))     return 'hour';
        if (preg_match('/\{date_day:|pub_day\}/', $pattern))       return 'day';
        if (preg_match('/\{date_month:|pub_month\}/', $pattern))   return 'month';
        if (preg_match('/\{date_year:|pub_year\}/', $pattern))     return 'year';
        return 'none';
    }

    private function get_date_parts_for_escalation(array $rule, int $post_id, object $post): array {
        if (!empty($rule['date_field'])) {
            $raw = get_post_meta($post_id, $rule['date_field'], true);
            $dt  = $raw ? $this->parse_date_value((string)$raw) : null;
        } else {
            $dt = new \DateTime($post->post_date); // fallback: publication date (local time)
        }
        if (!$dt) return [];

        return [
            'year'   => $dt->format('Y'),
            'month'  => $dt->format('m'),
            'day'    => $dt->format('d'),
            'hour'   => $dt->format('H'),
            'minute' => $dt->format('i'),
        ];
    }

    private function escalate_date_slug(string $slug, int $post_id, object $post, array $rule): string {
        $pattern   = $rule['slug_pattern'] ?? '';
        $precision = $this->detect_date_precision($pattern);
        if ($precision === 'none' && !empty($rule['title_pattern'])) {
            $precision = $this->detect_date_precision($rule['title_pattern']);
        }
        $parts     = $this->get_date_parts_for_escalation($rule, $post_id, $post);
        if (empty($parts)) {
            return wp_unique_post_slug($slug, $post_id, $post->post_status, $post->post_type, $post->post_parent);
        }

        // Escalation ladder: add progressively more date precision until unique.
        // Parts insert adjacent to existing date portion, not appended to end.
        $precision_order = ['year', 'month', 'day', 'hour', 'minute'];
        $precision_index = array_search($precision, $precision_order, true);
        if ($precision_index === false) {
            return wp_unique_post_slug($slug, $post_id, $post->post_status, $post->post_type, $post->post_parent);
        }

        // Build the anchor: the date string already present in the slug.
        $anchor = '';
        for ($i = 0; $i <= $precision_index; $i++) {
            $key = $precision_order[$i];
            if (!empty($parts[$key])) {
                $anchor .= ($anchor !== '' ? '-' : '') . $parts[$key];
            }
        }

        // Find anchor position in slug to splice after it.
        $anchor_pos = strpos($slug, $anchor);
        if ($anchor_pos === false) {
            return wp_unique_post_slug($slug, $post_id, $post->post_status, $post->post_type, $post->post_parent);
        }
        $before = substr($slug, 0, $anchor_pos + strlen($anchor));
        $after  = substr($slug, $anchor_pos + strlen($anchor));

        // Escalate: insert next date parts between anchor and remainder.
        $extra = '';
        $candidate = $slug;
        for ($i = $precision_index + 1; $i < count($precision_order); $i++) {
            $key = $precision_order[$i];
            if (empty($parts[$key])) continue;
            $extra .= '-' . $parts[$key];
            $candidate = $before . $extra . $after;
            if ($this->slug_is_unique($candidate, $post_id, $post->post_type)) {
                return $candidate;
            }
        }

        return wp_unique_post_slug($candidate, $post_id, $post->post_status, $post->post_type, $post->post_parent);
    }

    // -------------------------------------------------------------------------
    // Status logging
    // -------------------------------------------------------------------------

    protected function write_rule_status(int $rule_index, int $post_id, string $title,
                                         string $slug, array $warnings): void {
        $status = get_option('bws_title_slug_rule_status', []);

        // Always overwrite last-applied (one record per rule).
        $status[$rule_index]['last_applied'] = [
            'timestamp' => current_time('mysql'),
            'post_id'   => $post_id,
            'title'     => $title,
            'slug'      => $slug,
        ];

        // Only log warnings; cap at 10 entries (FIFO).
        if (!empty($warnings)) {
            $log = $status[$rule_index]['warnings'] ?? [];
            foreach ($warnings as $w) {
                $log[] = ['timestamp' => current_time('mysql'), 'post_id' => $post_id, 'message' => $w];
            }
            $status[$rule_index]['warnings'] = array_slice($log, -10);
        }

        update_option('bws_title_slug_rule_status', $status, false); // autoload=false
    }

    // -------------------------------------------------------------------------
    // Public API (stubs)
    // -------------------------------------------------------------------------

    public function preview_rule(array $rule): array {
        $args = ['post_type' => $rule['post_type'], 'posts_per_page' => 1,
                 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'];
        $posts = get_posts($args);
        if (empty($posts)) return ['error' => 'No published posts found for this post type'];

        $post    = $posts[0];
        $post_id = $post->ID;

        // Dry-run: resolve without writing.
        $new_title = $post->post_title;
        if (!empty($rule['title_pattern'])) {
            $default_title = $this->resolve_default_title($post_id, $post, $rule);
            $new_title = $this->resolve_pattern($rule['title_pattern'], $post_id, $post, 'title', $default_title);
        }
        $new_slug = $post->post_name;
        $default_slug = sanitize_title($new_title);
        if (!empty($rule['slug_pattern'])) {
            $built = $this->resolve_pattern($rule['slug_pattern'], $post_id, $post, 'slug', $new_title);
            $new_slug = $this->apply_slug_mode($built, $default_slug, $rule['slug_mode'] ?? 'prefix', $rule['slug_pattern'] ?? '');
        } elseif (!empty($rule['title_pattern'])) {
            $new_slug = $default_slug;
        }

        return [
            'post_id'       => $post_id,
            'post_url'      => get_edit_post_link($post_id),
            'current_title' => $post->post_title,
            'preview_title' => $new_title,
            'current_slug'  => $post->post_name,
            'preview_slug'  => $new_slug,
            'warnings'      => [],
        ];
    }

    /**
     * Bulk apply.
     *
     * Provokes a full ordered FORMAT pass per post rather than applying this
     * handler's rules itself — the same inversion `UnifiedHandlerBase`'s bulk
     * path took for converted term types (#60). Bulk is a provocation: it names
     * posts, and what happens to them is the pass's job, so a bulk run and a
     * save over the same rule set cannot diverge. It is also what keeps
     * `apply_to_data()` to the single call site H13 checks.
     *
     * @param int $batch_size Posts per rule per batch.
     * @param int $offset     Batch offset.
     * @return array
     */
    public function process_existing_posts($batch_size = 50, $offset = 0): array {
        $rules      = $this->get_enabled_rules();
        $dispatcher = \BWS\MetaConductor\Core\FormatDispatcher::instance();
        $processed  = 0;
        $errors     = [];

        foreach ($rules as $rule) {
            if (empty($rule['post_type'])) continue;

            $posts = get_posts([
                'post_type'      => $rule['post_type'],
                'post_status'    => ['publish', 'draft', 'private'],
                'posts_per_page' => $batch_size,
                'offset'         => $offset,
                'fields'         => 'all',
            ]);

            // No dispatcher means no pass ran, so nothing was applied. Counting
            // the post anyway is the "lying button" #31 removed: the caller
            // reports "processed N of N, done" having written nothing.
            if ($dispatcher === null) {
                $errors[] = __('No format dispatcher is registered — nothing was applied.', 'meta-conductor');
                break;
            }

            foreach ($posts as $post) {
                try {
                    $dispatcher->run_pass((int) $post->ID);
                    $processed++;
                } catch (\Exception $e) {
                    $errors[] = "Post {$post->ID}: " . $e->getMessage();
                }
            }
        }

        $total = (int) (new \WP_Query([
            'post_type'      => array_column($rules, 'post_type'),
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]))->found_posts;

        return [
            'processed' => $processed,
            'total'     => $total,
            'done'      => ($offset + $batch_size) >= $total,
            'errors'    => $errors,
        ];
    }
}
