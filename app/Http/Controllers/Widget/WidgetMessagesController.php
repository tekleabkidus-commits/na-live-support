<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Outbound\DeliveryState;
use App\Domain\Conversations\Outbound\DeliveryStateMachine;
use App\Domain\Conversations\Presence\TypingSignals;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Domain\Webchat\WebchatAdapter;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\Conversation;
use App\Support\Http\CursorPagination;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Quota\Quota;
use App\Support\Quota\QuotaMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 T-03 — the visitor's conversation doors, all scoped to the VISITOR
 * principal's OWN conversation (AC-04: the conversation is found by the
 * channel key (webchat, widget id, visitor id) — nothing else can be
 * addressed; a foreign id is ABSENT, 404, never a refusal that confirms it).
 *
 *   POST messages   the ONE inbound door — the same discipline as every
 *                   channel webhook (Doc 04 FR-10 step 1): quota BEFORE the
 *                   work, the envelope enqueued on `webchat-in`, ACK 202
 *                   never inline. The shell customer is minted by the
 *                   pipeline on THIS first message and nowhere else (FR-05).
 *   GET  messages   the cursor page (C-05, `paginateByTimestampAndId`), a
 *                   VISITOR-curated select: internal notes and quarantined
 *                   rows are structurally absent (SEC-06/BR-01: the select
 *                   never names them and the WHERE excludes them), plus the
 *                   sync envelope (typing, queue, survey) — the ONE sync
 *                   model polling shares (T-04).
 *   POST typing     TypingSignals::customerTyping (EC-12: TTL auto-clear).
 *   POST receipts   T-02 "delivered on socket ack": the platform's FIRST
 *                   `delivered` producer, through DeliveryStateMachine only,
 *                   for OUTBOUND rows of the visitor's OWN conversation —
 *                   `sent` first is the machine's own table (never faked).
 */
final class WidgetMessagesController
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly QuotaMeter $quota,
        private readonly VisitorConversation $conversations,
        private readonly MessageRepository $messages,
        private readonly DeliveryStateMachine $machine,
        private readonly TypingSignals $typing,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $visitor = $this->visitor($request);
        $widget = $this->conversations->widgetFor($request);

        /** @var array{client_message_id: string, kind: string, body?: string|null, media?: list<array{reference: string}>, reply_to?: string|null, occurred_at?: string|null, page_url?: string|null, form?: array{version_id: string, answers: array<string, mixed>}} $input */
        $input = $request->validate([
            'client_message_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'kind' => ['required', 'string', 'in:text,photo,video,document,voice,audio'],
            'body' => ['nullable', 'string', 'max:4000'],
            'media' => ['sometimes', 'array', 'max:1'],
            'media.*.reference' => ['required_with:media', 'string', 'max:128'],
            'media.*.mime' => ['nullable', 'string', 'max:100'],
            'media.*.width' => ['nullable', 'integer'],
            'media.*.height' => ['nullable', 'integer'],
            'reply_to' => ['nullable', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
            'page_url' => ['nullable', 'string', 'max:2048'],
            'form' => ['sometimes', 'array'],
            'form.version_id' => ['required_with:form', 'string', 'max:64'],
            'form.answers' => ['required_with:form', 'array'],
        ]);

        $this->quota->allow(Quota::MessagesPerMinute);

        try {

            $media = $this->conversations->mediaDescriptors($widget, $input['media'] ?? []);

        } catch (\InvalidArgumentException $e) {

            // FR-16: a descriptor that names no intake object of THIS visitor's (or a malformed one) is refused, never a 500

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'widget.upload.'.$e->getMessage());

        }

        $envelope = [
            'visitor_id' => $visitor->visitorId,
            'widget_id' => VisitorConversation::widgetId($widget),
            'message_id' => $input['client_message_id'],
            'kind' => $input['kind'],
            'body' => $input['body'] ?? null,
            'media' => $media,
            'reply_to' => $input['reply_to'] ?? null,
            'occurred_at' => $input['occurred_at'] ?? now('UTC')->toIso8601String(),
            'page_url' => $input['page_url'] ?? null,
            'form' => $input['form'] ?? null,
            'display' => null, // the asserted name (A-61) is looked up by the pipeline's identity seam, never trusted from the client
        ];

        NormalizeInbound::dispatch(WebchatAdapter::CHANNEL, VisitorConversation::widgetId($widget), $envelope)
            ->onQueue($this->registry->inboundFamily(WebchatAdapter::CHANNEL));

        return new JsonResponse(['accepted' => true, 'client_message_id' => $input['client_message_id']], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $visitor = $this->visitor($request);
        $widget = $this->conversations->widgetFor($request);
        $conversation = $this->conversations->find($widget, $visitor);

        if ($conversation === null) {
            // No message yet: an empty page and an empty envelope — no customer, no conversation (FR-05).
            return new JsonResponse(['data' => [], 'next_cursor' => null, 'conversation_id' => null] + $this->envelope(null));
        }

        $page = CursorPagination::fromRequest($request)->paginateByTimestampAndId(
            DB::table('messages')
                ->where('tenant_id', $conversation->getAttribute('tenant_id'))
                ->where('conversation_id', $conversation->identifier())
                ->where('is_internal', false)
                ->where('quarantined', false)
                ->whereNull('deleted_at')
                ->select(['id', 'created_at', 'direction', 'author_type', 'kind', 'body', 'media', 'delivery_state', 'edited_at', 'external_message_id', 'reply_to_message_id', 'channel_occurred_at', 'channel_meta']),
            'created_at',
        );

        $data = $page->items->map(fn (\stdClass $row): array => $this->conversations->present($row))->values()->all();

        return new JsonResponse([
            'data' => $data,
            'next_cursor' => $page->nextCursor,
            'conversation_id' => $conversation->identifier(),
        ] + $this->envelope($conversation));
    }

    public function typing(Request $request): SymfonyResponse
    {
        $visitor = $this->visitor($request);
        $widget = $this->conversations->widgetFor($request);
        $conversation = $this->conversations->find($widget, $visitor);
        if ($conversation !== null) {
            $this->typing->customerTyping($conversation);
        }

        return new Response('', 204);
    }

    public function receipts(Request $request): SymfonyResponse
    {
        $visitor = $this->visitor($request);
        $widget = $this->conversations->widgetFor($request);
        $conversation = $this->conversations->find($widget, $visitor);
        /** @var array{message_ids: list<string>} $input */
        $input = $request->validate(['message_ids' => ['required', 'array', 'max:50'], 'message_ids.*' => ['string', 'max:64']]);

        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $delivered = 0;
        foreach ($input['message_ids'] as $id) {
            $message = $this->messages->findInConversation($conversation, $id);
            if ($message === null || $message->direction !== 'out') {
                // Absence, never a refusal (AC-04 / C-10): a foreign or unknown id is 404 for the whole batch.
                throw new NotFoundHttpException;
            }
            if ($message->delivery_state === DeliveryState::Sent->value) {
                $this->machine->transition($message, DeliveryState::Delivered);
                $delivered++;
            }
            // Already delivered / not yet sent: nothing to say — `sent` never rolls back and delivered is earned once.
        }

        return new JsonResponse(['delivered' => $delivered]);
    }

    private function visitor(Request $request): WidgetVisitor
    {
        $principal = $request->user('widget'); // this surface names its guard explicitly (AuthenticateWidgetVisitor's law)
        if (! $principal instanceof WidgetVisitor) {
            throw new NotFoundHttpException;
        }

        return $principal;
    }

    /** @return array<string, mixed> the sync envelope the poller and the socket share (T-04) */
    private function envelope(?Conversation $conversation): array
    {
        return [
            'envelope' => [
                'typing' => $conversation !== null && $this->conversations->isStaffTyping($conversation),
                'queue' => $conversation !== null ? $this->conversations->queueState($conversation) : null,
                'survey' => $conversation !== null ? $this->conversations->pendingSurvey($conversation) : null,
                'blocked' => $conversation !== null && $conversation->state === 'blocked',
            ],
        ];
    }
}
