<?php

declare(strict_types=1);

use App\Domain\Ai\DecisionLog\AiDecisionPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 3's grant physics, asserted from the catalog — the
 * CustomerGrantPhysicsTest shape for Doc 09's five tables (S09 step 2b).
 *
 * The denied sets below are the AMENDMENT'S OWN FILED SPLITS, per privilege,
 * asserted EXACTLY: a widened grant (a column leaving a denied set) is as
 * red as a narrowed one, because the split is countersigned design, not a
 * starting point. Also here: the ai_decisions partition sweep (every
 * partition carries its own forced policy — the messages lesson), the
 * AiDecisionPartitions window proof, and flag 2's countersigned STATIC
 * assertion (no ON CONFLICT ever targets ai_config_versions — its races
 * serialize under the ConfigVersionRace sentinel instead).
 */
const DOC09_TABLES = [
    'ai_decisions', 'ai_config_versions', 'ai_kill_switches',
    'kb_chunks', 'ai_ticket_summaries',
];

/** The filed denied set per table AND PER PRIVILEGE (sorted). */
const DOC09_DENIED_COLUMNS = [
    'ai_decisions' => [
        // The SEC-06 reduction pair is platform-only in BOTH directions;
        // created_at is R-19a (ordering-relevant).
        'INSERT' => ['created_at', 'payload_hash', 'payloads_reduced_at'],
        // Enrichment is the ONE legal mutation: outcome, outcome_meta, updated_at.
        'UPDATE' => [
            'action', 'confidence', 'config_version_id', 'conversation_id',
            'created_at', 'customer_id', 'endpoint_ref', 'error_category', 'id',
            'intent', 'language', 'latency_ms', 'layer_hashes', 'message_id',
            'model', 'payload_hash', 'payloads_reduced_at', 'provider',
            'request_payload', 'response_payload', 'rg_risk', 'surface', 'task',
            'tenant_id', 'ticket_id', 'tokens_in', 'tokens_out',
        ],
    ],
    'ai_config_versions' => [
        // Born a draft: the publish stamps arrive only through UPDATE.
        'INSERT' => ['created_at', 'published_at', 'published_by'],
        // payload IMMUTABLE (FR-04 rollback correctness); identity never moves.
        'UPDATE' => ['created_at', 'created_by', 'id', 'key', 'kind', 'payload', 'tenant_id', 'version'],
    ],
    'ai_kill_switches' => [
        'INSERT' => ['created_at'],
        // The scope grid is identity; only the flip state mutates.
        'UPDATE' => ['channel', 'created_at', 'endpoint_ref', 'id', 'surface', 'tenant_id'],
    ],
    'kb_chunks' => [
        // content_tsv is GENERATED (unnameable either way, asserted anyway).
        'INSERT' => ['content_tsv', 'created_at'],
        // SCHEMA AMENDMENT 4 (S10 step 2b, countersigned): audience and variant_id
        // join the identity set — an audience change is a re-index into the other
        // partition through the one door, never a row move.
        'UPDATE' => ['article_ref', 'audience', 'chunk_index', 'content_tsv', 'created_at', 'id', 'tenant_id', 'variant_id'],
    ],
    'ai_ticket_summaries' => [
        'INSERT' => ['created_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id', 'ticket_id'],
    ],
];

it('R-19b/ADR-0003: the app role denied set per table and privilege is EXACTLY the amendment filing', function (): void {
    foreach (DOC09_TABLES as $table) {
        /** @var list<object{column_name: string}> $rows */
        $rows = DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY column_name',
            ['public', $table],
        );
        $columns = array_map(static fn (object $row): string => $row->column_name, $rows);
        expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table} — the derivation below would assert nothing");

        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $denied = [];
            foreach ($columns as $column) {
                /** @var object{ok: bool} $row */
                $row = DB::selectOne(
                    "SELECT has_column_privilege('app', ?, ?, ?) AS ok",
                    [$table, $column, $privilege],
                );
                if (! $row->ok) {
                    $denied[] = $column;
                }
            }
            sort($denied);

            expect($denied)->toBe(
                DOC09_DENIED_COLUMNS[$table][$privilege],
                sprintf('%s %s denied set drifted from SCHEMA AMENDMENT 3 (found: %s)', $table, $privilege, implode(', ', $denied)),
            );
        }
    }
});

it('ADR 0003: app holds no DELETE on any Doc 09 table', function (): void {
    foreach (DOC09_TABLES as $table) {
        /** @var object{ok: bool} $row */
        $row = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$table]);
        expect($row->ok)->toBeFalse("app can DELETE from {$table} — the amendment revokes it");
    }
});

it('N-01: every Doc 09 table is FORCED row-level security with the tenant_isolation policy', function (): void {
    foreach (DOC09_TABLES as $table) {
        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($row->relrowsecurity)->toBeTrue("{$table} has RLS disabled")
            ->and($row->relforcerowsecurity)->toBeTrue("{$table} does not FORCE RLS");

        /** @var object{n: int} $policy */
        $policy = DB::selectOne(
            "SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'",
            [$table],
        );
        expect((int) $policy->n)->toBe(1, "{$table} lacks its tenant_isolation policy");
    }
});

it('the messages lesson: EVERY ai_decisions partition carries its own forced policy and app DELETE revocation', function (): void {
    /** @var list<object{relname: string, relrowsecurity: bool, relforcerowsecurity: bool}> $partitions */
    $partitions = DB::select(<<<'SQL'
        SELECT relname, relrowsecurity, relforcerowsecurity
          FROM pg_class
         WHERE relname ~ '^ai_decisions_' AND relkind = 'r'
         ORDER BY relname
        SQL);

    expect(count($partitions))->toBeGreaterThanOrEqual(5, 'the migration promised MONTHS_AHEAD+1 partitions plus the default — the sweep below would be vacuous');

    foreach ($partitions as $partition) {
        expect($partition->relrowsecurity)->toBeTrue("{$partition->relname} has RLS disabled — a month-sized hole in N-01")
            ->and($partition->relforcerowsecurity)->toBeTrue("{$partition->relname} does not FORCE RLS");

        /** @var object{n: int} $policy */
        $policy = DB::selectOne(
            "SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'",
            [$partition->relname],
        );
        expect((int) $policy->n)->toBe(1, "{$partition->relname} lacks its own tenant_isolation policy");

        /** @var object{ok: bool} $delete */
        $delete = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$partition->relname]);
        expect($delete->ok)->toBeFalse("app can DELETE from {$partition->relname}");
    }
});

it('AiDecisionPartitions: the window is idempotent and a created month is born protected', function (): void {
    $manager = new AiDecisionPartitions;

    // Idempotence on the migration-created window: a second pass converges.
    expect($manager->ensureFutureMonths(CarbonImmutable::now('UTC')))->toBe([]);

    // A genuinely new month is created protected, then removed (test hygiene —
    // the removeMessagesPartition precedent).
    $future = CarbonImmutable::now('UTC')->startOfMonth()->addMonths(AiDecisionPartitions::MONTHS_AHEAD + 2);
    $name = $manager->nameFor($future);

    try {
        $created = $manager->ensureFutureMonths($future);
        expect($created)->toContain($name);

        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$name]);
        expect($row->relforcerowsecurity)->toBeTrue('a fresh partition arrived unprotected — the manager must protect at creation');
    } finally {
        DB::connection('pgsql_migrator')->statement("DROP TABLE IF EXISTS {$name}");
    }
});

it('flag 2 (countersigned): no ON CONFLICT statement ever targets ai_config_versions — races serialize via the sentinel', function (): void {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // php_strip_whitespace drops comments: the ban is on STATEMENTS, and
        // the sentinel classes' own docblocks lawfully NAME the banned form.
        $source = (string) php_strip_whitespace($file->getPathname());
        if (! str_contains($source, 'ai_config_versions')) {
            continue;
        }

        if (preg_match('/on\s+conflict/i', $source) === 1 || str_contains($source, '->upsert(')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([], 'a file touching ai_config_versions carries an upsert/ON CONFLICT: '.implode(', ', $offenders));
});
