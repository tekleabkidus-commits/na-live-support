<?php

declare(strict_types=1);

use App\Domain\Webchat\Seeding\WidgetFormSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 FR-15 / US-03 / Doc 06 FR-14 (closing-set row 5) — the OFFLINE FORM:
 * one synchronous door (STOP-5B): the story becomes the conversation's first
 * message, `NewTicket::fromOfflineForm` mints the `offline`-tagged ticket with
 * source `offline_form`, Doc 12's SubmissionWriter snapshots the answers, and
 * the receipt cites the REAL ticket number with the phone masked back (B3-S05
 * F10). The phone is a staff-collected FIELD value / metadata, never an
 * identity (Doc 08 BR-02; STOP-5A pending — fields only here). The SMS that
 * follows is Doc 14's ([D14]).
 */
function offlineScene(): array
{
    [$tenant, $widget, $key] = widgetFixture();
    bindTenant($tenant);
    $agent = ticketActor([]); // a user of THIS tenant (formScene() would mint another tenant)
    bindTenant($tenant); // ticketActor() leaves the context unbound
    app(WidgetFormSeeder::class)->seed($agent->identifier());
    $versionId = (string) DB::table('forms')->where('name', WidgetFormSeeder::OFFLINE)->value('current_version_id');
    resetWidgetClient();
    $session = widgetSession($key);

    return [$tenant, $widget, $key, $versionId, $session->json('token'), visitorCookieFrom($session), $session->json('visitor_id')];
}

it('FR-15: the offline form lands a REAL offline-tagged ticket (source offline_form, tag offline) on a new conversation whose first message is the story; the receipt cites the number and masks the phone; the answers are snapshotted into form_data', function (): void {
    [$tenant, , $key, $versionId, $token, $cookie, $visitorId] = offlineScene();

    $receipt = widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/forms/'.$versionId.'/steps', [
        'mode' => 'offline', 'complete' => true,
        'answers' => ['name' => 'አበበ ታደሰ', 'phone' => '+251900001234', 'story' => 'My deposit of 500 birr has not arrived since this morning.'],
    ])->assertStatus(201)->json();

    expect($receipt['ticket_number'])->toBeInt()->toBeGreaterThan(0)
        ->and($receipt['phone_masked'])->toBe('+251 •• ••• 1234')
        ->and($receipt)->not->toHaveKey('customer_id');

    bindTenant($tenant);
    $conversation = DB::table('conversations')->where('external_chat_id', $visitorId)->sole();
    $ticket = DB::table('tickets')->where('conversation_id', $conversation->id)->sole();
    expect($ticket->source)->toBe('offline_form')->and(App\Models\Ticket::query()->findOrFail($ticket->id)->tags)->toContain('offline')
        ->and((int) $ticket->number)->toBe($receipt['ticket_number'])
        ->and($conversation->open_ticket_id)->toBe($ticket->id);
    $formData = json_decode((string) $ticket->form_data, true);
    expect($formData['version_id'])->toBe($versionId)->and($formData['answers']['story'])->toContain('500 birr');
    // The story IS the first message (customer, text); nothing else was persisted.
    $messages = DB::table('messages')->where('conversation_id', $conversation->id)->get();
    expect($messages)->toHaveCount(1)->and($messages[0]->body)->toContain('500 birr')->and($messages[0]->author_type)->toBe('customer');
    // BR-02: no phone identity was minted; the name rides the customer's identity display.
    expect(DB::table('customer_identities')->where('type', 'phone')->count())->toBe(0);
    // Idempotent: the same submission again lands no second ticket.
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/forms/'.$versionId.'/steps', ['mode' => 'offline', 'complete' => true, 'answers' => ['name' => 'አበበ ታደሰ', 'phone' => '+251900001234', 'story' => 'My deposit of 500 birr has not arrived since this morning.']])->assertStatus(201);
    bindTenant($tenant);
    expect(DB::table('tickets')->where('conversation_id', $conversation->id)->count())->toBe(1);
});

it('FR-15 refusals are inline and neutral: a missing required answer answers 422 with the field named; an unpublished version is 404', function (): void {
    [, , $key, $versionId, $token, $cookie] = offlineScene();
    $r = widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/forms/'.$versionId.'/steps', ['mode' => 'offline', 'complete' => true, 'answers' => ['name' => 'x']]);
    $r->assertStatus(422);
    expect($r->json('errors'))->toHaveKey('phone');
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/forms/'.(string) Illuminate\Support\Str::uuid7().'/steps', ['mode' => 'offline', 'complete' => true, 'answers' => []])->assertStatus(404);
});
