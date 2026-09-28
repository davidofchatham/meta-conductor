<?php
/**
 * H13 — Term dispatcher invariant guard (#60, Phase 4 Gate 2).
 *
 * The dispatcher is correct because of properties that are hook registrations,
 * call-site counts and control-flow gates. None of them has a runtime-observable
 * signature until it is ALREADY wrong on a live site: a handler that kept a hook
 * just runs its rules twice, once out of authored order; a second caller of
 * apply_to_post just executes a rule outside any pass, with no lock held and no
 * order around it; a stray `private $processing` just silences the author's own
 * chain after its first write. All three look like working code. So they are
 * guarded by source inspection, the same way and for the same reason as
 * tests/verify-acf-write-queue.php (H8) and tests/verify-acp-gate.php (H6).
 *
 * Guarded:
 *   1. The dispatcher exists, owns the trigger union, and drains at shutdown
 *      AFTER AcfWriteQueue::flush — which marks posts dirty as it flushes, so
 *      draining first would leave them queued with nothing left to drain them.
 *      It also drains on the save path, without which an editor save renders
 *      pre-pass terms until reload, and it gates the pass on the established
 *      "do not recompute" switch rather than on the triggers.
 *   2. Every trigger is MARK-ONLY. A trigger that executed would make the pass
 *      count one-per-trigger, and one editor save fires six-ish of them.
 *   3. The pass lock is keyed (entity, effect kind) and released in `finally`.
 *   4. The dirty queue sits ABOVE the lock: mark_dirty() consults it, so a
 *      rule's own write is the pass's echo rather than a new signal.
 *   5. `apply_to_post` has exactly ONE call site in the whole plugin, in the
 *      dispatcher. This is the invariant the whole ticket rests on.
 *   6. Handlers register NOTHING outside their descriptor's `capture_hooks()`,
 *      and carry no re-entrancy boolean. A capture hook's callback may record
 *      and must not apply (6b). Every type is a pure applier (FW-39), so the
 *      registry — not a dispatcher constant — is where the exceptions live.
 *   7. (Retired with UNCONVERTED_TYPES — no type owns its apply hooks.)
 *   8. The time-based cron sweep is still registered — outside the handler,
 *      which registers nothing — and ENQUEUES rather than removing
 *      terms itself: a provocation names entities, a pass decides their fate.
 *   9. Each pass runs exactly its own kind: `owns()` asks the descriptor's
 *      `kind()`, the pass filters through it, and no dispatcher keeps a
 *      hand-written type list.
 *  10. Cross-entity rules DECLARE their reach and the pass enqueues it: the
 *      `fan_out()` seam exists on the base, the pass marks what it returns
 *      dirty, and a handler that overrides it writes only the post it
 *      was handed. A handler that reached the far entity directly would
 *      reconcile it by one rule, out of authored order — which is #35, the
 *      defect #62 exists to remove, and it looks like working code.
 *  11. The CAPTURE QUEUE reaches the dirty queue. A capture names an entity
 *      nothing else points at — the relationship that would have named it is
 *      what the write destroyed — so `drain_captures()` on the base,
 *      `enqueue_captures()` on the dispatcher and the call to it from `drain()`
 *      are the whole path a sever has. Break any link and the sever is recorded
 *      and then silently never applied (#63).
 *  12. The FORMAT kind has its own dispatcher, driven from the SAME drain, and
 *      the term pass runs first (#64). Cross-kind order is derived, not
 *      authored: `title_slug` reads terms and writes none. Every failure here
 *      produces a plausible title rather than a visible fault — the format pass
 *      running before the term pass reads the PREVIOUS save's terms; a format
 *      handler back on its own hook orders itself against the term pass by
 *      priority again; an applier called outside a pass writes nothing at all,
 *      which reads as an unconfigured rule.
 *  13. The EXISTING-POSTS APPLIER (FW-16 04) is a provocation, not a pass: it
 *      calls `drain_post()` per post and `drain()` once, never an `apply()`,
 *      an applier seam or a write primitive, and clears its one-time row
 *      override in a `finally`. Its preview (FW-16 06) reads the format pass
 *      through `compute()`, never drains, and clears the override the same way.
 *  14. Each dispatcher's `apply()` has ONE caller, its own pass: `run_pass()`
 *      for terms, `compute()` for formats. Nothing outside a dispatcher names
 *      either (FW-16 07, which retired the per-handler bulk path).
 *
 * Run:  php tests/verify-term-dispatcher.php
 *
 * @package Meta_Conductor
 */

$root   = dirname(__DIR__);
$errors = [];

// Which types belong to each kind is the registry's answer (FW-39) — load it
// rather than regex-read a list. Declaring the descriptors executes nothing.
define('ABSPATH', $root . '/');
define('BWS_META_CONDUCTOR_PATH', $root . '/');
require $root . '/autoload.php';
$kind_types = static fn(string $kind): array => array_keys(
    \BWS\MetaConductor\RuleTypes\Registry::of_kind($kind)
);

$dispatcher_file = $root . '/includes/core/class-term-dispatcher.php';
if (!is_file($dispatcher_file)) {
    fwrite(STDERR, "DISPATCHER FAIL — includes/core/class-term-dispatcher.php missing.\n");
    exit(1);
}
/**
 * Blank out comments while preserving line numbering.
 *
 * Every check below reads CODE, and this harness's own subject matter is
 * discussed at length in the comments of the files it inspects — the phrase
 * "apply_to_post()" appears in half a dozen docblocks explaining that there is
 * only one call site, and a `$processing` deletion is recorded in a comment
 * exactly where the property used to be. Grepping raw source would fail on the
 * prose describing the invariant holding.
 *
 * @param string $code PHP source.
 * @return string Same source, comments replaced by their own newlines.
 */
$strip_comments = static function (string $code): string {
    $out = '';
    foreach (token_get_all($code) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }

    return $out;
};

$src = $strip_comments((string) file_get_contents($dispatcher_file));

// --- 1. Trigger union + drain ordering -------------------------------------
// Mark-only registrations, so priority is meaningless on them; the drain's
// priority is not, and it is the one pinned.
// `deleted_term_relationships` is the one term write `set_object_terms` does
// NOT cover: wp_remove_object_terms() fires it and nothing else, so without it
// a term taken off a post that way provokes no pass at all (#62).
$triggers = ['set_object_terms', 'deleted_term_relationships', 'save_post', 'acf/save_post'];
foreach ($triggers as $hook) {
    $pattern = '/add_action\(\s*[\'"]' . preg_quote($hook, '/') . '[\'"]\s*,\s*\[\s*\$this\s*,\s*[\'"](\w+)[\'"]\s*\]/';
    if (!preg_match($pattern, $src)) {
        $errors[] = sprintf("The dispatcher does not register the '%s' trigger — that entry point starts no pass.", $hook);
    }
}

if (!preg_match('/const\s+DRAIN_PRIORITY\s*=\s*(\d+)\s*;/', $src, $m)) {
    $errors[] = 'DRAIN_PRIORITY constant not found — the shutdown drain priority must be a named constant.';
} else {
    $drain_priority = (int) $m[1];

    $queue_file = $root . '/includes/core/class-acf-write-queue.php';
    $flush_priority = null;
    if (is_file($queue_file)) {
        $qsrc = $strip_comments((string) file_get_contents($queue_file));
        if (preg_match('/add_action\(\s*[\'"]shutdown[\'"]\s*,\s*\[\s*\$this\s*,\s*[\'"]flush[\'"]\s*\]\s*,\s*(\d+)/', $qsrc, $qm)) {
            $flush_priority = (int) $qm[1];
        }
    }
    if ($flush_priority === null) {
        $errors[] = "AcfWriteQueue's shutdown flush registration not found — cannot verify the drain runs after it.";
    } elseif ($drain_priority <= $flush_priority) {
        $errors[] = sprintf(
            'DRAIN_PRIORITY is %d, at or below AcfWriteQueue::flush (%d) — posts the flush marks dirty would never be drained.',
            $drain_priority,
            $flush_priority
        );
    }

    if (!preg_match('/add_action\(\s*[\'"]shutdown[\'"]\s*,\s*\[\s*\$this\s*,\s*[\'"]drain[\'"]\s*\]\s*,\s*self::DRAIN_PRIORITY/', $src)) {
        $errors[] = 'drain() is not registered on shutdown at self::DRAIN_PRIORITY.';
    }
}

// --- 1b. The editor-visible drain -----------------------------------------
// shutdown fires after the response is built, so without a drain on the save
// path a block-editor save renders the author's raw term selection and only
// shows the pass's result on reload. That is a REGRESSION against the old
// per-handler hooks, which applied inside set_object_terms — invisible to any
// static check and easy to lose in a refactor of register().
if (!preg_match('/add_action\(\s*[\'"]wp_after_insert_post[\'"]\s*,\s*\[\s*\$this\s*,\s*[\'"]drain[\'"]\s*\]/', $src)) {
    $errors[] = 'drain() is not registered on wp_after_insert_post — an editor save would render pre-pass terms until reload.';
}

// --- 1c. The off switch is on the pass, not the triggers -------------------
// Same argument as the ACF queue's gate (H8, group 2b): gating the triggers
// leaves the direct entry points — the Admin Columns bridge's drain_post() and
// bulk apply's run_pass() — executing regardless. It must also honour the
// ESTABLISHED switch, or the fixture seeder's empty-rules-then-restore window
// reopens: it stands the ACF queue down, and the pass now happens on a drain
// that lands after the seeder restores the rules.
if (!preg_match('/function\s+run_pass\s*\(\s*int\s+\$(\w+)\s*\)\s*:\s*int\s*\{(.*?)\n    \}/s', $src, $gm)) {
    // Already reported by group 3.
} elseif (!preg_match('/\$this->pass_enabled\(/', $gm[2])) {
    $errors[] = 'run_pass() does not consult pass_enabled() — the disable gate must sit on the pass, or drain_post() and bulk apply bypass it.';
}
if (!preg_match('/function\s+pass_enabled\s*\(\s*int\s+\$\w+\s*\)\s*:\s*bool\s*\{(.*?)\n    \}/s', $src, $pem)) {
    $errors[] = 'pass_enabled(int $post_id): bool not found — the pass needs a single decision site for "turn this off".';
} else {
    foreach (
        [
            'WP_IMPORTING'                        => 'the import stand-down',
            'meta_conductor_acf_reapply_enabled'  => "the established off switch (the seeder uses it, and the pass now runs after the seeder restores its rules)",
            'meta_conductor_term_pass_enabled'    => 'the pass-specific override',
        ] as $needle => $what
    ) {
        if (strpos($pem[1], $needle) === false) {
            $errors[] = sprintf('pass_enabled() does not consult %s — %s is missing.', $needle, $what);
        }
    }
}

// --- 2. Triggers are MARK-ONLY ---------------------------------------------
// Each trigger callback's body may call mark_dirty and nothing else. A trigger
// that ran a pass would restore one-pass-per-trigger, which is the defect the
// queue exists to remove.
foreach (['on_terms_set', 'on_terms_deleted', 'on_save_post', 'on_acf_save_post'] as $cb) {
    if (!preg_match('/function\s+' . $cb . '\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $src, $bm)) {
        $errors[] = sprintf('Trigger callback %s() not found (or not `: void`).', $cb);
        continue;
    }
    $body = $bm[1];
    if (!preg_match('/\$this->mark_dirty\(/', $body)) {
        $errors[] = sprintf('%s() does not mark the entity dirty.', $cb);
    }
    foreach (['run_pass', 'drain', 'apply'] as $executor) {
        if (preg_match('/\b' . $executor . '\s*\(/', $body)) {
            $errors[] = sprintf(
                '%s() calls %s() — triggers must MARK only, or one save runs a pass per trigger.',
                $cb,
                $executor
            );
        }
    }
}

// --- 3. Pass lock: keyed (entity, kind), released in finally ---------------
if (!preg_match('/function\s+pass_key\s*\(\s*string\s+\$(\w+)\s*,\s*int\s+\$(\w+)\s*\)\s*:\s*string\s*\{(.*?)\n    \}/s', $src, $km)) {
    $errors[] = 'pass_key(string $kind, int $entity_id): string not found — the lock must be keyed on BOTH the kind and the entity.';
} else {
    [$whole, $kind_param, $entity_param, $key_body] = $km;
    if (!preg_match('/\$' . preg_quote($kind_param, '/') . '\b/', $key_body)
        || !preg_match('/\$' . preg_quote($entity_param, '/') . '\b/', $key_body)) {
        $errors[] = 'pass_key() does not use both parameters — dropping the entity makes the lock request-scoped, dropping the kind makes it global (ADR 0003 decision 4).';
    }
}

if (!preg_match('/function\s+run_pass\s*\(\s*int\s+\$(\w+)\s*\)\s*:\s*int\s*\{(.*?)\n    \}/s', $src, $rm)) {
    $errors[] = 'run_pass(int $post_id): int not found.';
} else {
    $body = $rm[2];
    if (!preg_match('/pass_active\(/', $body)) {
        $errors[] = 'run_pass() does not check pass_active() — a re-entrant pass would re-run the whole list.';
    }
    if (!preg_match('/\}\s*finally\s*\{\s*unset\(\s*self::\$passes\[/s', $body)) {
        $errors[] = 'run_pass() does not release the pass lock in a `finally` — a throwing handler would strand it and silence the entity for the rest of the request.';
    }
}

// --- 4. The queue sits ABOVE the lock --------------------------------------
if (!preg_match('/function\s+mark_dirty\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $src, $mm)) {
    $errors[] = 'mark_dirty() not found.';
} else {
    $body = $mm[1];
    if (!preg_match('/pass_active\(/', $body)) {
        $errors[] = 'mark_dirty() does not consult the pass lock — a rule\'s own write would enqueue the entity it is being applied to, which is cascade wearing the queue\'s clothes.';
    }
    $enqueue_at = strpos($body, '$this->dirty[');
    $gate_at    = strpos($body, 'pass_active(');
    if ($enqueue_at !== false && $gate_at !== false && $enqueue_at < $gate_at) {
        $errors[] = 'mark_dirty() enqueues before it checks the pass lock — the gate must come first.';
    }
}

if (!preg_match('/function\s+drain\s*\(\s*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $src, $dm)) {
    $errors[] = 'drain(): void not found.';
} elseif (!preg_match('/\}\s*finally\s*\{\s*\$this->draining\s*=\s*false;/s', $dm[1])) {
    $errors[] = 'drain() does not clear its re-entrancy guard in a `finally` — a throwing pass would leave the queue permanently un-drainable.';
}

// --- 5. apply_to_post has exactly ONE call site, in the dispatcher ----------
// THE invariant of the ticket: if anything else calls it, a rule executes
// outside a pass — no lock, no order.
$php_files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes'));
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $php_files[] = str_replace('\\', '/', $file->getPathname());
    }
}
sort($php_files);

$normalized_root = str_replace('\\', '/', $root) . '/';
$call_sites      = [];
foreach ($php_files as $file) {
    // Calls only: `function apply_to_post(` declarations are not call sites,
    // and comments are already blanked.
    $body = $strip_comments((string) file_get_contents($file));
    foreach (preg_split('/\R/', $body) as $n => $line) {
        if (preg_match('/\bapply_to_post\s*\(/', $line)
            && !preg_match('/function\s+apply_to_post/', $line)) {
            $call_sites[] = str_replace($normalized_root, '', $file) . ':' . ($n + 1);
        }
    }
}

$expected_site_file = 'includes/core/class-term-dispatcher.php';
$foreign = array_values(array_filter(
    $call_sites,
    static fn(string $site): bool => strpos($site, $expected_site_file) !== 0
));

if (empty($call_sites)) {
    $errors[] = 'No apply_to_post() call site found at all — the dispatcher must call the applier seam.';
}
if ($foreign) {
    $errors[] = sprintf(
        'apply_to_post() is called outside TermDispatcher (%s) — every execution must go through a pass (#60).',
        implode(', ', $foreign)
    );
}
if (count($call_sites) > 1 && !$foreign) {
    $errors[] = sprintf(
        'apply_to_post() has %d call sites inside the dispatcher — it must have exactly one choke point (TermDispatcher::apply()).',
        count($call_sites)
    );
}

// --- 6. Handlers register nothing but their descriptor's capture hooks -----
// Every rule type is a pure applier (FW-39 ticket 11 deleted the converted /
// unconverted lists with the last hook-driven type), so the one exception list
// is each descriptor's `capture_hooks()`. A future hook-driven type restores a
// descriptor field and a branch here, not a dispatcher constant.
$descriptors = \BWS\MetaConductor\RuleTypes\Registry::all();

// Rule type => handler file, from the class each descriptor names. Reflection
// only declares the class; nothing is constructed.
$handler_files = [];
$capture       = [];
foreach ($descriptors as $type => $descriptor) {
    $handler_files[$type] = basename((string) (new \ReflectionClass($descriptor->handler_class()))->getFileName());
    if ($descriptor->capture_hooks()) {
        $capture[$type] = $descriptor->capture_hooks();
    }
}

/**
 * Every hook a handler file registers, as `hook@line`.
 *
 * @param string $body Handler source.
 * @return string[]
 */
$registered_hooks = static function (string $body): array {
    $hooks = [];
    foreach (preg_split('/\R/', $body) as $n => $line) {
        if (preg_match('/^\s*\*/', $line) || preg_match('/^\s*\/\//', $line)) {
            continue;
        }
        if (preg_match('/add_(?:action|filter)\(\s*[\'"]([^\'"]+)[\'"]/', $line, $hm)) {
            $hooks[] = $hm[1] . '@' . ($n + 1);
        }
    }

    return $hooks;
};

foreach ($handler_files as $type => $handler_file) {
    $body = $strip_comments((string) file_get_contents($root . '/includes/handlers/' . $handler_file));

    $allowed = $capture[$type] ?? [];
    foreach ($registered_hooks($body) as $hook) {
        $name = substr($hook, 0, strrpos($hook, '@'));
        if (!in_array($name, $allowed, true)) {
            $errors[] = sprintf(
                '%s registers %s, which its descriptor does not list in capture_hooks() — the dispatchers own every trigger, so this rule type would run twice, once out of authored order.',
                $handler_file,
                $hook
            );
        }
    }

    // `$is_updating_post` is the format handler's old name for the same guard.
    if (preg_match('/private\s+(?:bool\s+)?\$(?:processing|is_updating_post)\b/', $body)) {
        $errors[] = sprintf(
            '%s still declares a re-entrancy boolean — the pass lock is the only guard, and a second one silences the author\'s own chain (ADR 0003, rejected option).',
            $handler_file
        );
    }
}

// --- 8. The time-based cron sweep enqueues, and is still registered --------
// The sweep is the one provocation that is neither a hook the dispatcher owns
// nor a user action, and converting it (#61) moved its registration OUT of the
// handler — group 6 above would otherwise have flagged it. Two things can now
// break silently. It can stop being registered at all, which retires the whole
// expiry behaviour with nothing to observe but terms that never go away. Or it
// can quietly go back to removing terms itself, which puts one rule on a
// private execution path outside any pass again — the exact defect the
// conversion removed, and invisible because the end state for that ONE rule is
// the same.
$sweep_hook   = 'bws_taxonomy_manager_cleanup';
$sweep_method = 'cleanup_expired_rules';

if (isset($descriptors['time_based_rules'])) {
    $manager_file = $root . '/includes/class-taxonomy-manager.php';
    if (!is_file($manager_file)) {
        $errors[] = 'includes/class-taxonomy-manager.php missing — cannot verify the time-based sweep is registered.';
    } elseif (!preg_match(
        '/add_action\(\s*[\'"]' . preg_quote($sweep_hook, '/') . '[\'"].*?[\'"]' . preg_quote($sweep_method, '/') . '[\'"]/s',
        $strip_comments((string) file_get_contents($manager_file))
    )) {
        $errors[] = sprintf(
            'The %s sweep is not registered in TaxonomyManager — a converted TimeBasedHandler registers nothing itself, so the daily expiry sweep would run nowhere.',
            $sweep_hook
        );
    }

    $tb_file = $root . '/includes/handlers/class-time-based-handler.php';
    if (!is_file($tb_file)) {
        $errors[] = 'class-time-based-handler.php missing.';
    } elseif (!preg_match(
        '/function\s+' . preg_quote($sweep_method, '/') . '\s*\([^)]*\)\s*\{(.*?)\n    \}/s',
        $strip_comments((string) file_get_contents($tb_file)),
        $swm
    )) {
        $errors[] = sprintf('%s() not found on TimeBasedHandler.', $sweep_method);
    } else {
        $body = $swm[1];
        if (!preg_match('/->mark_dirty\(/', $body)) {
            $errors[] = sprintf('%s() does not mark the posts it selects dirty — the sweep must ENQUEUE, not execute (#61).', $sweep_method);
        }
        if (!preg_match('/->drain\(/', $body)) {
            $errors[] = sprintf(
                '%s() does not drain — a caller that provokes the sweep and reads terms back in the same request would see pre-pass state.',
                $sweep_method
            );
        }
        foreach (['remove_terms_from_post', 'apply_terms_to_post', 'apply_time_based_rule'] as $effect) {
            if (preg_match('/\b' . $effect . '\s*\(/', $body)) {
                $errors[] = sprintf(
                    '%s() calls %s() — the sweep selects posts; what happens to them is the pass\'s job, or one rule runs outside any pass and out of authored order (#61).',
                    $sweep_method,
                    $effect
                );
            }
        }
    }
}

// --- 6b. An allow-listed hook must actually be a CAPTURE -------------------
// The allow-list names hooks, not behaviour. A handler that put its apply back
// on a hook it is already allowed to register would pass group 6 unnoticed —
// the registration is legal, the callback is not. A capture may READ and
// RECORD; the moment it writes, that rule type is executing outside a pass and
// out of authored order again, for exactly the entry point the allow-list
// exists to permit.
$write_primitives = [
    'apply_to_post',
    'apply_terms_to_post',
    'remove_terms_from_post',
    'wp_set_object_terms',
    'wp_remove_object_terms',
    'set_acf_taxonomy_value',
];

/**
 * One method body out of a handler source, or null.
 *
 * @param string $body Handler source (comments already blanked).
 * @param string $name Method name.
 * @return string|null
 */
$method_body = static function (string $body, string $name): ?string {
    $pattern = '/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s';

    return preg_match($pattern, $body, $m) ? $m[1] : null;
};

foreach ($capture as $type => $hooks) {
    $body = $strip_comments((string) file_get_contents($root . '/includes/handlers/' . $handler_files[$type]));

    foreach ($hooks as $hook) {
        // add_action OR add_filter: `related_post_terms`' sever captures are
        // acf/update_value FILTERS (they must return the value they were handed),
        // so an action-only pattern would report every one of them as
        // unregistered and, worse, never reach the write check on its callback.
        $pattern = '/add_(?:action|filter)\(\s*[\'"]' . preg_quote($hook, '/') . '[\'"]\s*,\s*(?:array\(|\[)\s*\$this\s*,\s*[\'"](\w+)[\'"]/';
        if (!preg_match($pattern, $body, $hm)) {
            $errors[] = sprintf(
                '%s is allowed the capture hook %s but does not register it — an allow-list entry for a hook nobody registers hides the fact that the state it captured is no longer captured.',
                $handler_files[$type],
                $hook
            );
            continue;
        }
        $callback = $hm[1];
        $cb_body  = $method_body($body, $callback);
        if ($cb_body === null) {
            $errors[] = sprintf('%s registers %s => %s(), which is not declared in that file.', $handler_files[$type], $hook, $callback);
            continue;
        }
        foreach ($write_primitives as $effect) {
            if (preg_match('/\b' . preg_quote($effect, '/') . '\s*\(/', $cb_body)) {
                $errors[] = sprintf(
                    '%s::%s() is a CAPTURE hook but calls %s() — capture records, it does not apply; a write here executes that rule type outside any pass (#62).',
                    $handler_files[$type],
                    $callback,
                    $effect
                );
            }
        }
    }
}

// --- 9. Each pass runs exactly its own kind, as the registry says ----------
// A kind list could still hold a row of the other kind's type; running it
// would hand a term rule to the format seam or vice versa. Ownership is the
// descriptor's `kind()`, asked through `owns()` — a hand-kept type list on a
// dispatcher is what FW-39 ticket 11 deleted, and one coming back would drift
// from the registry the first time a type is added.
foreach (
    [
        'TermDispatcher'   => $dispatcher_file,
        'FormatDispatcher' => $root . '/includes/core/class-format-dispatcher.php',
    ] as $class => $file
) {
    if (!is_file($file)) {
        continue; // reported by group 12 for the format file
    }
    $dsrc  = $strip_comments((string) file_get_contents($file));
    $owns  = $method_body($dsrc, 'owns');
    $pass  = $method_body($dsrc, $class === 'TermDispatcher' ? 'run_pass' : 'compute');
    if ($owns === null || !preg_match('/Registry::get\(.*->kind\(\)\s*===\s*self::KIND/s', $owns)) {
        $errors[] = "$class::owns() does not ask the registry for the type's kind — which types a pass runs must be derived from the descriptors (FW-39).";
    }
    if ($pass === null || !preg_match('/self::owns\(/', $pass)) {
        $errors[] = "$class's pass does not filter its rows through self::owns() — a row of the other kind's type would reach this kind's applier seam.";
    }
    if (preg_match('/const\s+(?:CONVERTED_TYPES|UNCONVERTED_TYPES|CAPTURE_HOOKS|CAPTURE_QUEUE_TYPES)\b/', $dsrc, $lm)) {
        $errors[] = "$class declares a hand-kept type list ($lm[0]) — capture and ownership facts live on the descriptors (FW-39 ticket 11).";
    }
}

// --- 10. The declared fan-out ----------------------------------------------
// A cross-entity rule must DECLARE the entities its effect reaches and let the
// dispatcher enqueue them, so each gets its own full ordered pass. Writing them
// from inside the applier instead reconciles the far entity by ONE rule, out of
// authored order, and the rest of the list then runs against it from whatever
// hooks fire — that is #35, and it looks exactly like working code because the
// far entity does end up with the terms.
$base_file = $root . '/includes/handlers/class-unified-handler-base.php';
if (!is_file($base_file)) {
    $errors[] = 'includes/handlers/class-unified-handler-base.php missing — cannot verify the fan-out seam.';
} else {
    $base_src = $strip_comments((string) file_get_contents($base_file));
    if (!preg_match('/public\s+function\s+fan_out\s*\(\s*int\s+\$\w+\s*,\s*array\s+\$\w+\s*\)\s*:\s*array\s*\{/', $base_src)) {
        $errors[] = 'fan_out(int $post_id, array $rule): array is not declared on UnifiedHandlerBase — every handler must answer the question, so the dispatcher can ask it unconditionally.';
    }
}

if (!preg_match('/function\s+run_pass\s*\(\s*int\s+\$\w+\s*\)\s*:\s*int\s*\{(.*?)\n    \}/s', $src, $fm)) {
    // Already reported by group 3.
} elseif (!preg_match('/fan_out\(/', $fm[1])) {
    $errors[] = 'run_pass() never collects the declared fan-out — a cross-entity rule would reach the far entity itself or not at all (#62).';
}

if (!preg_match('/function\s+enqueue_fan_out\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $src, $em)) {
    $errors[] = 'enqueue_fan_out(): void not found — the fan-out must land in the queue through one named site.';
} else {
    $body = $em[1];
    if (!preg_match('/->fan_out\(/', $body)) {
        $errors[] = 'enqueue_fan_out() does not ask the handler for its fan-out.';
    }
    if (!preg_match('/mark_dirty\(/', $body)) {
        $errors[] = 'enqueue_fan_out() does not mark the declared entities dirty — the fan-out must ENQUEUE.';
    }
    foreach (['run_pass', 'drain', 'apply'] as $executor) {
        if (preg_match('/\b' . $executor . '\s*\(/', $body)) {
            $errors[] = sprintf(
                'enqueue_fan_out() calls %s() — the fan-out names entities; running them inline nests a pass inside a pass and puts the far entity back outside the queue.',
                $executor
            );
        }
    }
}

// A handler's fan_out() may SELECT; it must not write. The write is what the
// declaration exists to avoid.
foreach ($handler_files as $type => $handler_file) {
    $body = $strip_comments((string) file_get_contents($root . '/includes/handlers/' . $handler_file));
    $fan  = $method_body($body, 'fan_out');
    if ($fan === null) {
        continue; // inherits the empty default — nothing to check
    }
    foreach ($write_primitives as $effect) {
        if (preg_match('/\b' . preg_quote($effect, '/') . '\s*\(/', $fan)) {
            $errors[] = sprintf(
                '%s::fan_out() calls %s() — a fan-out DECLARES entities for their own passes; writing them there is the push model #62 removed.',
                $handler_files[$type],
                $effect
            );
        }
    }
}

// --- 11. The capture queue reaches the dirty queue --------------------------
// A capture names an entity that NOTHING else points at — that is the whole
// reason it had to be captured rather than derived. If the dispatcher stops
// asking for it, or a handler stops handing it over, the sever is recorded and
// then silently never applied: the dependent keeps terms whose source is gone,
// and every other path still works, so nothing looks broken.
$base_file = $root . '/includes/handlers/class-unified-handler-base.php';
if (is_file($base_file)) {
    $base_src = $strip_comments((string) file_get_contents($base_file));
    if (!preg_match('/public\s+function\s+drain_captures\s*\(\s*\)\s*:\s*array\s*\{/', $base_src)) {
        $errors[] = 'drain_captures(): array is not declared on UnifiedHandlerBase — the dispatcher asks it of every handler whose descriptor drains captures (#63).';
    }
}

if (!preg_match('/function\s+enqueue_captures\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $src, $cm2)) {
    $errors[] = 'enqueue_captures(): void not found on the dispatcher — captured entities must land in the queue through one named site (#63).';
} else {
    $body = $cm2[1];
    if (!preg_match('/->drain_captures\(/', $body)) {
        $errors[] = 'enqueue_captures() does not ask handlers for their captured entities.';
    }
    if (!preg_match('/->drains_captures\(\)/', $body)) {
        $errors[] = 'enqueue_captures() does not consult the descriptor\'s drains_captures() — which handlers hand captured entities over is a descriptor fact (FW-39).';
    }
    if (!preg_match('/mark_dirty\(/', $body)) {
        $errors[] = 'enqueue_captures() does not mark the captured entities dirty — a capture must ENQUEUE, exactly like a fan-out.';
    }
    foreach (['run_pass', 'apply'] as $executor) {
        if (preg_match('/\b' . $executor . '\s*\(/', $body)) {
            $errors[] = sprintf(
                'enqueue_captures() calls %s() — it names entities; running them inline puts the captured entity back outside the queue and outside authored order.',
                $executor
            );
        }
    }
}

if (!preg_match('/function\s+drain\s*\(\s*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $src, $dm2)) {
    $errors[] = 'drain(): void not found — cannot verify the capture queue is drained.';
} elseif (!preg_match('/enqueue_captures\(/', $dm2[1])) {
    $errors[] = 'drain() never calls enqueue_captures() — captured severs would be recorded and never applied (#63).';
}

// `drains_captures()` and a `drain_captures()` override must agree, both ways.
// A flag without the override inherits the base's empty default: the sever is
// recorded and then never acted on, while every other path keeps working. An
// override without the flag is never asked — the same dead end from the other
// side. Propagation has neither on purpose: its capture is consumed by its own
// applier, which the queue was going to run anyway.
foreach ($descriptors as $type => $descriptor) {
    $overrides = $method_body(
        $strip_comments((string) file_get_contents($root . '/includes/handlers/' . $handler_files[$type])),
        'drain_captures'
    ) !== null;

    if ($descriptor->drains_captures() && !isset($capture[$type])) {
        $errors[] = sprintf(
            "%s drains captures but lists no capture_hooks() — a queueing capture with nothing capturing into it.",
            $type
        );
    }
    if ($descriptor->drains_captures() && !$overrides) {
        $errors[] = sprintf(
            '%s drains captures but %s declares no drain_captures() — it would inherit the empty default, so every entity its captures name is recorded and then silently never passed over (#63).',
            $type,
            $handler_files[$type]
        );
    }
    if (!$descriptor->drains_captures() && $overrides) {
        $errors[] = sprintf(
            '%s declares drain_captures() but its descriptor does not drain captures — the dispatcher never asks, so what it hands over is never passed over (#63).',
            $handler_files[$type]
        );
    }
}

// And drain_captures() must not write, on ANY handler that has one: it runs
// before any pass, so a write there is a rule executing with no lock held and
// no order around it.
foreach ($handler_files as $type => $handler_file) {
    $drainer = $method_body(
        $strip_comments((string) file_get_contents($root . '/includes/handlers/' . $handler_file)),
        'drain_captures'
    );
    if ($drainer === null) {
        continue; // absence is checked above, for the types it matters on
    }
    foreach ($write_primitives as $effect) {
        if (preg_match('/\b' . preg_quote($effect, '/') . '\s*\(/', $drainer)) {
            $errors[] = sprintf(
                '%s::drain_captures() calls %s() — it hands over entity IDs; the pass writes them (#63).',
                $handler_files[$type],
                $effect
            );
        }
    }
}

// --- 12. The FORMAT dispatcher and the derived cross-kind order (#64) ------
// The order terms → title/slug is DERIVED, not authored (ADR 0003 decision 2):
// `title_slug` reads terms and writes none. Until #64 that order rested on two
// accidents — a priority-99 registration and a handler constructed last — and
// BOTH would have inverted silently once the term pass moved onto a late drain.
// A title computed from the previous save's terms is still a plausible title,
// so there is nothing to observe. What replaces them is a sequence of two calls
// inside one drain step, which is exactly the kind of thing a refactor reorders
// without noticing. Hence every link in it is pinned here.
$format_file = $root . '/includes/core/class-format-dispatcher.php';
if (!is_file($format_file)) {
    $errors[] = 'includes/core/class-format-dispatcher.php missing — the format kind has no dispatcher, so its rules run nowhere (#64).';
} else {
    $fsrc = $strip_comments((string) file_get_contents($format_file));

    // 12a. It owns the FORMAT kind, and only that.
    if (!preg_match('/const\s+KIND\s*=\s*OptionRuleStorage::KIND_FORMAT\s*;/', $fsrc)) {
        $errors[] = 'FormatDispatcher::KIND is not OptionRuleStorage::KIND_FORMAT — the pass lock and the rule read would both address the wrong kind.';
    }

    // 12b. It owns NO triggers and NO drain. Two dispatchers with two queues
    // would have to be kept in step for the derived order to mean anything;
    // one queue is what makes the order a property of the code rather than of
    // two hook priorities agreeing.
    foreach (['set_object_terms', 'deleted_term_relationships', 'save_post', 'acf/save_post', 'shutdown', 'wp_after_insert_post'] as $owned) {
        if (preg_match('/add_(?:action|filter)\(\s*[\'"]' . preg_quote($owned, '/') . '[\'"]/', $fsrc)) {
            $errors[] = sprintf(
                "FormatDispatcher registers '%s' — TermDispatcher owns the trigger union and the drain for BOTH kinds, and a second queue would order the two passes by hook priority again (#64).",
                $owned
            );
        }
    }

    // 12c. The redirect fix survived the move off the handler. It compensates
    // for a write that now always happens post-write, so losing it means the
    // author's "View Post" link points at the pre-rule slug — a 404 seen once,
    // on the one screen where nobody is looking for a bug.
    if (!preg_match('/add_filter\(\s*[\'"]redirect_post_location[\'"]\s*,\s*\[\s*\$this\s*,\s*[\'"](\w+)[\'"]\s*\]/', $fsrc, $rfm)) {
        $errors[] = 'FormatDispatcher does not register redirect_post_location — the post-save "View Post" link would keep reading the pre-rule slug (#64).';
    } elseif (($rb = $method_body($fsrc, $rfm[1])) === null || strpos($rb, 'clean_post_cache') === false) {
        $errors[] = sprintf('FormatDispatcher::%s() does not flush the post cache — the redirect fix is the flush, not the hook.', $rfm[1]);
    }

    // 12d. The pass: locked on its own kind, gated, released in `finally`.
    if (!preg_match('/function\s+run_pass\s*\(\s*int\s+\$\w+\s*\)\s*:\s*int\s*\{(.*?)\n    \}/s', $fsrc, $frm)) {
        $errors[] = 'FormatDispatcher::run_pass(int $post_id): int not found.';
    } else {
        $body = $frm[1];
        if (!preg_match('/pass_active\(\s*\$\w+\s*,\s*self::KIND\s*\)/', $body)) {
            $errors[] = 'FormatDispatcher::run_pass() does not check the pass lock for its OWN kind — passing self::KIND is what stops a format write re-entering a format pass while leaving the term marks it legitimately raises audible.';
        }
        if (!preg_match('/\$this->pass_enabled\(/', $body)) {
            $errors[] = 'FormatDispatcher::run_pass() does not consult pass_enabled() — the disable gate must sit on the pass, for the same reason the term one does.';
        }
        if (!preg_match('/\}\s*finally\s*\{\s*TermDispatcher::release_pass\(/s', $body)) {
            $errors[] = 'FormatDispatcher::run_pass() does not release the pass lock in a `finally` — a throwing applier would strand it and silence the entity for the rest of the request.';
        }
    }
    if (!preg_match('/function\s+pass_enabled\s*\(\s*int\s+\$\w+\s*\)\s*:\s*bool\s*\{(.*?)\n    \}/s', $fsrc, $fpm)) {
        $errors[] = 'FormatDispatcher::pass_enabled(int $post_id): bool not found — the format pass needs its own single decision site for "turn this off".';
    } else {
        foreach (
            [
                'WP_IMPORTING'                       => 'the import stand-down',
                'meta_conductor_acf_reapply_enabled' => 'the established off switch (the seeder rides on it)',
                'meta_conductor_format_pass_enabled' => 'the format-specific override',
            ] as $needle => $what
        ) {
            if (strpos($fpm[1], $needle) === false) {
                $errors[] = sprintf('FormatDispatcher::pass_enabled() does not consult %s — %s is missing.', $needle, $what);
            }
        }
    }

    // 12e. The dispatcher performs the write, and suppresses the revision it
    // would otherwise create. The revision is OUR write, not the author's, and
    // a history full of them is how a rule stops being invisible in the wrong
    // way.
    $write_body = $method_body($fsrc, 'write');
    if ($write_body === null) {
        $errors[] = 'FormatDispatcher::write() not found — the appliers return data, so exactly one place must persist it (#64).';
    } else {
        if (!preg_match('/wp_update_post\(/', $write_body)) {
            $errors[] = 'FormatDispatcher::write() does not call wp_update_post() — the pass would compute a title and never store it.';
        }
        // The ADD, specifically: `remove_filter` on the same name lives two
        // lines below, so a plain substring search still matches a write whose
        // suppression has been deleted.
        // `wp_revisions_to_keep`, not `wp_save_post_revision_post_has_changed`:
        // WP asks the latter only once a revision exists, so a post with none
        // still got one per write (FW-16 04).
        if (!preg_match('/add_filter\(\s*[\'"]wp_revisions_to_keep[\'"]/', $write_body)) {
            $errors[] = 'FormatDispatcher::write() does not suppress the revision its update creates — every save would leave an extra revision whose only difference is a rule\'s own output.';
        }
        if (!preg_match('/remove_filter\(\s*[\'"]wp_revisions_to_keep[\'"]/', $write_body)) {
            $errors[] = 'FormatDispatcher::write() suppresses the revision and never lifts the suppression — every later save in the request would silently stop creating revisions.';
        }
    }
}

// --- 12h. apply_to_data has exactly ONE call site, in the format dispatcher -
// The format kind's half of group 5, and the same invariant: anything else
// calling the applier executes a format rule with no lock held and no order
// around it. It also has a failure mode group 5's does not — an applier called
// outside a pass writes nothing, so the rule simply has no effect, which reads
// as "the rule is not configured" rather than as a bug.
$base_file = $root . '/includes/handlers/class-unified-handler-base.php';
if (is_file($base_file)) {
    $base_src = $strip_comments((string) file_get_contents($base_file));
    if (!preg_match('/public\s+function\s+apply_to_data\s*\(\s*array\s+\$\w+\s*,\s*int\s+\$\w+\s*,\s*array\s+\$\w+\s*\)\s*:\s*\?array\s*\{/', $base_src)) {
        $errors[] = 'apply_to_data(array $data, int $post_id, array $rule): ?array is not declared on UnifiedHandlerBase — the format seam must be answerable by every handler, and its NULLABLE return is what distinguishes "not my rule" from "already in the target state" (#64).';
    }
}

$data_sites = [];
foreach ($php_files as $file) {
    $body = $strip_comments((string) file_get_contents($file));
    foreach (preg_split('/\R/', $body) as $n => $line) {
        if (preg_match('/\bapply_to_data\s*\(/', $line)
            && !preg_match('/function\s+apply_to_data/', $line)) {
            $data_sites[] = str_replace($normalized_root, '', $file) . ':' . ($n + 1);
        }
    }
}
$expected_data_file = 'includes/core/class-format-dispatcher.php';
$foreign_data = array_values(array_filter(
    $data_sites,
    static fn(string $site): bool => strpos($site, $expected_data_file) !== 0
));
if (empty($data_sites)) {
    $errors[] = 'No apply_to_data() call site found at all — the format dispatcher must call the applier seam.';
}
if ($foreign_data) {
    $errors[] = sprintf(
        'apply_to_data() is called outside FormatDispatcher (%s) — every format execution must go through a pass (#64).',
        implode(', ', $foreign_data)
    );
}
if (count($data_sites) > 1 && !$foreign_data) {
    $errors[] = sprintf(
        'apply_to_data() has %d call sites inside the format dispatcher — it must have exactly one choke point (FormatDispatcher::apply()).',
        count($data_sites)
    );
}

// A format applier RETURNS data; it must not write the row itself. That is what
// keeps one save to one row update however many rules matched, and what lets
// the deferred pre-write phase reuse the seam on data that has no row yet.
foreach ($kind_types('format_rules') as $type) {
    $applier = $method_body($strip_comments((string) file_get_contents($root . '/includes/handlers/' . $handler_files[$type])), 'apply_to_data');
    if ($applier === null) {
        $errors[] = sprintf(
            '%s is a format-kind handler but declares no apply_to_data() — it would inherit the base null, so every rule of its type reports "not mine" and silently never runs.',
            $handler_files[$type]
        );
        continue;
    }
    // The meta and option writes too, since FW-16 03: the compute step runs the
    // appliers for a preview, so anything an applier writes is written by a
    // preview. Per-rule state belongs in commit_data(), which only write() calls.
    foreach (['wp_update_post', 'wp_insert_post', 'update_post_meta', 'update_option'] as $effect) {
        if (preg_match('/\b' . preg_quote($effect, '/') . '\s*\(/', $applier)) {
            $errors[] = sprintf(
                '%s::apply_to_data() calls %s() — a format applier returns data; the dispatcher writes the row and commit_data() the per-rule state (#64, FW-16 03).',
                $handler_files[$type],
                $effect
            );
        }
    }
}

// --- 12j. The pass is compute, then write (FW-16 03) ------------------------
// Compute is the format preview's entry point, so it must be the ONLY caller of
// apply() — a second one would let a preview and a pass disagree — and it must
// write nothing: no row, no meta, no option, and no pass lock, since a preview
// is not a pass. write() is where the per-rule state lands, via commit_data().
if (isset($fsrc)) {
    $compute = null;
    if (!preg_match('/public\s+function\s+compute\s*\(\s*int\s+\$\w+\s*\)\s*:\s*\?array\s*\{(.*?)\n    \}/s', $fsrc, $cm)) {
        $errors[] = 'FormatDispatcher::compute(int $post_id): ?array not found — the format preview needs the pass without its write (FW-16 03).';
    } else {
        $compute = $cm[1];
        if (substr_count($fsrc, 'self::apply(') !== 1 || strpos($compute, 'self::apply(') === false) {
            $errors[] = 'FormatDispatcher::apply() must be called exactly once, from compute() — a preview and a pass must run the same rules the same way.';
        }
        foreach (['wp_update_post', 'update_post_meta', 'update_option', 'commit_data', '$this->write(', 'take_pass'] as $effect) {
            if (strpos($compute, $effect) !== false) {
                $errors[] = sprintf('FormatDispatcher::compute() reaches %s — compute must write nothing, or a preview writes (FW-16 03).', $effect);
            }
        }
    }
    if (isset($frm[1])) {
        $compute_at = strpos($frm[1], '$this->compute(');
        $write_at   = strpos($frm[1], '$this->write(');
        if ($compute_at === false || $write_at === false || $write_at < $compute_at) {
            $errors[] = 'FormatDispatcher::run_pass() is not compute() then write() — the pass and its preview would diverge (FW-16 03).';
        }
    }
    if (isset($write_body) && strpos($write_body, 'commit_data(') === false) {
        $errors[] = 'FormatDispatcher::write() does not call commit_data() — the idempotency meta would never be written (FW-16 03).';
    }
}

// --- 12i. The drain step runs the term pass, THEN the format pass -----------
// THE derived cross-kind order, as executable code. Both halves matter: the
// format pass must be reached from the drain at all (or format rules only run
// when something else happens to call them), and it must be reached AFTER the
// term pass (or `{term:TAX}` resolves against the previous save's terms, which
// is the defect the ticket exists to remove and which produces a perfectly
// plausible title).
if (!preg_match('/function\s+run_entity\s*\(\s*int\s+\$\w+\s*\)\s*:\s*int\s*\{(.*?)\n    \}/s', $src, $rem)) {
    $errors[] = 'TermDispatcher::run_entity(int $post_id): int not found — the two kinds must be sequenced at ONE named site, not wherever a drain happens to call them (#64).';
} else {
    $body     = $rem[1];
    $term_at  = strpos($body, '$this->run_pass(');
    $fmt_at   = strpos($body, 'format->run_pass(');
    if ($term_at === false) {
        $errors[] = 'run_entity() does not run the TERM pass.';
    }
    if ($fmt_at === false) {
        $errors[] = 'run_entity() does not run the FORMAT pass — format rules would execute only when something else happened to call them (#64).';
    }
    if ($term_at !== false && $fmt_at !== false && $fmt_at < $term_at) {
        $errors[] = 'run_entity() runs the format pass BEFORE the term pass — a {term:TAX} pattern would resolve against the previous save\'s terms, and the resulting title looks entirely plausible (#64).';
    }
}
foreach (['drain', 'drain_post'] as $entry) {
    $pattern = '/function\s+' . $entry . '\s*\([^)]*\)\s*:\s*(?:void|int)\s*\{(.*?)\n    \}/s';
    if (!preg_match($pattern, $src, $dem)) {
        continue; // absence already reported by groups 4/11
    }
    if (strpos($dem[1], 'run_entity(') === false) {
        $errors[] = sprintf(
            '%s() does not go through run_entity() — it would run the term pass alone, so a post drained by that path keeps the previous save\'s title (#64).',
            $entry
        );
    }
}

// --- 13. The existing-posts applier goes through the queue (FW-16 04) -------
// Bulk and save cannot disagree only if bulk IS a save's pass: it names posts
// and asks the drain for them, and never reaches an applier or a write itself.
// A one-time run's override must be cleared in a `finally`, or a throwing batch
// leaves a disabled rule running in every save for the rest of the request.
$applier_file = $root . '/includes/core/class-existing-posts-applier.php';
if (!is_file($applier_file)) {
    $errors[] = 'includes/core/class-existing-posts-applier.php not found — bulk apply has no single path through the queue (FW-16 04).';
} else {
    $asrc  = $strip_comments((string) file_get_contents($applier_file));
    $batch = $method_body($asrc, 'run_batch');
    if ($batch === null) {
        $errors[] = 'ExistingPostsApplier::run_batch() not found (FW-16 04).';
    } else {
        if (strpos($batch, '->drain_post(') === false || strpos($batch, '->drain()') === false) {
            $errors[] = 'run_batch() must pass each post with drain_post() and then drain() once, so fan-out and capture marks settle before the report (FW-16 04).';
        }
        if (!preg_match('/\}\s*finally\s*\{[^}]*clear_included_row\(/s', $batch)) {
            $errors[] = 'run_batch() does not clear the one-time row override in a `finally` — a throwing batch would leave a disabled rule running in every later pass of the request (FW-16 04).';
        }
    }
    // The preview shows what the pass WOULD produce, so it asks the format
    // dispatcher's compute step — never a pass, which writes (FW-16 06).
    $preview = $method_body($asrc, 'preview');
    if ($preview === null) {
        $errors[] = 'ExistingPostsApplier::preview() not found (FW-16 06).';
    } else {
        if (strpos($preview, '->compute(') === false) {
            $errors[] = 'preview() must read the format result through FormatDispatcher::compute() — the one path that shows a pass without writing it (FW-16 06).';
        }
        if (strpos($preview, 'drain') !== false) {
            $errors[] = 'preview() reaches the drain — a drained post is a WRITTEN post; the preview must write nothing (FW-16 06).';
        }
        if (!preg_match('/\}\s*finally\s*\{[^}]*clear_included_row\(/s', $preview)) {
            $errors[] = 'preview() does not clear the one-time row override in a `finally` — a throwing preview would leave a disabled rule running in every later pass of the request (FW-16 06).';
        }
    }
    foreach (array_merge(['::apply(', 'apply_to_data', 'run_pass(', 'wp_update_post', 'update_post_meta'], $write_primitives) as $effect) {
        if (strpos($asrc, $effect) !== false) {
            $errors[] = sprintf('The existing-posts applier reaches %s — it may only name posts to the queue; the pass does the work, or bulk and save can diverge (FW-16 04).', rtrim($effect, '('));
        }
    }
}

// --- 14. Each dispatcher's apply() has ONE caller: its own pass (FW-16 07) --
// Group 5 pins the applier seam to apply(); this pins apply() to the pass. The
// retired per-handler bulk path called TermDispatcher::apply() one rule at a
// time, outside any ordered pass — a bulk run that could disagree with a save.
// The format side's pass path is compute() (group 12j), which run_pass() and
// the applier's preview both go through.
$foreign_apply = [];
foreach ($php_files as $file) {
    $rel = str_replace($normalized_root, '', $file);
    foreach (preg_split('/\R/', $strip_comments((string) file_get_contents($file))) as $n => $line) {
        if (preg_match('/\b(?:Term|Format)Dispatcher::apply\s*\(/', $line)) {
            $foreign_apply[] = $rel . ':' . ($n + 1);
        }
    }
}
if ($foreign_apply) {
    $errors[] = sprintf(
        'A dispatcher apply() is called from outside its own pass (%s) — bulk goes through the existing-posts applier and the drain (FW-16 07).',
        implode(', ', $foreign_apply)
    );
}
$term_pass = $method_body($src, 'run_pass');
if (preg_match_all('/\b(?:self|static)::apply\s*\(/', $src) !== 1 || $term_pass === null || !preg_match('/\bself::apply\s*\(/', $term_pass)) {
    $errors[] = 'TermDispatcher::apply() must be called exactly once, from run_pass() — anything else executes a rule outside the ordered pass (FW-16 07).';
}

// --- 15. A stored row with no descriptor is skipped AND logged (ticket 10) --
// Both loops must ask Registry::known() — the skip-and-log — before they look a
// handler up. A missing handler alone also skips the row, but silently.
foreach (['TermDispatcher::run_pass' => $term_pass, 'FormatDispatcher::compute' => isset($fsrc) ? $method_body($fsrc, 'compute') : null] as $where => $body) {
    $known_at   = $body === null ? false : strpos($body, 'Registry::known(');
    $handler_at = $body === null ? false : strpos($body, '$this->handlers[');
    if ($known_at === false || $handler_at === false || $known_at > $handler_at) {
        $errors[] = "$where() must skip a row via Registry::known() before its handler lookup — an unknown stored type would otherwise be skipped without a log line (ticket 10).";
    }
}

if ($errors) {
    fwrite(STDERR, "DISPATCHER FAIL — term dispatcher invariants broken (#60):\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}

printf(
    "DISPATCHER OK — trigger union mark-only, drain after AcfWriteQueue, pass lock (entity, kind), single apply_to_post + apply_to_data call sites, fan-out + capture queue declared+enqueued, format pass driven from the shared drain AFTER the term pass; %d term / %d format type(s), %d capture type(s) (#60, #62, #63, #64).\n",
    count($kind_types('term_rules')),
    count($kind_types('format_rules')),
    count($capture)
);
exit(0);
