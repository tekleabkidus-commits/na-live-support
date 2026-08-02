<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every password-lifecycle outcome leaves a record (Doc 02 §4.8; Doc 17 FR-02).
 *
 * Same two sinks and same ordering as LoginRecorder, for the same reasons:
 * AUDIT gets the operational detail (IP, origin), synchronously and unwrapped,
 * so it survives whatever else fails; EVENTS get identifiers only, published in
 * their own small transaction AFTER the audit row is durable — a bus failure
 * can lose an event but never the record.
 *
 * §4.8 names exactly three password events — auth.password.changed,
 * reset_requested, reset_completed — and this class publishes no others. An
 * invitation acceptance publishes auth.password.changed (a password DID get
 * set; that is the fact consumers care about) while its audit entry keeps the
 * more precise auth.invite.accepted, because the trail answers "what happened
 * to this account" and the bus answers "what should react".
 */
final class PasswordLifecycleRecorder
{
    use RunsWithinTenant;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
        private readonly TenantContext $context,
    ) {}

    /**
     * FR-16: one reset request produced a token for THIS account. Recorded per
     * candidate, in that candidate's tenant — an address matching accounts in
     * three tenants leaves three trails, each visible to its own operator.
     */
    public function resetRequested(string $tenantId, string $userId, Request $request): void
    {
        $this->withinTenant($tenantId, function () use ($tenantId, $userId, $request): void {
            $this->audit->record(
                actionKey: 'auth.password.reset_requested',
                entity: 'user',
                entityId: $userId,
                actorType: AuditActor::Staff,
                payload: ['ip' => $request->ip() ?? 'unknown'],
                actorId: $userId,
                tenantId: $tenantId,
            );

            $this->publishOutsideTheAuditWrite('auth.password.reset_requested', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
        }, $this->context);
    }

    /**
     * A password was actually applied, through whichever door.
     */
    public function passwordApplied(User $user, PasswordChangeOrigin $origin, Request $request): void
    {
        $actionKey = match ($origin) {
            PasswordChangeOrigin::Reset => 'auth.password.reset_completed',
            PasswordChangeOrigin::SelfChange => 'auth.password.changed',
            PasswordChangeOrigin::InvitationAccept => 'auth.invite.accepted',
        };

        $eventName = match ($origin) {
            PasswordChangeOrigin::Reset => 'auth.password.reset_completed',
            // §4.8 has no invite event; the set-password fact IS a change.
            PasswordChangeOrigin::SelfChange,
            PasswordChangeOrigin::InvitationAccept => 'auth.password.changed',
        };

        $this->withinTenant($user->tenantId(), function () use ($user, $origin, $request, $actionKey, $eventName): void {
            $this->audit->record(
                actionKey: $actionKey,
                entity: 'user',
                entityId: $user->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'origin' => $origin->value,
                    'ip' => $request->ip() ?? 'unknown',
                ],
                actorId: $user->identifier(),
                tenantId: $user->tenantId(),
            );

            $this->publishOutsideTheAuditWrite($eventName, [
                'tenant_id' => $user->tenantId(),
                'user_id' => $user->identifier(),
                'origin' => $origin->value,
            ]);
        }, $this->context);
    }

    /**
     * FR-17/EC-04: an invitation link was minted or re-minted. Audit only — no
     * §4.8 event exists for invites, and inventing one is not this session's
     * call to make.
     */
    public function invitationIssued(string $tenantId, string $userId, bool $resend, Request $request, ?string $actorId = null): void
    {
        $this->withinTenant($tenantId, function () use ($tenantId, $userId, $resend, $request, $actorId): void {
            $this->audit->record(
                actionKey: $resend ? 'auth.invite.resent' : 'auth.invite.issued',
                entity: 'user',
                entityId: $userId,
                actorType: $actorId === null ? AuditActor::System : AuditActor::Staff,
                payload: ['ip' => $request->ip() ?? 'unknown'],
                actorId: $actorId,
                tenantId: $tenantId,
            );
        }, $this->context);
    }

    /**
     * Audit row first and unwrapped, then the event in its own transaction —
     * see LoginRecorder::publishOutsideTheAuditWrite for the two rules that
     * meet here and why this ordering satisfies both.
     *
     * @param  array<string, mixed>  $payload
     */
    private function publishOutsideTheAuditWrite(string $name, array $payload): void
    {
        DB::transaction(fn () => $this->events->publish($name, $payload));
    }
}
