<?php

declare(strict_types=1);

use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Webchat\Seeding\WidgetFormSeeder;
use App\Jobs\Webchat\PreChatConsumer;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Doc 13 FR-06 (STOP-5C): form answers ride the first message's envelope;
 * `Doc13/PreChat` — a DURABLE consumer (ADR 0011) — lands them: on
 * message.received the STAFF-COLLECTED field values through Doc 12's door
 * (when the tenant defines the field); on ticket.created the
 * `{version_id, answers}` snapshot into form_data (BR-06). A chatbot-resolved
 * conversation never gets a ticket — its answers stay on the customer. No
 * phone identity is ever minted here (Doc 08 BR-02; STOP-5A pending).
 */
it('lands the pre-chat answers: staff-collected fields on the first message, the form_data snapshot when the ticket is created; C-09 idempotent on the event id', function (): void {
    [$tenant, , $key] = widgetFixture();
    bindTenant($tenant);
    $agent = ticketActor([]); // a user of THIS tenant (formScene() would mint another tenant)
    bindTenant($tenant); // ticketActor() leaves the context unbound
    // An operator who DEFINED a phone field (Doc 12 FR-17): the seeded trio is betting_username/player_id/brand — no phone.
    app(App\Domain\Fields\DefinitionWriter::class)->create(['entity' => 'customer', 'key' => 'phone', 'label' => ['en' => 'Phone', 'am' => 'ስልክ'], 'type' => 'phone'], $agent->identifier());
    app(WidgetFormSeeder::class)->seed($agent->identifier());
    $preChat = (string) DB::table('forms')->where('name', WidgetFormSeeder::PRE_CHAT)->value('current_version_id');
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);

    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', [
        'client_message_id' => 'cm_1', 'kind' => 'text', 'body' => 'my deposit is missing',
        'form' => ['version_id' => $preChat, 'answers' => ['name' => 'Abebe Tadesse', 'phone' => '+251900001234']],
    ])->assertStatus(202);
    drainWebchatInbound();

    bindTenant($tenant);
    $conversation = Conversation::query()->where('channel', 'webchat')->sole();
    $received = relayOutboxRow('message.received'); // the relay's byte-copy (C-09 physics), the house helper
    bindTenant($tenant);
    app()->call([new PreChatConsumer($received), 'handle']);
    app()->call([new PreChatConsumer($received), 'handle']); // C-09: a second delivery changes nothing

    bindTenant($tenant);
    $customerId = (string) $conversation->customer_id;
    // The tenant's `phone` customer field: the pre-chat value landed as STAFF-COLLECTED (canonical E.164).
    $phone = DB::table('customer_field_values')->where('customer_id', $customerId)->where('field_key', 'phone')->value('value');
    expect($phone)->not->toBeNull('the pre-chat phone must land as a staff-collected field value');
    expect(DB::table('customer_identities')->where('type', 'phone')->count())->toBe(0, 'BR-02: never a phone identity from a form');
    // STOP-5A (a): ONE staff-visible system line "phone provided" (masked, staff-collected, not linked) — the second delivery drew no second line.
    $lines = DB::table('messages')->where('conversation_id', $conversation->identifier())->where('author_type', 'system')->where('kind', 'note')->get(['body', 'is_internal']);
    expect($lines)->toHaveCount(1)
        ->and((bool) $lines[0]->is_internal)->toBeTrue()
        ->and($lines[0]->body)->toBe(__('widget.prechat.phone_provided', ['phone' => '+251 •• ••• 1234']))
        ->and($lines[0]->body)->not->toContain('+251900001234');

    // A ticket later: the snapshot lands ONCE in form_data.
    $ticket = app(CreateTicket::class)->create(new NewTicket(conversation: $conversation, source: TicketSource::Automation, idempotencyKey: 'prechat-test'));
    $created = relayOutboxRow('ticket.created');
    bindTenant($tenant);
    app()->call([new PreChatConsumer($created), 'handle']);
    app()->call([new PreChatConsumer($created), 'handle']);
    bindTenant($tenant);
    $formData = json_decode((string) DB::table('tickets')->where('id', $ticket->identifier())->value('form_data'), true);
    expect($formData['version_id'])->toBe($preChat)->and($formData['answers']['name'])->toBe('Abebe Tadesse');
});

it('STOP-5A (a): a pre-chat phone that ALREADY sits on another customer draws the Doc 08 conflict flag READ-ONLY — the line names the conflict, nothing is attached, nothing is linked, the other customer keeps its identity', function (): void {
    [$tenant, , $key] = widgetFixture();
    bindTenant($tenant);
    $agent = ticketActor([]);
    bindTenant($tenant);
    app(WidgetFormSeeder::class)->seed($agent->identifier());
    $preChat = (string) DB::table('forms')->where('name', WidgetFormSeeder::PRE_CHAT)->value('current_version_id');
    // Another customer owns the phone through the FR-09 STAFF door (the only door that mints a phone identity).
    $other = App\Models\Customer::query()->create(['display_name' => 'Fixture Other Player', 'username' => '@fixture_other_'.Illuminate\Support\Str::random(6), 'is_shell' => false, 'created_source' => 'staff']);
    app(App\Domain\Customers\Identity\LinkIdentity::class)->link($other->identifier(), 'phone', '+251900001234', $agent->identifier());
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);

    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', [
        'client_message_id' => 'cm_conflict', 'kind' => 'text', 'body' => 'hello',
        'form' => ['version_id' => $preChat, 'answers' => ['name' => 'Kidist', 'phone' => '0900001234']],
    ])->assertStatus(202);
    drainWebchatInbound();
    bindTenant($tenant);
    $conversation = Conversation::query()->where('channel', 'webchat')->sole();
    $received = relayOutboxRow('message.received');
    bindTenant($tenant);
    app()->call([new PreChatConsumer($received), 'handle']);
    app()->call([new PreChatConsumer($received), 'handle']);

    $lines = DB::table('messages')->where('conversation_id', $conversation->identifier())->where('author_type', 'system')->where('kind', 'note')->get(['body']);
    expect($lines)->toHaveCount(1)->and($lines[0]->body)->toBe(__('widget.prechat.phone_conflict', ['phone' => '+251 •• ••• 1234']));
    // Read-only: the phone identity still belongs to the other customer alone; the visitor's customer gained none.
    $owners = DB::table('customer_identities')->where('type', 'phone')->where('value', '+251900001234')->pluck('customer_id')->all();
    expect($owners)->toBe([$other->identifier()])
        ->and(DB::table('customer_identities')->where('customer_id', (string) $conversation->customer_id)->where('type', 'phone')->count())->toBe(0);
});
