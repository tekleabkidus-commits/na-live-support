<?php

declare(strict_types=1);

namespace App\Domain\Auth\TwoFactor;

use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA enrolment: begin → confirm → recovery batch (Doc 02 FR-06, EC-10, SEC-03).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SECRET TRAVELS WITH ITS ENROLMENT ID. `two_factor_secret` stores an
 * encrypted JSON envelope {secret, enrolment_id}, not a bare secret, because
 * EC-10's failure is precisely two tabs holding two QRs: begin() in tab B
 * replaces tab A's envelope, and tab A's confirm — carrying A's enrolment_id —
 * is refused with a DESIGNED error rather than the indistinguishable "code
 * didn't work". Single-secret-wins, and the loser is told so.
 *
 * CONFIRMED IS A TIMESTAMP, NOT THE PRESENCE OF A SECRET. The pre-tenant door
 * (`resolve_staff_login`) computes two_factor_enabled from
 * two_factor_confirmed_at IS NOT NULL, so an abandoned begin() — secret stored,
 * never confirmed — changes nothing about login. FR-06's "require one valid
 * code to activate" is what stands between a mis-scanned QR and an account
 * whose owner can never log in again.
 *
 * DISCLOSURE DISCIPLINE (SEC-03): recovery-code plaintext exists in exactly one
 * place — confirm()'s return value — and dies with the response that carries
 * it. This class never logs codes or secrets (SEC-08).
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TwoFactorEnrolment
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly RecoveryCodeStore $recoveryCodes,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
        private readonly TenantContext $context,
        private readonly TotpVerifier $verifier,
    ) {}

    /**
     * FR-06 step 1: mint a pending secret and hand back what the wizard shows.
     *
     * @return array{secret: string, otpauth_uri: string, qr_svg: string, enrolment_id: string}
     */
    public function begin(User $user, string $issuer): array
    {
        // An ACTIVE enrolment cannot be begun over: begin() stores a new secret
        // and voids the confirmation, so allowing it here would let anyone
        // holding the session switch 2FA off without ever presenting a code.
        // The sanctioned paths out of an active enrolment both demand proof:
        // disable() (current code) or FR-08's admin reset.
        if ($user->getAttribute('two_factor_confirmed_at') !== null) {
            throw new TwoFactorAlreadyEnabled;
        }

        $secret = $this->google2fa->generateSecretKey();
        $enrolmentId = (string) Str::uuid7();

        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString((string) json_encode([
                'secret' => $secret,
                'enrolment_id' => $enrolmentId,
            ])),
            // A re-scan starts over: any previous confirmation is void the
            // moment a new secret exists, or the QR on screen and the check at
            // login would disagree about which secret is real.
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->record(
            actionKey: 'auth.2fa.enrolment_started',
            entity: 'user',
            entityId: $user->identifier(),
            actorType: AuditActor::Staff,
            payload: ['enrolment_id' => $enrolmentId],
            actorId: $user->identifier(),
        );

        $otpauthUri = $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);

        return [
            'secret' => $secret,
            'otpauth_uri' => $otpauthUri,
            'qr_svg' => $this->qrSvg($otpauthUri),
            'enrolment_id' => $enrolmentId,
        ];
    }

    /**
     * FR-06 steps 2–3: one valid code activates; eight recovery codes are
     * minted and returned — the only time their plaintext exists.
     *
     * @return list<string> the recovery codes
     *
     * @throws EnrolmentSuperseded when $enrolmentId is not the live one (EC-10)
     * @throws InvalidEnrolmentCode when the code does not verify
     */
    public function confirm(User $user, string $code, string $enrolmentId, CarbonImmutable $now): array
    {
        $envelope = $this->envelope($user);

        if ($envelope === null || ! hash_equals($envelope['enrolment_id'], $enrolmentId)) {
            throw new EnrolmentSuperseded;
        }

        if (! $this->verifier->verify($envelope['secret'], $code, $now)) {
            throw new InvalidEnrolmentCode;
        }

        $user->forceFill(['two_factor_confirmed_at' => $now])->save();

        $codes = $this->recoveryCodes->generate($user, $now);

        $this->audit->record(
            actionKey: 'auth.2fa.enabled',
            entity: 'user',
            entityId: $user->identifier(),
            actorType: AuditActor::Staff,
            payload: ['enrolment_id' => $enrolmentId],
            actorId: $user->identifier(),
        );

        // Audit first and unwrapped, publish in its own small transaction (the
        // LoginRecorder pattern): a bus failure may lose an EVENT, never the
        // RECORD.
        DB::transaction(fn (): string => $this->events->publish('auth.2fa.enabled', [
            'tenant_id' => $this->context->tenantId(),
            'user_id' => $user->identifier(),
        ]));

        return $codes;
    }

    /**
     * The secret the challenge verifies against; null until begin() has run.
     */
    public function secretFor(User $user): ?string
    {
        return $this->envelope($user)['secret'] ?? null;
    }

    /**
     * FR-07's banner path: mint a fresh batch of eight, superseding the old.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, CarbonImmutable $now): array
    {
        $codes = $this->recoveryCodes->generate($user, $now);

        $this->audit->record(
            actionKey: 'auth.2fa.codes_regenerated',
            entity: 'user',
            entityId: $user->identifier(),
            actorType: AuditActor::Staff,
            payload: ['count' => count($codes)],
            actorId: $user->identifier(),
        );

        return $codes;
    }

    /**
     * §4.8's auth.2fa.disabled: the user turns their own 2FA off — the caller
     * has already demanded a current code before reaching this line.
     */
    public function disable(User $user, CarbonImmutable $now): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->record(
            actionKey: 'auth.2fa.disabled',
            entity: 'user',
            entityId: $user->identifier(),
            actorType: AuditActor::Staff,
            payload: [],
            actorId: $user->identifier(),
        );

        DB::transaction(fn (): string => $this->events->publish('auth.2fa.disabled', [
            'tenant_id' => $this->context->tenantId(),
            'user_id' => $user->identifier(),
        ]));
    }

    /**
     * FR-08: an admin clears a user's 2FA; the user re-enrols at next login.
     * The typed-name confirmation is the CALLER's gate — by the time this
     * runs, the decision is made and this only executes and records it.
     */
    public function adminReset(User $target, User $admin, CarbonImmutable $now): void
    {
        $target->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->record(
            actionKey: 'auth.2fa.reset_by_admin',
            entity: 'user',
            entityId: $target->identifier(),
            actorType: AuditActor::Staff,
            payload: ['admin_id' => $admin->identifier()],
            actorId: $admin->identifier(),
        );

        DB::transaction(fn (): string => $this->events->publish('auth.2fa.reset_by_admin', [
            'tenant_id' => $this->context->tenantId(),
            'user_id' => $target->identifier(),
            'admin_id' => $admin->identifier(),
        ]));
    }

    private function qrSvg(string $otpauthUri): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($otpauthUri);
    }

    /**
     * @return array{secret: string, enrolment_id: string}|null
     */
    private function envelope(User $user): ?array
    {
        $stored = $user->getAttribute('two_factor_secret');

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $decoded = json_decode(Crypt::decryptString($stored), associative: true);

        if (! is_array($decoded)
            || ! is_string($decoded['secret'] ?? null)
            || ! is_string($decoded['enrolment_id'] ?? null)) {
            return null;
        }

        return ['secret' => $decoded['secret'], 'enrolment_id' => $decoded['enrolment_id']];
    }
}
