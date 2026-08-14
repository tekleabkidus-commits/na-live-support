<?php

declare(strict_types=1);

namespace App\Jobs\Customers;

use App\Domain\Conversations\Actions\ConversationBlocking;
use App\Jobs\OutboxConsumer;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-18/T-06's cascade, DURABLE (ADR 0011; contract fixture
 * doc08-events.json): customer.blocked quiets every conversation through
 * Doc 04's own ConversationBlocking door (consumed, never re-implemented);
 * customer.unblocked restores EXACTLY the conversations THIS cascade blocked
 * — the customer_block_cascade_items memory (amendment addition 7), so a
 * conversation blocked individually before the customer block SURVIVES the
 * unblock (FR-18's own last sentence).
 *
 * IDEMPOTENT ON event_id (C-09) BY STATE AND BY KEY: the block half skips
 * already-blocked conversations and the cascade-item unique index refuses a
 * double record — a re-block RE-ARMS the existing marker (ON CONFLICT DO
 * UPDATE clears restored_at) instead of duplicating it, and that RETURNING
 * always yields exactly one row: the conflict target CONTAINS tenant_id, so a
 * conflicting row is by definition this tenant's own (RLS cannot hide it from
 * the DO UPDATE), and a conflict on any OTHER constraint throws rather than
 * returning empty. The unblock half touches only
 * unrestored items whose conversation is still blocked. A crash mid-cascade
 * leaves the OUTBOUND GUARD holding (FS-04) and the re-driven job completes
 * the remainder — the repair IS the redelivery.
 *
 * The CURRENT customer row decides (values from truth, never payloads): a
 * redelivered customer.blocked for a since-unblocked customer does nothing,
 * and vice versa.
 */
final class BlockingCascadeConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(ConversationBlocking $blocking): void
    {
        /** @var object{id: string, tenant_id: string, name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null) {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($event->payload, true);
        $customerId = $payload['customer_id'] ?? null;
        if (! is_string($customerId)) {
            return;
        }

        /** @var object{blocked: bool, blocked_reason: ?string}|null $customer */
        $customer = DB::table('customers')->where('id', $customerId)->first(['blocked', 'blocked_reason']);
        if ($customer === null) {
            return;
        }

        if ($event->name === 'customer.blocked' && $customer->blocked) {
            $this->cascadeBlock($blocking, $event->tenant_id, $customerId, $customer->blocked_reason ?? '');
        }

        if ($event->name === 'customer.unblocked' && ! $customer->blocked) {
            $this->cascadeRestore($blocking, $customerId);
        }
    }

    private function cascadeBlock(ConversationBlocking $blocking, string $tenantId, string $customerId, string $reason): void
    {
        /** @var list<string> $activeIds */
        $activeIds = DB::table('conversations')
            ->where('customer_id', $customerId)
            ->where('state', '!=', 'blocked')
            ->pluck('id')
            ->all();

        foreach ($activeIds as $conversationId) {
            // The marker RE-ARMS on a re-block (the step-7 acceptance's owed
            // cycle): a row left stamped by a prior unblock would make this
            // cycle's restore find nothing and leave the conversation blocked
            // forever — so the upsert CLEARS restored_at, and the raced case
            // below withdraws the claim again.
            /** @var object{id: string} $recorded */
            $recorded = DB::selectOne(
                <<<'SQL'
                INSERT INTO customer_block_cascade_items (id, tenant_id, customer_id, conversation_id)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (tenant_id, customer_id, conversation_id) DO UPDATE SET restored_at = NULL
                RETURNING id
                SQL,
                [(string) new UuidV7, $tenantId, $customerId, $conversationId],
            );

            /** @var Conversation $conversation */
            $conversation = Conversation::query()->findOrFail($conversationId);
            if ($conversation->state !== 'blocked') {
                $blocking->block($conversation, null, __('customers.block.cascade_reason', ['reason' => $reason]));
            } else {
                // Raced: someone blocked it between our list and our act — the
                // cascade did NOT block this one; withdraw the claim.
                DB::table('customer_block_cascade_items')->where('id', $recorded->id)
                    ->update(['restored_at' => DB::raw('clock_timestamp()')]);
            }
        }
    }

    private function cascadeRestore(ConversationBlocking $blocking, string $customerId): void
    {
        /** @var list<object{id: string, conversation_id: string}> $items */
        $items = DB::table('customer_block_cascade_items')
            ->where('customer_id', $customerId)
            ->whereNull('restored_at')
            ->get(['id', 'conversation_id'])
            ->all();

        foreach ($items as $item) {
            /** @var Conversation $conversation */
            $conversation = Conversation::query()->findOrFail($item->conversation_id);
            if ($conversation->state === 'blocked') {
                $blocking->unblock($conversation, null, __('customers.block.cascade_restore_reason'));
            }
            DB::table('customer_block_cascade_items')->where('id', $item->id)
                ->update(['restored_at' => DB::raw('clock_timestamp()')]);
        }
    }
}
