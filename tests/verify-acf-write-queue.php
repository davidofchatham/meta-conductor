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
 *   5. flush_post() mutates the pending set INSIDE the reentrancy guard. Outside
 *      it, a call arriving mid-flush clears the post and then returns without
 *      applying it — neither applied nor pending (§V26).
 *   6. The conversion tool stands the queue down for its own writes, at
 *      PHP_INT_MAX so an ordinary site filter cannot re-open US17.
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
    $errors[] = "WP_IMPORTING suppression gate missing — imports would recompute per post.";
}
if (!preg_match('/apply_filters\(\s*[\'"]meta_conductor_acf_reapply_enabled[\'"]/', $src)) {
    $errors[] = "meta_conductor_acf_reapply_enabled filter missing — the import decision must be overridable.";
}

// --- 2b. The gate is AUTHORITATIVE and its filter contract holds ------------
// The gate must sit on apply(), the choke point every flush path funnels
// through. Gating only the listener leaves flush_post() — the Admin Columns
// path — applying even when a site has switched the behaviour off.
// Pin the GATE, not the parameter's spelling — same reason as group 4 below.
if (!preg_match('/function\s+apply\s*\(\s*int\s+\$(\w+)\s*\)[^{]*\{/', $src, $am)) {
    $errors[] = 'apply(int $<param>) not found — the single apply choke point must exist.';
} elseif (!preg_match(
    '/function\s+apply\s*\(\s*int\s+\$' . preg_quote($am[1], '/') . '\s*\)[^{]*\{\s*if\s*\(\s*!\s*\$this->reapply_enabled\(\s*\$' . preg_quote($am[1], '/') . '\s*\)\s*\)/',
    $src
)) {
    $errors[] = 'apply() does not open with the reapply_enabled() gate — flush_post() would bypass the disable filter.';
}
// One decision site only, and it takes an int: that type IS the filter's
// promise that it never receives ACF's 'options'/'user_N'/'term_N' targets.
if (!preg_match('/function\s+reapply_enabled\s*\(\s*int\s+\$\w+\s*\)\s*:\s*bool/', $src)) {
    $errors[] = 'reapply_enabled(int $post_id): bool not found — the filter must be handed a real post ID, not an ACF pseudo-target.';
}
if (preg_match_all('/apply_filters\(\s*[\'"]meta_conductor_acf_reapply_enabled[\'"]/', $src) !== 1) {
    $errors[] = 'meta_conductor_acf_reapply_enabled is applied in more than one place — the gate must have a single decision site.';
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

// --- 4b. flush_post() mutates pending INSIDE the reentrancy guard ----------
// Unsetting outside guarded() means a call arriving mid-flush clears the post
// and then returns without applying it: neither applied nor pending, so the
// post silently keeps stale terms. (§V26)
if (!preg_match(
    '/function\s+flush_post\s*\(\s*int\s+\$(\w+)\s*\)[^{]*\{(.*?)\n    \}/s',
    $src,
    $fpm
)) {
    $errors[] = 'flush_post(int $<param>) not found.';
} else {
    $body  = $fpm[2];
    $param = preg_quote($fpm[1], '/');
    // The unset must appear only after guarded( opens.
    $guard_at = strpos($body, 'guarded(');
    $unset_at = false;
    if (preg_match('/unset\(\s*\$this->pending\[\s*\$' . $param . '\s*\]\s*\)/', $body, $um, PREG_OFFSET_CAPTURE)) {
        $unset_at = $um[0][1];
    }
    if ($unset_at === false) {
        $errors[] = 'flush_post() does not drop its post from the pending set — a later flush would apply it twice.';
    } elseif ($guard_at === false || $unset_at < $guard_at) {
        $errors[] = 'flush_post() unsets $this->pending OUTSIDE guarded() — a re-entrant call would drop the post without applying it (§V26).';
    }
}

// --- 5. The conversion tool suppresses reapply for its own writes ----------
// The conversion tool writes target fields with update_field(), so without an
// explicit stand-down every converted post is enqueued and reapplied — a
// second wave of rule processing on top of the heaviest run this plugin does
// (US17). A real conversion run is impractical to sweep, so the wiring is
// pinned here instead: same rationale as the AC Pro gate guard.
$dp = $root . '/includes/conversion/class-data-processor.php';
if (!is_file($dp)) {
    $errors[] = 'includes/conversion/class-data-processor.php missing.';
} else {
    $dpsrc = (string) file_get_contents($dp);

    if (!preg_match('/function\s+with_reapply_suppressed\s*\(\s*callable\s+\$\w+\s*\)/', $dpsrc)) {
        $errors[] = 'DataProcessor::with_reapply_suppressed(callable) not found — conversion writes would trigger a reapply per converted post (US17).';
    }
    // Must both add AND remove the filter: leaving it added would silently
    // disable reapply for the rest of the request.
    if (!preg_match('/add_filter\(\s*[\'"]meta_conductor_acf_reapply_enabled[\'"]/', $dpsrc)
        || !preg_match('/remove_filter\(\s*[\'"]meta_conductor_acf_reapply_enabled[\'"]/', $dpsrc)) {
        $errors[] = 'Conversion suppression must both add AND remove meta_conductor_acf_reapply_enabled — a one-way add leaks past the conversion.';
    }
    // At an ordinary priority a site filter registered later in the chain
    // would silently re-open US17. Both registrations must match, or the
    // remove_filter misses and the suppression leaks past the conversion.
    foreach (['add_filter', 'remove_filter'] as $fn) {
        $pattern = '/' . $fn . '\(\s*[\'"]meta_conductor_acf_reapply_enabled[\'"]\s*,\s*\$\w+\s*,\s*PHP_INT_MAX\s*\)/';
        if (!preg_match($pattern, $dpsrc)) {
            $errors[] = sprintf(
                'Conversion suppression %s does not use PHP_INT_MAX — an ordinary priority lets a late site filter re-open US17.',
                $fn
            );
        }
    }
    foreach (['process_copy_data_conversion', 'process_map_data_conversion', 'process_conversion_chunk'] as $entry) {
        $pattern = '/public\s+function\s+' . preg_quote($entry, '/') . '\s*\([^)]*\)\s*:\s*array\s*\{\s*return\s+\$this->with_reapply_suppressed\(/';
        if (!preg_match($pattern, $dpsrc)) {
            $errors[] = sprintf('DataProcessor::%s() does not route through with_reapply_suppressed() (US17).', $entry);
        }
    }
}

if ($errors) {
    fwrite(STDERR, "ACF-QUEUE FAIL — AcfWriteQueue invariants broken (#42):\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}

echo "ACF-QUEUE OK — claim priority, import/disable gate on apply(), single-site filter contract, post-ID gate, bounded-flush skip all intact (#42).\n";
exit(0);
