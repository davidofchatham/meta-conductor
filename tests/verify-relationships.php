<?php
/**
 * FW-61 02 — The relationship module's contract, and what left the handlers.
 *
 * Static seam, no WordPress. Pins:
 *   - the `Relationship` interface: the six questions and nothing else (no kind
 *     method, no kind parameter), with the entity-neutral/post-only decision
 *     stated in its docblock;
 *   - both implementations implement it, and the pure facts they answer
 *     (written post types, status gating, the ACF sever key);
 *   - that the graph no longer lives in the two handlers.
 *
 * The behavior seam is the testbed sweeps through the dispatcher (the ticket-01
 * baseline); this file only keeps the module boundary from eroding.
 *
 * Run:  php tests/verify-relationships.php   (local PHP CLI, no WP needed)
 *
 * @package Meta_Conductor
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('BWS_META_CONDUCTOR_PATH', dirname(__DIR__) . '/');

foreach (['add_action', 'add_filter'] as $fn) {
    if (!function_exists($fn)) { eval("function {$fn}() {}"); }
}
if (!class_exists('WP_Post')) {
    class WP_Post {
        public function __construct(public string $post_type, public string $post_status) {}
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use BWS\MetaConductor\Relationships\AcfRelationship;
use BWS\MetaConductor\Relationships\ParentRelationship;
use BWS\MetaConductor\Relationships\Relationship;
use BWS\MetaConductor\RuleTypes\TermsFromRelatedPostRules;

$fail  = [];
$check = function (string $name, bool $cond) use (&$fail) { if (!$cond) { $fail[] = $name; } };

// --- The contract. ------------------------------------------------------------
$check('Relationship interface exists', interface_exists(Relationship::class));

$iface = new ReflectionClass(Relationship::class);
$methods = array_map(static fn($m) => $m->getName(), $iface->getMethods());
sort($methods);
$check('interface has exactly the six questions, no kind method', $methods === [
    'dependents_of', 'is_eligible_dependent', 'is_eligible_source',
    'sources_of', 'status_gates_source', 'written_post_types',
]);

$doc = preg_replace("/\s*\n\s*\*\s*/", " ", (string) $iface->getDocComment());
$check('docblock: each implementation fixes the entity kind of each end', stripos($doc, 'fixes the entity kind') !== false);
$check('docblock: both ends are posts for both implementations', stripos($doc, 'both ends are posts') !== false);

foreach ($iface->getMethods() as $m) {
    foreach ($m->getParameters() as $p) {
        $check("no kind parameter on {$m->getName()}", stripos($p->getName(), 'kind') === false);
    }
}

$check('ParentRelationship implements Relationship', is_subclass_of(ParentRelationship::class, Relationship::class));
$check('AcfRelationship implements Relationship', is_subclass_of(AcfRelationship::class, Relationship::class));

// --- Pure facts. ---------------------------------------------------------------
$parent = new ParentRelationship();
$acf    = new AcfRelationship();

$check('parent writes post_types', $parent->written_post_types(['post_types' => ['page']]) === ['page']);
$check('parent empty post_types = every type', $parent->written_post_types([]) === []);
$check('parent "any" = every type', $parent->written_post_types(['post_types' => ['any']]) === []);
$check('parent: status gates the written post, not a source', $parent->status_gates_source([]) === false);

$check('acf push writes any type', $acf->written_post_types(['holder_role' => 'source', 'post_type' => 'a']) === []);
$check('acf pull writes the holder type', $acf->written_post_types(['holder_role' => 'target', 'post_type' => 'a']) === ['a']);
$check('acf absent holder_role is pull', $acf->written_post_types(['post_type' => 'a']) === ['a']);
$check('acf pull with no holder type = every type', $acf->written_post_types(['holder_role' => 'target']) === []);
$check('acf: status gates the source', $acf->status_gates_source([]) === true);

// FW-61 04: `post_types` is an OPTIONAL narrowing of the receiving post.
$check('acf push scoped by post_types writes those', $acf->written_post_types(['holder_role' => 'source', 'post_types' => ['page']]) === ['page']);
$check('acf push, "any" = every type', $acf->written_post_types(['holder_role' => 'source', 'post_types' => ['any']]) === []);
$page = new WP_Post('page', 'draft');
$post = new WP_Post('post', 'draft');
$check('acf push: a dependent must pass post_types',
    !$acf->is_eligible_dependent($post, ['holder_role' => 'source', 'post_types' => ['page']])
    && $acf->is_eligible_dependent($page, ['holder_role' => 'source', 'post_types' => ['page']]));
$check('acf: empty post_types = no extra narrowing',
    $acf->is_eligible_dependent($post, ['holder_role' => 'source', 'post_types' => []])
    && $acf->is_eligible_dependent($post, ['holder_role' => 'source']));
$check('acf: post_types never gates the SOURCE',
    $acf->is_eligible_source($post, ['holder_role' => 'target', 'post_types' => ['page']]));
$check('acf: post_status never gates eligibility (the handler gates the source)',
    $acf->is_eligible_dependent($page, ['holder_role' => 'source', 'post_status' => ['publish']]));

// The sever record is keyed by the fields that decide which end feeds which, and
// by the relationship, so a parent row and an ACF row never share a record.
$row = ['relationship' => 'acf', 'taxonomy' => 't', 'post_type' => 'h', 'acf_field_name' => 'f', 'reverse_acf_field_name' => 'r', 'holder_role' => 'source'];
$check('sever key shape', $acf->sever_key($row) === 'acf|t|h|f|r|source');
$check('sever key: absent holder_role reads as target', $acf->sever_key(['taxonomy' => 't']) === 'acf|t||||target');
foreach (['relationship', 'taxonomy', 'post_type', 'acf_field_name', 'reverse_acf_field_name', 'holder_role'] as $f) {
    $other = $row;
    $other[$f] = 'x';
    $check("sever key distinguishes {$f}", $acf->sever_key($other) !== $acf->sever_key($row));
}

// The descriptor answers through the module.
$merged = new TermsFromRelatedPostRules();
$check('descriptor written_post_types delegates (acf)', $merged->written_post_types($row) === $acf->written_post_types($row));
$check('descriptor status_gates_source delegates (acf)', $merged->status_gates_source($row) === true);
$check('descriptor written_post_types delegates (parent)', $merged->written_post_types(['relationship' => 'parent', 'post_types' => ['page']]) === ['page']);
$check('descriptor status_gates_source (parent)', $merged->status_gates_source(['relationship' => 'parent']) === false);
$check('descriptor: a row with no known relationship writes every type and gates the written post',
    $merged->written_post_types(['post_types' => ['page']]) === [] && $merged->status_gates_source([]) === false);

// FW-61 03: post_types scopes the RECEIVING post; a parent is gated on status only.
$page_parent = new WP_Post('page', 'draft');
$post_parent = new WP_Post('post', 'publish');
$gated       = ['post_types' => ['page'], 'post_status' => ['publish']];
$check('parent: a dependent must pass post_types', !$parent->is_eligible_dependent($post_parent, ['post_types' => ['page']]));
$check('parent: a dependent must pass post_status', !$parent->is_eligible_dependent($page_parent, $gated));
$check('parent: a source is NOT gated on post_types', $parent->is_eligible_source($post_parent, $gated));
$check('parent: a source is gated on post_status', !$parent->is_eligible_source($page_parent, $gated));

// --- The graph left the handlers. ------------------------------------------------
$strip = static function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$prop = $strip((string) file_get_contents(dirname(__DIR__) . '/includes/handlers/class-terms-from-related-post-handler.php'));

foreach (['find_holders_referencing', 'resolve_reverse', 'acf_bidirectional_partners', 'acf_field_target_post_types',
          'reverse_lookup_cache', 'field_target_types_cache', 'record_sever', 'function sever_key', 'private array $severed',
          'pending_marks', 'extract_ids', 'read_relationship'] as $needle) {
    $check("ACF graph out of the handler: {$needle}", strpos($prop, $needle) === false);
}
foreach (['child_cache', 'CHILD_STATUSES', 'resolve_child_post_types', 'get_posts('] as $needle) {
    $check("parent query out of the handler: {$needle}", strpos($prop, $needle) === false);
}

// Kept in the handler by design.
$check('parent lost-terms capture stays in the related-post handler', strpos($prop, 'captured_removals') !== false);
$check('zero-source gate stays in the related-post handler', strpos($prop, 'empty($sources)') !== false);

// The two old handlers and descriptors are gone, not aliased.
foreach (['class-related-post-terms-handler.php' => 'handlers', 'class-related-post-terms-rules.php' => 'rule-types'] as $file => $dir) {
    $check("deleted: includes/{$dir}/{$file}", !file_exists(dirname(__DIR__) . "/includes/{$dir}/{$file}"));
}

if ($fail) {
    fwrite(STDERR, "FAIL verify-relationships:\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "RELATIONSHIPS OK
";
