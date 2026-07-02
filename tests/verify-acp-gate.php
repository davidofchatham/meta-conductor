<?php
/**
 * H7 — Admin Columns Pro gate guard (SPEC §V5, B3).
 *
 * Fails if any executable `class_exists('ACP\Plugin')` (or the leading-backslash
 * variant) reappears under includes/. AC Pro v7 has NO `ACP\Plugin` class — it
 * bootstraps via `ACP\Loader` and defines the `ACP_VERSION` constant. Gating AC
 * Pro detection on that class is silently false on v7, so the v7 reapply
 * fallback (#37) never registers and the diagnostics readout misreports "Not
 * Active". The correct gate is `defined('ACP_VERSION')`.
 *
 * This is the recurrence trap of B3: the wrong gate reads plausibly and passes
 * php -l + autoload — only a live v7 site reveals it. Grep-guard it instead.
 *
 * Comments/strings that MENTION the trap (e.g. the explanatory code comments at
 * the fix sites) are allowed — the guard matches only the live call form
 * `class_exists( '...ACP\Plugin...' )`, and strips // and # line comments first.
 *
 * Run:  php tests/verify-acp-gate.php
 *
 * @package Meta_Conductor
 */

$root = dirname(__DIR__);
$rii  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
);

// Live call form: class_exists( <quote> [\]ACP\Plugin <quote> ). Escaped or
// literal backslash both matched. Case-insensitive on the function name.
$pattern = '/class_exists\s*\(\s*[\'"]\\\\?ACP\\\\\\\\?Plugin[\'"]/i';

$violations = [];
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $lines = file($f->getPathname(), FILE_IGNORE_NEW_LINES);
    foreach ($lines as $n => $line) {
        // Strip trailing line comments so an explanatory `// ...class_exists('ACP\Plugin')...`
        // doesn't trip the guard. Block comments explaining the trap are single-
        // line in this codebase; a `*`-prefixed docblock line has no live call.
        $code = preg_replace('~//.*$|#.*$~', '', $line);
        $code = preg_replace('~^\s*\*.*$~', '', $code);
        if ($code !== null && preg_match($pattern, $code)) {
            $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $f->getPathname());
            $violations[] = sprintf('%s:%d  %s', $rel, $n + 1, trim($line));
        }
    }
}

if ($violations) {
    fwrite(STDERR, "ACP-GATE FAIL — wrong AC Pro gate (use defined('ACP_VERSION'), not class_exists('ACP\\Plugin')). B3/§V5:\n");
    foreach ($violations as $v) {
        fwrite(STDERR, "  $v\n");
    }
    exit(1);
}

echo "ACP-GATE OK — no class_exists('ACP\\Plugin') gate in includes/ (B3/§V5).\n";
exit(0);
