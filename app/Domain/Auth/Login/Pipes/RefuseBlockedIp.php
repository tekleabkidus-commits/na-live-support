<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login\Pipes;

use App\Domain\Auth\Login\FailureReason;
use App\Domain\Auth\Login\IpAllowlist;
use App\Domain\Auth\Login\LoginRecorder;
use App\Domain\Auth\Login\LoginRefused;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Doc 02 SEC-05, closed at S19 step 7 with the role store: per-ROLE IP
 * allowlists, evaluated POST-AUTH PRE-SESSION — after RefuseBlockedPrincipal
 * in both pipelines (password acceptance and 2FA completion), so a blocked
 * IP is told nothing an unauthenticated caller could not learn (the refusal
 * is FR-02's generic error; the truth lives in the audit trail and the
 * auth.ip.blocked event).
 *
 * A user with no role, or a role with a NULL allowlist, passes — the
 * restriction is an explicit per-role choice. FailureReason::IpBlocked,
 * defined-but-unfired since S5, finally fires here.
 */
final class RefuseBlockedIp
{
    public function __construct(private readonly LoginRecorder $recorder) {}

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user('staff') ?? auth('staff')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $role = $user->role()->first();
        /** @var list<string>|null $allowlist */
        $allowlist = $role?->ip_allowlist;

        if ($role === null || IpAllowlist::permits($allowlist, (string) $request->ip())) {
            return $next($request);
        }

        $this->recorder->ipBlocked($user, $request);

        auth('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw new LoginRefused(FailureReason::IpBlocked);
    }
}
