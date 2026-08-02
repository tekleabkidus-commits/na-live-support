<?php

declare(strict_types=1);

namespace App\Domain\Auth\Webhooks;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A webhook request failed its second credential (Doc 02 FR-23/FR-24, AC-07;
 * step-11 ruling on the unified refusal).
 *
 * ONE code, ONE byte-identical body for every cause — secret mismatch, header
 * absent, signature wrong, timestamp stale, nonce replayed, key unconfigured,
 * replay cache unreachable. The response never says which check refused
 * (distinguishing them would hand a probing caller a debugging oracle for the
 * platform's webhook defences); the METRICS distinguish them fully, because
 * monitoring and Doc 13/18's dashboards need the cause and hold no oracle.
 *
 * AC-07's three clauses land here and around here: 403 (this render),
 * metric incremented (the constructor's log line), nothing processed (this
 * throws from MIDDLEWARE — the handler is never invoked, which the spy-route
 * test asserts positively).
 */
final class WebhookRejected extends RuntimeException
{
    private function __construct(public readonly string $internalReason, string $metric)
    {
        parent::__construct(sprintf('Webhook rejected: %s', $internalReason));

        // No secret material here, ever: reason categories and the C-10 shape
        // only. The presented signature/secret is not even passed in.
        Log::warning('webhook rejected', [
            'reason' => $internalReason,
            'security_metric' => $metric,
        ]);
    }

    public static function secretRejected(string $internalReason): self
    {
        return new self($internalReason, 'webhook.secret_rejected');
    }

    public static function replayCacheUnavailable(): self
    {
        return new self('replay_cache_unavailable', 'webhook.replay_cache_unavailable');
    }

    public function render(Request $request): Response
    {
        // One render path — byte-identity is a property of the shape (the
        // AC-01 lesson, third application: login, tokens, now webhooks).
        return ProblemDetails::response(
            code: ErrorCode::WebhookRejected,
            status: 403,
            detail: (string) __('auth.webhook.rejected'),
        );
    }
}
