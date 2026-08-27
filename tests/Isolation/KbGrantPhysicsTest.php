<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 4's grant physics, asserted from the catalog — the
 * AiGrantPhysicsTest shape for Doc 10's seven tables + the partitioned
 * kb_chunks (S10 step 2b). The denied sets are the AMENDMENT'S OWN FILED SPLITS
 * per privilege, asserted EXACTLY (a widened grant is as red as a narrowed one).
 * Also here: kb_article_versions / kb_slug_history hold NO UPDATE grant at all
 * (AC-07 / T-06 physics); every table AND both partitions are FORCED RLS with
 * tenant_isolation and app holds no DELETE (STOP-A condition 5: the refusal is
 * executed, not inferred); the partition set is exactly public + internal
 * (STOP-B); the ONLY non-PK unique on kb_chunks is the new arbiter and the old
 * (article_ref, chunk_index) arbiter is GONE (STOP-A condition 3 — with the
 * amendment-3 FLAG-2 sweep: no ON CONFLICT targets kb_chunks anywhere in app/);
 * FLAG B: kb_feedback's dedupe index is its only non-PK unique and the only
 * lawful ON CONFLICT target; nothing in app/ references kb_chunks_legacy.
 */
const DOC10_TABLES = [
    'kb_categories', 'kb_articles', 'kb_article_variants', 'kb_article_versions',
    'kb_slug_history', 'kb_review_comments', 'kb_feedback', 'kb_chunks',
];

const DOC10_PARTITIONS = ['kb_chunks_internal', 'kb_chunks_public'];

/** The filed denied set per table AND PER PRIVILEGE (sorted). '*' = every column. */
const DOC10_DENIED_COLUMNS = [
    'kb_categories' => [
        // A row is never BORN deleted: deleted_at is UPDATE-only (disclosed deviation from
        // the amendment's "minus created_at" wording — a narrower grant, not a wider one).
        'INSERT' => ['created_at', 'deleted_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'kb_articles' => [
        'INSERT' => ['created_at', 'deleted_at'],
        // seeded is set at birth (BR-07); audience stays updatable behind the one door (FLAG A).
        'UPDATE' => ['created_at', 'created_by', 'id', 'seeded', 'tenant_id'],
    ],
    'kb_article_variants' => [
        'INSERT' => ['created_at', 'current_version_id', 'published_at', 'submitted_at', 'working_copy_saved_at'],
        'UPDATE' => ['article_id', 'created_at', 'id', 'language', 'tenant_id'],
    ],
    'kb_article_versions' => [
        // published_at is ORDERING-RELEVANT (SS-04) — R-19a both directions.
        'INSERT' => ['created_at', 'published_at'],
        'UPDATE' => '*',
    ],
    'kb_slug_history' => [
        'INSERT' => ['created_at'],
        'UPDATE' => '*',
    ],
    'kb_review_comments' => [
        'INSERT' => ['created_at', 'resolved_at', 'resolved_by'],
        'UPDATE' => ['author_id', 'block_anchor', 'body', 'created_at', 'id', 'tenant_id', 'variant_id', 'version_id'],
    ],
    'kb_feedback' => [
        'INSERT' => ['actioned_at', 'actioned_by', 'created_at'],
        'UPDATE' => ['article_id', 'created_at', 'id', 'source', 'staff_id', 'tenant_id', 'variant_id', 'visitor_hash'],
    ],
    'kb_chunks' => [
        'INSERT' => ['content_tsv', 'created_at'],
        'UPDATE' => ['article_ref', 'audience', 'chunk_index', 'content_tsv', 'created_at', 'id', 'tenant_id', 'variant_id'],
    ],
];

function kbColumns(string $table): array
{
    /** @var list<object{column_name: string}> $rows */
    $rows = DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY column_name', ['public', $table]);

    return array_map(static fn (object $row): string => $row->column_name, $rows);
}

it('R-19b/ADR-0003: the app role denied set per table and privilege is EXACTLY the amendment-4 filing', function (): void {
    foreach (DOC10_TABLES as $table) {
        $columns = kbColumns($table);
        expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table}");
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $denied = [];
            foreach ($columns as $column) {
                /** @var object{ok: bool} $row */
                $row = DB::selectOne('SELECT has_column_privilege(\'app\', ?, ?, ?) AS ok', [$table, $column, $privilege]);
                if (! $row->ok) {
                    $denied[] = $column;
                }
            }
            sort($denied);
            $expected = DOC10_DENIED_COLUMNS[$table][$privilege];
            expect($denied)->toBe($expected === '*' ? $columns : $expected, sprintf('%s %s denied set drifted from SCHEMA AMENDMENT 4 (found: %s)', $table, $privilege, implode(', ', $denied)));
        }
    }
});

it('AC-07/T-06 physics: kb_article_versions and kb_slug_history carry NO app UPDATE grant at any level', function (): void {
    foreach (['kb_article_versions', 'kb_slug_history'] as $table) {
        /** @var object{ok: bool} $row */
        $row = DB::selectOne("SELECT has_table_privilege('app', ?, 'UPDATE') AS ok", [$table]);
        expect($row->ok)->toBeFalse("{$table} is updatable by app — immutability is gone");
        /** @var object{n: int|string} $cols */
        $cols = DB::selectOne("SELECT count(*) AS n FROM information_schema.column_privileges WHERE grantee = 'app' AND table_name = ? AND privilege_type = 'UPDATE'", [$table]);
        expect((int) $cols->n)->toBe(0, "{$table} has column-level UPDATE grants for app");
    }
});

it('ADR 0003 / STOP-A condition 5: app holds no DELETE on any Doc 10 table nor on either kb_chunks partition — and the refusal EXECUTES', function (): void {
    foreach (array_merge(DOC10_TABLES, DOC10_PARTITIONS) as $table) {
        /** @var object{ok: bool} $row */
        $row = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$table]);
        expect($row->ok)->toBeFalse("app can DELETE from {$table}");
    }
    $tenant = createTenant();
    bindTenant($tenant);
    foreach (['kb_chunks', 'kb_chunks_public', 'kb_chunks_internal', 'kb_article_versions'] as $table) {
        try {
            DB::transaction(static fn () => DB::statement("DELETE FROM {$table}"));
            $this->fail("app deleted from {$table}");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('permission denied', $table);
        }
    }
});

it('N-01: every Doc 10 table and both kb_chunks partitions are FORCED row-level security with the tenant_isolation policy', function (): void {
    foreach (array_merge(DOC10_TABLES, DOC10_PARTITIONS) as $table) {
        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($row->relrowsecurity)->toBeTrue("{$table} has RLS disabled")
            ->and($row->relforcerowsecurity)->toBeTrue("{$table} does not FORCE RLS");
        /** @var object{n: int|string} $policy */
        $policy = DB::selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);
        expect((int) $policy->n)->toBe(1, "{$table} lacks the tenant_isolation policy");
    }
});

it('STOP-B: kb_chunks is LIST-partitioned by audience into exactly public + internal, each partition carrying its OWN HNSW and GIN indexes', function (): void {
    /** @var list<object{relname: string}> $rows */
    $rows = DB::select("SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid JOIN pg_class p ON p.oid = i.inhparent WHERE p.relname = 'kb_chunks' ORDER BY c.relname");
    expect(array_map(static fn (object $r): string => $r->relname, $rows))->toBe(DOC10_PARTITIONS);
    /** @var object{partstrat: string} $strategy */
    $strategy = DB::selectOne("SELECT partstrat FROM pg_partitioned_table pt JOIN pg_class c ON c.oid = pt.partrelid WHERE c.relname = 'kb_chunks'");
    expect($strategy->partstrat)->toBe('l');
    foreach (DOC10_PARTITIONS as $partition) {
        /** @var list<object{amname: string}> $ams */
        $ams = DB::select('SELECT am.amname FROM pg_index ix JOIN pg_class i ON i.oid = ix.indexrelid JOIN pg_class t ON t.oid = ix.indrelid JOIN pg_am am ON am.oid = i.relam WHERE t.relname = ? ORDER BY am.amname', [$partition]);
        $names = array_map(static fn (object $r): string => $r->amname, $ams);
        expect($names)->toContain('hnsw')->toContain('gin');
    }
});

it('STOP-A condition 3 + FLAG B: the ONLY non-PK unique on kb_chunks is the new arbiter (the old article_ref arbiter is GONE); kb_feedback\'s only non-PK unique is the visitor dedupe', function (): void {
    $uniques = static function (string $table): array {
        /** @var list<object{indexname: string}> $rows */
        $rows = DB::select('SELECT i.relname AS indexname FROM pg_index ix JOIN pg_class i ON i.oid = ix.indexrelid JOIN pg_class t ON t.oid = ix.indrelid WHERE t.relname = ? AND ix.indisunique ORDER BY 1', [$table]);

        return array_map(static fn (object $r): string => $r->indexname, $rows);
    };
    expect($uniques('kb_chunks'))->toBe(['kb_chunks_arbiter', 'kb_chunks_pkey'])
        ->and($uniques('kb_feedback'))->toBe(['kb_feedback_pkey', 'kb_feedback_visitor_dedupe']);
    /** @var object{n: int|string} $legacy */
    $legacy = DB::selectOne("SELECT count(*) AS n FROM pg_class WHERE relname IN ('kb_chunks_identity', 'kb_chunks_legacy', 'kb_chunks_legacy_identity')");
    expect((int) $legacy->n)->toBe(0, 'the old arbiter or the legacy table still exists — FLAG C\'s contract phase has not landed');
});

it('FLAG-2 / FLAG B static sweep: no ON CONFLICT or upsert() in app/ targets kb_chunks; the only lawful kb_feedback upserter is the feedback writer; nothing references kb_chunks_legacy', function (): void {
    $chunkOffenders = [];
    $feedbackUpserters = [];
    $legacyRefs = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) php_strip_whitespace($file->getPathname());
        $rel = str_replace(base_path().'/', '', $file->getPathname());
        $upserts = str_contains($source, 'ON CONFLICT') || str_contains($source, '->upsert(') || str_contains($source, '->updateOrInsert(');
        if (str_contains($source, 'kb_chunks') && $upserts) {
            $chunkOffenders[] = $rel;
        }
        if (str_contains($source, 'kb_feedback') && $upserts) {
            $feedbackUpserters[] = $rel;
        }
        if (str_contains($source, 'kb_chunks_legacy')) {
            $legacyRefs[] = $rel;
        }
    }
    expect($chunkOffenders)->toBe([], 'ON CONFLICT/upsert targets kb_chunks: '.implode(', ', $chunkOffenders))
        ->and(array_diff($feedbackUpserters, ['app/Domain/Kb/Feedback/FeedbackWriter.php']))->toBe([], 'a second kb_feedback upserter: '.implode(', ', $feedbackUpserters))
        ->and($legacyRefs)->toBe([], 'app/ references kb_chunks_legacy: '.implode(', ', $legacyRefs));
});
