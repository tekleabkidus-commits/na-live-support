<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The relay LIVENESS probe (S07 step 14, advisor ruling condition 2 on the
 * no-process discovery): proves a committed outbox row reaches the events
 * log within the documents' own budget, moved by the RUNNING relay service.
 *
 * A COMMAND, not a Pest test, for a structural reason found the hard way:
 * in-container Pest runs against the per-process TESTING database while the
 * relay service watches the runtime database — a test can never observe the
 * sibling process. This probe runs in the runtime environment itself.
 *
 * N, DERIVED AND STATED: T-02/FR-11 promise live list updates within a 5 s
 * batch window over a live feed. The relay is the FIRST leg of that pipeline
 * (outbox → events → consumer → Reverb → client batch); a first leg longer
 * than the WHOLE promise is structurally late. Budget therefore defaults to
 * 5 s and the measured figure is always printed.
 *
 * Guarded to local/testing/ci: the probe row is synthetic and must never
 * pollute a production event log.
 */
final class RelayLivenessProbe extends Command
{
    protected $signature = 'outbox:liveness-probe {--budget=5 : seconds the relay has (T-02/FR-11 bound)}';

    protected $description = 'Prove the relay service moves a committed outbox row into the events log within budget';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            $this->error('outbox:liveness-probe is a stack proof for local/testing/ci only.');

            return self::FAILURE;
        }

        /** @var object{id: string}|null $tenant */
        $tenant = DB::connection('pgsql_platform')->table('tenants')->first(['id']);
        if ($tenant === null) {
            $this->error('no tenant exists — seed the browser fixtures first.');

            return self::FAILURE;
        }

        $budget = max(1.0, (float) $this->option('budget'));
        $eventId = (string) Str::uuid7();

        DB::connection('pgsql_platform')->table('outbox')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'event_id' => $eventId,
            'name' => 'fixture.relay_liveness',
            'schema_version' => 1,
            'payload' => json_encode(['probe' => 'relay-liveness', 'synthetic' => true], JSON_THROW_ON_ERROR),
            'correlation_id' => (string) Str::uuid7(),
            'occurred_at' => DB::raw('clock_timestamp()'),
        ]);

        $start = microtime(true);
        while (microtime(true) - $start < $budget) {
            if (DB::connection('pgsql_platform')->table('events')->where('id', $eventId)->exists()) {
                $this->info(sprintf(
                    'RELAY LIVE: relayed in %.2f s (budget %.1f s — T-02/FR-11\'s 5 s promise bounds the pipeline)',
                    microtime(true) - $start,
                    $budget,
                ));

                return self::SUCCESS;
            }
            usleep(200_000);
        }

        $this->error(sprintf(
            'RELAY DEAD OR LATE: a committed outbox row did not reach the events log in %.1f s — is the `relay` compose service running?',
            $budget,
        ));

        return self::FAILURE;
    }
}
