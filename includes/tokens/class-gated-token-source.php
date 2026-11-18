<?php

namespace BWS\MetaConductor\Tokens;

if (!defined('ABSPATH')) exit;

/**
 * A related post the status gate holds back (FW-67): it exists, but it is not
 * published, so a `{parent:…}` or `{refs:…}` token must not read it.
 *
 * Answers nothing, so every token behind it renders empty and drops out like any
 * empty token. It exists as its own type, and not as `null`, so the engine can
 * tell "held back" from "nothing related" (`TokenEngine::all_gated()`): a pattern
 * whose every token is held back is skipped, where one whose related post just
 * has no value keeps writing as before. A null object, because the six-method
 * `TokenSourceInterface` is pinned by H17.
 */
final class GatedTokenSource implements TokenSourceInterface {

    public function field(string $key): mixed {
        return '';
    }

    public function terms(string $taxonomy): array {
        return [];
    }

    public function published(): ?\DateTimeInterface {
        return null;
    }

    public function modified(): ?\DateTimeInterface {
        return null;
    }

    public function slug(): string {
        return '';
    }

    public function related(string $relation, string $field = ''): ?TokenSourceInterface {
        return null;
    }
}
