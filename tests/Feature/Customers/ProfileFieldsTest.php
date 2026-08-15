<?php

declare(strict_types=1);

use App\Domain\Customers\Profile\CustomerProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-01 / FR-02 / BR-06 / EC-10 / EC-11 (S08 step 9) — the profile
| attribute writer: sticky sources, LWW with the honest conflict signal,
| the A-37 noop at the service's own branch, and BR-06's schema absence.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** A customers.edit-holding actor for the autosave door. */
function profileEditor(string $roleName): App\Models\User
{
    $role = App\Models\Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'color' => 'role-agent',
            'permissions' => ['customers.view', 'customers.edit'],
            'scopes' => ['customers' => 'All'],
        ],
    );
    $user = App\Models\User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

it('FR-01: a staff write to language/country is STICKY — sources stamp \'staff\', old/new land in audit, names-only in the event (Q7)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $editor = profileEditor('S08 Profile Editor');

    $response = test()->actingAs($editor, 'staff')
        ->patchJson("https://localhost/api/staff/customers/{$customerId}/profile", [
            'language' => 'am', 'country' => 'ET', 'tags' => ['vip', 'payout watch'],
        ]);
    $response->assertOk();

    bindTenant($tenant);    // the request lifecycle cleared the RLS binding on its way out
    /** @var object{language: string, language_source: string, country: string, country_source: string, tags: string} $row */
    $row = DB::table('customers')->where('id', $customerId)->first(['language', 'language_source', 'country', 'country_source', 'tags']);
    expect($row->language)->toBe('am')
        ->and($row->language_source)->toBe('staff')
        ->and($row->country)->toBe('ET')
        ->and($row->country_source)->toBe('staff')
        ->and($row->tags)->toContain('payout watch');

    /** @var object{payload: string} $event */
    $event = DB::table('outbox')->where('name', 'customer.updated')->latest('occurred_at')->first(['payload']);
    /** @var array{changed: list<string>} $payload */
    $payload = json_decode($event->payload, true);
    expect($payload['changed'])->toEqualCanonicalizing(['language', 'country', 'tags'])
        ->and(json_encode($payload))->not->toContain('am"', 'the event carries VALUES — Q7 says names only');

    /** @var object{payload: string} $audit */
    $audit = DB::table('audit_log')->where('action_key', 'customer.updated')->latest('occurred_at')->first(['payload']);
    expect($audit->payload)->toContain('"old"')->toContain('"new"');
});

it('EC-11: simultaneous edits are LAST-WRITE-WINS with the honest conflict flag — the second write LANDS and is TOLD it overwrote; both writes audited', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $profile = app(CustomerProfile::class);
    $actorA = (string) Str::uuid7();
    $actorB = (string) Str::uuid7();

    /** @var object{updated_at: string} $stamp */
    $stamp = DB::selectOne('SELECT updated_at::text AS updated_at FROM customers WHERE id = ?', [$customerId]);
    $sharedExpectation = $stamp->updated_at; // both agents loaded the same panel

    $first = $profile->update($customerId, ['display_name' => 'Agent A version'], $actorA, $sharedExpectation);
    expect($first)->not->toBeNull()
        ->and($first['conflict'])->toBeFalse();

    // Agent B still holds the ORIGINAL stamp — their write lands (LWW) and
    // the response says so honestly.
    $second = $profile->update($customerId, ['display_name' => 'Agent B version'], $actorB, $sharedExpectation);
    expect($second)->not->toBeNull()
        ->and($second['conflict'])->toBeTrue('the overwrite was silent — EC-11\'s toast has no data')
        ->and((string) DB::table('customers')->where('id', $customerId)->value('display_name'))->toBe('Agent B version')
        ->and(DB::table('audit_log')->where('action_key', 'customer.updated')->count())->toBe(2);
});

it('A-37: the unchanged-value autosave is the SERVICE\'s own noop — null return, no event, no audit — and the door declares it (audit.noop passes the middleware)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['language' => 'en', 'language_source' => 'staff']);
    $editor = profileEditor('S08 Profile Noop');

    $before = DB::table('audit_log')->where('action_key', 'customer.updated')->count();
    $events = DB::table('outbox')->where('name', 'customer.updated')->count();

    // The same value again: the declared-noop path — a 200 with changed: [],
    // and the DeclareAuditAction middleware ACCEPTS the absence (it would
    // throw in testing were the declaration missing — the negative control
    // is the middleware's own contract).
    $response = test()->actingAs($editor, 'staff')
        ->patchJson("https://localhost/api/staff/customers/{$customerId}/profile", ['language' => 'en']);
    $response->assertOk();

    bindTenant($tenant);
    expect($response->json('changed'))->toBe([])
        ->and(DB::table('audit_log')->where('action_key', 'customer.updated')->count())->toBe($before)
        ->and(DB::table('outbox')->where('name', 'customer.updated')->count())->toBe($events);
});

it('EC-10: the detected-latest disagreement is DERIVED data (chip), and apply-detected adopts it with source back to \'detected\' — staff-set wins until the human says otherwise', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['language' => 'en', 'language_source' => 'staff']);
    $editor = profileEditor('S08 Profile Detect');

    // An Amharic inbound message — the detector's stored per-message language.
    $conversationId = anchoredConversation($tenantId, $customerId, 'tg-ec10-1');
    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text', 'body' => 'ሰላም እንዴት ነህ',
        'is_internal' => false, 'language' => 'am',
    ]);

    // The chip's data rides the panel header; the staff value stands.
    $panel = test()->actingAs($editor, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel");
    $panel->assertOk();
    expect($panel->json('header.language'))->toBe('en')
        ->and($panel->json('header.language_source'))->toBe('staff')
        ->and($panel->json('header.detected_language'))->toBe('am');

    // Apply: the chip's server half — adopted, un-sticky.
    $apply = test()->actingAs($editor, 'staff')
        ->postJson("https://localhost/api/staff/customers/{$customerId}/profile/apply-detected");
    $apply->assertOk();

    bindTenant($tenant);
    /** @var object{language: string, language_source: string} $row */
    $row = DB::table('customers')->where('id', $customerId)->first(['language', 'language_source']);
    expect($row->language)->toBe('am')
        ->and($row->language_source)->toBe('detected');
});

it('FR-02/BR-06: the customer schema carries NO consent or marketing column, and nothing auto-deletes — the lifecycle is shell → enriched → tombstone only', function (): void {
    $columns = Schema::getColumnListing('customers');

    foreach ($columns as $column) {
        expect(str_contains($column, 'consent') || str_contains($column, 'marketing') || str_contains($column, 'newsletter') || str_contains($column, 'opt_in'))
            ->toBeFalse("BR-06: a consent/marketing-shaped column exists: {$column}");
    }

    // FR-02's no-auto-delete is grant physics, proven in
    // CustomerGrantPhysicsTest (DELETE revoked); asserted here as the
    // lifecycle's third state existing at all: merged_into_id is the
    // tombstone column and the only exit.
    expect(in_array('merged_into_id', $columns, true))->toBeTrue();
});
