<?php

declare(strict_types=1);

use App\Jobs\Sms\Consumers\TicketCreatedSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-01 offline_form_confirmation / step-0 finding 5 / the B3-S05 seam
| — Doc 13's offline form promised "the SMS that follows is a notification"
| and the promise becomes TRUE here: a ticket.created with the CLOSED
| payload's source='offline_form' (the marker — never channel_meta) produces
| the offline_form_confirmation SMS through the one door; an ordinary
| ticket.created produces ticket_created; both seeded ON (§21).
*/

it('source=offline_form → the offline_form_confirmation row (not ticket_created); an agent-created ticket → ticket_created; each exactly once on re-drive', function (): void {
    $scene = smsPipeScene();
    Queue::fake();

    // the offline-form ticket (Doc 13's shape: source offline_form on the ticket AND the payload)
    $conversation = anotherConversation($scene['tenant']->identifier(), 'offline', $scene['ticket']->customer_id);
    $offlineTicketId = (string) Illuminate\Support\Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $offlineTicketId, 'tenant_id' => $scene['tenant']->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $scene['ticket']->customer_id,
        'number' => random_int(1, 2_000_000_000), 'status' => 'new', 'priority' => 'normal', 'source' => 'offline_form',
    ]);
    $offlineEvent = inboxSeedEvent('ticket.created', [
        'tenant_id' => $scene['tenant']->identifier(),
        'ticket_id' => $offlineTicketId,
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $scene['ticket']->customer_id,
        'source' => 'offline_form',
        'actor_type' => 'system',
        'actor_id' => null,
    ]);
    (new TicketCreatedSms($offlineEvent))->handle();
    expect(DB::table('sms_messages')->where('event', 'offline_form_confirmation')->where('ticket_id', $offlineTicketId)->count())->toBe(1)
        ->and(DB::table('sms_messages')->where('event', 'ticket_created')->count())->toBe(0);

    // the ordinary creation on the scene's own ticket
    $agentEvent = inboxSeedEvent('ticket.created', [
        'tenant_id' => $scene['tenant']->identifier(),
        'ticket_id' => $scene['ticket']->identifier(),
        'conversation_id' => $scene['conversation']->identifier(),
        'customer_id' => $scene['ticket']->customer_id,
        'source' => 'agent',
        'actor_type' => 'staff',
        'actor_id' => $scene['user']->identifier(),
    ]);
    (new TicketCreatedSms($agentEvent))->handle();
    expect(DB::table('sms_messages')->where('event', 'ticket_created')->count())->toBe(1);

    (new TicketCreatedSms($offlineEvent))->handle();
    (new TicketCreatedSms($agentEvent))->handle();
    expect(DB::table('sms_messages')->count())->toBe(2, 'C-09: re-drives land nothing more');
});

it('the confirmation renders and sends through the pipeline like any other event — the mock accepts it', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $conversation = anotherConversation($scene['tenant']->identifier(), 'offline2', $scene['ticket']->customer_id);
    $ticketId = (string) Illuminate\Support\Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $ticketId, 'tenant_id' => $scene['tenant']->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $scene['ticket']->customer_id,
        'number' => random_int(1, 2_000_000_000), 'status' => 'new', 'priority' => 'normal', 'source' => 'offline_form',
    ]);
    $eventId = inboxSeedEvent('ticket.created', [
        'tenant_id' => $scene['tenant']->identifier(),
        'ticket_id' => $ticketId,
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $scene['ticket']->customer_id,
        'source' => 'offline_form',
        'actor_type' => 'system',
        'actor_id' => null,
    ]);
    (new TicketCreatedSms($eventId))->handle();

    $smsMessageId = (string) DB::table('sms_messages')->where('event', 'offline_form_confirmation')->value('id');
    smsPipeRunJob($smsMessageId);
    /** @var object{state: string, rendered_body: string} $row */
    $row = DB::table('sms_messages')->where('id', $smsMessageId)->first(['state', 'rendered_body']);
    expect($row->state)->toBe('sent')->and($row->rendered_body)->not->toBeEmpty();
});
