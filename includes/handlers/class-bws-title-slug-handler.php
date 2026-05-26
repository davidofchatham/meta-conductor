<?php
if (!defined('ABSPATH')) exit;

class BWS_Title_Slug_Handler extends BWS_Unified_Handler_Base {

    /** @var bool Prevents re-entry when we call wp_update_post() ourselves */
    private bool $is_updating_post = false;

    /** @var array<int,true> Post IDs processed in this request (prevents dual-hook double-run) */
    private array $processed_in_request = [];

    /** @var array<int,string> Submitted post_title captured before DB write, keyed by post ID */
    private array $pending_submitted_titles = [];

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

    // -------------------------------------------------------------------------
    // Hook registration
    // -------------------------------------------------------------------------

    protected function init_hooks(): void {
        // Capture title before WordPress writes it (priority 1 = before everything).
        // Skip when $is_updating_post so our own wp_update_post() call doesn't overwrite the raw title.
        add_filter('wp_insert_post_data', [$this, 'capture_submitted_title'], 1, 2);

        // Process after ACF commits its fields to postmeta (priority 10).
        add_action('acf/save_post', [$this, 'on_acf_save_post'], 99);

        // Fallback for sites without ACF. Skipped if acf/save_post already ran.
        add_action('save_post', [$this, 'on_save_post'], 99, 3);
    }

    // -------------------------------------------------------------------------
    // Hook callbacks
    // -------------------------------------------------------------------------

    public function capture_submitted_title(array $data, array $postarr): array {
        if ($this->is_updating_post) {
            return $data; // Don't capture our own wp_update_post() writes.
        }
        $post_id = (int) ($postarr['ID'] ?? 0);
        if ($post_id > 0) {
            $this->pending_submitted_titles[$post_id] = $data['post_title'];
        }
        return $data; // Never modify — capture only.
    }

    public function on_acf_save_post($post_id): void {
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) return;
        if ($this->should_skip($post_id, $post)) return;
        $this->processed_in_request[$post_id] = true;
        $this->process_for_post($post_id, $post);
    }

    public function on_save_post(int $post_id, WP_Post $post, bool $update): void {
        if (function_exists('acf')) return; // ACF active — on_acf_save_post handles it.
        if ($this->should_skip($post_id, $post)) return;
        if (isset($this->processed_in_request[$post_id])) return;
        $this->processed_in_request[$post_id] = true;
        $this->process_for_post($post_id, $post);
    }

    // -------------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------------

    private function should_skip(int $post_id, WP_Post $post): bool {
        if ($this->is_updating_post)                return true;
        if (isset($this->processed_in_request[$post_id])) return true;
        if (wp_is_post_autosave($post_id))          return true;
        if (wp_is_post_revision($post_id))          return true;
        if (in_array($post->post_status, ['auto-draft', 'trash'], true)) return true;
        return false;
    }

    // -------------------------------------------------------------------------
    // Core orchestration
    // -------------------------------------------------------------------------

    private function process_for_post(int $post_id, WP_Post $post): void {
        $rules = $this->get_enabled_rules();
        $rule  = $this->find_matching_rule($post, $rules);
        if (!$rule) return;

        // --- Title ---
        $new_title = $post->post_title; // default: unchanged
        if (!empty($rule['title_pattern'])) {
            $default_title = $this->resolve_default_title($post_id, $post, $rule);
            $new_title = $this->resolve_pattern($rule['title_pattern'], $post_id, $post, 'title', $default_title);
            if ($new_title === '') $new_title = $post->post_title; // never blank a title
        }

        // --- Slug ---
        // {default_slug} is always sanitize_title($new_title) in the current pass —
        // NEVER read from $post->post_name. Slug idempotency derives from title idempotency.
        $new_slug = null;
        $default_slug = sanitize_title($new_title);
        if (!empty($rule['slug_pattern'])) {
            $built = $this->resolve_pattern($rule['slug_pattern'], $post_id, $post, 'slug', $new_title);
            $new_slug = $this->apply_slug_mode($built, $default_slug, $rule['slug_mode'] ?? 'prefix');
        } elseif (!empty($rule['title_pattern'])) {
            $new_slug = $default_slug; // implicit: derive slug from computed title
        }

        if ($new_slug !== null) {
            $new_slug = $this->make_unique_slug($new_slug, $post_id, $post, $rule);
        }

        // Early exit if nothing changed.
        $title_changed = ($new_title !== $post->post_title);
        $slug_changed  = ($new_slug !== null && $new_slug !== $post->post_name);
        if (!$title_changed && !$slug_changed) return;

        // Write — suppress the extra revision our update would otherwise create.
        $this->is_updating_post = true;
        add_filter('wp_save_post_revision_post_has_changed', '__return_false');

        $update_data = ['ID' => $post_id];
        if ($title_changed) $update_data['post_title'] = $new_title;
        if ($slug_changed)  $update_data['post_name']  = $new_slug;
        wp_update_post($update_data);

        remove_filter('wp_save_post_revision_post_has_changed', '__return_false');
        $this->is_updating_post = false;

        // Store idempotency meta (only needed when {default_title} is in pattern).
        if (!empty($rule['title_pattern']) && $this->pattern_uses_default_title($rule['title_pattern'])) {
            $raw_used = $this->pending_submitted_titles[$post_id]
                        ?? get_post_meta($post_id, '_bws_raw_title', true)
                        ?? $post->post_title;
            update_post_meta($post_id, '_bws_raw_title', $raw_used);
            update_post_meta($post_id, '_bws_applied_title', $new_title);
        }

        // Log status / warnings.
        $rule_index = (int) ($rule['id'] ?? 0);
        $this->write_rule_status($rule_index, $post_id, $new_title, $new_slug ?? $post->post_name, []);
    }

    private function find_matching_rule(WP_Post $post, array $rules): ?array {
        foreach ($rules as $rule) {
            if (!empty($rule['post_type']) && $rule['post_type'] === $post->post_type) {
                return $rule;
            }
        }
        return null;
    }

    private function pattern_uses_default_title(string $pattern): bool {
        return str_contains($pattern, '{default_title}');
    }

    // -------------------------------------------------------------------------
    // Token resolution engine
    // -------------------------------------------------------------------------

    protected function resolve_pattern(string $pattern, int $post_id, WP_Post $post,
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

    private function build_from_segments(array $segments, int $post_id, WP_Post $post,
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

    private function resolve_token(string $token, int $post_id, WP_Post $post,
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

    private function parse_date_value(string $value): ?DateTime {
        foreach (['Ymd', 'Y-m-d', 'Y-m-d H:i:s', 'd/m/Y'] as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt !== false) return $dt;
        }
        // Unix timestamp fallback.
        if (is_numeric($value)) {
            return (new DateTime())->setTimestamp((int)$value);
        }
        $ts = strtotime($value);
        return $ts !== false ? (new DateTime())->setTimestamp($ts) : null;
    }

    private function get_date_part(string $field_name, int $post_id, string $part): string {
        $raw = get_post_meta($post_id, $field_name, true);
        if (empty($raw)) return '';
        $dt = $this->parse_date_value((string) $raw);
        if (!$dt) return '';
        return $this->format_date_part($dt, $part);
    }

    private function get_pub_part(WP_Post $post, string $part): string {
        // Uses post_date (local time), not post_date_gmt.
        $dt = new DateTime($post->post_date);
        return $this->format_date_part($dt, $part);
    }

    private function format_date_part(DateTime $dt, string $part): string {
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

    protected function resolve_default_title(int $post_id, WP_Post $post, array $rule): string {
        $pattern = $rule['title_pattern'] ?? '';

        // Short-circuit: if pattern has no {default_title}, all tokens are external —
        // no compounding possible, no meta tracking needed.
        if (!$this->pattern_uses_default_title($pattern)) {
            return $post->post_title;
        }

        $submitted = $this->pending_submitted_titles[$post_id] ?? $post->post_title;
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

    private function try_inverse_strip(string $submitted, int $post_id, WP_Post $post,
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

    protected function apply_slug_mode(string $built, string $default_slug, string $mode): string {
        return match($mode) {
            'prefix'  => trim($built . '-' . $default_slug, '-'),
            'suffix'  => trim($default_slug . '-' . $built, '-'),
            default   => $built, // replace
        };
    }

    protected function make_unique_slug(string $slug, int $post_id, WP_Post $post, array $rule): string {
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

    private function get_date_parts_for_escalation(array $rule, int $post_id, WP_Post $post): array {
        if (!empty($rule['date_field'])) {
            $raw = get_post_meta($post_id, $rule['date_field'], true);
            $dt  = $raw ? $this->parse_date_value((string)$raw) : null;
        } else {
            $dt = new DateTime($post->post_date); // fallback: publication date (local time)
        }
        if (!$dt) return [];

        return [
            'month'  => $dt->format('m'),
            'day'    => $dt->format('d'),
            'hour'   => $dt->format('H'),
            'minute' => $dt->format('i'),
        ];
    }

    private function escalate_date_slug(string $slug, int $post_id, WP_Post $post, array $rule): string {
        $pattern   = $rule['slug_pattern'] ?? '';
        $precision = $this->detect_date_precision($pattern);
        $parts     = $this->get_date_parts_for_escalation($rule, $post_id, $post);
        if (empty($parts)) {
            return wp_unique_post_slug($slug, $post_id, $post->post_status, $post->post_type, $post->post_parent);
        }

        // Escalation ladder: add progressively more date precision until unique.
        $ladder = match($precision) {
            'year'  => ['month', 'day', 'hour', 'minute'],
            'month' => ['day', 'hour', 'minute'],
            'day'   => ['hour', 'minute'],
            'hour'  => ['minute'],
            default => [],
        };

        $candidate = $slug;
        foreach ($ladder as $part) {
            if (empty($parts[$part])) continue;
            $candidate = $slug . '-' . $parts[$part];
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

    public function validate_rule($rule_data): array {
        $errors = [];
        if (empty($rule_data['post_type'])) $errors[] = 'Post type is required';
        if (empty($rule_data['title_pattern']) && empty($rule_data['slug_pattern']))
            $errors[] = 'At least one of title pattern or slug pattern is required';
        return ['valid' => empty($errors), 'errors' => $errors];
    }

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
            $new_slug = $this->apply_slug_mode($built, $default_slug, $rule['slug_mode'] ?? 'prefix');
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

    public function process_existing_posts($batch_size = 50, $offset = 0): array {
        $rules     = $this->get_enabled_rules();
        $processed = 0;
        $errors    = [];

        foreach ($rules as $rule) {
            if (empty($rule['post_type'])) continue;

            $posts = get_posts([
                'post_type'      => $rule['post_type'],
                'post_status'    => ['publish', 'draft', 'private'],
                'posts_per_page' => $batch_size,
                'offset'         => $offset,
                'fields'         => 'all',
            ]);

            foreach ($posts as $post) {
                try {
                    $this->process_for_post($post->ID, $post);
                    $this->processed_in_request = []; // reset guard between posts
                    $processed++;
                } catch (Exception $e) {
                    $errors[] = "Post {$post->ID}: " . $e->getMessage();
                }
            }
        }

        $total = (int) (new WP_Query([
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
