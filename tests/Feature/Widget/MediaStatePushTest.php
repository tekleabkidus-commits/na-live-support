<?php

declare(strict_types=1);

use App\Domain\Webchat\WebchatAdapter;
use App\Events\Conversations\RealtimePush;
use App\Jobs\Webchat\MediaStatePush;
use App\Support\Events\ConsumerRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 13 FR-16 / SEC-04 / D15 — Doc13/MediaStatePush (S13 step 9b): Doc 04's
| scan verdict wakes the visitor's thread through the visitor's PRIVATE thread
| channel; the frame carries no bytes, no key, no signature. A non-webchat
| conversation is ignored. Registered on both media events.
|--------------------------------------------------------------------------
*/

function mediaEventRow(string $name, array $payload): string
{
    $id = (string) Str::uuid7();
    DB::table('events')->insert(['id' => $id, 'tenant_id' => app(TenantContext::class)->tenantId(), 'name' => $name, 'schema_version' => 1, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

    return $id;
}

it('registers on media.stored and media.infected', function (): void {
    expect(ConsumerRegistry::consumersFor('media.stored'))->toContain(MediaStatePush::class)
        ->and(ConsumerRegistry::consumersFor('media.infected'))->toContain(MediaStatePush::class);
});

it('pushes a bytes-free wake-up to the webchat visitor\'s thread channel for clean and for blocked; ignores other channels', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    Event::fake([RealtimePush::class]);
    [, $conversation] = conversationFixture();
    $visitorId = 'v_'.Str::random(12);
    DB::table('conversations')->where('id', $conversation->identifier())->update(['channel' => WebchatAdapter::CHANNEL, 'external_chat_id' => $visitorId]);
    $messageId = (string) Str::uuid7();

    $clean = mediaEventRow('media.stored', ['tenant_id' => $tenant->identifier(), 'message_id' => $messageId, 'conversation_id' => $conversation->identifier(), 'index' => 0]);
    expect((new MediaStatePush($clean))->handle(app(App\Domain\Webchat\Realtime\WebchatPusher::class)))->toBe(1);
    $blocked = mediaEventRow('media.infected', ['tenant_id' => $tenant->identifier(), 'message_id' => $messageId, 'conversation_id' => $conversation->identifier(), 'index' => 0, 'signature' => 'Eicar-Test-Signature']);
    expect((new MediaStatePush($blocked))->handle(app(App\Domain\Webchat\Realtime\WebchatPusher::class)))->toBe(1);

    Event::assertDispatchedTimes(RealtimePush::class, 2);
    Event::assertDispatched(RealtimePush::class, function (RealtimePush $push) use ($tenant, $visitorId, $messageId): bool {
        $json = json_encode($push->payload, JSON_THROW_ON_ERROR);

        return $push->channelName === sprintf('tenant.%s.visitor.%s.thread', $tenant->identifier(), $visitorId)
            && $push->eventName === 'webchat.media'
            && ($push->payload['message_ref'] ?? null) === $messageId
            && in_array($push->payload['state'] ?? null, ['clean', 'blocked'], true)
            && ! str_contains($json, 'storage_key') && ! str_contains($json, 'Eicar'); // no key, no signature, no bytes
    });

    // A non-webchat conversation: nothing pushed.
    [, $other] = conversationFixture();
    $none = mediaEventRow('media.stored', ['tenant_id' => $tenant->identifier(), 'message_id' => (string) Str::uuid7(), 'conversation_id' => $other->identifier(), 'index' => 0]);
    expect((new MediaStatePush($none))->handle(app(App\Domain\Webchat\Realtime\WebchatPusher::class)))->toBe(0);
    Event::assertDispatchedTimes(RealtimePush::class, 2);
});
