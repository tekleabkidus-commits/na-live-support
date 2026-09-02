<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Jobs\Conversations\EnqueueOutboundLane;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Events\EventPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-12: every compose source — agent (Doc 07), AI (Doc 09), automation
 * (Doc 12), system — enters HERE and only here: one transaction writes the row
 * in `queued` plus its `message.queued` outbox event, and the lane push rides an
 * afterCommit job on the channel's outbound family (C-06: the queue leg never
 * outruns the commit).
 *
 * T-03's outbound half: a caller-supplied idempotency key finds the ORIGINAL row
 * on retry — an offline composer reconciling (§9) or a double-clicked send never
 * writes a sibling. Uniqueness is this lookup + the compose path's single door,
 * not a DB unique (the messages migration records why a partitioned unique
 * cannot span retries across a month boundary).
 *
 * BR-02's early half: composing INTO a blocked conversation refuses immediately
 * with `conversation_blocked` — automations included, no exceptions. (EC-04 —
 * blocked BETWEEN compose and send — is the lane worker's check.)
 *
 * kind=note never composes here: notes are not deliverable (SEC-07's type-level
 * exclusion — DeliverablePayload has no note constructor) and take the notes
 * path (step 6). The guard below just makes the refusal loud and named.
 */
final class ComposeOutbound
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly EventPublisher $events,
        private readonly AdapterRegistry $registry,
        private readonly \App\Domain\Customers\Blocking\CustomerSendGuard $customerGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $channelMeta  COUNTERSIGNED CORE EDIT (S09 step 10 packet): passthrough to persistOutbound, nothing else
     */
    public function compose(
        Conversation $conversation,
        string $authorType,
        ?string $authorId,
        string $kind,
        ?string $body,
        ?string $idempotencyKey = null,
        array $channelMeta = [],
    ): Message {
        if ($kind === 'note') {
            throw new DomainException('notes are not deliverable and never compose as outbound (SEC-07/BR-01) — use the notes path.');
        }

        if ($conversation->state === 'blocked') {
            throw new DomainException('conversation_blocked: no outbound to a blocked conversation, no exceptions (BR-02).');
        }

        // RULED CORE EDIT (S08 escalation #2, plan-forecast accepted at the
        // plan audit, landing ordered at the step-6 acceptance): Doc 08
        // T-06's SECOND BELT — the customer-level guard, checked on every
        // outbound so a conversation created MID-CASCADE is still refused and
        // a crashed cascade cannot open a gap (FS-04). Sabotage #8 removes
        // this call and AC-06's mid-cascade clause goes red.
        if ($this->customerGuard->refuses($conversation->customer_id)) {
            throw new DomainException('customer_blocked: this customer is blocked on every channel — nothing can be sent (Doc 08 FR-18/T-06).');
        }

        // COUNTERSIGNED CORE EDIT (S12 PACKET P-SEND, batch-C checkpoint):
        // Doc 12 T-06's staff half — "unresolved detection blocks at the send
        // API, not only UI". A deliverable body carrying an unresolved
        // {{variable}} pill refuses with the stable code (C-04); notes never
        // reach here (the guard above), so a DRAFT may hold pills — a SEND
        // may not. The automation half is SendMacroReplyAction's.
        if ($body !== null && \App\Domain\Macros\MacroGuard::unresolvedIn($body) !== []) {
            throw new DomainException('blank_variable: the body carries unresolved variable pills — resolve or remove them before sending (Doc 12 T-06/BR-04).');
        }

        if ($idempotencyKey !== null) {
            $original = $this->messages->findByIdempotencyKey($conversation, $idempotencyKey);

            if ($original instanceof Message) {
                return $original; // T-03: the retry finds the original, never a sibling
            }
        }

        $message = DB::transaction(function () use ($conversation, $authorType, $authorId, $kind, $body, $idempotencyKey, $channelMeta): Message {
            $message = $this->messages->persistOutbound($conversation, $authorType, $authorId, $kind, $body, $idempotencyKey, $channelMeta);

            $this->events->publish('message.queued', [
                'tenant_id' => $conversation->getAttribute('tenant_id'),
                'message_id' => $message->identifier(),
                'conversation_id' => $conversation->identifier(),
            ]);

            return $message;
        });

        // afterCommit by bus default (C-06): the lane learns of the row only
        // after the row exists for every reader.
        EnqueueOutboundLane::dispatch($conversation->identifier(), $message->identifier())
            ->onQueue($this->registry->outboundFamily($conversation->channel));

        return $message;
    }
}
