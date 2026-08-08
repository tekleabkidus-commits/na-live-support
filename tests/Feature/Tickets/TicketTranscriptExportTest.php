<?php

declare(strict_types=1);

use App\Domain\Conversations\Transcripts\TranscriptExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 04 FR-24's TICKET half (inherited row 1, closed here) — A-18 (step 8)
|--------------------------------------------------------------------------
|
| "rendered PDF/text of a conversation or ticket range" — the conversation half
| shipped with Doc 04 step 7; this is the ticket half on the SAME gate
| (conversations.transcript.export, re-checked at export — Doc 03 FR-06), the same
| watermark, the same notes double-confirm. SEC-05 keeps tickets.export
| (property/timeline) a separate, future gate — nothing here touches it.
|
| A-18's two bases, both proven: anchors where present (BR-02's boundary table is
| the authority), lifetime window otherwise.
*/

/** A customer message with a chosen created_at, through the platform role. */
function exportMessageAt(string $tenantId, string $conversationId, string $body, string $at): string
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_platform')->table('messages')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'direction' => 'in',
        'author_type' => 'customer',
        'kind' => 'text',
        'body' => $body,
        'is_internal' => false,
        'created_at' => $at,
    ]);

    return $id;
}

test('A-18 anchors basis: an anchored ticket exports EXACTLY what its ranges resolve to, watermarked, audited with ticket_id', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    commitFixtures();

    try {
        // Five messages at pinned instants; the anchor spans m2..m4 — m1 and m5 are
        // OUTSIDE the range and must not appear.
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[$i] = exportMessageAt($tenantId, $conversation->identifier(), "anchored message {$i}", sprintf('2026-08-01 10:0%d:00+00', $i));
        }

        DB::table('ticket_message_anchors')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'ticket_id' => $ticket->identifier(),
            'conversation_id' => $conversation->identifier(),
            'from_message_id' => $ids[2],
            'to_message_id' => $ids[4],
            'kind' => 'transcript_anchor',
        ]);

        $exporter = ticketActor(['conversations.transcript.export', 'tickets.view'], 'Fixture Export Role');
        $result = app(TranscriptExporter::class)->exportTicketRange($ticket, $exporter, 'text');

        expect($result['mime'])->toBe('text/plain')
            ->and($result['content'])->toContain('anchored message 2')
            ->and($result['content'])->toContain('anchored message 3')
            ->and($result['content'])->toContain('anchored message 4')
            ->and($result['content'])->not->toContain('anchored message 1')
            ->and($result['content'])->not->toContain('anchored message 5');

        // SEC-05: the watermark names the exporter (Doc 04's own key renders it).
        $exporterName = $exporter->getAttribute('name');
        expect($result['content'])->toContain(is_string($exporterName) ? $exporterName : '');

        // The audit entry carries the ticket and the basis (A-18); the event stays v1.
        $audit = DB::table('audit_log')->where('action_key', 'conversation.transcript_exported')->orderByDesc('id')->first();
        $payload = json_decode((string) $audit->payload, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['ticket_id'])->toBe($ticket->identifier())
            ->and($payload['range_basis'])->toBe('anchors')
            ->and(DB::table('outbox')->where('name', 'transcript.exported')->count())->toBe(1);
    } finally {
        cleanupTenants([$tenantId]);
    }
});

test('A-18 lifetime basis: an unanchored ticket exports its tenure — pre-ticket and post-close messages stay out, notes stay out by default', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    commitFixtures();

    try {
        $before = exportMessageAt($tenantId, $conversation->identifier(), 'pre-ticket chatter', '2026-08-01 09:00:00+00');

        $ticket = machineClosedTicket(anotherConversation($tenantId, 'export-life'), 'closed');
        /** @var App\Models\Conversation $conv */
        $conv = App\Models\Conversation::query()->findOrFail($ticket->conversation_id);

        // Inside the tenure (between the DB-stamped created_at and closed_at) — the
        // window is pinned by moving the WORLD, the step-5 pattern.
        DB::connection('pgsql_platform')->update(
            "UPDATE tickets SET closed_at = closed_at + interval '2 hours' WHERE id = ?",
            [$ticket->identifier()],
        );
        $ticket->refresh();
        // created_at carries no model cast (R-19b column) — parse the raw value.
        $createdAt = Carbon\CarbonImmutable::parse((string) $ticket->created_at);
        $inside = exportMessageAt($tenantId, $conv->identifier(), 'inside the tenure', $createdAt->addHour()->format('Y-m-d H:i:s.uP'));
        $after = exportMessageAt($tenantId, $conv->identifier(), 'after the close', $ticket->closed_at->addHour()->format('Y-m-d H:i:s.uP'));

        // An internal note inside the window: excluded without the double-confirm.
        DB::connection('pgsql_platform')->table('messages')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'conversation_id' => $conv->identifier(),
            'direction' => 'in',
            'author_type' => 'agent',
            'kind' => 'note',
            'body' => 'internal-only observation',
            'is_internal' => true,
            'created_at' => $createdAt->addMinutes(30)->format('Y-m-d H:i:s.uP'),
        ]);

        $exporter = ticketActor(['conversations.transcript.export', 'tickets.view'], 'Fixture Export Role');
        $result = app(TranscriptExporter::class)->exportTicketRange($ticket, $exporter, 'text');

        expect($result['content'])->toContain('inside the tenure')
            ->and($result['content'])->not->toContain('after the close', 'the lifetime window ends at closed_at')
            ->and($result['content'])->not->toContain('pre-ticket chatter', 'another conversation, another tenure — never in this export')
            ->and($result['content'])->not->toContain('internal-only observation', 'US-04: notes stay out without the double-confirm');

        $audit = DB::table('audit_log')->where('action_key', 'conversation.transcript_exported')->orderByDesc('id')->first();
        expect(json_decode((string) $audit->payload, true)['range_basis'])->toBe('lifetime');
    } finally {
        cleanupTenants([$tenantId]);
    }
});

test('the gate is Doc 04\'s, re-checked at export: without conversations.transcript.export the ticket door refuses identically', function () {
    [, , $ticket] = ticketFixture('open');
    $blind = ticketActor(['tickets.view'], 'Fixture No-Export Role');

    expect(fn () => app(TranscriptExporter::class)->exportTicketRange($ticket, $blind, 'text'))
        ->toThrow(DomainException::class, 'conversations.transcript.export is required');

    // And the notes double-confirm binds the ticket door exactly as it binds the
    // conversation door.
    $blessed = ticketActor(['conversations.transcript.export', 'tickets.view'], 'Fixture Export Role');
    expect(fn () => app(TranscriptExporter::class)->exportTicketRange($ticket, $blessed, 'text', includeNotes: true))
        ->toThrow(DomainException::class, 'second confirmation');
});
