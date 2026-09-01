<?php

declare(strict_types=1);

namespace App\Domain\Macros;

/**
 * Doc 12 T-06 / BR-04 / FR-15 — the no-blank grammar: a body still carrying an
 * unresolved {{variable}} pill NEVER reaches a customer. The automation send
 * arm enforces it now; packet P-SEND puts the same one-line refusal into
 * Doc 04's ComposeOutbound so the STAFF send API refuses too (T-06's own
 * wording — "blocks at the send API, not only UI"). One grammar, both doors.
 */
final class MacroGuard
{
    public const CODE = 'blank_variable';

    /** @return list<string> the unresolved variable names still in the body */
    public static function unresolvedIn(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z_.]+[a-z0-9_.]*)\s*\}\}/i', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    public static function assertNoBlank(string $body): void
    {
        $unresolved = self::unresolvedIn($body);
        if ($unresolved !== []) {
            throw new \DomainException(sprintf(
                '%s: the body still carries unresolved variable(s) [%s] — no {{blank}} ever reaches a customer (FR-15/BR-04).',
                self::CODE,
                implode(', ', $unresolved),
            ));
        }
    }
}
