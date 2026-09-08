<?php

declare(strict_types=1);

namespace App\Domain\Sms\Encoding;

/**
 * 3GPP TS 23.038 §6.2.1 — the GSM 7-bit default alphabet (basic table, one
 * septet each) and its extension table (two septets each: the escape plus the
 * character). ONE character outside both forces UCS-2 (FR-05).
 */
final class Gsm7Charset
{
    private const BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const EXTENSION = "\f^{}\\[~]|€";

    /** @var array<int|string, int>|null septets per character, lazily built (PHP coerces the digit characters to int keys) */
    private static ?array $septets = null;

    /** @return int septets this character costs in GSM-7, or 0 when it is not representable */
    public static function septets(string $character): int
    {
        if (self::$septets === null) {
            $table = [];
            foreach (mb_str_split(self::BASIC, 1, 'UTF-8') as $c) {
                $table[$c] = 1;
            }
            foreach (mb_str_split(self::EXTENSION, 1, 'UTF-8') as $c) {
                $table[$c] = 2;
            }
            self::$septets = $table;
        }

        return self::$septets[$character] ?? 0;
    }
}
