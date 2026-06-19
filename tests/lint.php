<?php
/**
 * H1 — Syntax-lint sweep (Phase 2a).
 *
 * Runs `php -l` over every .php file under includes/ plus the plugin root files
 * (autoload.php, meta-conductor.php). Skips vendor/, libs/, node_modules. Exits
 * non-zero if any file has a parse error.
 *
 * Catches the namespace-after-guard fatal (SPEC §V12) and any malformed edit
 * before sync. Pairs with verify-autoload.php (H2) which checks resolution.
 *
 * Run:  php tests/lint.php
 *
 * @package Meta_Conductor
 */

$root = dirname(__DIR__);
$targets = [$root . '/autoload.php', $root . '/meta-conductor.php'];

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $f) {
    if ($f->getExtension() === 'php') {
        $targets[] = $f->getPathname();
    }
}

$php = PHP_BINARY;
$failures = [];
foreach ($targets as $file) {
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) {
        $failures[$file] = implode("\n", $out);
    }
    $out = [];
}

$rel = fn($p) => str_replace($root . DIRECTORY_SEPARATOR, '', $p);
if ($failures) {
    fwrite(STDERR, "\nLINT FAIL — " . count($failures) . " file(s) with parse errors:\n");
    foreach ($failures as $file => $msg) {
        fwrite(STDERR, "  ✗ " . $rel($file) . "\n    " . str_replace("\n", "\n    ", $msg) . "\n");
    }
    exit(1);
}

// --- V1 static check (SPEC §V1 / §B3): no manual require/include of a plugin
// file anywhere under includes/, including method bodies. Such requires only
// run at call-time so the autoload harness can't see them — they fatal live.
// WP-core requires (wp-admin/includes/*) are allowed.
$v1 = [];
foreach ($targets as $file) {
    $norm = str_replace('\\', '/', $file);
    if (strpos($norm, '/includes/') === false) {
        continue; // only enforce inside includes/
    }
    foreach (file($file) as $n => $line) {
        if (preg_match('/\b(require|include)(_once)?\b.*(includes\/|PLUGIN_DIR|class-[a-z-]+\.php)/', $line)
            && strpos($line, 'wp-admin/includes') === false) {
            $v1[] = $rel($file) . ':' . ($n + 1) . '  ' . trim($line);
        }
    }
}
if ($v1) {
    fwrite(STDERR, "\nV1 FAIL — manual plugin-file require/include in includes/ (use the autoloader):\n");
    foreach ($v1 as $hit) {
        fwrite(STDERR, "  ✗ $hit\n");
    }
    exit(1);
}

fwrite(STDOUT, "LINT OK — " . count($targets) . " files, no syntax errors. V1 clean (no manual requires).\n");
exit(0);
