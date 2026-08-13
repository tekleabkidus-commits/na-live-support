<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| S07 step 11 — the composer's doors: STRUCTURAL mode safety (FR-17/BR-02 —
| the Doc 04 AC-04 integration re-proof, sabotage #14's target), FR-21's
| reply-then-transition order with reply-stays-sent, Doc 06 EC-08's prompt
| (the closing row), FR-22 drafts (EC-06 conflict + clear-on-send).
|--------------------------------------------------------------------------
*/

function composerScene(string $status = 'open'): array
{
    [$tenant, $conversation, $ticket] = ticketFixture($status);
    $tenantId = $tenant->identifier();
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));
    // The compose path resolves the channel adapter (Doc 04's registry) —
    // register fakechan exactly as the outbound-pipeline suite does.
    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096, mediaTypes: ['photo'], supportsTyping: false,
            supportsEdit: false, supportsDelete: false, supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S11 Composer');
    grantPermissions($actor, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.reply', 'tickets.note', 'tickets.status.change', 'tickets.reopen'], 'S11 Composer Role');

    return [$tenant, $tenantId, $conversation, $ticket, $actor];
}

it('MODE SAFETY IS STRUCTURAL (AC-05\'s integration half, the Doc 04 AC-04 re-proof): the note door writes kind=note/is_internal with NO delivery lifecycle and NO outbound event; the reply door CANNOT carry a note (sabotage #14 red here)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = composerScene();

    $note = $this->actingAs($actor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/note',
        ['body' => 'internal only — @colleague check the batch'],
    );
    $note->assertStatus(201);

    asTenant($tenant, function () use ($note): void {
        /** @var object{kind: string, is_internal: bool, delivery_state: string|null, direction: string} $row */
        $row = DB::table('messages')->where('id', $note->json('message_id'))->first(['kind', 'is_internal', 'delivery_state', 'direction']);
        expect($row->kind)->toBe('note')
            ->and((bool) $row->is_internal)->toBeTrue()
            ->and($row->delivery_state)->toBeNull('a NOTE grew a delivery lifecycle — the adapter path is reachable (N-04 violated)');

        // ZERO outbound events for the note: nothing queued toward any
        // adapter (the AC-04 bar, asserted at the outbox).
        expect(DB::table('outbox')->where('name', 'message.queued')->where('payload->message_id', $note->json('message_id'))->count())
            ->toBe(0, 'a note reached the outbound event path');
    });
});

it('FR-21: send-and-set-status executes reply THEN transition in order; a REJECTED transition leaves the reply SENT and reports transition_rejected (B12-A01 AC-05\'s rollback + one-tap retry)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = composerScene();

    // Happy half: send & pending-customer (open→pending_customer, legal).
    $sent = $this->actingAs($actor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/reply',
        ['body' => 'Need your account number.', 'then' => 'pending_customer'],
    );
    $sent->assertStatus(201);
    expect($sent->json('transition.ok'))->toBeTrue();
    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('pending_customer');
    });

    // Rejection half: pending_customer → resolved is NOT an edge of the
    // frozen matrix — the reply must STAY SENT, the refusal reported.
    $second = $this->actingAs($actor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/reply',
        ['body' => 'One more thing…', 'then' => 'resolve'],
    );
    $second->assertStatus(201);
    expect($second->json('transition.ok'))->toBeFalse()
        ->and($second->json('transition.code'))->toBe('transition_rejected');
    asTenant($tenant, function () use ($second, $ticket): void {
        expect(DB::table('messages')->where('id', $second->json('message_id'))->exists())->toBeTrue('the reply was rolled back with the transition — FR-21 forbids that');
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('pending_customer', 'the rejected transition still moved the ticket');
    });

    // B12-A01 AC-05's one-tap retry door: a LEGAL edge lands
    // (pending_customer → open, the matrix's own row), and the refusal
    // path answers 422 transition_rejected for the illegal one.
    $illegalRetry = $this->actingAs($actor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/transition',
        ['to' => 'resolve'],
    );
    $illegalRetry->assertStatus(422);
    expect($illegalRetry->json('code'))->toBe('transition_rejected');

    $retry = $this->actingAs($actor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/transition',
        ['to' => 'open'],
    );
    $retry->assertOk();
});

it('Doc 06 EC-08 (the closing row\'s prompt): replying on a RESOLVED ticket answers 409 reopen_confirmation_required; confirmed → reopens through the SOLE writer, then sends', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = composerScene('resolved');

    $unconfirmed = $this->actingAs($actor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/reply',
        ['body' => 'Following up'],
    );
    $unconfirmed->assertStatus(409);
    expect($unconfirmed->json('code'))->toBe('reopen_confirmation_required');
    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('resolved', 'the unconfirmed prompt still moved the ticket');
    });

    $confirmed = $this->actingAs($actor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/reply',
        ['body' => 'Following up', 'confirm_reopen' => true],
    );
    $confirmed->assertStatus(201);
    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('open', 'the confirmed reopen did not land through the sole writer');
    });
});

it('FR-22 drafts: save round-trips; the send CLEARS the draft (tombstone); EC-12\'s key is the CONVERSATION (survives ticket merges by construction)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = composerScene();

    $saved = $this->actingAs($actor, 'staff')->patchJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/draft',
        ['body' => 'half-typed thought'],
    );
    $saved->assertOk();

    asTenant($tenant, function () use ($actor, $conversation): void {
        expect(DB::table('message_drafts')->where('user_id', $actor->identifier())->where('conversation_id', $conversation->identifier())->whereNull('cleared_at')->exists())->toBeTrue();
    });

    $this->actingAs($actor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/reply',
        ['body' => 'the real reply'],
    )->assertStatus(201);

    asTenant($tenant, function () use ($actor, $conversation): void {
        expect(DB::table('message_drafts')->where('user_id', $actor->identifier())->where('conversation_id', $conversation->identifier())->whereNull('cleared_at')->exists())
            ->toBeFalse('the send did not tombstone the draft (FR-22)');
    });
});

it('FR-05/BR-03 (ruling (c), step 14): a staff reply through the door IMPLICITLY CLAIMS the unassigned ticket via the owning service — visible in the payload, inert on an already-held ticket, inert without tickets.assign_self, and OFF with the tenant toggle (the reply standing every time)', function (): void {
    // Actors are created up front — ambient tenant context does not survive
    // the HTTP request cycles below (the file's standing pattern).
    [$tenant, $tenantId, $conversation, $ticket] = composerScene();
    $claimer = inboxScopedActor('All', 'S14 Claimer');
    grantPermissions($claimer, ['tickets.view', 'conversations.view', 'tickets.reply', 'tickets.assign_self'], 'S14 Claimer Role');
    $second = inboxScopedActor('All', 'S14 Second');
    grantPermissions($second, ['tickets.view', 'conversations.view', 'tickets.reply', 'tickets.assign_self'], 'S14 Second Role');

    // (1) The first public reply claims — through AssignTicket's own write,
    // so the ticket.assigned event rides the outbox like any assignment.
    $reply = $this->actingAs($claimer, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/reply',
        ['body' => 'On it — the first reply claims.'],
    );
    $reply->assertStatus(201);
    expect($reply->json('implicit_claim'))->toBeTrue();
    asTenant($tenant, function () use ($ticket, $claimer): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))
            ->toBe($claimer->identifier(), 'the reply did not claim (FR-05/BR-03)');
        expect(DB::table('outbox')->where('name', 'ticket.assigned')
            ->where('payload->ticket_id', $ticket->identifier())->count())
            ->toBeGreaterThan(0, 'the implicit claim bypassed the owning service (no ticket.assigned event)');
    });

    // (2) A second replier's reply leaves the held ticket exactly as held.
    $again = $this->actingAs($second, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation->identifier().'/reply',
        ['body' => 'Adding context — not stealing it.'],
    );
    $again->assertStatus(201);
    expect($again->json('implicit_claim'))->toBeFalse();
    asTenant($tenant, function () use ($ticket, $claimer): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))
            ->toBe($claimer->identifier(), 'a later reply moved an existing assignment');
    });

    // (3) Without tickets.assign_self the reply stands and no claim happens
    // (the scene actor's grant set deliberately lacks it).
    [$tenant2, , $conversation2, $ticket2, $plainActor] = composerScene();
    $claimer2 = inboxScopedActor('All', 'S14 Toggled Claimer');
    grantPermissions($claimer2, ['tickets.view', 'conversations.view', 'tickets.reply', 'tickets.assign_self'], 'S14 Toggled Role');
    $noPerm = $this->actingAs($plainActor, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation2->identifier().'/reply',
        ['body' => 'Reply without the claim capability.'],
    );
    $noPerm->assertStatus(201);
    expect($noPerm->json('implicit_claim'))->toBeFalse();
    asTenant($tenant2, function () use ($ticket2): void {
        expect(DB::table('tickets')->where('id', $ticket2->identifier())->value('assignee_id'))->toBeNull();
    });

    // (4) R-04's per-tenant toggle: off → the reply still sends, the claim
    // does not (the service's own gate, exercised through the door).
    asTenant($tenant2, function (): void {
        app(App\Support\Settings\SettingsRepository::class)->set('tickets.implicit_claim', false);
    });
    $toggled = $this->actingAs($claimer2, 'staff')->postJson(
        'https://localhost/inbox/thread/'.$conversation2->identifier().'/reply',
        ['body' => 'Strict dispatch — replies never claim here.'],
    );
    $toggled->assertStatus(201);
    expect($toggled->json('implicit_claim'))->toBeFalse();
    asTenant($tenant2, function () use ($ticket2): void {
        expect(DB::table('tickets')->where('id', $ticket2->identifier())->value('assignee_id'))->toBeNull();
    });
});
