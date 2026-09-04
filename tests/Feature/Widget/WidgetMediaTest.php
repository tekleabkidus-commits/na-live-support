<?php

declare(strict_types=1);

use App\Domain\Conversations\Media\SourceUrlResolvers;
use App\Domain\Webchat\Media\WidgetIntake;
use App\Jobs\Conversations\FetchInboundMedia;
use App\Models\Conversation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Doc 13 FR-16 / EC-08 / SEC-04 (S13 step 9b): the upload INTAKE on Doc 04's
| physics — capped before a byte is stored; the descriptor rides POST messages
| as an OPAQUE `webchat-intake:` source the resolver turns into an internal
| presigned GET per attempt; only the visitor's OWN intake objects can be
| named; the same-origin read door serves CLEAN items only.
|--------------------------------------------------------------------------
*/

it('POST media: a photo lands in the intake (kind from the mime); over the cap = 413 BEFORE any byte is stored; the descriptor becomes a Doc 04 media item with the opaque source; a foreign upload_id is refused', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);
    $visitorId = $session->json('visitor_id');
    bindTenant($tenant);
    $disk = Storage::disk('media');

    $png = UploadedFile::fake()->image('receipt.png', 320, 200);
    $r = widgetClient($key, $token, $cookie)->post('/api/widget/v1/media', ['file' => $png])->assertStatus(201);
    expect($r->json('kind'))->toBe('photo')->and($r->json('mime'))->toBe('image/png')->and($r->json('upload_id'))->toMatch('/^[0-9a-f-]{36}\.png$/');
    $prefix = sprintf('t/%s/widget-intake/%s/%s/', $tenant->identifier(), $widget->getKey(), $visitorId);
    expect($disk->exists($prefix.$r->json('upload_id')))->toBeTrue('the intake object exists under the visitor prefix');

    // EC-08: the cap is refused BEFORE storing — the intake holds exactly one object still.
    $before = count($disk->files(rtrim($prefix, '/')));
    $big = UploadedFile::fake()->create('statement.pdf', 26 * 1024, 'application/pdf');
    widgetClient($key, $token, $cookie)->post('/api/widget/v1/media', ['file' => $big])->assertStatus(413)->assertJsonPath('code', 'validation_failed');
    expect(count($disk->files(rtrim($prefix, '/'))))->toBe($before);

    // The descriptor rides POST messages: the persisted item carries the OPAQUE source + scan pending; the resolver mints an internal URL.
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', [
        'client_message_id' => 'cm_media_1', 'kind' => 'photo', 'body' => null,
        'media' => [['reference' => $r->json('upload_id'), 'mime' => 'image/png']],
    ])->assertStatus(202);
    drainWebchatInbound();
    bindTenant($tenant);
    $conversation = Conversation::query()->where('external_chat_id', $visitorId)->sole();
    $message = DB::table('messages')->where('conversation_id', $conversation->identifier())->sole();
    $items = json_decode((string) $message->media, true);
    expect($items)->toHaveCount(1)
        ->and($items[0]['source_url'])->toBe(WidgetIntake::SCHEME.':'.$prefix.$r->json('upload_id'))
        ->and($items[0]['scan_status'])->toBe('pending')
        ->and($items[0]['mime'])->toBe('image/png');
    $resolved = app(SourceUrlResolvers::class)->resolve($items[0]['source_url']);
    expect($resolved)->toStartWith('http://minio:9000/')->and($resolved)->toContain('X-Amz-Signature=');

    // A pending item has NO url in the visitor's page and the read door answers 404 (SEC-04).
    $page = widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/messages')->assertStatus(200);
    expect($page->json('data.0.media.0.scan'))->toBe('pending')->and($page->json('data.0.media.0.url'))->toBeNull();
    widgetClient($key, $token, $cookie)->get('/api/widget/v1/media/'.$message->id.'/0')->assertStatus(404);

    // A foreign upload_id (another visitor's prefix) is refused at the descriptor door.
    $other = widgetSession($key);
    $otherToken = $other->json('token');
    $otherCookie = visitorCookieFrom($other);
    widgetClient($key, $otherToken, $otherCookie)->postJson('/api/widget/v1/messages', [
        'client_message_id' => 'cm_media_2', 'kind' => 'photo', 'media' => [['reference' => $r->json('upload_id')]],
    ])->assertStatus(422);
});

it('the pipeline fetches the intake through the egress proxy, scans it, stores a clean copy — and the same-origin door streams it to the visitor, and only to that visitor', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);
    $visitorId = $session->json('visitor_id');
    bindTenant($tenant);
    $png = UploadedFile::fake()->image('receipt.png', 64, 48);
    $up = widgetClient($key, $token, $cookie)->post('/api/widget/v1/media', ['file' => $png])->assertStatus(201)->json();
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_media_3', 'kind' => 'photo', 'media' => [['reference' => $up['upload_id'], 'mime' => 'image/png']]])->assertStatus(202);
    drainWebchatInbound();
    bindTenant($tenant);
    $conversation = Conversation::query()->where('external_chat_id', $visitorId)->sole();
    $message = DB::table('messages')->where('conversation_id', $conversation->identifier())->sole();
    Queue::assertPushed(FetchInboundMedia::class);

    // Doc 04's chain for real: fetch (proxy → minio) → clamd → strip → store.
    app()->call([new FetchInboundMedia($message->id, 0), 'handle']);
    bindTenant($tenant);
    $items = json_decode((string) DB::table('messages')->where('id', $message->id)->value('media'), true);
    expect($items[0]['scan_status'])->toBe('clean')->and($items[0]['storage_key'])->toStartWith('t/'.$tenant->identifier().'/conversations/');

    $page = widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/messages')->assertStatus(200);
    expect($page->json('data.0.media.0.url'))->toBe('/api/widget/v1/media/'.$message->id.'/0');
    $stream = widgetClient($key, $token, $cookie)->get('/api/widget/v1/media/'.$message->id.'/0');
    $stream->assertStatus(200)->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'max-age=300, private');
    expect(strlen((string) $stream->streamedContent()))->toBeGreaterThan(100);

    // Another visitor: the same path is a 404 (C-10 — indistinguishable from missing).
    $other = widgetSession($key);
    widgetClient($key, $other->json('token'), visitorCookieFrom($other))->get('/api/widget/v1/media/'.$message->id.'/0')->assertStatus(404);
});
