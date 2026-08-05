<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Inbound\DeadLetterNotParked;
use App\Domain\Conversations\Inbound\DeadLetters;
use App\Domain\Conversations\Inbound\InboundDedupeStore;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\Message;
use App\Models\MessageDeadLetter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| FS-05 — dead-letter parking + ops re-drive (Doc 04 S20 step 9b; R-17)
|--------------------------------------------------------------------------
|
| A poison inbound (normalizer crash loop) gets ×3 attempts, then parks in
| message_dead_letters with its FULL payload; the ops view lists parked items
| and re-drives them. The re-drive carries the PARKED job's dedupe claimant —
| the trap InboundDedupeStore's docblock records: without it, a poison that
| died after claiming its slot re-drives into a silent duplicate-drop.
|
| The ×3 through a REAL worker is exercised by the SS-04 drill (step 9c); here
| the tries contract is pinned and the parking path is driven directly, the
| way the worker drives it.
*/

function deadLetterFixture(): Tenant
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'voice'],
            supportsTyping: true,
            supportsEdit: true,
            supportsDelete: true,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    return $tenant;
}

/** @return array<string, mixed> */
function poisonRaw(): array
{
    // Missing update_id/chat_id/…: the fake adapter's normalizer throws — the
    // FS-05 crash-loop shape (a payload the current normalizer cannot survive).
    return ['poison' => true, 'note' => 'synthetic poison fixture'];
}

/** @return array<string, mixed> */
function healthyRaw(int $updateId): array
{
    return [
        'update_id' => $updateId,
        'chat_id' => 'chat-900',
        'message_id' => 'ext-dl-'.$updateId,
        'from' => ['id' => 'user-9', 'display' => 'Fixture Customer', 'username' => '@fixture_deadletter'],
        'kind' => 'text',
        'text' => 'healthy message '.$updateId,
        'occurred_at' => '2026-08-05T10:00:00+00:00',
    ];
}

/** Drive a poison payload the way the worker does: handle throws, failed() parks. */
function parkPoison(array $raw, ?string $claimant = null): MessageDeadLetter
{
    $context = app(App\Support\Tenancy\TenantContext::class);
    $tenantId = $context->tenantId();

    $job = new NormalizeInbound('fakechan', 'bot-1', $raw, $claimant);

    $caught = null;
    try {
        Bus::dispatchSync($job);
    } catch (Throwable $error) {
        $caught = $error;
    }

    expect($caught)->not->toBeNull();

    // The sync driver already invoked failed() when handle threw — after the
    // job middleware's finally cleared the context, exactly the state the real
    // worker's final-attempt call arrives in. (Calling failed() again here
    // would park a second row; this test caught that on its first run.)

    bindTenant($tenantId);

    /** @var MessageDeadLetter $letter */
    $letter = MessageDeadLetter::query()->orderByDesc('id')->firstOrFail();

    return $letter;
}

// ── The ×3 contract and the park ─────────────────────────────────────────────

test('FS-05: tries is exactly 3 — the crash-loop budget is a frozen contract', function () {
    expect((new NormalizeInbound('fakechan', 'bot-1', []))->tries)->toBe(3);
});

test('FS-05: a poison message parks with its FULL payload, error, and audit entry — and is never lost', function () {
    deadLetterFixture();

    $letter = parkPoison(poisonRaw());

    expect($letter->status)->toBe(MessageDeadLetter::STATUS_PARKED)
        ->and($letter->payload)->toEqual(poisonRaw())           // the FULL payload (jsonb reorders keys, never values)
        ->and($letter->channel)->toBe('fakechan')
        ->and($letter->channel_ref_id)->toBe('bot-1')
        ->and($letter->inbound_family)->toBe('webchat-in')   // captured at park; re-drive needs no live registry
        ->and($letter->error_class)->toBe(InvalidArgumentException::class)
        ->and($letter->error_message)->toContain('update_id')
        ->and(DB::table('audit_log')->where('action_key', 'conversation.message_parked')->count())->toBe(1);
});

test('FS-05: the conversation continues around a parked item — later inbound flows normally', function () {
    $tenant = deadLetterFixture();

    parkPoison(poisonRaw());

    Queue::fake([App\Jobs\Conversations\ConfirmBatchTail::class]);
    Bus::dispatchSync(new NormalizeInbound('fakechan', 'bot-1', healthyRaw(801)));
    bindTenant($tenant);

    expect(Message::query()->where('external_message_id', 'ext-dl-801')->exists())->toBeTrue()
        ->and(MessageDeadLetter::query()->where('status', MessageDeadLetter::STATUS_PARKED)->count())->toBe(1);
});

test('FR-03f: a job with no tenant does not park — the framework failed-jobs row is the record', function () {
    deadLetterFixture();

    $job = new NormalizeInbound('fakechan', 'bot-1', poisonRaw());
    $job->tenantId = null;

    $job->failed(new RuntimeException('dispatcher bug'));

    bindTenant(Tenant::query()->firstOrFail()->identifier());
    expect(MessageDeadLetter::query()->count())->toBe(0);
});

// ── The re-drive, and the dedupe-claimant trap ───────────────────────────────

test('FS-05: re-drive flips the row (audited, DB time) and dispatches onto the inbound family with the PARKED claimant', function () {
    deadLetterFixture();
    $operator = grantPermissions(User::factory()->create(), ['conversations.view', 'tickets.reply'], 'DL Operator Role');

    $claimant = (string) Str::uuid7();
    $letter = parkPoison(poisonRaw(), $claimant);
    expect($letter->claimant)->toBe($claimant);

    Queue::fake();

    $redriven = app(DeadLetters::class)->redrive($letter->identifier(), $operator);

    expect($redriven->status)->toBe(MessageDeadLetter::STATUS_REDRIVEN)
        ->and($redriven->redriven_by)->toBe($operator->identifier())
        ->and($redriven->redriven_at)->not->toBeNull()
        ->and(DB::table('audit_log')->where('action_key', 'conversation.message_redriven')->count())->toBe(1);

    Queue::assertPushedOn('webchat-in', NormalizeInbound::class, function (NormalizeInbound $job) use ($claimant): bool {
        // jsonb reorders keys, never values — canonicalize before comparing.
        $expected = poisonRaw();
        $actual = $job->raw;
        ksort($expected);
        ksort($actual);

        return $job->claimantOverride === $claimant && $actual === $expected;
    });
});

test('FS-05/EC-03: a re-driven message SURVIVES a pre-claimed dedupe slot — and the fresh-claimant control proves the trap is real', function () {
    deadLetterFixture();

    // The poison died AFTER claiming its dedupe slot (attempt 1 claimed, the
    // crash came later): the slot is held under the parked claimant.
    $claimant = (string) Str::uuid7();
    $raw = healthyRaw(902);
    app(InboundDedupeStore::class)->firstSight('fakechan', 'fake:902', $claimant);

    // CONTROL — the trap is real: the same payload arriving as a NEW job (fresh
    // claimant, a genuine redelivery as far as dedupe can tell) drops silently.
    Queue::fake([App\Jobs\Conversations\ConfirmBatchTail::class]);
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
    Bus::dispatchSync(new NormalizeInbound('fakechan', 'bot-1', $raw));
    bindTenant($tenantId);
    expect(Message::query()->where('external_message_id', 'ext-dl-902')->exists())->toBeFalse();

    // THE MECHANISM — the re-drive carries the parked claimant, matches its own
    // slot, and the message persists. This assertion goes red if the claimant
    // pass-through is removed (the 9b sabotage target).
    Bus::dispatchSync(new NormalizeInbound('fakechan', 'bot-1', $raw, $claimant));
    bindTenant($tenantId);
    expect(Message::query()->where('external_message_id', 'ext-dl-902')->exists())->toBeTrue();
});

test('FS-05: a second re-drive refuses — dead_letter_not_parked, row history untouched', function () {
    deadLetterFixture();
    $operator = grantPermissions(User::factory()->create(), ['tickets.reply'], 'DL Operator Role');

    $letter = parkPoison(poisonRaw());

    Queue::fake();
    app(DeadLetters::class)->redrive($letter->identifier(), $operator);

    expect(fn () => app(DeadLetters::class)->redrive($letter->identifier(), $operator))
        ->toThrow(DeadLetterNotParked::class, 'dead_letter_not_parked');
});

// ── The ops surface: routes, gates, payload visibility ───────────────────────

test('A-6: the page is gated conversations.view; the re-drive is gated tickets.reply; the payload is visible in full', function () {
    deadLetterFixture();

    $letter = parkPoison(poisonRaw());

    $viewer = grantPermissions(User::factory()->create(), ['conversations.view'], 'DL Viewer Role');
    $outsider = grantPermissions(User::factory()->create(), ['tickets.view'], 'DL Outsider Role');
    $operator = grantPermissions(User::factory()->create(), ['conversations.view', 'tickets.reply'], 'DL Operator Role');

    // No conversations.view → no page — and the refusal is the PERMISSION's,
    // not a tenant-state accident (this distinction caught a fixture bug once).
    test()->actingAs($outsider, 'staff')->get('https://localhost/conversations/dead-letters')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    // conversations.view → the page, with the full payload in its props.
    test()->actingAs($viewer, 'staff')->get('https://localhost/conversations/dead-letters')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Conversations/DeadLetters', shouldExist: false)
            ->where('parked.0.id', $letter->identifier())
            ->where('parked.0.payload.poison', true)
            ->where('parked.0.payload.note', 'synthetic poison fixture')
            // The key contains a dot, so no dot-path: pull `can` whole.
            ->where('can', fn ($can) => collect($can)->get('tickets.reply') === false));

    // The viewer may LOOK but not re-drive (tickets.reply is the mutation gate).
    test()->actingAs($viewer, 'staff')
        ->postJson('https://localhost/api/staff/conversations/dead-letters/'.$letter->identifier().'/redrive')
        ->assertForbidden();

    // The operator re-drives; a second attempt answers 409 dead_letter_not_parked.
    Queue::fake();
    test()->actingAs($operator, 'staff')
        ->postJson('https://localhost/api/staff/conversations/dead-letters/'.$letter->identifier().'/redrive')
        ->assertOk();
    test()->actingAs($operator, 'staff')
        ->postJson('https://localhost/api/staff/conversations/dead-letters/'.$letter->identifier().'/redrive')
        ->assertStatus(409)
        ->assertJsonPath('code', 'dead_letter_not_parked');
});
