<?php

declare(strict_types=1);

use App\Domain\Customers\Rg\RgFlagRepository;
use App\Domain\Customers\Rg\RgFlagService;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-14/FR-15 storage halves, EC-06, AC-B4-02 (server half),
| sabotage amendment #16 control #2
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('SABOTAGE-16 CONTROL #2 (inverted R-19): customers.rg_flag_set_at is a BYTE-COPY of the owning history row\'s own DB stamp — never a fresh clock', function (): void {
    [$customerId, $actorId] = rgScene();

    $historyId = app(RgFlagService::class)->set($customerId, 'fixture: byte copy', 'staff', $actorId);

    $historyStamp = (string) DB::selectOne('SELECT created_at::text AS s FROM rg_flag_history WHERE id = ?', [$historyId])->s;
    $currentStamp = (string) DB::selectOne('SELECT rg_flag_set_at::text AS s FROM customers WHERE id = ?', [$customerId])->s;

    expect($currentStamp)->toBe($historyStamp);
});

it('FR-14 set (staff): flag + provenance columns, history row, outbox event, audit entry — all in one transaction', function (): void {
    [$customerId, $actorId] = rgScene();

    $historyId = app(RgFlagService::class)->set($customerId, 'fixture: chasing losses over three sessions', 'staff', $actorId);

    $customer = DB::table('customers')->where('id', $customerId)->first();
    expect($customer->rg_flag)->toBeTrue()
        ->and($customer->rg_flag_set_by)->toBe($actorId)
        ->and($customer->rg_flag_reason)->toBe('fixture: chasing losses over three sessions');

    $history = DB::table('rg_flag_history')->where('id', $historyId)->first();
    expect($history->action)->toBe('set')
        ->and($history->source)->toBe('staff')
        ->and($history->actor_id)->toBe($actorId);

    expect(DB::table('outbox')->where('name', 'customer.rg_flag.set')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'customer.rg_flag.set')->count())->toBe(1);
});

it('FR-14 set (ai, the [D09] fixture): actor NULL by the schema\'s own CHECK, excerpt reference MANDATORY and stored', function (): void {
    [$customerId] = rgScene();
    $service = app(RgFlagService::class);

    // The mandatory-excerpt clause refuses first.
    expect(fn () => $service->set($customerId, 'fixture: ai without excerpt', 'ai', null))
        ->toThrow(InvalidArgumentException::class, 'excerpt');

    $historyId = $service->set($customerId, 'fixture: ai detection', 'ai', null, ['message_id' => '01a0-fixture', 'ticket' => 5012]);

    $history = DB::table('rg_flag_history')->where('id', $historyId)->first();
    expect($history->source)->toBe('ai')
        ->and($history->actor_id)->toBeNull()
        ->and(json_decode((string) $history->excerpt_ref, true)['message_id'])->toBe('01a0-fixture');
});

it('FR-15/EC-06 clear: permission + reason, history keeps BOTH rows forever; clearing an unset flag is the honest no-change the door will declare (A-37)', function (): void {
    [$customerId, $actorId] = rgScene();
    $service = app(RgFlagService::class);

    // Clear on an unset flag: NO history row, no event — the null return is
    // the causal fact the step-11 door ties its audit.noop declaration to.
    expect($service->clear($customerId, 'fixture: nothing to clear', $actorId))->toBeNull()
        ->and(DB::table('rg_flag_history')->count())->toBe(0);

    $service->set($customerId, 'fixture: ai detection', 'ai', null, ['message_id' => 'fixture-m1']);
    $clearId = $service->clear($customerId, 'fixture: reviewed — describing a friend\'s situation', $actorId);

    expect($clearId)->not->toBeNull();
    $customer = DB::table('customers')->where('id', $customerId)->first();
    expect($customer->rg_flag)->toBeFalse()
        ->and($customer->rg_flag_set_at)->toBeNull()
        ->and($customer->rg_flag_reason)->toBeNull();

    // EC-06: history keeps both — the false positive AND its review.
    $history = app(RgFlagRepository::class)->history($customerId);
    expect($history)->toHaveCount(2)
        ->and($history[0]->action)->toBe('set')->and($history[0]->source)->toBe('ai')
        ->and($history[1]->action)->toBe('cleared')->and($history[1]->reason)->toContain('friend');

    expect(DB::table('outbox')->where('name', 'customer.rg_flag.cleared')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'customer.rg_flag.cleared')->count())->toBe(1);
});

it('AC-B4-02 (server half): the history exports WHOLE — authors, reasons, sources, AI excerpt refs — including entries whose flag has since been cleared', function (): void {
    [$customerId, $actorId] = rgScene();
    $service = app(RgFlagService::class);

    $service->set($customerId, 'fixture: manual set', 'staff', $actorId);
    $service->clear($customerId, 'fixture: first review', $actorId);
    $service->set($customerId, 'fixture: ai re-detection', 'ai', null, ['message_id' => 'fixture-m2']);

    $history = app(RgFlagRepository::class)->history($customerId);

    expect($history)->toHaveCount(3)
        ->and(array_column($history, 'action'))->toBe(['set', 'cleared', 'set'])
        ->and($history[0]->actor_id)->toBe($actorId)
        ->and($history[2]->source)->toBe('ai')
        ->and(json_decode((string) $history[2]->excerpt_ref, true)['message_id'])->toBe('fixture-m2')
        // every row carries its reason — a trail that cannot leave whole is
        // not a trail (the export DOOR rides customers.export at step 9).
        ->and(array_filter(array_column($history, 'reason')))->toHaveCount(3);
});
