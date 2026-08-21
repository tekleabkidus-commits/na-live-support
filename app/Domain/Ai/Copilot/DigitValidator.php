<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

/**
 * §14's MONEY-SAFETY RULE: "translation tasks preserve numbers/currency/
 * reference formats verbatim (validator rejects altered digits)". EC-10:
 * the rule applies to mixed-language input too.
 *
 * Mechanism: every number-bearing token of the SOURCE must appear in the
 * TARGET the same number of times — a multiset comparison, order-free
 * (translation reorders clauses), but VALUE-exact: "1,500" and "1500" are
 * different tokens, as are "TX-48213" and "TX-48231". Tokens: runs of
 * ASCII or Ethiopic digits with their internal separators (., : / -) and
 * uppercase reference prefixes (TX-, REF…). A reordered digit, a dropped
 * zero, a "translated" currency amount — all rejected. Sabotage #14
 * disables this and the altered-digit fixture goes red.
 */
final class DigitValidator
{
    private const TOKEN = '/(?:[A-Z]{1,6}-?)?[0-9\x{1369}-\x{137C}]+(?:[.,:\/-][0-9\x{1369}-\x{137C}]+)*/u';

    /**
     * @return array{ok: bool, missing: list<string>, extra: list<string>}
     */
    public static function compare(string $source, string $target): array
    {
        $sourceTokens = self::tokens($source);
        $targetTokens = self::tokens($target);

        $missing = self::difference($sourceTokens, $targetTokens);
        $extra = self::difference($targetTokens, $sourceTokens);

        return ['ok' => $missing === [] && $extra === [], 'missing' => $missing, 'extra' => $extra];
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $matches);

        $tokens = $matches[0];
        sort($tokens);

        return $tokens;
    }

    /**
     * Multiset difference: what $a holds that $b lacks (with multiplicity).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private static function difference(array $a, array $b): array
    {
        $counts = array_count_values($b);
        $result = [];
        foreach ($a as $token) {
            if (($counts[$token] ?? 0) > 0) {
                $counts[$token]--;

                continue;
            }
            $result[] = $token;
        }

        return $result;
    }
}
