<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Support\Audit\AuditActor;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 13 SEC-06 (S13 step 12) — the transcript door: the visitor's OWN rows,
| frame text localized, content verbatim, internal notes ABSENT with the
| positive control (the note IS in the data), rate-limited per visitor,
| closed by `widget.transcript.mode = off`, 404 with no conversation.
|--------------------------------------------------------------------------
*/

function transcriptScene(): array
{
    [$tenant, $widget, $key] = widgetFixture();
    resetWidgetClient();
    $session = widgetSession($key);

    return [$tenant, $widget, $key, $session->json('token'), visitorCookieFrom($session)];
}

it('serves the visitor\'s own thread as text — you / assistant / staff first name; the note planted in the same conversation is ABSENT while the data holds it; Amharic frame text on request', function (): void {
    [$tenant, $widget, $key, $token, $cookie] = transcriptScene();
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_t1', 'kind' => 'text', 'body' => 'Where is my withdrawal? ጥያቄ'])->assertStatus(202);
    drainWebchatInbound();
    bindTenant($tenant);
    $conversation = Conversation::query()->where('channel', 'webchat')->sole();
    $agent = ticketActor(['tickets.view'], 'Fixture Transcript Agent');
    DB::table('users')->where('id', $agent->identifier())->update(['name' => 'Selam Fixture']);
    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
        'direction' => 'out', 'author_type' => 'agent', 'author_id' => $agent->identifier(), 'kind' => 'text', 'body' => 'Withdrawals land within 24 hours.', 'media' => '[]', 'channel_meta' => '{}', 'delivery_state' => 'delivered',
    ]);
    app(App\Domain\Conversations\Notes\NotesWriter::class)->addSystemNote($conversation, 'STAFF ONLY: verify the deposit reference');

    $r = widgetClient($key, $token, $cookie)->get('/api/widget/v1/transcript');
    $r->assertOk();
    expect($r->headers->get('Content-Type'))->toContain('text/plain')
        ->and($r->headers->get('Content-Disposition'))->toContain('attachment; filename="transcript-')
        ->and($r->headers->get('Cache-Control'))->toContain('no-store');
    $text = (string) $r->getContent();
    expect($text)->toContain('Your conversation transcript')
        ->toContain('This file holds your own conversation only')
        ->toContain('· You · Where is my withdrawal? ጥያቄ')
        ->toContain('· Selam · Withdrawals land within 24 hours.')
        ->not->toContain('STAFF ONLY')
        ->not->toContain('Selam Fixture') // first name only — never the full name, never an email
        ->not->toContain($agent->identifier());
    bindTenant($tenant);
    expect(DB::table('messages')->where('conversation_id', $conversation->identifier())->where('kind', 'note')->count())->toBe(1, 'positive control: the note IS in the data');

    $am = widgetClient($key, $token, $cookie)->get('/api/widget/v1/transcript?language=am')->assertOk();
    expect((string) $am->getContent())->toContain('የውይይትዎ ግልባጭ')->toContain('· እርስዎ · Where is my withdrawal? ጥያቄ'); // frame localized, content verbatim
});

it('is rate-limited per visitor by widget.transcript.rate_per_hour (429 problem details with retry_after_seconds), closed by mode=email (Doc 16 delivery, no download door: 404), and 404 with no conversation', function (): void {
    [$tenant, $widget, $key, $token, $cookie] = transcriptScene();
    widgetClient($key, $token, $cookie)->get('/api/widget/v1/transcript')->assertStatus(404); // no conversation yet
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_t2', 'kind' => 'text', 'body' => 'hello'])->assertStatus(202);
    drainWebchatInbound();
    bindTenant($tenant);
    foreach ([1, 2, 3] as $n) {
        widgetClient($key, $token, $cookie)->get('/api/widget/v1/transcript')->assertOk();
        bindTenant($tenant);
    }
    $limited = widgetClient($key, $token, $cookie)->get('/api/widget/v1/transcript');
    $limited->assertStatus(429);
    expect($limited->json('code'))->toBe('rate_limited')->and($limited->json('retry_after_seconds'))->toBeGreaterThan(0);
    bindTenant($tenant);
    app(SettingsRepository::class)->set('widget.transcript.mode', 'email', AuditActor::PlatformAdmin, tenantSurface: false);
    widgetClient($key, $token, $cookie)->get('/api/widget/v1/transcript')->assertStatus(404);
});
