<?php

declare(strict_types=1);

use App\Domain\Inbox\Health\ChannelHealthState;
use App\Events\Conversations\RealtimePush;
use App\Jobs\Inbox\HealthIndicatorConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| channel.health.changed v2's FIRST consumer (inherited row 5; S07 step 3):
| ConsumerRegistry entry, C-09 idempotency, the FS-02-style kill/re-drive
| catch-up drill, transition-only CONSUMED (A-22) — never re-derived.
|--------------------------------------------------------------------------
*/

/** A v2 event row, the catalog's closed payload — seeded at ITS schema_version. */
function healthSeedEvent(string $tenantId, string $status, string $ref = 'bot-fixture-1'): string
{
    $eventId = (string) Illuminate\Support\Str::uuid7();

    DB::table('events')->insert([
        'id' => $eventId,
        'tenant_id' => $tenantId,
        'name' => 'channel.health.changed',
        'schema_version' => 2,
        'payload' => json_encode([
            'tenant_id' => $tenantId, 'channel' => 'telegram',
            'channel_ref_id' => $ref, 'status' => $status,
        ], JSON_THROW_ON_ERROR),
    ]);

    return $eventId;
}

function runHealthConsumer(string $eventId): void
{
    (new HealthIndicatorConsumer($eventId))->handle(
        app(ChannelHealthState::class),
        app(App\Domain\Inbox\Realtime\ViewDeltaPusher::class),
    );
}

it('consumes transitions AS STATE — the payload word is stored verbatim per endpoint, pushed to the inbox channel, and a redelivery changes nothing (C-09)', function (): void {
    Event::fake([RealtimePush::class]);
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $state = app(ChannelHealthState::class);

    // The consumer's input really is the v2 tri-state event — seeded at its
    // catalog schema_version; the contract pin lives in doc07-events.json.
    $downEvent = healthSeedEvent($tenantId, 'down');
    expect((int) DB::table('events')->where('id', $downEvent)->value('schema_version'))->toBe(2);
    runHealthConsumer($downEvent);

    expect($state->all())->toBe(['telegram:bot-fixture-1' => 'down']);
    Event::assertDispatched(RealtimePush::class, function (RealtimePush $push): bool {
        return str_ends_with($push->channelName, '.inbox')
            && $push->eventName === 'inbox.channel_health'
            && $push->payload['status'] === 'down'
            && $push->payload['v'] === 1;
    });

    // C-09: the SAME event again — same state, no drift.
    runHealthConsumer($downEvent);
    expect($state->all())->toBe(['telegram:bot-fixture-1' => 'down']);

    // Recovery is a TRANSITION EVENT, consumed — never inferred: the store
    // holds exactly what the last transition said, per endpoint.
    runHealthConsumer(healthSeedEvent($tenantId, 'healthy'));
    runHealthConsumer(healthSeedEvent($tenantId, 'degraded', 'bot-fixture-2'));
    expect($state->all())->toBe([
        'telegram:bot-fixture-1' => 'healthy',
        'telegram:bot-fixture-2' => 'degraded',
    ]);
});

it('FS-02 drill: kill the consumer before any effect — no trace; re-drive lands the state exactly; a third delivery changes nothing', function (): void {
    Event::fake([RealtimePush::class]);
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $state = app(ChannelHealthState::class);
    $eventId = healthSeedEvent($tenantId, 'down');

    // ── THE KILL: on the event-row read itself (QueryExecuted fires
    // post-statement; the throw lands before the state write).
    $armed = true;
    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$armed): void {
        if ($armed && str_contains($query->sql, '"events"') && str_contains($query->sql, 'where "id"')) {
            throw new RuntimeException('drill: health consumer died mid-flight');
        }
    });

    try {
        runHealthConsumer($eventId);
        $this->fail('the kill hook did not fire — the drill proved nothing');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('drill: health consumer died');
    } finally {
        $armed = false;
    }

    expect($state->all())->toBe([], 'the killed attempt left state behind');
    Event::assertNotDispatched(RealtimePush::class);

    // ── RE-DRIVE, then a duplicate: exactly-once-in-effect.
    runHealthConsumer($eventId);
    runHealthConsumer($eventId);
    expect($state->all())->toBe(['telegram:bot-fixture-1' => 'down']);
});

it('is structurally transition-only: the consumer names no heartbeat, no poll, no timer — the A-22 semantics cannot be re-derived here', function (): void {
    // CODE only — the docblock legitimately NAMES heartbeats to forbid them,
    // so the scan must not read its own prohibition (comment-stripped).
    $code = '';
    foreach (token_get_all((string) file_get_contents(app_path('Jobs/Inbox/HealthIndicatorConsumer.php'))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    // Positive control: the scan still sees real code (the class name).
    expect(str_contains($code, 'HealthIndicatorConsumer'))->toBeTrue('the comment-strip destroyed the code — the scan below would be vacuous');

    foreach (['getWebhookInfo', 'heartbeat', 'everyFiveMinutes', 'poll('] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse(
            "the health consumer's CODE references '{$forbidden}' — transitions are CONSUMED (A-22), never re-derived from liveness signals",
        );
    }
});
