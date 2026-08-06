<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Models\Customer;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| S21 STEP 0 — what a "body-order" violation in the AC-03 soak actually is
|--------------------------------------------------------------------------
|
| BACKGROUND. The AC-03 soak (LaneOrderingSoakTest) counts two kinds of
| violation. `receipt-order` means the adapter received a lane's messages out
| of order — a real FIFO defect. `body-order` means something earlier: the
| STORED THREAD, read in FR-07 display order (created_at, id), is not in the
| order the messages were composed. Run soak-10 went red with
| violations=3 kinds={"body-order":3,"receipt-order":0} on a fully drained
| soak (trueSent=10000, nullExternalId=0) — so it is NOT the NULL-external-id
| artefact that 0a root-caused, and it is NOT a lane-ordering failure. It is
| the ordering KEY itself.
|
| THIS FILE IS THE INSTRUMENT CHECK. It answers one question with controls
| rather than argument: can the (created_at, id) key disagree with compose
| order, and if so exactly what makes it?
|
| The two facts it pins down:
|
|   1. `created_at` is written by the APP CLOCK at WHOLE-SECOND precision.
|      Eloquent formats timestamps with the Postgres grammar's 'Y-m-d H:i:s',
|      so the microseconds the column can hold (timestamptz, DEFAULT now())
|      are truncated on the way in. The migration's own DB-time default is
|      overwritten by an app-server wall clock on every insert.
|
|   2. Because of (1), display order is decided by `id` only when two rows
|      land in the same second. UUIDv7 is monotonic per process, so ties are
|      resolved correctly — the key is safe as long as the clock only ever
|      moves FORWARD. If the app clock steps BACKWARD across a second
|      boundary, a later-composed message is stored with an EARLIER
|      created_at and sorts ahead of its predecessor. The thread is then
|      out of order on disk, in the index, and on the page.
|
| C-11 ("Ordering decisions use DB time or UUIDv7 monotonicity — NEVER
| app-server wall clocks") is the convention this violates. The positive
| control below is the proof that the violation is reachable, not theoretical.
|
| STATUS: this is a PROBE, not a regression test. It asserts what the code
| does TODAY so the behaviour is recorded and reproducible. When the ordering
| key is fixed, the positive control here MUST start failing — that is the
| tripwire, and it is intended.
*/

/**
 * Register the fake channel and open one conversation to compose into.
 *
 * @return array{0: ComposeOutbound, 1: App\Models\Conversation}
 */
function probeThread(string $chatId): array
{
    $adapter = new FakeChannelAdapter(new Capabilities(
        maxText: 4096,
        mediaTypes: [],
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

    $customer = Customer::query()->create([
        'display_name' => 'Fixture', 'username' => '@fixture_orderkey', 'is_shell' => true,
    ]);

    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', $chatId, $customer->identifier());

    return [app(ComposeOutbound::class), $conversation];
}

/**
 * Compose one message with the app clock pinned to $at, then rebind tenancy —
 * the sync dispatch inside compose runs the job middleware, whose finally
 * clears both tenancy layers (the house pattern, see OutboundPipelineTest).
 */
function composeAtClock(ComposeOutbound $compose, App\Models\Conversation $conversation, string $tenantId, CarbonImmutable $at, string $body): void
{
    Carbon::setTestNow($at);
    CarbonImmutable::setTestNow($at);

    $compose->compose($conversation, 'agent', null, 'text', $body);

    bindTenant($tenantId);
}

/** The stored thread in FR-07 display order, with created_at at full DB precision. */
function storedThread(App\Models\Conversation $conversation): array
{
    return Message::query()
        ->where('conversation_id', $conversation->identifier())
        ->where('direction', 'out')
        ->orderBy('created_at')->orderBy('id')
        ->selectRaw('body, id, created_at::text as ts')
        ->get()
        ->map(fn ($r): array => ['body' => (string) $r->body, 'ts' => (string) $r->ts, 'id' => (string) $r->id])
        ->all();
}

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('FACT 1: created_at is written by the app clock and truncated to whole seconds', function () {
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    $tenantId = $tenant->tenantId();

    [$compose, $conversation] = probeThread('probe-precision');

    // A moment with a deliberately non-zero microsecond component.
    $at = CarbonImmutable::parse('2026-01-01 00:00:10.123456', 'UTC');
    composeAtClock($compose, $conversation, $tenantId, $at, 'only-message');

    $rows = storedThread($conversation);

    fwrite(STDERR, sprintf(
        "\n[ORDERKEY PROBE] composed at : %s\n".
        "[ORDERKEY PROBE] stored ts    : %s\n",
        $at->format('Y-m-d H:i:s.u'),
        $rows[0]['ts'],
    ));

    // The microseconds the column CAN hold are gone: the app truncated them.
    expect($rows[0]['ts'])->toContain('00:00:10')
        ->and($rows[0]['ts'])->not->toContain('123456');
});

test('NEGATIVE CONTROL: a forward-only clock keeps the thread in compose order', function () {
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    $tenantId = $tenant->tenantId();

    [$compose, $conversation] = probeThread('probe-forward');

    // Deliberately straddles a second boundary, which is where the whole-second
    // truncation makes the key most fragile.
    $moments = [
        ['2026-01-01 00:00:10.900', 'a-first'],
        ['2026-01-01 00:00:11.050', 'b-second'],
        ['2026-01-01 00:00:11.100', 'c-third'],
        ['2026-01-01 00:00:11.400', 'd-fourth'],
    ];

    foreach ($moments as [$ts, $body]) {
        composeAtClock($compose, $conversation, $tenantId, CarbonImmutable::parse($ts, 'UTC'), $body);
    }

    $stored = array_column(storedThread($conversation), 'body');
    $composed = array_column($moments, 1);

    fwrite(STDERR, sprintf(
        "\n[ORDERKEY PROBE] forward clock — composed: %s\n".
        "[ORDERKEY PROBE] forward clock — stored  : %s\n",
        implode(',', $composed), implode(',', $stored),
    ));

    expect($stored)->toBe($composed);
});

test('THRESHOLD: a backstep SMALLER than the gap between two same-lane messages does not invert', function () {
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    $tenantId = $tenant->tenantId();

    [$compose, $conversation] = probeThread('probe-threshold');

    // This is the soak's own geometry, with the LARGEST backstep actually
    // measured on this host (run soak-12: maxBackstepMs=435.108). Two messages
    // of one lane are ~1 s apart because a round visits 50 lanes in between, so
    // a 435 ms backstep still leaves the pair moving FORWARD (1000 - 435 =
    // +565 ms) and the truncated second never decreases.
    //
    // This is why soak-12/13/14 recorded backsteps and still scored zero
    // violations. A body-order violation needs the backstep to EXCEED the
    // same-lane gap — which is what `laneBacksteps` in the soak now measures.
    $moments = [
        ['2026-01-01 00:00:10.200', 'lane-msg-1'],
        ['2026-01-01 00:00:11.200', 'lane-msg-2'],
        ['2026-01-01 00:00:11.765', 'lane-msg-3-after-435ms-backstep'],
    ];

    foreach ($moments as [$ts, $body]) {
        composeAtClock($compose, $conversation, $tenantId, CarbonImmutable::parse($ts, 'UTC'), $body);
    }

    $stored = array_column(storedThread($conversation), 'body');
    $composed = array_column($moments, 1);

    fwrite(STDERR, sprintf(
        "\n[ORDERKEY PROBE] 435ms backstep, 1s lane gap — composed: %s\n".
        "[ORDERKEY PROBE] 435ms backstep, 1s lane gap — stored  : %s\n",
        implode(',', $composed), implode(',', $stored),
    ));

    expect($stored)->toBe($composed);
});

test('POSITIVE CONTROL: a backward app-clock step inverts the (created_at, id) thread order', function () {
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    $tenantId = $tenant->tenantId();

    [$compose, $conversation] = probeThread('probe-backstep');

    // Composes 1-3 run forward across a second boundary. Then the clock steps
    // BACK by 200 ms — smaller than one second, and smaller than the gap
    // between two messages of the same lane in the soak — which is enough,
    // because the truncation to whole seconds turns a sub-second step into a
    // whole-second difference the moment it crosses a boundary.
    $moments = [
        ['2026-01-01 00:00:10.900', 'a-first'],
        ['2026-01-01 00:00:11.050', 'b-second'],
        ['2026-01-01 00:00:11.100', 'c-third'],
        ['2026-01-01 00:00:10.950', 'd-fourth-after-backstep'],
    ];

    foreach ($moments as [$ts, $body]) {
        composeAtClock($compose, $conversation, $tenantId, CarbonImmutable::parse($ts, 'UTC'), $body);
    }

    $rows = storedThread($conversation);
    $stored = array_column($rows, 'body');
    $composed = array_column($moments, 1);

    foreach ($rows as $i => $r) {
        fwrite(STDERR, sprintf("[ORDERKEY PROBE] slot %d  ts=%s  body=%s\n", $i, $r['ts'], $r['body']));
    }
    fwrite(STDERR, sprintf(
        "[ORDERKEY PROBE] back-step — composed: %s\n".
        "[ORDERKEY PROBE] back-step — stored  : %s\n".
        "[ORDERKEY PROBE] INVERTED? %s\n\n",
        implode(',', $composed), implode(',', $stored),
        var_export($stored !== $composed, true),
    ));

    // THE POSITIVE CONTROL. The soak's body-order check compares exactly these
    // two arrays. If this assertion passes, a backward clock step is a
    // sufficient cause for `body-order` violations — the instrument can see it.
    expect($stored)->not->toBe($composed, 'if this passes, the ordering key survived a backward clock step and body-order violations need another explanation');

    // And name the shape precisely: the last-composed message sorted early.
    expect($stored[1])->toBe('d-fourth-after-backstep');
});
