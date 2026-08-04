<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doc 02 FR-09, closed at S19 step 7 with the real role resolver: users whose
 * ROLE is named in `auth.2fa.required_roles` cannot reach any screen except
 * 2FA enrolment until enrolled — after a grace of `auth.2fa.grace_days`
 * (default 3) with a countdown banner.
 *
 * The grace anchor is `two_factor_required_since`, stamped on FIRST subject
 * encounter (filed interpretation — see the column's migration). Within
 * grace: everything works and the countdown data rides the request attribute
 * below for the layout banner. Past grace: redirect to enrolment, with the
 * enrolment/security/logout surfaces allowlisted so the user can always
 * comply or leave.
 *
 * The setting stores role NAMES (the operator-meaningful identity the roles
 * UI shows); enrolled users and roleless users are out of scope by
 * construction. Days are computed against DB time (C-11).
 */
final class EnforceTwoFactorForRole
{
    public const GRACE_ATTRIBUTE = 'two_factor_grace_days_left';

    private const ALLOWED_ROUTE_PREFIXES = [
        'auth.2fa.', 'auth.security', 'auth.logout', 'auth.session',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasTwoFactorEnabled() || $user->isServiceAccount()) {
            return $next($request);
        }

        // The web group carries auth surfaces that run BEFORE tenant
        // resolution (login redirects, the recaller); settings are
        // tenant-scoped, so enforcement waits for a bound tenant — the staff
        // stacks always bind, so no protected surface escapes.
        if (! app(\App\Support\Tenancy\TenantContext::class)->isBound()) {
            return $next($request);
        }

        $requiredRoles = $this->settings->get('auth.2fa.required_roles');
        if (! is_array($requiredRoles) || $requiredRoles === []) {
            return $next($request);
        }

        $role = $user->role()->first();
        if ($role === null || ! in_array($role->name, $requiredRoles, true)) {
            return $next($request);
        }

        $daysLeft = $this->graceDaysLeft($user);

        if ($daysLeft > 0) {
            $request->attributes->set(self::GRACE_ATTRIBUTE, $daysLeft);

            return $next($request);
        }

        if ($this->isAllowedRoute($request)) {
            return $next($request);
        }

        return $request->expectsJson()
            ? new \Illuminate\Http\JsonResponse(['status' => 'two_factor_enrolment_required'], 403)
            : redirect()->route('auth.security.page');
    }

    private function graceDaysLeft(User $user): int
    {
        // First subject encounter — the anchor starts NOW, in DB time. The
        // guarded UPDATE can only ever FILL the anchor, never reset it, so a
        // stale model instance cannot restart anyone's grace window.
        DB::update(
            'UPDATE users SET two_factor_required_since = now() WHERE id = ? AND two_factor_required_since IS NULL',
            [$user->identifier()],
        );

        $graceDaysSetting = $this->settings->get('auth.2fa.grace_days');
        $graceDays = is_numeric($graceDaysSetting) ? (int) $graceDaysSetting : 3;

        /** @var object{left: int|string} $row */
        $row = DB::selectOne(
            'SELECT GREATEST(0, CEIL(EXTRACT(EPOCH FROM ((two_factor_required_since + make_interval(days => ?)) - now())) / 86400)) AS left
               FROM users WHERE id = ?',
            [$graceDays, $user->identifier()],
        );

        return (int) $row->left;
    }

    private function isAllowedRoute(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        foreach (self::ALLOWED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
