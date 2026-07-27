<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| migration-safety gate (Doc 18 FR-09; convention C-12: expand-contract only)
|------------------------------------------------------------------------------
|
| Statically lints database/migrations/*.php. Blocks, in the up() phase:
|
|  1. DESTRUCTIVE OPS — dropColumn, dropTable/Schema::drop(IfExists),
|     renameColumn, dropForeign-with-column-drop. Expand-contract means drops
|     ship TWO releases after deprecation, alone, never with dependent code.
|     A legitimate contract-phase migration must carry the marker
|         @expand-contract: contract-phase <ticket/ADR ref>
|     in a comment, which asserts (reviewably) that the expand+backfill
|     releases already shipped.
|
|  2. LONG-LOCK PATTERNS — index creation on a HOT table via the schema
|     builder (->index(, ->unique(, ->fullText(), which takes a blocking
|     lock. Hot tables must use DB::statement('CREATE INDEX CONCURRENTLY …')
|     outside a transaction ($withinTransaction = false).
|
|  3. AUDIT-TABLE CHANGES — any migration touching an audit table must carry
|         @audit-change-control: <ref>
|     (Doc 17 FR-11): the audit log's shape is under change control.
|
| Exit 0 = pass, 1 = violations. An empty migrations directory passes.
|
*/

$root = dirname(__DIR__);
$dir = $root.'/database/migrations';
$violations = [];

$hotTables = ['messages', 'events', 'audit_entries', 'audit_log', 'conversations', 'tickets'];
$auditTables = ['audit_entries', 'audit_log', 'audit_anchors'];

$files = glob($dir.'/*.php') ?: [];
sort($files);

foreach ($files as $path) {
    $relative = substr($path, strlen($root) + 1);
    $contents = (string) file_get_contents($path);

    // Only lint the up() phase: everything before "function down".
    $downPos = strpos($contents, 'function down');
    $up = $downPos === false ? $contents : substr($contents, 0, $downPos);

    $hasContractMarker = preg_match('/@expand-contract:\s*contract-phase\s+\S+/', $contents) === 1;
    $hasAuditMarker = preg_match('/@audit-change-control:\s*\S+/', $contents) === 1;

    // 1. Destructive ops in up() without a contract-phase marker.
    $destructive = [
        '/->dropColumn\s*\(/' => 'dropColumn',
        '/Schema::drop(IfExists)?\s*\(/' => 'Schema::drop',
        '/->renameColumn\s*\(/' => 'renameColumn',
        '/DROP\s+(TABLE|COLUMN)/i' => 'raw DROP',
    ];
    foreach ($destructive as $regex => $label) {
        if (preg_match($regex, $up) === 1 && ! $hasContractMarker) {
            $violations[] = sprintf(
                '%s: destructive op (%s) in up() without "@expand-contract: contract-phase <ref>" marker — expand-contract only (C-12)',
                $relative,
                $label,
            );
        }
    }

    // 2. Blocking index creation on hot tables.
    foreach ($hotTables as $table) {
        $touchesTable = preg_match('/Schema::(table|create)\s*\(\s*[\'"]'.preg_quote($table, '/').'[\'"]/', $up) === 1;
        if ($touchesTable && preg_match('/->(index|unique|fullText)\s*\(/', $up) === 1) {
            $violations[] = sprintf(
                '%s: schema-builder index on hot table "%s" takes a blocking lock — use DB::statement(\'CREATE INDEX CONCURRENTLY …\') with $withinTransaction = false',
                $relative,
                $table,
            );
        }
    }

    // 3. Audit-table changes need the change-control marker.
    foreach ($auditTables as $table) {
        $touchesAudit = preg_match('/[\'"]'.preg_quote($table, '/').'[\'"]/', $up) === 1;
        if ($touchesAudit && ! $hasAuditMarker) {
            $violations[] = sprintf(
                '%s: touches audit table "%s" without "@audit-change-control: <ref>" marker (Doc 17 FR-11)',
                $relative,
                $table,
            );
        }
    }
}

if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, "migration-safety: $v\n");
    }
    fwrite(STDERR, sprintf("migration-safety: FAIL (%d violation(s))\n", count($violations)));
    exit(1);
}

printf("migration-safety: PASS (%d migration(s) linted)\n", count($files));
exit(0);
