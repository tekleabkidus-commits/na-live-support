<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 FS-05 — the nightly invariant auditor (step 9)
|--------------------------------------------------------------------------
|
| "exists precisely because it should never fire" — so its test must MAKE it fire.
| The seeded violations require removing the physics first (the status CHECK, the
| T-02 index), which is exactly FS-05's premise: a defense-in-depth breach where an
| outer layer has been quietly removed. DDL rides the migrator connection, the
| seeded rows ride the platform connection, and BOTH layers are restored in the
| finally whatever happens.
|
| Sabotage #12 blinds the auditor's scans: THIS positive control is what goes red.
| The clean-pass test would stay green under the blinding — vacuously — which is
| why the positive control exists at all (S19 lesson #7).
*/

test('FS-05: a clean platform passes the audit with exit 0', function () {
    $exit = asPlatformWorker(fn (): int => Artisan::call('tickets:invariant-audit'));

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('clean');
});

test('FS-05 positive control: seeded violations make the auditor FIRE — non-zero exit, row ids on the critical page', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $customerId = $conversation->customer_id;

    commitFixtures();

    $badStatusId = (string) Str::uuid7();
    $dupOpenId = (string) Str::uuid7();

    try {
        // THE BREACH: both physics layers removed, exactly what FS-05 anticipates.
        DB::connection('pgsql_migrator')->statement('ALTER TABLE tickets DROP CONSTRAINT tickets_status_check');
        DB::connection('pgsql_migrator')->statement('DROP INDEX tickets_one_open_family');

        // Violation 1: an illegal status.
        DB::connection('pgsql_platform')->table('tickets')->insert([
            'id' => $badStatusId,
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->identifier(),
            'customer_id' => $customerId,
            'number' => random_int(1, 2_000_000_000),
            'status' => 'haunted',
            'priority' => 'normal',
            'source' => 'agent',
            'linked_work' => true,   // keep it out of the open-family scan: one violation per scan
        ]);

        // Violation 2: a second non-linked open-family row in the fixture's conversation.
        DB::connection('pgsql_platform')->table('tickets')->insert([
            'id' => $dupOpenId,
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->identifier(),
            'customer_id' => $customerId,
            'number' => random_int(1, 2_000_000_000),
            'status' => 'open',
            'priority' => 'normal',
            'source' => 'agent',
            'linked_work' => false,
        ]);

        /** @var list<MessageLogged> $critical */
        $critical = [];
        Log::listen(function (MessageLogged $event) use (&$critical): void {
            if ($event->level === 'critical') {
                $critical[] = $event;
            }
        });

        $exit = asPlatformWorker(fn (): int => Artisan::call('tickets:invariant-audit'));
        $output = Artisan::output();   // output() flushes on read — capture once

        expect($exit)->toBe(1, 'FS-05: the page IS the non-zero exit — a breach that exits 0 pages nobody')
            ->and($output)->toContain('1 illegal-status row(s)')
            ->and($output)->toContain('1 conversation(s) with duplicate open-family');

        // "pages with row ids", literally.
        expect($critical)->toHaveCount(1);
        $context = $critical[0]->context;
        expect($context['illegal_status_rows'][0]['id'])->toBe($badStatusId)
            ->and($context['illegal_status_rows'][0]['status'])->toBe('haunted')
            ->and($context['duplicate_open_family'][0]['conversation_id'])->toBe($conversation->identifier())
            ->and($context['duplicate_open_family'][0]['ticket_ids'])->toContain($dupOpenId)
            ->and($context['duplicate_open_family'][0]['ticket_ids'])->toContain($ticket->identifier());
    } finally {
        // Restore the world in dependency order: rows out first (ADD CONSTRAINT
        // validates existing rows), then both physics layers back.
        DB::connection('pgsql_platform')->table('tickets')->whereIn('id', [$badStatusId, $dupOpenId])->delete();
        DB::connection('pgsql_migrator')->statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated', 'resolved', 'closed', 'spam'))");
        DB::connection('pgsql_migrator')->statement(<<<'SQL'
            CREATE UNIQUE INDEX tickets_one_open_family
                ON tickets (conversation_id)
             WHERE status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated')
               AND linked_work = false
            SQL);
        cleanupTenants([$tenantId]);
    }
});
