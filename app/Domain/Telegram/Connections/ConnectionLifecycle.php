<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Connections;

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The business-connection lifecycle (Doc 05 FR-07…10, SEC-04, EC-01/02/03/05/06/11,
 * FS-04). Update-driven truth: states change ONLY from business_connection
 * updates (FR-09) except the admin confirm/reject/disconnect actions.
 *
 * States: pending → active → paused ↔ active, and any → revoked.
 *  - pending  a fresh connection AWAITS admin confirmation (SEC-04); it NEVER
 *             routes (the routing guard reads active-only) and alerts after
 *             10 min.
 *  - active   admin-confirmed "this is our account"; routes.
 *  - paused   can_reply=false (EC-03, redo step 2) or owner-side pause;
 *             inbound still flows, outbound frozen.
 *  - revoked  owner disconnected / is_enabled=false (FR-10): channel_unreachable
 *             + outbound lane freeze + rescue data.
 *
 * EC-11: upsert by connection_id — the THREE real captured updates (one
 * connection, widening rights) collapse to ONE row, and a redelivery whose
 * state is unchanged emits NO event (idempotent).
 * EC-02: two admins racing the connect converge on that one row.
 * FS-04: >3 state transitions/hour collapse notifications into a digest;
 * the STATE stays truthful (the transition is still applied and recorded).
 */
final class ConnectionLifecycle
{
    private const FLAP_WINDOW_SECONDS = 3600;

    private const FLAP_THRESHOLD = 3;

    public function __construct(
        private readonly EventPublisher $events,
        private readonly TenantContext $context,
        private readonly RedisFactory $redis,
        private readonly ChannelUnreachableWriter $unreachable,
    ) {}

    /**
     * Apply a business_connection update (FR-07/FR-09). Returns the resolved state.
     *
     * @param  array<string, mixed>  $connection  the update's business_connection object
     */
    public function applyUpdate(string $botId, array $connection): string
    {
        $connectionId = is_string($connection['id'] ?? null) ? $connection['id'] : '';
        /** @var array<string, mixed> $user */
        $user = is_array($connection['user'] ?? null) ? $connection['user'] : [];
        $accountUserId = is_int($user['id'] ?? null) ? $user['id'] : 0;
        $isEnabled = ($connection['is_enabled'] ?? true) === true;
        $canReply = ($connection['can_reply'] ?? true) === true;

        if ($connectionId === '' || $accountUserId === 0) {
            return 'ignored';
        }

        $accountName = $this->accountName($user);
        $rights = is_array($connection['rights'] ?? null) ? $connection['rights'] : [];

        return DB::transaction(function () use ($botId, $connectionId, $accountUserId, $accountName, $rights, $isEnabled, $canReply): string {
            $existing = DB::table('telegram_business_connections')
                ->where('bot_id', $botId)
                ->where('connection_id', $connectionId)
                ->lockForUpdate()
                ->first();

            // EC-11: the target state, derived purely from the update.
            // A connection that was EVER confirmed (state active or paused)
            // resumes to active on can_reply=true — never back to pending.
            // Pending is only the never-confirmed initial state (SEC-04).
            $everConfirmed = $existing !== null && in_array($existing->state, ['active', 'paused'], true);
            $targetState = ! $isEnabled
                ? 'revoked'
                : (! $canReply ? 'paused' : ($everConfirmed ? 'active' : 'pending'));

            if ($existing === null) {
                DB::table('telegram_business_connections')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $this->context->tenantId(),
                    'bot_id' => $botId,
                    'connection_id' => $connectionId,
                    'account_user_id' => $accountUserId,
                    'account_name' => $accountName,
                    'account_username' => $accountName,
                    'user_chat_id' => $accountUserId,
                    'state' => $targetState,
                    'can_reply' => $canReply,
                    'rights' => json_encode($rights),
                ]);
                $priorState = null;
            } else {
                DB::table('telegram_business_connections')
                    ->where('id', $existing->id)
                    ->update([
                        'account_name' => $accountName, // EC-05 forward-rename
                        'can_reply' => $canReply,
                        'rights' => json_encode($rights),
                        'state' => $targetState,
                        'updated_at' => DB::raw('clock_timestamp()'),
                    ]);
                $priorState = $existing->state;
            }

            // EC-11 idempotence: no transition, no event.
            if ($priorState === $targetState) {
                return $targetState;
            }

            $this->onTransition($botId, $connectionId, $accountUserId, $targetState);

            return $targetState;
        });
    }

    /** FR-07 step 3: the admin confirms "this is our account" — pending → active (SEC-04). */
    public function confirm(string $connectionId, string $adminId): void
    {
        $row = DB::table('telegram_business_connections')->where('connection_id', $connectionId)->first();
        if ($row === null || $row->state !== 'pending') {
            return;
        }

        DB::transaction(function () use ($row, $adminId, $connectionId): void {
            DB::table('telegram_business_connections')->where('id', $row->id)->update([
                'state' => 'active',
                'confirmed_by' => $adminId,
                'confirmed_at' => DB::raw('clock_timestamp()'),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $this->onTransition(is_scalar($row->bot_id) ? (string) $row->bot_id : '', $connectionId, is_numeric($row->account_user_id) ? (int) $row->account_user_id : 0, 'active');
        });
    }

    /** EC-01: the admin rejects a wrong account — the connection row is deleted, the checklist restarts. */
    public function reject(string $connectionId): void
    {
        DB::table('telegram_business_connections')
            ->where('connection_id', $connectionId)
            ->where('state', 'pending')
            ->delete();
    }

    private function onTransition(string $botId, string $connectionId, int $accountUserId, string $state): void
    {
        if (in_array($state, ['active', 'paused', 'revoked'], true)) {
            $this->events->publish('telegram.connection.'.$state, [
                'tenant_id' => $this->context->tenantId(),
                'bot_id' => $botId,
                'connection_id' => $connectionId,
                'account_user_id' => $accountUserId,
            ]);
        }

        if ($state === 'revoked') {
            // FR-10: flag open tickets, freeze outbound, prepare rescue data.
            $this->unreachable->onRevoked($botId, $connectionId);
        }

        // Notify on paused|revoked (FR-09) — unless FS-04 damping folds it.
        if (in_array($state, ['paused', 'revoked'], true)) {
            $this->notifyOrDigest($connectionId, $state);
        }
    }

    /** FS-04: >3 transitions/hour → one digest; the state was already applied truthfully. */
    private function notifyOrDigest(string $connectionId, string $state): void
    {
        $key = sprintf('t:%s:tg-flap:%s', $this->context->tenantId(), $connectionId);
        $connection = $this->redis->connection('volatile');
        $count = $connection->command('incr', [$key]);
        if ($count === 1) {
            $connection->command('expire', [$key, self::FLAP_WINDOW_SECONDS]);
        }

        $damped = is_numeric($count) && (int) $count > self::FLAP_THRESHOLD;

        $this->events->publish('telegram.connection.notified', [
            'tenant_id' => $this->context->tenantId(),
            'connection_id' => $connectionId,
            'state' => $state,
            'delivery' => $damped ? 'digest' : 'immediate',
        ]);
    }

    /** @param array<string, mixed> $user */
    private function accountName(array $user): string
    {
        $name = trim(implode(' ', array_filter([
            is_string($user['first_name'] ?? null) ? $user['first_name'] : null,
            is_string($user['last_name'] ?? null) ? $user['last_name'] : null,
        ])));

        return $name !== '' ? $name : (is_string($user['username'] ?? null) ? $user['username'] : 'Account');
    }
}
