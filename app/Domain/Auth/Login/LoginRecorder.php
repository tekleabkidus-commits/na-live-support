<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login;

use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every authentication outcome leaves a record (Doc 02 FR-04, §4.8; Doc 17 FR-02).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO SINKS, DELIBERATELY DIFFERENT, AND THE RULE FOR WHAT GOES WHERE.
 *
 *   AUDIT (this tenant's append-only trail) gets the operational detail an
 *   operator's security officer needs to answer "who logged in, from where"
 *   (US-06): the IP, the device, the failure reason. It is tenant-scoped,
 *   access-controlled, and append-only by database grant (N-13).
 *
 *   EVENTS (the bus) get IDENTIFIERS ONLY. A bus fans out to consumers with
 *   different retention and access rules, so an address or a raw IP crossing it
 *   is a leak waiting for its first subscriber. Anything a consumer needs beyond
 *   an id, it fetches by id under its own authorisation.
 *
 * WHY THE AUDIT WRITE IS SYNCHRONOUS (owner ruling, Doc 17 FR-02). Auth is the
 * one class of event written straight to the audit table rather than published
 * through the outbox and relayed. The reason is failure behaviour: FS-01 has
 * staff auth fail CLOSED when infrastructure is degraded, and an audit trail
 * that depended on a working relay would go silent during precisely the incident
 * it exists to reconstruct. AuditLogger's insert shares schema and code with the
 * future outbox consumer, so Doc 17 inherits this rather than reworking it.
 *
 * WHY A FAILED LOGIN AGAINST AN UNKNOWN ADDRESS PUBLISHES NO EVENT. There is no
 * tenant to publish it under — that is not an omission but the honest shape of
 * the fact: nobody's workspace was attacked, because no such account exists. It
 * is counted as a platform security metric instead, which is where "someone is
 * spraying addresses at the login form" is actually visible.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class LoginRecorder
{
    use RunsWithinTenant;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
        private readonly TenantContext $context,
    ) {}

    public function succeeded(User $user, Request $request, string $deviceId, bool $newDevice): void
    {
        $this->audit->record(
            actionKey: 'auth.login.succeeded',
            entity: 'user',
            entityId: $user->identifier(),
            actorType: AuditActor::Staff,
            payload: [
                'ip' => $this->ip($request),
                'device_id' => $deviceId,
                'new_device' => $newDevice,
            ],
            actorId: $user->identifier(),
        );

        $this->publishOutsideTheAuditWrite('auth.login.succeeded', [
            'tenant_id' => $this->context->tenantId(),
            'user_id' => $user->identifier(),
            'device_id' => $deviceId,
            'new_device' => $newDevice,
        ]);
    }

    /**
     * A failure that IDENTIFIED an account — wrong password on a real user,
     * a locked account, a disabled user, a suspended tenant.
     */
    public function failedForKnownAccount(
        StaffLoginCandidate $candidate,
        FailureReason $reason,
        Request $request,
    ): void {
        // BIND THE CANDIDATE'S TENANT FOR THE WRITE. A failed login binds no
        // tenant — resolving one is what SUCCESS does — but the audit row and the
        // outbox row both live in that account's tenant, behind RLS. Without
        // this the insert fails on `app.tenant_id` being unset, and (worse) it
        // aborts the surrounding transaction so every later statement fails with
        // a misleading "transaction is aborted". See RunsWithinTenant.
        $this->withinTenant($candidate->tenantId, function () use ($candidate, $reason, $request): void {
            $this->audit->record(
                actionKey: 'auth.login.failed',
                entity: 'user',
                entityId: $candidate->userId,
                // 'staff', not 'system': a real principal attempted this, even
                // though the attempt failed. Attributing it to the system would
                // hide a targeted attack inside background noise.
                actorType: AuditActor::Staff,
                payload: [
                    'reason' => $reason->value,
                    'ip' => $this->ip($request),
                ],
                actorId: $candidate->userId,
                tenantId: $candidate->tenantId,
            );

            $this->publishOutsideTheAuditWrite('auth.login.failed', [
                'tenant_id' => $candidate->tenantId,
                'user_id' => $candidate->userId,
                'reason' => $reason->value,
                'ip_hash' => $this->hashIp($request),
            ]);
        }, $this->context);
    }

    /**
     * A failure against an address that matches NO account.
     *
     * Deliberately produces no audit entry and no event: both are tenant-scoped,
     * and there is no tenant. The metric is the correct home for it — see the
     * class docblock.
     */
    public function failedForUnknownAccount(Request $request): void
    {
        logger()->info('Staff login attempt for an unknown address', [
            'reason' => FailureReason::BadCredentials->value,
            // Hashed, not raw: this line goes to the platform log, which is not
            // tenant-scoped (SEC-08).
            'ip_hash' => $this->hashIp($request),
        ]);
    }

    public function loggedOut(User $user, Request $request): void
    {
        $this->audit->record(
            actionKey: 'auth.logout',
            entity: 'user',
            entityId: $user->identifier(),
            actorType: AuditActor::Staff,
            payload: ['ip' => (string) $request->ip()],
            actorId: $user->identifier(),
        );

        $this->publishOutsideTheAuditWrite('auth.logout', [
            'tenant_id' => $this->context->tenantId(),
            'user_id' => $user->identifier(),
        ]);
    }

    /**
     * Publish the event in its OWN transaction, after the audit row is already
     * durable.
     *
     * Two rules meet here and the ordering is what satisfies both. The outbox
     * invariant (Doc 01 T-03) refuses a publish outside a transaction, because
     * an outbox row must commit with the write it describes. The auth-audit
     * ruling (Doc 17 FR-02) requires the audit entry to be written
     * synchronously, with no surrounding transaction, so that it survives
     * whatever else fails.
     *
     * Writing the audit row first and unwrapped, then publishing inside a small
     * transaction of its own, means a failure in the bus can lose an EVENT but
     * can never lose the RECORD. The reverse order — one transaction around both
     * — would roll the audit entry back with the event, which is the outcome the
     * ruling exists to prevent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function publishOutsideTheAuditWrite(string $name, array $payload): void
    {
        DB::transaction(fn () => $this->events->publish($name, $payload));
    }

    /**
     * Salted with the app key, so the same source hashes consistently within a
     * deployment (correlation works) but the hashes are useless outside it and
     * cannot be reversed with a rainbow table of the IPv4 space.
     */
    private function hashIp(Request $request): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', $this->ip($request), is_string($key) ? $key : '');
    }

    /**
     * Request::ip() is nullable — a console-dispatched or malformed request has
     * none. Recording the empty string would put a plausible-looking blank in
     * the audit trail; 'unknown' says what actually happened.
     */
    private function ip(Request $request): string
    {
        return $request->ip() ?? 'unknown';
    }
}
