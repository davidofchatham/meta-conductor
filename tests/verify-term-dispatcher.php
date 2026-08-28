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
        $pattern = '/add_action\(\s*[\'"]' . preg_quote($hook, '/') . '[\'"]\s*,\s*(?:array\(|\[)\s*\$this\s*,\s*[\'"](\w+)[\'"]/';
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

if ($errors) {
    fwrite(STDERR, "DISPATCHER FAIL — term dispatcher invariants broken (#60):\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}

printf(
    "DISPATCHER OK — trigger union mark-only, drain after AcfWriteQueue, pass lock (entity, kind), single apply_to_post call site, fan-out declared+enqueued; %d converted / %d still hooked / %d capture type(s) (#60, #62).\n",
    count($converted ?? []),
    count($unconverted ?? []),
    count($capture ?? [])
);
exit(0);
