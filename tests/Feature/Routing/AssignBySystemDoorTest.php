<?php

declare(strict_types=1);

use App\Domain\Tickets\Assignment\AssigneeOutOfScope;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Assignment\RoutingCommit;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 T-02 / A-49 / FR-15 / FR-05 — THE SYSTEM DOOR (S11 STOP-A packet, countersigned)
|--------------------------------------------------------------------------
|
| Obligations bound at the countersign: the system path carries FR-15's as-if-assigned
| check (A-11) exactly as the staff path; actor=system, audited with the decision id;
| assignBySystem is UNREACHABLE from HTTP (a static assertion — server-internal means
| proven internal); FR-05's new → open lands through the transition service as the
| system actor; `by` is null on the event (the catalog KEYS unchanged).
*/

/** @return array{0: Ticket, 1: User} an unassigned `new` ticket and an Own-scoped agent who can view */
function systemDoorFixture(): array
{
    [, $conversation] = creationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    $agent = ticketActor(['tickets.view'], 'Fixture Routed Agent');

    return [$ticket, $agent];
}

function routingCommit(int $capacity = 5, bool $countPending = false): RoutingCommit
{
    return new RoutingCommit((string) Str::uuid7(), (string) Str::uuid7(), $capacity, $countPending);
}

it('is UNREACHABLE from HTTP: no route action names assignBySystem, and no controller under app/Http references it', function () {
    foreach (Route::getRoutes() as $route) {
        expect($route->getActionName())->not->toContain('assignBySystem');
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Http'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            expect((string) file_get_contents($file->getPathname()))->not->toContain('assignBySystem',
                'a controller references the system door — server-internal means no HTTP path');
        }
    }
});

it('assigns as the SYSTEM: assignee set, FR-05 new → open through the transition service as the system actor, ticket.assigned with by=null, the audit row a system actor carrying the routing decision id', function () {
    [$ticket, $agent] = systemDoorFixture();
    $commit = routingCommit();

    $result = app(AssignTicket::class)->assignBySystem($ticket, $agent, $commit);

    expect($result->assignee_id)->toBe($agent->identifier())
        ->and($result->status())->toBe(TicketStatus::Open);

    /** @var object{payload: string} $event */
    $event = DB::table('outbox')->where('name', 'ticket.assigned')->orderByDesc('id')->first(['payload']);
    $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['new_assignee_id'])->toBe($agent->identifier())
        ->and(array_key_exists('by', $payload))->toBeTrue('the catalog key `by` must stay present')
        ->and($payload['by'])->toBeNull('a system assignment has no user `by`');

    /** @var object{actor_type: string, actor_id: ?string, payload: string} $audit */
    $audit = DB::table('audit_log')->where('action_key', 'ticket.assigned')->orderByDesc('id')->first(['actor_type', 'actor_id', 'payload']);
    expect($audit->actor_type)->toBe('system')
        ->and($audit->actor_id)->toBeNull();
    $auditPayload = json_decode($audit->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($auditPayload['routing_decision_id'])->toBe($commit->decisionId);

    /** @var object{actor_type: string} $transition */
    $transition = DB::table('outbox')->where('name', 'ticket.status_changed')->orderByDesc('id')->first(['payload']);
    expect(json_decode($transition->payload, true, 512, JSON_THROW_ON_ERROR)['actor_type'])->toBe('system');

    // FLAG G: the placement trigger stamped routed_at from the database clock.
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('routed_at'))->not->toBeNull();
});

it('carries FR-15\'s as-if-assigned check exactly as the staff path: a Team-scoped assignee outside the department is REFUSED assignee_out_of_scope, and an agent without tickets.view too', function () {
    [$ticket] = systemDoorFixture();
    $teamScoped = scopedActor(['tickets.view'], 'Team', 'Fixture Team Routed Role');   // Team membership: fail-closed until step 3 binds it
    expect(fn () => app(AssignTicket::class)->assignBySystem($ticket, $teamScoped, routingCommit()))
        ->toThrow(AssigneeOutOfScope::class);

    $blind = ticketActor([], 'Fixture Blind Role');
    expect(fn () => app(AssignTicket::class)->assignBySystem($ticket->fresh(), $blind, routingCommit()))
        ->toThrow(AssigneeOutOfScope::class);

    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBeNull('a refused system assignment wrote the row');
});

it('loses the FR-16 race to a manual claim that landed first (BR-03: humans override machines) — the guarded UPDATE matches nothing, the row is untouched', function () {
    [$ticket, $agent] = systemDoorFixture();
    // FR-05: a manual claim of a `new` ticket opens it through the transition service, which
    // checks the STAFF actor's tickets.status.change (the first run's own catch).
    $human = ticketActor(['tickets.view', 'tickets.assign_self', 'tickets.status.change'], 'Fixture Human Claimer');
    app(AssignTicket::class)->claim($ticket, $human);

    expect(fn () => app(AssignTicket::class)->assignBySystem($ticket->fresh(), $agent, routingCommit()))
        ->toThrow(App\Domain\Tickets\Assignment\AlreadyClaimed::class);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBe($human->identifier());
});
