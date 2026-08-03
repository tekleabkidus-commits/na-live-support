<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Password\ActionTokenRedeemer;
use App\Domain\Auth\Password\PasswordChangeOrigin;
use App\Domain\Auth\Password\PasswordChanger;
use App\Domain\Auth\Password\ResetRequestHandler;
use App\Domain\Auth\Password\TokenPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The FR-16 reset surface (Doc 02 FR-16, SEC-01, SEC-02, AC-01, EC-03, EC-11).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FOUR MOMENTS, FOUR RULES:
 *
 *   request()  answers IDENTICALLY whatever happens — AC-01's reset half. The
 *              body is a fixed shape, and ResetRequestHandler holds every
 *              response to the same duration floor. Nothing conditional
 *              reaches this method's return.
 *
 *   show()     is where FR-16's "dedicated error screen" lives: a dead link —
 *              unknown, expired, used, superseded, all indistinguishable
 *              (SEC-01) — renders the request-again screen, never a form that
 *              would fail on submit.
 *
 *   store()    consumes the token BETWEEN policy passing and the password
 *              changing (single-use has to bind to the mutation, not to the
 *              attempt): a policy failure leaves the link alive for the retry
 *              the user is about to make; a consumed token can never act
 *              twice. The gate closure PasswordChanger runs after policy is
 *              what enforces that ordering in one place.
 *
 *   EC-03      is satisfied by what is ABSENT: nothing here reads a device,
 *              cookie or session from request time, so a link opened on a
 *              different device simply works. The new-device notice belongs
 *              to the login that follows (T-03's deferred notice).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PasswordResetController extends Controller
{
    use RunsWithinTenant;

    public function __construct(
        private readonly ResetRequestHandler $requests,
        private readonly ActionTokenRedeemer $redeemer,
        private readonly PasswordChanger $changer,
        private readonly SettingsRepository $settings,
        private readonly TenantContext $context,
    ) {}

    /**
     * GET /forgot-password — the request form.
     */
    public function showRequestForm(): InertiaResponse
    {
        return Inertia::render('Auth/ForgotPassword', [
            'copy' => [
                'title' => __('auth.password.forgot_title'),
                'intro' => __('auth.password.forgot_intro'),
                'emailLabel' => __('auth.password.email_label'),
                'submit' => __('auth.password.forgot_submit'),
                'sent' => __('auth.password.forgot_sent'),
            ],
        ]);
    }

    /**
     * POST /forgot-password — mint tokens for eligible candidates, answer
     * uniformly (SEC-01: "if the account exists…").
     */
    public function request(Request $request): JsonResponse|RedirectResponse
    {
        $email = $request->string('email')->toString();

        if ($email !== '') {
            $this->requests->handle($email, $request, CarbonImmutable::now());
        }

        // One body for every outcome — including the empty-email one, which
        // must not become a distinguishable validation error (AC-01 compares
        // BYTES; a "please enter an email" branch is a second body).
        if ($request->expectsJson()) {
            return new JsonResponse(['status' => 'accepted']);
        }

        return redirect()->back()->with('status', 'sent');
    }

    /**
     * GET /reset-password?token=… — the form, or the dedicated dead-link screen.
     */
    public function show(Request $request): InertiaResponse
    {
        $token = $request->string('token')->toString();
        $redemption = $this->redeemer->resolve($token, TokenPurpose::PasswordReset);

        if ($redemption === null) {
            return $this->deadLinkScreen();
        }

        $minLength = $this->withinTenant(
            $redemption->tenantId,
            fn (): int => $this->minLength(),
            $this->context,
        );

        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'minLength' => $minLength,
            'copy' => [
                'title' => __('auth.password.reset_title'),
                'passwordLabel' => __('auth.password.password_label'),
                'confirmLabel' => __('auth.password.confirm_label'),
                'submit' => __('auth.password.reset_submit'),
                'done' => __('auth.password.reset_done'),
                'lengthHint' => __('auth.password.rule_length', ['min' => $minLength]),
                'mismatch' => __('auth.password.confirm_mismatch'),

                // Step 13: §9's password field (toggle + text strength meter).
                'showPassword' => __('auth.login.show_password'),
                'hidePassword' => __('auth.login.hide_password'),
                'strengthLabel' => __('auth.password.strength_label'),
                'strengthWeak' => __('auth.password.strength_weak'),
                'strengthFair' => __('auth.password.strength_fair'),
                'strengthStrong' => __('auth.password.strength_strong'),
            ],
        ]);
    }

    /**
     * POST /reset-password — apply the new password on a live token.
     */
    public function store(Request $request): Response
    {
        $token = $request->string('token')->toString();
        $password = $request->string('password')->toString();

        $redemption = $this->redeemer->resolve($token, TokenPurpose::PasswordReset);

        if ($redemption === null) {
            return $this->deadLinkResponse($request);
        }

        if ($password === '' || ! hash_equals($password, $request->string('password_confirmation')->toString())) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: (string) __('auth.password.confirm_mismatch'),
                errors: ['password_confirmation' => [(string) __('auth.password.confirm_mismatch')]],
            );
        }

        $now = CarbonImmutable::now();

        $applied = $this->withinTenant($redemption->tenantId, function () use ($redemption, $password, $request, $now): bool {
            $user = User::query()->find($redemption->userId);

            if ($user === null) {
                return false;
            }

            // Policy first, then the token dies, then the password changes —
            // see the class docblock. PasswordPolicyViolation escapes from
            // inside apply() with the token still live.
            $this->changer->apply(
                $user,
                $password,
                PasswordChangeOrigin::Reset,
                $now,
                $request,
                keepSessionId: null,
                oncePolicyPassed: fn (): bool => $this->redeemer->consume($redemption, $now),
            );

            return true;
        }, $this->context);

        if (! $applied) {
            return $this->deadLinkResponse($request);
        }

        if ($request->expectsJson()) {
            return new JsonResponse(['status' => 'password_reset']);
        }

        return redirect('/')->with('status', 'password_reset');
    }

    private function deadLinkScreen(): InertiaResponse
    {
        return Inertia::render('Auth/LinkDead', [
            'purpose' => TokenPurpose::PasswordReset->value,
            'requestAgainUrl' => route('auth.password.forgot.form'),
            'copy' => $this->deadLinkCopy(),
        ]);
    }

    private function deadLinkResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return ProblemDetails::response(
                code: ErrorCode::ActionLinkInvalid,
                status: 422,
                detail: (string) __('auth.password.link_dead_body'),
            );
        }

        return redirect()->route('auth.password.forgot.form')->with('status', 'link_dead');
    }

    /**
     * @return array<string, mixed>
     */
    private function deadLinkCopy(): array
    {
        return [
            'title' => __('auth.password.link_dead_title'),
            'body' => __('auth.password.link_dead_body'),
            'requestAgain' => __('auth.password.link_dead_again'),
        ];
    }

    private function minLength(): int
    {
        $value = $this->settings->get('auth.password.min_length');

        return is_numeric($value) ? (int) $value : 12;
    }
}
