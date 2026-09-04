<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCHEMA AMENDMENT 7 grant physics (S13 step 2b; countersigned at 2a, C1–C4 folded) — the
 * AutomationGrantPhysicsTest shape for items 1–6. The denied sets are the
 * AMENDMENT'S OWN FILED SPLITS per privilege, asserted EXACTLY (a widened
 * grant is as red as a narrowed one). Executing controls: a config version
 * UPDATE dying 42501 (FR-20 immutable by grant), a widget key_id UPDATE
 * dying 42501 (Doc 02's physics re-asserted), captured_at re-stamped by the
 * fill trigger on UPDATE (R-20 shape), and the ref_* table holding no app
 * write at all.
 */
const DOC13_TABLES = ['widgets', 'widget_config_versions', 'widget_visitor_contexts', 'widget_health_counters', 'widget_beacon_samples', 'vault_secrets'];

/** The filed denied set per table AND PER PRIVILEGE (sorted). '*' = every column. */
const DOC13_DENIED = [
    'widgets' => [
        // Doc 02's columns stay app-writable incl. the hygiene pair (the Eloquent model writes
        // them — a S14 fact the filing had wrong: widgets carried TABLE-level grants); C1 denies
        // the rotation stamp everywhere; identity/creation immutable on UPDATE; key_id stays
        // updatable (a Doc 01 key rotation re-points it).
        'INSERT' => ['identity_secret_rotated_at'],
        'UPDATE' => ['created_at', 'id', 'identity_secret_rotated_at', 'tenant_id'],
    ],
    'widget_config_versions' => [
        // published_at is ORDERING-RELEVANT (the publish history) — R-19b; hygiene app-denied.
        'INSERT' => ['created_at', 'published_at'],
        'UPDATE' => '*', // immutable by grant (as ruled)
    ],
    'widget_visitor_contexts' => [
        // captured_at is the trigger's (R-20); identity immutable on UPDATE.
        'INSERT' => ['captured_at', 'created_at', 'updated_at'],
        'UPDATE' => ['captured_at', 'conversation_id', 'created_at', 'id', 'tenant_id', 'visitor_id'],
    ],
    'widget_health_counters' => [
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['created_at', 'day', 'id', 'tenant_id', 'widget_id'],
    ],
    'widget_beacon_samples' => [
        'INSERT' => ['occurred_at'],
        'UPDATE' => '*',
    ],
    'vault_secrets' => [
        // RIDER 1: ciphertext behind a vault:// ref; INSERT-only; retire (never rewrite) a predecessor.
        'INSERT' => ['created_at', 'retired_at'],
        'UPDATE' => ['ciphertext', 'created_at', 'ref', 'tenant_id'],
    ],
];

it('R-19b/ADR-0003: the app denied set per widget table and privilege is EXACTLY the amendment-7 filing', function (): void {
    foreach (DOC13_TABLES as $table) {
        $columns = doc12Columns($table);
        expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table}");
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $expected = DOC13_DENIED[$table][$privilege];
            $denied = doc12DeniedSet($table, $privilege);
            if ($expected === '*') {
                expect($denied)->toBe($columns, "{$table} {$privilege}: app should hold NO column grant");
            } else {
                expect($denied)->toBe($expected, "{$table} {$privilege} denied set drifted");
            }
        }
    }
});

it('N-01: every widget tenant table is FORCED RLS with tenant_isolation, app holds no DELETE on any, and ref_widget_bundles is app-READ-ONLY platform reference data', function (): void {
    foreach (DOC13_TABLES as $table) {
        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($row->relrowsecurity)->toBeTrue("{$table} has RLS disabled")
            ->and($row->relforcerowsecurity)->toBeTrue("{$table} does not FORCE RLS");
        /** @var object{n: int|string} $policy */
        $policy = DB::selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);
        expect((int) $policy->n)->toBe(1, "{$table} lacks the tenant_isolation policy");
        /** @var object{ok: bool} $del */
        $del = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$table]);
        expect($del->ok)->toBeFalse("app can DELETE from {$table}");
    }

    foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
        /** @var object{ok: bool} $ref */
        $ref = DB::selectOne("SELECT has_table_privilege('app', 'ref_widget_bundles', ?) AS ok", [$privilege]);
        expect($ref->ok)->toBeFalse("app can {$privilege} ref_widget_bundles — the pointer is the release pipeline's");
    }
    /** @var object{ok: bool} $sel */
    $sel = DB::selectOne("SELECT has_table_privilege('app', 'ref_widget_bundles', 'SELECT') AS ok");
    expect($sel->ok)->toBeTrue('the shim must read the pointer');
});

it('FR-20 / Doc 02 physics: a config-version UPDATE and a widgets key_id UPDATE die 42501 — with a lawful publish + pointer flip as the positive control; captured_at is re-stamped by the trigger, never by the app', function (): void {
    [$tenant, $widget] = widgetFixture();
    bindTenant($tenant);

    // Positive control: INSERT a version, flip the pointer — both granted.
    $versionId = (string) Str::uuid7();
    DB::table('widget_config_versions')->insert([
        'id' => $versionId, 'tenant_id' => $tenant->identifier(), 'widget_id' => $widget->getKey(),
        'version' => 1, 'config' => json_encode(['appearance' => []]), 'published_by' => (string) Str::uuid7(),
    ]);
    DB::table('widgets')->where('id', $widget->getKey())->update(['live_version_id' => $versionId, 'name' => 'Fixture widget']);
    expect(DB::table('widgets')->where('id', $widget->getKey())->value('live_version_id'))->toBe($versionId);

    foreach ([
        "UPDATE widget_config_versions SET config = '{}'::jsonb",
        "UPDATE widget_config_versions SET status = 'superseded'",
        "UPDATE widgets SET tenant_id = '00000000-0000-0000-0000-000000000000'",
        // C1's executing control: the rotation stamp is the trigger's, never the app's clock.
        'UPDATE widgets SET identity_secret_rotated_at = now()',
        'UPDATE widget_visitor_contexts SET captured_at = now()',
        'DELETE FROM widget_config_versions',
    ] as $sql) {
        try {
            DB::transaction(static fn () => DB::statement($sql));
            $this->fail("app executed: {$sql}");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('permission denied', $sql);
        }
    }

    // R-20 control: the trigger stamps captured_at on UPDATE from the DB clock.
    [, $conversation] = conversationFixture(); // the house helper: a shell customer + a conversation under the bound tenant
    $conversationId = $conversation->identifier();
    $contextId = (string) Str::uuid7();
    DB::table('widget_visitor_contexts')->insert([
        'id' => $contextId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversationId,
        'visitor_id' => 'v-fixture', 'url' => 'https://customer.harness.localhost:8443/deposit',
    ]);
    $first = DB::table('widget_visitor_contexts')->where('id', $contextId)->value('captured_at');
    DB::statement('SELECT pg_sleep(0.01)');
    DB::table('widget_visitor_contexts')->where('id', $contextId)->update(['url' => 'https://customer.harness.localhost:8443/withdraw']);
    $second = DB::table('widget_visitor_contexts')->where('id', $contextId)->value('captured_at');
    expect($second)->not->toBe($first, 'captured_at did not move on UPDATE — the fill trigger is missing');

    // C1 control: a rotation (the secret ref changing) moves the stamp from the DB clock;
    // an unrelated UPDATE leaves it exactly where it was.
    DB::table('widgets')->where('id', $widget->getKey())->update(['identity_secret_ref' => 'vault://widget/'.$widget->getKey().'/v1']);
    $rotated = DB::table('widgets')->where('id', $widget->getKey())->value('identity_secret_rotated_at');
    expect($rotated)->not->toBeNull('a rotation did not stamp identity_secret_rotated_at');
    DB::table('widgets')->where('id', $widget->getKey())->update(['name' => 'Renamed widget']);
    expect(DB::table('widgets')->where('id', $widget->getKey())->value('identity_secret_rotated_at'))->toBe($rotated, 'an unrelated UPDATE moved the rotation stamp');
    DB::statement('SELECT pg_sleep(0.01)');
    DB::table('widgets')->where('id', $widget->getKey())->update(['identity_secret_ref' => 'vault://widget/'.$widget->getKey().'/v2', 'identity_secret_previous_ref' => 'vault://widget/'.$widget->getKey().'/v1']);
    expect(DB::table('widgets')->where('id', $widget->getKey())->value('identity_secret_rotated_at'))->not->toBe($rotated, 'the second rotation did not re-stamp');
});
