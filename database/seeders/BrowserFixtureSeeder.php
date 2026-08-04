<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenancy\TenantLifecycle;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Known-credential fixtures for the BROWSER suite (Doc 02 step 6; Doc 19).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS, AND WHY IT IS NOT SCOPE CREEP.
 *
 * Several Doc 02 requirements are only observable with a real cookie jar:
 * SEC-04's fixation resistance, Q7's rejection of a wrong tenant hint, FR-10's
 * session cap, FR-11's timeouts, T-04's revocation. The PHP test client does not
 * replay Set-Cookie, so every one of those assertions passed in S6 whether the
 * feature worked or not — three separate false greens, documented in
 * BUILD-STATUS. The browser suite has a real cookie jar; it needs accounts to
 * log into, and it cannot create them through a UI that does not exist yet.
 *
 * SAFETY. This refuses to run outside local/testing/ci. The credentials are
 * deliberately obvious and the addresses use the `@fixture` convention
 * CLAUDE.md §8 mandates, so a leak is self-identifying. It is invoked
 * explicitly (`db:seed --class=BrowserFixtureSeeder`), never from
 * DatabaseSeeder, so no deploy path can reach it by default.
 *
 * TWO TENANTS, deliberately: the interesting Q7 case is a hint pointing at
 * ANOTHER REAL workspace, which is indistinguishable from a legitimate hint
 * except that it is not this session's. A single-tenant fixture could only test
 * the boring forgery shapes.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BrowserFixtureSeeder extends Seeder
{
    public const PASSWORD = 'browser-fixture-password';

    public const PRIMARY_EMAIL = 'agent@fixture.example';

    public const SECOND_TENANT_EMAIL = 'other@fixture.example';

    // Doc 02 step 8 (password.spec.ts): a DEDICATED account for the flows that
    // MUTATE credentials, so a reset journey can never strand the primary
    // account other specs log into.
    public const RESET_EMAIL = 'reset-target@fixture.example';

    // FR-17: seeded in the INVITED state — unusable random hash, NULL
    // password_set_at. The invitation journey activates it; the suite's
    // make-invited affordance restores it for the next run.
    public const INVITED_EMAIL = 'invited-fixture@fixture.example';

    // Doc 02 step 13 (auth-screens.spec.ts): a DEDICATED account for the 2FA
    // journeys — enrolment via the wizard, then the challenge step of the
    // login card. Dedicated so a mid-run failure can never leave the primary
    // account 2FA-enrolled and break every other spec's password-only login;
    // the suite resets it with `auth:session-inspect clear-2fa` either way.
    public const TWO_FACTOR_EMAIL = 'twofactor-fixture@fixture.example';

    // Doc 03 (S19, rbac.spec.ts): a READ ONLY principal for AC-08's deny half
    // — controls must be hidden/disabled exactly where verdict=deny.
    public const READ_ONLY_EMAIL = 'readonly-fixture@fixture.example';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException(
                'BrowserFixtureSeeder creates accounts with published credentials and must never run '
                .'outside local/testing/ci.'
            );
        }

        $primary = $this->tenant('Fixture Operator A');
        $this->user($primary, self::PRIMARY_EMAIL);
        $this->user($primary, self::RESET_EMAIL);
        $this->invitedUser($primary, self::INVITED_EMAIL);
        $this->user($primary, self::TWO_FACTOR_EMAIL);

        // Doc 03 (S19): the flip made every permission declaration real — the
        // fixture accounts need ROLES now. Appendix B's templates seed via the
        // real seeder; the primary agent gets Super Admin (the specs drive the
        // admin surfaces), the AC-08 fixture gets the Read Only template.
        $this->user($primary, self::READ_ONLY_EMAIL);
        app(TenantContext::class)->runFor(
            $primary->identifier(),
            ResolutionSource::Console,
            function (): void {
                app(\App\Domain\Rbac\RoleSeeder::class)->seed();
                $superAdmin = \App\Models\Role::query()->where('name', 'Super Admin')->firstOrFail();
                $readOnly = \App\Models\Role::query()->where('name', 'Read Only')->firstOrFail();
                foreach ([self::PRIMARY_EMAIL, self::RESET_EMAIL, self::TWO_FACTOR_EMAIL] as $email) {
                    User::query()->where('email', $email)
                        ->update(['role_id' => $superAdmin->identifier()]);
                }
                User::query()->where('email', self::READ_ONLY_EMAIL)
                    ->update(['role_id' => $readOnly->identifier()]);
            },
        );

        $second = $this->tenant('Fixture Operator B');
        $this->user($second, self::SECOND_TENANT_EMAIL);

        $this->command->info(sprintf(
            'Browser fixtures ready: tenant A %s, tenant B %s',
            $primary->identifier(),
            $second->identifier(),
        ));
    }

    /**
     * Create an ACTIVE tenant the same way provisioning does.
     *
     * The order matters and is not incidental: `tenants` carries an RLS policy
     * keyed on its own id, so the context must be bound to the id BEFORE the row
     * can be inserted. That is what lets tenant creation run as the ordinary
     * `app` role with no RLS bypass (Doc 01 SEC-02).
     */
    private function tenant(string $name): Tenant
    {
        $existing = $this->findTenantByName($name);

        if ($existing !== null) {
            // ENSURE the state rather than assume it. An earlier run that failed
            // after creating the row left a tenant stuck in `provisioning`, and
            // returning it unchanged made every fixture login fail with FR-05's
            // "workspace temporarily unavailable" — a correct refusal of a
            // wrongly-seeded fixture, which looked like a login bug for far
            // longer than it should have.
            return $this->ensureActive($existing);
        }

        $tenantId = (string) Str::uuid7();

        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($tenantId, ResolutionSource::Console);
        app(RlsSession::class)->set($tenantId);

        // Through the FACTORY, not Tenant::create(): `state` is deliberately not
        // mass-assignable (the lifecycle trigger rejects any other writer), so a
        // direct create() cannot satisfy the NOT NULL column. The factory seeds
        // `provisioning`; TenantLifecycle performs the only sanctioned
        // transition to active.
        $tenant = Tenant::factory()->create([
            'id' => $tenantId,
            'name' => $name,
            'slug' => Str::slug($name),
        ]);

        return $this->ensureActive($tenant);
    }

    private function ensureActive(Tenant $tenant): Tenant
    {
        if ($tenant->state() === TenantState::Active) {
            return $tenant;
        }

        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($tenant->identifier(), ResolutionSource::Console);
        app(RlsSession::class)->set($tenant->identifier());

        app(TenantLifecycle::class)->transition(
            tenant: $tenant,
            target: TenantState::Active,
            actor: AuditActor::System,
            reason: 'browser fixture seeding',
        );

        return $tenant->refresh();
    }

    private function user(Tenant $tenant, string $email): User
    {
        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($tenant->identifier(), ResolutionSource::Console);
        app(RlsSession::class)->set($tenant->identifier());

        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            // SELF-HEALING, not just idempotent: the password journeys change
            // this credential, and an existing row returned untouched would
            // make every later run's logins fail with the fixture password.
            // Re-seeding restores the published credential exactly.
            $existing->setAttribute('password', self::PASSWORD);
            $existing->setAttribute('password_set_at', now());
            $existing->save();

            return $existing;
        }

        return User::query()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->identifier(),
            'name' => 'Fixture Agent',
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'password_set_at' => now(),
        ]);
    }

    /**
     * FR-17's invited shape: the account exists, no password was ever chosen.
     */
    private function invitedUser(Tenant $tenant, string $email): User
    {
        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($tenant->identifier(), ResolutionSource::Console);
        app(RlsSession::class)->set($tenant->identifier());

        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            $existing->setAttribute('password', bin2hex(random_bytes(32)));
            $existing->setAttribute('password_set_at', null);
            $existing->setAttribute('password_changed_at', null);
            $existing->save();

            return $existing;
        }

        return User::query()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->identifier(),
            'name' => 'Fixture Invitee',
            'email' => $email,
            'password' => Hash::make(bin2hex(random_bytes(32))),
            'password_set_at' => null,
        ]);
    }

    private function findTenantByName(string $name): ?Tenant
    {
        // Read across tenants deliberately and narrowly: the seeder is
        // idempotent, and finding an existing fixture requires looking without a
        // bound context. platform_admin is the sanctioned role for that
        // (Doc 01 SEC-02 / ADR 0003), and it is used for a name lookup only.
        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', $name)->first();

        if ($row === null) {
            return null;
        }

        app(TenantContext::class)->clear();
        $rowId = is_string($row->id) ? $row->id : '';
        app(TenantContext::class)->bind($rowId, ResolutionSource::Console);
        app(RlsSession::class)->set($rowId);

        $tenant = Tenant::query()->find($rowId);

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
