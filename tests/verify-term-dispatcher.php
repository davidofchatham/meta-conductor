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
 *   6. CONVERTED handlers register NOTHING outside the capture-hook allow-list,
 *      and carry no re-entrancy boolean. A hook on that allow-list must be a
 *      CAPTURE — its callback may record and must not apply.
 *   7. UNCONVERTED handlers are enumerated, and the enumeration matches which
 *      handlers still register hooks — so each conversion ticket shrinks a list
 *      that is visible in its diff, and cannot shrink it without doing the work.
 *   8. The time-based cron sweep is still registered — outside the converted
 *      handler, which registers nothing — and ENQUEUES rather than removing
 *      terms itself: a provocation names entities, a pass decides their fate.
 *   9. CONVERTED and UNCONVERTED partition the storage layer's rule types: no
 *      type is in both (double-apply) and none is in neither (silently retired).
 *  10. Cross-entity rules DECLARE their reach and the pass enqueues it: the
 *      `fan_out()` seam exists on the base, the pass marks what it returns
 *      dirty, and a converted handler that overrides it writes only the post it
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
 *
 * Run:  php tests/verify-term-dispatcher.php
 *
 * @package Meta_Conductor
 */

$root   = dirname(__DIR__);
$errors = [];

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

/**
 * Pull a `private const NAME = [ 'a', 'b' ];` string list out of a source file.
 *
 * @param string $src  Source to read.
 * @param string $name Constant name.
 * @return string[]|null Values, or null when the constant is absent.
 */
$const_list = static function (string $src, string $name): ?array {
    if (!preg_match('/const\s+' . preg_quote($name, '/') . '\s*=\s*\[(.*?)\];/s', $src, $m)) {
        return null;
    }
    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $m[1], $vals);

    return $vals[1];
};

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
            'meta_conductor_acf_reapply_enabled'  => "the established off switch (the seeder and conversion tool use it, and the pass now runs after the seeder restores its rules)",
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

// --- 6/7. Converted vs unconverted handlers --------------------------------
$converted   = $const_list($src, 'CONVERTED_TYPES');
$unconverted = $const_list($src, 'UNCONVERTED_TYPES');
$capture     = null;
if (preg_match('/const\s+CAPTURE_HOOKS\s*=\s*(\[.*?\]\s*;)/s', $src, $cm)) {
    // Parse the MAP, not every quoted string inside it. A flat value list makes
    // the `$capture[$rule_type]` lookup below miss for every type, so the
    // allow-list silently reads as empty and a legitimate capture hook is
    // rejected — which is exactly what happened the moment CAPTURE_HOOKS
    // stopped being `[]` (#62).
    $capture = [];
    if (preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*\[([^\]]*)\]/', $cm[1], $entries, PREG_SET_ORDER)) {
        foreach ($entries as $entry) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $entry[2], $hooks);
            $capture[$entry[1]] = $hooks[1];
        }
    }
}

if ($converted === null) {
    $errors[] = 'CONVERTED_TYPES constant not found — what a pass runs must be enumerated.';
}
if ($unconverted === null) {
    $errors[] = 'UNCONVERTED_TYPES constant not found — the handlers still owning hooks must be named, so each conversion shrinks a list visible in the diff.';
}
if ($capture === null) {
    $errors[] = 'CAPTURE_HOOKS constant not found — the capture-hook allow-list must be enumerated even while it is empty.';
}

// Rule type => handler file. The map is the harness's own, deliberately: it is
// what lets the check read a handler's registrations without booting WordPress.
$handler_files = [
    'hierarchical_rules'                   => 'class-hierarchical-handler.php',
    'hierarchical_level_restriction_rules' => 'class-hierarchical-level-restriction-handler.php',
    'propagation_rules'                    => 'class-propagation-handler.php',
    'related_rules'                        => 'class-related-handler.php',
    'related_post_terms_rules'             => 'class-related-post-terms-handler.php',
    'time_based_rules'                     => 'class-time-based-handler.php',
    'title_slug_rules'                     => 'class-title-slug-handler.php',
];

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

foreach (($converted ?? []) as $type) {
    if (!isset($handler_files[$type])) {
        $errors[] = sprintf("CONVERTED_TYPES names '%s', which maps to no handler file this harness knows.", $type);
        continue;
    }
    $file = $root . '/includes/handlers/' . $handler_files[$type];
    if (!is_file($file)) {
        $errors[] = sprintf('Handler file for %s missing (%s).', $type, $handler_files[$type]);
        continue;
    }
    $body = $strip_comments((string) file_get_contents($file));

    $allowed = $capture[$type] ?? [];
    foreach ($registered_hooks($body) as $hook) {
        $name = substr($hook, 0, strrpos($hook, '@'));
        if (!in_array($name, is_array($allowed) ? $allowed : [], true)) {
            $errors[] = sprintf(
                '%s is CONVERTED but still registers %s — the dispatcher owns the trigger union, so this rule type would run twice, once out of authored order.',
                $handler_files[$type],
                $hook
            );
        }
    }

    if (preg_match('/private\s+(?:bool\s+)?\$processing\b/', $body)) {
        $errors[] = sprintf(
            '%s still declares $processing — a converted handler must carry no re-entrancy boolean; the pass lock is the only guard, and a second one silences the author\'s own chain (ADR 0003, rejected option).',
            $handler_files[$type]
        );
    }
}

foreach (($unconverted ?? []) as $type) {
    if (!isset($handler_files[$type])) {
        $errors[] = sprintf("UNCONVERTED_TYPES names '%s', which maps to no handler file this harness knows.", $type);
        continue;
    }
    $file = $root . '/includes/handlers/' . $handler_files[$type];
    if (!is_file($file)) {
        $errors[] = sprintf('Handler file for %s missing (%s).', $type, $handler_files[$type]);
        continue;
    }
    if (empty($registered_hooks($strip_comments((string) file_get_contents($file)))))  {
        $errors[] = sprintf(
            '%s registers no hooks but is still listed UNCONVERTED — if it has been converted, move it to CONVERTED_TYPES so the pass actually runs it; otherwise its rules now run nowhere.',
            $handler_files[$type]
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

if (in_array('time_based_rules', $converted ?? [], true)) {
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

// --- 9. The two lists partition the term-kind rule types -------------------
// A type in both would double-apply; a type in neither would be silently
// retired, which is the failure mode that looks most like nothing happening.
$storage_file = $root . '/includes/storage/class-option-rule-storage.php';
if (!is_file($storage_file)) {
    $errors[] = 'includes/storage/class-option-rule-storage.php missing.';
} elseif ($converted !== null && $unconverted !== null) {
    $ssrc = $strip_comments((string) file_get_contents($storage_file));
    $term_types = [];
    if (preg_match('/self::KIND_TERM\s*=>\s*\[(.*?)\]/s', $ssrc, $tm)) {
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $tm[1], $tvals);
        $term_types = $tvals[1];
    }
    if (empty($term_types)) {
        $errors[] = 'Could not read KIND_TYPES[KIND_TERM] out of OptionRuleStorage — cannot verify the dispatcher covers every term rule type.';
    } else {
        $both = array_intersect($converted, $unconverted);
        if ($both) {
            $errors[] = sprintf(
                'Rule types in BOTH CONVERTED_TYPES and UNCONVERTED_TYPES (%s) — they would run twice, once from a hook and once from the pass.',
                implode(', ', $both)
            );
        }
        $covered = array_merge($converted, $unconverted);
        $missing = array_values(array_diff($term_types, $covered));
        if ($missing) {
            $errors[] = sprintf(
                'Term rule types in neither list (%s) — a type the dispatcher does not run and that owns no hooks is silently retired.',
                implode(', ', $missing)
            );
        }
        $stray = array_values(array_diff($covered, $term_types));
        if ($stray) {
            $errors[] = sprintf(
                'Types listed on the term dispatcher that are not in KIND_TERM (%s) — a format-kind rule belongs to the format dispatcher (#64).',
                implode(', ', $stray)
            );
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

foreach (($capture ?? []) as $type => $hooks) {
    if (!isset($handler_files[$type])) {
        $errors[] = sprintf("CAPTURE_HOOKS names '%s', which maps to no handler file this harness knows.", $type);
        continue;
    }
    if (!in_array($type, $converted ?? [], true)) {
        $errors[] = sprintf(
            "CAPTURE_HOOKS names '%s', which is not CONVERTED — an unconverted handler owns all its hooks, so an allow-list entry for it means nothing.",
            $type
        );
        continue;
    }
    $file = $root . '/includes/handlers/' . $handler_files[$type];
    if (!is_file($file)) {
        continue; // already reported by group 6
    }
    $body = $strip_comments((string) file_get_contents($file));

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

// A converted handler's fan_out() may SELECT; it must not write. The write is
// what the declaration exists to avoid.
foreach (($converted ?? []) as $type) {
    if (!isset($handler_files[$type])) {
        continue; // already reported by group 6
    }
    $file = $root . '/includes/handlers/' . $handler_files[$type];
    if (!is_file($file)) {
        continue;
    }
    $body = $strip_comments((string) file_get_contents($file));
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
        $errors[] = 'drain_captures(): array is not declared on UnifiedHandlerBase — the dispatcher asks every converted handler for it unconditionally (#63).';
    }
}

if (!preg_match('/function\s+enqueue_captures\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $src, $cm2)) {
    $errors[] = 'enqueue_captures(): void not found on the dispatcher — captured entities must land in the queue through one named site (#63).';
} else {
    $body = $cm2[1];
    if (!preg_match('/->drain_captures\(/', $body)) {
        $errors[] = 'enqueue_captures() does not ask handlers for their captured entities.';
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

// A type on CAPTURE_QUEUE_TYPES must actually hand its entities over. Without
// the override it inherits the base's empty default, which is not an error
// anywhere else — propagation's capture is consumed by its own applier, so it
// legitimately has none — and is a silent dead end here: the sever is recorded
// and then never acted on, while every other path keeps working.
$capture_queue = $const_list($src, 'CAPTURE_QUEUE_TYPES');
if ($capture_queue === null) {
    $errors[] = 'CAPTURE_QUEUE_TYPES constant not found — which captures name entities the queue must be TOLD about has to be enumerated, not inferred (#63).';
}
foreach (($capture_queue ?? []) as $type) {
    if (!isset($capture[$type])) {
        $errors[] = sprintf(
            "CAPTURE_QUEUE_TYPES names '%s', which owns no capture hooks — a queueing capture with nothing capturing into it.",
            $type
        );
        continue;
    }
    if (!isset($handler_files[$type])) {
        continue; // already reported above
    }
    $file = $root . '/includes/handlers/' . $handler_files[$type];
    if (!is_file($file)) {
        continue;
    }
    if ($method_body($strip_comments((string) file_get_contents($file)), 'drain_captures') === null) {
        $errors[] = sprintf(
            '%s is on CAPTURE_QUEUE_TYPES but declares no drain_captures() — it would inherit the empty default, so every entity its captures name is recorded and then silently never passed over (#63).',
            $handler_files[$type]
        );
    }
}

// And drain_captures() must not write, on ANY capture handler that has one: it
// runs before any pass, so a write there is a rule executing with no lock held
// and no order around it.
foreach (($capture ?? []) as $type => $hooks) {
    if (!isset($handler_files[$type])) {
        continue; // already reported above
    }
    $file = $root . '/includes/handlers/' . $handler_files[$type];
    if (!is_file($file)) {
        continue;
    }
    $body    = $strip_comments((string) file_get_contents($file));
    $drainer = $method_body($body, 'drain_captures');
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
$fmt_converted = null;
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
                'meta_conductor_acf_reapply_enabled' => 'the established off switch (the seeder and conversion tool ride on it)',
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
        if (!preg_match('/add_filter\(\s*[\'"]wp_save_post_revision_post_has_changed[\'"]/', $write_body)) {
            $errors[] = 'FormatDispatcher::write() does not suppress the revision its update creates — every save would leave an extra revision whose only difference is a rule\'s own output.';
        }
        if (!preg_match('/remove_filter\(\s*[\'"]wp_save_post_revision_post_has_changed[\'"]/', $write_body)) {
            $errors[] = 'FormatDispatcher::write() suppresses the revision and never lifts the suppression — every later save in the request would silently stop creating revisions.';
        }
    }

    // 12f. Converted/unconverted partition the FORMAT kind, exactly as groups
    // 6/7/9 do for the term kind.
    $fmt_converted   = $const_list($fsrc, 'CONVERTED_TYPES');
    $fmt_unconverted = $const_list($fsrc, 'UNCONVERTED_TYPES');
    if ($fmt_converted === null || $fmt_unconverted === null) {
        $errors[] = 'FormatDispatcher is missing CONVERTED_TYPES / UNCONVERTED_TYPES — what a format pass runs, and what still owns hooks, must be enumerated.';
    } elseif (is_file($storage_file)) {
        $ssrc2 = $strip_comments((string) file_get_contents($storage_file));
        $format_types = [];
        if (preg_match('/self::KIND_FORMAT\s*=>\s*\[(.*?)\]/s', $ssrc2, $fm2)) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $fm2[1], $fvals);
            $format_types = $fvals[1];
        }
        if (empty($format_types)) {
            $errors[] = 'Could not read KIND_TYPES[KIND_FORMAT] out of OptionRuleStorage — cannot verify the format dispatcher covers every format rule type.';
        } else {
            $fboth = array_intersect($fmt_converted, $fmt_unconverted);
            if ($fboth) {
                $errors[] = sprintf('Format rule types in BOTH lists (%s) — they would run twice.', implode(', ', $fboth));
            }
            $fcovered = array_merge($fmt_converted, $fmt_unconverted);
            $fmissing = array_values(array_diff($format_types, $fcovered));
            if ($fmissing) {
                $errors[] = sprintf(
                    'Format rule types in neither list (%s) — a type no dispatcher runs and that owns no hooks is silently retired.',
                    implode(', ', $fmissing)
                );
            }
            $fstray = array_values(array_diff($fcovered, $format_types));
            if ($fstray) {
                $errors[] = sprintf(
                    'Types listed on the format dispatcher that are not in KIND_FORMAT (%s) — a term-kind rule belongs to the term dispatcher.',
                    implode(', ', $fstray)
                );
            }
        }
    }

    // 12g. A converted FORMAT handler registers nothing and carries no
    // re-entrancy boolean — the same bright line group 6 holds for the term
    // kind, and the one that kills the priority-99 registration for good.
    $fmt_capture = [];
    if (preg_match('/const\s+CAPTURE_HOOKS\s*=\s*(\[.*?\]\s*;)/s', $fsrc, $fcm)) {
        if (preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*\[([^\]]*)\]/', $fcm[1], $fentries, PREG_SET_ORDER)) {
            foreach ($fentries as $entry) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $entry[2], $fhooks);
                $fmt_capture[$entry[1]] = $fhooks[1];
            }
        }
    } else {
        $errors[] = 'FormatDispatcher::CAPTURE_HOOKS not found — the allow-list must be declared even while it is empty, or "a converted handler registers nothing" has an implicit exception list.';
    }

    foreach (($fmt_converted ?? []) as $type) {
        if (!isset($handler_files[$type])) {
            $errors[] = sprintf("FormatDispatcher::CONVERTED_TYPES names '%s', which maps to no handler file this harness knows.", $type);
            continue;
        }
        $file = $root . '/includes/handlers/' . $handler_files[$type];
        if (!is_file($file)) {
            $errors[] = sprintf('Handler file for %s missing (%s).', $type, $handler_files[$type]);
            continue;
        }
        $body    = $strip_comments((string) file_get_contents($file));
        $allowed = $fmt_capture[$type] ?? [];
        foreach ($registered_hooks($body) as $hook) {
            $name = substr($hook, 0, strrpos($hook, '@'));
            if (!in_array($name, $allowed, true)) {
                $errors[] = sprintf(
                    '%s is CONVERTED but still registers %s — the dispatchers own every trigger, and a format handler on its own hook is back to ordering itself against the term pass by priority (#64).',
                    $handler_files[$type],
                    $hook
                );
            }
        }
        if (preg_match('/private\s+(?:bool\s+)?\$(?:processing|is_updating_post)\b/', $body)) {
            $errors[] = sprintf(
                '%s still declares a re-entrancy boolean — the pass lock is the only guard, and a handler that no longer writes has nothing to guard against.',
                $handler_files[$type]
            );
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
foreach (($fmt_converted ?? []) as $type) {
    if (!isset($handler_files[$type])) {
        continue; // already reported above
    }
    $file = $root . '/includes/handlers/' . $handler_files[$type];
    if (!is_file($file)) {
        continue;
    }
    $applier = $method_body($strip_comments((string) file_get_contents($file)), 'apply_to_data');
    if ($applier === null) {
        $errors[] = sprintf(
            '%s is CONVERTED for the format kind but declares no apply_to_data() — it would inherit the base null, so every rule of its type reports "not mine" and silently never runs.',
            $handler_files[$type]
        );
        continue;
    }
    // The meta and option writes too, since FW-16 03: the compute step runs the
    // appliers for a preview, so anything an applier writes is written by a
    // preview. Per-rule state belongs in commit_data(), which only write() calls.
    foreach (['wp_update_post', 'wp_insert_post', 'update_post_meta', 'update_option', 'write_rule_status'] as $effect) {
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
        $errors[] = 'FormatDispatcher::write() does not call commit_data() — the idempotency meta and rule status would never be written (FW-16 03).';
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

if ($errors) {
    fwrite(STDERR, "DISPATCHER FAIL — term dispatcher invariants broken (#60):\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}

printf(
    "DISPATCHER OK — trigger union mark-only, drain after AcfWriteQueue, pass lock (entity, kind), single apply_to_post + apply_to_data call sites, fan-out + capture queue declared+enqueued, format pass driven from the shared drain AFTER the term pass; %d term converted / %d still hooked / %d capture type(s) / %d format converted (#60, #62, #63, #64).\n",
    count($converted ?? []),
    count($unconverted ?? []),
    count($capture ?? []),
    count($fmt_converted ?? [])
);
exit(0);
