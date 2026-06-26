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
     * Re-entrancy: (post_id, taxonomy) pairs currently inside a sync write,
     * keyed `in_sync[$post_id][$taxonomy]`. Guards the set_object_terms cascade
     * alongside the idempotent short-circuit. Keyed by taxonomy (not just post)
     * so that if another plugin cross-links a DIFFERENT taxonomy on the same
     * post mid-write, that taxonomy's sync is not wrongly suppressed. (SPEC §V11;
     * PR#24 round 2 #6)
     *
     * @var array<int,array<string,bool>>
     */
    private array $in_sync = [];

    public function get_handler_type() {
        return $this->handler_type;
    }

    protected function get_rule_type() {
        return 'related_post_terms_rules';
    }

    /**
     * Per-request capture of DEPENDENTS removed from a PUSH source's
     * relationship field this save, keyed by source post ID THEN taxonomy:
     * `severed[source_id][taxonomy] = [removed_dependent_ids]`. Filled by
     * capture_removed_dependents on acf/update_value (which sees old vs new),
     * drained in on_acf_save_post. Enables source-side sever strip without
     * per-term tracking. (SPEC §V14)
     *
     * PUSH-ONLY by construction: for a pull rule the field lists the holder's
     * SOURCES, not dependents — removing one is already handled by the holder's
     * own sync_for_post (it recomputes from remaining sources), and treating a
     * removed source as a dependent would wipe that source's terms (PR#24 Bug 1).
     * Keyed by taxonomy so a severed source only strips the taxonomy IT feeds,
     * not every keep_in_sync taxonomy site-wide (PR#24 Bug 2).
     *
     * @var array<int,array<string,int[]>>
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

        // A PUSH source is being permanently deleted: its dependents must lose
        // its contribution. No acf/update_value fires on delete, so CAPTURE the
        // whole relationship as "removed" while the source still exists
        // (before_delete_post), then STRIP after it's gone (deleted_post) — once
        // the dying source no longer resolves as a remaining source of its
        // dependents. (SPEC §V14; PR#24 round 2 #4)
        add_action('before_delete_post', [$this, 'on_before_delete_post'], 10, 1);
        add_action('deleted_post', [$this, 'on_deleted_post'], 10, 1);
    }

    // ---------------------------------------------------------------------
    // Triggers (SPEC §V4)
    // ---------------------------------------------------------------------

    public function on_acf_save_post($post_id): void {
        if (!is_numeric($post_id)) {
            return;
        }
        $post_id = (int) $post_id;
        // Older ACF fires acf/save_post for autosave/revision IDs; skip them so
        // sync never runs against an autosave object. Mirrors on_post_save.
        // (PR#24 round 2 #5)
        if (\wp_is_post_autosave($post_id) || \wp_is_post_revision($post_id)) {
            return;
        }
        $this->sync_for_post($post_id);
        // Withdraw the source's contribution from any dependent it just
        // dropped from its relationship field. (SPEC §V14)
        $this->process_severed($post_id);
    }

    /**
     * A post is being permanently deleted. If it is a PUSH source, CAPTURE its
     * entire current relationship as "removed" while the post still exists — no
     * acf/update_value fires on delete. The actual strip runs in on_deleted_post
     * (after the source is gone, so it no longer resolves as a remaining source
     * of its dependents). Mirrors capture_removed_dependents' push+keep_in_sync+
     * per-taxonomy scoping. (SPEC §V14; PR#24 round 2 #4)
     *
     * @param int $post_id Post being deleted.
     */
    public function on_before_delete_post($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return;
        }

        foreach ($this->get_enabled_rules() as $rule) {
            if (!$this->holder_is_source($rule) || empty($rule['keep_in_sync'])) {
                continue;
            }
            $taxonomy   = (string) ($rule['taxonomy'] ?? '');
            $field_name = (string) ($rule['acf_field_name'] ?? '');
            if ($taxonomy === '' || $field_name === '') {
                continue;
            }
            // Only act if THIS post is a holder of the rule's field.
            if (!$this->post_type_matches($post, $this->holder_post_type($rule))) {
                continue;
            }
            $dependents = $this->read_relationship($post_id, $field_name);
            if (empty($dependents)) {
                continue;
            }
            $existing = $this->severed[$post_id][$taxonomy] ?? [];
            $this->severed[$post_id][$taxonomy] = array_values(
                array_unique(array_merge($existing, $dependents))
            );
        }
    }

    /**
     * The post is now deleted. Strip the captured dependents: with the source
     * gone, recompute_dependent's reverse lookup resolves only the dependents'
     * REMAINING sources, so the deleted source's terms correctly withdraw.
     * (SPEC §V14; PR#24 round 2 #4)
     *
     * @param int $post_id Deleted post.
     */
    public function on_deleted_post($post_id): void {
        $this->process_severed((int) $post_id);
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
        if ($field_name === '') {
            return $value;
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return $value;
        }

        // Only PUSH + keep_in_sync rules whose forward field is THIS field matter
        // here: their field value lists the holder's dependents, so a removed
        // entry is a dependent to strip. Pull rules (field lists sources) are
        // skipped — sync_for_post on the holder already handles their sever
        // (PR#24 Bug 1). Add-only rules never remove, so a sever can't strip
        // them — skip too (PR#24 Bug 3). The post must also be of the rule's
        // HOLDER type: ACF field names aren't unique across post types, so a
        // same-named field on a DIFFERENT post type could otherwise match a
        // rule and force_sync-wipe unrelated dependents (PR#24 round 3 Bug 1).
        $push_rules = [];
        foreach ($this->get_enabled_rules() as $rule) {
            if ($this->holder_is_source($rule)
                && !empty($rule['keep_in_sync'])
                && (string) ($rule['acf_field_name'] ?? '') === $field_name
                && $this->post_type_matches($post, $this->holder_post_type($rule))) {
                $push_rules[] = $rule;
            }
        }
        if (empty($push_rules)) {
            return $value;
        }

        // Read the OLD value. acf/update_value (priority 5) fires before the new
        // value is written, so get_field() returns the old DB value TODAY — but
        // that ordering is an ACF implementation detail, not a contract. Fall
        // back to raw post meta (also still the old value at this point) if the
        // ACF read comes back empty, hardening against a future ACF that primes
        // its value cache with the new value before this filter. (PR#24 Bug 4)
        $old = $this->read_relationship($post_id, $field_name);
        if (empty($old)) {
            $old = $this->extract_ids(\get_post_meta($post_id, $field_name, true));
        }
        if (empty($old)) {
            return $value;
        }

        $new     = $this->extract_ids($value);
        $removed = array_values(array_diff($old, $new));
        if (empty($removed)) {
            return $value;
        }

        // Record removed dependents per taxonomy the holder pushes (Bug 2: a
        // severed source only strips the taxonomy it actually feeds).
        foreach ($push_rules as $rule) {
            $taxonomy = (string) ($rule['taxonomy'] ?? '');
            if ($taxonomy === '') {
                continue;
            }
            $existing = $this->severed[$post_id][$taxonomy] ?? [];
            $this->severed[$post_id][$taxonomy] = array_values(
                array_unique(array_merge($existing, $removed))
            );
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
        $by_taxonomy = $this->severed[$source_id];
        unset($this->severed[$source_id]);

        $rules = $this->get_enabled_rules();

        // Each removed dependent is recomputed ONLY in the taxonomy the severing
        // source actually pushes (capture already scoped this per push rule):
        // remaining valid sources' terms survive; if none remain, the dependent
        // is emptied (severed source withdrawn). force_sync bypasses the V13
        // zero-source skip for exactly these orphans. (§V14)
        foreach ($by_taxonomy as $taxonomy => $dep_ids) {
            foreach ($dep_ids as $dep_id) {
                $this->recompute_dependent((int) $dep_id, (string) $taxonomy, $rules, true);
            }
        }
    }

    public function on_post_save($post_id): void {
        $post_id = (int) $post_id;
        if (\wp_is_post_autosave($post_id) || \wp_is_post_revision($post_id)) {
            return;
        }
        $this->sync_for_post($post_id);
        // Also drain any captured severs here. acf/update_value can fire without
        // a following acf/save_post (e.g. programmatic update_field() in a CLI
        // import), which would otherwise leave $this->severed growing unbounded
        // and the sever unprocessed. save_post (priority 25) runs after ACF
        // writes its fields (priority 10), so capture is complete by now.
        // process_severed unsets the key, so the acf/save_post path won't
        // double-process. (PR#24 round 2 #7)
        $this->process_severed($post_id);
    }

    /**
     * @param int    $object_id Post whose terms changed.
     * @param array  $terms     Unused.
     * @param array  $tt_ids    Unused.
     * @param string $taxonomy  Taxonomy that changed.
     */
    public function on_terms_changed($object_id, $terms, $tt_ids, $taxonomy): void {
        $object_id = (int) $object_id;
        $taxonomy  = (string) $taxonomy;
        // Skip writes we are making ourselves (cascade guard), scoped to the
        // taxonomy we're writing — a cross-taxonomy side effect on the same post
        // is still processed. (SPEC §V11; PR#24 round 2 #6)
        if (!empty($this->in_sync[$object_id][$taxonomy])) {
            return;
        }
        $this->sync_for_post($object_id, $taxonomy);
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
            // Gate on dependent-type ELIGIBILITY, not just the post_type_matches
            // ''=any wildcard: a push rule's dependent type is '' so this would
            // otherwise run the reverse-lookup on EVERY saved post site-wide
            // (#6). is_eligible_dependent_type narrows push to the ACF field's
            // configured target post types when known.
            if ($this->is_eligible_dependent_type($post, $rule)) {
                $dependents[$post_id][$taxonomy] = true;
            }

            // Is THIS post a source under this rule? Then its dependents must
            // recompute. (push: holder is source → its related posts; pull:
            // related posts are sources → the holder that references them.)
            // Gate on source-type ELIGIBILITY, mirroring the dependent-side
            // pre-filter above: a pull rule's source type is '' (any), which
            // would otherwise run resolve_reverse — including the tier-3
            // meta_query scan — on EVERY saved post site-wide. (SPEC §V17/B7)
            if ($this->is_eligible_source_type($post, $rule)) {
                foreach ($this->dependents_of_source($post_id, $rule) as $dep_id) {
                    $dependents[$dep_id][$taxonomy] = true;
                }
            }
        }

        foreach ($dependents as $dep_id => $taxes) {
            foreach (array_keys($taxes) as $taxonomy) {
                // No per-request recompute cache: the save_post + acf/save_post
                // double-fire is made safe by write_terms' idempotent
                // short-circuit + the in_sync cascade guard (SPEC §V11), NOT by
                // caching the result. A cache here is actively WRONG — a status
                // transition (publish→draft) changes the source-status gate
                // BETWEEN the two fires, so the first (stale-status) recompute
                // would suppress the second (correct-status) one and the
                // authoritative wipe would never run. (SPEC §B5)
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
        // $any_sync is set true ONLY by a keep_in_sync rule that actually has
        // sources for this dependent (loop below). It is NOT seeded from
        // $force_sync — that would force replace even when the only sourced rule
        // is add-only, breaking the add-only contract (PR#24 Bug 3).
        //
        // The sever path passes $force_sync=true and is used DIRECTLY in the
        // final write decision (not via $any_sync): capture_removed_dependents
        // only records under push + keep_in_sync rules, so $force_sync here
        // always means "a keep_in_sync rule manages this orphan" → replace from
        // remaining sources, emptying if none remain. (SPEC §V14)
        $any_sync     = false;
        $source_count = 0; // resolved sources across all applicable rules (SPEC §V13)

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

        if ($any_sync || $force_sync) {
            // keep-in-sync (or a forced sever of a keep_in_sync push) ⇒
            // final = authoritative (rule-union replace; empties if none). (§V3/§V14)
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
        // NOTE: deliberately NOT UnifiedHandlerBase::apply_terms_to_post — that
        // method early-returns on empty $terms, so it cannot do the keep-in-sync
        // empty-replace (wipe-to-[]) this rule needs, and it has no idempotent
        // short-circuit / cascade guard. Kept separate on purpose.
        $terms   = array_values(array_unique(array_map('intval', $terms)));
        $current = \wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (\is_wp_error($current)) {
            $current = [];
        }
        $current = array_map('intval', $current);

        // Single path: replace ⇒ final = $terms; add-only ⇒ final = current ∪ terms.
        $final = $replace ? $terms : array_values(array_unique(array_merge($current, $terms)));

        // Idempotent short-circuit (SPEC §V11): no change ⇒ no write ⇒ no
        // set_object_terms ⇒ cascade dies. Order-insensitive compare.
        sort($final);
        $cmp = $current;
        sort($cmp);
        if ($final === $cmp) {
            return;
        }

        // Cascade guard (SPEC §V11), scoped to (post, taxonomy). try/finally so
        // a throwing set_object_terms hook can't leak the flag and silently skip
        // this post+taxonomy for the rest of the request. (#3; round-2 #6)
        $this->in_sync[$post_id][$taxonomy] = true;
        try {
            \wp_set_object_terms($post_id, $final, $taxonomy);
        } finally {
            unset($this->in_sync[$post_id][$taxonomy]);
        }

        $this->debug_log(
            sprintf('ACF-ref sync (%s) post %d tax %s', $replace ? 'replace' : 'add', $post_id, $taxonomy),
            ['terms' => $final]
        );
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

        // LIKE on the bare ID (NOT quote-wrapped). ACF serializes a
        // relationship/post-object value as an INTEGER array — `a:1:{i:0;i:42;}`
        // — so the old `"42"` pattern (which only matches STRING serialization
        // `s:2:"42"`) never matched modern ACF and tier 3 silently returned
        // nothing. The bare value over-matches (e.g. 42 inside 142), so the
        // per-result read_relationship() below is the authoritative filter.
        // (PR#24 round 2 #2)
        $candidates = \get_posts([
            'post_type'      => $holder_type,
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => $field_name,
                'value'   => (string) $related_id,
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
        return \BWS\MetaConductor\Admin\Config\ConfigHelpers::selected_checkbox_slugs($rule['post_status'] ?? []);
    }

    /** Whether a post's type matches a required type ('' ⇒ any). */
    private function post_type_matches(\WP_Post $post, string $required_type): bool {
        return $required_type === '' || $post->post_type === $required_type;
    }

    /**
     * Whether $post can plausibly be a DEPENDENT of $rule — used to avoid
     * running the reverse-lookup on every saved post site-wide. (#6)
     *
     * pull (dependent type = holder, concrete): exact type match.
     * push (dependent type = '' / any): the dependent is whatever the ACF
     *   field points AT, so narrow to the field's configured target post types
     *   when ACF can tell us; if the field config is unknown/unconstrained,
     *   fall back to eligible (correctness preserved — V13's source-presence
     *   gate still prevents any wrong write; this is purely a perf pre-filter).
     */
    private function is_eligible_dependent_type(\WP_Post $post, array $rule): bool {
        $dep_type = $this->dependent_post_type($rule);
        if ($dep_type !== '') {
            return $post->post_type === $dep_type;
        }

        // push: consult the ACF field's target post types.
        $targets = $this->acf_field_target_post_types((string) ($rule['acf_field_name'] ?? ''));
        if (empty($targets)) {
            return true; // unknown/unconstrained → can't narrow; stay correct
        }
        return in_array($post->post_type, $targets, true);
    }

    /**
     * Whether $post can plausibly be a SOURCE of $rule — the source-side mirror
     * of is_eligible_dependent_type. Avoids running resolve_reverse (incl. the
     * tier-3 meta_query scan) on every saved post site-wide. (SPEC §V17/B7)
     *
     * push (source type = holder, concrete): exact type match.
     * pull (source type = '' / any): the source is whatever the ACF field points
     *   AT (a related post), so narrow to the field's configured target post
     *   types when ACF can tell us; unknown/unconstrained ⇒ stay eligible
     *   (resolve_reverse on an ineligible post returns empty anyway, and V13's
     *   source-presence gate still prevents wrong writes — purely a perf filter).
     */
    private function is_eligible_source_type(\WP_Post $post, array $rule): bool {
        $src_type = $this->source_post_type($rule);
        if ($src_type !== '') {
            return $post->post_type === $src_type;
        }

        // pull: consult the ACF field's target post types (the related posts).
        $targets = $this->acf_field_target_post_types((string) ($rule['acf_field_name'] ?? ''));
        if (empty($targets)) {
            return true; // unknown/unconstrained → can't narrow; stay correct
        }
        return in_array($post->post_type, $targets, true);
    }

    /**
     * Target post types an ACF relationship/post-object field points at, from
     * its `post_type` setting. Empty ⇒ unconstrained or ACF unavailable.
     * Defensive — never fatals on old/absent ACF.
     *
     * @return string[]
     */
    private function acf_field_target_post_types(string $field_name): array {
        if ($field_name === '' || !function_exists('acf_get_field')) {
            return [];
        }
        $field = \acf_get_field($field_name);
        if (!is_array($field) || empty($field['post_type'])) {
            return [];
        }
        return array_values(array_filter((array) $field['post_type']));
    }
}
