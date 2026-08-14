<?php

declare(strict_types=1);

use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Customers\Blocking\BlockCustomer;
use App\Jobs\Customers\BlockingCascadeConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-18 / T-06 / FS-04 / EC-07 / AC-06 — blocking, belt and braces
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** Seed the cascade's event row the relay would have written (catalog shape). */
function blockingEvent(string $tenantId, string $name, string $customerId, string $actorId): string
{
    $eventId = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $eventId, 'tenant_id' => $tenantId, 'name' => $name, 'schema_version' => 1,
        'payload' => json_encode([
            'tenant_id' => $tenantId, 'customer_id' => $customerId,
            ($name === 'customer.blocked' ? 'blocked_by' : 'unblocked_by') => $actorId,
        ], JSON_THROW_ON_ERROR),
    ]);

    return $eventId;
}

it('SABOTAGE-16 CONTROL #3 (inverted R-19): blocked_at is a BYTE-COPY of the block event\'s own outbox stamp — never a fresh clock', function (): void {
    $tenant = createTenant();
    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    $eventId = app(BlockCustomer::class)->block($customerId, 'fixture: byte copy', $actor->identifier());

    $outboxStamp = (string) DB::selectOne('SELECT occurred_at::text AS s FROM outbox WHERE event_id = ?', [$eventId])->s;
    $written = (string) DB::selectOne('SELECT blocked_at::text AS s FROM customers WHERE id = ?', [$customerId])->s;

    expect($written)->toBe($outboxStamp);
});

it('AC-06: the cascade quiets EVERY channel, unblock restores — and a conversation blocked INDIVIDUALLY before the customer block SURVIVES the unblock (the addition-7 memory)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    // The hybrid pair (two channels/bots) + one conversation ALREADY blocked
    // individually (Doc 04's own block, before the customer-level act).
    $a = anchoredConversation($tenantId, $customerId, 'tg-blk-1', 'bot-business');
    $b = anchoredConversation($tenantId, $customerId, 'tg-blk-1', 'bot-direct');
    $individually = anchoredConversation($tenantId, $customerId, 'tg-blk-2');
    /** @var App\Models\Conversation $pre */
    $pre = App\Models\Conversation::query()->findOrFail($individually);
    app(App\Domain\Conversations\Actions\ConversationBlocking::class)->block($pre, null, 'fixture: single-channel nuisance');

    app(BlockCustomer::class)->block($customerId, 'fixture: abuse after declined payout', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.blocked', $customerId, $actor->identifier())))
        ->handle(app(App\Domain\Conversations\Actions\ConversationBlocking::class));

    expect(DB::table('conversations')->whereIn('id', [$a, $b, $individually])->where('state', 'blocked')->count())->toBe(3)
        ->and(DB::table('customer_block_cascade_items')->whereNull('restored_at')->count())->toBe(2)
        ->and(DB::table('audit_log')->where('action_key', 'customer.blocked')->count())->toBe(1);

    app(BlockCustomer::class)->unblock($customerId, 'fixture: appeal accepted', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.unblocked', $customerId, $actor->identifier())))
        ->handle(app(App\Domain\Conversations\Actions\ConversationBlocking::class));

    // The cascade-blocked pair restored; the individual block SURVIVED.
    expect(DB::table('conversations')->where('id', $a)->value('state'))->toBe('active')
        ->and(DB::table('conversations')->where('id', $b)->value('state'))->toBe('active')
        ->and(DB::table('conversations')->where('id', $individually)->value('state'))->toBe('blocked')
        ->and(DB::table('customer_block_cascade_items')->whereNull('restored_at')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action_key', 'customer.unblocked')->count())->toBe(1);
});

it('AC-06 (the mid-cascade clause, sabotage #8\'s subject): a conversation created AFTER the block but BEFORE any cascade is STILL refused by the outbound guard', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    app(BlockCustomer::class)->block($customerId, 'fixture: mid-cascade', $actor->identifier());

    // The cascade has NOT run. A brand-new conversation appears — its state
    // is 'active', so the conversation-level check alone would let this
    // through. T-06's second belt refuses it.
    $fresh = anchoredConversation($tenantId, $customerId, 'tg-mid-cascade');
    /** @var App\Models\Conversation $conversation */
    $conversation = App\Models\Conversation::query()->findOrFail($fresh);

    expect(fn () => app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', 'should never send'))
        ->toThrow(DomainException::class, 'customer_blocked');

    expect(DB::table('messages')->where('conversation_id', $fresh)->count())->toBe(0);
});

it('FS-04: a PARTIAL cascade (crash mid-way) leaves the guard holding; the re-driven consumer completes the remainder — the repair IS the redelivery (C-09)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    $a = anchoredConversation($tenantId, $customerId, 'tg-fs4-1', 'bot-business');
    $b = anchoredConversation($tenantId, $customerId, 'tg-fs4-2', 'bot-direct');

    app(BlockCustomer::class)->block($customerId, 'fixture: crash drill', $actor->identifier());

    // Simulate the crash's leftover: only conversation A got blocked (a
    // partial first delivery — recorded, blocked, then death).
    /** @var App\Models\Conversation $ca */
    $ca = App\Models\Conversation::query()->findOrFail($a);
    app(App\Domain\Conversations\Actions\ConversationBlocking::class)->block($ca, null, 'fixture: partial cascade leftover');
    DB::statement('INSERT INTO customer_block_cascade_items (id, tenant_id, customer_id, conversation_id) VALUES (?, ?, ?, ?)',
        [(string) Str::uuid7(), $tenantId, $customerId, $a]);

    // The guard holds for BOTH conversations regardless of cascade state.
    /** @var App\Models\Conversation $cb */
    $cb = App\Models\Conversation::query()->findOrFail($b);
    expect(fn () => app(ComposeOutbound::class)->compose($cb, 'agent', null, 'text', 'gap probe'))
        ->toThrow(DomainException::class, 'customer_blocked');

    // The REDELIVERY completes the cascade: A skipped (already blocked and
    // recorded — the unique index refuses a double record), B blocked.
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.blocked', $customerId, $actor->identifier())))
        ->handle(app(App\Domain\Conversations\Actions\ConversationBlocking::class));

    expect(DB::table('conversations')->where('id', $b)->value('state'))->toBe('blocked')
        ->and(DB::table('customer_block_cascade_items')->where('customer_id', $customerId)->count())->toBe(2);
});

it('EC-07 (the documented limitation): a blocked customer\'s brand-new channel identity is a FRESH, UNBLOCKED shell — no auto-link, no inherited block (BR-02)', function (): void {
    $tenant = createTenant();
    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();
    app(BlockCustomer::class)->block($customerId, 'fixture: the evader', $actor->identifier());

    // The same human returns on a new Telegram account. The resolver mints a
    // fresh shell — and it is NOT blocked, because auto-linking is the guess
    // this product refuses to make anywhere (F05's frame: merge-then-inherit
    // or block the shell are the STAFF answers).
    $result = app(App\Domain\Customers\Identity\IdentityResolution::class)->resolveInbound(
        'telegram',
        new App\Domain\Conversations\Adapters\InboundSender('evader-new-acct', 'New Account', '@yosef_k2'),
    );

    expect($result->created)->toBeTrue()
        ->and($result->customerId)->not->toBe($customerId)
        ->and((bool) DB::table('customers')->where('id', $result->customerId)->value('blocked'))->toBeFalse()
        ->and(app(App\Domain\Customers\Blocking\CustomerSendGuard::class)->refuses($result->customerId))->toBeFalse();
});

it('A-37 noops both directions: blocking a blocked customer and unblocking an unblocked one are honest no-changes', function (): void {
    $tenant = createTenant();
    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    expect(app(BlockCustomer::class)->unblock($customerId, 'fixture: nothing to lift', $actor->identifier()))->toBeNull();

    app(BlockCustomer::class)->block($customerId, 'fixture: once', $actor->identifier());
    expect(app(BlockCustomer::class)->block($customerId, 'fixture: twice', $actor->identifier()))->toBeNull()
        ->and(DB::table('outbox')->where('name', 'customer.blocked')->count())->toBe(1);
});

it('THE OWED CYCLE (step-7 acceptance): block → unblock → RE-block → unblock, with an individually-blocked conversation partway — the correct set restores at BOTH unblocks (the marker re-arms)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();
    $blocking = app(App\Domain\Conversations\Actions\ConversationBlocking::class);

    $a = anchoredConversation($tenantId, $customerId, 'tg-cyc-1', 'bot-business');
    $b = anchoredConversation($tenantId, $customerId, 'tg-cyc-2', 'bot-direct');

    // CYCLE 1: block cascades over both; unblock restores both.
    app(BlockCustomer::class)->block($customerId, 'fixture: cycle 1', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.blocked', $customerId, $actor->identifier())))->handle($blocking);
    app(BlockCustomer::class)->unblock($customerId, 'fixture: lift 1', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.unblocked', $customerId, $actor->identifier())))->handle($blocking);

    expect(DB::table('conversations')->whereIn('id', [$a, $b])->where('state', 'active')->count())->toBe(2);

    // PARTWAY: conversation B gets blocked INDIVIDUALLY between the cycles.
    /** @var App\Models\Conversation $cb */
    $cb = App\Models\Conversation::query()->findOrFail($b);
    $blocking->block($cb, null, 'fixture: individual nuisance between cycles');

    // CYCLE 2: re-block — the marker for A must RE-ARM (its cycle-1 row is
    // stamped restored); B is already blocked so the cascade must NOT claim it.
    app(BlockCustomer::class)->block($customerId, 'fixture: cycle 2', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.blocked', $customerId, $actor->identifier())))->handle($blocking);

    expect(DB::table('conversations')->whereIn('id', [$a, $b])->where('state', 'blocked')->count())->toBe(2)
        ->and(DB::table('customer_block_cascade_items')->where('conversation_id', $a)->whereNull('restored_at')->count())->toBe(1)
        ->and(DB::table('customer_block_cascade_items')->where('conversation_id', $b)->whereNull('restored_at')->count())->toBe(0);

    // SECOND unblock: A restores (the re-armed marker), B SURVIVES — the
    // silent failure modes were "A blocked forever" and "B released by
    // someone else's unblock"; both are refuted here.
    app(BlockCustomer::class)->unblock($customerId, 'fixture: lift 2', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.unblocked', $customerId, $actor->identifier())))->handle($blocking);

    expect(DB::table('conversations')->where('id', $a)->value('state'))->toBe('active')
        ->and(DB::table('conversations')->where('id', $b)->value('state'))->toBe('blocked')
        ->and(DB::table('customer_block_cascade_items')->whereNull('restored_at')->count())->toBe(0);
});

it('THE RACED WITHDRAW (fold ownership): a conversation blocked between LIST and ACT is withdrawn — on a RE-ARMED pre-existing marker, whose own row id the upsert RETURNING hands back', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();
    $blocking = app(App\Domain\Conversations\Actions\ConversationBlocking::class);

    $a = anchoredConversation($tenantId, $customerId, 'tg-race-1', 'bot-business');
    $b = anchoredConversation($tenantId, $customerId, 'tg-race-2', 'bot-direct');

    // CYCLE 1 lays down the pre-existing markers, both stamped restored.
    app(BlockCustomer::class)->block($customerId, 'fixture: race cycle 1', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.blocked', $customerId, $actor->identifier())))->handle($blocking);
    app(BlockCustomer::class)->unblock($customerId, 'fixture: race lift 1', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.unblocked', $customerId, $actor->identifier())))->handle($blocking);
    $cycle1RowId = (string) DB::table('customer_block_cascade_items')->where('conversation_id', $b)->value('id');

    // THE RACER: the cascade writes B's marker BEFORE it re-reads B's state —
    // this listener fires on exactly that upsert and blocks B individually in
    // the gap, the deterministic version of "blocked between list and act".
    $raceArmed = false;
    DB::listen(function ($query) use (&$raceArmed, $b, $blocking): void {
        if ($raceArmed || ! str_contains($query->sql, 'customer_block_cascade_items') || ! in_array($b, $query->bindings ?? [], true)) {
            return;
        }
        $raceArmed = true;
        $blocking->block(App\Models\Conversation::query()->findOrFail($b), null, 'fixture: the racer, between list and act');
    });

    // CYCLE 2: the cascade lists both as active, re-arms both markers, blocks
    // A itself — and finds B already taken. The withdraw must stamp B's
    // CYCLE-1 row (the id the DO UPDATE returned), never a duplicate.
    app(BlockCustomer::class)->block($customerId, 'fixture: race cycle 2', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.blocked', $customerId, $actor->identifier())))->handle($blocking);

    expect($raceArmed)->toBeTrue()
        ->and(DB::table('customer_block_cascade_items')->where('conversation_id', $b)->count())->toBe(1)
        ->and((string) DB::table('customer_block_cascade_items')->where('conversation_id', $b)->value('id'))->toBe($cycle1RowId)
        ->and(DB::table('customer_block_cascade_items')->where('conversation_id', $b)->whereNull('restored_at')->count())->toBe(0)
        ->and(DB::table('customer_block_cascade_items')->where('conversation_id', $a)->whereNull('restored_at')->count())->toBe(1);

    // The unblock releases only A — the racer's block on B SURVIVES, because
    // the withdrawn claim means the cascade never owned it.
    app(BlockCustomer::class)->unblock($customerId, 'fixture: race lift 2', $actor->identifier());
    (new BlockingCascadeConsumer(blockingEvent($tenantId, 'customer.unblocked', $customerId, $actor->identifier())))->handle($blocking);

    expect(DB::table('conversations')->where('id', $a)->value('state'))->toBe('active')
        ->and(DB::table('conversations')->where('id', $b)->value('state'))->toBe('blocked')
        ->and(DB::table('customer_block_cascade_items')->whereNull('restored_at')->count())->toBe(0);
});
