<?php

declare(strict_types=1);

use App\Domain\Customers\Identity\DuplicateIdentity;
use App\Domain\Customers\Identity\LinkIdentity;
use App\Domain\Customers\Identity\UnlinkIdentity;
use App\Domain\Customers\Identity\UnlinkMoveSet;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-09/FR-10 — link & unlink (AC-02's FR-09 half; AC-04 whole;
| EC-05; A-42's anchor rule, both directions)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

// anchoredConversation() lives in tests/Pest.php (shared with the merge suites).

it('FR-09: a staff-linked phone stores E.164, source=staff, DB-time provenance — and the event + audit rows land', function (): void {
    $tenant = createTenant();
    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    $identityId = app(LinkIdentity::class)->link($customerId, 'phone', '0912344417', (string) $actor->getKey());

    $identity = DB::table('customer_identities')->where('id', $identityId)->first();
    expect($identity->type)->toBe('phone')
        ->and($identity->value)->toBe('+251912344417')     // ADR 0014: normalized ONCE at write
        ->and($identity->source)->toBe('staff')
        ->and($identity->first_seen_at)->not->toBeNull();

    expect(DB::table('outbox')->where('name', 'customer.identity.linked')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'customer.identity.linked')->count())->toBe(1);
});

it('FR-09/AC-02 (the block half): the value on ANOTHER customer refuses with that customer named and the merge offer — and the door answers the stable 409', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    $holder = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $asker = enforcedCustomer($tenant->identifier(), '2026-06-02 10:00:00+00');
    $actor = ticketActor(['customers.identity.link']);

    app(LinkIdentity::class)->link($holder, 'phone', '+251900887766', (string) $actor->getKey());

    // Service level: the typed refusal names the holder.
    try {
        app(LinkIdentity::class)->link($asker, 'phone', '0900887766', (string) $actor->getKey());
        $this->fail('the duplicate phone was ACCEPTED — FR-09\'s block is gone');
    } catch (DuplicateIdentity $e) {
        expect($e->existingCustomerId)->toBe($holder);
    }

    // Door level: 409, C-04 stable code, the id + offer — and nothing changed.
    test()->actingAs($actor, 'staff')
        ->postJson('https://localhost/api/staff/customers/'.$asker.'/identities', ['type' => 'phone', 'value' => '0900887766'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'identity_already_linked')
        ->assertJsonPath('existing_customer_id', $holder)
        ->assertJsonPath('merge_offer', true);

    bindTenant($tenant);    // the request lifecycle cleared the RLS binding on its way out
    expect(DB::table('customer_identities')->where('value', '+251900887766')->count())->toBe(1);
});

it('linking the SAME value to the SAME customer is the A-37 noop — the guarded route passes with zero new audit rows via the causal declaration', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $actor = ticketActor(['customers.identity.link']);

    app(LinkIdentity::class)->link($customerId, 'email', 'Fixture@Example.ET', (string) $actor->getKey());
    expect(DB::table('customer_identities')->where('type', 'email')->value('value'))->toBe('fixture@example.et');

    $auditBefore = DB::table('audit_log')->where('action_key', 'customer.identity.linked')->count();

    test()->actingAs($actor, 'staff')
        ->postJson('https://localhost/api/staff/customers/'.$customerId.'/identities', ['type' => 'email', 'value' => 'fixture@example.et'])
        ->assertOk()
        ->assertJsonPath('linked', false);

    bindTenant($tenant);    // the request lifecycle cleared the RLS binding on its way out
    expect(DB::table('audit_log')->where('action_key', 'customer.identity.linked')->count())->toBe($auditBefore)
        ->and(DB::table('customer_identities')->where('type', 'email')->count())->toBe(1);
});

it('AC-04: the typed confirmation and the executed move come from ONE source — preview == moved, exactly, with the A-42 anchor rule proven in both directions', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $actor = ticketActor(['customers.identity.unlink']);

    // The telegram identity being unlinked, with TWO anchored conversations
    // (external_chat_id == identity value — Telegram private-chat physics)…
    $identityId = (string) new Symfony\Component\Uid\UuidV7;
    DB::table('customer_identities')->insert([
        'id' => $identityId, 'tenant_id' => $tenantId, 'customer_id' => $customerId,
        'channel' => 'telegram', 'external_user_id' => 'tg-900800',
        'type' => 'telegram', 'value' => 'tg-900800', 'source' => 'channel_auto',
        'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // …the Doc 05 EC-04 HYBRID pair: the Business bot's conversation and the
    // direct bot's, SAME user — two conversations, one identity, both anchored.
    $anchoredA = anchoredConversation($tenantId, $customerId, 'tg-900800', 'bot-business');
    $anchoredB = anchoredConversation($tenantId, $customerId, 'tg-900800', 'bot-direct');
    // …and one UNRELATED conversation (a different chat) that must STAY.
    $unrelated = anchoredConversation($tenantId, $customerId, 'tg-other-chat');

    rollupTicket($tenantId, $anchoredA, $customerId, 'open');
    rollupTicket($tenantId, $anchoredA, $customerId, 'closed');
    rollupTicket($tenantId, $unrelated, $customerId, 'closed');

    // The preview — the confirmation's content, from the single source.
    $preview = test()->actingAs($actor, 'staff')
        ->getJson('https://localhost/api/staff/customers/'.$customerId.'/identities/'.$identityId.'/unlink-preview')
        ->assertOk()->json();

    expect($preview['conversations'])->toBe(2)
        ->and($preview['tickets'])->toBe(2)
        ->and($preview['open_ticket_ids'])->toHaveCount(1);

    // Execute through the real guarded door.
    $moved = test()->actingAs($actor, 'staff')
        ->postJson('https://localhost/api/staff/customers/'.$customerId.'/identities/'.$identityId.'/unlink', ['reason' => 'fixture: separating a shared account', 'confirm' => 'UNLINK'])
        ->assertOk()->json();

    bindTenant($tenant);    // the request lifecycle cleared the RLS binding on its way out

    // AC-04's identity: what the confirmation said == what actually moved.
    $shellId = $moved['new_customer_id'];
    expect($moved['moved_conversations'])->toBe($preview['conversations'])
        ->and($moved['moved_tickets'])->toBe($preview['tickets']);

    $movedConversations = DB::table('conversations')->where('customer_id', $shellId)->pluck('id')->all();
    sort($movedConversations);
    $expected = [$anchoredA, $anchoredB];
    sort($expected);
    expect($movedConversations)->toBe($expected)
        ->and(DB::table('conversations')->where('id', $unrelated)->value('customer_id'))->toBe($customerId)
        ->and(DB::table('tickets')->where('customer_id', $shellId)->count())->toBe(2)
        ->and(DB::table('customer_identities')->where('id', $identityId)->value('customer_id'))->toBe($shellId);

    // Both sides' rollups recomputed in the same transaction.
    expect(DB::table('customers')->where('id', $shellId)->value('tickets_count'))->toBe(2)
        ->and(DB::table('customers')->where('id', $customerId)->value('tickets_count'))->toBe(1);

    expect(DB::table('audit_log')->where('action_key', 'customer.identity.unlinked')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'customer.identity.unlinked')->count())->toBe(1);
});

it('EC-05: unlinking the ONLY identity is allowed — the profile keeps its staff data with zero identities; the shell carries the conversations', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['display_name' => 'ኪዳነ ሃይሉ', 'tags' => '{vip}']);
    $actor = App\Models\User::factory()->create();

    $identityId = (string) new Symfony\Component\Uid\UuidV7;
    DB::table('customer_identities')->insert([
        'id' => $identityId, 'tenant_id' => $tenantId, 'customer_id' => $customerId,
        'channel' => 'telegram', 'external_user_id' => 'tg-900900',
        'type' => 'telegram', 'value' => 'tg-900900', 'source' => 'channel_auto',
        'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    anchoredConversation($tenantId, $customerId, 'tg-900900');

    $moved = app(UnlinkIdentity::class)->unlink($identityId, 'fixture: EC-05', (string) $actor->getKey());

    $original = DB::table('customers')->where('id', $customerId)->first();
    expect(DB::table('customer_identities')->where('customer_id', $customerId)->count())->toBe(0)
        ->and($original->display_name)->toBe('ኪዳነ ሃይሉ')
        ->and($original->tags)->toBe('{vip}')
        ->and(DB::table('conversations')->where('customer_id', $moved['new_customer_id'])->count())->toBe(1);
});

it('A-42\'s consequence: a phone identity anchors NO conversations — unlinking it moves the identity alone', function (): void {
    $tenant = createTenant();
    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();
    anchoredConversation($tenant->identifier(), $customerId, 'tg-someone');

    $identityId = app(LinkIdentity::class)->link($customerId, 'phone', '+251900112233', (string) $actor->getKey());

    expect(app(UnlinkMoveSet::class)->for($identityId)['conversation_ids'])->toBe([]);

    $moved = app(UnlinkIdentity::class)->unlink($identityId, 'fixture: phone off', (string) $actor->getKey());
    expect($moved['moved_conversations'])->toBe(0)
        ->and(DB::table('conversations')->where('customer_id', $customerId)->count())->toBe(1);
});

it('C-10: a mismatched customer/identity pairing answers 404 — indistinguishable from missing', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    $a = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $b = enforcedCustomer($tenant->identifier(), '2026-06-02 10:00:00+00');
    $actor = ticketActor(['customers.identity.unlink']);

    $identityId = (string) new Symfony\Component\Uid\UuidV7;
    DB::table('customer_identities')->insert([
        'id' => $identityId, 'tenant_id' => $tenant->identifier(), 'customer_id' => $a,
        'channel' => 'telegram', 'external_user_id' => 'tg-901000',
        'type' => 'telegram', 'value' => 'tg-901000', 'source' => 'channel_auto',
        'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    test()->actingAs($actor, 'staff')
        ->getJson('https://localhost/api/staff/customers/'.$b.'/identities/'.$identityId.'/unlink-preview')
        ->assertNotFound();
    test()->actingAs($actor, 'staff')
        ->getJson('https://localhost/api/staff/customers/'.$a.'/identities/'.(string) new Symfony\Component\Uid\UuidV7.'/unlink-preview')
        ->assertNotFound();
});
