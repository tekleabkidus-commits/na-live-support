<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Domain\Conversations\Storage\OpenTicketExists;
use App\Models\Conversation;
use App\Models\Customer;
use App\Support\Http\CursorPagination;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Conversation + message schema physics (Doc 04 S20 step 2)
|--------------------------------------------------------------------------
|
| FR-01 identity uniqueness · FR-03 never-deleted as GRANT physics · FR-04/BR-05
| one-open-ticket invariant · FR-05/FR-06 field constraints as CHECK physics ·
| FR-07/BR-04 receipt-order truth with the composite cursor (T-08's first real
| consumer). Everything below drives the REPOSITORIES (T-01/R-05) — the only
| sanctioned path to these tables.
|
| WHY THE RAW FIXTURES NO LONGER NAME `created_at` (R-19a, ADR 0010). The app role has
| lost column-level INSERT on `messages.created_at`, so no fixture on this connection
| may supply it. Two of the three raw inserts here never cared about the value — they
| exist to trip a CHECK constraint — and simply let the DB default fill it. The FR-07
| burst genuinely needs an exact tie, and forces one with an UPDATE after the insert:
| R-19a revoked INSERT on that column, not UPDATE, and the UPDATE runs on the SAME
| connection and transaction as the conversation it points at.
|
| It deliberately does NOT route these through `pgsql_platform` the way RetentionTest
| and MessagePartitionTest do. Those suites commit their fixtures; these do not, so a
| second connection cannot see the in-transaction conversation row and every insert
| would die on the FK instead of on the constraint under test — which is exactly what
| happened when this was first tried, and it made two CHECK tests pass for the wrong
| reason.
*/

function conversationsFixture(): array
{
    $tenant = createTenant();
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'voice'],
            supportsTyping: true,
            supportsEdit: true,
            supportsDelete: true,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create([
        'display_name' => 'Fixture Customer',
        'username' => '@fixture_customer',
        'is_shell' => true,
    ]);

    return [$tenant, $customer];
}

// ── FR-01: one conversation per {tenant, channel endpoint, external chat} ─────

test('FR-01: the same channel identity always resolves to the same conversation', function () {
    [, $customer] = conversationsFixture();
    $repo = app(ConversationRepository::class);

    $first = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-100', $customer->identifier());
    $second = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-100', $customer->identifier());
    $other = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-200', $customer->identifier());

    expect($second->identifier())->toBe($first->identifier())
        ->and($other->identifier())->not->toBe($first->identifier());
});

test('FR-01: the identity unique index refuses a duplicate at the database layer', function () {
    [, $customer] = conversationsFixture();
    app(ConversationRepository::class)->findOrCreateForInbound('fakechan', 'bot-1', 'chat-dup', $customer->identifier());

    expect(fn () => Conversation::query()->create([
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-dup',
        'customer_id' => $customer->identifier(),
        'state' => 'active',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('FR-09: an unregistered channel is refused by the repository boundary', function () {
    [, $customer] = conversationsFixture();

    expect(fn () => app(ConversationRepository::class)
        ->findOrCreateForInbound('nochan', 'bot-1', 'chat-1', $customer->identifier()))
        ->toThrow(DomainException::class);
});

// ── FR-04/BR-05: the one-open-ticket invariant ────────────────────────────────

test('FR-04: the open-ticket slot claims atomically and refuses a second open with open_ticket_exists', function () {
    [, $customer] = conversationsFixture();
    $repo = app(ConversationRepository::class);
    $conversation = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-t1', $customer->identifier());

    $ticketA = (string) Str::uuid7();
    $ticketB = (string) Str::uuid7();

    $repo->claimOpenTicket($conversation, $ticketA);
    expect($conversation->open_ticket_id)->toBe($ticketA);

    // Positive control above; now the refusal, carrying the stable code (C-04).
    try {
        $repo->claimOpenTicket($conversation, $ticketB);
        $this->fail('a second open ticket must refuse (BR-05)');
    } catch (OpenTicketExists $e) {
        expect($e->existingTicketId)->toBe($ticketA)
            ->and(OpenTicketExists::CODE)->toBe('open_ticket_exists');
    }
});

test('FR-04: release is holder-only — a stale ticket cannot clobber the slot', function () {
    [, $customer] = conversationsFixture();
    $repo = app(ConversationRepository::class);
    $conversation = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-t2', $customer->identifier());

    $holder = (string) Str::uuid7();
    $stale = (string) Str::uuid7();
    $repo->claimOpenTicket($conversation, $holder);

    expect($repo->releaseOpenTicket($conversation, $stale))->toBeFalse()
        ->and($conversation->open_ticket_id)->toBe($holder)
        ->and($repo->releaseOpenTicket($conversation, $holder))->toBeTrue()
        ->and($conversation->open_ticket_id)->toBeNull();
});

// ── FR-05/FR-06: field physics ────────────────────────────────────────────────

test('FR-06: a body over 16 KB is refused by CHECK constraint, not by validation', function () {
    [, $customer] = conversationsFixture();
    $repo = app(ConversationRepository::class);
    $conversation = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-b1', $customer->identifier());
    $messages = app(MessageRepository::class);

    // Positive control: exactly at the boundary stores.
    $atLimit = $messages->persistOutbound($conversation, 'agent', null, 'text', str_repeat('a', 16384));
    expect($atLimit->identifier())->toBeUuid();

    expect(fn () => $messages->persistOutbound($conversation, 'agent', null, 'text', str_repeat('a', 16385)))
        ->toThrow(QueryException::class);
});

test('FR-05/SEC-07 row shadow: kind=note must be is_internal and nothing else may claim it', function () {
    [, $customer] = conversationsFixture();
    $conversation = app(ConversationRepository::class)->findOrCreateForInbound('fakechan', 'bot-1', 'chat-n1', $customer->identifier());
    $tenantId = $conversation->getAttribute('tenant_id');

    $insert = fn (string $kind, bool $isInternal) => DB::table('messages')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'conversation_id' => $conversation->identifier(),
        'direction' => 'in',
        'author_type' => 'customer',
        'kind' => $kind,
        'body' => 'x',
        'media' => '[]',
        'is_internal' => $isInternal,
        'channel_meta' => '{}',
    ]);

    expect(fn () => $insert('note', false))->toThrow(QueryException::class)
        ->and(fn () => $insert('text', true))->toThrow(QueryException::class);
});

test('FR-13 shape: outbound rows must carry a delivery state; inbound rows must not', function () {
    [, $customer] = conversationsFixture();
    $conversation = app(ConversationRepository::class)->findOrCreateForInbound('fakechan', 'bot-1', 'chat-d1', $customer->identifier());
    $tenantId = $conversation->getAttribute('tenant_id');

    $insert = fn (string $direction, ?string $state) => DB::table('messages')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'conversation_id' => $conversation->identifier(),
        'direction' => $direction,
        'author_type' => $direction === 'in' ? 'customer' : 'agent',
        'kind' => 'text',
        'body' => 'x',
        'media' => '[]',
        'delivery_state' => $state,
        'channel_meta' => '{}',
    ]);

    expect(fn () => $insert('out', null))->toThrow(QueryException::class)
        ->and(fn () => $insert('in', 'queued'))->toThrow(QueryException::class);
});

// ── FR-03 + ADR 0003: never-deleted is grant physics ─────────────────────────

test('FR-03: the app role cannot DELETE conversations, messages, or revisions — and revisions cannot be UPDATEd', function () {
    // By catalog fact, the same shape GrantPhysicsTest sweeps schema-wide — asserted
    // here explicitly so this step's report stands on its own executed output.
    $denied = fn (string $sql) => expect(fn () => DB::statement($sql))->toThrow(QueryException::class);

    $denied('DELETE FROM conversations WHERE false');
    $denied('DELETE FROM messages WHERE false');
    $denied('DELETE FROM message_revisions WHERE false');
    $denied('UPDATE message_revisions SET prior_body = NULL WHERE false');
    $denied('DELETE FROM message_drafts WHERE false');
});

// ── FR-07/BR-04: receipt-order truth + the composite cursor (T-08) ───────────

test('FR-07: a same-second burst orders stably by (created_at, id) across cursor pages', function () {
    [, $customer] = conversationsFixture();
    $repo = app(ConversationRepository::class);
    $conversation = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-o1', $customer->identifier());
    $tenantId = $conversation->getAttribute('tenant_id');

    // Ten messages in ONE receipt second (EC-01's burst shape), inserted shuffled;
    // their UUIDv7 ids carry the arrival order the shuffle tries to hide.
    $sameSecond = '2026-08-01 10:00:00+00';
    $ids = [];
    for ($i = 0; $i < 10; $i++) {
        $ids[] = (string) Str::uuid7();
    }
    $shuffled = $ids;
    shuffle($shuffled);
    foreach ($shuffled as $id) {
        DB::table('messages')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->identifier(),
            'direction' => 'in',
            'author_type' => 'customer',
            'kind' => 'text',
            'body' => 'burst',
            'media' => '[]',
            'channel_meta' => '{}',
            // The channel claims a LATER clock for the earliest id — BR-04 says that
            // must not matter (it is metadata; EC-10 renders it, never sorts by it).
            'channel_occurred_at' => '2026-08-01 10:59:00+00',
        ]);
    }

    // FORCE THE TIE. The DB assigned ten distinct microsecond stamps, which would make
    // this test prove nothing about the tiebreak. The app role may not NAME created_at
    // on INSERT (R-19a) but may still UPDATE it, and this runs on the same connection
    // and transaction as the rows above, so they are visible.
    DB::table('messages')->whereIn('id', $ids)->update(['created_at' => $sameSecond]);

    $messages = app(MessageRepository::class);
    $seen = [];
    $cursor = null;
    do {
        $params = ['limit' => 3] + ($cursor !== null ? ['cursor' => $cursor] : []);
        $page = $messages->threadPage($conversation, CursorPagination::fromRequest(Request::create('/t', 'GET', $params)));
        foreach ($page->items as $row) {
            $seen[] = $row->id;
        }
        $cursor = $page->nextCursor;
        if ($cursor !== null) {
            expect($cursor)->not->toContain('|'); // opaque (C-05), never readable structure
        }
    } while ($cursor !== null);

    sort($ids); // UUIDv7 lexical order IS generation order
    expect($seen)->toBe($ids)
        ->and(count($seen))->toBe(10);
});
