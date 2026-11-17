<?php
/**
 * MetaWriter contract (FW-5 03; the FW-46 acceptance bar).
 *
 * Standalone, no WP: the four WP/ACF functions the helper calls are stubbed and
 * record what they were handed. Proves the routing and the refusal, not ACF.
 *
 * Run:  php tests/verify-meta-writer.php
 *
 * @package Meta_Conductor
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mw_calls']  = [];
$GLOBALS['mw_fields'] = [];   // name => ACF field array, what acf_maybe_get_field() knows.

function wp_slash($v) {
    return is_array($v) ? array_map('wp_slash', $v) : (is_string($v) ? addslashes($v) : $v);
}
function acf_maybe_get_field($name, $post_id = false, $strict = true) {
    return $GLOBALS['mw_fields'][$name] ?? false;
}
function update_field($selector, $value, $post_id) {
    $GLOBALS['mw_calls'][] = ['update_field', $selector, $value, $post_id];
    return true;
}
function update_post_meta($post_id, $key, $value) {
    $GLOBALS['mw_calls'][] = ['update_post_meta', $key, $value, $post_id];
    return true;
}

require dirname(__DIR__) . '/includes/core/class-meta-writer.php';

use BWS\MetaConductor\Core\MetaWriter;

$fail = 0;
function mw_check(string $label, $got, $want): void {
    global $fail;
    if ($got !== $want) {
        $fail++;
        fwrite(STDERR, "FAIL $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n");
    }
}
function mw_run(string $name, $value, string $key = ''): array {
    $GLOBALS['mw_calls'] = [];
    $ok = MetaWriter::write(7, $name, $value, $key);
    return [$ok, $GLOBALS['mw_calls']];
}

// A plain key is written as post meta, slashed once.
[$ok, $calls] = mw_run('plain_key', 'a\\b');
mw_check('plain: ok', $ok, true);
mw_check('plain: update_post_meta, slashed', $calls, [['update_post_meta', 'plain_key', 'a\\\\b', 7]]);

// A name that matches an ACF field goes through ACF by that field's KEY.
$GLOBALS['mw_fields']['acf_name'] = ['key' => 'field_acf_name', 'name' => 'acf_name'];
[$ok, $calls] = mw_run('acf_name', 'v');
mw_check('acf name: ok', $ok, true);
mw_check('acf name: update_field by key', $calls, [['update_field', 'field_acf_name', 'v', 7]]);

// An explicit key skips resolution and writes through ACF.
[$ok, $calls] = mw_run('whatever', 'v', 'field_given');
mw_check('given key: update_field by that key', $calls, [['update_field', 'field_given', 'v', 7]]);

// A `_`-prefixed name is refused: nothing written, false returned.
[$ok, $calls] = mw_run('_edit_lock', 'x');
mw_check('underscore: refused', $ok, false);
mw_check('underscore: nothing written', $calls, []);
$GLOBALS['mw_fields']['_acf_private'] = ['key' => 'field_p'];
[$ok, $calls] = mw_run('_acf_private', 'x');
mw_check('underscore matching an ACF field: still refused', [$ok, $calls], [false, []]);

// An empty name is refused.
[$ok, $calls] = mw_run('', 'x');
mw_check('empty name: refused', [$ok, $calls], [false, []]);

// A raw array passes through, each string slashed, nothing cast.
[$ok, $calls] = mw_run('plain_key', [12, '13', ['q' => "it's"]]);
mw_check('array: raw, slashed per string', $calls, [['update_post_meta', 'plain_key', [12, '13', ['q' => "it\\'s"]], 7]]);

// An empty string is a write, not a skip.
[$ok, $calls] = mw_run('plain_key', '');
mw_check('empty value: written', $calls, [['update_post_meta', 'plain_key', '', 7]]);

mw_check('acceptable: plain', [MetaWriter::acceptable('a'), MetaWriter::acceptable('a_b')], [true, true]);
mw_check('acceptable: empty and _-prefixed refused', [MetaWriter::acceptable(''), MetaWriter::acceptable('_a')], [false, false]);

if ($fail) {
    fwrite(STDERR, "META-WRITER FAIL — $fail check(s).\n");
    exit(1);
}
echo "META-WRITER OK\n";
