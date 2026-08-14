<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-09's staff link: add a phone or email by hand, normalized on save,
 * provenance recorded (source=staff — the B13 subject-source idiom renders
 * "added by Sara, Jul 12" from these columns).
 *
 * Phones normalize ONCE at write via ADR 0014's wrapper (E.164 stored;
 * display derives national + flag from the stored value); emails lowercase
 * (FR-04). BR-04's block: a value already on ANOTHER customer throws
 * DuplicateIdentity naming that customer (the merge-instead offer);
 * the SAME value on the SAME customer is the honest no-change the door
 * declares as an A-37 causal noop (null return).
 *
 * Cross-type linking stays a HUMAN act by construction — this class IS the
 * explicit staff action BR-02 demands; nothing calls it automatically.
 */
final class LinkIdentity
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $type  'phone' or 'email' — the staff-linkable types
     *                        (channel-minted identities come from their
     *                        channels); anything else is refused at runtime
     * @return string|null the identity id; null = no-change (already linked here)
     *
     * @throws DuplicateIdentity when the value belongs to another customer
     */
    public function link(string $customerId, string $type, string $rawValue, string $actorId, string $regionHint = 'ET'): ?string
    {
        $value = match ($type) {
            'phone' => PhoneNumber::toE164($rawValue, $regionHint),
            'email' => mb_strtolower(trim($rawValue)),
            default => throw new InvalidArgumentException("staff linking adds phone or email — '{$type}' identities are minted by their channel (FR-09)"),
        };

        if ($value === '') {
            throw new InvalidArgumentException('an identity value cannot be empty');
        }

        /** @var object{id: string, customer_id: string}|null $existing */
        $existing = DB::table('customer_identities')
            ->where('type', $type)->where('value', $value)
            ->first(['id', 'customer_id']);

        if ($existing !== null) {
            if ($existing->customer_id === $customerId) {
                return null;    // the A-37 noop fact — the door declares it causally
            }

            throw new DuplicateIdentity($existing->customer_id, $type);
        }

        try {
            return DB::transaction(function () use ($customerId, $type, $value, $actorId): string {
                $identityId = (string) new UuidV7;

                // BR-04 stays a DB property even against a racing link: the same
                // ON CONFLICT arbiter the resolver rides.
                /** @var object{id: string}|null $row */
                $row = DB::selectOne(
                    <<<'SQL'
                INSERT INTO customer_identities
                    (id, tenant_id, customer_id, channel, external_user_id,
                     type, value, source, first_seen_at, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'staff', clock_timestamp(), '{}', now(), now())
                ON CONFLICT (tenant_id, type, value) DO NOTHING
                RETURNING id
                SQL,
                    [$identityId, $this->context->tenantId(), $customerId, $type, $value, $type, $value],
                );

                if ($row === null) {
                    /** @var object{customer_id: string}|null $winner */
                    $winner = DB::table('customer_identities')->where('type', $type)->where('value', $value)->first(['customer_id']);
                    if ($winner !== null && $winner->customer_id === $customerId) {
                        throw new RaceLost;     // rolled back; treated as no-change one frame up
                    }

                    throw new DuplicateIdentity($winner->customer_id ?? 'unknown', $type);
                }

                $this->events->publish('customer.identity.linked', [
                    'tenant_id' => $this->context->tenantId(),
                    'customer_id' => $customerId,
                    'identity_id' => $identityId,
                    'type' => $type,
                    'source' => 'staff',
                ]);

                $this->audit->record('customer.identity.linked', 'customer', $customerId, AuditActor::Staff, ['type' => $type], actorId: $actorId);

                return $identityId;
            });
        } catch (RaceLost) {
            return null;    // a concurrent link of the SAME value to the SAME customer won
        }
    }
}
