<?php
/**
 * Related Post Terms (ACF Reference) handler.
 *
 * Copies taxonomy terms between a post and the posts it relates to via an ACF
 * relationship / post-object field, using a DECLARATIVE source-authoritative
 * model (no per-application tracking meta): a dependent's terms in a taxonomy
 * are recomputed each sync as the union of terms derivable from its valid
 * sources across all enabled rules. (SPEC §V3)
 *
 * Direction is holder-relative (SPEC §V1): the ACF field pins the holder post
 * type; `holder_role` says which end is authoritative. source = push the
 * holder's terms out to related posts; target = pull related posts' terms onto
 * the holder.
 *
 * @since 0.1.0
 */

namespace BWS\MetaConductor\Handlers;

if (!defined('ABSPATH')) {
    exit;
}

class RelatedPostTermsHandler extends UnifiedHandlerBase {

    protected $handler_type = 'related_post_terms';

    /**
     * Re-entrancy: post IDs currently inside a sync write. Guards the
     * set_object_terms cascade alongside the idempotent short-circuit. (SPEC §V11)
     *
     * @var array<int,bool>
     */
    private array $in_sync = [];

    public function get_handler_type() {
        return $this->handler_type;
    }

    protected function get_rule_type() {
        return 'related_post_terms_rules';
    }

    /**
     * Per-request capture of dependents REMOVED from a source's relationship
     * field this save, keyed by source post ID. Filled by capture_removed_*
     * on acf/update_value (which sees old vs new), drained in on_acf_save_post.
     * Enables source-side sever strip without per-term tracking. (SPEC §V14)
     *
     * @var array<int,int[]>
     */
    private array $severed = [];

    protected function init_hooks() {
        // A post was saved: it may be a SOURCE (push to its dependents) or a
        // DEPENDENT (recompute itself from its sources). Both handled. (SPEC §V4)
        add_action('acf/save_post', [$this, 'on_acf_save_post'], 30);
        add_action('save_post', [$this, 'on_post_save'], 25, 1);

        // A post's terms changed: if it is a source, propagate to its
        // dependents. (SPEC §V4)
        add_action('set_object_terms', [$this, 'on_terms_changed'], 15, 4);

        // Capture dependents removed from a relationship field this save, so we
        // can withdraw the source's contribution from them while the source is
        // still known. acf/update_value fires BEFORE the new value is written,
        // so get_field() still returns the OLD value here. (SPEC §V14)
        add_filter('acf/update_value/type=relationship', [$this, 'capture_removed_dependents'], 5, 3);
        add_filter('acf/update_value/type=post_object', [$this, 'capture_removed_dependents'], 5, 3);
    }

    // ---------------------------------------------------------------------
    // Triggers (SPEC §V4)
    // ---------------------------------------------------------------------

    public function on_acf_save_post($post_id): void {
        if (!is_numeric($post_id)) {
            return;
        }
        $post_id = (int) $post_id;
        $this->sync_for_post($post_id);
        // Withdraw the source's contribution from any dependent it just
        // dropped from its relationship field. (SPEC §V14)
        $this->process_severed($post_id);
    }

    /**
     * acf/update_value filter (priority 5, before the value is written): if
     * this field is a relationship field used by some enabled rule, diff the
     * OLD value (still readable via get_field here) against the new value and
     * record the removed dependent IDs for this source. (SPEC §V14)
     *
     * @param mixed $value    New field value (returned unchanged).
     * @param int   $post_id  Source post being saved.
     * @param array $field    ACF field array.
     * @return mixed
     */
    public function capture_removed_dependents($value, $post_id, $field) {
        $post_id    = (int) $post_id;
        $field_name = (string) ($field['name'] ?? '');
        if ($field_name === '' || !$this->field_used_by_rule($field_name)) {
            return $value;
        }

        $old = $this->read_relationship($post_id, $field_name);
        if (empty($old)) {
            return $value;
        }

        $new = $this->extract_ids($value);
        $removed = array_values(array_diff($old, $new));
        if (!empty($removed)) {
            $existing = $this->severed[$post_id] ?? [];
            $this->severed[$post_id] = array_values(array_unique(array_merge($existing, $removed)));
        }

        return $value;
    }

    /**
     * Recompute each dependent the given source just dropped. The dependent
     * now resolves zero sources under this source's rule, so a normal
     * recompute would skip it (V13). Force the keep-in-sync replace so the
     * severed source's terms are withdrawn. (SPEC §V14)
     */
    private function process_severed(int $source_id): void {
        if (empty($this->severed[$source_id])) {
            return;
        }
        $removed = $this->severed[$source_id];
        unset($this->severed[$source_id]);

        $rules = $this->get_enabled_rules();

        // Taxonomies that have at least one keep-in-sync rule (add-only never
        // removes, so a severed source can't strip those).
        $sync_taxes = [];
        foreach ($rules as $rule) {
            $taxonomy = (string) ($rule['taxonomy'] ?? '');
            if ($taxonomy !== '' && !empty($rule['keep_in_sync'])) {
                $sync_taxes[$taxonomy] = true;
            }
        }
        if (empty($sync_taxes)) {
            return;
        }

        foreach ($removed as $dep_id) {
            foreach (array_keys($sync_taxes) as $taxonomy) {
                // Force replace from REMAINING sources: other valid sources'
                // terms survive; if none remain, the dependent is emptied
                // (severed source withdrawn). (§V14)
                $this->recompute_dependent((int) $dep_id, $taxonomy, $rules, true);
            }
        }
    }

    /** Whether any enabled rule uses $field_name as its forward or reverse field. */
    private function field_used_by_rule(string $field_name): bool {
        foreach ($this->get_enabled_rules() as $rule) {
            if ((string) ($rule['acf_field_name'] ?? '') === $field_name
                || (string) ($rule['reverse_acf_field_name'] ?? '') === $field_name) {
                return true;
            }
        }
        return false;
    }

    public function on_post_save($post_id): void {
        $post_id = (int) $post_id;
        if (\wp_is_post_autosave($post_id) || \wp_is_post_revision($post_id)) {
            return;
        }
        $this->sync_for_post($post_id);
    }

    /**
     * @param int    $object_id Post whose terms changed.
     * @param array  $terms     Unused.
     * @param array  $tt_ids    Unused.
     * @param string $taxonomy  Taxonomy that changed.
     */
    public function on_terms_changed($object_id, $terms, $tt_ids, $taxonomy): void {
        $object_id = (int) $object_id;
        // Skip writes we are making ourselves (cascade guard). (SPEC §V11)
        if (!empty($this->in_sync[$object_id])) {
            return;
        }
        $this->sync_for_post($object_id, (string) $taxonomy);
    }

    // ---------------------------------------------------------------------
    // Orchestration
    // ---------------------------------------------------------------------

    /**
     * Given a post that changed, find every DEPENDENT affected (the post
     * itself if it is a dependent under some rule, and the dependents it is a
     * source for) and recompute each. (SPEC §V3/§V4)
     *
     * @param int    $post_id        Post that triggered the sync.
     * @param string $only_taxonomy  Optional: limit to this taxonomy (terms-change trigger).
     */
    private function sync_for_post(int $post_id, string $only_taxonomy = ''): void {
        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $rules = $this->get_enabled_rules();
        if (empty($rules)) {
            return;
        }

        // Collect the set of (dependent_post_id, taxonomy) pairs to recompute.
        $dependents = [];

        foreach ($rules as $rule) {
            $taxonomy = (string) ($rule['taxonomy'] ?? '');
            if ($taxonomy === '' || ($only_taxonomy !== '' && $taxonomy !== $only_taxonomy)) {
                continue;
            }

            // Is THIS post a dependent under this rule? (It receives terms.)
            if ($this->post_type_matches($post, $this->dependent_post_type($rule))) {
                $dependents[$post_id][$taxonomy] = true;
            }

            // Is THIS post a source under this rule? Then its dependents must
            // recompute. (push: holder is source → its related posts; pull:
            // related posts are sources → the holder that references them.)
            if ($this->post_type_matches($post, $this->source_post_type($rule))) {
                foreach ($this->dependents_of_source($post_id, $rule) as $dep_id) {
                    $dependents[$dep_id][$taxonomy] = true;
                }
            }
        }

        foreach ($dependents as $dep_id => $taxes) {
            foreach (array_keys($taxes) as $taxonomy) {
                $this->recompute_dependent((int) $dep_id, (string) $taxonomy, $rules);
            }
        }
    }

    /**
     * Recompute one dependent's terms in one taxonomy from the union of all
     * enabled rules' valid sources. Source-authoritative, declarative,
     * idempotent. (SPEC §V3/§V5/§V11)
     */
    private function recompute_dependent(int $dependent_id, string $taxonomy, array $rules, bool $force_sync = false): void {
        $authoritative = [];
        $any_sync      = $force_sync; // §V14 sever: replace even at zero sources
        $source_count  = 0; // resolved sources across all applicable rules (SPEC §V13)

        $dep_post = \get_post($dependent_id);
        if (!$dep_post instanceof \WP_Post) {
            return;
        }

        foreach ($rules as $rule) {
            if ((string) ($rule['taxonomy'] ?? '') !== $taxonomy) {
                continue;
            }
            // Post type must be eligible as a dependent ('' = any, e.g. push).
            if (!$this->post_type_matches($dep_post, $this->dependent_post_type($rule))) {
                continue;
            }

            // V13: the rule "manages" this dependent ONLY if it resolves a
            // real source for it. Type-match alone is NOT enough — a push
            // rule's dependent type is '' (any), which would otherwise treat
            // every saved post as a managed dependent and wipe it.
            $sources = $this->sources_of_dependent($dependent_id, $rule);
            if (empty($sources)) {
                continue;
            }

            $source_count += count($sources);
            if (!empty($rule['keep_in_sync'])) {
                $any_sync = true;
            }

            foreach ($sources as $source_id) {
                // Source-scoped status gate (SPEC §V5). A gated-out source
                // still counts as a resolved source (V13) — its terms just
                // don't contribute — so a published dependent of a draft
                // source is sync-emptied, NOT skipped-as-unmanaged.
                if (!$this->source_status_passes($source_id, $rule)) {
                    continue;
                }
                $src_terms = \wp_get_object_terms($source_id, $taxonomy, ['fields' => 'ids']);
                if (!\is_wp_error($src_terms)) {
                    foreach ($src_terms as $tid) {
                        $authoritative[(int) $tid] = true;
                    }
                }
            }
        }

        // V13: no resolved source under ANY rule ⇒ this post is not managed
        // here ⇒ leave its terms untouched. Empty-replace is permitted only
        // when sources exist but yield no terms (legit sync-to-empty).
        // EXCEPTION (§V14): a forced sever recompute writes even at zero
        // sources — the dependent was just orphaned and must lose the
        // withdrawn source's terms (computed from remaining sources, if any).
        if ($source_count === 0 && !$force_sync) {
            return;
        }

        $authoritative = array_keys($authoritative);

        if ($any_sync) {
            // keep-in-sync ⇒ final = authoritative (rule-union replace). (§V3)
            $this->write_terms($dependent_id, $taxonomy, $authoritative, true);
        } elseif (!empty($authoritative)) {
            // add-only ⇒ never removes. (§V3)
            $this->write_terms($dependent_id, $taxonomy, $authoritative, false);
        }
    }

    /**
     * Write terms with idempotent short-circuit + cascade guard. (SPEC §V11)
     *
     * @param int   $post_id  Dependent post.
     * @param string $taxonomy Taxonomy.
     * @param int[] $terms    Authoritative term IDs.
     * @param bool  $replace  true = set exactly (keep-in-sync); false = merge (add-only).
     */
    private function write_terms(int $post_id, string $taxonomy, array $terms, bool $replace): void {
        $terms   = array_values(array_unique(array_map('intval', $terms)));
        $current = \wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (\is_wp_error($current)) {
            $current = [];
        }
        $current = array_map('intval', $current);

        if ($replace) {
            sort($terms);
            $cmp = $current;
            sort($cmp);
            if ($terms === $cmp) {
                return; // idempotent no-op → breaks the cascade
            }
            $this->in_sync[$post_id] = true;
            \wp_set_object_terms($post_id, $terms, $taxonomy);
            unset($this->in_sync[$post_id]);

            $this->debug_log(
                sprintf('ACF-ref sync (replace) post %d tax %s', $post_id, $taxonomy),
                ['terms' => $terms]
            );
        } else {
            $merged = array_values(array_unique(array_merge($current, $terms)));
            sort($merged);
            $cmp = $current;
            sort($cmp);
            if ($merged === $cmp) {
                return; // already a superset → no-op
            }
            $this->in_sync[$post_id] = true;
            \wp_set_object_terms($post_id, $merged, $taxonomy);
            unset($this->in_sync[$post_id]);

            $this->debug_log(
                sprintf('ACF-ref sync (add) post %d tax %s', $post_id, $taxonomy),
                ['terms' => $terms]
            );
        }
    }

    // ---------------------------------------------------------------------
    // Direction model (SPEC §V1) — resolve source/dependent ends per rule
    // ---------------------------------------------------------------------

    /** The post type that OWNS the ACF field (the holder). */
    private function holder_post_type(array $rule): string {
        return (string) ($rule['post_type'] ?? '');
    }

    /** Holder is source when holder_role=source (push); else target (pull). */
    private function holder_is_source(array $rule): bool {
        return (($rule['holder_role'] ?? 'target') === 'source');
    }

    /**
     * Post type of the SOURCE end (terms come FROM here). Empty string ⇒ "any"
     * (the related posts side, whose type we don't constrain).
     */
    private function source_post_type(array $rule): string {
        // holder is source → source type = holder. holder is target → source =
        // the related posts (unconstrained type).
        return $this->holder_is_source($rule) ? $this->holder_post_type($rule) : '';
    }

    /**
     * Post type of the DEPENDENT end (terms are written here). Empty ⇒ any.
     */
    private function dependent_post_type(array $rule): string {
        return $this->holder_is_source($rule) ? '' : $this->holder_post_type($rule);
    }

    /**
     * The dependents that a given SOURCE post feeds, under this rule.
     *
     * push (holder=source): the source IS the holder → read its ACF field →
     *   the related posts are dependents.
     * pull (holder=target): the source is a related post → find the holders
     *   that reference it (reverse lookup) → those holders are dependents.
     *
     * @return int[]
     */
    private function dependents_of_source(int $source_id, array $rule): array {
        if ($this->holder_is_source($rule)) {
            return $this->read_relationship($source_id, (string) $rule['acf_field_name']);
        }
        return $this->resolve_reverse($source_id, $rule);
    }

    /**
     * The sources that feed a given DEPENDENT post, under this rule.
     *
     * push (holder=source): the dependent is a related post → find the holders
     *   that reference it (reverse lookup) → those holders are sources.
     * pull (holder=target): the dependent IS the holder → read its ACF field →
     *   the related posts are sources.
     *
     * @return int[]
     */
    private function sources_of_dependent(int $dependent_id, array $rule): array {
        if ($this->holder_is_source($rule)) {
            return $this->resolve_reverse($dependent_id, $rule);
        }
        return $this->read_relationship($dependent_id, (string) $rule['acf_field_name']);
    }

    // ---------------------------------------------------------------------
    // Reverse-field resolution — three tiers (SPEC §V6)
    // ---------------------------------------------------------------------

    /**
     * Resolve the holder posts whose relationship field points at $related_id.
     * Tier 1 explicit reverse field → tier 2 ACF native bidi → tier 3 meta_query.
     *
     * @return int[]
     */
    private function resolve_reverse(int $related_id, array $rule): array {
        // Tier 1: explicit reverse field on the related post.
        $reverse = (string) ($rule['reverse_acf_field_name'] ?? '');
        if ($reverse !== '') {
            return $this->read_relationship($related_id, $reverse);
        }

        // Tier 2: ACF native bidirectional — the field's partner key. Wrapped
        // defensively; absent/old ACF or no bidi config falls through silently.
        $partner = $this->acf_bidirectional_partner((string) $rule['acf_field_name']);
        if ($partner !== '') {
            return $this->read_relationship($related_id, $partner);
        }

        // Tier 3: meta_query fallback (slow; correct).
        return $this->find_holders_referencing($related_id, $rule);
    }

    /**
     * Read an ACF relationship/post-object field → array of related post IDs.
     *
     * @return int[]
     */
    private function read_relationship(int $post_id, string $field_name): array {
        if ($field_name === '' || !function_exists('get_field')) {
            return [];
        }
        return $this->extract_ids(\get_field($field_name, $post_id));
    }

    /**
     * Normalize an ACF relationship/post-object value (array of WP_Post,
     * array of IDs, single object, or single ID) to a unique int[] of post IDs.
     *
     * @param mixed $value
     * @return int[]
     */
    private function extract_ids($value): array {
        if (empty($value)) {
            return [];
        }
        $ids = [];
        $items = is_array($value) ? $value : [$value];
        foreach ($items as $item) {
            if (is_object($item) && isset($item->ID)) {
                $ids[] = (int) $item->ID;
            } elseif (is_numeric($item)) {
                $ids[] = (int) $item;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The ACF native-bidirectional partner field name, or '' if none / ACF
     * unavailable. Defensive: never fatals on old/absent ACF. (SPEC §V6 tier 2)
     */
    private function acf_bidirectional_partner(string $field_name): string {
        if ($field_name === '' || !function_exists('acf_get_field')) {
            return '';
        }
        $field = \acf_get_field($field_name);
        if (!is_array($field) || empty($field['bidirectional'])) {
            return '';
        }
        $targets = $field['bidirectional_target'] ?? [];
        if (!is_array($targets) || empty($targets)) {
            return '';
        }
        // bidirectional_target holds field KEYS; resolve the first to its name.
        $partner = \acf_get_field($targets[0]);
        return (is_array($partner) && !empty($partner['name'])) ? (string) $partner['name'] : '';
    }

    /**
     * Tier 3 fallback: holders whose relationship field references $related_id.
     * meta_query LIKE + per-result verification (false-positive prone). (SPEC §V6)
     *
     * @return int[]
     */
    private function find_holders_referencing(int $related_id, array $rule): array {
        $holder_type = $this->holder_post_type($rule);
        $field_name  = (string) $rule['acf_field_name'];
        if ($holder_type === '' || $field_name === '') {
            return [];
        }

        $candidates = \get_posts([
            'post_type'      => $holder_type,
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => $field_name,
                'value'   => '"' . $related_id . '"',
                'compare' => 'LIKE',
            ]],
        ]);

        $matches = [];
        foreach ($candidates as $cid) {
            $cid = (int) $cid;
            if (in_array($related_id, $this->read_relationship($cid, $field_name), true)) {
                $matches[] = $cid;
            }
        }
        return $matches;
    }

    // ---------------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------------

    /** Source-status gate (SPEC §V5). Empty gate ⇒ any status passes. */
    private function source_status_passes(int $source_id, array $rule): bool {
        $statuses = $this->status_gate($rule);
        if (empty($statuses)) {
            return true;
        }
        return in_array(\get_post_status($source_id), $statuses, true);
    }

    /**
     * Normalize the post_status gate (Wireframe checkboxes {slug:bool} or list)
     * to a list of slugs. Empty ⇒ no filter.
     *
     * @return string[]
     */
    private function status_gate(array $rule): array {
        $raw = $rule['post_status'] ?? [];
        if (empty($raw) || !is_array($raw)) {
            return [];
        }
        return array_is_list($raw) ? $raw : array_keys(array_filter($raw));
    }

    /** Whether a post's type matches a required type ('' ⇒ any). */
    private function post_type_matches(\WP_Post $post, string $required_type): bool {
        return $required_type === '' || $post->post_type === $required_type;
    }
}
