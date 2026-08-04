<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Disable\UserDisabler;
use App\Domain\Auth\Lockout\LockoutManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Doc 02 admin surfaces that waited for real enforcement (S19 step 7):
 *
 *  - POST …/unlock — S5's unlockByAdmin, until now unit-proven with no route
 *    ("its HTTP route is Doc 03's admin console"). Gated by
 *    admin.users.manage; audited as auth.lockout.unlocked by the manager.
 *  - POST …/disable — BR-04's UserDisabler ("no HTTP route yet by design —
 *    Doc 03 owns admin.users.disable"). Gated by admin.users.disable (high);
 *    audited as auth.user.disabled by the disabler, sessions + tokens die.
 *
 * FR-08a's last-admin protection wraps the DISABLE path at S19 step 8 — the
 * guard lands with the rails and its AC-05 proof; until that commit this
 * route exists only in this session's tree, never deployed without it.
 *
 * 404 for a missing or cross-tenant user id (C-10 — RLS already guarantees
 * the cross-tenant half; the shape is the same for both).
 */
final class AdminUsersController extends Controller
{
    public function unlock(Request $request, string $userId, LockoutManager $lockouts): JsonResponse
    {
        $admin = $this->admin($request);
        $user = User::query()->whereKey($userId)->firstOrFail();

        $lockouts->unlockByAdmin($user, $admin);

        return new JsonResponse(['status' => 'unlocked']);
    }

    public function disable(Request $request, string $userId, UserDisabler $disabler): \Symfony\Component\HttpFoundation\Response
    {
        $admin = $this->admin($request);
        $user = User::query()->whereKey($userId)->firstOrFail();

        // FR-08a (S19 step 8, as this class's docblock promised): disabling
        // the last admin-pair holder is a demotion in disguise.
        try {
            app(\App\Domain\Rbac\LastAdminGuard::class)->assertNotLastAdmin($user);
        } catch (\App\Domain\Rbac\LastAdminProtected $blocked) {
            return \App\Support\Problem\ProblemDetails::response(
                code: \App\Support\Problem\ErrorCode::Forbidden,
                status: 403,
                detail: (string) __('rbac.blocked.last_admin'),
                extra: ['other_holders' => $blocked->otherHolders],
            );
        }

        $revoked = $disabler->disable($user, CarbonImmutable::now(), $admin->identifier());

        return new JsonResponse(['status' => 'disabled', 'revoked_token_count' => $revoked]);
    }

    private function admin(Request $request): User
    {
        $admin = $request->user();
        abort_unless($admin instanceof User, 401);

        return $admin;
    }
}
