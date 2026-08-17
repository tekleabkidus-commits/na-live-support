<?php

declare(strict_types=1);

use App\Jobs\Customers\CustomerExportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Doc 08 SEC-04 / SEC-06 / FS-05 (S08 step 9) — export: async, watermarked,
| signed-at-completion, audited; the verdict re-checked IN THE JOB; partial
| artifacts unrepresentable (one atomic put). Erasure INITIATES only (A-38).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** A customers.export-holding actor. */
function exportActor(string $roleName, array $extraPermissions = []): App\Models\User
{
    $role = App\Models\Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'color' => 'role-agent',
            'permissions' => array_merge(['customers.view', 'customers.export'], $extraPermissions),
            'scopes' => ['customers' => 'All'],
        ],
    );
    $user = App\Models\User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

it('SEC-04: the full journey — request audited at the door, the job builds the WATERMARKED archive, one atomic put, completion audited, the re-issue door signs it', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['display_name' => 'Export Fixture']);
    $requester = exportActor('S08 Export Requester');

    // Sync queue in tests: dispatch runs the job inline.
    $request = test()->actingAs($requester, 'staff')
        ->postJson("https://localhost/api/staff/customers/{$customerId}/export");
    $request->assertStatus(202);
    $exportId = (string) $request->json('export_id');

    bindTenant($tenant);    // the request lifecycle cleared the RLS binding on its way out
    $key = CustomerExportJob::storageKey($tenantId, $exportId);
    $disk = Storage::disk((string) config('media.disk'));
    expect($disk->exists($key))->toBeTrue('the archive never landed')
        ->and(DB::table('audit_log')->where('action_key', 'customer.export_requested')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'customer.exported')->count())->toBe(1);

    // The archive is a real zip carrying the watermark in every member.
    $tmp = tempnam(sys_get_temp_dir(), 'cx-assert-');
    file_put_contents($tmp, $disk->get($key));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $profile = (string) $zip->getFromName('profile.json');
    $zip->close();
    @unlink($tmp);

    expect($names)->toEqualCanonicalizing(['watermark.txt', 'profile.json', 'tickets.json', 'files-manifest.json'])
        ->and($profile)->toContain('Export Fixture')->toContain('watermark');

    // Re-issue door: complete artifact → a fresh signed URL.
    $url = test()->actingAs($requester, 'staff')
        ->getJson("https://localhost/api/staff/customers/{$customerId}/export/{$exportId}");
    $url->assertOk();
    expect((string) $url->json('url'))->toContain($exportId);
});

it('SEC-06: the verdict is re-checked IN THE JOB — a revocation between click and run produces NO artifact and NO completion audit', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $requester = exportActor('S08 Export Revoked');

    // The revocation lands BEFORE the job runs: strip the key, then run the
    // job directly (the door would have audited the request; here the job's
    // own gate is the subject).
    App\Models\Role::query()->where('name', 'S08 Export Revoked')->update([
        'permissions' => json_encode(['customers.view']),
    ]);
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($requester);

    $exportId = (string) Illuminate\Support\Str::uuid7();
    (new CustomerExportJob($tenantId, $exportId, $customerId, $requester->identifier()))->handle();

    expect(Storage::disk((string) config('media.disk'))->exists(CustomerExportJob::storageKey($tenantId, $exportId)))->toBeFalse('a revoked requester still produced an artifact — SEC-06\'s job re-check is gone')
        ->and(DB::table('audit_log')->where('action_key', 'customer.exported')->count())->toBe(0);
});

it('FS-05: a crash between build and put leaves NOTHING — no object, no signature, the re-issue door 404s; the RETRY completes idempotently on the same export id', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $requester = exportActor('S08 Export Chaos');
    $exportId = (string) Illuminate\Support\Str::uuid7();
    $key = CustomerExportJob::storageKey($tenantId, $exportId);
    $disk = Storage::disk((string) config('media.disk'));

    // THE KILL: the chaos hook dies after the archive is built, before the put.
    config()->set('customers.export_chaos_kill', true);
    expect(fn () => (new CustomerExportJob($tenantId, $exportId, $customerId, $requester->identifier()))->handle())
        ->toThrow(RuntimeException::class);

    expect($disk->exists($key))->toBeFalse('a partial artifact exists — the atomic-put design is broken');
    test()->actingAs($requester, 'staff')
        ->getJson("https://localhost/api/staff/customers/{$customerId}/export/{$exportId}")
        ->assertStatus(404); // never signable

    // THE RETRY (the queue's re-drive, same job identity): completes whole.
    config()->set('customers.export_chaos_kill', false);
    (new CustomerExportJob($tenantId, $exportId, $customerId, $requester->identifier()))->handle();

    bindTenant($tenant);
    expect($disk->exists($key))->toBeTrue()
        ->and(DB::table('audit_log')->where('action_key', 'customer.exported')->count())->toBe(1);
});

it('A-38/[D17]: erasure INITIATION rides customers.delete with a mandatory reason — the audit entry is the whole durable record; without the key the door refuses', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');

    $holder = exportActor('S08 Erasure Holder', ['customers.delete']);
    $response = test()->actingAs($holder, 'staff')
        ->postJson("https://localhost/api/staff/customers/{$customerId}/erasure", ['reason' => 'data-subject request, ticket #4411']);
    $response->assertStatus(202);
    bindTenant($tenant);
    expect(DB::table('audit_log')->where('action_key', 'customer.erasure.initiated')->count())->toBe(1)
        ->and((string) DB::table('audit_log')->where('action_key', 'customer.erasure.initiated')->value('payload'))->toContain('4411');

    // No reason → 422; no key → 403; the customer row is untouched either way
    // ([D17] owns any actual erasure — nothing else may even mark it).
    test()->actingAs($holder, 'staff')
        ->postJson("https://localhost/api/staff/customers/{$customerId}/erasure", [])->assertStatus(422);
    bindTenant($tenant);    // cleared again by the 422 request
    $without = exportActor('S08 Erasure NoKey');
    test()->actingAs($without, 'staff')
        ->postJson("https://localhost/api/staff/customers/{$customerId}/erasure", ['reason' => 'should never land'])->assertStatus(403);
    bindTenant($tenant);
    expect(DB::table('customers')->where('id', $customerId)->exists())->toBeTrue();
});

it('H06 (step 12): the profile page REPORTS a pending erasure — requested-by, stamp, and a stable reference read from the audit record; absent when none was raised', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $initiator = exportActor('S08 Erasure Surface', ['customers.delete']);

    // Before any request: the surface is silent.
    $before = test()->actingAs($initiator, 'staff')->get("https://localhost/customers/{$customerId}");
    $before->assertOk();
    $before->assertInertia(fn ($assert) => $assert
        ->component('Customers/Profile', shouldExist: false)
        ->where('erasure', null));

    test()->actingAs($initiator, 'staff')
        ->postJson("https://localhost/api/staff/customers/{$customerId}/erasure", ['reason' => 'Customer asked by phone during a fixture ticket.'])
        ->assertStatus(202);

    $after = test()->actingAs($initiator, 'staff')->get("https://localhost/customers/{$customerId}");
    $after->assertOk();
    $after->assertInertia(fn ($assert) => $assert
        ->component('Customers/Profile', shouldExist: false)
        ->where('erasure.requested_by', fn ($value) => is_string($value) && $value !== '')
        ->where('erasure.requested_at', fn ($value) => is_string($value) && $value !== '')
        ->where('erasure.reference', fn ($value) => is_string($value) && str_starts_with($value, 'ERA-')));
});
