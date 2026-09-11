<?php

declare(strict_types=1);

use App\Support\Timers\DueSet;
use Illuminate\Support\Facades\Redis;

/*
| A-65 — the platform timer kernel's laws: candidates only (due() answers by
| the caller's OWN clock, at-or-before), a canceled member is gone, a lost
| ZSET rebuilds from column truth at the exact deadline (delayed, never
| premature), members parse round-trip, and kinds are isolated ZSETs.
|
| PLACEMENT (the B✓ fix-first ruling, 2026-09-11): the kernel talks to
| redis-durable, so this suite lives in the tier that owns a live stack —
| tests/Unit is app-less BY DESIGN and CI's Stage 2 runs with no services
| (runs #330–#333 red on exactly this file booting the app there). Never a
| skip, never a Redis stub in a unit test.
*/

function dueSetKernel(): DueSet
{
    Redis::connection('durable')->command('del', ['due:t_kernel', 'due:t_other']);

    return new DueSet;
}

it('due() answers at-or-before the CALLER\'s clock only — a future member never fires early', function (): void {
    $kernel = dueSetKernel();
    $kernel->schedule('t_kernel', 'tenant-a|row-1', 1_000_000);
    $kernel->schedule('t_kernel', 'tenant-a|row-2', 2_000_000);

    expect($kernel->due('t_kernel', 999_999))->toBe([])
        ->and($kernel->due('t_kernel', 1_000_000))->toBe(['tenant-a|row-1'])
        ->and($kernel->due('t_kernel', 5_000_000))->toBe(['tenant-a|row-1', 'tenant-a|row-2']);
});

it('cancel removes; re-schedule moves the score; kinds are isolated sets', function (): void {
    $kernel = dueSetKernel();
    $kernel->schedule('t_kernel', 'tenant-a|row-1', 1_000_000);
    $kernel->cancel('t_kernel', 'tenant-a|row-1');
    expect($kernel->due('t_kernel', 9_000_000))->toBe([]);

    $kernel->schedule('t_kernel', 'tenant-a|row-1', 1_000_000);
    $kernel->schedule('t_kernel', 'tenant-a|row-1', 8_000_000); // ZADD replaces the score
    expect($kernel->due('t_kernel', 7_000_000))->toBe([]);

    $kernel->schedule('t_other', 'tenant-a|row-9', 1_000_000);
    expect($kernel->due('t_kernel', 1_000_000))->toBe([])
        ->and($kernel->due('t_other', 1_000_000))->toBe(['tenant-a|row-9']);
});

it('rebuild restores from column truth at the exact deadline — a lost ZSET delays, never fires early', function (): void {
    $kernel = dueSetKernel();
    Redis::connection('durable')->command('del', ['due:t_kernel']); // "the ZSET is lost"

    $restored = $kernel->rebuild('t_kernel', function (): iterable {
        yield 'tenant-a|row-1' => 3_000_000;
        yield 'tenant-b|row-2' => 4_000_000;
    });

    expect($restored)->toBe(2)
        ->and($kernel->due('t_kernel', 2_999_999))->toBe([], 'restored at the COLUMN deadline — never earlier')
        ->and($kernel->due('t_kernel', 4_000_000))->toBe(['tenant-a|row-1', 'tenant-b|row-2']);
});

it('members parse round-trip (the id half may itself carry pipes-free uuids only)', function (): void {
    expect(DueSet::member('tenant-a', 'row-1'))->toBe('tenant-a|row-1')
        ->and(DueSet::parseMember('tenant-a|row-1'))->toBe(['tenant-a', 'row-1']);
    expect(fn () => (new DueSet)->schedule('Bad Kind!', 'm', 1))->toThrow(InvalidArgumentException::class);
});
