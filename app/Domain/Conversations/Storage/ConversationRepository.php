<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Storage;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Models\Conversation;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ONLY write path to conversations (Doc 04 T-01, Doc 01 R-05).
 *
 * Everything here is tenant-scoped through the model's global scope + RLS (N-01's two
 * layers); nothing accepts a tenant id (N-02).
 */
final class ConversationRepository
{
    public function __construct(private readonly AdapterRegistry $adapters) {}

    /**
     * FR-01: find or create THE conversation for {tenant, channel endpoint, external
     * chat}. Race-safe against the unique index — a concurrent first-contact loses
     * the insert and adopts the winner's row (FR-10 step 4's "create conversation if
     * first contact" happens inside the caller's transaction).
     *
     * The channel must be REGISTERED (the migration's docblock: registry-validated,
     * never a DDL enum — SS-02's zero-core-edits depends on this).
     */
    public function findOrCreateForInbound(
        string $channel,
        string $channelRefId,
        string $externalChatId,
        string $customerId,
    ): Conversation {
        if (! in_array($channel, $this->adapters->channels(), true)) {
            throw new DomainException("channel '{$channel}' is not registered (FR-09).");
        }

        $existing = Conversation::query()
            ->where('channel', $channel)
            ->where('channel_ref_id', $channelRefId)
            ->where('external_chat_id', $externalChatId)
            ->first();

        if ($existing instanceof Conversation) {
            return $existing;
        }

        try {
            // Nested transaction = savepoint: the caught violation below must not
            // poison the caller's enclosing persist transaction (Postgres aborts
            // the whole transaction on any error unless a savepoint contains it).
            return DB::transaction(fn (): Conversation => Conversation::query()->create([
                'channel' => $channel,
                'channel_ref_id' => $channelRefId,
                'external_chat_id' => $externalChatId,
                'customer_id' => $customerId,
                'state' => 'active',
            ]));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // The concurrent first-contact won the insert; adopt its row.
            $winner = Conversation::query()
                ->where('channel', $channel)
                ->where('channel_ref_id', $channelRefId)
                ->where('external_chat_id', $externalChatId)
                ->first();

            if (! $winner instanceof Conversation) {
                throw new RuntimeException('unique violation on conversation identity but no winner row visible — investigate RLS context.');
            }

            return $winner;
        }
    }

    /**
     * FR-04/BR-05: claim the one open-ticket slot ATOMICALLY. The UPDATE's WHERE is
     * the invariant — it succeeds only while the slot is empty, so two concurrent
     * opens serialize on the row and the loser gets `open_ticket_exists`. Doc 06 is
     * the caller; this session ships the mechanism and its test.
     */
    public function claimOpenTicket(Conversation $conversation, string $ticketId): void
    {
        $claimed = Conversation::query()
            ->whereKey($conversation->identifier())
            ->whereNull('open_ticket_id')
            ->update(['open_ticket_id' => $ticketId]);

        if ($claimed === 1) {
            $conversation->refresh();

            return;
        }

        $current = Conversation::query()->whereKey($conversation->identifier())->first();
        $existing = $current?->open_ticket_id;

        throw new OpenTicketExists(
            $conversation->identifier(),
            is_string($existing) ? $existing : 'unknown',
        );
    }

    /**
     * Release the slot — only for the ticket that holds it (a stale release from a
     * ticket that already lost the slot must be a no-op refusal, not a clobber).
     */
    public function releaseOpenTicket(Conversation $conversation, string $ticketId): bool
    {
        $released = Conversation::query()
            ->whereKey($conversation->identifier())
            ->where('open_ticket_id', $ticketId)
            ->update(['open_ticket_id' => null]);

        $conversation->refresh();

        return $released === 1;
    }

    /** FR-03: blocked stops outbound and quarantines inbound. State only — the
     *  conversation.blocked event + audit ride the block ACTION (step 8's wiring). */
    public function block(Conversation $conversation): void
    {
        Conversation::query()->whereKey($conversation->identifier())->update(['state' => 'blocked']);
        $conversation->refresh();
    }

    public function unblock(Conversation $conversation): void
    {
        Conversation::query()->whereKey($conversation->identifier())->update(['state' => 'active']);
        $conversation->refresh();
    }

    /** Recency rollups (FR-02) — stamped in DB time (C-11), never PHP now(). */
    public function touchCustomerActivity(Conversation $conversation): void
    {
        Conversation::query()->whereKey($conversation->identifier())
            ->update(['last_customer_message_at' => DB::raw('now()')]);
        $conversation->refresh();
    }

    public function touchOutboundActivity(Conversation $conversation): void
    {
        Conversation::query()->whereKey($conversation->identifier())
            ->update(['last_outbound_at' => DB::raw('now()')]);
        $conversation->refresh();
    }
}
