<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Drafts\DraftStore;
use App\Domain\Conversations\Notes\NotesWriter;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Presence\TypingSignals;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Models\Customer;
use App\Models\User;
use App\Support\Events\EventCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Notes, typing, drafts (Doc 04 S20 step 6 — FR-17/18/19-adjacent/20, T-07,
| EC-06, EC-12's expiry physics, AC-04's runtime half, FR-26's draft.saved
| exclusion)
|--------------------------------------------------------------------------
*/

function notesFixture(bool $typingCapability = true): array
{
    $tenant = createTenant();
    bindTenant($tenant);

    $adapter = new FakeChannelAdapter(new Capabilities(
        maxText: 4096,
        mediaTypes: ['photo'],
        supportsTyping: $typingCapability,
        supportsEdit: false,
        supportsDelete: false,
        supportsButtons: false,
        replyWindow: ReplyWindow::none(),
    ));

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', $adapter, 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_notes', 'is_shell' => true, 'created_source' => 'staff']);
    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', 'chat-notes-'.uniqid(), $customer->identifier());

    $staff = asTenant($tenant, fn (): User => User::factory()->create([
        'tenant_id' => $tenant->tenantId(),
        'name' => 'fixture.agent',
    ]));
    bindTenant($tenant);

    return [$conversation, $staff, $adapter, $tenant];
}

// ── FR-17: notes ─────────────────────────────────────────────────────────────

test('FR-17: a note persists internal-only with note.added + audit, and an @mention emits note.mentioned', function () {
    [$conversation, $staff, , $tenant] = notesFixture();

    $mentioned = asTenant($tenant, fn (): User => User::factory()->create([
        'tenant_id' => $tenant->tenantId(),
        'name' => 'fixture.lead',
    ]));
    bindTenant($tenant);

    $note = app(NotesWriter::class)->addNote($conversation, $staff, 'Escalating to @fixture.lead — customer disputes a withdrawal.');

    expect($note->kind)->toBe('note')
        ->and($note->is_internal)->toBeTrue()
        ->and($note->delivery_state)->toBeNull() // no delivery lifecycle exists for it
        ->and(DB::table('outbox')->where('name', 'note.added')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'conversation.note_added')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'note.mentioned')->value('payload'))->toContain($mentioned->identifier());
});

test('AC-04 runtime half: the compose path REFUSES kind=note before any row exists — through the real send door', function () {
    [$conversation] = notesFixture();

    expect(fn () => app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'note', 'leak attempt'))
        ->toThrow(DomainException::class, 'notes are not deliverable');

    // Nothing half-happened: no row, no event, no lane entry.
    expect(App\Models\Message::query()->count())->toBe(0)
        ->and(DB::table('outbox')->count())->toBe(0);
});

// ── FR-18/EC-12: typing ──────────────────────────────────────────────────────

test('FR-18: staff composing signals the adapter at most once per 4 s window', function () {
    [$conversation, , $adapter] = notesFixture();
    $typing = app(TypingSignals::class);

    $typing->staffComposing($conversation);
    $typing->staffComposing($conversation);
    $typing->staffComposing($conversation);

    expect($adapter->typingSignals)->toHaveCount(1); // the window swallowed the burst
});

test('FR-18: the typing signal is capability-gated — a channel that cannot show it never receives one', function () {
    [$conversation, , $adapter] = notesFixture(typingCapability: false);

    app(TypingSignals::class)->staffComposing($conversation);

    expect($adapter->typingSignals)->toHaveCount(0);
});

test('EC-12: customer typing auto-expires by TTL physics — a dead Reverb node cannot leave a stuck indicator', function () {
    [$conversation] = notesFixture();
    $typing = app(TypingSignals::class);

    $typing->customerTyping($conversation);
    $typing->customerTyping($conversation); // never stacks — same key, refreshed TTL

    expect($typing->isCustomerTyping($conversation))->toBeTrue();

    // The expiry is the TTL itself: drop the key the way Redis will and the
    // indicator is gone — no cleanup job exists to fail.
    app(App\Support\Cache\TenantCache::class)->forget('customer-typing:'.$conversation->identifier());
    expect($typing->isCustomerTyping($conversation))->toBeFalse();
});

// ── FR-20/T-07/EC-06: drafts ─────────────────────────────────────────────────

test('T-07: one draft per (user, conversation) — saves upsert, the unique index refuses a second row', function () {
    [$conversation, $staff] = notesFixture();
    $store = app(DraftStore::class);

    $store->save($staff, $conversation, 'half a reply', null);
    $result = $store->save($staff, $conversation, 'half a reply, longer', null);

    expect($result['conflict'])->toBeFalse()
        ->and(App\Models\MessageDraft::query()->count())->toBe(1)
        ->and($store->for($staff, $conversation)?->body)->toBe('half a reply, longer');
});

test('EC-06: last-writer-wins — the device holding a STALE updated_at loses and gets the conflict signal', function () {
    [$conversation, $staff] = notesFixture();
    $store = app(DraftStore::class);

    $first = $store->save($staff, $conversation, 'from the laptop', null);
    $laptopSawUpdatedAt = CarbonImmutable::instance($first['draft']->getAttribute('updated_at'));

    // The phone saves later (newer updated_at in DB time)…
    DB::table('message_drafts')->where('id', $first['draft']->identifier())
        ->update(['body' => 'from the phone', 'updated_at' => DB::raw("now() + interval '5 seconds'")]);

    // …so the laptop's next save, guarded by what IT last saw, must lose.
    $result = $store->save($staff, $conversation, 'from the laptop, stale', $laptopSawUpdatedAt);

    expect($result['conflict'])->toBeTrue()
        ->and($store->for($staff, $conversation)?->body)->toBe('from the phone')
        // The toast the loser shows exists in both launch languages (§14).
        ->and(__('messaging.draft.updated_elsewhere', locale: 'en'))->not->toContain('messaging.')
        ->and(__('messaging.draft.updated_elsewhere', locale: 'am'))->not->toContain('messaging.');
});

test('FR-20: cleared on send — a tombstone (the table cannot DELETE), and restore finds nothing', function () {
    [$conversation, $staff] = notesFixture();
    $store = app(DraftStore::class);

    $store->save($staff, $conversation, 'about to send', null);
    $store->clearOnSend($staff, $conversation);

    expect($store->for($staff, $conversation))->toBeNull()
        ->and(App\Models\MessageDraft::query()->count())->toBe(1); // the row remains, tombstoned
});

test('FR-26: draft.saved is LOCAL ONLY — absent from the catalog, so the outbox physically rejects it', function () {
    expect(EventCatalog::names())->not->toContain('draft.saved');

    [$conversation, $staff] = notesFixture();
    $store = app(DraftStore::class);
    $store->save($staff, $conversation, 'a draft', null);

    // No draft activity ever touched the bus.
    expect(DB::table('outbox')->count())->toBe(0);
});
