<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Password\ActionTokenRedeemer;
use App\Domain\Auth\Password\InvitationIssuer;
use App\Domain\Auth\Password\PasswordChangeOrigin;
use App\Domain\Auth\Password\PasswordChanger;
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
 * The FR-17 invited-user surface (Doc 02 FR-17, EC-04, BR-02, SEC-01, SEC-02).
 *
 * Deliberately the same shape as PasswordResetController — same dead-link
 * discipline, same consume-between-policy-and-mutation ordering, same uniform
 * resend answer — because a person clicking an emailed link should meet one
 * behaviour, not two. What differs is only what FR-17 makes different: the
 * door (invitation tokens), the validity horizon (7 days), and the outcome
 * (an invited account becomes active via its FIRST password).
 *
 * §8's "set-password screen doubles as profile completion (name, language,
 * optional avatar)" is the VIEWS step's — this ships the functional password
 * half, exactly as S11 shipped a functional 2FA card ahead of the designed
 * wizard. Recorded in BUILD-STATUS, not silently dropped.
 */
final class InvitationController extends Controller
{
    use RunsWithinTenant;

    public function __construct(
        private readonly ActionTokenRedeemer $redeemer,
        private readonly PasswordChanger $changer,
        private readonly InvitationIssuer $issuer,
        private readonly SettingsRepository $settings,
        private readonly TenantContext $context,
    ) {}

    /**
     * GET /invitation?token=… — the set-password form, or the dead-link screen
     * (which carries the EC-04 self-service resend).
     */
    public function show(Request $request): InertiaResponse
    {
        $token = $request->string('token')->toString();
        $redemption = $this->redeemer->resolve($token, TokenPurpose::Invitation);

        if ($redemption === null) {
            return Inertia::render('Auth/LinkDead', [
                'purpose' => TokenPurpose::Invitation->value,
                'resendUrl' => route('auth.invitation.resend'),
                'copy' => [
                    'title' => __('auth.password.link_dead_title'),
                    'body' => __('auth.password.link_dead_body'),
                    'requestAgain' => __('auth.password.invite_resend'),
                    'emailLabel' => __('auth.password.email_label'),
                    'resent' => __('auth.password.invite_resent_note'),
                ],
            ]);
        }

        // §8 (step 13): the screen doubles as profile completion, so the form
        // prefills what the admin already entered — completion, not a blank
        // slate. Read within the token's tenant like everything else here.
        $prefill = $this->withinTenant($redemption->tenantId, function () use ($redemption): array {
            $user = User::query()->find($redemption->userId);
            $locale = $user?->getAttribute('locale');

            return [
                'minLength' => $this->minLength(),
                'name' => is_string($user?->getAttribute('name')) ? $user->getAttribute('name') : '',
                'locale' => in_array($locale, ['en', 'am'], true) ? $locale : 'en',
            ];
        }, $this->context);

        $minLength = $prefill['minLength'];

        return Inertia::render('Auth/Invitation', [
            'token' => $token,
            'minLength' => $minLength,
            'copy' => [
                'title' => __('auth.password.invite_title'),
                'intro' => __('auth.password.invite_intro'),
                'passwordLabel' => __('auth.password.password_label'),
                'confirmLabel' => __('auth.password.confirm_label'),
                'submit' => __('auth.password.invite_submit'),
                'done' => __('auth.password.invite_done'),
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
            'profile' => [
                'nameLabel' => __('auth.password.name_label'),
                'languageLabel' => __('auth.login.language_label'),
                'langEnglish' => __('auth.login.lang_english'),
                'langAmharic' => __('auth.login.lang_amharic'),
                'name' => $prefill['name'],
                'locale' => $prefill['locale'],
            ],
        ]);
    }

    /**
     * POST /invitation — the invited person sets their FIRST password. The
     * only place a password enters this flow (BR-02: never an admin).
     */
    public function store(Request $request): Response
    {
        $token = $request->string('token')->toString();
        $password = $request->string('password')->toString();

        $redemption = $this->redeemer->resolve($token, TokenPurpose::Invitation);

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

        // §8 (step 13): the profile-completion half. Both optional; both
        // refused loudly when malformed rather than silently rewritten. The
        // locale bar matches ApplyUserLocale's guest hop exactly — en|am, an
        // exact set, because this value feeds the same translator.
        $name = trim($request->string('name')->toString());
        $locale = $request->string('locale')->toString();

        if ($locale !== '' && ! in_array($locale, ['en', 'am'], true)) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: (string) __('auth.password.invalid_language'),
                errors: ['locale' => [(string) __('auth.password.invalid_language')]],
            );
        }

        if (mb_strlen($name) > 255) {
            return ProblemDetails::response(
                code: ErrorCode::ValidationFailed,
                status: 422,
                detail: (string) __('auth.password.invalid_name'),
                errors: ['name' => [(string) __('auth.password.invalid_name')]],
            );
        }

        $now = CarbonImmutable::now();

        $applied = $this->withinTenant($redemption->tenantId, function () use ($redemption, $password, $name, $locale, $request, $now): bool {
            $user = User::query()->find($redemption->userId);

            if ($user === null) {
                return false;
            }

            $this->changer->apply(
                $user,
                $password,
                PasswordChangeOrigin::InvitationAccept,
                $now,
                $request,
                keepSessionId: null,
                oncePolicyPassed: fn (): bool => $this->redeemer->consume($redemption, $now),
            );

            // AFTER the password applied, deliberately: a policy-refused
            // attempt must leave the user row untouched — a mutation on a
            // failed accept would be a write the route's audit declaration
            // (fired on 2xx only) never covers.
            if ($name !== '') {
                $user->setAttribute('name', $name);
            }
            if ($locale !== '') {
                $user->setAttribute('locale', $locale);
            }
            if ($user->isDirty()) {
                $user->save();
            }

            return true;
        }, $this->context);

        if (! $applied) {
            return $this->deadLinkResponse($request);
        }

        if ($request->expectsJson()) {
            return new JsonResponse(['status' => 'password_set']);
        }

        return redirect('/')->with('status', 'password_set');
    }

    /**
     * POST /invitation/resend — EC-04's self-service half. Uniform answer,
     * rate-limited by the route, floor-equalized by the issuer (SEC-01 names
     * the invite flow alongside login and reset).
     */
    public function resend(Request $request): JsonResponse|RedirectResponse
    {
        $email = $request->string('email')->toString();

        if ($email !== '') {
            $this->issuer->resendForEmail($email, $request, CarbonImmutable::now());
        }

        if ($request->expectsJson()) {
            return new JsonResponse(['status' => 'accepted']);
        }

        return redirect()->back()->with('status', 'sent');
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

        return redirect()->route('auth.invitation.form')->with('status', 'link_dead');
    }

    private function minLength(): int
    {
        $value = $this->settings->get('auth.password.min_length');

        return is_numeric($value) ? (int) $value : 12;
    }
}
