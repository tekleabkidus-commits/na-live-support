<?php

declare(strict_types=1);

namespace App\Domain\Auth\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * P-DLR-AUTH (Doc 14 R-D14-5; STOP-C amendment 1): a DETECTED replay on the
 * `url_secret` surface. Thrown from MIDDLEWARE so the handler is never
 * invoked (AC-07's "nothing processed" stays structural), it renders 202 —
 * never a 403 an unsigned gateway could treat as delivery failure and retry
 * forever. The metric names the category; no secret material is logged.
 */
final class WebhookReplayIgnored extends RuntimeException
{
    private function __construct()
    {
        parent::__construct('Webhook replay ignored');

        Log::info('webhook replay ignored', ['security_metric' => 'webhook.replay_ignored']);
    }

    public static function withinWindow(): self
    {
        return new self;
    }

    public function render(Request $request): Response
    {
        return new JsonResponse(['replay' => 'ignored'], 202);
    }
}
