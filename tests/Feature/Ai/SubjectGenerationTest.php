<?php

declare(strict_types=1);

use App\Domain\Ai\Providers\ProviderResponse;
use App\Jobs\Ai\GenerateTicketSubject;
use Illuminate\Support\Facades\DB;

/**
 * Doc 06 FR-03's generation half, owned by Doc 09 (generate_subject, FR-01's
 * catalog): a ticket.created consumer writes through Doc 06's OWN door
 * SubjectWriter::generated() — subject_source=ai, never overwriting, EC-11's
 * absence on failure, idempotent on redelivery (C-09).
 */
function runSubjectConsumer(string $eventId): void
{
    (new GenerateTicketSubject($eventId))->handle(
        app(App\Domain\Ai\Orchestrator\AiOrchestrator::class),
        app(App\Domain\Ai\Prompt\LayeredAssembly::class),
        app(App\Domain\Tickets\Creation\SubjectWriter::class),
        app(App\Domain\Ai\DecisionLog\DecisionWriter::class),
    );
}

function subjectScene(?string $subject = null): array
{
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    if ($subject !== null) {
        app(App\Domain\Tickets\Creation\SubjectWriter::class)->editedByStaff($ticket, $subject, grantPermissions($actor, ['tickets.view', 'tickets.edit'], 'S09 Subject Editor'));
    }
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] my telebirr deposit of 500 ETB has not arrived since yesterday');
    $eventId = inboxSeedEvent('ticket.created', ['tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(), 'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id, 'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null]);

    return [$tenant, $tenantId, $conversation, $ticket, $eventId];
}

it('FR-03: a subjectless ticket gets a generated subject through SubjectWriter::generated() — subject_source=ai, ticket.subject_changed evented + audited by Doc 06\'s door; the decision row records it; a redelivery changes nothing (C-09)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $eventId] = subjectScene();
    $driver = copilotDriver(new ProviderResponse('"Delayed telebirr deposit"', 80, 6), new ProviderResponse('Second subject', 80, 6));

    runSubjectConsumer($eventId);

    $row = DB::table('tickets')->where('id', $ticket->identifier())->first(['subject', 'subject_source']);
    expect($row->subject)->toBe('Delayed telebirr deposit')->and($row->subject_source)->toBe('ai');
    expect(DB::table('outbox')->where('name', 'ticket.subject_changed')->where('payload->ticket_id', $ticket->identifier())->count())->toBe(1);
    expect(DB::table('audit_log')->where('action_key', 'ticket.subject_changed')->where('entity_id', $ticket->identifier())->count())->toBe(1);
    expect(DB::table('ai_decisions')->where('ticket_id', $ticket->identifier())->where('task', 'generate_subject')->value('action'))->toBe('subject_written');
    // The template forbids amounts/references: the model saw the instruction; the customer turn rode as fenced user content.
    expect($driver->seen[0]['messages'][0]['content'])->toContain('Never include amounts, reference numbers');

    runSubjectConsumer($eventId);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('subject'))->toBe('Delayed telebirr deposit')
        ->and(count($driver->seen))->toBe(1, 'a redelivery must not call the model again')
        ->and(DB::table('ai_decisions')->where('ticket_id', $ticket->identifier())->where('task', 'generate_subject')->count())->toBe(1);
});

it('FR-03: a ticket that ALREADY has a staff subject is never overwritten (the door refuses; no model call); EC-11: a provider outage leaves the subject NULL with no half-written value, and the throw is the retry path', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $eventId] = subjectScene('Staff wrote this');
    $driver = copilotDriver(new ProviderResponse('AI subject', 1, 1));
    runSubjectConsumer($eventId);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('subject'))->toBe('Staff wrote this')->and($driver->seen)->toBe([]);

    bindTenant($tenant);
    [$tenant2, $tenantId2, $conversation2, $ticket2, $eventId2] = subjectScene();
    copilotDriver(); // empty script → provider failure → chain exhausted
    Illuminate\Support\Facades\Cache::flush();
    expect(fn () => runSubjectConsumer($eventId2))->toThrow(App\Domain\Ai\Providers\AllProvidersExhausted::class);
    expect(DB::table('tickets')->where('id', $ticket2->identifier())->value('subject'))->toBeNull();
});

it('FR-03: the 80-character cap and one-line normalization hold; an EMPTY model answer writes nothing and logs invalid_action', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $eventId] = subjectScene();
    copilotDriver(new ProviderResponse("  Deposit\n   delayed   ".str_repeat('x', 100), 1, 1));
    runSubjectConsumer($eventId);
    $subject = DB::table('tickets')->where('id', $ticket->identifier())->value('subject');
    expect(mb_strlen((string) $subject))->toBe(80)->and($subject)->toStartWith('Deposit delayed x');

    bindTenant($tenant);
    [$t2, $tid2, $c2, $ticket2, $eventId2] = subjectScene();
    copilotDriver(new ProviderResponse('   ', 1, 1));
    runSubjectConsumer($eventId2);
    expect(DB::table('tickets')->where('id', $ticket2->identifier())->value('subject'))->toBeNull()
        ->and(DB::table('ai_decisions')->where('ticket_id', $ticket2->identifier())->value('error_category'))->toBe('invalid_action');
});
