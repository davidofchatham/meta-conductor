<?php
/**
 * WP Wireframe Bootstrap
 *
 * Registers custom field types, builds the settings config, and boots
 * Wireframe\App for the Meta Conductor admin page.
 *
 * @package BWS_Meta_Manager
 * @since 0.3.0
 */

namespace BWS\MetaConductor\Admin;

use BWS\MetaConductor\RuleTypes\Labels;
use BWS\MetaConductor\RuleTypes\Registry;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\RuleStorage;

if (!defined('ABSPATH')) {
    exit;
}

class WireframeBootstrap {

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_action('init', [self::class, 'boot'], 10);

        // WP repeats the top-level title as the first submenu item; relabel
        // it. Late priority, so it runs after every page has registered.
        add_action('admin_menu', static function (): void {
            global $submenu;
            foreach ($submenu['meta-conductor'] ?? [] as $i => $item) {
                if ($item[2] === 'meta-conductor') {
                    $submenu['meta-conductor'][$i][0] = __('Configure Rules', 'meta-conductor');
                }
            }
        }, 999);

        // Snapshot every row title in the ordered term-rule list
        // (architecture.md → The ordered rule repeaters).
        // One hook for the whole repeater, dispatching on each row's `type` —
        // the per-type propagation and time-based hooks rescoped onto it when
        // the config collapsed (#57), then related + ACF-reference (#58), and
        // hierarchical + level-restriction gained titles for the first time,
        // which is what finally makes the "[Disabled] " prefix uniform across
        // the list (#30). Wireframe's title_template does raw value
        // substitution only — it cannot resolve a stored term ID to a name —
        // so the titles must be persisted.
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_term_rule_labels'], 10, 1);

        // The same, for the ordered format-rule list (#59). A separate hook
        // rather than one generic snapshot over both kinds: the two lists
        // dispatch on disjoint type sets and share only the mechanics
        // `snapshot_row_titles()` holds, so merging them would buy a parameter
        // and cost the ability to read either one on its own.
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_format_rule_labels'], 10, 1);

        // Snapshot the General-tab claim-override row title. Without it the
        // repeater interpolates the raw stored value ("category: replace"),
        // the one surface the claim vocabulary would miss (ADR 0004). This is
        // the one snapshot that did NOT rescope onto a repeater — it belongs
        // to the per-taxonomy default, not to a rule (#53 §2).
        add_filter('wp-wireframe/save/payload', [self::class, 'snapshot_claim_override_labels'], 10, 1);

        // The collision advisory's two surfaces (#65): recompute-and-persist on
        // `settings_saved`, and the on-demand ActionField re-check. Registered
        // from the detector itself rather than here — they are its hooks, and
        // both fire on requests that never reach `boot()`'s admin gate.
        CollisionDetector::init();

        // The Apply page's button filters — REST requests, like the re-check.
        ApplyPage::init();
    }

    /**
     * Bake `row_title` onto every row of one kind list.
     *
     * The mechanics both kinds share, stated once: the position number, the
     * disabled marker, the single escape, and leaving a payload that carries
     * no list of this kind untouched. What differs is the per-type title
     * schema, which is the row's descriptor's `row_title()`.
     *
     * An unrecognized or absent `type` — or one of the other kind — is named
     * rather than left blank: the `type` select is `required`, so this should
     * be unreachable through the admin, but a row that somehow lacks one
     * still has to stay findable in a collapsed list.
     *
     * Runs on `wp-wireframe/save/payload`, which fires AFTER the Sanitizer (so
     * `row_title` survives despite not being an editable subfield) and before
     * the merge into saved state.
     *
     * Each `row_title()` returns UNESCAPED text and is escaped here, once —
     * the same discipline the `RuleTypes\Labels` helpers already follow.
     *
     * Descriptors read the row through `OptionRuleStorage::project_kind_rules()`:
     * the payload holds FORM values (`[N]` term ids, the combined ACF field
     * value), and the projection is the one place those are decoded. Only
     * `row_title` is written back onto the raw row.
     *
     * The title LEADS with the row's list position ("#3 "). Wireframe only
     * numbers a row whose `title_template` renders empty, so a titled list
     * showed no positions at all — and position is what decides a collision,
     * what the collision advisory names, and what the author reorders. Baked
     * rather than interpolated because `title_template` substitutes row values
     * and has no index token. The same save that renumbers the rows recomputes
     * the findings, and the on-demand check re-snapshots in-flight rows before
     * reading their titles, so the two never disagree (#65 UX follow-up).
     *
     * The position counts EVERY row, including one that is not an array and
     * carries no title: a skipped row still occupies a place in the list the
     * repeater renders, so numbering past it would misname every row below.
     *
     * @since 0.8.0
     * @param array    $clean_values Sanitized top-level field map.
     * @param string $key          Kind-list key.
     * @return array
     */
    private static function snapshot_row_titles(array $clean_values, string $key): array {
        if (empty($clean_values[$key]) || !is_array($clean_values[$key])) {
            return $clean_values;
        }

        $descriptors = Registry::of_kind($key);
        $position    = 0;

        foreach ($clean_values[$key] as &$rule) {
            $position++;

            if (!is_array($rule)) {
                continue;
            }

            $type       = (string) ($rule['type'] ?? '');
            $descriptor = $descriptors[$type] ?? null;
            if ($descriptor) {
                $title = $descriptor->row_title(OptionRuleStorage::project_kind_rules([$rule])[0]);
            } elseif ($type === '') {
                $title = __('(no rule type chosen)', 'meta-conductor');
            } else {
                /* translators: %s: the stored rule type key. */
                $title = sprintf(__('(unknown rule type: %s — this rule never runs)', 'meta-conductor'), $type);
            }

            $rule['row_title'] = '#' . $position . ' '
                . Labels::disabled_prefix($rule)
                . \esc_html($title);
        }
        unset($rule);

        return $clean_values;
    }

    /**
     * Assemble the row title for every rule in the ordered term-rule list.
     *
     * One hook for the whole repeater, dispatching on each row's `type`, so a
     * rule type's title schema stays one function while the disabled marker,
     * the escaping and the "which key holds the rules" question are answered
     * once for all of them.
     *
     * A separate entry point from the format twin rather than one generic
     * snapshot over both kinds: the two lists dispatch on disjoint type sets
     * and share nothing but the mechanics `snapshot_row_titles()` now holds,
     * so merging them would buy a parameter and cost the ability to read
     * either one on its own.
     *
     * See [docs/architecture.md → The ordered rule repeaters](../../docs/architecture.md#the-ordered-rule-repeaters).
     *
     * @since 0.8.0 Replaces snapshot_propagation_labels + snapshot_time_based_labels.
     * @param array $clean_values Sanitized top-level field map.
     * @return array
     */
    public static function snapshot_term_rule_labels(array $clean_values): array {
        return self::snapshot_row_titles(
            $clean_values,
            OptionRuleStorage::KIND_TERM
        );
    }

    /**
     * Assemble the row title for every rule in the ordered format-rule list
     * (#59). The format-kind twin of snapshot_term_rule_labels, hooked on the
     * same `wp-wireframe/save/payload` seam at the same priority.
     *
     * The list this replaces interpolated `{name}` live, which needed no
     * snapshot at all. Baking one now is what lets the title carry the
     * disabled marker and the post-type scope — and, when
     * `field_transformation` joins, a per-type schema — none of which
     * Wireframe's substitution-only `title_template` can produce.
     *
     * @since 0.8.0
     * @param array $clean_values Sanitized top-level field map.
     * @return array
     */
    public static function snapshot_format_rule_labels(array $clean_values): array {
        return self::snapshot_row_titles(
            $clean_values,
            OptionRuleStorage::KIND_FORMAT
        );
    }

    /**
     * Backfill the `row_title` of any stored rule that lacks one, before
     * Wireframe reads the settings option raw.
     *
     * `row_title` is a save-time snapshot, so a rule that reached storage
     * some other way — a seeded fixture, a raw `update_option()` from
     * WP-CLI — has none, and `title_template` renders its collapsed row
     * blank. The per-type repeaters mostly hid this by interpolating a live
     * token (`{taxonomy}`, `{name}`) instead; one shared template means one
     * shared fix. Runs over BOTH kind lists (#59).
     *
     * This is not a migration. The every-boot shape migrations that used to
     * run here were deleted with FW-39 once no stored row was in a legacy
     * shape.
     *
     * Self-limiting — once every row is titled there is nothing to do, so
     * this is at most one write per rule set, not one per admin load.
     * Both kinds are repaired in ONE write for the same reason: two
     * update_option calls would leave a window where the two lists disagree
     * about which admin load they belong to.
     *
     * @since 0.8.0
     * @param RuleStorage $storage The instance handlers hold this request.
     * @return void
     */
    private static function repair_stored_rules(RuleStorage $storage): void {
        $settings = $storage->get_raw_settings();
        $changed  = [];

        foreach ([
            OptionRuleStorage::KIND_TERM   => 'repair_term_rows',
            OptionRuleStorage::KIND_FORMAT => 'repair_format_rows',
        ] as $key => $repairer) {
            $rows = $settings[$key] ?? [];

            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $repaired = self::$repairer($rows);

            if ($repaired !== $rows) {
                $changed[$key] = $repaired;
            }
        }

        if (empty($changed)) {
            return;
        }

        $settings = array_merge($settings, $changed);

        // update_option returns false for a genuine failure AND for a no-op on
        // equality; here the values provably differ, so false can only mean the
        // write failed. Leaving the request cache pointing at the repaired rows
        // in that case would have handlers act on rules that are not in the
        // database. (architecture.md invariant #7)
        if (!update_option(OptionRuleStorage::OPTION_NAME, $settings)) {
            return;
        }

        // Drop the cache so nothing in this request keeps serving the
        // pre-repair rows.
        $storage->clear_cache();
    }

    /**
     * The term list's row repair: the title backfill. Pure — takes rows,
     * returns rows, so the caller decides whether anything is worth writing.
     *
     * @since 0.8.0
     * @param array $rows Stored `term_rules` rows.
     * @return array
     */
    private static function repair_term_rows(array $rows): array {
        $key = OptionRuleStorage::KIND_TERM;

        return self::snapshot_term_rule_labels([$key => $rows])[$key];
    }

    /**
     * The format list's row repairs: the title backfill, and nothing else.
     *
     * @since 0.8.0
     * @param array $rows Stored `format_rules` rows.
     * @return array
     */
    private static function repair_format_rows(array $rows): array {
        $key = OptionRuleStorage::KIND_FORMAT;

        return self::snapshot_format_rule_labels([$key => $rows])[$key];
    }

    /**
     * Assemble each General-tab claim-override row title.
     *
     * Hooked on `wp-wireframe/save/payload`. Schema:
     *   {Taxonomy}: {claim}
     *   e.g. "Categories: owning"
     *
     * Without this the repeater's `title_template` interpolates the raw
     * stored value (`category: replace`), which is the one place the claim
     * vocabulary would not reach — see CONTEXT.md → Claim, ADR 0004.
     * An unresolvable taxonomy falls back to its slug rather than an empty
     * title, since the row is still selectable and must stay identifiable.
     *
     * @param array $clean_values
     * @return array
     */
    public static function snapshot_claim_override_labels(array $clean_values): array {
        if (empty($clean_values['conflict_handling_overrides'])
            || !is_array($clean_values['conflict_handling_overrides'])) {
            return $clean_values;
        }

        foreach ($clean_values['conflict_handling_overrides'] as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $slug  = (string) ($row['taxonomy'] ?? '');
            $tax   = Labels::taxonomy_label($slug);
            $claim = Labels::claim_label($row['mode'] ?? 'merge');

            // ': ' as a literal, matching the time-based title — a
            // placeholders-and-punctuation-only string is not worth translating.
            $row['row_title'] = \esc_html(($tax !== '' ? $tax : $slug) . ': ' . $claim);
        }
        unset($row);

        return $clean_values;
    }

    /**
     * Boot Wireframe\App with the assembled config.
     *
     * Gated to admin + REST contexts. Front-end requests skip boot entirely —
     * BWS_Config_Helpers::all_term_options() does a full get_terms() scan
     * across every public taxonomy, which is wasted work outside the
     * settings UI and its save endpoint.
     */
    public static function boot(): void {
        if (!class_exists(\Wireframe\App::class)) {
            return;
        }

        // `REST_REQUEST` constant isn't defined until `parse_request` (well
        // after our `init` priority 10 boot), so detect REST via the URL
        // prefix instead. Both forms of permalinks supported.
        $rest_prefix = trailingslashit(rest_get_url_prefix());
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $is_rest     = str_contains($request_uri, '/' . $rest_prefix) || str_contains($request_uri, '?rest_route=');

        if (!is_admin() && !$is_rest) {
            return;
        }

        $storage = \BWS\MetaConductor\Storage\StorageFactory::get_instance();

        // Bring the persisted list up to what the repeater expects to render,
        // BEFORE Wireframe reads the option raw. (#57)
        self::repair_stored_rules($storage);

        // First collision scan for a rule set that never passed through a save
        // on this page — an upgrade, a seeded fixture, a CLI import. Runs AFTER
        // the repair so it reads the shapes the repeater is about to render,
        // and BEFORE the config is built, which is what reads the findings.
        // At most one write per site (#65).
        CollisionDetector::maybe_prime();

        // WireframeConfig autoloads via PSR-4 (autoload.php) — no manual require (Phase 2a).

        // Multi-page mode with one page. The single-page menu_slug bug
        // (wp-wireframe#5) is fixed as of 1.0.6, but the `pages[]` form stays
        // — it's the natural shape for adding more Wireframe pages later.
        \Wireframe\App::boot([
            'prefix'     => 'bws-meta-conductor',
            'capability' => 'manage_options',
            'version'    => defined('META_CONDUCTOR_VERSION') ? META_CONDUCTOR_VERSION : '0.3.0',
            // Symlinked installs (local dev) resolve the package via realpath()
            // to a path outside WP_PLUGIN_DIR, so Wireframe's assetsUrl() prefix
            // match fails and emits a broken asset base. plugins_url() keyed off
            // the symlinked main-file path derives the correct URL. Wireframe
            // appends src/assets/ internally.
            'assets_url' => \plugins_url(
                'vendor/tdrayson/wp-wireframe/src/assets/',
                dirname(__DIR__, 2) . '/meta-conductor.php'
            ),
            'pages'      => [
                [
                    'id'            => 'settings',
                    'option_key'    => 'bws_meta_conductor_settings',
                    'page_title'    => __('Meta Conductor', 'meta-conductor'),
                    'menu_title'    => __('Meta Conductor', 'meta-conductor'),
                    'menu_slug'     => 'meta-conductor',
                    'menu_icon'     => 'dashicons-category',
                    'menu_position' => 80,
                    'config'        => \BWS\MetaConductor\Admin\Config\WireframeConfig::build(),
                ],
            ],
        ]);

        // Apply to Existing Posts, a submenu of the page above (FW-16). Its
        // OWN boot call, not a second `pages[]` entry: Wireframe 1.0.6 merges
        // the boot-level option key OVER a page's (`$perBoot + [...]` in
        // `App::resolvePages()`), so as a sibling entry this page would save
        // into `bws_meta_conductor_settings`. Built after the repair above,
        // so its dropdown reads the rows the settings repeater renders.
        \Wireframe\App::boot([
            'prefix'     => 'bws-meta-conductor',
            'capability' => 'manage_options',
            'version'    => defined('META_CONDUCTOR_VERSION') ? META_CONDUCTOR_VERSION : '0.3.0',
            'option_key' => ApplyPage::OPTION_KEY,
            'pages'      => [ApplyPage::page()],
        ]);
    }
}
