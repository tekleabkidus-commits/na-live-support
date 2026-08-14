<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Conversations\Identity\CustomerResolver;
use App\Domain\Customers\Identity\ChannelIdentityMap;
use App\Domain\Customers\Identity\IdentityResolution;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 AC-01 (T-08's resolver matrix) — proven BEFORE the resolver serves
| Doc 04 in anger (the plan's ordered minimum; the wiring rides this commit)
|--------------------------------------------------------------------------
|
| FR-07's two lines, FR-08's refusal to merge, FR-06's history, BR-02's
| sharpest prohibition (no cross-type auto-link — sabotage #4's subject), and
| T-02's race-safety as a DB property, exercised by a GENUINE fork race.
|
| The Doc 05 EC-04 CLOSING clause lives here too: the identity-resolution
| half of "hybrid: one customer, two conversations" — the same Telegram user
| arriving on the Business surface and the direct surface resolves to ONE
| customer because both carry the same (telegram, user-id) identity. The
| grouping/badge/warning half was Doc 07's, closed at S07 step 9.
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('A-40: every channel the AdapterRegistry knows has an identity-type mapping, and an unmapped channel THROWS at the boundary', function (): void {
    foreach (app(AdapterRegistry::class)->channels() as $channel) {
        expect(ChannelIdentityMap::typeFor($channel))->toBeIn(['telegram', 'web', 'phone', 'email']);
    }

    expect(fn (): string => ChannelIdentityMap::typeFor('smoke-signal'))
        ->toThrow(RuntimeException::class, 'smoke-signal');
});

it('AC-01 create-shell: a first contact mints a shell + typed identity, writes NOTHING to the deprecated columns, and publishes the two create events', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $result = app(IdentityResolution::class)->resolveInbound(
            'telegram',
            new InboundSender('900100', 'Abel Fixture', '@abel_fixture'),
        );

        expect($result->created)->toBeTrue();

        /** @var Customer $customer */
        $customer = Customer::query()->findOrFail($result->customerId);
        expect($customer->is_shell)->toBeTrue()
            ->and($customer->created_source)->toBe('telegram')
            // FR-01's derivation reading: display_name is the STAFF slot; the
            // channel display lives on the identity. The deprecated columns
            // are never written by the new resolver (SCHEMA AMENDMENT 2).
            ->and($customer->display_name)->toBeNull()
            ->and($customer->username)->toBeNull()
            ->and($customer->phone)->toBeNull();

        /** @var CustomerIdentity $identity */
        $identity = CustomerIdentity::query()->sole();
        $metadata = (array) json_decode((string) $identity->getRawOriginal('metadata'), true);
        expect($identity->type)->toBe('telegram')
            ->and($identity->value)->toBe('900100')
            ->and($identity->source)->toBe('channel_auto')
            ->and($identity->first_seen_at)->not->toBeNull()
            ->and($metadata['display'] ?? null)->toBe('Abel Fixture')
            ->and($metadata['username'] ?? null)->toBe('@abel_fixture');

        expect(DB::table('outbox')->where('name', 'customer.created')->count())->toBe(1)
            ->and(DB::table('outbox')->where('name', 'customer.identity.linked')->count())->toBe(1);
    });
});

it('AC-01 exact-match: the second contact returns the SAME customer, created=false, no new events — and the Doc 04 seam delegates to the same answer', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $sender = new InboundSender('900200', 'Meron Fixture', '@meron_fixture');
        $resolver = app(IdentityResolution::class);

        $first = $resolver->resolveInbound('telegram', $sender);
        $second = $resolver->resolveInbound('telegram', $sender);

        expect($second->created)->toBeFalse()
            ->and($second->customerId)->toBe($first->customerId)
            ->and(Customer::query()->count())->toBe(1)
            ->and(DB::table('outbox')->where('name', 'customer.created')->count())->toBe(1);

        // The Doc 04 seam (A-40 wiring): same channel + sender → same id,
        // through the unchanged resolve(string, InboundSender): string.
        expect(app(CustomerResolver::class)->resolve('telegram', $sender))->toBe($first->customerId);
    });
});

it('AC-01 / FR-06 username change: display refreshes and the OLD name lands in metadata history with a DB-time stamp', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $resolver = app(IdentityResolution::class);
        $resolver->resolveInbound('telegram', new InboundSender('900300', 'Kidist Fixture', '@kidist_old'));
        $resolver->resolveInbound('telegram', new InboundSender('900300', 'Kidist Fixture', '@kidist_new'));

        /** @var CustomerIdentity $identity */
        $identity = CustomerIdentity::query()->sole();
        $metadata = (array) json_decode((string) $identity->getRawOriginal('metadata'), true);

        expect($metadata['username'] ?? null)->toBe('@kidist_new')
            ->and($metadata['history'] ?? null)->toHaveCount(1)
            ->and($metadata['history'][0]['old'] ?? null)->toBe('@kidist_old')
            ->and($metadata['history'][0]['changed_at'] ?? '')->not->toBe('');
    });
});

it('AC-01 NO CROSS-TYPE AUTO-LINK (BR-02, the document\'s sharpest prohibition): a sender-supplied phone attaches NOTHING — sabotage #4\'s subject', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        // The sender carries a phone (a profile hint, or a number typed in
        // chat — C07's frame). The resolver must leave it as TEXT: no phone
        // identity, no deprecated-column write, nothing attached to anyone.
        $result = app(IdentityResolution::class)->resolveInbound(
            'telegram',
            new InboundSender('900400', 'Dawit Fixture', '@dawit_fixture', '+251900887766'),
        );

        expect(CustomerIdentity::query()->where('type', 'phone')->count())->toBe(0)
            ->and(Customer::query()->findOrFail($result->customerId)->phone)->toBeNull()
            ->and(CustomerIdentity::query()->count())->toBe(1);
    });
});

it('Doc 05 EC-04 CLOSING (identity-resolution half): the same Telegram user on the Business and direct surfaces is ONE customer', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $resolver = app(IdentityResolution::class);

        // Both Doc 05 surfaces present the same channel + external user id;
        // the (telegram, user-id) identity is the resolver's whole key, so
        // hybrid mode cannot mint a second customer. The grouping/badge/
        // older-activity half closed at Doc 07 step 9 (BUILD-STATUS row).
        $business = $resolver->resolveInbound('telegram', new InboundSender('900500', 'Hybrid Fixture', '@hybrid_fixture'));
        $direct = $resolver->resolveInbound('telegram', new InboundSender('900500', 'Hybrid Fixture', '@hybrid_fixture'));

        expect($direct->customerId)->toBe($business->customerId)
            ->and(Customer::query()->count())->toBe(1);
    });
});

it('AC-01 RACE: six forked processes race the same first message — exactly ONE customer, every loser adopts the winner (T-02\'s DB property)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    commitFixtures();

    // Fork discipline: the parent purges its PDO handles BEFORE forking so no
    // child shares a socket; every child reconnects, resolves, writes its
    // answer to a file, and dies with SIGKILL (no shutdown handlers, no pest
    // side effects). The parent reconnects, asserts, and cleans up.
    DB::purge('pgsql');
    DB::purge('pgsql_migrator');
    DB::purge('pgsql_platform');

    $dir = sys_get_temp_dir().'/s08-race-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $workers = 6;
    $pids = [];

    try {
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    DB::purge('pgsql');
                    DB::connection('pgsql')->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
                    $context = app(TenantContext::class);
                    $context->clear();
                    $context->bind($tenantId, ResolutionSource::Console);

                    $result = app(IdentityResolution::class)->resolveInbound(
                        'telegram',
                        new InboundSender('race-900600', 'Race Fixture', '@race_fixture'),
                    );
                    file_put_contents("{$dir}/{$w}", $result->customerId.'|'.($result->created ? '1' : '0'));
                } catch (Throwable $e) {
                    file_put_contents("{$dir}/{$w}", 'ERR|'.$e->getMessage());
                }

                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge('pgsql');
        bindTenant($tenantId, ResolutionSource::Console);

        $answers = [];
        $createdFlags = 0;
        for ($w = 0; $w < $workers; $w++) {
            $raw = (string) file_get_contents("{$dir}/{$w}");
            expect($raw)->not->toStartWith('ERR|');
            [$id, $created] = explode('|', $raw);
            $answers[] = $id;
            $createdFlags += (int) $created;
        }

        expect(array_unique($answers))->toHaveCount(1)
            ->and($createdFlags)->toBe(1)
            ->and(DB::table('customers')->count())->toBe(1)
            ->and(DB::table('customer_identities')->where('type', 'telegram')->where('value', 'race-900600')->count())->toBe(1);
    } finally {
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
        DB::purge('pgsql');
        cleanupTenants([$tenantId]);
    }
});

it('T-02 THE SPECULATIVE WINDOW (amendment addition 8): a loser racing into a HELD uncommitted competitor takes the graceful RaceLost path — never a raw 23505 off a non-arbiter index', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    commitFixtures();

    DB::purge('pgsql');
    DB::purge('pgsql_migrator');
    DB::purge('pgsql_platform');

    $dir = sys_get_temp_dir().'/s08-specwin-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $winnerId = (string) Illuminate\Support\Str::uuid7();

    try {
        // THE COMPETITOR (forked): inserts the same identity in an OPEN
        // transaction, signals, HOLDS the window with pg_sleep, then commits.
        // The loser's arbiter pre-check runs while this sits uncommitted —
        // the exact window the step-8 canonical red fell into by chance.
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                DB::purge('pgsql');
                $child = DB::connection('pgsql');
                $child->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
                $child->statement('BEGIN');
                $child->statement(
                    "INSERT INTO customers (id, tenant_id, is_shell, created_source, created_at, updated_at) VALUES (?, ?, true, 'telegram', now(), now())",
                    [$winnerId, $tenantId],
                );
                $child->statement(
                    <<<'SQL'
                    INSERT INTO customer_identities
                        (id, tenant_id, customer_id, channel, external_user_id,
                         type, value, source, first_seen_at, metadata, created_at, updated_at)
                    VALUES (?, ?, ?, 'telegram', 'specwin-900800', 'telegram', 'specwin-900800',
                            'channel_auto', clock_timestamp(), '{}', now(), now())
                    SQL,
                    [(string) Illuminate\Support\Str::uuid7(), $tenantId, $winnerId],
                );
                file_put_contents("{$dir}/held", '1');
                $child->statement('SELECT pg_sleep(1.2)');
                $child->statement('COMMIT');
                file_put_contents("{$dir}/committed", '1');
            } catch (Throwable $e) {
                file_put_contents("{$dir}/err", $e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        // THE LOSER (this process): waits for the held signal, then races in.
        $deadline = microtime(true) + 5.0;
        while (! file_exists("{$dir}/held") && ! file_exists("{$dir}/err") && microtime(true) < $deadline) {
            usleep(20_000);
        }
        expect(file_exists("{$dir}/err"))->toBeFalse('the competitor failed to arm: '.@file_get_contents("{$dir}/err"))
            ->and(file_exists("{$dir}/held"))->toBeTrue('the competitor never armed the window');

        DB::purge('pgsql');
        bindTenant($tenantId, ResolutionSource::Console);

        // Blocks on the competitor's held index entry until its commit, then
        // the conflict materializes. GRACEFUL is the whole assertion: the
        // arbiter takes it (RaceLost → adopt), no raw 23505 off the legacy
        // (tenant, channel, external_user_id) index (addition 8 dropped it).
        $result = app(IdentityResolution::class)->resolveInbound(
            'telegram',
            new InboundSender('specwin-900800', 'Specwin Fixture', '@specwin_fixture'),
        );

        pcntl_waitpid($pid, $status);

        expect($result->created)->toBeFalse('the loser thinks it created — it never saw the committed winner')
            ->and($result->customerId)->toBe($winnerId)
            ->and(DB::table('customer_identities')->where('type', 'telegram')->where('value', 'specwin-900800')->count())->toBe(1);
    } finally {
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
        DB::purge('pgsql');
        cleanupTenants([$tenantId]);
    }
});
