<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use Illuminate\Support\Facades\DB;

/**
 * P-EXPIRED-CARD (Doc 07 core, STOP-E; Doc 05 FR-23 / Doc 14 FR-02 US-02
 * EC-10) — the server-side control, RED FIRST on the tree without it.
 *
 * D07-N3 (filed by name): `ThreadController::conversationMeta` answers
 * `customer_has_phone` from the DEPRECATED `customers.phone` mirror
 * (S08 deprecation; `LegacyIdentityEnforce` keeps it as a mirror). The
 * packet makes the predicate Doc 08's own — a `customer_identities` row of
 * type `phone` — the SAME predicate Doc 14's guard reads, so the card and
 * the guard can never disagree; and adds `sms_available` (an active
 * gateway exists — §8 "SMS is off until a gateway exists").
 */
it('customer_has_phone is TRUE for a customer with a phone IDENTITY and a NULL mirror column; sms_available is answered', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S14 Expired Card');
    grantPermissions($actor, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.reply'], 'S14 Expired Card Role');
    app(ListProjector::class)->recomputeTicket($ticket->identifier());

    // the identity exists (Doc 08's shape); the deprecated mirror stays NULL on purpose
    DB::table('customer_identities')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid7(),
        'tenant_id' => $tenantId,
        'customer_id' => $ticket->customer_id,
        'channel' => 'phone',
        'external_user_id' => '+251900001234',
        'type' => 'phone',
        'value' => '+251900001234',
        'source' => 'staff',
        'first_seen_at' => now(),
        'metadata' => '{}',
    ]);
    DB::table('customers')->where('id', $ticket->customer_id)->update(['phone' => null]);

    $page = $this->actingAs($actor, 'staff')->get('https://localhost/inbox/thread/'.$conversation->identifier());
    $page->assertOk();

    $meta = $page->viewData('page')['props']['conversation'] ?? null;
    expect($meta)->not->toBeNull()
        ->and($meta['customer_has_phone'])->toBeTrue('the composer must read Doc 08\'s phone identity, never the deprecated mirror (D07-N3)')
        ->and(array_key_exists('sms_available', $meta))->toBeTrue('the card needs to know whether a gateway exists (§8)');
});
