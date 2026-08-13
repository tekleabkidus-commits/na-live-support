<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Tickets\Merge\MergeTickets;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| S07 step 14 — B1-S04's operation doors over Doc 06's owning services
| (split/escalate/spam/transfer/unmerge/reopen). The services' physics are
| Doc 06's own suites; these clauses prove the DOORS: scope→404-pattern,
| refusals→stable C-04 codes, the projector converged, and the reopen door
| carrying the REAL written reason the closed→open edge demands — with the
| generic transition door now REFUSING that edge (the fake-reason defect
| the step-14 sweep found).
|
| Actors minted BEFORE the first HTTP call (the standing pattern).
|--------------------------------------------------------------------------
*/

/**
 * A scope-TRUE actor: role at the given level holding exactly the named
 * keys (grantPermissions would silently widen scopes to All — the standing
 * lesson, applied here as a helper).
 *
 * @param  list<string>  $permissions
 */
function scopedOperator(string $level, array $permissions, string $roleName): User
{
    $role = App\Models\Role::query()->create([
        'name' => $roleName,
        'color' => 'role-agent',
        'permissions' => $permissions,
        'scopes' => ['tickets' => $level, 'conversations' => $level, 'customers' => $level, 'reports' => $level],
    ]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

/** @return array{0: mixed, 1: mixed, 2: Ticket, 3: User} */
function operationScene(string $status = 'open', array $extraPermissions = []): array
{
    [$tenant, $conversation, $ticket] = ticketFixture($status);
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S14 Operator');
    grantPermissions($actor, array_merge([
        'tickets.view', 'conversations.view', 'views.manage', 'tickets.status.change',
    ], $extraPermissions), 'S14 Operator Role');
    app(ListProjector::class)->recomputeTicket($ticket->identifier());

    return [$tenant, $conversation, $ticket, $actor];
}

it('spam + unspam doors: mark flips the machine through the owning service (reason mandatory), unmark within the window restores — and the projector converges both ways', function (): void {
    [$tenant, , $ticket, $actor] = operationScene('open', ['tickets.spam.mark']);

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/spam', ['reason' => 'link farm blast, not a customer'])
        ->assertOk()
        ->assertJsonPath('status', 'spam');

    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('spam');
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('spam_marked_at'))->not->toBeNull('the trigger did not stamp the window start');
        expect(DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->value('status'))->toBe('spam', 'the projection did not converge');
    });

    // The short reason refuses validation-shaped, per the service's own bar.
    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/unspam', ['reason' => 'oops'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/unspam', ['reason' => 'real customer, mis-flagged by me'])
        ->assertOk();

    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('spam_marked_at'))->toBeNull('the exit did not clear the window stamp');
    });
});

it('escalate + deescalate doors: supervisor review with the ≥10-char reason; the record lands in the escalation column; de-escalation returns to open with a note', function (): void {
    [$tenant, , $ticket, $actor] = operationScene('open', ['tickets.escalate']);

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/escalate', [
            'path' => 'supervisor_review',
            'reason' => 'payout over limit with mismatched reference',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'escalated');

    asTenant($tenant, function () use ($ticket): void {
        $escalation = DB::table('tickets')->where('id', $ticket->identifier())->value('escalation');
        expect($escalation)->not->toBeNull();
        $decoded = json_decode((string) $escalation, true, 8, JSON_THROW_ON_ERROR);
        expect($decoded['path'])->toBe('supervisor_review')
            ->and($decoded['reason'])->toBe('payout over limit with mismatched reference');
    });

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/deescalate', ['note' => 'reviewed and cleared by supervisor'])
        ->assertOk()
        ->assertJsonPath('status', 'open');
});

it('transfer door: moves the ticket to the OPAQUE destination id (existence validation is Doc 11\'s arrival gift — the service\'s recorded posture); a Team-scoped actor answers the fail-closed refusal', function (): void {
    [$tenant, , $ticket, $actor] = operationScene('open', ['tickets.department.change']);
    $teamScoped = scopedOperator('Team', ['tickets.view', 'conversations.view', 'views.manage', 'tickets.department.change'], 'S14 TeamScoped Role');
    $departmentId = (string) Illuminate\Support\Str::uuid7();

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/transfer', ['department_id' => $departmentId])
        ->assertOk()
        ->assertJsonPath('department_id', $departmentId);

    asTenant($tenant, function () use ($ticket, $departmentId): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('department_id'))->toBe($departmentId);
    });

    // Pre-Doc 11 membership is EMPTY — a Team-scoped actor FAILS CLOSED at
    // the door's own scope resolution (they cannot even SEE the ticket), so
    // the answer is the C-10 404-pattern: no oracle, nothing smuggled. The
    // service's own destination refusal is Doc 06's suite's proof.
    $this->actingAs($teamScoped, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/transfer', ['department_id' => (string) Illuminate\Support\Str::uuid7()])
        ->assertNotFound();
});

it('split door: mints the linked ticket from selected messages; the FOURTH split answers the cap refusal (split_cap_reached) with the C-04 stable code', function (): void {
    [$tenant, $conversation, $ticket, $actor] = operationScene('open', ['tickets.split', 'tickets.create']);
    $messageIds = asTenant($tenant, function () use ($tenant, $conversation): array {
        $ids = [];
        foreach (range(1, 4) as $i) {
            $id = (string) Illuminate\Support\Str::uuid7();
            DB::table('messages')->insert([
                'id' => $id, 'tenant_id' => $tenant->identifier(),
                'conversation_id' => $conversation->identifier(),
                'direction' => 'in', 'author_type' => 'customer',
                'kind' => 'text', 'body' => 'split source '.$i, 'is_internal' => false,
            ]);
            $ids[] = $id;
        }

        return $ids;
    });

    foreach (range(0, 2) as $i) {
        $this->actingAs($actor, 'staff')
            ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/split', [
                'message_ids' => [$messageIds[$i]],
                'subject' => 'Linked work '.($i + 1),
            ])
            ->assertOk();
    }

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/split', [
            'message_ids' => [$messageIds[3]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('refusal_code', 'split_cap_reached');
});

it('reopen door (C9): carries the REAL written reason into the closed→open edge — audited verbatim — while the generic transition door now REFUSES that edge (the fake-reason defect closed)', function (): void {
    [$tenant, $conversation, $ticket, $actor] = operationScene('open', ['tickets.reopen']);

    // Close through the machine so closed_at exists (the window boundary).
    asTenant($tenant, function () use ($ticket): void {
        $model = Ticket::query()->findOrFail($ticket->identifier());
        app(App\Domain\Tickets\Transitions\TicketTransitionService::class)->transition(
            $model,
            App\Domain\Tickets\Transitions\TicketStatus::Resolved,
            App\Domain\Tickets\Transitions\TransitionContext::system('test_resolve'),
        );
        app(App\Domain\Tickets\Transitions\TicketTransitionService::class)->transition(
            $model->refresh(),
            App\Domain\Tickets\Transitions\TicketStatus::Closed,
            App\Domain\Tickets\Transitions\TransitionContext::system('test_close'),
        );
    });

    // The DEFECT's regression: the generic transition door refuses the edge
    // instead of satisfying the mandatory reason with its own origin string.
    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/thread/'.$conversation->identifier().'/transition', ['to' => 'open'])
        ->assertStatus(422)
        ->assertJsonPath('refusal_code', 'reopen_reason_required');

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/reopen', ['reason' => 'customer says the payout failed again'])
        ->assertOk()
        ->assertJsonPath('status', 'open');

    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('open');
        // The written reason IS the audited reason — not a door literal.
        $reopenAudited = DB::table('audit_log')->where('action_key', 'ticket.status_changed')
            ->where('payload', 'like', '%customer says the payout failed again%')->exists();
        expect($reopenAudited)->toBeTrue('the reopen audit does not carry the WRITTEN reason');
    });
});

it('unmerge door (C7): reverses through the owning service (source back to open, pointer cleared, both projections converged); a second ask answers not_merged; scope misses answer the 404-pattern', function (): void {
    [$tenant, $conversation, $ticket, $actor] = operationScene('open', ['tickets.merge']);
    $outsider = scopedOperator('Own', ['tickets.view', 'conversations.view', 'tickets.merge'], 'S14 Outsider Role');

    asTenant($tenant, function () use ($tenant, $conversation, $ticket, $actor): void {
        $c = anotherConversation($tenant->identifier(), 'unmerge-target-', is_string($conversation->customer_id) ? $conversation->customer_id : null);
        [$other] = inboxTicketRow($tenant->identifier(), $c, 'open');
        app(ListProjector::class)->recomputeTicket($other->identifier());
        $source = Ticket::query()->findOrFail($ticket->identifier());
        $target = Ticket::query()->findOrFail($other->identifier());
        app(MergeTickets::class)->merge([$source], $target, $actor);
    });

    // Scope miss FIRST (the outsider sees the 404-pattern, not a 403).
    $this->actingAs($outsider, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/unmerge')
        ->assertNotFound();

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/unmerge')
        ->assertOk()
        ->assertJsonPath('status', 'open');

    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('merged_into'))->toBeNull();
        expect(DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->value('status'))->toBe('open', 'the source projection did not converge');
    });

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/unmerge')
        ->assertStatus(422)
        ->assertJsonPath('refusal_code', 'not_merged');
});

it('follow-up door (C9 lapsed — ruling 2026-08-13, the DOCUMENT wins): FR-08\'s new-ticket-and-link recourse mints the linked ticket (previous_ticket_id carried, FR-10 slot claimed), refuses a non-closed ticket, and answers open_ticket_exists while the follow-up itself is live', function (): void {
    [$tenant, $conversation, $ticket, $actor] = operationScene('open', ['tickets.create']);

    // A live ticket's recourse is never a follow-up — refused before any mint.
    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/follow-up')
        ->assertStatus(422);

    // Close through the machine (the sole writer; the slot releases on close).
    asTenant($tenant, function () use ($ticket): void {
        $model = Ticket::query()->findOrFail($ticket->identifier());
        app(App\Domain\Tickets\Transitions\TicketTransitionService::class)->transition(
            $model,
            App\Domain\Tickets\Transitions\TicketStatus::Resolved,
            App\Domain\Tickets\Transitions\TransitionContext::system('test_resolve'),
        );
        app(App\Domain\Tickets\Transitions\TicketTransitionService::class)->transition(
            $model->refresh(),
            App\Domain\Tickets\Transitions\TicketStatus::Closed,
            App\Domain\Tickets\Transitions\TransitionContext::system('test_close'),
        );
    });

    $minted = $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/follow-up', ['subject' => 'Second payout attempt failed'])
        ->assertOk();

    /** @var string $newId */
    $newId = $minted->json('ticket_id');
    asTenant($tenant, function () use ($newId, $ticket, $conversation): void {
        /** @var object{previous_ticket_id: string|null, status: string, subject: string|null, conversation_id: string} $row */
        $row = DB::table('tickets')->where('id', $newId)->first(['previous_ticket_id', 'status', 'subject', 'conversation_id']);
        expect($row->previous_ticket_id)->toBe($ticket->identifier(), 'the FR-08 link is the point — previous_ticket_id must carry it')
            ->and($row->conversation_id)->toBe($conversation->identifier())
            ->and($row->status)->toBe('new')
            ->and($row->subject)->toBe('Second payout attempt failed');
        // FR-10: the fresh ticket holds the conversation slot.
        expect(DB::table('conversations')->where('id', $conversation->identifier())->value('open_ticket_id'))->toBe($newId);
    });

    // While the follow-up is live, a second follow-up on the SAME closed
    // ticket answers the service's own open_ticket_exists refusal.
    $second = $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/follow-up');
    $second->assertStatus(422);
    expect((string) $second->json('detail'))->toContain('open_ticket_exists');
});
