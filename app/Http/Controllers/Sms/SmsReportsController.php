<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\Reports\SmsFacts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * SEC-06 — the SMS report feed (spend + counts) is `reports.view`-gated at
 * the route. The reader excludes fixture/test rows at the source.
 */
final class SmsReportsController extends Controller
{
    public function __construct(private readonly SmsFacts $facts) {}

    public function summary(): JsonResponse
    {
        return new JsonResponse($this->facts->summary());
    }
}
