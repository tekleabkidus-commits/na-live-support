<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Widget\WidgetGuard;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Settings\SettingsRegistry;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doc 13 T-03 — "rate-limited per visitor token (Doc 02 FR-25 family)": the
 * ThrottleWebhookKey shape, keyed on the PRESENTED token's hash (never the
 * raw token in a bucket name), declared AFTER the widget guard on every
 * visitor endpoint. Deliberately NOT Laravel's ThrottleRequests — that class
 * is priority-listed and the sorter would hoist it above the tenant binder
 * (MiddlewareOrderTest's law for this stack).
 *
 * Two buckets, two dials (registries/settings.php, platform-only):
 *   widget.rate.requests_per_minute — every visitor request;
 *   widget.rate.messages_per_minute — the message door only (`:messages`).
 *
 * FAIL-OPEN ON COUNTER OUTAGE with a named metric (FR-25's own posture): a
 * rate limit is capacity protection, not authentication; the guard already
 * decided who this is. A 429 is RFC 9457 with the stable `rate_limited` code
 * and Retry-After.
 */
final class ThrottleVisitorToken
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly SettingsRegistry $registry,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $scope = 'requests'): Response
    {
        $token = $request->header(WidgetGuard::TOKEN_HEADER);
        if (! is_string($token) || $token === '') {
            throw new \LogicException('Visitor throttle reached without a visitor token — declare it AFTER the widget guard (Doc 13 T-03).');
        }

        $bucket = sprintf('widget-visitor:%s:%s', $scope, hash('sha256', $token));
        $limit = $this->perMinute($scope);

        try {
            if ($this->limiter->tooManyAttempts($bucket, $limit)) {
                Log::info('widget visitor rate limit exceeded', [
                    'scope' => $scope,
                    'security_metric' => 'widget.rate_limited',
                ]);

                $response = ProblemDetails::response(
                    code: ErrorCode::RateLimited,
                    status: 429,
                    detail: (string) __('auth.throttle.too_many_requests'),
                );
                $response->headers->set('Retry-After', (string) max(1, $this->limiter->availableIn($bucket)));

                return $response;
            }
            $this->limiter->hit($bucket, 60);
        } catch (\Throwable) {
            Log::warning('widget visitor throttle counter unreachable — failing open', [
                'scope' => $scope,
                'security_metric' => 'widget.throttle_unavailable',
            ]);
        }

        return $next($request);
    }

    private function perMinute(string $scope): int
    {
        $key = $scope === 'messages' ? 'widget.rate.messages_per_minute' : 'widget.rate.requests_per_minute';
        $default = $this->registry->definition($key)['default'];

        return is_numeric($default) ? (int) $default : ($scope === 'messages' ? 20 : 120);
    }
}
