<?php

declare(strict_types=1);

use App\Domain\Conversations\Actions\ConversationBlocking;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Realtime\ConversationChannelGate;
use App\Domain\Conversations\Realtime\RealtimePusher;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Events\Conversations\RealtimePush;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Support\Events\EventCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Realtime + blocking actions (Doc 04 S20 step 8 — FR-03 actions, FR-25,
| T-06 scope-on-subscribe = Doc 03 FR-06's Reverb half [inherited row 2],
| SEC-06 emit fence, FR-26 catalog completeness)
|--------------------------------------------------------------------------
*/

function realtimeFixture(): array
{
    $tenant = createTenant();
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo'],
            supportsTyping: false,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_rt', 'is_shell' => true]);
    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', 'chat-rt-'.uniqid(), $customer->identifier());

    $granted = asTenant($tenant, fn (): User => grantAllPermissions(User::factory()->create([
        'tenant_id' => $tenant->tenantId(),
        'name' => 'fixture.viewer',
    ])));
    $ungranted = asTenant($tenant, fn (): User => User::factory()->create([
        'tenant_id' => $tenant->tenantId(),
        'name' => 'fixture.outsider',
    ]));
    bindTenant($tenant);

    return [$conversation, $granted, $ungranted];
}

// ── FR-03: block/unblock as observable actions ───────────────────────────────

test('FR-03: block and unblock emit their events and audit entries; a double block refuses', function () {
    [$conversation, $granted] = realtimeFixture();
    $blocking = app(ConversationBlocking::class);

    $blocking->block($conversation, $granted, 'spam flood');

    expect($conversation->refresh()->state)->toBe('blocked')
        ->and(DB::table('outbox')->where('name', 'conversation.blocked')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'conversation.blocked')->count())->toBe(1);

    expect(fn () => $blocking->block($conversation, $granted, 'again'))->toThrow(DomainException::class);

    $blocking->unblock($conversation, null, 'automation cleared'); // system actor path

    expect($conversation->refresh()->state)->toBe('active')
        ->and(DB::table('outbox')->where('name', 'conversation.unblocked')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'conversation.unblocked')->count())->toBe(1);
});

// ── FR-25/SEC-06: the pusher's guards ────────────────────────────────────────

test('FR-25: every push carries v:1 on the tenant-namespaced channel', function () {
    [$conversation] = realtimeFixture();
    Event::fake([RealtimePush::class]);

    app(RealtimePusher::class)->toConversation($conversation->identifier(), 'message.received', [
        'tenant_id' => $conversation->getAttribute('tenant_id'),
        'message_id' => (string) Str::uuid7(),
    ]);

    Event::assertDispatched(RealtimePush::class, function (RealtimePush $push) use ($conversation): bool {
        return $push->payload['v'] === 1
            && str_contains($push->channelName, 'tenant.')
            && str_contains($push->channelName, '.conversation.'.$conversation->identifier())
            && $push->eventName === 'message.received';
    });
});

test('FR-25: a payload over 32 KB is REFUSED — media rides as signed URLs, never bytes', function () {
    [$conversation] = realtimeFixture();
    Event::fake([RealtimePush::class]);

    expect(fn () => app(RealtimePusher::class)->toConversation($conversation->identifier(), 'message.received', [
        'tenant_id' => $conversation->getAttribute('tenant_id'),
        'inlined_media' => str_repeat('a', 33_000),
    ]))->toThrow(DomainException::class, '32 KB');

    Event::assertNotDispatched(RealtimePush::class);
});

test('SEC-06: outside production, a payload naming a FOREIGN tenant id refuses at emit', function () {
    [$conversation] = realtimeFixture();
    Event::fake([RealtimePush::class]);

    expect(fn () => app(RealtimePusher::class)->toConversation($conversation->identifier(), 'message.received', [
        'tenant_id' => (string) Str::uuid7(), // another tenant's id
    ]))->toThrow(DomainException::class, 'FOREIGN tenant');

    Event::assertNotDispatched(RealtimePush::class);
});

// ── T-06: scope ON SUBSCRIBE (Doc 03 FR-06's Reverb half, inherited row 2) ───

test('T-06: subscribe requires conversations.view AND scope — refusals are silent and identical (C-10)', function () {
    [$conversation, $granted, $ungranted] = realtimeFixture();
    $gate = app(ConversationChannelGate::class);

    // All-scoped holder of conversations.view: presence member info comes back.
    $member = $gate->authorize($granted, $conversation->identifier());
    expect($member)->toBeArray()
        ->and($member['id'])->toBe($granted->identifier());

    // No permission → false; unknown conversation → the SAME false.
    expect($gate->authorize($ungranted, $conversation->identifier()))->toBeFalse()
        ->and($gate->authorize($granted, (string) Str::uuid7()))->toBeFalse();
});

// ── FR-26: the catalog is CLOSED and complete ────────────────────────────────

test('FR-26: every named event exists in the catalog; draft.saved does not; channel.health.changed arrives with the step-9 drills', function () {
    $names = EventCatalog::names();

    $required = [
        'conversation.created', 'conversation.blocked', 'conversation.unblocked',
        'message.received', 'message.queued', 'message.sent', 'message.delivered',
        'message.failed', 'message.canceled', 'message.edited', 'message.deleted',
        'media.stored', 'media.infected', 'note.added', 'transcript.exported',
        // A-4's filed names:
        'note.mentioned', 'conversation.lane_skipped',
        // T-05's hooks:
        'media.needs_transcript', 'media.needs_ocr',
    ];

    foreach ($required as $name) {
        expect($names)->toContain($name);
    }

    expect($names)->not->toContain('draft.saved');
});
