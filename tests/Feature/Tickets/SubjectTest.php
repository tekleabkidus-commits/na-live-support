<?php

declare(strict_types=1);

use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\SubjectWriter;
use App\Domain\Tickets\Creation\TicketSource;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-03 / EC-11 — the subject, and the seam Doc 09 fills
|--------------------------------------------------------------------------
|
| "FR-03 Subject: optional at creation; if absent, AI generates one from the first
| messages (Doc 09) within 30 s, marked subject_source=ai, editable by staff
| (tickets.edit)."
|
| "EC-11 AI subject generation fails → subject stays empty with retry affordance; lists
| fall back to first-message snippet (Doc 07)."
|
| EC-11 IS PROVEN AS AN ABSENCE, which is the honest shape for it: a failed generation
| calls nothing, so the column stays NULL and there is no sentinel value, no error
| string and no half-written subject to clean up. The retry affordance and the snippet
| fallback are Doc 07's rendering of that NULL — the pre-agreed deferral — so what this
| file can prove is that the NULL survives, unpolluted, and that the ticket is otherwise
| completely usable without a subject.
*/

test('FR-03: Doc 09\'s seam writes the subject and marks it ai-authored', function () {
    [, $conversation] = creationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));

    expect($ticket->subject)->toBeNull();

    $wrote = app(SubjectWriter::class)->generated($ticket, 'Deposit of 500 ETB not credited');

    expect($wrote)->toBeTrue();
    $ticket->refresh();
    expect($ticket->subject)->toBe('Deposit of 500 ETB not credited')
        ->and($ticket->subject_source)->toBe('ai');

    $event = DB::table('outbox')->where('name', 'ticket.subject_changed')->first();
    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['subject_source'])->toBe('ai')
        ->and($payload['actor_type'])->toBe('ai')
        // Q7's posture: the TEXT is not on the 13-month stream.
        ->and($payload)->not->toHaveKey('subject');

    // …it is in the audit entry, with old/new (SEC-03).
    $audit = DB::table('audit_log')->where('action_key', 'ticket.subject_changed')->first();
    $auditPayload = json_decode((string) $audit->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($auditPayload['old_subject'])->toBeNull()
        ->and($auditPayload['new_subject'])->toBe('Deposit of 500 ETB not credited');
});

test('FR-03: the AI seam never overwrites a subject that already exists', function () {
    [, $conversation] = creationFixture();
    $ticket = app(CreateTicket::class)->create(
        new NewTicket($conversation, TicketSource::Agent, subject: 'Written by the agent'),
    );

    $wrote = app(SubjectWriter::class)->generated($ticket, 'Written by the AI');

    expect($wrote)->toBeFalse()
        ->and($ticket->fresh()->subject)->toBe('Written by the agent')
        ->and($ticket->fresh()->subject_source)->toBe('staff');
});

test('FR-03: a staff edit needs tickets.edit, and the source label follows the editor', function () {
    [, $conversation] = creationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    app(SubjectWriter::class)->generated($ticket, 'Deposit issue');

    $withoutPermission = ticketActor(['tickets.view'], 'Fixture No Edit Role');
    expect(fn () => app(SubjectWriter::class)->editedByStaff($ticket->fresh(), 'Corrected subject', $withoutPermission))
        ->toThrow(DomainException::class, 'tickets.edit');

    $editor = ticketActor(['tickets.edit'], 'Fixture Edit Role');
    app(SubjectWriter::class)->editedByStaff($ticket->fresh(), 'Deposit of 500 ETB not credited', $editor);

    expect($ticket->fresh()->subject)->toBe('Deposit of 500 ETB not credited')
        ->and($ticket->fresh()->subject_source)->toBe('staff');
});

test('FR-03: a subject may be absent but may not be blanked to an empty string', function () {
    [, $conversation] = creationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, subject: 'Something'));
    $editor = ticketActor(['tickets.edit'], 'Fixture Edit Role');

    // An empty subject is what Doc 09 FILLS; letting staff write one would make the
    // ticket indistinguishable from one awaiting generation.
    expect(fn () => app(SubjectWriter::class)->editedByStaff($ticket, '   ', $editor))
        ->toThrow(DomainException::class);

    expect($ticket->fresh()->subject)->toBe('Something');
});

test('EC-11: a failed generation leaves the subject NULL — no sentinel, no error text, ticket still usable', function () {
    [, $conversation] = creationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::AiHandoff));

    // Doc 09's generation fails => it simply never calls the writer. Nothing to undo.
    expect($ticket->subject)->toBeNull()
        ->and($ticket->subject_source)->toBeNull()
        ->and(DB::table('outbox')->where('name', 'ticket.subject_changed')->count())->toBe(0);

    // The ticket is fully functional without one: it has its number, its status and its
    // slot. Doc 07 renders the first-message snippet in the list and a retry affordance
    // on the panel (pre-agreed deferral) — neither needs a value stored here.
    expect($ticket->number)->toBeGreaterThan(0)
        ->and($ticket->status()->value)->toBe('new')
        ->and($conversation->fresh()->open_ticket_id)->toBe($ticket->identifier());

    // And the retry is just the seam called again, later, successfully.
    expect(app(SubjectWriter::class)->generated($ticket->fresh(), 'Deposit not credited'))->toBeTrue();
    expect($ticket->fresh()->subject_source)->toBe('ai');
});
