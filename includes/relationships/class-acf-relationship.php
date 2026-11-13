<?php
/**
 * The ACF relationship: posts joined by an ACF relationship / post-object field.
 *
 * @package Meta_Conductor
 * @since 0.8.0
 */

namespace BWS\MetaConductor\Relationships;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direction is holder-relative (SPEC §V1): the ACF field pins the holder post
 * type; `holder_role` says which end is the source. source = push (the holder's
 * terms go out to the posts its field lists); target = pull (the related posts'
 * terms land on the holder).
 *
 * This class owns the graph and the capture of what a write destroys:
 *
 *   - the holder/direction model, the three reverse-lookup tiers and their memo,
 *     and the field-to-target-types memo;
 *   - the SEVER CAPTURE: `capture_removed_dependents()` and
 *     `capture_deleted_post()` record, while the old relationship is still
 *     readable, which dependents lost a source; `is_severed()` answers for one
 *     row and `drain_marks()` is the channel that hands the affected entities to
 *     the dispatcher. Capture records and applies nothing: the handler keeps the
 *     hook registration, the claim, the zero-source gate and the term write.
 *
 * Rows are the canonical shape: `post_type` is the HOLDER type (split off the
 * combined ACF value by the descriptor's `normalize()`), `acf_field_name` the
 * forward field, `reverse_acf_field_name` the optional explicit reverse field.
 */
final class AcfRelationship implements Relationship {

    /**
     * Posts whose sources were BROKEN this request,
     * `[post_id][sever key] => true`.
     *
     * The fact, not the queue: it stays for the whole request because a post
     * can get more than one pass (the `wp_after_insert_post` drain and the
     * `shutdown` one), and both must reach the same conclusion. Re-applying a
     * withdrawal that already happened writes nothing.
     *
     * Keyed by the post TO RECOMPUTE, and by the ROW (see `sever_key()`), not
     * merely the taxonomy: the record is the ONE thing allowed to bypass the
     * handler's zero-source gate, so a link cut under row C must never license
     * row A to empty-replace the taxonomy.
     *
     * @var array<int,array<string,bool>>
     */
    private array $severed = [];

    /**
     * Entities a capture says need a pass, as a hash SET. CONSUMED by
     * `drain_marks()`.
     *
     * Separate from `$severed` because they answer different questions: this is
     * "who has not been passed over since the capture", spent once the
     * dispatcher has been told, while `$severed` is "who was orphaned this
     * request", which an applier may consult more than once.
     *
     * @var array<int,true>
     */
    private array $pending_marks = [];

    /**
     * Per-request memo of tier-3 reverse-lookup results, keyed
     * `"{related_id}:{holder_type}:{field_name}"`.
     *
     * A pass runs EVERY row against the entity, so a site with several rows
     * would otherwise repeat the unindexed scan once per row per entity. It
     * caches only the holder SET, never a write decision, and is dropped where
     * the graph is about to change (a managed field write, a delete).
     *
     * @var array<string,int[]>
     */
    private array $reverse_lookup_cache = [];

    /**
     * Per-request memo of ACF field → target post types, keyed by selector.
     * Instance property (not a function-static) so it doesn't bleed across
     * instances in tests. (PR#24 round 7 minor)
     *
     * @var array<string,string[]>
     */
    private array $field_target_types_cache = [];

    /**
     * Whether ACF is loaded far enough to read a relationship field. False ⇒ an
     * `acf` row runs nothing (its stored value survives untouched).
     */
    public static function is_available(): bool {
        return function_exists('acf_get_field') && function_exists('get_field');
    }

    // ---------------------------------------------------------------------
    // Relationship contract
    // ---------------------------------------------------------------------

    /**
     * push (holder=source): the dependent is a related post → find the holders
     *   that reference it (reverse lookup) → those holders are sources.
     * pull (holder=target): the dependent IS the holder → read its ACF field →
     *   the related posts are sources.
     */
    public function sources_of(int $dependent_id, array $row): array {
        if ($this->holder_is_source($row)) {
            return $this->resolve_reverse($dependent_id, $row);
        }
        return $this->read_relationship($dependent_id, (string) $row['acf_field_name']);
    }

    /**
     * push (holder=source): the source IS the holder → read its ACF field → the
     *   related posts are dependents.
     * pull (holder=target): the source is a related post → find the holders that
     *   reference it (reverse lookup) → those holders are dependents.
     */
    public function dependents_of(int $source_id, array $row): array {
        if ($this->holder_is_source($row)) {
            return $this->read_relationship($source_id, (string) $row['acf_field_name']);
        }
        return $this->resolve_reverse($source_id, $row);
    }

    /**
     * Whether $post can plausibly be a SOURCE of the row. Avoids running the
     * reverse lookup (incl. the tier-3 meta_query scan) for every entity passed
     * over. (SPEC §V17/B7)
     *
     * push (source type = holder, concrete): exact type match.
     * pull (source type = '' / any): the source is whatever the ACF field points
     *   AT (a related post), so narrow to the field's configured target post
     *   types when ACF can tell us; unknown/unconstrained stays eligible.
     */
    public function is_eligible_source(\WP_Post $post, array $row): bool {
        $src_type = $this->holder_is_source($row) ? $this->holder_post_type($row) : '';
        if ($src_type !== '') {
            return $post->post_type === $src_type;
        }

        return $this->targets_allow($post, $row);
    }

    /**
     * Whether $post can plausibly be a DEPENDENT of the row.
     *
     * pull (dependent type = holder, concrete): exact type match.
     * push (dependent type = '' / any): the dependent is whatever the ACF field
     *   points AT, so narrow to the field's configured target post types when
     *   ACF can tell us; unknown/unconstrained stays eligible (correctness is
     *   preserved: the handler's zero-source gate prevents any wrong write; this
     *   is partly a perf pre-filter).
     */
    public function is_eligible_dependent(\WP_Post $post, array $row): bool {
        // `post_types` narrows the receiving post further; empty = no extra
        // narrowing, so a row stored before the merge acts on what it acted on.
        $scope = $this->scope_post_types($row);
        if (!empty($scope) && !in_array($post->post_type, $scope, true)) {
            return false;
        }

        $dep_type = $this->holder_is_source($row) ? '' : $this->holder_post_type($row);
        if ($dep_type !== '') {
            return $post->post_type === $dep_type;
        }

        return $this->targets_allow($post, $row);
    }

    /**
     * The DEPENDENT end. Under `holder_role = source` (push) the dependents are
     * the related posts, whose type the row only constrains through its optional
     * `post_types`, so "all" without one. Under pull the dependent is the
     * holder; `post_types` can only narrow that away, and a collision check
     * that over-reports is the safe direction, so the holder type stands.
     */
    public function written_post_types(array $row): array {
        if ($this->holder_is_source($row)) {
            return $this->scope_post_types($row);
        }
        $post_type = trim($this->holder_post_type($row));

        return $post_type === '' ? [] : [$post_type];
    }

    /**
     * The row's `post_types` as a slug list; `[]` for empty or `any`
     * (`should_process_post()`'s "don't gate" sentinel).
     *
     * @return string[]
     */
    private function scope_post_types(array $row): array {
        $slugs = (array) ($row['post_types'] ?? []);

        return ($slugs === [] || ($slugs[0] ?? '') === 'any') ? [] : array_values($slugs);
    }

    /**
     * Whether the row OWNS its taxonomy: only an owning (`replace`) row ever
     * withdraws terms, so only it reacts to a sever. Contributing and
     * Deferring never remove.
     */
    private function owns(array $row): bool {
        return ($row['conflict_handling'] ?? 'merge') === 'replace';
    }

    /**
     * `post_status` gates the SOURCE here; narrowing the dependents by it would
     * silently drop a draft dependent of a published source from a run.
     */
    public function status_gates_source(array $row): bool {
        return true;
    }

    // ---------------------------------------------------------------------
    // Sever capture — records only; applies nothing
    // ---------------------------------------------------------------------

    /**
     * Whether a link feeding this post was cut this request under THIS row.
     */
    public function is_severed(int $post_id, array $row): bool {
        return !empty($this->severed[$post_id][$this->sever_key($row)]);
    }

    /**
     * Hand over, and clear, the entities a capture says need a pass.
     *
     * @return int[]
     */
    public function drain_marks(): array {
        $ids                 = array_keys($this->pending_marks);
        $this->pending_marks = [];

        return $ids;
    }

    /**
     * The `$severed` key for a row: everything that decides which end of which
     * relationship feeds which taxonomy.
     *
     * Both sides must derive it identically, and they do: the capture reads rows
     * through the handler's enabled-rules slice and the applier is handed its
     * row by the pass, but both go through `OptionRuleStorage::normalize_rule_shape()`,
     * which splits the combined `post_type:field` values. Nothing here is
     * derived from a row's position or index, which the two paths assign
     * independently.
     *
     * @param array $row Canonical-shape rule row.
     * @return string
     */
    public function sever_key(array $row): string {
        return implode('|', [
            (string) ($row['relationship'] ?? 'acf'),
            (string) ($row['taxonomy'] ?? ''),
            (string) ($row['post_type'] ?? ''),
            (string) ($row['acf_field_name'] ?? ''),
            (string) ($row['reverse_acf_field_name'] ?? ''),
            (string) ($row['holder_role'] ?? 'target'),
        ]);
    }

    /**
     * CAPTURE (not an apply): called from `acf/update_value` at priority 5,
     * before the value is written. Diff the OLD relationship value (still
     * readable here) against the new one and record every dependent the removal
     * orphans.
     *
     * Handles BOTH directions, because either end of a relationship can be the
     * post the user edits to remove a link (invariant #15):
     *
     *   PUSH (holder=source): the saved field is the row's FORWARD field. Its
     *     value lists the holder's dependents → a removed entry is a dependent
     *     to recompute. Post must be the row's HOLDER type.
     *   PULL (holder=target): the saved field is the row's REVERSE field (the
     *     source/related-post side: explicit `reverse_acf_field_name` or an ACF
     *     native-bidi partner). Its value lists the HOLDERS that reference this
     *     source → a removed entry is a holder (dependent) to recompute. Post
     *     must be an eligible SOURCE type. (B8)
     *   PUSH, DEPENDENT END (holder=source, but the REVERSE field is the one
     *     edited, on the dependent): the dependent is dropping its own source.
     *     A removed entry is a SOURCE, so the post to recompute is the edited
     *     post ITSELF. (#43)
     *
     * Rows that do not own their taxonomy (anything but Owning) never remove, so
     * a sever can't strip them: skipped in both directions (PR#24 Bug 3). The
     * post-type gate matters because ACF field names aren't unique across post
     * types (PR#24 round 3 Bug 1).
     *
     * Tier-3 pull rows (no explicit reverse field AND no ACF native bidi) have no
     * reverse field NAME to match here, so a relationship EDIT on their source
     * can't be captured; only the delete path covers them. Known gap.
     *
     * @param array[] $rules   This type's enabled rows.
     * @param mixed   $value   New field value (returned unchanged).
     * @param int     $post_id Post being saved (a source or a holder).
     * @param array   $field   ACF field array.
     * @return mixed
     */
    public function capture_removed_dependents(array $rules, $value, $post_id, $field) {
        $post_id    = (int) $post_id;
        $field_name = (string) ($field['name'] ?? '');
        if ($field_name === '') {
            return $value;
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return $value;
        }

        // Rows for which the removed entries are DEPENDENTS, and rows for which
        // they are SOURCES (so the post to recompute is this one).
        $dependent_rules = [];
        $self_rules      = [];
        foreach ($rules as $rule) {
            if (!$this->owns($rule)) {
                continue; // contributing / deferring never remove
            }
            if ($this->holder_is_source($rule)) {
                if ((string) ($rule['acf_field_name'] ?? '') === $field_name
                    && $this->post_type_matches($post, $this->holder_post_type($rule))) {
                    $dependent_rules[] = $rule;
                } elseif ($this->field_is_reverse_of($field_name, $rule)
                    && $this->is_eligible_dependent($post, $rule)) {
                    $self_rules[] = $rule; // push, dependent end (#43)
                }
            } elseif ($this->field_is_reverse_of($field_name, $rule)
                && $this->is_eligible_source($post, $rule)) {
                $dependent_rules[] = $rule; // pull, source end (B8)
            }
        }
        if (empty($dependent_rules) && empty($self_rules)) {
            return $value;
        }

        // A field THIS plugin manages is being written ⇒ the relationship graph
        // is about to change ⇒ drop the tier-3 reverse-lookup memo so the pass
        // does not resolve sources against the pre-edit graph. Placed AFTER the
        // row guard so an unrelated relationship-field save site-wide doesn't
        // needlessly bust the cache. (PR#24 round 5 #2, round 7 #1)
        $this->reverse_lookup_cache = [];

        // Read the OLD value. acf/update_value (priority 5) fires before the new
        // value is written, so get_field() returns the old DB value TODAY, but
        // that ordering is an ACF implementation detail, not a contract. Fall
        // back to raw post meta (also still the old value at this point) if the
        // ACF read comes back empty. (PR#24 Bug 4)
        $old = $this->read_relationship($post_id, $field_name);
        if (empty($old)) {
            $old = $this->extract_ids(\get_post_meta($post_id, $field_name, true));
        }
        if (empty($old)) {
            return $value;
        }

        $removed = array_values(array_diff($old, $this->extract_ids($value)));
        if (empty($removed)) {
            return $value;
        }

        foreach ($dependent_rules as $rule) {
            $this->record_sever($rule, $removed);
        }
        foreach ($self_rules as $rule) {
            $this->record_sever($rule, [$post_id]);
        }

        return $value;
    }

    /**
     * CAPTURE (not an apply): a post is about to be permanently deleted. Record
     * the dependents it feeds while its relationships are still readable.
     *
     * No `acf/update_value` fires on a delete, and afterwards the dying post's
     * field cannot be read at all, so this is the only moment its dependents can
     * be named. Both directions (§V14; PR#24 round 2 #4, round 5 #4):
     *
     *   PUSH (this post is the field-holding source): its dependents are the
     *     related posts in its field.
     *   PULL (this post is a related SOURCE): its dependents are the HOLDERS that
     *     reference it, via reverse lookup.
     *
     * @param array[] $rules   This type's enabled rows.
     * @param int     $post_id Post being deleted.
     */
    public function capture_deleted_post(array $rules, $post_id): void {
        $post_id = (int) $post_id;
        // Skip revision purges (wp_delete_post_revision fires before_delete_post):
        // a 'revision' post matches no holder/source type anyway. (round 7 #4)
        if ($post_id <= 0 || \wp_is_post_revision($post_id)) {
            return;
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return;
        }

        foreach ($rules as $rule) {
            if (!$this->owns($rule)) {
                continue; // only Owning removes ⇒ a delete can't strip the rest
            }
            $taxonomy = (string) ($rule['taxonomy'] ?? '');
            if ($taxonomy === '') {
                continue;
            }

            if ($this->holder_is_source($rule)) {
                // PUSH: this post must be the field holder; its dependents are
                // the related posts it lists.
                $field_name = (string) ($rule['acf_field_name'] ?? '');
                if ($field_name === ''
                    || !$this->post_type_matches($post, $this->holder_post_type($rule))) {
                    continue;
                }
                $dependents = $this->read_relationship($post_id, $field_name);
            } else {
                // PULL: this post is a SOURCE; its dependents are the holders
                // that reference it. Gate on source eligibility so we don't
                // reverse-lookup every deleted post site-wide. (§V17)
                if (!$this->is_eligible_source($post, $rule)) {
                    continue;
                }
                $dependents = $this->dependents_of($post_id, $rule);
            }

            $this->record_sever($rule, $dependents);
        }

        // The graph is about to lose a node, so every memoized holder set may be
        // wrong from here on. Cleared AFTER the capture: the capture's own
        // lookups are the last ones entitled to the pre-delete graph. (round 5 #2)
        $this->reverse_lookup_cache = [];
    }

    /**
     * Record that these posts lost a source UNDER THIS ROW, and that each needs a
     * pass. The single merge point for all four capture branches, so the
     * empty-taxonomy guard, the key derivation and the two structures cannot
     * drift apart.
     *
     * @param array $rule     The row whose link was cut (empty taxonomy: skip).
     * @param int[] $post_ids Posts orphaned under it.
     */
    private function record_sever(array $rule, array $post_ids): void {
        if ((string) ($rule['taxonomy'] ?? '') === '' || empty($post_ids)) {
            return;
        }
        $key = $this->sever_key($rule);
        foreach ($post_ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $this->severed[$id][$key] = true;
            $this->pending_marks[$id] = true;
        }
    }

    // ---------------------------------------------------------------------
    // Direction model (SPEC §V1)
    // ---------------------------------------------------------------------

    /** The post type that OWNS the ACF field (the holder). */
    private function holder_post_type(array $rule): string {
        return (string) ($rule['post_type'] ?? '');
    }

    /** Holder is source when holder_role=source (push); else target (pull). */
    private function holder_is_source(array $rule): bool {
        return (($rule['holder_role'] ?? 'target') === 'source');
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

        // Tier 2: ACF native bidirectional, the field's partner key(s). A field
        // can be bidi-linked to MORE THAN ONE partner field (one per target post
        // type), so union the reverse reads across all of them (PR#24 round 4
        // #1). Absent/old ACF or no bidi config returns [].
        $partners = $this->acf_bidirectional_partners(self::acf_selector($rule));
        if (!empty($partners)) {
            $holders = [];
            foreach ($partners as $partner) {
                $holders = array_merge($holders, $this->read_relationship($related_id, $partner));
            }
            return array_values(array_unique($holders));
        }

        // Tier 3: meta_query fallback (slow; correct).
        return $this->find_holders_referencing($related_id, $rule);
    }

    /**
     * Whether $field_name is the REVERSE side of $rule's forward relationship
     * field, i.e. a field on the source/related-post end whose value lists the
     * holders. Used to capture a pull-direction sever when the user edits the
     * SOURCE to drop a holder. (B8)
     *
     * Tier 1: explicit `reverse_acf_field_name`. Tier 2: an ACF native-bidi
     * partner of the forward field. Tier 3 (meta_query) has no reverse field
     * name, so it can't be matched here. Mirrors resolve_reverse's tier order.
     */
    private function field_is_reverse_of(string $field_name, array $rule): bool {
        if ($field_name === '') {
            return false;
        }
        $reverse = (string) ($rule['reverse_acf_field_name'] ?? '');
        if ($reverse !== '') {
            return $field_name === $reverse;
        }
        return in_array(
            $field_name,
            $this->acf_bidirectional_partners(self::acf_selector($rule)),
            true
        );
    }

    /**
     * What to hand `acf_get_field()` for this row's forward field. (#25)
     *
     * The stored field KEY when the row has one, the only identity that
     * separates two separately-created fields sharing a bare name. Falls back to
     * the bare name for a row saved before #25, or imported from another site
     * whose key means nothing here. The key is VERIFIED before it is returned: a
     * key that no longer resolves would otherwise make every lookup return
     * nothing, which is worse than the ambiguous name.
     *
     * NOT for reading a VALUE (`get_field($name, $post_id)` is post-scoped and
     * was never ambiguous). This is for the field's CONFIG.
     */
    private static function acf_selector(array $rule): string {
        $key  = (string) ($rule['acf_field_key'] ?? '');
        $name = (string) ($rule['acf_field_name'] ?? '');

        if ($key === '' || !function_exists('acf_get_field')) {
            return $name;
        }

        return is_array(\acf_get_field($key)) ? $key : $name;
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
     * Normalize an ACF relationship/post-object value (array of WP_Post, array of
     * IDs, single object, or single ID) to a unique int[] of post IDs.
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
     * The ACF native-bidirectional partner field names (one per linked target
     * field), or [] if none / ACF unavailable. A field bidi-linked to multiple
     * post types has multiple partner fields; ALL are returned (PR#24 round 4
     * #1). Defensive: never fatals on old/absent ACF. (SPEC §V6 tier 2)
     *
     * @param string $selector Field KEY where the row has one, else bare name
     *                         (`acf_selector()`), NOT a raw row value. (#25)
     * @return string[]
     */
    private function acf_bidirectional_partners(string $selector): array {
        if ($selector === '' || !function_exists('acf_get_field')) {
            return [];
        }
        $field = \acf_get_field($selector);
        if (!is_array($field) || empty($field['bidirectional'])) {
            return [];
        }
        $targets = $field['bidirectional_target'] ?? [];
        if (!is_array($targets) || empty($targets)) {
            return [];
        }
        // bidirectional_target holds field KEYS; resolve each to its name.
        $names = [];
        foreach ($targets as $key) {
            $partner = \acf_get_field($key);
            if (is_array($partner) && !empty($partner['name'])) {
                $names[] = (string) $partner['name'];
            }
        }
        return array_values(array_unique($names));
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

        // Per-request memo: a pass asks once per row per entity, so without it
        // this unindexed scan repeats across a rule set and across a drain.
        // (PR#24 round 5 #2)
        $cache_key = $related_id . ':' . $holder_type . ':' . $field_name;
        if (isset($this->reverse_lookup_cache[$cache_key])) {
            return $this->reverse_lookup_cache[$cache_key];
        }

        // LIKE on the bare ID (NOT quote-wrapped). ACF serializes a
        // relationship/post-object value as an INTEGER array (`a:1:{i:0;i:42;}`),
        // so a `"42"` pattern (STRING serialization) never matches modern ACF.
        // The bare value over-matches (42 inside 142), so the per-result
        // read_relationship() below is the authoritative filter. (PR#24 round 2 #2)
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

        $this->reverse_lookup_cache[$cache_key] = $matches;
        return $matches;
    }

    // ---------------------------------------------------------------------
    // Eligibility helpers
    // ---------------------------------------------------------------------

    /** Whether a post's type matches a required type ('' ⇒ any). */
    private function post_type_matches(\WP_Post $post, string $required_type): bool {
        return $required_type === '' || $post->post_type === $required_type;
    }

    /**
     * Whether the row's ACF field can point at this post's type. Unknown or
     * unconstrained field config ⇒ true (can't narrow; stay correct).
     */
    private function targets_allow(\WP_Post $post, array $row): bool {
        $targets = $this->acf_field_target_post_types(self::acf_selector($row));
        if (empty($targets)) {
            return true;
        }
        return in_array($post->post_type, $targets, true);
    }

    /**
     * Target post types an ACF relationship/post-object field points at, from its
     * `post_type` setting. Empty ⇒ unconstrained or ACF unavailable. Defensive:
     * never fatals on old/absent ACF.
     *
     * @param string $selector Field KEY where the row has one, else bare name
     *                         (`acf_selector()`), NOT a raw row value. (#25)
     * @return string[]
     */
    private function acf_field_target_post_types(string $selector): array {
        if ($selector === '' || !function_exists('acf_get_field')) {
            return [];
        }
        // Per-request memo: called from both eligibility pre-filters, once per
        // row, on every entity a pass touches. Keyed by the SELECTOR, so a
        // key-bearing row and a legacy name-only row for the same field are
        // separate entries. (PR#24 round 6 minor)
        if (array_key_exists($selector, $this->field_target_types_cache)) {
            return $this->field_target_types_cache[$selector];
        }
        $field  = \acf_get_field($selector);
        $result = (is_array($field) && !empty($field['post_type']))
            ? array_values(array_filter((array) $field['post_type']))
            : [];
        $this->field_target_types_cache[$selector] = $result;
        return $result;
    }
}
