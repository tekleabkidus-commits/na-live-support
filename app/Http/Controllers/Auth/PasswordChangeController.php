<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Password\PasswordChangeOrigin;
use App\Domain\Auth\Password\PasswordChanger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authenticated self-service password change (Doc 02 FR-14, FR-15).
 *
 * PROVING THE CURRENT PASSWORD IS THE POINT, not a formality: a live session
 * is a weaker credential than the password (it can be a walked-away-from desk,
 * a stolen cookie inside its FR-11 windows), and the one thing a session thief
 * must not be able to do with it is set a password they DO know. The argon2id
 * cost of the proof is NFR-03's, on a rare human action.
 *
 * FR-14's revocation half: every OTHER session dies; the one that proved the
 * password survives. The 2FA sibling (TwoFactorEnrolmentController) already
 * revokes on 2FA changes; between them FR-14's sentence is fully implemented.
 */
final class PasswordChangeController extends Controller
{
    public function __construct(private readonly PasswordChanger $changer) {}

    public function update(Request $request): Response
    {
        $user = $request->user('staff');

        if (! $user instanceof User) {
            // Unreachable behind auth:staff; the narrow type is for PHPStan
            // and for the day someone reorders the middleware.
            abort(401);
        }

        $current = $request->string('current_password')->toString();
        $password = $request->string('password')->toString();

        if (! Hash::check($current, (string) $user->getRawOriginal('password'))) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: (string) __('auth.password.current_password_wrong'),
                errors: ['current_password' => [(string) __('auth.password.current_password_wrong')]],
            );
        }

        if ($password === '' || ! hash_equals($password, $request->string('password_confirmation')->toString())) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: (string) __('auth.password.confirm_mismatch'),
                errors: ['password_confirmation' => [(string) __('auth.password.confirm_mismatch')]],
            );
        }

        $this->changer->apply(
            $user,
            $password,
            PasswordChangeOrigin::SelfChange,
            CarbonImmutable::now(),
            $request,
            keepSessionId: $request->session()->getId(),
        );

        return new \Illuminate\Http\JsonResponse(['status' => 'password_changed']);
    }
}
