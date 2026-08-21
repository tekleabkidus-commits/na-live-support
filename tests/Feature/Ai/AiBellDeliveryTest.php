<?php

declare(strict_types=1);

use App\Domain\Ai\KillSwitch\KillSwitches;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Supervisor\AnomalyScan;
use App\Domain\Ai\Supervisor\DailyDigest;
use Illuminate\Support\Facades\DB;

/**
 * S09 packet 2 (countersigned): Doc 09's notification categories reach
 * Doc 07's bell through the ListProjector's three ai.* arms. Per arm: the
 * notification reaches EXACTLY its ruled recipients and never a
 * non-permitted holder; the actor never self-notifies; redelivery mints
 * nothing twice (C-09). The producers publish the amended FR-21 events
 * inside a transaction (T-03).
 */
function bellRows(string $category): array
{
    return DB::table('inbox_notifications')->where('category', $category)->orderBy('user_id')->pluck('user_id')->all();
}

function roleUser(string $name, array $permissions): App\Models\User
{
    return grantPermissions(App\Models\User::factory()->create(), $permissions, $name);
}

it('ai.killswitch.changed → every live ai.killswitch holder EXCEPT the actor; an agent without the key gets nothing; redelivery mints nothing twice', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $admin = roleUser('S09 Bell Admin', ['tickets.view', 'ai.killswitch']);
    $otherAdmin = roleUser('S09 Bell Admin 2', ['tickets.view', 'ai.killswitch']);
    $agent = roleUser('S09 Bell Agent', ['tickets.view', 'ai.copilot.use']);
    $disabled = roleUser('S09 Bell Disabled', ['tickets.view', 'ai.killswitch']);
    $disabled->forceFill(['disabled_at' => now()])->save();

    app(KillSwitches::class)->flip('chatbot', 'telegram', null, true, '[FIXTURE] incident', $admin->identifier());
    $eventId = relayOutboxRow('ai.killswitch.changed');
    runListProjector($eventId);

    $expected = [$otherAdmin->identifier()];
    sort($expected);
    expect(bellRows('notify.ai.killswitch_changed'))->toBe($expected, 'exactly the OTHER live holder — not the actor, not the agent, not the disabled admin');
    $payload = json_decode((string) DB::table('inbox_notifications')->where('category', 'notify.ai.killswitch_changed')->value('payload'), true);
    expect($payload)->toMatchArray(['surface' => 'chatbot', 'channel' => 'telegram', 'is_off' => true])->and(json_encode($payload))->not->toContain('incident');

    runListProjector($eventId);
    expect(bellRows('notify.ai.killswitch_changed'))->toHaveCount(1);
});

it('ai.anomaly.detected → every live reports.supervisor.live holder, one row per alert event; nobody else', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = supervisorScene();
    try {
        $supervisor = roleUser('S09 Bell Supervisor', ['tickets.view', 'reports.supervisor.live']);
        $agent = roleUser('S09 Bell Agent B', ['tickets.view', 'ai.copilot.use']);

        // A last-full-hour spike with no baseline → an intent_new alert (kind=intent).
        for ($i = 0; $i < 6; $i++) {
            classifyRow($tenantId, $conversation->identifier(), 'withdrawals', -1, "date_trunc('hour', clock_timestamp()) - interval '30 minutes'");
        }
        $scan = app(AnomalyScan::class)->run();
        expect(count($scan['alerts']))->toBeGreaterThanOrEqual(1);

        $events = DB::table('outbox')->where('name', 'ai.anomaly.detected')->get(['event_id', 'tenant_id', 'name', 'payload', 'schema_version']);
        expect($events)->toHaveCount(count($scan['alerts']));
        foreach ($events as $event) {
            DB::table('events')->insertOrIgnore(['id' => $event->event_id, 'tenant_id' => $event->tenant_id, 'name' => $event->name, 'schema_version' => $event->schema_version, 'payload' => $event->payload]);
            runListProjector($event->event_id);
            $payload = json_decode((string) $event->payload, true);
            expect($payload)->toHaveKeys(['tenant_id', 'decision_id', 'kind', 'intent'])->and($payload['decision_id'])->toBe($scan['decision_id']);
        }
        expect(array_unique(bellRows('notify.ai.anomaly')))->toBe([$supervisor->identifier()])
            ->and(count(bellRows('notify.ai.anomaly')))->toBe(count($scan['alerts']));
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('ai.digest.ready → its ONE recipient and only while they still hold the key; another supervisor gets nothing', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $manager = roleUser('S09 Bell Manager', ['tickets.view', 'reports.supervisor.live']);
    $otherManager = roleUser('S09 Bell Manager 2', ['tickets.view', 'reports.supervisor.live']);
    copilotDriver(new ProviderResponse("- a\n- b", 1, 1), new ProviderResponse("- a\n- b", 1, 1)); // template fallback path; delivery is what this proves

    $digest = app(DailyDigest::class)->forRecipient($manager);
    $eventId = relayOutboxRow('ai.digest.ready');
    expect(json_decode((string) DB::table('events')->where('id', $eventId)->value('payload'), true))->toMatchArray(['recipient_id' => $manager->identifier(), 'decision_id' => $digest['decision_id']]);
    runListProjector($eventId);
    expect(bellRows('notify.ai.digest'))->toBe([$manager->identifier()]);

    // The recipient loses the key before delivery → nothing minted.
    grantPermissions($otherManager, ['tickets.view'], 'S09 Bell Demoted');
    copilotDriver(new ProviderResponse('- a', 1, 1), new ProviderResponse('- a', 1, 1));
    app(DailyDigest::class)->forRecipient($otherManager);
    runListProjector(relayOutboxRow('ai.digest.ready'));
    expect(bellRows('notify.ai.digest'))->toBe([$manager->identifier()]);
});

it('ai.provider.health_changed is DELIBERATELY not projected into the bell (Doc 07\'s banner reads it live)', function (): void {
    expect(App\Support\Events\ConsumerRegistry::consumersFor('ai.provider.health_changed'))->not->toContain(App\Jobs\Inbox\ListProjectorConsumer::class);
});
