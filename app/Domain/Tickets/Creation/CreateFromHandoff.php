<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Creation;

use App\Models\Conversation;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-11 — THE SEAM Doc 09 CALLS: `createFromHandoff(conversation, packet)`.
 *
 * "AI handoff (primary): Doc 09 calls createFromHandoff(conversation, packet) … the
 * packet is pinned to the ticket as an immutable snapshot and rendered by Doc 07."
 *
 * WHAT IS BUILT HERE AND WHAT IS NOT. The seam, the contract, the pinning and the
 * immutability are this session's and are proven against a Doc 09 FIXTURE. The live
 * CALLER — real AI deciding to hand off — is Doc 09's, and the packet's RENDERING is
 * Doc 07's; both are the pre-agreed deferral. So this class is complete in itself and
 * has no consumer yet, which is the normal shape of a seam and not an omission.
 *
 * THE SUGGESTION IS A SUGGESTION. FR-11 calls the routing "suggested {department,
 * priority} + reasons[]", and §8 renders it "with applied/overridden indicator" — so
 * the packet's values are APPLIED to the new ticket as its starting point while the
 * packet keeps its own copy forever. Comparing the two is what lets Doc 07 draw that
 * indicator, and it only works because the packet cannot be edited afterwards.
 *
 * THE ANCHOR IS A REFERENCE, NEVER A COPY (BR-02). transcript_anchor becomes a
 * `ticket_message_anchors` row; no message is moved, copied or re-parented, then or
 * ever.
 */
final class CreateFromHandoff
{
    public function __construct(private readonly CreateTicket $tickets) {}

    public function create(Conversation $conversation, HandoffPacket $packet, ?string $idempotencyKey = null): Ticket
    {
        return DB::transaction(function () use ($conversation, $packet, $idempotencyKey): Ticket {
            $ticket = $this->tickets->create(new NewTicket(
                conversation: $conversation,
                source: TicketSource::AiHandoff,
                // No subject: FR-03 lets Doc 09 generate one within 30 s and mark it
                // `ai`. The packet's summary is NOT quietly promoted into the subject —
                // they are different artifacts with different contracts.
                priority: $packet->suggestedPriority,
                departmentId: $packet->suggestedDepartment,
                formData: $packet->formData,
                idempotencyKey: $idempotencyKey,
            ));

            // T-05/SEC-02: insert-only, one packet per ticket (unique ticket_id), no
            // UPDATE grant at all. A "corrected" packet is a new ticket's packet.
            DB::table('ticket_handoff_packets')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'payload' => json_encode($packet->toPayload(), JSON_THROW_ON_ERROR),
                'schema_version' => $packet->schemaVersion,
            ]);

            // BR-02: the range is a LINK. Messages stay in their conversation.
            DB::table('ticket_message_anchors')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $conversation->identifier(),
                'from_message_id' => $packet->transcriptAnchor['from_message_id'],
                'to_message_id' => $packet->transcriptAnchor['to_message_id'],
                'kind' => 'transcript_anchor',
            ]);

            return $ticket->refresh();
        });
    }
}
