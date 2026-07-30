<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\Tenant;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The FR-05 / AC-08 suspended-tenant matrix, in one place (Doc 01 FR-05, EC-03).
 *
 * AC-08 requires every entry surface — staff login, API, webhook, widget, DLR — to
 * behave "exactly per FR-05". The only way that is true rather than aspirational is
 * if one piece of code makes the decision for all five, from the state enum's own
 * methods. Five surfaces each with their own opinion is five chances to get
 * suspension subtly wrong, and the failure mode is an operator who has stopped
 * paying still sending messages to players.
 *
 * The asymmetry that matters, and the reason this is a matrix rather than a boolean:
 *
 *   suspended + inbound   → ACCEPTED and queued. Nothing is lost (US-02, BR-05).
 *   suspended + outbound  → REFUSED. The operator must not appear to be operating.
 *   suspended + staff     → REFUSED, session invalidated on the next request (EC-03).
 *
 * Declared as `tenant.state:inbound` or `tenant.state:outbound` or
 * `tenant.state:staff` so the route says which side of that asymmetry it is on.
 */
final class EnforceTenantState
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $surface): Response
    {
        $state = $this->state();

        $permitted = match ($surface) {
            'staff' => $state->allowsStaffLogin(),
            'inbound' => $state->acceptsInboundTraffic(),
            'outbound' => $state->allowsOutboundSends(),
            default => throw new \LogicException(sprintf(
                'Unknown tenant-state surface "%s". Expected staff, inbound or outbound — the three '
                .'sides of FR-05\'s matrix.',
                $surface,
            )),
        };

        if (! $permitted) {
            return ProblemDetails::response(
                code: ErrorCode::TenantStateForbidden,
                status: 403,
                detail: __('foundation.tenant.state_forbidden', ['state' => $state->value]),
                extra: [
                    // §8/§9: a suspended tenant shows a non-dismissable banner. The
                    // API contract carries the i18n key so every surface renders the
                    // same message in the user's language, and no copy is hardcoded
                    // (N-03).
                    'banner_i18n_key' => $state->requiresBanner() ? $state->bannerI18nKey() : null,
                    'tenant_state' => $state->value,
                ],
            );
        }

        return $next($request);
    }

    private function state(): TenantState
    {
        // The context is bound by ResolveTenant, which ran first and already knows
        // the state — but re-reading it here means a suspension that lands between
        // resolution and this check still takes effect, and EC-03's "next request"
        // guarantee does not depend on ordering luck.
        $tenant = Tenant::query()->findOrFail($this->context->tenantId());

        return $tenant->state();
    }
}
