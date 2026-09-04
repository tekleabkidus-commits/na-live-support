<?php

declare(strict_types=1);

namespace App\Jobs\Webchat;

use App\Domain\Conversations\Notes\NotesWriter;
use App\Domain\Customers\Identity\PhoneNumber;
use App\Domain\Fields\Seeding\CustomerFieldSeeder;
use App\Domain\Fields\ValueWriter;
use App\Domain\Forms\SubmissionWriter;
use App\Domain\Webchat\Forms\OfflineSubmission;
use App\Jobs\OutboxConsumer;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * Doc13/PreChat (ADR 0011, declared in the catalog; fixtures/tickets/consumers/
 * doc13.json): form answers that rode a message's envelope (`channel_meta.form`)
 * land here — on message.received the STAFF-COLLECTED field values through
 * Doc 12's one write door (when the tenant defines the field; otherwise the
 * value stays in the message's meta for staff), on ticket.created the
 * `{version_id, answers}` snapshot into form_data (BR-06). C-09: idempotent —
 * fields are set to the same canonical value, the snapshot is written once
 * (an existing form_data with the same version_id is left alone). No phone or
 * email IDENTITY is ever minted here (Doc 08 BR-02; STOP-5A RULED (a) 2026-09-04):
 * a pre-chat phone lands as a staff-collected field and drops ONE staff-visible
 * system line "phone provided" — with the Doc 08 conflict flag, computed
 * READ-ONLY, when that phone already belongs to another customer. The phone
 * becomes an identity only through Doc 08's FR-09 staff door.
 */
final class PreChatConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'webchat-in';
    }

    public function handle(ValueWriter $fields, SubmissionWriter $submissions, NotesWriter $notes): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name', 'payload']);
        if ($event === null) {
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($event->payload, true, 16, JSON_THROW_ON_ERROR);

        if ($event->name === 'message.received') {
            $this->onMessage($fields, $notes, $payload);
        } elseif ($event->name === 'ticket.created') {
            $this->onTicket($submissions, $payload);
        }
    }

    /** @param array<string, mixed> $payload */
    private function onMessage(ValueWriter $fields, NotesWriter $notes, array $payload): void
    {
        $messageId = $payload['message_id'] ?? null;
        $customerId = $payload['customer_id'] ?? null;
        if (! is_string($messageId) || ! is_string($customerId)) {
            return;
        }
        $form = $this->formMeta($messageId);
        if ($form === null) {
            return;
        }
        foreach (['name', 'phone', 'email'] as $key) {
            $value = $form['answers'][$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            try {
                // The SYSTEM actor uuid (the seeders' precedent): a consumer acting on a rule of its own.
                $fields->setCustomerField($customerId, $key, trim($value), CustomerFieldSeeder::SYSTEM_ACTOR);
            } catch (\InvalidArgumentException) {
                // No such customer field for this tenant, or the value fails its definition:
                // the answer stays where it already is (the message's meta), visible to staff.
            }
        }

        $phone = $form['answers']['phone'] ?? null;
        $conversationId = $payload['conversation_id'] ?? null;
        if (is_string($phone) && trim($phone) !== '' && is_string($conversationId)) {
            $this->phoneProvidedLine($notes, $conversationId, $customerId, trim($phone));
        }
    }

    /**
     * STOP-5A (a): the staff-visible system line — "phone provided" (staff-collected,
     * never an identity) and, when the phone already sits on ANOTHER customer's
     * identity, the Doc 08 conflict flag computed read-only (nothing attached,
     * nothing linked — the FR-09 staff door decides). C-09: one line per
     * conversation per phone — a re-delivery finds its own line and stops.
     */
    private function phoneProvidedLine(NotesWriter $notes, string $conversationId, string $customerId, string $rawPhone): void
    {
        try {
            $e164 = PhoneNumber::toE164($rawPhone, 'ET');
        } catch (\Throwable) {
            return; // an unparseable phone stays in the message's meta; no line to draw
        }
        $conflicts = DB::table('customer_identities')->where('type', 'phone')->where('value', $e164)->where('customer_id', '!=', $customerId)->count();
        $masked = OfflineSubmission::mask($e164);
        $body = $conflicts > 0
            ? (string) __('widget.prechat.phone_conflict', ['phone' => $masked])
            : (string) __('widget.prechat.phone_provided', ['phone' => $masked]);

        $already = DB::table('messages')->where('conversation_id', $conversationId)->where('author_type', 'system')->where('kind', 'note')->where('body', $body)->exists();
        if ($already) {
            return;
        }
        $conversation = Conversation::query()->find($conversationId);
        if ($conversation instanceof Conversation) {
            $notes->addSystemNote($conversation, $body);
        }
    }

    /** @param array<string, mixed> $payload */
    private function onTicket(SubmissionWriter $submissions, array $payload): void
    {
        $ticketId = $payload['ticket_id'] ?? null;
        $conversationId = $payload['conversation_id'] ?? null;
        if (! is_string($ticketId) || ! is_string($conversationId)) {
            return;
        }
        /** @var object{id: string}|null $message */
        $message = DB::table('messages')->where('conversation_id', $conversationId)->where('direction', 'in')
            ->whereRaw("jsonb_exists(channel_meta, 'form')")->orderBy('created_at')->first(['id']); // never the `?` operator in a bound statement (risk 0h)
        if ($message === null) {
            return;
        }
        $form = $this->formMeta($message->id);
        if ($form === null) {
            return;
        }
        $existing = DB::table('tickets')->where('id', $ticketId)->value('form_data');
        if (is_string($existing)) {
            $decoded = json_decode($existing, true);
            if (is_array($decoded) && ($decoded['version_id'] ?? null) === $form['version_id']) {
                return; // C-09: already snapshotted
            }
        }
        try {
            $submissions->record($ticketId, $form['version_id'], $form['answers'], 'webchat', complete: false);
        } catch (\Throwable) {
            // A version no longer readable or a refused answer set: the message's meta keeps the answers for staff.
        }
    }

    /** @return array{version_id: string, answers: array<string, mixed>}|null */
    private function formMeta(string $messageId): ?array
    {
        $meta = DB::table('messages')->where('id', $messageId)->value('channel_meta');
        $decoded = is_string($meta) ? json_decode($meta, true) : null;
        $form = is_array($decoded) && is_array($decoded['form'] ?? null) ? $decoded['form'] : null;
        if ($form === null || ! is_string($form['version_id'] ?? null) || ! is_array($form['answers'] ?? null)) {
            return null;
        }
        /** @var array<string, mixed> $answers */
        $answers = $form['answers'];

        return ['version_id' => $form['version_id'], 'answers' => $answers];
    }
}
