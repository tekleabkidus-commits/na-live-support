<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;

/**
 * P-TIMELINE-SMS (Doc 07 core, STOP-F; Doc 14 FR-12 / AC-09 / §9) — the
 * server-side control, RED FIRST on the tree without it.
 *
 * The timeline door filters `name LIKE 'ticket.%'` (TicketActionsController
 * :187) with a key-scoped allowlist (:199): an `sms.*` lifecycle event
 * carrying `ticket_id` never appears. The packet widens the name filter to
 * the eight `sms.*` lifecycle names, admits the SMS keys through the
 * allowlist, and answers the row's LIVE state + the DRAWN masked phone
 * (`+251 91 ••• 4417`) from a Doc 14 read — never the full number, never a
 * body (SEC-02). The actor is class-only (system).
 */
it('an sms.sent event with a ticket_id appears on the ticket timeline as a system row with the SMS detail keys', function (): void {
    // the TicketActionDoorsTest scene, mirrored (Pest test-file functions are file-scoped)
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S14 Timeline Sms');
    grantPermissions($actor, ['tickets.view', 'conversations.view', 'views.manage'], 'S14 Timeline Sms Role');
    app(ListProjector::class)->recomputeTicket($ticket->identifier());
    $tenantId = $tenant->identifier();

    inboxSeedEvent('ticket.status_changed', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'old_status' => 'new',
        'new_status' => 'open', 'actor_type' => 'agent', 'actor_id' => $actor->identifier(),
    ]);
    $smsMessageId = '0192a3b4-0000-7000-8000-00000000c0de';
    inboxSeedEvent('sms.sent', [
        'tenant_id' => $tenantId, 'sms_message_id' => $smsMessageId, 'event' => 'ticket_resolved',
        'ticket_id' => $ticket->identifier(), 'customer_id' => $ticket->customer_id, 'gateway_id' => null,
        'lane' => 'sms-transactional', 'template_version_id' => null, 'reason' => null,
    ]);

    $response = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/t/'.$ticket->identifier().'/timeline?all=1');
    $response->assertOk();

    /** @var list<array{name: string, actor_type: string, actor_name: string|null, detail: array<string, mixed>}> $rows */
    $rows = (array) $response->json('rows');
    $sms = array_values(array_filter($rows, fn (array $r): bool => $r['name'] === 'sms.sent'));

    expect(count($sms))->toBe(1, 'the sms.* lifecycle row must reach the timeline (P-TIMELINE-SMS)')
        ->and($sms[0]['actor_type'])->toBe('system')
        ->and($sms[0]['actor_name'])->toBeNull('an SMS row is class-only')
        ->and($sms[0]['detail']['sms_message_id'] ?? null)->toBe($smsMessageId)
        ->and($sms[0]['detail']['event'] ?? null)->toBe('ticket_resolved')
        ->and($sms[0]['detail'])->not->toHaveKey('phone')
        ->and($sms[0]['detail'])->not->toHaveKey('rendered_body');
});
