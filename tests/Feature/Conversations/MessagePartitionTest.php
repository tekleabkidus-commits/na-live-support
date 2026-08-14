<?php

declare(strict_types=1);

use App\Domain\Conversations\Storage\MessagePartitions;
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Messages partition physics (Doc 04 T-01/FS-04, Doc 01 EC-10 conventions)
|--------------------------------------------------------------------------
|
| The tests the messages migration and MessagePartitions promise BY NAME:
| the messages half of RlsCoverageTest's no-bare-partition rule, the
| partitions:check monitor extended to the messages window, FS-04's
| pager path (a row in messages_default is an incident, never a save),
| and the DELIBERATE difference from events — no blind monthly retention
| drop; messages leave only through the settings-driven retention job
| with legal hold (T-08/EC-09/AC-09, arrives step 9).
|
| DDL on the migrator connection is auto-commit — every partition mutation
| restores in a finally, exactly as the Foundation partition tests do.
*/

function messagePartitionRlsFacts(): array
{
    /** @var list<object{relname: string, relrowsecurity: bool, relforcerowsecurity: bool, policies: int|string}> */
    return DB::connection('pgsql_migrator')->select("
        SELECT c2.relname,
               c2.relrowsecurity,
               c2.relforcerowsecurity,
               (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c2.oid) AS policies
        FROM pg_inherits i
        JOIN pg_class c ON c.oid = i.inhparent
        JOIN pg_class c2 ON c2.oid = i.inhrelid
        WHERE c.relname = 'messages'
        ORDER BY c2.relname
    ");
}

it('finds every existing messages partition enabled, forced, and policed (NFR-01, the RlsCoverageTest messages half)', function () {
    $facts = messagePartitionRlsFacts();

    expect($facts)->not->toBeEmpty();

    foreach ($facts as $fact) {
        expect($fact->relrowsecurity)->toBeTrue("{$fact->relname} has RLS disabled")
            ->and($fact->relforcerowsecurity)->toBeTrue("{$fact->relname} does not FORCE RLS — the owner bypasses it")
            ->and((int) $fact->policies)->toBeGreaterThan(0, "{$fact->relname} has no policy — bare partition");
    }
});

it('protects messages partitions the MAINTENANCE COMMAND creates, not only the migration ones', function () {
    $partitions = app(MessagePartitions::class);
    $today = CarbonImmutable::now('UTC');

    // Force creation of a genuinely new partition (one month past the real window).
    $shifted = $today->startOfMonth()->addMonth();
    $name = $partitions->nameFor($shifted->addMonths(MessagePartitions::MONTHS_AHEAD));

    try {
        expect($partitions->ensureFutureMonths($shifted))->toBe([$name]);

        $fact = collect(messagePartitionRlsFacts())->firstWhere('relname', $name);

        expect($fact)->not->toBeNull()
            ->and($fact->relrowsecurity)->toBeTrue()
            ->and($fact->relforcerowsecurity)->toBeTrue()
            ->and((int) $fact->policies)->toBeGreaterThan(0);
    } finally {
        removeMessagesPartition($name);
    }
});

it('monitor goes RED when a future messages partition is missing, and maintain heals it (EC-10 conventions)', function () {
    $partitions = app(MessagePartitions::class);
    $today = CarbonImmutable::now('UTC');
    $furthest = $partitions->nameFor($today->startOfMonth()->addMonths(MessagePartitions::MONTHS_AHEAD));

    try {
        removeMessagesPartition($furthest);

        expect(Artisan::call('partitions:check'))->toBe(1)
            ->and(Artisan::output())->toContain('messages: only');
    } finally {
        // Heal THROUGH the maintenance command — proving the pair works as a pair.
        Artisan::call('partitions:maintain');
    }

    expect(Artisan::call('partitions:check'))->toBe(0);
});

it('monitor goes RED when any row sits in messages_default — FS-04\'s pager path', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();
    bindTenant($tenant);

    // The row shape needs real FK targets; the models suffice — no adapter involved.
    $customer = Customer::query()->create([
        'display_name' => 'Fixture Customer',
        'username' => '@fixture_partition',
        'is_shell' => true,
        'created_source' => 'staff',
    ]);
    $conversation = Conversation::query()->create([
        'channel' => 'fakechan',
        'channel_ref_id' => 'bot-1',
        'external_chat_id' => 'chat-stray',
        'customer_id' => $customer->identifier(),
        'state' => 'active',
    ]);
    commitFixtures();

    // platform_admin (BYPASSRLS) is the only role that can plant a stray the way no
    // application path ever would: created_at two years out lands in messages_default.
    $platform = DB::connection('pgsql_platform');

    try {
        $platform->table('messages')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->identifier(),
            'direction' => 'in',
            'author_type' => 'customer',
            'kind' => 'text',
            'body' => 'stray',
            'media' => '[]',
            'channel_meta' => '{}',
            'created_at' => CarbonImmutable::now('UTC')->addYears(2),
        ]);

        expect(asPlatformWorker(fn (): int => Artisan::call('partitions:check')))->toBe(1)
            ->and(Artisan::output())->toContain('messages_default');
    } finally {
        cleanupTenants([$tenantId]);
    }

    expect(asPlatformWorker(fn (): int => Artisan::call('partitions:check')))->toBe(0);
});

it('maintain NEVER drops an old messages partition — retention is the settings-driven job, not this blind pass (T-08/EC-09)', function () {
    $partitions = app(MessagePartitions::class);
    $today = CarbonImmutable::now('UTC');

    // 14 months old — the age at which the EVENTS pass drops. Messages must survive it.
    $oldMonth = $today->startOfMonth()->subMonths(14);
    $oldName = $partitions->nameFor($oldMonth);

    try {
        DB::connection('pgsql_migrator')->statement(sprintf(
            "CREATE TABLE %s PARTITION OF messages FOR VALUES FROM ('%s') TO ('%s')",
            $oldName,
            $oldMonth->toDateTimeString(),
            $oldMonth->addMonth()->toDateTimeString(),
        ));

        expect(Artisan::call('partitions:maintain'))->toBe(0)
            ->and($partitions->existing())->toContain($oldName);
    } finally {
        removeMessagesPartition($oldName);
    }
});
