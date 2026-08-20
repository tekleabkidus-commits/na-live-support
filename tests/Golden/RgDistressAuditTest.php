<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Pipeline\InboundClassification;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Jobs\Ai\ClassifyInboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SS-03 (AC-04's audit form): FIFTY seeded distress dialogs (en + am) through
 * the REAL pipeline — every one answered by the template ONLY (byte-equal),
 * routed to the RG handoff, flagged WITH its excerpt, and ZERO generated RG
 * text (the provider sees exactly classify + confirm per dialog, never a
 * generation call on the send path). The corpus is the governed asset §21
 * rec 3 names (synthetic fixtures; [D19] owns the process).
 */
function ss03RegisterFakeChannel(): void
{
    $registry = app(App\Domain\Conversations\Adapters\AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new Tests\Support\Adapters\FakeChannelAdapter(new App\Domain\Conversations\Adapters\Capabilities(
            maxText: 4096, mediaTypes: ['photo'], supportsTyping: false, supportsEdit: false,
            supportsDelete: false, supportsButtons: false, replyWindow: App\Domain\Conversations\Adapters\ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }
}

it('SS-03: 50 seeded distress dialogs -> template-only, RG-routed, flagged with excerpts, zero generated RG text', function (): void {
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    $tenantId = $tenant->identifier();
    ss03RegisterFakeChannel();

    $decoded = json_decode((string) file_get_contents(base_path('fixtures/ai/rg/ss03-distress.json')), true);
    $dialogs = is_array($decoded) && is_array($decoded['dialogs'] ?? null) ? $decoded['dialogs'] : [];
    expect(count($dialogs))->toBe(50);

    $driver = new FakeDriver(new FixturesOnlyEgress);
    app(ProviderRegistry::class)->swap('fake-ss03', $driver);
    config(['ai.default_routing.fast' => [['provider' => 'fake-ss03', 'model' => 'm']], 'ai.default_routing.strong' => [['provider' => 'fake-ss03', 'model' => 'm']]]);

    $audited = 0;
    foreach ($dialogs as $dialog) {
        $language = is_string($dialog['language'] ?? null) ? $dialog['language'] : 'en';
        $text = is_string($dialog['text'] ?? null) ? $dialog['text'] : '';

        $customerId = (string) Str::uuid7();
        DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram', 'language' => $language]);
        $conversation = anotherConversation($tenantId, 'ss03-'.$audited, $customerId);

        // Realistic two-stage outputs for a distress dialog.
        $driver->queue(new ProviderResponse((string) json_encode(['intent' => 'rg_risk', 'sentiment' => -2, 'urgency' => 3, 'spam' => 0, 'rg_risk' => 0.9]), 400, 50));
        $driver->queue(new ProviderResponse((string) json_encode(['rg_risk' => 0.93, 'evidence' => 'gambling harm']), 100, 20));
        $callsBefore = count($driver->seen);

        $messageId = (string) Str::uuid7();
        DB::table('messages')->insert([
            'id' => $messageId, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(),
            'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId, 'kind' => 'text', 'body' => $text, 'language' => $language,
        ]);
        $eventId = (string) Str::uuid7();
        DB::table('events')->insert([
            'id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received',
            'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $messageId, 'conversation_id' => $conversation->identifier(), 'customer_id' => $customerId, 'kind' => 'text']),
        ]);

        (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));

        // Template ONLY, byte for byte, in the dialog's language.
        $outbound = DB::table('messages')->where('conversation_id', $conversation->identifier())->where('direction', 'out')->get();
        expect($outbound)->toHaveCount(1, "dialog {$audited}: expected exactly one outbound");
        expect($outbound->first()->body)->toBe(__('ai.rg.template', [], $language), "dialog {$audited}: generated or altered RG text");
        expect($outbound->first()->author_type)->toBe('ai');

        // Routed: the RG handoff ticket exists with the packet's reasons.
        $ticket = DB::table('tickets')->where('conversation_id', $conversation->identifier())->where('source', 'ai_handoff')->first();
        expect($ticket)->not->toBeNull("dialog {$audited}: no RG handoff ticket");
        $packet = DB::table('ticket_handoff_packets')->where('ticket_id', $ticket->id)->value('payload');
        expect((string) $packet)->toContain('rg_queue');

        // Flagged with excerpt, source=ai, null actor.
        $flag = DB::table('rg_flag_history')->where('customer_id', $customerId)->where('action', 'set')->first();
        expect($flag->source)->toBe('ai')->and($flag->actor_id)->toBeNull()
            ->and(json_decode((string) $flag->excerpt_ref, true)['message_id'])->toBe($messageId);

        // ZERO generated RG text: exactly classify + confirm touched the provider.
        expect(count($driver->seen) - $callsBefore)->toBe(2, "dialog {$audited}: a generation call rode the RG path");

        $audited++;
    }

    expect($audited)->toBe(50);
});
