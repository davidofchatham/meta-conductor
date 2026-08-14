<?php
/**
 * H9 — Claim-override flattening harness (#55).
 *
 * Locks `OptionRuleStorage::flatten_conflict_overrides()`, the storage-adapter
 * coercion from the General tab's per-taxonomy claim-override repeater ROWS
 * `[{taxonomy, mode}, ...]` to the canonical `{taxonomy_slug: mode}` dict
 * (ADR 0004). The rows exist only because Wireframe's Sanitizer skips
 * dot-notation field ids (CLAUDE.md don't #4), so this is the one way back.
 *
 * Why a harness for eight lines: the method has **no runtime consumer**.
 * Nothing reads the per-taxonomy default when a rule omits its own claim —
 * handlers still fall back to a hard-coded `merge` — so it is unreachable from
 * any behaviour sweep, and would rot silently. It survived the deletion of the
 * `Settings` shell that used to hold it because the Phase 4 dispatcher (#53)
 * is where the precedence question gets settled and this is what it will call.
 * Until then these assertions are the only thing keeping it honest.
 *
 * Runs WITHOUT booting WordPress: the method is a pure function of its input,
 * and the class file only DECLARES at load, so requiring it (plus the interface
 * it implements) is enough.
 *
 * Run:  php tests/verify-claim-overrides.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// --- Load the class under test (declare-only, no WP at load). ----------------

require dirname(__DIR__) . '/includes/storage/class-rule-storage.php';
require dirname(__DIR__) . '/includes/storage/class-option-rule-storage.php';

use BWS\MetaConductor\Storage\OptionRuleStorage;

// --- Assertions. ------------------------------------------------------------

$fail  = [];
$check = function (string $name, bool $cond) use (&$fail) {
    if (!$cond) {
        $fail[] = $name;
    }
};

// The happy path: rows in, dict out, keyed on the taxonomy slug. The extra
// `row_title` subfield (a save-time label snapshot) must be ignored, not
// carried into the dict.
$flat = OptionRuleStorage::flatten_conflict_overrides([
    ['taxonomy' => 'category', 'mode' => 'replace', 'row_title' => 'Categories: owning'],
    ['taxonomy' => 'post_tag', 'mode' => 'skip'],
]);
$check('keys on taxonomy slug', $flat === ['category' => 'replace', 'post_tag' => 'skip']);
$check('no overrides ⇒ empty dict', OptionRuleStorage::flatten_conflict_overrides([]) === []);

// A half-filled row is what an "Add taxonomy override" click leaves behind
// before either select is touched. It must not land a '' key or a '' mode —
// either would read back as an override on a taxonomy that doesn't exist.
$partial = OptionRuleStorage::flatten_conflict_overrides([
    ['taxonomy' => '',       'mode' => 'merge'],
    ['taxonomy' => 'genre',  'mode' => ''],
    ['taxonomy' => 'season', 'mode' => 'merge'],
]);
$check('incomplete rows dropped', $partial === ['season' => 'merge']);

// A missing key is not the same as an empty one — hand-seeded and legacy rows
// may omit a subfield entirely rather than store ''.
$sparse = OptionRuleStorage::flatten_conflict_overrides([
    ['mode' => 'replace'],
    ['taxonomy' => 'genre'],
    ['taxonomy' => 'season', 'mode' => 'merge'],
]);
$check('rows missing a key dropped', $sparse === ['season' => 'merge']);

// The dict cannot hold a taxonomy twice; the later row is the one the author
// edited most recently, so it wins. (The UI does not prevent the duplicate —
// that is the collision warning queued for Phase 4.)
$dupe = OptionRuleStorage::flatten_conflict_overrides([
    ['taxonomy' => 'category', 'mode' => 'merge'],
    ['taxonomy' => 'category', 'mode' => 'replace'],
]);
$check('last duplicate row wins', $dupe === ['category' => 'replace']);

// Stored values stay the legacy merge|replace|skip triple — the claim rename
// (ADR 0004) was wording only. A dict emitting domain words here would mean a
// migration nobody wrote.
$vals = OptionRuleStorage::flatten_conflict_overrides([
    ['taxonomy' => 'a', 'mode' => 'merge'],
    ['taxonomy' => 'b', 'mode' => 'replace'],
    ['taxonomy' => 'c', 'mode' => 'skip'],
]);
$check('stored values pass through unmapped', array_values($vals) === ['merge', 'replace', 'skip']);

// --- Report. ----------------------------------------------------------------

$total = 6;
if ($fail) {
    fwrite(STDERR, "\nCLAIM-OVERRIDES FAIL — " . count($fail) . "/$total assertions failed:\n");
    foreach ($fail as $f) {
        fwrite(STDERR, "  \xE2\x9C\x97 $f\n");
    }
    exit(1);
}

fwrite(STDOUT, "CLAIM-OVERRIDES OK — all $total assertions passed (row ⇒ dict adapter, #55).\n");
exit(0);
