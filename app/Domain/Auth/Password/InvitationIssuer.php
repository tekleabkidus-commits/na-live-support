<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Domain\Auth\Login\StaffLoginResolver;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Mail\InvitationMail;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Mints and re-mints invitation links (Doc 02 FR-17, EC-04, BR-02, SEC-01).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NO PASSWORD PARAMETER EXISTS ON ANY PATH THROUGH THIS CLASS — that is FR-17's
 * "admins never choose passwords for users" and BR-02 made structural: the
 * issuance signature accepts a user and a clock, the link carries a token, and
 * the only place a password can enter the system is the invited person's own
 * set-password form. InvitationTest asserts this shape by reflection.
 *
 * WHO CALLS issue(). Nobody yet, over HTTP: the admin user-creation surface is
 * Doc 03's (roles must exist before users can be created with one). The service
 * exists now because the TOKEN lifecycle is this document's (FR-17 defines the
 * invited state, its 7-day link and its resend), and Doc 03 will call exactly
 * this method rather than reimplementing it.
 *
 * resendForEmail() is EC-04's self-service half and IS wired now (an invited
 * person whose link expired must not need to find an administrator). It runs
 * the same uniform-response discipline as the reset request: eligible or not,
 * the caller sees one answer at one floor (SEC-01 covers invites by name).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class InvitationIssuer
{
    use RunsWithinTenant;

    /**
     * FR-17: "7-day validity". A constant, not a setting — no document makes
     * it tunable, and an unread setting may not enter the registry.
     */
    public const VALIDITY_DAYS = 7;

    public function __construct(
        private readonly StaffLoginResolver $resolver,
        private readonly ActionTokenIssuer $tokens,
        private readonly PasswordLifecycleRecorder $recorder,
        private readonly SettingsRepository $settings,
        private readonly UniformResponseFloor $floor,
        private readonly TenantContext $context,
    ) {}

    /**
     * Mint (or re-mint — the issuer supersedes any live link, EC-04's physics)
     * an invitation for a known invited user. Call with or without a bound
     * tenant; the write binds the user's own.
     */
    public function issue(
        User $user,
        CarbonImmutable $now,
        Request $request,
        ?string $actorId = null,
        bool $resend = false,
    ): void {
        $this->withinTenant($user->tenantId(), function () use ($user, $now, $request, $actorId, $resend): void {
            $address = $user->getAttribute('email');
            $email = is_string($address) ? $address : '';

            $plaintext = $this->tokens->issue(
                $user->tenantId(),
                $user->identifier(),
                TokenPurpose::Invitation,
                $email,
                $now,
                $now->addDays(self::VALIDITY_DAYS),
                $request,
            );

            Mail::to($email)->send(
                (new InvitationMail(
                    inviteUrl: route('auth.invitation.form', ['token' => $plaintext]),
                    workspaceName: $this->workspaceName($user),
                    validDays: self::VALIDITY_DAYS,
                ))->locale($this->recipientLocale($user)),
            );

            $this->recorder->invitationIssued(
                $user->tenantId(),
                $user->identifier(),
                resend: $resend,
                request: $request,
                actorId: $actorId,
            );
        }, $this->context);
    }

    /**
     * EC-04: "self-service resend (rate-limited); old link invalidated."
     *
     * Anyone may POST an address here (the rate limit is the route's); only
     * addresses matching an INVITED account in an active tenant get a new
     * link, and the response never says which happened (SEC-01 names invites
     * explicitly). The old link dies by the one-live-per-purpose index.
     */
    public function resendForEmail(string $email, Request $request, CarbonImmutable $now): void
    {
        $this->floor->run(function () use ($email, $request, $now): void {
            foreach ($this->resolver->candidatesFor($email) as $candidate) {
                $invited = ! $candidate->passwordSet
                    && ! $candidate->disabled
                    && $candidate->tenantState === TenantState::Active;

                if (! $invited) {
                    continue;
                }

                $this->withinTenant($candidate->tenantId, function () use ($candidate, $request, $now): void {
                    $user = User::query()->find($candidate->userId);

                    if ($user !== null) {
                        $this->issue($user, $now, $request, resend: true);
                    }
                }, $this->context);
            }
        });
    }

    private function recipientLocale(User $user): string
    {
        $preference = $user->getAttribute('locale');

        if (is_string($preference) && $preference !== '') {
            return $preference;
        }

        $tenantDefault = $this->settings->get('tenant.default_language');

        return is_string($tenantDefault) && $tenantDefault !== '' ? $tenantDefault : 'en';
    }

    private function workspaceName(User $user): string
    {
        $name = $user->tenant()->first()?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : 'Support';
    }
}
