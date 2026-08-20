<?php

declare(strict_types=1);

namespace App\Domain\Ai\DecisionLog;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly partition arithmetic for ai_decisions (SCHEMA AMENDMENT 3 item 1;
 * Doc 09 FR-20/NFR-05) — the class the ai_decisions migration promised by
 * name; the MessagePartitions/EventPartitions shape.
 *
 * DELIBERATELY DOC 09-OWNED with its own console command
 * (ai:partitions-maintain) instead of extending Doc 01/04's
 * PartitionsCheck/Maintain — zero edits to other documents' files; the
 * advisor may fold it into the platform command later (flagged at the
 * step-2 checkpoint). No dropExpired here: rows never drop wholesale in
 * v1 — SEC-06's schedule REDUCES payloads at 30 days (the platform-role
 * job, step 4); partition retention policy is Doc 18/19 territory.
 *
 * All DDL on the migrator connection (ADR 0003); "today" is a parameter,
 * never an internal now() (the §8 no-clock rule).
 */
final class AiDecisionPartitions
{
    public const MONTHS_AHEAD = 3;

    private const CONNECTION = 'pgsql_migrator';

    /** The partition holding a given month, e.g. ai_decisions_2026_08. */
    public function nameFor(CarbonImmutable $month): string
    {
        return sprintf('ai_decisions_%s', $month->startOfMonth()->format('Y_m'));
    }

    /** @return list<string> names of every existing monthly partition, ascending */
    public function existing(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT relname FROM pg_class WHERE relname ~ '^ai_decisions_\\d{4}_\\d{2}$' ORDER BY relname",
        );

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    /**
     * Idempotent by construction: every run converges on the same window.
     *
     * @return list<string> partitions created this run
     */
    public function ensureFutureMonths(CarbonImmutable $today): array
    {
        $created = [];
        $month = $today->startOfMonth();

        for ($i = 0; $i <= self::MONTHS_AHEAD; $i++) {
            $name = $this->nameFor($month);

            if (! in_array($name, $this->existing(), true)) {
                $this->createMonth($month);
                $created[] = $name;
            }

            $month = $month->addMonth();
        }

        return $created;
    }

    private function createMonth(CarbonImmutable $month): void
    {
        $start = $month->startOfMonth();
        $name = $this->nameFor($start);
        $connection = DB::connection(self::CONNECTION);

        $connection->statement(sprintf(
            'CREATE TABLE %s PARTITION OF ai_decisions FOR VALUES FROM (%s) TO (%s)',
            $name,
            sprintf("'%s'", $start->toDateTimeString()),
            sprintf("'%s'", $start->addMonth()->toDateTimeString()),
        ));

        // The messages lesson: a parent's policies do not govern DIRECT
        // partition queries — every partition carries its own forced policy.
        $connection->statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
        $connection->statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
        $connection->statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$name}
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
        $connection->statement("REVOKE DELETE ON {$name} FROM app");
    }
}
