<?php
/**
 * Related Post Terms (ACF Reference) handler.
 *
 * Copies taxonomy terms between a post and the posts it relates to via an ACF
 * relationship / post-object field, using a DECLARATIVE source-authoritative
 * model (no per-application tracking meta): a dependent's terms in a taxonomy
 * are recomputed from the sources one rule resolves for it.
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

/**
 * The last conversion (#63): pull applier + declared fan-out + capture layer.
 *
 * WHAT CHANGED AND WHY. This handler owned six hooks, ran every rule at once
 * from each of them, and wrote both ends of a relationship out of band. Three
 * consequences, all of them the defects Phase 4 exists to remove:
 *
 *   - It occupied ONE slot in the authored order however many rules it held,
 *     so two of its rows straddling another type's rule was inexpressible —
 *     the failure mode ADR 0003 rejected type-level ordering for.
 *   - Its writes landed on OTHER posts from inside the writing post's own
 *     execution, so the written post never got an ordered pass: one rule
 *     reached it, and the rest of the list then ran against it from whatever
 *     hooks happened to fire (#35's shape).
 *   - Its taxonomy-scoped `in_sync` cascade guard was a second re-entrancy
 *     mechanism competing with the pass lock (ADR 0003, rejected option).
 *
 * So it splits three ways, along the seams #62 already cut:
 *
 *   - `apply_to_post(P, rule)` recomputes P AND ONLY P under exactly ONE rule
 *     row, by READING the sources that row resolves for it. Pull, not push.
 *   - `fan_out(P, rule)` DECLARES the dependents P feeds under that row. The
 *     dispatcher marks them dirty; each gets its own full ordered pass.
 *   - The CAPTURE layer records what the write is about to destroy — a
 *     relationship that no longer exists cannot be read back — and applies
 *     nothing. `drain_captures()` hands the affected entities to the
 *     dispatcher at drain start.
 *
 * TWO BEHAVIOUR CHANGES ON A LIVE RULE TYPE, both consequences of one rule row
 * becoming the unit of execution. Recorded in the changelog as such.
 *
 *   1. TWO ROWS IN ONE TAXONOMY NO LONGER UNION; THEY COMPOSE BY ORDER. The old
 *      `recompute_dependent()` unioned every rule's sources for a taxonomy and
 *      wrote once. A per-rule applier cannot see its siblings, and must not:
 *      order is the composition mechanism (ADR 0002 decision 2). So a
 *      keep-in-sync row is `owning` over the taxonomy — it replaces — and the
 *      LAST such row in the list wins on any dependent both rows manage. An
 *      author who wants the union puts the second row on add-only, or orders
 *      the owning row first.
 *   2. AN ORPHANED DEPENDENT IS EMPTIED BY THE OWNING ROW EVEN IF A SIBLING
 *      ADD-ONLY ROW STILL HAS SOURCES. The old force path guarded that case
 *      (`$force_sync && $source_count === 0`), because one write served every
 *      rule. Per-rule, the add-only row runs in its own turn and re-adds its
 *      contribution if it is ordered after; if it is ordered before, the owning
 *      row's replace is what the author asked for. Note the limit of this: a
 *      sever is recorded against the RULE whose link was cut, so a row that
 *      resolves no source and had none cut still declines to write at all. Two
 *      rows contending over a taxonomy is a claim question; whether a row
 *      manages the post at all is not, and stays gated.
 *
 * WHAT LIVE STATE STILL CANNOT ANSWER. "This post resolves no sources" means
 * two different things: never related (leave it alone — invariant #2, the
 * destructive-write gate), and JUST severed (withdraw the gone source's
 * terms). Nothing on either post records which, and the relationship that would
 * have said so is precisely what was destroyed. `related`'s #61 answer —
 * restate the condition in live state — is unavailable here for that reason, so
 * the sever is CAPTURED as it happens instead, by the three hooks on
 * `TermDispatcher::CAPTURE_HOOKS['related_post_terms_rules']`. Capture records
 * and applies nothing; the applier consumes the record during a pass.
 */
class RelatedPostTermsHandler extends UnifiedHandlerBase {

    protected $handler_type = 'related_post_terms';

    /**
     * Posts whose sources were BROKEN this request,
     * `[post_id][rule key] => true`.
     *
     * The fact, not the queue: it stays for the whole request because a post
     * can get more than one pass (the `wp_after_insert_post` drain and the
     * `shutdown` one), and both must reach the same conclusion. Re-applying a
     * withdrawal that already happened writes nothing — the applier's end-state
     * comparison sees no change.
     *
     * Keyed by the post TO RECOMPUTE (not, as before #63, by the post whose
     * save drains it). The old key existed because nothing else would ever
     * reach `process_severed`; the dispatcher's queue is that mechanism now, so
     * the natural key is the one the applier asks with.
     *
     * KEYED BY THE RULE, NOT MERELY BY THE TAXONOMY, and that is load-bearing
     * now that the ROW is the unit of execution. The record is the ONE thing
     * allowed to bypass the zero-source gate (invariant #2) — the one thing
     * that lets a rule empty a post it resolves no source for. Under the old
     * cross-rule write a taxonomy key sufficed, because `source_count` was
     * summed over every rule before anything was written. Per-rule it does not:
     * a link cut under rule C would license rule A — which never resolved a
     * source for that post and never wrote to it — to empty-replace the whole
     * taxonomy, destroying what C had just legitimately put there. The key is
     * derived from the fields that decide which end feeds which, so two rows
     * that resolve identically share it harmlessly and two rows that do not are
     * kept apart. (A row has no stable id — ADR 0003 rejected `_id` — and the
     * per-type index is assigned independently on the two read paths, so
     * content is the only thing both sides can agree on.)
     *
     * @var array<int,array<string,bool>>
     */
    private array $severed = [];

    /**
     * Entities a capture says need a pass, as a hash SET. CONSUMED by
     * `drain_captures()`.
     *
     * Separate from `$severed` because they answer different questions: this is
     * "who has not been passed over since the capture", which is spent once the
     * dispatcher has been told, while `$severed` is "who was orphaned this
     * request", which the applier may need to consult more than once.
     *
     * @var array<int,true>
     */
    private array $pending_marks = [];

    /**
     * Per-request memo of tier-3 reverse-lookup results, keyed
     * `"{related_id}:{holder_type}:{field_name}"`.
     *
     * A pass runs EVERY rule against the entity, so a site with several rules of
     * this type would otherwise repeat the unindexed scan once per rule per
     * entity. The relationship graph is stable within a request except where
     * this file itself invalidates the memo (a relationship field write, a
     * delete), so caching the lookup is safe — and, unlike a recompute-RESULT
     * cache (forbidden by invariant #3 because the status gate can change
     * mid-request), this caches only the holder SET, never the write decision.
     *
     * @var array<string,int[]>
     */
    private array $reverse_lookup_cache = [];

    /**
     * Per-request memo of ACF field → target post types, keyed by field name.
     * Instance property (not a function-static) so it doesn't bleed across
     * handler instances in tests. (PR#24 round 7 minor)
     *
     * @var array<string,string[]>
     */
    private array $field_target_types_cache = [];

    /**
     * Per-REQUEST memo of enabled rules, for the CAPTURE path only — the
     * applier is handed its rule by the pass and never reads the list.
     *
     * The `acf/update_value` capture fires once per relationship field saved
     * site-wide, and each call re-ran get_enabled_rules → storage normalize over
     * every rule. The rule set is immutable within a request that saves posts
     * (rules are only mutated by the admin REST save, a separate request), so a
     * request-lifetime memo is safe. (PR#24 round 8 #4)
     *
     * @var array<int,array>|null
     */
    private ?array $enabled_rules_memo = null;

    public function get_handler_type() {
        return $this->handler_type;
    }

    protected function get_rule_type() {
        return 'related_post_terms_rules';
    }

    /** Memoized get_enabled_rules for the request. (round 8 #4) */
    private function enabled_rules(): array {
        if ($this->enabled_rules_memo === null) {
            $this->enabled_rules_memo = $this->get_enabled_rules();
        }
        return $this->enabled_rules_memo;
    }

    /**
     * CAPTURE hooks only — no apply hooks (#63).
     *
     * `acf/save_post` p30, `save_post` p25 and `set_object_terms` p15 lived here
     * until 0.8.0. `TermDispatcher` owns the trigger union now, so registering
     * any of them here would run every rule of this type twice, once out of
     * authored order.
     *
     * The three that stay all read state the write destroys, and all of them
     * only RECORD:
     *
     *   `acf/update_value/type=relationship` + `.../type=post_object` fire
     *   BEFORE ACF writes the new value, which is the last moment the OLD
     *   relationship is readable. A pass, running at drain, sees only the new
     *   graph — in which the severed link simply is not there, indistinguishable
     *   from one that never was.
     *
     *   `before_delete_post` is the same problem for a vanishing post: no
     *   `acf/update_value` fires on delete, and once the post is gone its
     *   relationship field cannot be read to find out whom it fed. The strip
     *   itself no longer needs a second `deleted_post` hook to sequence it —
     *   that pairing existed so the recompute ran AFTER the source stopped
     *   resolving, and the drain is already long after.
     *
     * `TermDispatcher::CAPTURE_HOOKS` is the allow-list that permits these
     * three, and H13 fails on any registration here that is not on it, or on
     * any of these callbacks calling a write primitive.
     */
    protected function init_hooks() {
        add_filter('acf/update_value/type=relationship', [$this, 'capture_removed_dependents'], 5, 3);
        add_filter('acf/update_value/type=post_object', [$this, 'capture_removed_dependents'], 5, 3);
        add_action('before_delete_post', [$this, 'capture_deleted_post'], 10, 1);
    }

    // Intentional no-op (not a forgotten implementation). apply_to_post() is
    // the applier; the base process_post routes through RuleEngine, which this
    // handler does not use.
    public function process_post($post_id, $post, $update) {}

    // ---------------------------------------------------------------------
    // Applier (#63)
    // ---------------------------------------------------------------------

    /**
     * Apply ONE rule row to ONE post: recompute this post's terms in the rule's
     * taxonomy from the sources THAT ROW resolves for it. Writes this post and
     * nothing else.
     *
     * WHY THIS DOES NOT CALL `should_process_post()`. On every other rule type
     * `post_status` gates the post being written. Here it is the SOURCE-status
     * gate (§V5) — "only copy from published sources" — and applying it to the
     * dependent would silently stop a draft dependent being kept in sync by a
     * published source, which is neither what the field says nor what the rule
     * did before. The post-type gate is this type's own two-sided eligibility
     * test instead: `post_types` is not even a subfield of this rule type (#58),
     * because the ACF field pins the holder type and the field's target types
     * pin the other end.
     *
     * THE ZERO-SOURCE SKIP IS THE DESTRUCTIVE-WRITE GATE (invariant #2, V13). A
     * push rule's dependent type is `''` = any, so type-match alone would make
     * every post in the field's target types a managed dependent and empty it.
     * Positive evidence that the row manages this post is a resolved source —
     * or a captured sever, which is the same evidence one moment later.
     *
     * @param int   $post_id Post to recompute.
     * @param array $rule    One enabled rule row (canonical shape).
     * @return bool Whether this post's terms actually changed.
     */
    public function apply_to_post(int $post_id, array $rule): bool {
        $taxonomy = (string) ($rule['taxonomy'] ?? '');
        if ($post_id <= 0 || $taxonomy === '') {
            return false;
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return false;
        }

        // Both halves of the dependent-side gate the pre-#63 pipeline applied:
        // the eligibility pre-filter (sync_for_post) and the exact type match
        // (recompute_dependent). For a pull rule they agree; for a push rule the
        // second is vacuous and the first narrows to the ACF field's targets.
        if (!$this->is_eligible_dependent_type($post, $rule)
            || !$this->post_type_matches($post, $this->dependent_post_type($rule))) {
            return false;
        }

        $keep_in_sync = !empty($rule['keep_in_sync']);
        $sources      = $this->sources_of_dependent($post_id, $rule);

        // An add-only row never removes, so a sever cannot make it act; only a
        // keep-in-sync row withdraws a gone source's terms (PR#24 Bug 3). And
        // only THIS row's own sever counts — see $severed.
        $orphaned = $keep_in_sync && !empty($this->severed[$post_id][$this->sever_key($rule)]);

        if (empty($sources) && !$orphaned) {
            return false;
        }

        // Source-scoped status gate (§V5). A gated-out source still COUNTS as a
        // resolved source above — its terms just don't contribute — so a
        // published dependent of a draft source is sync-emptied, not
        // skipped-as-unmanaged.
        $passing = [];
        foreach ($sources as $source_id) {
            if ($this->source_status_passes((int) $source_id, $rule)) {
                $passing[] = (int) $source_id;
            }
        }

        return $this->write_terms($post_id, $taxonomy, $this->terms_of($passing, $taxonomy), $keep_in_sync);
    }

    /**
     * The dependents THIS post feeds under this rule row — its declared reach
     * (#62's seam, this type's second user).
     *
     * push (holder = source): the related posts listed in the holder's field.
     * pull (holder = target): the holders that reference this related post.
     *
     * Neighbours, not closures: each dependent gets its own full ordered pass,
     * and a dependent is never itself a source under the same row, so there is
     * nothing further to walk.
     *
     * A SEVERED dependent is deliberately NOT here. It is unreachable by
     * construction — the relationship that would name it is the thing that was
     * just destroyed — which is why the capture layer exists and why
     * `drain_captures()` is a separate channel into the queue.
     *
     * @param int   $post_id Post just passed over.
     * @param array $rule    The rule row.
     * @return int[] Dependent post IDs.
     */
    public function fan_out(int $post_id, array $rule): array {
        if ((string) ($rule['taxonomy'] ?? '') === '') {
            return [];
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return [];
        }

        // Gate on source ELIGIBILITY, not just the ''-is-any wildcard: a pull
        // rule's source type is '' , which would otherwise run resolve_reverse —
        // including the tier-3 meta_query scan — for every entity passed over
        // site-wide. (§V17/B7)
        if (!$this->is_eligible_source_type($post, $rule)) {
            return [];
        }

        return $this->dependents_of_source($post_id, $rule);
    }

    /**
     * Entities whose CAPTURED state needs a pass. Consumed — see
     * `$pending_marks`.
     *
     * The dispatcher calls this at drain start, so a severed dependent reaches
     * a full ordered pass by the same route a saved post does. It has to be a
     * separate channel from `fan_out()` because the fan-out is asked while
     * passing over a post, and the entity a sever affects may be reachable from
     * no post that gets one: the holder was deleted, or the link that named it
     * is gone.
     *
     * @return int[] Entity IDs to mark dirty.
     */
    public function drain_captures(): array {
        $ids                 = array_keys($this->pending_marks);
        $this->pending_marks = [];

        return $ids;
    }

    // ---------------------------------------------------------------------
    // Capture layer (#63) — records only; applies nothing
    // ---------------------------------------------------------------------

    /**
     * CAPTURE hook (not an apply): `acf/update_value` at priority 5, before the
     * value is written. Diff the OLD relationship value (still readable here)
     * against the new one and record every dependent the removal orphans.
     *
     * Handles BOTH directions, because either end of a relationship can be the
     * post the user edits to remove a link (invariant #15):
     *
     *   PUSH (holder=source): the saved field is the rule's FORWARD field. Its
     *     value lists the holder's dependents → a removed entry is a dependent
     *     to recompute. Post must be the rule's HOLDER type.
     *   PULL (holder=target): the saved field is the rule's REVERSE field (the
     *     source/related-post side — explicit `reverse_acf_field_name` or an ACF
     *     native-bidi partner). Its value lists the HOLDERS that reference this
     *     source → a removed entry is a holder (dependent) to recompute. Post
     *     must be an eligible SOURCE type. Without this, editing the source to
     *     drop a holder leaves no capture: the pass then reads the NEW graph
     *     (holder already gone) and never recomputes it, so the holder keeps the
     *     stale pulled term. (B8)
     *   PUSH, DEPENDENT END (holder=source, but the REVERSE field is the one
     *     edited, on the dependent): the dependent is dropping its own source.
     *     A removed entry is a SOURCE, so the post to recompute is the edited
     *     post ITSELF. This is the end an editor actually touches. (#43)
     *
     * Add-only rules (keep_in_sync off) never remove, so a sever can't strip
     * them — skipped in both directions (PR#24 Bug 3). The post-type gate
     * matters because ACF field names aren't unique across post types: a
     * same-named field on a different post type could otherwise match a rule and
     * orphan unrelated dependents (PR#24 round 3 Bug 1).
     *
     * Tier-3 pull rules (no explicit reverse field AND no ACF native bidi) have
     * no reverse field NAME to match here, so a relationship EDIT on their
     * source can't be captured — only the delete path covers them. Such rules
     * rely on the meta_query scan and have no stored reverse value to diff;
     * their edit-sever is a known gap.
     *
     * @param mixed $value    New field value (returned unchanged).
     * @param int   $post_id  Post being saved (a source or a holder).
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

        // Rules for which the removed entries are DEPENDENTS, and rules for
        // which they are SOURCES (so the post to recompute is this one).
        $dependent_rules = [];
        $self_rules      = [];
        foreach ($this->enabled_rules() as $rule) {
            if (empty($rule['keep_in_sync'])) {
                continue; // add-only never removes
            }
            if ($this->holder_is_source($rule)) {
                if ((string) ($rule['acf_field_name'] ?? '') === $field_name
                    && $this->post_type_matches($post, $this->holder_post_type($rule))) {
                    $dependent_rules[] = $rule;
                } elseif ($this->field_is_reverse_of($field_name, $rule)
                    && $this->is_eligible_dependent_type($post, $rule)) {
                    $self_rules[] = $rule; // push, dependent end (#43)
                }
            } elseif ($this->field_is_reverse_of($field_name, $rule)
                && $this->is_eligible_source_type($post, $rule)) {
                $dependent_rules[] = $rule; // pull, source end (B8)
            }
        }
        if (empty($dependent_rules) && empty($self_rules)) {
            return $value;
        }

        // A field THIS plugin manages is being written ⇒ the relationship graph
        // is about to change ⇒ drop the tier-3 reverse-lookup memo so the pass
        // does not resolve sources against the pre-edit graph. Placed AFTER the
        // rule guard so an unrelated relationship-field save site-wide doesn't
        // needlessly bust the cache. (PR#24 round 5 #2, round 7 #1)
        $this->reverse_lookup_cache = [];

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
     * CAPTURE hook (not an apply): a post is about to be permanently deleted.
     * Record the dependents it feeds while its relationships are still readable.
     *
     * No `acf/update_value` fires on a delete, and afterwards the dying post's
     * field cannot be read at all — so this is the only moment its dependents
     * can be named. Both directions (§V14; PR#24 round 2 #4, round 5 #4):
     *
     *   PUSH (this post is the field-holding source): its dependents are the
     *     related posts in its field — capture them directly.
     *   PULL (this post is a related SOURCE): its dependents are the HOLDERS
     *     that reference it — capture them via reverse lookup. Without this, a
     *     deleted pull-source with no terms in the synced taxonomy leaves the
     *     holder holding stale terms with nothing to provoke a recompute.
     *
     * The strip itself happens in each dependent's own pass at drain, which is
     * necessarily after the delete — so the pre-#63 `deleted_post` half of this
     * pair, which existed only to sequence the strip after the source stopped
     * resolving, is gone.
     *
     * @param int $post_id Post being deleted.
     */
    public function capture_deleted_post($post_id): void {
        $post_id = (int) $post_id;
        // Skip revision purges (wp_delete_post_revision fires before_delete_post)
        // — a 'revision' post matches no holder/source type anyway, so this just
        // avoids the rule scan + get_post on every revision cleanup. (round 7 #4)
        if ($post_id <= 0 || \wp_is_post_revision($post_id)) {
            return;
        }

        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return;
        }

        foreach ($this->enabled_rules() as $rule) {
            if (empty($rule['keep_in_sync'])) {
                continue; // add-only never removes ⇒ a delete can't strip
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
                if (!$this->is_eligible_source_type($post, $rule)) {
                    continue;
                }
                $dependents = $this->dependents_of_source($post_id, $rule);
            }

            $this->record_sever($rule, $dependents);
        }

        // The graph is about to lose a node, so every memoized holder set may be
        // wrong from here on. Cleared AFTER the capture — the capture's own
        // lookups are the last ones entitled to the pre-delete graph — and
        // nothing repopulates it until the drain, which is post-delete. This is
        // what the pre-#63 `deleted_post` hook did, at the only other moment it
        // can be done. (round 5 #2)
        $this->reverse_lookup_cache = [];
    }

    /**
     * Record that these posts lost a source UNDER THIS RULE, and that each
     * needs a pass.
     *
     * The single merge point for all four capture branches, so the
     * empty-taxonomy guard, the key derivation and the two structures cannot
     * drift apart.
     *
     * @param array $rule     The rule whose link was cut (empty taxonomy: skip).
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

    /**
     * The `$severed` key for a rule: everything that decides which end of which
     * relationship feeds which taxonomy.
     *
     * Both sides must derive it identically, and they do — the capture reads
     * rules through `get_enabled_rules()` (this type's slice) and the applier
     * is handed its row by the pass over the whole kind list, but both go
     * through `OptionRuleStorage::normalize_rule_shape()`, which is what splits
     * the combined `post_type:field` values. Nothing here is derived from a
     * row's position or index, which the two paths assign independently.
     *
     * @param array $rule Canonical-shape rule.
     * @return string
     */
    private function sever_key(array $rule): string {
        return implode('|', [
            (string) ($rule['taxonomy'] ?? ''),
            (string) ($rule['post_type'] ?? ''),
            (string) ($rule['acf_field_name'] ?? ''),
            (string) ($rule['reverse_acf_field_name'] ?? ''),
            (string) ($rule['holder_role'] ?? 'target'),
        ]);
    }

    // ---------------------------------------------------------------------
    // Write
    // ---------------------------------------------------------------------

    /**
     * The union of the given posts' terms in one taxonomy.
     *
     * Batched: `wp_get_object_terms` accepts an int[] of objects, so all passing
     * sources for a rule resolve in ONE query rather than N (PR#24 round 5 #3).
     *
     * @param int[]  $post_ids
     * @param string $taxonomy
     * @return int[]
     */
    private function terms_of(array $post_ids, string $taxonomy): array {
        if (empty($post_ids)) {
            return [];
        }
        $terms = \wp_get_object_terms($post_ids, $taxonomy, ['fields' => 'ids']);

        return \is_wp_error($terms) ? [] : $this->normalize_term_ids((array) $terms);
    }

    /**
     * Write one rule's computed set, through the shared claim semantics.
     *
     * `keep_in_sync` IS a claim in the ADR 0004 sense — owning over the whole
     * taxonomy when on, contributing when off — so it maps onto
     * `compute_end_state()` rather than re-deriving the merge/replace switch
     * here. That mapping is the reason an empty authoritative set can still
     * empty the taxonomy under keep-in-sync (`replace` of nothing) while leaving
     * it untouched under add-only (`merge` of nothing), which is exactly the
     * pair of behaviours the old four-branch write decision spelled out.
     *
     * `apply_terms_to_post()` is still not the write path: it early-returns on
     * an empty term list, so it cannot express the keep-in-sync empty-replace.
     * The claim encoding is shared; the guard is not. (#38 cluster 2, don't 6d.)
     *
     * @param int    $post_id      Dependent post.
     * @param string $taxonomy     Taxonomy.
     * @param int[]  $terms        Authoritative term IDs for this rule.
     * @param bool   $keep_in_sync true ⇒ owning (replace); false ⇒ contributing.
     * @return bool Whether the post's terms actually changed.
     */
    private function write_terms(int $post_id, string $taxonomy, array $terms, bool $keep_in_sync): bool {
        $current = \wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (\is_wp_error($current)) {
            $current = [];
        }
        $current = $this->normalize_term_ids((array) $current);
        $final   = $this->compute_end_state($current, $terms, $keep_in_sync ? 'replace' : 'merge');

        // No-change short-circuit. A pass runs every rule whether or not this
        // one's trigger fired, so without this the same recompute re-writes and
        // re-logs on every provocation. Both sides come out of
        // normalize_term_ids/compute_end_state sorted, so === is a set test.
        if ($final === $current) {
            return false;
        }

        $result = \wp_set_object_terms($post_id, $final, $taxonomy);

        // wp_set_object_terms returns WP_Error when the taxonomy doesn't exist
        // (e.g. deleted after the rule was saved). Don't log a success message
        // for a write that silently no-op'd. (PR#24 round 7 #2)
        if (\is_wp_error($result)) {
            $this->debug_log(
                sprintf('ACF-ref sync FAILED post %d tax %s: %s', $post_id, $taxonomy, $result->get_error_message()),
                ['terms' => $final]
            );
            return false;
        }

        $this->debug_log(
            sprintf('ACF-ref sync (%s) post %d tax %s', $keep_in_sync ? 'replace' : 'add', $post_id, $taxonomy),
            ['before' => $current, 'after' => $final]
        );

        return true;
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

        // Tier 2: ACF native bidirectional — the field's partner key(s). A field
        // can be bidi-linked to MORE THAN ONE partner field (one per target post
        // type), so union the reverse reads across all of them, not just the
        // first (PR#24 round 4 #1). Wrapped defensively; absent/old ACF or no
        // bidi config returns [].
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
     * field — i.e. a field on the source/related-post end whose value lists the
     * holders. Used to capture a pull-direction sever when the user edits the
     * SOURCE to drop a holder. (B8 — pull-edit sever symmetry)
     *
     * Tier 1: explicit `reverse_acf_field_name`. Tier 2: an ACF native-bidi
     * partner of the forward field. Tier 3 (meta_query) has no reverse field
     * name, so it can't be matched here — those rules' edit-sever is uncovered
     * (delete-sever still works). Mirrors resolve_reverse's tier order.
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
     * What to hand `acf_get_field()` for this rule's forward field. (#25)
     *
     * The stored field KEY when the row has one — the only identity that
     * separates two separately-created fields sharing a bare name, which
     * `acf_get_field($name)` resolves to one arbitrary match. Falls back to the
     * bare name for a row saved before #25, or one imported from another site
     * whose key means nothing here: that is exactly the pre-#25 lookup, so a
     * row is never worse off than it was.
     *
     * NOT for reading a VALUE — `get_field($name, $post_id)` is post-scoped and
     * was never ambiguous. This is for the field's CONFIG.
     *
     * The key is VERIFIED before it is returned, and that verification is the
     * whole fallback: a key that no longer resolves — the field was deleted and
     * rebuilt, or the row was imported from a site where that key never existed
     * — would otherwise make every lookup return nothing at all, which is worse
     * than the ambiguous name this change replaced. Resolving twice costs one
     * extra hit on ACF's own field store after the first call in a request.
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
     * The ACF native-bidirectional partner field names (one per linked target
     * field), or [] if none / ACF unavailable. A field bidi-linked to multiple
     * post types has multiple partner fields — ALL are returned (PR#24 round 4
     * #1). Defensive: never fatals on old/absent ACF. (SPEC §V6 tier 2)
     *
     * @param string $selector Field KEY where the row has one, else bare name
     *                         (`acf_selector()`), NOT a raw rule value. (#25)
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

        // Per-request memo: a pass asks once per rule per entity, so without it
        // this unindexed scan repeats across a rule set and across a drain.
        // (PR#24 round 5 #2)
        $cache_key = $related_id . ':' . $holder_type . ':' . $field_name;
        if (isset($this->reverse_lookup_cache[$cache_key])) {
            return $this->reverse_lookup_cache[$cache_key];
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

        $this->reverse_lookup_cache[$cache_key] = $matches;
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
     * running the reverse-lookup for every entity passed over site-wide. (#6)
     *
     * pull (dependent type = holder, concrete): exact type match.
     * push (dependent type = '' / any): the dependent is whatever the ACF
     *   field points AT, so narrow to the field's configured target post types
     *   when ACF can tell us; if the field config is unknown/unconstrained,
     *   fall back to eligible (correctness preserved — the zero-source gate
     *   still prevents any wrong write; this is purely a perf pre-filter).
     */
    private function is_eligible_dependent_type(\WP_Post $post, array $rule): bool {
        $dep_type = $this->dependent_post_type($rule);
        if ($dep_type !== '') {
            return $post->post_type === $dep_type;
        }

        // push: consult the ACF field's target post types.
        $targets = $this->acf_field_target_post_types(self::acf_selector($rule));
        if (empty($targets)) {
            return true; // unknown/unconstrained → can't narrow; stay correct
        }
        return in_array($post->post_type, $targets, true);
    }

    /**
     * Whether $post can plausibly be a SOURCE of $rule — the source-side mirror
     * of is_eligible_dependent_type. Avoids running resolve_reverse (incl. the
     * tier-3 meta_query scan) for every entity passed over. (SPEC §V17/B7)
     *
     * push (source type = holder, concrete): exact type match.
     * pull (source type = '' / any): the source is whatever the ACF field points
     *   AT (a related post), so narrow to the field's configured target post
     *   types when ACF can tell us; unknown/unconstrained ⇒ stay eligible
     *   (resolve_reverse on an ineligible post returns empty anyway, and the
     *   zero-source gate still prevents wrong writes — purely a perf filter).
     */
    private function is_eligible_source_type(\WP_Post $post, array $rule): bool {
        $src_type = $this->source_post_type($rule);
        if ($src_type !== '') {
            return $post->post_type === $src_type;
        }

        // pull: consult the ACF field's target post types (the related posts).
        $targets = $this->acf_field_target_post_types(self::acf_selector($rule));
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
     * @param string $selector Field KEY where the row has one, else bare name
     *                         (`acf_selector()`), NOT a raw rule value. (#25)
     * @return string[]
     */
    private function acf_field_target_post_types(string $selector): array {
        if ($selector === '' || !function_exists('acf_get_field')) {
            return [];
        }
        // Per-request memo: this is called from both eligibility pre-filters,
        // once per rule, on every entity a pass touches. Field config is stable
        // within a request. (PR#24 round 6 minor) Keyed by the SELECTOR, so a
        // key-bearing row and a legacy name-only row for the same field are
        // separate entries rather than one masquerading as the other.
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
