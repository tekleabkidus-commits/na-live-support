<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

/**
 * Doc 14 FR-04 `quiet_hours_exempt` + FR-03/FR-16 "held … or skipped per
 * template flag": the template's quiet-hours policy. `exempt` is FR-04's
 * flag (default true only for verification_code and security_notification);
 * `hold` is the default for everything else (FR-16: "non-exempt messages
 * arriving in-window are HELD"); `skip` is the per-template alternative FR-03
 * names. The `sms_templates.quiet_hours_policy` column carries the value;
 * `quiet_hours_exempt` reads as `policy === exempt`.
 */
enum QuietHoursPolicy: string
{
    case Exempt = 'exempt';
    case Hold = 'hold';
    case Skip = 'skip';
}
