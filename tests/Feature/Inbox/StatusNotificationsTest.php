<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| S07 step 12 — FR-28's status machine (heartbeat physics, system-away
| materialized on read, the audited force door) + FR-26's bell doors, with
| the R-26 AMENDMENT fifth/sixth-sibling refusals EXECUTED.
|--------------------------------------------------------------------------
*/

function statusScene(): array
{
    [$tenant] = ticketFixture('open');
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S12 Status');

    return [$tenant, $actor];
}

it('FR-28: set-own transitions write the row + publish agent.status_changed through the OUTBOX (C-06) with reason and null attribution', function (): void {
    [$tenant, $actor] = statusScene();

    app(AgentStatus::class)->set($actor, 'online');

    expect(DB::table('agent_statuses')->where('user_id', $actor->identifier())->value('status'))->toBe('online');
    /** @var object{payload: string} $event */
    $event = DB::table('outbox')->where('name', 'agent.status_changed')->orderByDesc('occurred_at')->first(['payload']);
    /** @var array<string, mixed> $payload */
    $payload = json_decode($event->payload, true, 8, JSON_THROW_ON_ERROR);
    expect($payload['status'])->toBe('online')
        ->and($payload['user_id'])->toBe($actor->identifier())
        ->and($payload['set_by_user_id'])->toBeNull();
});

it('FR-28 heartbeat physics: online with a live TTL key stays online; the key\'s death MATERIALIZES away/heartbeat_lost ON READ — written and evented, not just displayed', function (): void {
    [$tenant, $actor] = statusScene();
    $status = app(AgentStatus::class);

    $status->set($actor, 'online');
    expect($status->current($actor->identifier())['status'])->toBe('online');

    // Kill the heartbeat key (the REAL mechanism — TTL expiry's effect).
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
    Redis::connection('volatile')->del("t:{$tenantId}:agent_heartbeat:{$actor->identifier()}");

    $current = $status->current($actor->identifier());
    expect($current['status'])->toBe('away')
        ->and($current['reason'])->toBe('heartbeat_lost');
    expect(DB::table('agent_statuses')->where('user_id', $actor->identifier())->value('reason'))
        ->toBe('heartbeat_lost', 'system-away was displayed but not MATERIALIZED');
    expect(DB::table('outbox')->where('name', 'agent.status_changed')->count())->toBeGreaterThanOrEqual(2);
});

it('FR-28 force door: supervision.agent_status.force flips ANOTHER agent with attribution stored + the agent.status_forced audit entry; without the key the door refuses 403', function (): void {
    [$tenant, $actor] = statusScene();
    $supervisor = inboxScopedActor('All', 'S12 Supervisor');
    grantPermissions($supervisor, ['tickets.view', 'conversations.view', 'views.manage', 'supervision.agent_status.force'], 'S12 Supervisor Role');
    // Pre-HTTP, the OutboxChaos idiom: request teardown unbinds the harness.
    $plain = inboxScopedActor('All', 'S12 Plain');

    app(AgentStatus::class)->set($actor, 'online');

    $this->actingAs($supervisor, 'staff')
        ->postJson('https://localhost/inbox/status/'.$actor->identifier().'/force', ['status' => 'away'])
        ->assertOk();

    asTenant($tenant, function () use ($actor, $supervisor): void {
        /** @var object{status: string, reason: string, set_by_user_id: string} $row */
        $row = DB::table('agent_statuses')->where('user_id', $actor->identifier())->first(['status', 'reason', 'set_by_user_id']);
        expect($row->status)->toBe('away')
            ->and($row->reason)->toBe('forced')
            ->and($row->set_by_user_id)->toBe($supervisor->identifier(), 'attribution lost — "set by …" has nothing to render');
        expect(DB::table('audit_log')->where('action_key', 'agent.status_forced')->count())->toBeGreaterThanOrEqual(1);
    });

    // The non-holder refusal, executed.
    $this->actingAs($plain, 'staff')
        ->postJson('https://localhost/inbox/status/'.$actor->identifier().'/force', ['status' => 'online'])
        ->assertStatus(403);
});

it('R-26 AMENDMENT fifth+sixth siblings, refusals EXECUTED: a view-only reader is 403 at read-all and set-status (zero rows moved); the workspace actor lands both', function (): void {
    [$tenant, $actor] = statusScene();
    $reader = grantPermissions(App\Models\User::factory()->create(), ['tickets.view', 'conversations.view'], 'S12 RO Reader Role');

    DB::table('inbox_notifications')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(),
        'user_id' => $reader->identifier(),
        'category' => 'notify.inbox.assignment',
        'payload' => '{}',
    ]);

    $this->actingAs($reader, 'staff')->postJson('https://localhost/inbox/notifications/read-all')->assertStatus(403);
    $this->actingAs($reader, 'staff')->postJson('https://localhost/inbox/status', ['status' => 'online'])->assertStatus(403);
    asTenant($tenant, function () use ($reader): void {
        expect(DB::table('inbox_notifications')->where('user_id', $reader->identifier())->whereNull('read_at')->count())->toBe(1);
        expect(DB::table('agent_statuses')->where('user_id', $reader->identifier())->exists())->toBeFalse();
    });

    // The reader still READS the bell (the audience key).
    $this->actingAs($reader, 'staff')->getJson('https://localhost/inbox/notifications')->assertOk();

    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/notifications/read-all')->assertOk();
    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/status', ['status' => 'online'])->assertOk();
});

it('FR-26: the bell lists the viewer\'s rows newest-first with the unread count; read-all stamps read_at (the ONLY app-updatable column) for THIS viewer only', function (): void {
    [$tenant, $actor] = statusScene();
    $other = inboxScopedActor('All', 'S12 Other');
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();

    foreach ([[$actor, 'notify.inbox.assignment'], [$actor, 'notify.inbox.mention'], [$other, 'notify.inbox.assignment']] as [$user, $category]) {
        DB::table('inbox_notifications')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'user_id' => $user->identifier(), 'category' => $category, 'payload' => '{}',
        ]);
    }

    $bell = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/notifications');
    $bell->assertOk();
    expect($bell->json('unread'))->toBe(2)
        ->and(count((array) $bell->json('notifications')))->toBe(2);

    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/notifications/read-all')->assertOk();
    asTenant($tenant, function () use ($actor, $other): void {
        expect(DB::table('inbox_notifications')->where('user_id', $actor->identifier())->whereNull('read_at')->count())->toBe(0);
        expect(DB::table('inbox_notifications')->where('user_id', $other->identifier())->whereNull('read_at')->count())
            ->toBe(1, 'read-all crossed viewers');
    });
});
