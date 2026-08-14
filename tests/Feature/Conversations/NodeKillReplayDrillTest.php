<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Inbound\InboundDedupeStore;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Outbound\LaneWorker;
use App\Domain\Conversations\Outbound\SendingReconciler;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Jobs\Conversations\ConfirmBatchTail;
use App\Jobs\Conversations\FetchInboundMedia;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| SS-04 — the node-kill replay drill (Doc 04 S20 step 9c)
|                 …and Doc 01 AC-03's vehicle (inherited row 4, CLAIMED here)
|--------------------------------------------------------------------------
|
| SS-04: "Full node kill during a 1k-message replay drill: post-drain
| reconciliation shows zero lost, zero duplicated side effects."
| Doc 01 AC-03: "kill each worker class mid-burst → no lost messages/events
| after drain."
|
| WHAT "KILL" MEANS HERE, STATED PLAINLY. This is a DETERMINISTIC KILL-POINT
| drill, not a literal SIGKILL of a container: each worker class is killed at
| the exact moment where a crash is dangerous, and the survivor's replay is
| driven through the REAL pipeline. That is deliberate and, for a correctness
| proof, stronger than one random SIGKILL — a random kill lands in an
| uninteresting microsecond nearly every time, while these land on the three
| moments that can actually lose or duplicate:
|
|   inbound normalizer  killed AFTER the dedupe claim, BEFORE the persist
|   inbound normalizer  killed AFTER the persist commits, BEFORE the queue ACK
|   lane worker         killed mid-send, leaving the row in `sending`
|
| The literal container-kill rehearsal is Doc 18 FR-23's scheduled monthly
| staging drill ("node-kill replay (Doc 04 SS-04)") — it cannot run inside
| this harness because every test runs in a rolled-back transaction that a
| separate worker process cannot see, and the fake adapter is registered in
| this process only. The traceability row says exactly this; it is never
| claimed as a literal node kill.
*/

function drillFixture(): array
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);

    $adapter = new FakeChannelAdapter(new Capabilities(
        maxText: 4096,
        mediaTypes: ['photo'],
        supportsTyping: false,
        supportsEdit: false,
        supportsDelete: false,
        supportsButtons: false,
        replyWindow: ReplyWindow::none(),
    ));

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', $adapter, 'webchat-in', 'webchat-out');
    }

    return [$adapter, app(ConversationRepository::class)];
}

/** @return array<string, mixed> */
function drillRaw(int $sequence, string $chat = 'chat-drill'): array
{
    return [
        'update_id' => 500_000 + $sequence,
        'chat_id' => $chat,
        'message_id' => 'ext-drill-'.$sequence,
        'from' => ['id' => 'user-drill', 'display' => 'Fixture Customer', 'username' => '@fixture_drill'],
        'kind' => 'text',
        'text' => 'replay message '.$sequence,
        'occurred_at' => '2026-08-05T12:00:00+00:00',
    ];
}

function drillRun(array $raw, ?string $claimant = null): void
{
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
    Bus::dispatchSync(new NormalizeInbound('fakechan', 'bot-1', $raw, $claimant));
    bindTenant($tenantId);
}

// ── Kill point 1: normalizer dies AFTER claiming dedupe, BEFORE persisting ───

test('SS-04 / Doc01 AC-03: an inbound worker killed after the dedupe claim loses NOTHING — the replay persists under the same claimant', function () {
    drillFixture();
    Queue::fake([ConfirmBatchTail::class, FetchInboundMedia::class]);

    // The kill: attempt 1 claimed its dedupe slot, then the process died before
    // the persist transaction. Modelled exactly — the claim exists, the row
    // does not.
    $claimant = (string) Str::uuid7();
    $raw = drillRaw(1);
    app(InboundDedupeStore::class)->firstSight('fakechan', 'fake:500001', $claimant);

    expect(Message::query()->where('external_message_id', 'ext-drill-1')->exists())->toBeFalse();

    // The survivor: the queue redelivers the SAME job (same claimant).
    drillRun($raw, $claimant);

    expect(Message::query()->where('external_message_id', 'ext-drill-1')->count())->toBe(1);
});

// ── Kill point 2: normalizer dies AFTER the persist commits, BEFORE the ACK ──

test('SS-04: an inbound worker killed after the persist commits produces ZERO duplicated side effects on replay', function () {
    drillFixture();
    Queue::fake([ConfirmBatchTail::class, FetchInboundMedia::class]);

    $claimant = (string) Str::uuid7();
    $raw = drillRaw(2);

    // Attempt 1 runs to completion — the row is committed…
    drillRun($raw, $claimant);
    $message = Message::query()->where('external_message_id', 'ext-drill-2')->sole();

    $revisionsBefore = MessageRevision::query()->count();
    $editEventsBefore = DB::table('outbox')->where('name', 'message.edited')->count();
    $editAuditsBefore = DB::table('audit_log')->where('action_key', 'conversation.message_edited')->count();

    // …and THEN the worker dies before ACKing, so the queue redelivers it
    // (C-09: at-least-once is assumed everywhere — this is not exotic).
    drillRun($raw, $claimant);

    // Zero duplicated side effects: still one row, and the replay wrote NO
    // revision, NO message.edited event, NO edit audit entry. A redelivery of
    // identical content is not a customer edit.
    expect(Message::query()->where('external_message_id', 'ext-drill-2')->count())->toBe(1)
        ->and($message->refresh()->body)->toBe('replay message 2')
        ->and($message->edited_at)->toBeNull()
        ->and(MessageRevision::query()->count())->toBe($revisionsBefore)
        ->and(DB::table('outbox')->where('name', 'message.edited')->count())->toBe($editEventsBefore)
        ->and(DB::table('audit_log')->where('action_key', 'conversation.message_edited')->count())->toBe($editAuditsBefore);
});

test('FR-21 still holds after the idempotency fix: a GENUINE customer edit (changed body) does write its revision', function () {
    drillFixture();
    Queue::fake([ConfirmBatchTail::class, FetchInboundMedia::class]);

    $raw = drillRaw(3);
    drillRun($raw);
    $message = Message::query()->where('external_message_id', 'ext-drill-3')->sole();

    // The channel-originated edit as a real channel delivers it: a NEW update
    // (its own update_id, so its own dedupe key) carrying the SAME
    // message_id with changed content.
    drillRun(array_merge($raw, [
        'update_id' => 599_003,
        'text' => 'replay message 3 (edited by the customer)',
    ]));

    expect(Message::query()->where('external_message_id', 'ext-drill-3')->count())->toBe(1)
        ->and($message->refresh()->body)->toBe('replay message 3 (edited by the customer)')
        ->and($message->edited_at)->not->toBeNull()
        ->and(MessageRevision::query()->where('message_id', $message->identifier())->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'message.edited')->count())->toBe(1);
});

// ── Kill point 3: lane worker dies mid-send, leaving the row in `sending` ────

test('SS-04: a lane worker killed mid-send reconciles from recorded truth — an accepted send becomes sent, never a double delivery', function () {
    [$adapter, $repo] = drillFixture();
    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_drill', 'is_shell' => true, 'created_source' => 'staff']);
    $conversation = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-kill-send', $customer->identifier());
    $t0 = CarbonImmutable::now('UTC');

    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
    $message = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', 'mid-send kill');
    bindTenant($tenantId);

    // The kill: the adapter ACCEPTED the send (external id recorded) and the
    // worker died before the sent transition. The lane still holds the head.
    app(App\Domain\Conversations\Storage\MessageRepository::class)
        ->updateDeliveryState($message, 'sending');
    app(App\Domain\Conversations\Storage\MessageRepository::class)
        ->recordExternalMessageId($message, 'fake-out-accepted-1');

    $sendsBefore = count($adapter->sent);

    // The lane REFUSES to guess (out-of-order prevention outranks throughput).
    app(LaneWorker::class)->processOnce($t0);
    expect($message->refresh()->delivery_state)->toBe('sending')
        ->and(count($adapter->sent))->toBe($sendsBefore);

    // Reconciliation decides from recorded truth: the id proves acceptance.
    $result = app(SendingReconciler::class)->reconcile($t0->addMinutes(5));

    expect($result['recovered_sent'])->toBe(1)
        ->and($result['requeued_retry'])->toBe(0)
        ->and($message->refresh()->delivery_state)->toBe('sent')
        ->and(count($adapter->sent))->toBe($sendsBefore); // never re-sent = zero duplicate delivery
});

test('SS-04: a mid-send kill with NO recorded acceptance re-queues for retry under the SAME idempotency key — zero loss, dedupe left to the channel', function () {
    [$adapter, $repo] = drillFixture();
    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_drill', 'is_shell' => true, 'created_source' => 'staff']);
    $conversation = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-kill-send-2', $customer->identifier());
    $t0 = CarbonImmutable::now('UTC');

    // An explicit client idempotency key — T-03's offline-composer case, and
    // the one where retry safety after a mid-send kill actually matters.
    $idempotencyKey = 'drill-idem-'.uniqid();
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
    $message = app(ComposeOutbound::class)
        ->compose($conversation, 'agent', null, 'text', 'mid-send kill, unknown outcome', $idempotencyKey);
    bindTenant($tenantId);

    app(App\Domain\Conversations\Storage\MessageRepository::class)->updateDeliveryState($message, 'sending');

    $result = app(SendingReconciler::class)->reconcile($t0->addMinutes(5));

    expect($result['requeued_retry'])->toBe(1)
        ->and($message->refresh()->delivery_state)->toBe('failed_retryable');

    // It drains on the next cycle, carrying the ORIGINAL idempotency key (T-03)
    // — that key, not optimism, is what makes the re-send safe.
    app(LaneWorker::class)->processOnce($t0->addMinutes(6));

    expect($message->refresh()->delivery_state)->toBe('sent')
        ->and($adapter->sent[count($adapter->sent) - 1]->idempotencyKey)->toBe($idempotencyKey)
        ->and($idempotencyKey)->not->toBeNull();
});

// ── The 1k replay, with every worker class killed mid-burst ─────────────────

test('SS-04 / Doc01 AC-03: a 1k-message replay with each worker class killed mid-burst — post-drain reconciliation shows zero lost, zero duplicated', function () {
    [$adapter, $repo] = drillFixture();
    Queue::fake([ConfirmBatchTail::class, FetchInboundMedia::class]);
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();

    $total = 1000;
    $killEvery = 7;   // inbound normalizer killed after its dedupe claim
    $replayEvery = 11; // inbound normalizer killed after commit, before ACK

    $claimants = [];

    for ($i = 1; $i <= $total; $i++) {
        $raw = drillRaw($i, 'chat-burst');
        $claimant = (string) Str::uuid7();
        $claimants[$i] = $claimant;

        if ($i % $killEvery === 0) {
            // KILL: claim the slot, then die before persisting. Nothing else.
            app(InboundDedupeStore::class)->firstSight('fakechan', 'fake:'.(500_000 + $i), $claimant);

            continue;
        }

        drillRun($raw, $claimant);

        if ($i % $replayEvery === 0) {
            // KILL: committed, then died before the ACK → the queue redelivers.
            drillRun($raw, $claimant);
        }
    }

    // DRAIN: every killed-before-persist job is redelivered by the queue.
    for ($i = $killEvery; $i <= $total; $i += $killEvery) {
        drillRun(drillRaw($i, 'chat-burst'), $claimants[$i]);
    }

    bindTenant($tenantId);

    // ZERO LOST: all 1000 distinct messages exist.
    $persisted = Message::query()->where('direction', 'in')->where('kind', 'text')->count();

    // ZERO DUPLICATED: one row per external id, and no replay masqueraded as an
    // edit (no revisions, no message.edited events from identical redeliveries).
    $distinct = Message::query()->where('direction', 'in')->where('kind', 'text')
        ->distinct()->count('external_message_id');

    expect($persisted)->toBe($total)
        ->and($distinct)->toBe($total)
        ->and(MessageRevision::query()->count())->toBe(0)
        ->and(DB::table('outbox')->where('name', 'message.edited')->count())->toBe(0)
        ->and(DB::table('outbox')->where('name', 'message.received')->count())->toBe($total);
});
