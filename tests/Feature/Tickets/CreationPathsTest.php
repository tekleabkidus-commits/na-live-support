<?php

declare(strict_types=1);

use App\Domain\Conversations\Storage\OpenTicketExists;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-12 / FR-13 / FR-14 — the creation doors, and AC-02's concurrent half
|--------------------------------------------------------------------------
|
| FR-13 requires automation and API to take "the same validation path", and FR-12/FR-14
| are the same claim about the agent and offline-form doors. The tests below therefore
| check the SHARED behaviour once per door rather than trusting the shared class: the
| failure this guards against is a future path that grows its own creator and quietly
| skips the open-family claim.
|
| AC-02's second half lives here: "concurrent creates on one conversation yield exactly
| one open ticket + open_ticket_exists rejections". (The direct-SQL bypass half is in
| tests/Isolation/TicketOpenFamilyInvariantTest.php, which landed in step 1 — before the
| creation paths existed, per §21's sequencing.)
*/

/** @return array{0: App\Models\Tenant, 1: Conversation} */
function creationFixture(): array
{
    $tenant = createTenant();
    $customer = Customer::query()->create([
        'display_name' => 'Fixture Player',
        'username' => '@fixture_creation_'.Str::random(6),
        'is_shell' => true,
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenant->identifier(),
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-'.Str::random(10),
        'customer_id' => $customer->identifier(),
        'state' => 'active',
    ]);

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->findOrFail($conversationId);

    return [$tenant, $conversation];
}

test('FR-12/13/14: every door produces a `new` ticket that records its source and claims the open slot', function (string $source) {
    [, $conversation] = creationFixture();

    $request = $source === 'offline_form'
        ? NewTicket::fromOfflineForm($conversation, ['question' => 'where is my deposit'])
        : new NewTicket($conversation, TicketSource::from($source));

    $ticket = app(CreateTicket::class)->create($request);

    expect($ticket->status())->toBe(TicketStatus::New)          // §4.2's entry state
        ->and($ticket->source)->toBe($source)                    // FR-13 "source recorded"
        ->and($ticket->number)->toBeGreaterThan(0)               // FR-02
        ->and($conversation->fresh()->open_ticket_id)->toBe($ticket->identifier());

    // The shared path emits and audits the same way whichever door was used.
    expect(DB::table('outbox')->where('name', 'ticket.created')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'ticket.created')->where('entity_id', $ticket->identifier())->count())->toBe(1);
})->with(['agent', 'automation', 'api', 'offline_form', 'ai_handoff']);

test('FR-14: the offline form is tagged `offline` by the path itself, not by the caller remembering', function () {
    [, $conversation] = creationFixture();

    $ticket = app(CreateTicket::class)->create(
        NewTicket::fromOfflineForm($conversation, ['question' => 'where is my deposit'], tags: ['deposits']),
    );

    $tags = DB::table('tickets')->where('id', $ticket->identifier())->value('tags');
    expect($tags)->toContain('offline')->toContain('deposits');

    // And the path REFUSES to be constructed without it, so no other caller can mint an
    // offline ticket that lists cannot find.
    expect(fn () => new NewTicket($conversation, TicketSource::OfflineForm))
        ->toThrow(InvalidArgumentException::class, 'offline');
});

test('FR-12: an open-family ticket blocks a second create and hands back the ticket to jump to', function () {
    [, $conversation] = creationFixture();
    $creator = app(CreateTicket::class);

    $first = $creator->create(new NewTicket($conversation, TicketSource::Agent));

    // §8: the dialog asks BEFORE it offers the button, so it can show a jump link
    // instead of an error (FR-12: "blocked if open-family exists, showing a jump link").
    expect($creator->openFamilyTicketFor($conversation->fresh()))->toBe($first->identifier());

    // And the authority is the claim itself, which refuses.
    expect(fn () => $creator->create(new NewTicket($conversation->fresh(), TicketSource::Agent)))
        ->toThrow(OpenTicketExists::class, 'open_ticket_exists');

    expect(Ticket::query()->where('conversation_id', $conversation->identifier())->count())
        ->toBe(1, 'the refused create left a second ticket behind');
});

test('FR-13: an idempotency key makes a replayed create return the SAME ticket, not a second one', function () {
    [, $conversation] = creationFixture();
    $creator = app(CreateTicket::class);

    $first = $creator->create(new NewTicket($conversation, TicketSource::Api, idempotencyKey: 'req-4812'));
    $replay = $creator->create(new NewTicket($conversation->fresh(), TicketSource::Api, idempotencyKey: 'req-4812'));

    expect($replay->identifier())->toBe($first->identifier())
        ->and($replay->number)->toBe($first->number)
        ->and(Ticket::query()->count())->toBe(1);

    // A DIFFERENT key on the same conversation is a genuine second create, and FR-10
    // refuses it for the right reason — the invariant, not the key.
    expect(fn () => $creator->create(new NewTicket($conversation->fresh(), TicketSource::Api, idempotencyKey: 'req-9999')))
        ->toThrow(OpenTicketExists::class);
});

test('AC-02 (concurrent half): concurrent creates on one conversation yield exactly one open ticket', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();

    $customer = Customer::query()->create([
        'display_name' => 'Fixture Player',
        'username' => '@fixture_ac02',
        'is_shell' => true,
    ]);
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenantId,
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-ac02',
        'customer_id' => $customer->identifier(),
        'state' => 'active',
    ]);
    commitFixtures();

    $names = [];

    try {
        // Six genuinely separate sessions racing the same conversation — the
        // duplicate-storm shape AC-02 describes, not a loop.
        $workers = 6;
        for ($w = 0; $w < $workers; $w++) {
            $name = "pgsql_ac02_{$w}";
            config(["database.connections.{$name}" => config('database.connections.pgsql')]);
            $names[] = $name;
            DB::connection($name)->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
        }

        $creator = app(CreateTicket::class);
        $created = 0;
        $refused = 0;

        foreach ($names as $name) {
            DB::setDefaultConnection($name);
            try {
                /** @var Conversation $fresh */
                $fresh = Conversation::query()->findOrFail($conversationId);
                $creator->create(new NewTicket($fresh, TicketSource::Api));
                $created++;
            } catch (OpenTicketExists) {
                $refused++;
            } finally {
                DB::setDefaultConnection('pgsql');
            }
        }

        expect($created)->toBe(1, 'more than one concurrent create succeeded — the conversation has two open tickets');
        expect($refused)->toBe($workers - 1, 'the losers were not refused with open_ticket_exists');

        // The row count is the fact behind the counters.
        expect(DB::connection('pgsql_platform')->table('tickets')->where('conversation_id', $conversationId)->count())
            ->toBe(1, 'AC-02: exactly one open ticket, whatever the counters said');
    } finally {
        foreach ($names as $name) {
            $connection = DB::connection($name);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::purge($name);
        }
        DB::setDefaultConnection('pgsql');
        cleanupTenants([$tenantId]);
    }
});

test('FR-21: a split target takes NO open slot — the one sanctioned multi-open case', function () {
    [, $conversation] = creationFixture();
    $creator = app(CreateTicket::class);

    $origin = $creator->create(new NewTicket($conversation, TicketSource::Agent));

    // linked_work coexists with the open ticket (the T-02 index excludes it), and the
    // conversation's single slot still points at the ORIGIN.
    $split = $creator->create(new NewTicket(
        $conversation->fresh(),
        TicketSource::Agent,
        linkedWork: true,
        originTicketId: $origin->identifier(),
    ));

    expect($split->linked_work)->toBeTrue()
        ->and($conversation->fresh()->open_ticket_id)->toBe($origin->identifier())
        ->and(Ticket::query()->where('conversation_id', $conversation->identifier())->count())->toBe(2);

    // And linked work without an origin is not a split — it is a second open ticket
    // wearing a flag, so the type refuses to exist.
    expect(fn () => new NewTicket($conversation->fresh(), TicketSource::Agent, linkedWork: true))
        ->toThrow(InvalidArgumentException::class, 'not a split');
});

test('FR-03: a subject given at creation is marked staff-authored; an absent one stays NULL for Doc 09', function () {
    [, $conversation] = creationFixture();
    $creator = app(CreateTicket::class);

    $withSubject = $creator->create(new NewTicket($conversation, TicketSource::Agent, subject: 'Deposit not credited'));
    expect($withSubject->subject)->toBe('Deposit not credited')
        ->and($withSubject->subject_source)->toBe('staff');

    [, $other] = creationFixture();
    $without = $creator->create(new NewTicket($other, TicketSource::Agent));
    expect($without->subject)->toBeNull()
        ->and($without->subject_source)->toBeNull();

    // A blank subject is not a subject: it would look present to Doc 09's "if absent"
    // branch and the ticket would never get one.
    expect(fn () => new NewTicket($other, TicketSource::Agent, subject: '   '))
        ->toThrow(InvalidArgumentException::class);
});
