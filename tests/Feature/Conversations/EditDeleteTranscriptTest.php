<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Revisions\HistoryReader;
use App\Domain\Conversations\Revisions\MessageEditing;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Domain\Conversations\Transcripts\TranscriptExporter;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageRevision;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Edit/delete/revisions + transcripts (Doc 04 S20 step 7 — FR-21/22/23/24,
| BR-03, SEC-05, AC-06, US-02/US-04/US-06; Doc 03 FR-06 export re-check as
| its first real consumer — inherited row 1)
|--------------------------------------------------------------------------
*/

function revisionsFixture(bool $canEditDelete = true): array
{
    $tenant = createTenant();
    bindTenant($tenant);

    $adapter = new FakeChannelAdapter(new Capabilities(
        maxText: 4096,
        mediaTypes: ['photo'],
        supportsTyping: false,
        supportsEdit: $canEditDelete,
        supportsDelete: $canEditDelete,
        supportsButtons: false,
        replyWindow: ReplyWindow::none(),
    ));

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', $adapter, 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_rev', 'is_shell' => true]);
    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', 'chat-rev-'.uniqid(), $customer->identifier());

    $granted = asTenant($tenant, fn (): User => grantAllPermissions(User::factory()->create([
        'tenant_id' => $tenant->tenantId(),
        'name' => 'fixture.supervisor',
    ])));
    $ungranted = asTenant($tenant, fn (): User => User::factory()->create([
        'tenant_id' => $tenant->tenantId(),
        'name' => 'fixture.rookie',
    ]));
    bindTenant($tenant);

    return [$conversation, $granted, $ungranted, $adapter, $customer];
}

function inboundRow(Conversation $conversation, Customer $customer, string $body, string $externalId): Message
{
    return Message::query()->create([
        'conversation_id' => $conversation->identifier(),
        'direction' => 'in',
        'author_type' => 'customer',
        'author_id' => $customer->identifier(),
        'kind' => 'text',
        'body' => $body,
        'external_message_id' => $externalId,
    ]);
}

// ── AC-06: the round-trip ────────────────────────────────────────────────────

test('AC-06: customer edit + delete round-trip preserves originals; the full history is gated AND access-audited', function () {
    [$conversation, $granted, $ungranted, , $customer] = revisionsFixture();
    $editing = app(MessageEditing::class);

    $message = inboundRow($conversation, $customer, 'I sent 500 birr', 'ext-r1');

    $editing->customerEdited($message, 'I sent 50 birr');

    expect($message->refresh()->body)->toBe('I sent 50 birr')
        ->and($message->edited_at)->not->toBeNull()
        ->and(MessageRevision::query()->where('message_id', $message->identifier())->sole()->prior_body)->toBe('I sent 500 birr')
        ->and(DB::table('outbox')->where('name', 'message.edited')->count())->toBe(1);

    $editing->customerDeleted($message);

    expect($message->refresh()->deleted_at)->not->toBeNull() // tombstone, visible to staff (US-02)
        ->and($message->body)->toBe('I sent 50 birr')          // content retained per retention (BR-03)
        ->and(DB::table('outbox')->where('name', 'message.deleted')->count())->toBe(1);

    // The gate: no conversations.history.full → no preserved content, at all.
    expect(fn () => app(HistoryReader::class)->revisionsFor($message, $ungranted))
        ->toThrow(DomainException::class, 'conversations.history.full');

    // With the permission: revisions come back AND the ACCESS is audited.
    $revisions = app(HistoryReader::class)->revisionsFor($message, $granted);

    expect($revisions)->toHaveCount(2)
        ->and(DB::table('audit_log')->where('action_key', 'conversation.history_viewed')->count())->toBe(1)
        ->and(DB::table('audit_log')->whereIn('action_key', ['conversation.message_edited', 'conversation.message_deleted'])->count())->toBe(2);
});

test('FR-21: a channel redelivery carrying a KNOWN external id becomes a revision through the pipeline — never a second row', function () {
    [$conversation] = revisionsFixture();
    Queue::fake([App\Jobs\Conversations\ConfirmBatchTail::class, App\Jobs\Conversations\FetchInboundMedia::class]);

    $raw = fn (string $text, int $update) => [
        'update_id' => $update,
        'chat_id' => $conversation->external_chat_id,
        'message_id' => 'ext-edit-1',
        'from' => ['id' => 'user-1', 'display' => 'Fixture', 'username' => '@fixture_rev'],
        'kind' => 'text', 'text' => $text, 'occurred_at' => '2026-08-01T10:00:00+00:00',
    ];

    $context = app(App\Support\Tenancy\TenantContext::class);
    $tenantId = $context->tenantId();

    Bus::dispatchSync(new App\Jobs\Conversations\NormalizeInbound('fakechan', 'bot-1', $raw('original wording', 1)));
    bindTenant($tenantId);
    Bus::dispatchSync(new App\Jobs\Conversations\NormalizeInbound('fakechan', 'bot-1', $raw('edited wording', 2)));
    bindTenant($tenantId);

    $message = Message::query()->where('external_message_id', 'ext-edit-1')->sole(); // ONE row

    expect($message->body)->toBe('edited wording')
        ->and($message->edited_at)->not->toBeNull()
        ->and(MessageRevision::query()->where('message_id', $message->identifier())->sole()->prior_body)->toBe('original wording');
});

// ── FR-22/FR-23: staff side ──────────────────────────────────────────────────

test('FR-22: staff edit rides the capability interface, revises, audits, and reaches the adapter', function () {
    [$conversation, $granted, , $adapter] = revisionsFixture();
    $messages = app(MessageRepository::class);

    $sent = $messages->persistOutbound($conversation, 'agent', $granted->identifier(), 'text', 'our first answer');
    $messages->updateDeliveryState($sent, 'sent');
    $messages->recordExternalMessageId($sent, 'fake-out-77');

    app(MessageEditing::class)->staffEdit($sent, $granted, 'our corrected answer');

    expect($sent->refresh()->body)->toBe('our corrected answer')
        ->and($adapter->edits)->toHaveCount(1)
        ->and(MessageRevision::query()->where('message_id', $sent->identifier())->sole()->source)->toBe('staff');
});

test('FR-22: staff delete requires the telegram.message.delete permission — the seeded key, wired through the Doc 03 provider', function () {
    [$conversation, $granted, $ungranted, $adapter] = revisionsFixture();
    $messages = app(MessageRepository::class);

    $sent = $messages->persistOutbound($conversation, 'agent', $granted->identifier(), 'text', 'to be removed');
    $messages->updateDeliveryState($sent, 'sent');
    $messages->recordExternalMessageId($sent, 'fake-out-78');

    expect(fn () => app(MessageEditing::class)->staffDelete($sent, $ungranted))
        ->toThrow(DomainException::class, 'telegram.message.delete');

    app(MessageEditing::class)->staffDelete($sent, $granted);

    expect($sent->refresh()->deleted_at)->not->toBeNull()
        ->and($adapter->deletes)->toHaveCount(1);
});

test('FR-22: a channel whose capability is off has NO staff edit path', function () {
    [$conversation, $granted] = revisionsFixture(canEditDelete: false);
    $messages = app(MessageRepository::class);

    $sent = $messages->persistOutbound($conversation, 'agent', $granted->identifier(), 'text', 'immutable here');
    $messages->updateDeliveryState($sent, 'sent');

    expect(fn () => app(MessageEditing::class)->staffEdit($sent, $granted, 'nope'))
        ->toThrow(DomainException::class, 'capability off');
});

test('FR-23: AI messages are never edited or deleted post-send — corrections are new messages', function () {
    [$conversation, $granted] = revisionsFixture();
    $messages = app(MessageRepository::class);

    $ai = $messages->persistOutbound($conversation, 'ai', null, 'text', 'AI answer');
    $messages->updateDeliveryState($ai, 'sent');

    expect(fn () => app(MessageEditing::class)->staffEdit($ai, $granted, 'silently fixed'))
        ->toThrow(DomainException::class, 'never edited')
        ->and(fn () => app(MessageEditing::class)->staffDelete($ai, $granted))
        ->toThrow(DomainException::class, 'never edited');
});

// ── FR-24/SEC-05: transcripts ────────────────────────────────────────────────

test('FR-24/US-04: the transcript excludes notes by default, carries the watermark, audits, and emits — and the export permission re-checks fresh (Doc 03 FR-06)', function () {
    [$conversation, $granted, $ungranted, , $customer] = revisionsFixture();

    inboundRow($conversation, $customer, 'customer says hello', 'ext-t1');
    Message::query()->create([
        'conversation_id' => $conversation->identifier(),
        'direction' => 'in', 'author_type' => 'agent', 'author_id' => $granted->identifier(),
        'kind' => 'note', 'body' => 'INTERNAL: suspected fraud', 'is_internal' => true,
    ]);

    // No permission → refused AT export time (the re-check, inherited row 1).
    expect(fn () => app(TranscriptExporter::class)->export($conversation, $ungranted, 'text'))
        ->toThrow(DomainException::class, 'conversations.transcript.export');

    $export = app(TranscriptExporter::class)->export($conversation, $granted, 'text');

    expect($export['content'])->toContain('customer says hello')
        ->and($export['content'])->not->toContain('INTERNAL: suspected fraud') // US-04
        ->and($export['content'])->toContain('fixture.supervisor')             // SEC-05 watermark
        ->and(DB::table('outbox')->where('name', 'transcript.exported')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'conversation.transcript_exported')->count())->toBe(1);
});

test('FR-24: including notes takes BOTH flags — the double-confirm is structural', function () {
    [$conversation, $granted, , , $customer] = revisionsFixture();

    inboundRow($conversation, $customer, 'hello', 'ext-t2');
    Message::query()->create([
        'conversation_id' => $conversation->identifier(),
        'direction' => 'in', 'author_type' => 'agent', 'author_id' => $granted->identifier(),
        'kind' => 'note', 'body' => 'the internal note', 'is_internal' => true,
    ]);

    expect(fn () => app(TranscriptExporter::class)->export($conversation, $granted, 'text', includeNotes: true))
        ->toThrow(DomainException::class, 'second confirmation');

    $export = app(TranscriptExporter::class)->export($conversation, $granted, 'text', includeNotes: true, includeNotesConfirmed: true);

    expect($export['content'])->toContain('the internal note');
});

test('FR-24 PDF (ADR 0009): renders with the embedded Ethiopic font — Amharic body text survives', function () {
    [$conversation, $granted, , , $customer] = revisionsFixture();

    inboundRow($conversation, $customer, 'ብር መላክ አልቻልኩም', 'ext-t3'); // "I could not send birr"

    $export = app(TranscriptExporter::class)->export($conversation, $granted, 'pdf');

    expect($export['mime'])->toBe('application/pdf')
        ->and(str_starts_with($export['content'], '%PDF'))->toBeTrue()
        ->and(str_contains($export['content'], 'NotoSansEthiopic'))->toBeTrue(); // the font is EMBEDDED
});
