<?php
/**
 * H8 — ACF write queue invariant guard (#42).
 *
 * AcfWriteQueue is correct because of four properties that are pure hook
 * constants and control-flow gates. None of them has a runtime-observable
 * signature until it is ALREADY wrong on a live site — a broken claim priority
 * just means the handlers silently stop running, and a missing import gate just
 * means an import quietly recomputes ten thousand posts. So they are guarded by
 * source inspection, the same way and for the same reason as the AC Pro gate
 * guard (tests/verify-acp-gate.php).
 *
 * Guarded:
 *   1. CLAIM_PRIORITY sits ABOVE every handler's own registration. The latest
 *      is TitleSlugHandler at acf/save_post priority 99; claiming below that
 *      would drop the post from the pending set before the handlers ran, so
 *      neither the ordinary path NOR the flush would apply it.
 *   2. The WP_IMPORTING suppression gate is present. Without it an import
 *      triggers one full rule recompute per imported post.
 *   3. The target gate is a POSITIVE INTEGER post ID. ACF passes 'options',
 *      'user_5' and 'term_3' through the same filter; without the gate those
 *      pseudo-targets would be cast to 0 and enqueued as a bogus post.
 *   4. The bounded mid-request flush SKIPS the post currently being recorded.
 *      acf/update_value is a PRE-write filter, so flushing the in-flight post
 *      would read its old value and write terms from it.
 *
 * Run:  php tests/verify-acf-write-queue.php
 *
 * @package Meta_Conductor
 */

$root   = dirname(__DIR__);
$queue  = $root . '/includes/core/class-acf-write-queue.php';
$errors = [];

if (!is_file($queue)) {
    fwrite(STDERR, "ACF-QUEUE FAIL — includes/core/class-acf-write-queue.php missing.\n");
    exit(1);
}
$src = (string) file_get_contents($queue);

// --- 1. Claim priority above the latest handler (TitleSlugHandler, 99) ------
if (!preg_match('/const\s+CLAIM_PRIORITY\s*=\s*(\d+)\s*;/', $src, $m)) {
    $errors[] = 'CLAIM_PRIORITY constant not found — the claim priority must be a named constant.';
} elseif ((int) $m[1] <= 99) {
    $errors[] = sprintf(
        'CLAIM_PRIORITY is %d — must exceed 99 (TitleSlugHandler::acf/save_post), or the claim runs before the handlers.',
        (int) $m[1]
    );
}
// Both claim registrations must actually USE the constant.
foreach (['save_post', 'acf/save_post'] as $hook) {
    $pattern = '/add_action\(\s*[\'"]' . preg_quote($hook, '/') . '[\'"]\s*,\s*\[\s*\$this\s*,\s*[\'"]claim[\'"]\s*\]\s*,\s*self::CLAIM_PRIORITY/';
    if (!preg_match($pattern, $src)) {
        $errors[] = sprintf("claim() is not registered on '%s' at self::CLAIM_PRIORITY.", $hook);
    }
}

// --- 2. Import suppression gate --------------------------------------------
if (!preg_match('/defined\(\s*[\'"]WP_IMPORTING[\'"]\s*\)/', $src)) {
    $errors[] = "WP_IMPORTING suppression gate missing from record() — imports would recompute per post.";
}
if (!preg_match('/apply_filters\(\s*[\'"]meta_conductor_acf_reapply_enabled[\'"]/', $src)) {
    $errors[] = "meta_conductor_acf_reapply_enabled filter missing — the import decision must be overridable.";
}

// --- 3. Positive-integer post-ID target gate -------------------------------
if (!preg_match('/is_numeric\(\s*\$post_id\s*\)/', $src)) {
    $errors[] = "record() does not is_numeric-gate \$post_id — ACF 'options'/'user_N'/'term_N' targets would enqueue.";
}
if (!preg_match('/\$id\s*<=\s*0/', $src)) {
    $errors[] = "record() does not reject non-positive post IDs.";
}

// --- 4. Bounded flush skips the in-flight post -----------------------------
// Pin the SKIP, not the parameter's spelling — a clarifying rename should not
// fail this test. Capture whatever the parameter is called, then require the
// loop to skip that same variable.
if (!preg_match('/function\s+flush_pending_except\s*\(\s*int\s+\$(\w+)\s*\)/', $src, $pm)) {
    $errors[] = 'flush_pending_except(int $<param>) not found — the bounded flush must be able to skip a post.';
} elseif (!preg_match('/if\s*\(\s*\$id\s*===\s*\$' . preg_quote($pm[1], '/') . '\s*\)\s*\{\s*continue;/', $src)) {
    $errors[] = 'The bounded flush does not skip $' . $pm[1] . ' — acf/update_value is a PRE-write filter, so the in-flight post would be read stale.';
}
if (!preg_match('/flush_pending_except\(\s*\$id\s*\)/', $src)) {
    $errors[] = 'record() does not pass the post being recorded to flush_pending_except.';
}

if ($errors) {
    fwrite(STDERR, "ACF-QUEUE FAIL — AcfWriteQueue invariants broken (#42):\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}

echo "ACF-QUEUE OK — claim priority, import gate, post-ID gate, bounded-flush skip all intact (#42).\n";
exit(0);
