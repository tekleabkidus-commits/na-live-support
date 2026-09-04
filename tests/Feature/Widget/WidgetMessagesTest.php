<?php

declare(strict_types=1);

use App\Domain\Conversations\Outbound\DeliveryState;
use App\Domain\Webchat\WebchatAdapter;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Doc 13 T-03 / T-02 / FR-10 — the visitor's message doors: the inbound door
 * ACKs 202 and enqueues on `webchat-in` (never inline; the SAME discipline as
 * every channel webhook), the idempotency key becomes the channel message id
 * (a retried POST is the SAME message — one row), the cursor page is the
 * visitor-curated shape with internal notes and quarantined rows ABSENT
 * (SEC-06/BR-01, a positive control on each), and `POST receipts` is the
 * platform's FIRST `delivered` producer — only from `sent`, only for the
 * visitor's own outbound rows, never faked (AC-01's server half; sabotage (h)
 * is the DOM half at step 8).
 */
function visitorScene(): array
{
    [$tenant, $widget, $key] = widgetFixture();
    resetWidgetClient();
    $session = widgetSession($key);

    return [$tenant, $widget, $key, $session->json('token'), visitorCookieFrom($session), $session->json('visitor_id')];
}

it('POST messages ACKs 202 and enqueues NormalizeInbound on webchat-in — never processed inline; the envelope carries the channel key (widget id, visitor id, client message id)', function (): void {
    [$tenant, $widget, $key, $token, $cookie, $visitorId] = visitorScene();
    Queue::fake();

    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', [
        'client_message_id' => 'cm_first', 'kind' => 'text', 'body' => 'Selam',
    ])->assertStatus(202)->assertJson(['accepted' => true, 'client_message_id' => 'cm_first']);

    Queue::assertPushed(NormalizeInbound::class, 1);
    Queue::assertPushed(fn (NormalizeInbound $job): bool => $job->queue === 'webchat-in'
        && $job->channel === WebchatAdapter::CHANNEL
        && $job->channelRefId === (string) $widget->getKey()
        && $job->raw['visitor_id'] === $visitorId
        && $job->raw['message_id'] === 'cm_first');
    bindTenant($tenant);
    expect(DB::table('messages')->count())->toBe(0, 'nothing is persisted inline (FR-10 step 1)');
});

it('idempotency: the same client_message_id POSTed twice lands ONE row (the dedupe key is the channel message id, T-03/T-02)', function (): void {
    [$tenant, $widget, $key, $token, $cookie] = visitorScene();
    Queue::fake();

    foreach ([1, 2] as $attempt) {
        widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_retry', 'kind' => 'text', 'body' => 'retry #'.$attempt])->assertStatus(202);
    }
    expect(drainWebchatInbound())->toBe(2);

    bindTenant($tenant);
    $conversation = Conversation::query()->where('channel', 'webchat')->where('channel_ref_id', (string) $widget->getKey())->sole();
    expect(Message::query()->where('conversation_id', $conversation->identifier())->count())->toBe(1)
        ->and(Message::query()->where('conversation_id', $conversation->identifier())->sole()->body)->toBe('retry #1');
});

it('GET messages is the visitor-curated cursor page: notes and quarantined rows ABSENT with a positive control; no author ids, no channel meta; C-05 cursoring', function (): void {
    [$tenant, $widget, $key, $token, $cookie] = visitorScene();
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_1', 'kind' => 'text', 'body' => 'visible one'])->assertStatus(202);
    drainWebchatInbound();

    bindTenant($tenant);
    $conversation = Conversation::query()->where('channel', 'webchat')->sole();
    // A staff internal note (structurally undeliverable, N-04) and a quarantined row in the SAME conversation.
    app(App\Domain\Conversations\Notes\NotesWriter::class)->addSystemNote($conversation, 'STAFF ONLY: verify the deposit reference');
    DB::table('messages')->where('conversation_id', $conversation->identifier())->where('body', 'visible one')->update(['quarantined' => false]);
    $quarantinedId = (string) Illuminate\Support\Str::uuid7();
    DB::table('messages')->insert([
        'id' => $quarantinedId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text', 'body' => 'QUARANTINED', 'quarantined' => true, 'media' => '[]', 'channel_meta' => '{}',
    ]);

    $page = widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/messages?limit=10')->assertOk()->json();
    $bodies = array_column($page['data'], 'body');
    expect($bodies)->toBe(['visible one'])
        ->and($bodies)->not->toContain('STAFF ONLY: verify the deposit reference')
        ->and($bodies)->not->toContain('QUARANTINED');
    foreach ($page['data'] as $row) {
        expect($row)->not->toHaveKeys(['author_id', 'channel_meta', 'is_internal']);
    }
    // Positive control: the staff thread DOES see the note (the absence above is the widget's, not the data's).
    bindTenant($tenant); // a request cycle clears the RLS setting; direct reads re-bind
    expect(DB::table('messages')->where('conversation_id', $conversation->identifier())->where('kind', 'note')->count())->toBe(1);
    // C-05: offset pagination is refused by name.
    widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/messages?page=2')->assertStatus(400);
});

it('POST receipts: the FIRST delivered producer — an outbound row moves sent → delivered exactly once, a queued row does not, a foreign id is 404', function (): void {
    [$tenant, $widget, $key, $token, $cookie] = visitorScene();
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_1', 'kind' => 'text', 'body' => 'hello'])->assertStatus(202);
    drainWebchatInbound();

    bindTenant($tenant);
    $conversation = Conversation::query()->where('channel', 'webchat')->sole();
    $sentId = (string) Illuminate\Support\Str::uuid7();
    $queuedId = (string) Illuminate\Support\Str::uuid7();
    foreach ([[$sentId, 'sent'], [$queuedId, 'queued']] as [$id, $state]) {
        DB::table('messages')->insert([
            'id' => $id, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
            'direction' => 'out', 'author_type' => 'agent', 'kind' => 'text', 'body' => 'reply '.$state, 'delivery_state' => $state, 'media' => '[]', 'channel_meta' => '{}',
        ]);
    }

    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/receipts', ['message_ids' => [$sentId, $queuedId]])->assertOk()->assertJson(['delivered' => 1]);
    bindTenant($tenant);
    expect(DB::table('messages')->where('id', $sentId)->value('delivery_state'))->toBe(DeliveryState::Delivered->value)
        ->and(DB::table('messages')->where('id', $queuedId)->value('delivery_state'))->toBe('queued', 'delivered is earned from sent only — never faked over queued');
    expect(DB::table('outbox')->where('name', 'message.delivered')->count())->toBe(1);

    // A second receipt for the same id is a no-op (earned once); a foreign id is ABSENT.
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/receipts', ['message_ids' => [$sentId]])->assertOk()->assertJson(['delivered' => 0]);
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/receipts', ['message_ids' => [(string) Illuminate\Support\Str::uuid7()]])->assertStatus(404);
});

it('POST typing records the customer signal with its TTL (EC-12) and 204s; a visitor with no conversation yet 204s too', function (): void {
    [$tenant, , $key, $token, $cookie] = visitorScene();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/typing')->assertStatus(204);
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_1', 'kind' => 'text', 'body' => 'x'])->assertStatus(202);
    drainWebchatInbound();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/typing')->assertStatus(204);
    bindTenant($tenant);
    $conversation = Conversation::query()->where('channel', 'webchat')->sole();
    bindTenant($tenant);
    expect(app(App\Domain\Conversations\Presence\TypingSignals::class)->isCustomerTyping($conversation))->toBeTrue();
});

it('GET config: the live config over the neutral defaults, the adapter\'s capability envelope, hours from the real calendar, the language chain, Cache-Control 60 s; a soft-deleted widget is DEAD to the wire (C3)', function (): void {
    [$tenant, $widget, $key, $token, $cookie] = visitorScene();
    $config = widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/config?language=am')->assertOk();
    $config->assertJsonPath('capabilities.max_text', 4000)->assertJsonPath('capabilities.typing', true)->assertJsonPath('language', 'am')->assertJsonPath('version', null);
    expect($config->json('capabilities.media'))->toBe(['photo', 'video', 'document'])
        ->and($config->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($config->json('hours'))->toHaveKeys(['open', 'opens_at', 'timezone'])
        ->and($config->json('strings'))->toBeArray();

    bindTenant($tenant);
    DB::table('widgets')->where('id', $widget->getKey())->update(['deleted_at' => DB::raw('clock_timestamp()')]);
    resetWidgetClient();
    widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/config')->assertStatus(404);
});
