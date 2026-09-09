<?php
/**
 * #59 behaviour sweep — a title/slug rule AUTHORED IN THE FORMAT REPEATER
 * still fires, and authored order decides which one does.
 *
 * sweep-59-roundtrip.php proves the STORED rule survives the move. This proves
 * the other direction: a rule that arrives the way the new repeater delivers it
 * — through Wireframe's real sanitize, the row-title snapshot and the save-time
 * projection — is a rule the handler finds and resolves tokens against.
 *
 * Three acts:
 *
 *   1. **Author.** Two rows on the SAME post type are pushed through
 *      `RepeaterField::sanitize` + both save-payload filters and written to the
 *      option, exactly as a settings save would. The stored `format_rules`
 *      order and the projected `title_slug_rules` array are asserted, and the
 *      handler is asked which rule it would apply.
 *   2. **Reorder.** The two rows are swapped and re-saved. The format pass is
 *      first-match-wins per rule type (`rule_matches()`, #64 — it was
 *      `find_matching_rule()` when this sweep was written), so the winner must
 *      change — which is the sharpest
 *      available proof that the repeater's authored order reaches the handler,
 *      and the behaviour the post-type field's description now promises.
 *   3. **Tokens.** `{meta:}`, `{term:}`, `{terms:}` and `{pub_*}` are resolved
 *      through the handler against a real fixture post in both title and slug
 *      context, and compared against expectations computed independently from
 *      WordPress. #59 moves the config, not the engine, so these must be
 *      identical to before — which is exactly why they are asserted.
 *
 * NON-MUTATING by construction: no post is ever saved, so nothing renames a
 * `post_name` and the §7 restore gotcha does not apply. The settings option is
 * snapshotted up front and restored in a `finally`, including on failure.
 *
 * Run: wp eval-file <mount>/tools/fixtures/mc-rules/sweep-59-behaviour.php --allow-root
 * Prereq: mc-rules seeded (seed.php).
 */

use BWS\MetaConductor\Admin\Config\FormatRulesConfig;
use BWS\MetaConductor\Admin\WireframeBootstrap;
use BWS\MetaConductor\Handlers\TitleSlugHandler;
use BWS\MetaConductor\Storage\OptionRuleStorage;
use BWS\MetaConductor\Storage\StorageFactory;
use BWS\MetaConductor\TaxonomyManager;
use Wireframe\Framework\Fields\RepeaterField;

require_once __DIR__ . '/lookup.php';

$fail = [];
$note = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . " $label\n";
    if (!$ok) {
        $fail[] = $label;
    }
};

$KIND     = OptionRuleStorage::KIND_FORMAT;
$storage  = StorageFactory::get_instance();
$snapshot = get_option(OptionRuleStorage::OPTION_NAME, []);

/**
 * Push rows through the exact save path a settings submit takes: Wireframe's
 * real repeater sanitize against the live config, then the row-title snapshot
 * filter, then the option write. There is no projection step since #66 — the
 * list the repeater writes is the shape storage reads.
 */
$save_rows = static function (array $rows) use ($KIND, $storage, $snapshot): array {
    $field   = FormatRulesConfig::section()['fields'][0];
    $payload = [$KIND => RepeaterField::sanitize($rows, $field['args'])];
    $payload = WireframeBootstrap::snapshot_format_rule_labels($payload);

    // Wireframe merges the clean payload over saved state; mirror that rather
    // than replacing the option, so nothing else in the fixture is disturbed.
    update_option(OptionRuleStorage::OPTION_NAME, array_merge($snapshot, $payload));
    $storage->clear_cache();

    return get_option(OptionRuleStorage::OPTION_NAME, []);
};

try {
    // ── The subject: a seeded mc_item with a date field and topic terms. ────

    // mc_fixture_find_post returns an ID (0 when missing), not a post object.
    $post_id = (int) mc_fixture_find_post('mc-item-alpha', 'mc_item');
    $post    = $post_id ? get_post($post_id) : null;
    if (!$post) {
        echo "FAIL cannot find fixture post mc-item-alpha — seed.php first\n";
        exit(1);
    }

    // ── 1. Author two rules on one post type. ──────────────────────────────

    $first = [
        'type' => 'title_slug_rules', 'enabled' => true, 'name' => 'Sweep 59 first',
        'post_type' => 'mc_item', 'slug_pattern' => 'first-{default_slug}',
        'slug_mode' => 'replace', 'date_escalation' => false, 'date_field' => '',
        'title_pattern' => '',
    ];
    $second = [
        'type' => 'title_slug_rules', 'enabled' => true, 'name' => 'Sweep 59 second',
        'post_type' => 'mc_item', 'slug_pattern' => 'second-{default_slug}',
        'slug_mode' => 'replace', 'date_escalation' => false, 'date_field' => '',
        'title_pattern' => '',
    ];

    $saved = $save_rows([$first, $second]);

    $note('the repeater save writes format_rules',
        count($saved[$KIND] ?? []) === 2);
    $note('stored order is the authored order',
        array_column($saved[$KIND], 'name') === ['Sweep 59 first', 'Sweep 59 second']);
    $note('the save writes no type-keyed copy (#66)',
        !array_key_exists('title_slug_rules', $saved));
    $note('the row title was snapshot onto the list row',
        ($saved[$KIND][0]['row_title'] ?? '') === 'Sweep 59 first (MC Items)');

    // What the handler reads — the derived kind-list path, on a fresh cache.
    $read = $storage->get_rules('title_slug_rules');
    $note('the handler reads both authored rules, in order',
        array_column($read, 'name') === ['Sweep 59 first', 'Sweep 59 second']);
    $note('the patterns and slug mode read back intact',
        ($read[0]['slug_pattern'] ?? '') === 'first-{default_slug}'
        && ($read[0]['slug_mode'] ?? '') === 'replace');

    $handler = TaxonomyManager::get_instance()->get_handler('title_slug');
    $note('the title_slug handler is live', $handler instanceof TitleSlugHandler);

    // First-match-wins moved off `find_matching_rule()` in #64: the format
    // dispatcher offers every rule in list order and stops at the first of a
    // type whose `rule_matches()` answers. The winner is therefore the first
    // enabled rule the handler claims — computed here the same way the pass
    // computes it, and still without applying anything.
    $matches = new ReflectionMethod(TitleSlugHandler::class, 'rule_matches');
    $winner = static function (object $p) use ($handler, $matches): array {
        foreach (StorageFactory::get_instance()->get_rules('title_slug_rules') as $rule) {
            if ($matches->invoke($handler, $rule, $p->post_type)) {
                return $rule;
            }
        }
        return [];
    };

    $note('first-match-wins picks the top authored rule',
        ($winner($post)['name'] ?? '') === 'Sweep 59 first');

    // ── 2. Reorder: the winner must change. ────────────────────────────────

    $saved = $save_rows([$second, $first]);
    $note('the reorder is stored',
        array_column($saved[$KIND], 'name') === ['Sweep 59 second', 'Sweep 59 first']);
    $note('dragging a rule up makes it the one that applies',
        ($winner($post)['name'] ?? '') === 'Sweep 59 second');

    // A disabled top rule must not win — `enabled` is read through the same
    // kind list, and it is one of the ungated shared subfields H12 pins.
    $disabled_second = $second;
    $disabled_second['enabled'] = false;
    $save_rows([$disabled_second, $first]);
    $enabled_only = array_values(array_filter(
        StorageFactory::get_instance()->get_rules('title_slug_rules'),
        static fn($r) => !empty($r['enabled'])
    ));
    $note('a disabled row still stores, and drops out of the enabled set',
        count($enabled_only) === 1 && $enabled_only[0]['name'] === 'Sweep 59 first');

    // ── 3. Tokens, resolved through the handler. ───────────────────────────

    $resolve = new ReflectionMethod(TitleSlugHandler::class, 'resolve_pattern');
    $token = static fn(string $pattern, string $context) => $resolve->invoke(
        $handler, $pattern, $post_id, $post, $context, $post->post_title
    );

    // {meta:} — expectation read straight from postmeta.
    $meta = (string) get_post_meta($post_id, 'mc_event_date', true);
    $note('{meta:} resolves in title context (' . $meta . ')',
        $meta !== '' && $token('{meta:mc_event_date}', 'title') === $meta);
    $note('{meta:} is sanitized in slug context',
        $token('{meta:mc_event_date}', 'slug') === sanitize_title($meta));

    // {date_*:} — the derived-precision tokens the fixture rule uses.
    $note('{date_year:} resolves to the meta date\'s year',
        $token('{date_year:mc_event_date}', 'slug') === substr($meta, 0, 4));

    // {term:} / {terms:} — expectations computed from the real term set.
    $terms = wp_get_object_terms($post_id, 'mc_topic');
    $note('the subject carries mc_topic terms to resolve',
        !is_wp_error($terms) && count($terms) > 0);

    if (!is_wp_error($terms) && $terms) {
        $names = wp_list_pluck($terms, 'name');
        $slugs = wp_list_pluck($terms, 'slug');
        sort($names);
        sort($slugs);

        $note('{term:TAX} is the first term name in title context',
            $token('{term:mc_topic}', 'title') === $names[0]);
        $note('{term:TAX} is the first term slug in slug context',
            $token('{term:mc_topic}', 'slug') === $slugs[0]);
        $note('{terms:TAX} comma-joins names in title context',
            $token('{terms:mc_topic}', 'title') === implode(', ', $names));
        $note('{terms:TAX} hyphen-joins slugs in slug context',
            $token('{terms:mc_topic}', 'slug') === implode('-', $slugs));
    }

    // {pub_*} — SITE-LOCAL publication date, not UTC. get_the_date() applies
    // the site timezone, which is the behaviour #59's AC names explicitly.
    $note('{pub_year} is the site-local publication year',
        $token('{pub_year}', 'title') === get_the_date('Y', $post));
    $note('{pub_month} is the site-local month NAME in title context',
        $token('{pub_month}', 'title') === get_the_date('F', $post));
    $note('{pub_month} is the site-local month NUMBER in slug context',
        $token('{pub_month}', 'slug') === get_the_date('m', $post));
    $note('{pub_day} is the site-local day',
        $token('{pub_day}', 'title') === get_the_date('j', $post));

    // {default_slug} — the token the seeded fixture rule leans on.
    $note('{default_slug} derives from the computed title',
        $token('{default_slug}', 'slug') === sanitize_title($post->post_title));

    // Nothing above saved a post, so no slug was rewritten.
    $note('the subject\'s post_name is untouched',
        get_post_field('post_name', $post_id) === $post->post_name);
} finally {
    update_option(OptionRuleStorage::OPTION_NAME, $snapshot);
    StorageFactory::get_instance()->clear_cache();

    $restored = get_option(OptionRuleStorage::OPTION_NAME, []);
    echo (($restored === $snapshot) ? 'PASS' : 'FAIL')
        . " settings option restored to its pre-sweep state\n";
    if ($restored !== $snapshot) {
        $fail[] = 'restore';
    }
}

echo $fail ? "\nSWEEP-59-BEHAVIOUR FAIL: " . count($fail) . " failed\n" : "\nSWEEP-59-BEHAVIOUR OK\n";
exit($fail ? 1 : 0);
