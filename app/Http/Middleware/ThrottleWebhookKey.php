<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Settings\SettingsRegistry;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * `webhook.throttle` — FR-25's per-key rate limit (Doc 02 FR-25, §12).
 *
 * PER KEY, not per tenant and not per IP, on purpose: one operator can run
 * several bots and a gateway; one chatty bot must not starve its siblings
 * (proven by the isolation test, and sabotage (e) flips the key to the
 * tenant id to watch it fail). The key id comes from the binder's request
 * attribute, so this sits AFTER `tenant:*` — and before the secret check,
 * FR-03's cheapest-first pattern: a flooding caller is refused by a Redis
 * counter before any credential row is even read.
 *
 * DELIBERATELY NOT Laravel's ThrottleRequests: that class is priority-listed
 * and the sorter would hoist it above the (unlisted) binder — the step-9 bug
 * shape, avoided the step-10 way (a plain class the sorter cannot see).
 *
 * The limit is the REGISTERED PLATFORM DEFAULT (auth.webhook.rate_per_minute,
 * ruling decision 4): tenant_editable is false — the limit protects shared
 * ingress capacity — so the registry default IS the value and no per-tenant
 * read is needed. O(1): Laravel's limiter is a Redis counter with a TTL
 * (§12's budget note, same as the lockout counters).
 */
final class ThrottleWebhookKey
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly SettingsRegistry $registry,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);

        if (! is_string($keyId) || $keyId === '') {
            throw new \LogicException('Webhook throttle reached without a resolved key (Doc 02 FR-25).');
        }

        $bucket = 'webhook-key:'.$keyId;

        // FAIL-OPEN ON COUNTER OUTAGE, with a named metric (NFR-04's shape).
        // A rate limit is capacity protection, not authentication: when its
        // Redis is down the platform is already degraded, and refusing ALL
        // webhook traffic for want of a counter would convert a cache outage
        // into a channel outage. The SECURITY decisions keep their own
        // postures regardless: secret_header needs no Redis at all, and the
        // FR-24 replay guard fails CLOSED (ruling, decision 3) — the FS test
        // proves all three behaviors against one broken Redis.
        try {
            if ($this->limiter->tooManyAttempts($bucket, $this->perMinute())) {
                Log::warning('webhook rate limit exceeded', [
                    'key_id' => $keyId,
                    'security_metric' => 'webhook.rate_limited',
                ]);

                return ProblemDetails::response(
                    code: ErrorCode::RateLimited,
                    status: 429,
                    detail: (string) __('auth.throttle.too_many_requests'),
                );
            }

            $this->limiter->hit($bucket, 60);
        } catch (\Throwable) {
            Log::warning('webhook throttle counter unreachable — failing open', [
                'key_id' => $keyId,
                'security_metric' => 'webhook.throttle_unavailable',
            ]);
        }

        return $next($request);
    }

    private function perMinute(): int
    {
        $definition = $this->registry->definition('auth.webhook.rate_per_minute');
        $default = $definition['default'];

        return is_numeric($default) ? (int) $default : 600;
    }
}
