<?php

declare(strict_types=1);

use App\Domain\Customers\Panel\CustomerPayload;
use App\Domain\Customers\Rg\RgFlagRepository;
use App\Domain\Customers\Rg\RgFlagService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 AC-05 (server halves) / SEC-01 / BR-03 / NFR-06 / T-05 / AC-B4-01
|--------------------------------------------------------------------------
|
| The four proofs AC-05 names, at the level this step owns (the UI-absence
| half joins at step 11 with the surfaces):
|   1. COLUMN-LEVEL ABSENCE, QUERY-LEVEL: an unpermitted build never NAMES
|      the rg columns in SQL and the payload carries NO rg keys — absent,
|      never null (BR-03: absence over redaction; sabotage #1's subject).
|   2. Cache-bypass freshness (NFR-06 + AC-B4-01: "run it against a warm
|      cache; a passing result on a cold one proves nothing" — sabotage #2's
|      subject).
|   3. History immutability, LIVE (the grant physics exercised as 42501s).
|   4. T-05's bypass-list clause: Doc 03's own capability cache already
|      exempts customers.rg_flag.* (R-04) — asserted, not re-implemented.
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** @return array{0: string, 1: string} [customerId, actorId] */
function rgScene(): array
{
    $tenant = createTenant();
    $customerId = enforcedCustomer($tenant->identifier(), '2026-06-01 10:00:00+00');
    $actor = App\Models\User::factory()->create();

    return [$customerId, (string) $actor->getKey()];
}

it('SEC-01/BR-03: an unpermitted payload NEVER NAMES the rg columns in SQL, and the keys are ABSENT — not null (sabotage #1\'s subject)', function (): void {
    [$customerId, $actorId] = rgScene();
    app(RgFlagService::class)->set($customerId, 'fixture: chasing losses', 'staff', $actorId);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $payload = app(CustomerPayload::class)->build($customerId, viewerSeesRg: false);

    $rgMentions = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'rg_')));
    expect($rgMentions)->toBe([]);

    expect($payload)->not->toBeNull()
        ->and(array_key_exists('rg_flag', $payload))->toBeFalse()
        ->and(array_key_exists('rg_flag_reason', $payload))->toBeFalse()
        ->and(array_key_exists('rg_flag_set_by', $payload))->toBeFalse()
        ->and(array_key_exists('rg_flag_set_at', $payload))->toBeFalse()
        // ...while the profile is COMPLETE in its own right (E03's no-seam law).
        ->and($payload['id'])->toBe($customerId)
        ->and(array_key_exists('blocked', $payload))->toBeTrue();
});

it('the permitted payload carries the rg block, values intact', function (): void {
    [$customerId, $actorId] = rgScene();
    app(RgFlagService::class)->set($customerId, 'fixture: chasing losses', 'staff', $actorId);

    $payload = app(CustomerPayload::class)->build($customerId, viewerSeesRg: true);

    expect($payload['rg_flag'])->toBeTrue()
        ->and($payload['rg_flag_reason'])->toBe('fixture: chasing losses')
        ->and($payload['rg_flag_set_by'])->toBe($actorId);
});

it('NFR-06/AC-B4-01: RG reads are FRESH against a warm cache — an intervening write is visible on the very next read (sabotage #2\'s subject)', function (): void {
    [$customerId, $actorId] = rgScene();
    $service = app(RgFlagService::class);
    $repository = app(RgFlagRepository::class);

    $service->set($customerId, 'fixture: set', 'staff', $actorId);
    expect($repository->current($customerId)->rg_flag)->toBeTrue();

    // The warm-cache condition: by the time of the second read the first has
    // already executed once — a remember()-style implementation would now
    // serve the stale TRUE.
    $service->clear($customerId, 'fixture: reviewed, false positive', $actorId);
    expect($repository->current($customerId)->rg_flag)->toBeFalse();

    $service->set($customerId, 'fixture: set again', 'staff', $actorId);
    expect($repository->current($customerId)->rg_flag)->toBeTrue();
});

it('AC-05: the history is immutable LIVE — app UPDATE and DELETE both refuse with 42501 (the grant exercised, not just declared)', function (): void {
    [$customerId, $actorId] = rgScene();
    app(RgFlagService::class)->set($customerId, 'fixture: immutable', 'staff', $actorId);

    expect(fn () => DB::transaction(fn () => DB::table('rg_flag_history')->update(['reason' => 'rewritten'])))
        ->toThrow(QueryException::class, '42501');

    expect(fn () => DB::transaction(fn () => DB::table('rg_flag_history')->delete()))
        ->toThrow(QueryException::class, '42501');
});

it('T-05: Doc 03\'s capability cache already bypasses customers.rg_flag.* (R-04\'s list) — asserted from the class constant, never re-implemented', function (): void {
    $reflection = new ReflectionClass(App\Support\Rbac\CachedCapabilityMaps::class);
    /** @var list<string> $bypass */
    $bypass = $reflection->getConstant('HIGH_SENSITIVITY_KEYS') ?: [];

    if ($bypass === []) {
        // Constant name defensive: derive from any list-typed constant carrying the keys.
        foreach ($reflection->getConstants() as $value) {
            if (is_array($value) && in_array('customers.rg_flag.view', $value, true)) {
                $bypass = $value;
                break;
            }
        }
    }

    expect($bypass)->toContain('customers.rg_flag.view')
        ->and($bypass)->toContain('customers.rg_flag.set');
});
