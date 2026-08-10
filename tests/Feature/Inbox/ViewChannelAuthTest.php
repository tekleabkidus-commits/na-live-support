<?php

declare(strict_types=1);

use App\Domain\Inbox\Realtime\ViewChannelGate;
use App\Domain\Inbox\Realtime\ViewHash;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| SEC-01 on subscribe (S07 step 3) — the T-06 precedent applied to view
| channels: hashes embed scope context; subscribe re-authorizes by
| RE-DERIVATION; a scope change breaks the stale hash and forces the
| resubscribe with pruned membership. SABOTAGE #12's TARGETS live here.
|--------------------------------------------------------------------------
*/

it('SEC-01: a viewer is admitted to exactly the channels their CURRENT capabilities derive — their own per-user views and their scope\'s shared variant; everything else is the same silent false', function (): void {
    [$tenant] = ticketFixture('open');
    $all = inboxScopedActor('All', 'S07 CH All');
    $own = inboxScopedActor('Own', 'S07 CH Own');
    $stranger = User::factory()->create(); // no role at all → no tickets.view

    $gate = app(ViewChannelGate::class);
    $hashes = app(ViewHash::class);

    // Positive controls: own per-user view; the All-scope shared variant.
    expect($gate->authorize($all, $hashes->forViewer('my_open', $all)))->toBeTrue()
        ->and($gate->authorize($all, $hashes->forViewer('unassigned', $all)))->toBeTrue()
        ->and($gate->authorize($own, $hashes->forViewer('my_open', $own)))->toBeTrue();

    // ANOTHER user's per-user channel: refused (the hash embeds THEIR id).
    expect($gate->authorize($own, $hashes->forViewer('my_open', $all)))->toBeFalse('a viewer subscribed to a colleague\'s my_open channel');

    // The Own-scoped viewer presenting the ALL-scope shared hash: refused —
    // their own derivation is own:{id}, never all (BR-03 at the socket).
    expect($gate->authorize($own, $hashes->forViewer('unassigned', $all)))->toBeFalse('an Own-scoped viewer authorized onto the All-scope variant channel');

    // No tickets.view → nothing authorizes, not even a per-user shape.
    expect($gate->authorize($stranger, $hashes->forViewer('my_open', $stranger)))->toBeFalse();

    // Garbage hashes: the same silent false (C-10 — the socket explains nothing).
    expect($gate->authorize($all, 'not-a-hash'))->toBeFalse()
        ->and($gate->authorize($all, hash('sha256', 'forged')))->toBeFalse();
});

it('SEC-01: a SCOPE CHANGE breaks the previously valid hash — the forced-resubscribe mechanism, executed', function (): void {
    [$tenant] = ticketFixture('open');
    $user = inboxScopedActor('All', 'S07 CH Mutable');
    $gate = app(ViewChannelGate::class);
    $hashes = app(ViewHash::class);

    // Valid under All scope…
    $staleHash = $hashes->forViewer('unassigned', $user);
    expect($gate->authorize($user, $staleHash))->toBeTrue();

    // …the role narrows (Doc 03 FR-07) — the capability cache invalidates…
    $narrow = App\Models\Role::query()->firstOrCreate(
        ['name' => 'S07 CH Narrowed'],
        [
            'color' => 'role-agent',
            'permissions' => ['tickets.view'],
            'scopes' => ['tickets' => 'Own', 'conversations' => 'Own', 'customers' => 'Own', 'reports' => 'Own'],
        ],
    );
    $user->forceFill(['role_id' => $narrow->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    // …and the OLD hash stops authorizing while the NEW derivation works:
    // the client MUST resubscribe under its pruned variant. That is SEC-01's
    // "force resubscribe with pruned membership pushed", as physics.
    expect($gate->authorize($user, $staleHash))->toBeFalse('a stale pre-scope-change hash still authorizes — sabotage #12\'s shape')
        // The NEW derivation is the own:{id} variant — the pruned-membership
        // channel the client resubscribes to (its ZSET materializes lazily at
        // step 4 per A-30; the CHANNEL authorizes from birth)…
        ->and($hashes->forViewer('unassigned', $user))->not->toBe($staleHash)
        ->and($gate->authorize($user, $hashes->forViewer('unassigned', $user)))->toBeTrue('the pruned variant channel must authorize — it is what the forced resubscribe lands on')
        ->and($gate->authorize($user, $hashes->forViewer('my_open', $user)))->toBeTrue('the per-user channel must survive the narrowing');
});
