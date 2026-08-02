<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-15's max-age clause: an expired password confines its owner to the
 * surface where it can be changed (Doc 02 FR-15; auth.password.max_age_days,
 * default 0 = disabled).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CONFINEMENT, NOT LOCKOUT. The principal stays authenticated — their identity
 * is not in doubt, their credential is merely stale — so this runs AFTER the
 * guard and the tenant middleware (it needs both the user and the tenant's own
 * setting) and allows exactly three doors: the Security page (where the change
 * form lives), the change endpoint itself, and logout. Everything else answers
 * with one problem-details code the client can branch on to route the user.
 *
 * S10'S LESSON APPLIES VERBATIM: a policy proven with an injected clock while
 * its middleware hangs attached to nothing is a paper control. This class
 * ships wired into both staff surfaces in bootstrap/app.php + routes/api.php,
 * and PasswordPolicyTest's sabotage clause is "unwire it and two tests go
 * red", not a re-proof of date arithmetic.
 *
 * THE COMPARISON READS password_changed_at — the column FR-15 says an
 * admin-forced expiry can move independently of password_set_at. A user with
 * NO password_changed_at (pre-lifecycle accounts) is treated as not expired:
 * max-age is an operator policy over changes the platform has seen, not a
 * retroactive lockout of every account older than the feature.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class EnforcePasswordExpiry
{
    use RunsWithinTenant;

    /**
     * Route names an expired-password principal may still reach.
     */
    private const ALLOWED_ROUTES = [
        'auth.security.page',
        'auth.password.change',
        'auth.logout',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('staff');

        if (! $user instanceof User) {
            return $next($request); // guests have no password to expire
        }

        // READ UNDER THE USER'S OWN TENANT, explicitly. On the staff API this
        // middleware runs after `tenant:staff_session` and the bind is a
        // no-op; on the WEB group no tenant middleware exists, and an unbound
        // SettingsRepository::get() throws — which 500'd every authenticated
        // page the moment this middleware was first wired. Caught by the
        // browser suite only: the pest suite cannot render the one
        // authenticated web page (no Vite manifest in stage 3), which is
        // exactly why the browser stage exists.
        $maxAgeDays = $this->withinTenant(
            $user->tenantId(),
            fn (): int => $this->maxAgeDays(),
            $this->context,
        );

        if ($maxAgeDays <= 0) {
            return $next($request); // 0 = disabled, the spec default
        }

        $changedAt = $user->getAttribute('password_changed_at');

        if (! $changedAt instanceof \DateTimeInterface) {
            return $next($request);
        }

        $expired = CarbonImmutable::instance($changedAt)
            ->addDays($maxAgeDays)
            ->lessThanOrEqualTo(CarbonImmutable::now());

        if (! $expired || in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return ProblemDetails::response(
                code: ErrorCode::PasswordExpired,
                status: 403,
                detail: (string) __('auth.password.expired_notice'),
            );
        }

        return redirect()->route('auth.security.page')->with('status', 'password_expired');
    }

    private function maxAgeDays(): int
    {
        $value = $this->settings->get('auth.password.max_age_days');

        return is_numeric($value) ? (int) $value : 0;
    }
}
