<?php

declare(strict_types=1);

use App\Domain\Tickets\Creation\CreateFromHandoff;
use App\Domain\Tickets\Creation\HandoffPacket;
use App\Domain\Tickets\Transitions\TicketStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-11 / T-05 / SEC-02 / AC-08 — the handoff packet
|--------------------------------------------------------------------------
|
| "AC-08 Handoff packet: created from a Doc 09 fixture, all fields rendered, immutability
| enforced at DB."
|
| Doc 09 does not exist, so the packet is driven from fixtures/tickets/handoff/
| doc09-packet.json — the contract Doc 09's real packet will have to satisfy. That is
| the pre-agreed shape for this bucket: the SEAM is DONE-able here, the live caller is
| Doc 09's and the rendering is Doc 07's.
|
| "All fields rendered" is the half this session can only take so far: every field is
| PERSISTED and readable, and the rendering is Doc 07's. The audit row says so rather
| than claiming a screen exists.
|
| IMMUTABILITY IS ASSERTED AT THE DATABASE, not through the class. A test that only
| proved "the service does not offer an update method" would pass just as happily
| against a table anyone can UPDATE — SEC-02 wants the grant, so the test attempts the
| write and requires Postgres to refuse it.
*/

/** @return array{0: App\Models\Tenant, 1: App\Models\Conversation, 2: array<string, mixed>} */
function handoffFixture(): array
{
    [$tenant, $conversation] = creationFixture();

    /** @var array<string, mixed> $packet */
    $packet = json_decode(
        (string) file_get_contents(base_path('fixtures/tickets/handoff/doc09-packet.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    // The fixture ships placeholder anchor ids because message ids are per-run. Doc 09
    // will supply real ones; the harness substitutes this run's.
    $packet['transcript_anchor'] = [
        'from_message_id' => (string) Str::uuid7(),
        'to_message_id' => (string) Str::uuid7(),
    ];

    return [$tenant, $conversation, $packet];
}

test('AC-08: a ticket is created from the Doc 09 fixture with the packet pinned to it', function () {
    [, $conversation, $data] = handoffFixture();
    $packet = HandoffPacket::fromArray($data);

    $ticket = app(CreateFromHandoff::class)->create($conversation, $packet);

    expect($ticket->status())->toBe(TicketStatus::New)
        ->and($ticket->source)->toBe('ai_handoff')
        // FR-11: the suggestion is APPLIED as the ticket's starting point…
        ->and($ticket->priority)->toBe('high')
        // …and FR-03 leaves the subject for Doc 09 rather than promoting the summary.
        ->and($ticket->subject)->toBeNull();

    $row = DB::table('ticket_handoff_packets')->where('ticket_id', $ticket->identifier())->first();
    expect($row)->not->toBeNull('the packet was not pinned to the ticket (FR-11/T-05)');

    /** @var array<string, mixed> $stored */
    $stored = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

    // EVERY field of FR-11's normative contract survives the round trip. Listed one by
    // one on purpose: a loop over array_keys would pass if the contract silently shrank.
    expect($stored['summary'])->toBe($data['summary'])
        ->and($stored['intent'])->toBe('deposit_not_credited')
        ->and($stored['confidence'])->toBe(0.87)
        ->and($stored['language'])->toBe('am')
        ->and($stored['sentiment'])->toBe('neutral')
        ->and($stored['urgency'])->toBe('high')
        ->and($stored['suggested']['department'])->toBeNull()
        ->and($stored['suggested']['priority'])->toBe('high')
        ->and($stored['suggested']['reasons'])->toHaveCount(2)
        ->and($stored['kb_shown'])->toBe(['kb-deposit-timing-local-agents', 'kb-what-to-do-if-a-deposit-is-late'])
        // Compared field by field, not as an array: Postgres jsonb normalises key
        // ORDER, so a whole-array identity check was asserting a property jsonb never
        // promised (it failed on the first run for exactly that reason). The contract
        // is the two ids, and that is what is checked.
        ->and($stored['transcript_anchor']['from_message_id'])->toBe($data['transcript_anchor']['from_message_id'])
        ->and($stored['transcript_anchor']['to_message_id'])->toBe($data['transcript_anchor']['to_message_id'])
        ->and($stored['form_data'])->toBeNull()
        ->and((int) $row->schema_version)->toBe(1);   // NFR-06's versioning
});

test('AC-08/SEC-02: the packet is immutable at the DATABASE — no UPDATE, no DELETE, for the app role', function () {
    [, $conversation, $data] = handoffFixture();
    $ticket = app(CreateFromHandoff::class)->create($conversation, HandoffPacket::fromArray($data));

    // Attempted through raw SQL, deliberately: the guarantee must not depend on the
    // domain class declining to offer a method.
    expect(fn () => DB::transaction(fn () => DB::statement(
        "UPDATE ticket_handoff_packets SET payload = '{}'::jsonb WHERE ticket_id = ?",
        [$ticket->identifier()],
    )))->toThrow(QueryException::class, 'permission denied');

    expect(fn () => DB::transaction(fn () => DB::statement(
        'DELETE FROM ticket_handoff_packets WHERE ticket_id = ?',
        [$ticket->identifier()],
    )))->toThrow(QueryException::class, 'permission denied');

    // T-05: one packet per ticket, ever — a second insert dies on the unique index.
    expect(fn () => DB::transaction(fn () => DB::table('ticket_handoff_packets')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $ticket->getAttribute('tenant_id'),
        'ticket_id' => $ticket->identifier(),
        'payload' => '{}',
        'schema_version' => 1,
    ])))->toThrow(QueryException::class);
});

test('BR-02: the transcript anchor is a LINK — no message is moved, copied or re-parented', function () {
    [, $conversation, $data] = handoffFixture();
    $ticket = app(CreateFromHandoff::class)->create($conversation, HandoffPacket::fromArray($data));

    $anchor = DB::table('ticket_message_anchors')
        ->where('ticket_id', $ticket->identifier())
        ->where('kind', 'transcript_anchor')
        ->first();

    expect($anchor)->not->toBeNull()
        ->and($anchor->conversation_id)->toBe($conversation->identifier())
        ->and($anchor->from_message_id)->toBe($data['transcript_anchor']['from_message_id'])
        ->and($anchor->to_message_id)->toBe($data['transcript_anchor']['to_message_id']);
});

test('FR-11: the normative contract is ENFORCED before the immutable insert, not trusted', function () {
    [, , $data] = handoffFixture();

    // Because the row can never be corrected (SEC-02), everything checkable is checked
    // once, up front. Each case below is a clause of FR-11's own sentence.
    // SHALLOW replace, deliberately: array_replace_recursive MERGES nested arrays, so
    // an override of transcript_anchor kept the original's to_message_id and the case
    // that was supposed to fail validation quietly passed it. Caught on the first run
    // by 'Exception not thrown' — the test was wrong, not the contract.
    $with = function (array $overrides) use ($data): callable {
        return fn () => HandoffPacket::fromArray(array_replace($data, $overrides));
    };

    // "summary (2–4 sentences)"
    expect($with(['summary' => 'One sentence only.']))->toThrow(InvalidArgumentException::class, '2-4 sentences');
    expect($with(['summary' => 'A. B. C. D. E.']))->toThrow(InvalidArgumentException::class, '2-4 sentences');

    // "intent+confidence" — a confidence is a probability
    expect($with(['confidence' => 1.4]))->toThrow(InvalidArgumentException::class, 'probability');

    // "suggested {department, priority}" — the four of FR-17, no others
    expect($with(['suggested' => ['priority' => 'critical']]))->toThrow(InvalidArgumentException::class, 'priority');

    // "transcript_anchor (message id range)"
    expect($with(['transcript_anchor' => ['from_message_id' => 'x']]))->toThrow(InvalidArgumentException::class, 'to_message_id');

    // "kb_shown[] (article ids)"
    expect($with(['kb_shown' => ['']]))->toThrow(InvalidArgumentException::class, 'article');

    // A missing top-level key is named rather than defaulted.
    $missing = $data;
    unset($missing['sentiment']);
    expect(fn () => HandoffPacket::fromArray($missing))->toThrow(InvalidArgumentException::class, 'sentiment');
});

test('FR-11: the packet keeps its OWN copy of the suggestion, so an override stays visible', function () {
    // §8 renders "suggested routing with applied/overridden indicator", which is only
    // possible because the packet cannot be edited: the ticket may move on, the packet
    // still says what the AI suggested.
    [, $conversation, $data] = handoffFixture();
    $ticket = app(CreateFromHandoff::class)->create($conversation, HandoffPacket::fromArray($data));

    DB::table('tickets')->where('id', $ticket->identifier())->update(['priority' => 'low']);

    $stored = json_decode(
        (string) DB::table('ticket_handoff_packets')->where('ticket_id', $ticket->identifier())->value('payload'),
        true, 512, JSON_THROW_ON_ERROR,
    );

    expect($ticket->fresh()->priority)->toBe('low')
        ->and($stored['suggested']['priority'])->toBe('high',
            'the packet followed the ticket — Doc 07 can no longer show that the suggestion was overridden');
});
