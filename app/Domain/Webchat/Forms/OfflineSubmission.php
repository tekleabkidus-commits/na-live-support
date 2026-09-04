<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Forms;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Identity\CustomerResolver;
use App\Domain\Conversations\Inbound\InboundPersister;
use App\Domain\Forms\SubmissionWriter;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Domain\Webchat\WebchatAdapter;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 FR-15 / US-03 — the OFFLINE form, one synchronous door through the
 * existing doors (STOP-5B, stated): the story becomes the conversation's
 * FIRST message (Doc 04's persister — the same one-transaction door the
 * inbound job calls; the shell customer is minted here because this IS the
 * visitor's first engagement, FR-05), then Doc 06 FR-14's factory mints the
 * `offline`-tagged ticket with source `offline_form`, then Doc 12's
 * SubmissionWriter snapshots the answers into form_data. The receipt cites
 * the REAL ticket number (B3-S05 F10) and masks the phone back so a typo is
 * caught while it matters. Idempotent on the visitor + version + answers hash
 * (a retried tap lands no second ticket). The SMS is Doc 14's seam ([D14]).
 *
 * The phone is NOT an identity (Doc 08 BR-02): it lands as the name/phone
 * the consumer files as staff-collected (PreChat, on message.received) —
 * STOP-5A's ruling decides whether an identity ever follows.
 */
final class OfflineSubmission
{
    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly CustomerResolver $identity,
        private readonly InboundPersister $persister,
        private readonly CreateTicket $tickets,
        private readonly SubmissionWriter $submissions,
    ) {}

    /**
     * @param  array<string, mixed>  $answers  validated + canonical (FormStepValidator)
     * @return array{ticket_number: int, phone_masked: ?string, conversation_id: string}
     */
    public function submit(Widget $widget, WidgetVisitor $visitor, string $versionId, array $answers): array
    {
        $story = is_string($answers['story'] ?? null) ? $answers['story'] : '';
        $name = is_string($answers['name'] ?? null) ? $answers['name'] : null;
        $phone = is_string($answers['phone'] ?? null) ? $answers['phone'] : null;
        $clientId = 'offline_'.substr(hash('sha256', $versionId.'|'.json_encode($answers, JSON_THROW_ON_ERROR)), 0, 24);

        return DB::transaction(function () use ($widget, $visitor, $versionId, $answers, $story, $name, $phone, $clientId): array {
            $adapter = $this->adapters->adapter(WebchatAdapter::CHANNEL);
            $inbound = $adapter->handleWebhook([
                'visitor_id' => $visitor->visitorId,
                'widget_id' => VisitorConversation::widgetId($widget),
                'message_id' => $clientId,
                'kind' => 'text',
                'body' => $story,
                'occurred_at' => now('UTC')->toIso8601String(),
                'display' => $name,
                'form' => ['version_id' => $versionId, 'answers' => $answers, 'offline' => true],
            ]);

            // Idempotent: the same submission again finds its message and its ticket.
            $existing = DB::table('messages')->where('external_message_id', $inbound->externalMessageId)->first(['conversation_id']);
            if ($existing !== null && is_string($existing->conversation_id)) {
                $conversation = Conversation::query()->findOrFail($existing->conversation_id);
                $ticket = is_string($conversation->open_ticket_id) ? Ticket::query()->find($conversation->open_ticket_id) : null;
                if ($ticket instanceof Ticket) {
                    return $this->receipt($ticket, $phone, $conversation);
                }
            }

            $customerId = $this->identity->resolve(WebchatAdapter::CHANNEL, $inbound->sender);
            $message = $this->persister->persist(WebchatAdapter::CHANNEL, VisitorConversation::widgetId($widget), $inbound, $customerId);
            $conversation = Conversation::query()->findOrFail($message->conversation_id);

            $ticket = $this->tickets->create(NewTicket::fromOfflineForm(
                $conversation,
                ['version_id' => $versionId, 'answers' => $answers],
                ['webchat'],
                $story !== '' ? mb_substr($story, 0, 80) : null,
            ));
            $this->submissions->record($ticket->identifier(), $versionId, $answers, WebchatAdapter::CHANNEL);

            return $this->receipt($ticket, $phone, $conversation);
        });
    }

    /** @return array{ticket_number: int, phone_masked: ?string, conversation_id: string} */
    private function receipt(Ticket $ticket, ?string $phone, Conversation $conversation): array
    {
        return [
            'ticket_number' => (int) $ticket->number,
            'phone_masked' => $phone !== null ? self::mask($phone) : null,
            'conversation_id' => $conversation->identifier(),
        ];
    }

    /** +251900001234 → "+251 •• ••• 1234": the country code and the last four, nothing else. */
    public static function mask(string $e164): string
    {
        $digits = preg_replace('/\D/', '', $e164) ?? '';
        if (strlen($digits) < 8) {
            return '••••';
        }
        $country = substr($digits, 0, 3);
        $last = substr($digits, -4);

        return sprintf('+%s •• ••• %s', $country, $last);
    }
}
