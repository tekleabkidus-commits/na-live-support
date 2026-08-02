<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Domain\Auth\Login\StaffLoginCandidate;
use App\Domain\Auth\Login\StaffLoginResolver;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * One reset request, start to finish (Doc 02 FR-16, SEC-01, AC-01, EC-11;
 * rulings R-6, R-7; migration note: one token PER MATCHING ACCOUNT).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHO GETS A TOKEN, AND WHO SILENTLY DOES NOT. Every decision here must be
 * invisible in the response (SEC-01), so each is recorded for the operator
 * instead of the requester:
 *
 *   LOCKED accounts get one — that is EC-11's entire point: the victim's way
 *   back must not require the attacker to stop.
 *
 *   INVITED accounts (password never set) do not — their sanctioned path is
 *   the invitation resend (EC-04), which also completes profile setup (§8).
 *   A reset here would bypass that and confuse the invited state's semantics.
 *
 *   DISABLED accounts do not — BR-04 disabled them deliberately; a reset that
 *   re-armed the credential would undo an administrative decision without an
 *   administrator.
 *
 *   Non-ACTIVE tenants do not — redemption would refuse the token anyway
 *   (ActionTokenRedemption::isLive), so minting one only manufactures a
 *   dead link.
 *
 * MULTI-TENANT ADDRESSES get one token per eligible account, each bound to its
 * own user and tenant, each in its own email — and NOTHING in any email or
 * response says how many there were (R-7: the escape-hatch explanation lives
 * in runbook copy only). Completion later unlocks only its own account (R-6).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ResetRequestHandler
{
    use RunsWithinTenant;

    public function __construct(
        private readonly StaffLoginResolver $resolver,
        private readonly ActionTokenIssuer $tokens,
        private readonly PasswordLifecycleRecorder $recorder,
        private readonly SettingsRepository $settings,
        private readonly UniformResponseFloor $floor,
        private readonly TenantContext $context,
    ) {}

    public function handle(string $email, Request $request, CarbonImmutable $now): void
    {
        $this->floor->run(function () use ($email, $request, $now): void {
            foreach ($this->resolver->candidatesFor($email) as $candidate) {
                if ($this->eligible($candidate)) {
                    $this->issueFor($candidate, $request, $now);
                }
            }
        });
    }

    private function eligible(StaffLoginCandidate $candidate): bool
    {
        return $candidate->passwordSet
            && ! $candidate->disabled
            && $candidate->tenantState === TenantState::Active;
    }

    private function issueFor(StaffLoginCandidate $candidate, Request $request, CarbonImmutable $now): void
    {
        $this->withinTenant($candidate->tenantId, function () use ($candidate, $request, $now): void {
            $user = User::query()->find($candidate->userId);

            if ($user === null) {
                return; // raced away between the door and the bound read
            }

            $email = (string) $user->getAttribute('email');
            $ttlMinutes = $this->ttlMinutes();

            $plaintext = $this->tokens->issue(
                $candidate->tenantId,
                $candidate->userId,
                TokenPurpose::PasswordReset,
                $email,
                $now,
                $now->addMinutes($ttlMinutes),
                $request,
            );

            $workspace = $this->workspaceName($user);

            Mail::to($email)->send(
                (new PasswordResetMail(
                    resetUrl: route('auth.password.reset.form', ['token' => $plaintext]),
                    workspaceName: $workspace,
                    validMinutes: $ttlMinutes,
                ))->locale($this->recipientLocale($user)),
            );

            $this->recorder->resetRequested($candidate->tenantId, $candidate->userId, $request);
        }, $this->context);
    }

    private function ttlMinutes(): int
    {
        $value = $this->settings->get('auth.password.reset_ttl');

        return is_numeric($value) ? (int) $value : 60;
    }

    /**
     * §14: the email renders in the recipient's language — user preference,
     * then tenant default, then English. Resolved HERE, while the tenant is
     * bound, because the queue worker that renders the mail has no tenant and
     * cannot run the chain's tenant hop (see PasswordResetMail's docblock).
     */
    private function recipientLocale(User $user): string
    {
        $preference = $user->getAttribute('locale');

        if (is_string($preference) && $preference !== '') {
            return $preference;
        }

        $tenantDefault = $this->settings->get('tenant.default_language');

        return is_string($tenantDefault) && $tenantDefault !== '' ? $tenantDefault : 'en';
    }

    /**
     * N-03: the operator's name is DATA in the email, never copy. Same
     * resolution as the 2FA issuer (TwoFactorEnrolmentController::issuerFor).
     */
    private function workspaceName(User $user): string
    {
        $name = $user->tenant()->first()?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : 'Support';
    }
}
