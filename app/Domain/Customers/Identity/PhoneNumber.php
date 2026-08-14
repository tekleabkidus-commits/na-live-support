<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * ADR 0014's single wrapper — no other code touches libphonenumber (pinned
 * 9.0.36). Normalize ONCE at write (condition b); stored values are never
 * re-normalized by an upgrade (condition c — the divergence report is the
 * upgrade's job, not this class's).
 *
 * FR-04: "phone (E.164, normalized on save with country inference help)".
 * §14 / B4-S08 I08: the region is SHOWN, never assumed — display derives the
 * national format + region from the STORED E.164, so a Kenyan number renders
 * Kenyan.
 */
final class PhoneNumber
{
    /**
     * @param  string  $regionHint  ISO region for numbers typed without +CC
     *                              (the country selector's value, or the
     *                              tenant default — FR-04's inference help)
     */
    public static function toE164(string $raw, string $regionHint = 'ET'): string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($raw, $regionHint);
        } catch (NumberParseException $e) {
            throw new InvalidArgumentException("not a parseable phone number: {$e->getMessage()}", previous: $e);
        }

        if (! $util->isValidNumber($parsed)) {
            throw new InvalidArgumentException('not a valid phone number for its region');
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    /** National display form derived from the STORED E.164 (never re-stored). */
    public static function national(string $e164): string
    {
        $util = PhoneNumberUtil::getInstance();

        return $util->format($util->parse($e164, null), PhoneNumberFormat::NATIONAL);
    }

    /** ISO region derived from the STORED E.164 — the flag the UI shows (I08). */
    public static function region(string $e164): ?string
    {
        $util = PhoneNumberUtil::getInstance();

        return $util->getRegionCodeForNumber($util->parse($e164, null));
    }
}
