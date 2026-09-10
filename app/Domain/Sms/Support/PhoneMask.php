<?php

declare(strict_types=1);

namespace App\Domain\Sms\Support;

/**
 * The ONE masked-phone vocabulary (SEC-01/[D17]; the B4-S08 seam row).
 *
 *  - display(): the DRAWN form, byte-exact to the frozen B4-S08 copy
 *    "+251 91 ••• 4417" — country code, the first two subscriber digits,
 *    three dots, the last four. Every staff-facing surface outside the two
 *    permissioned UIs shows this and nothing longer.
 *  - forLog(): the stricter log form (no operator digits) the driver already
 *    writes — a log line is not a UI and gets less.
 */
final class PhoneMask
{
    public static function display(string $e164): string
    {
        $digits = preg_replace('/\D/', '', $e164) ?? '';
        if (strlen($digits) < 9) {
            return '••••';
        }

        return '+'.substr($digits, 0, 3).' '.substr($digits, 3, 2).' ••• '.substr($digits, -4);
    }

    public static function forLog(string $e164): string
    {
        return \App\Domain\Sms\Gateways\Drivers\HttpGenericDriver::mask($e164);
    }
}
