<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use App\Domain\Conversations\Adapters\InboundSender;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * THE Doc 08 resolver (FR-07/FR-08, T-02) — the platform's hot path: it runs
 * on EVERY inbound message (Doc 04 FR-10 step 3; NFR-01 ≤ 5 ms p99, the hit
 * path is one index-only exact match).
 *
 * The algorithm is two lines and both matter (FR-07):
 *   1. exact (type, value) match → return the customer (refresh display /
 *      username history if changed — FR-06);
 *   2. no match → create shell customer + identity (source=channel_auto),
 *      created: true.
 *
 * RACE SAFETY IS A DB PROPERTY (T-02): one INSERT … ON CONFLICT
 * (tenant_id, type, value) DO NOTHING upsert path — never a
 * check-then-insert, never an exception-driven retry. A lost race rolls the
 * orphan shell back with its savepoint and adopts the winner. AC-01's
 * concurrent-first-message fixture yields exactly ONE customer.
 *
 * THE SHARPEST PROHIBITION (BR-02/FR-07): the resolver NEVER links across
 * identity types. `extras.phone_hint` (a phone the customer typed in chat, a
 * sender-profile phone) is accepted and deliberately IGNORED — a wrong
 * silent link in a betting product misroutes money conversations.
 * Cross-type linking is always an explicit staff action (FR-09, step 5).
 * AC-01 proves the ABSENCE of the link; sabotage #4 teaches this class to
 * auto-link and must turn that fixture red.
 *
 * The resolver NEVER merges (FR-08) — it matches or creates, nothing else.
 *
 * DISPLAY IS CHANNEL PROVENANCE, NOT THE STAFF NAME (FR-01's derivation +
 * §14's "Telegram first+last concatenation is display-only", filed reading):
 * the channel's display/username live in the identity's metadata
 * (`display`, `username`, `history`); `customers.display_name` is the
 * STAFF-SET slot and this class never writes it on a hit — the render layer
 * derives: staff name → identity display → username → "Customer #short-id".
 * On CREATE the shell's display_name stays NULL (pure shell; A13's frame
 * draws exactly that: "Customer #8f21 — No name yet").
 *
 * Deprecation note: this class writes NEITHER customers.phone NOR
 * customers.username (both deprecated by SCHEMA AMENDMENT 2; the drop is
 * gated). The legacy channel/external_user_id identity columns are still
 * filled until their own contract-window drop.
 */
final class IdentityResolution
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
    ) {}

    public function resolveInbound(string $channel, InboundSender $sender): ResolutionResult
    {
        $type = ChannelIdentityMap::typeFor($channel);
        $value = $sender->externalUserId;

        $existing = CustomerIdentity::query()
            ->where('type', $type)
            ->where('value', $value)
            ->first();

        if ($existing instanceof CustomerIdentity) {
            $this->refreshChannelDisplay($existing, $sender);

            return new ResolutionResult($existing->customer_id, false);
        }

        return $this->createShell($channel, $type, $value, $sender);
    }

    /**
     * FR-06: the identity's metadata carries the channel display + username,
     * with {old, changed_at} history appended on a username change (Doc 05
     * EC-05's forward-rename; search matches the history at step 8). Runs
     * only when something actually changed — the hot path stays one SELECT.
     */
    private function refreshChannelDisplay(CustomerIdentity $identity, InboundSender $sender): void
    {
        $raw = $identity->getRawOriginal('metadata');
        /** @var array<string, mixed> $metadata */
        $metadata = (array) json_decode(is_string($raw) ? $raw : '{}', true);

        $dirty = false;
        $known = isset($metadata['username']) && is_string($metadata['username']) ? $metadata['username'] : null;

        if ($sender->username !== null && $sender->username !== $known) {
            if ($known !== null) {
                /** @var list<array{old: string, changed_at: string}> $history */
                $history = isset($metadata['history']) && is_array($metadata['history']) ? $metadata['history'] : [];
                $history[] = ['old' => $known, 'changed_at' => $this->dbNow()];
                $metadata['history'] = $history;
            }
            $metadata['username'] = $sender->username;
            $dirty = true;
        }

        $knownDisplay = isset($metadata['display']) && is_string($metadata['display']) ? $metadata['display'] : null;
        if ($sender->display !== $knownDisplay) {
            $metadata['display'] = $sender->display;
            $dirty = true;
        }

        if ($dirty) {
            DB::table('customer_identities')
                ->where('id', $identity->identifier())
                ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
        }
    }

    /**
     * The single upsert path (T-02). The savepoint takes the orphan shell
     * down when the ON CONFLICT arbiter says a concurrent first message won.
     */
    private function createShell(string $channel, string $type, string $value, InboundSender $sender): ResolutionResult
    {
        try {
            return DB::transaction(function () use ($channel, $type, $value, $sender): ResolutionResult {
                $customer = Customer::query()->create([
                    'is_shell' => true,
                    'created_source' => $type === 'telegram' ? 'telegram' : 'webchat',
                ]);

                $metadata = array_filter([
                    'display' => $sender->display,
                    'username' => $sender->username,
                ], static fn ($v): bool => $v !== null);

                /** @var object{id: string}|null $row */
                $row = DB::selectOne(
                    <<<'SQL'
                INSERT INTO customer_identities
                    (id, tenant_id, customer_id, channel, external_user_id,
                     type, value, source, first_seen_at, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'channel_auto', clock_timestamp(), ?, now(), now())
                ON CONFLICT (tenant_id, type, value) DO NOTHING
                RETURNING id
                SQL,
                    [
                        (string) new UuidV7,
                        $this->context->tenantId(),
                        $customer->identifier(),
                        $channel,
                        $value,
                        $type,
                        $value,
                        // display is non-nullable on InboundSender, so the map is
                        // never empty and always encodes as a JSON object.
                        json_encode($metadata),
                    ],
                );

                if ($row === null) {
                    // Concurrent first contact won the index. DB::transaction
                    // rolls back ONLY on a throw — a plain return would COMMIT
                    // the orphan shell — so the race loss is an exception by
                    // construction, caught one frame out.
                    throw new RaceLost;
                }

                // FR-22, create path only (the hot hit path publishes nothing).
                $this->events->publish('customer.created', [
                    'tenant_id' => $this->context->tenantId(),
                    'customer_id' => $customer->identifier(),
                    'created_source' => $type === 'telegram' ? 'telegram' : 'webchat',
                ]);
                $this->events->publish('customer.identity.linked', [
                    'tenant_id' => $this->context->tenantId(),
                    'customer_id' => $customer->identifier(),
                    'identity_id' => $row->id,
                    'type' => $type,
                    'source' => 'channel_auto',
                ]);

                return new ResolutionResult($customer->identifier(), true);
            });
        } catch (RaceLost) {
            // fall through to winner adoption
        }

        $winner = CustomerIdentity::query()
            ->where('type', $type)
            ->where('value', $value)
            ->first();

        if (! $winner instanceof CustomerIdentity) {
            throw new RuntimeException('ON CONFLICT arbiter fired but no winner row is visible — investigate RLS context.');
        }

        return new ResolutionResult($winner->customer_id, false);
    }

    /** History stamps come from the database clock, never PHP's (C-11 spirit). */
    private function dbNow(): string
    {
        /** @var object{now: string} $row */
        $row = DB::selectOne('SELECT clock_timestamp()::text AS now');

        return $row->now;
    }
}
