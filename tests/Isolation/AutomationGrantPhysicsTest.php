<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCHEMA AMENDMENT 6 grant physics, automation half (S12 step 2b; countersign
 * obligations 1 + 4) — the KbGrantPhysicsTest shape for items 1–4 and 11–14.
 * The denied sets are the AMENDMENT'S OWN FILED SPLITS per privilege, asserted
 * EXACTLY (a widened grant is as red as a narrowed one). The executing controls:
 * field_definitions key/type/entity UPDATE dying 42501 (FR-18's "type changes
 * prohibited" as column physics — sabotage (h)'s standing red), form_versions
 * holding NO UPDATE at all (BR-06), rule-revision definitions immutable, and the
 * R-20 never-re-stamps control on the activation fill-only trigger.
 */
const DOC12_AUTOMATION_TABLES = [
    'automation_rules', 'automation_rule_revisions', 'automation_rule_executions',
    'automation_keyword_sets', 'field_definitions', 'field_definition_options',
    'forms', 'form_versions', 'form_flow_checkpoints',
    // RIDER 5 (step 13): the external arms' three tables.
    'automation_webhook_destinations', 'automation_webhook_deliveries', 'customer_marketing_suppressions',
];

/** The filed denied set per table AND PER PRIVILEGE (sorted). '*' = every column. */
const DOC12_AUTOMATION_DENIED = [
    'automation_rules' => [
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'automation_rule_revisions' => [
        // Born a draft: activation fields and the SEC-01 floor arrive at activation.
        'INSERT' => ['activated_at', 'activated_by', 'activator_capability_floor', 'created_at'],
        // History physics: definition/author/rule immutable; activated_at is the trigger's.
        'UPDATE' => ['activated_at', 'author_id', 'created_at', 'definition', 'id', 'rule_id', 'tenant_id'],
    ],
    'automation_rule_executions' => [
        'INSERT' => ['created_at', 'executed_at'],
        // RIDER 2: the queued chain's ONE landing write — everything else immutable.
        'UPDATE' => ['condition_results', 'context_ref', 'created_at', 'event_id', 'executed_at', 'guard_trips', 'id', 'matched', 'revision_id', 'rule_id', 'tenant_id'],
    ],
    'automation_keyword_sets' => [
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'field_definitions' => [
        // A field is never BORN deactivated.
        'INSERT' => ['created_at', 'deactivated_at', 'updated_at'],
        // FR-18: entity/key/type immutable BY GRANT — the type-change refusal is physics.
        'UPDATE' => ['created_at', 'entity', 'id', 'key', 'tenant_id', 'type'],
    ],
    'field_definition_options' => [
        // label_snapshot is captured at removal, never at birth (EC-08).
        'INSERT' => ['created_at', 'label_snapshot', 'updated_at'],
        'UPDATE' => ['created_at', 'field_id', 'id', 'tenant_id', 'value'],
    ],
    'forms' => [
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'form_versions' => [
        // published_at is ORDERING-RELEVANT (version history) — R-19b.
        'INSERT' => ['created_at', 'published_at'],
        'UPDATE' => '*',
    ],
    'form_flow_checkpoints' => [
        // RIDER 3: born live — outcome/finished arrive at the close, never at birth.
        'INSERT' => ['created_at', 'finished_at', 'outcome', 'updated_at'],
        // EC-06's pin as physics: the flow's version (and identity) never moves.
        'UPDATE' => ['conversation_id', 'created_at', 'form_id', 'id', 'language', 'mode', 'tenant_id', 'version_id'],
    ],
    'automation_webhook_destinations' => [
        // RIDER 5 A: born active with a clean streak; the EC-13 stamps arrive by outcome.
        'INSERT' => ['active', 'auto_disabled_at', 'created_at', 'failure_streak', 'last_delivered_at', 'last_error', 'last_failed_at', 'updated_at'],
        // SEC-02 as physics: url/host (and provenance) never move — a corrected target is a new row.
        'UPDATE' => ['created_at', 'created_by', 'host', 'id', 'tenant_id', 'url'],
    ],
    'automation_webhook_deliveries' => [
        // RIDER 5 B: born queued with zero attempts.
        'INSERT' => ['attempts', 'created_at', 'delivered_at', 'last_error', 'last_status_code', 'next_attempt_at', 'state', 'updated_at'],
        // What was sent is what was recorded: payload, destination and provenance immutable.
        'UPDATE' => ['created_at', 'destination_id', 'event_name', 'execution_id', 'id', 'payload', 'rule_id', 'tenant_id'],
    ],
    'customer_marketing_suppressions' => [
        // RIDER 5 C: born live; the clear arrives by outcome.
        'INSERT' => ['cleared_at', 'cleared_by', 'created_at', 'updated_at'],
        // Identity and reason immutable; NO DELETE — history stays.
        'UPDATE' => ['created_at', 'customer_id', 'id', 'reason', 'rule_ref', 'source', 'tenant_id'],
    ],
];

it('R-19b/ADR-0003: the app denied set per automation table and privilege is EXACTLY the amendment-6 filing', function (): void {
    foreach (DOC12_AUTOMATION_TABLES as $table) {
        $columns = doc12Columns($table);
        expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table}");
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $expected = DOC12_AUTOMATION_DENIED[$table][$privilege];
            $denied = doc12DeniedSet($table, $privilege);
            if ($expected === '*') {
                expect($denied)->toBe($columns, "{$table} {$privilege}: app should hold NO column grant");
            } else {
                expect($denied)->toBe($expected, "{$table} {$privilege} denied set drifted");
            }
        }
    }
});

it('N-01: every automation table is FORCED RLS with tenant_isolation, and app holds no DELETE on any of them', function (): void {
    foreach (DOC12_AUTOMATION_TABLES as $table) {
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
});

it('FR-18 physics (countersign obligation 1): a field key/type/entity UPDATE and a form_versions UPDATE die 42501 — with a lawful UPDATE as the positive control', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    // Positive control: the grants that SHOULD work, do (the refusals below are
    // privilege physics, not a broken binding).
    $fieldId = (string) Str::uuid7();
    DB::table('field_definitions')->insert([
        'id' => $fieldId, 'tenant_id' => $tenant->identifier(),
        'entity' => 'ticket', 'key' => 'payment_method', 'type' => 'dropdown',
    ]);
    DB::table('field_definitions')->where('id', $fieldId)->update(['position' => 3]);
    expect(DB::table('field_definitions')->where('id', $fieldId)->value('position'))->toBe(3);
    // RIDER 3 positive control: the checkpoint write columns ARE granted.
    DB::statement('UPDATE form_flow_checkpoints SET step_index = step_index');

    foreach ([
        "UPDATE field_definitions SET key = 'renamed_key'",
        "UPDATE field_definitions SET type = 'text'",
        "UPDATE field_definitions SET entity = 'customer'",
        "UPDATE form_versions SET schema = '{}'::jsonb",
        "UPDATE automation_rule_revisions SET definition = '{}'::jsonb",
        'UPDATE automation_rule_executions SET matched = false',
        // RIDER 3 / EC-06's fail-first control: a running flow's version can never move.
        "UPDATE form_flow_checkpoints SET version_id = '00000000-0000-0000-0000-000000000000'",
    ] as $sql) {
        try {
            DB::transaction(static fn () => DB::statement($sql));
            $this->fail("app executed: {$sql}");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('permission denied', $sql);
        }
    }
});

it('R-20 control (countersign obligation 4): the activation stamp fills once from the database clock and NEVER re-stamps', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    $ruleId = (string) Str::uuid7();
    DB::table('automation_rules')->insert([
        'id' => $ruleId, 'tenant_id' => $tenant->identifier(),
        'name' => 'Fixture rule', 'trigger' => 'ticket.created', 'position' => 1,
    ]);
    $revisionId = (string) Str::uuid7();
    $author = (string) Str::uuid7();
    DB::table('automation_rule_revisions')->insert([
        'id' => $revisionId, 'tenant_id' => $tenant->identifier(),
        'rule_id' => $ruleId, 'definition' => json_encode(['actions' => []]), 'author_id' => $author,
    ]);

    $draft = DB::table('automation_rule_revisions')->where('id', $revisionId)->first();
    expect($draft->activated_at)->toBeNull('a draft revision carries no activation stamp');

    // The flip: activated_by NULL -> value stamps activated_at in DB time.
    $activator = (string) Str::uuid7();
    DB::table('automation_rule_revisions')->where('id', $revisionId)->update(['activated_by' => $activator]);
    $activated = DB::table('automation_rule_revisions')->where('id', $revisionId)->first();
    expect($activated->activated_at)->not->toBeNull('the fill-only trigger did not stamp');
    expect($activated->activated_by)->toBe($activator);

    // The never-re-stamps control: a second write moves NOTHING — the trigger
    // restores both activation fields (a floor refresh is a NEW revision, by design).
    DB::table('automation_rule_revisions')->where('id', $revisionId)->update(['activated_by' => (string) Str::uuid7()]);
    $after = DB::table('automation_rule_revisions')->where('id', $revisionId)->first();
    expect($after->activated_at)->toBe($activated->activated_at, 'activated_at MOVED — the fill-only law is broken')
        ->and($after->activated_by)->toBe($activator, 'activated_by moved after activation');
});

it('RIDER 5 physics (step 13): a destination url, a delivery payload and a suppression reason each die 42501 on UPDATE — SEC-02 / the dispute record / the history posture as column grants', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    $customerId = (string) DB::table('tickets')->where('id', $ticket->identifier())->value('customer_id');
    $destinationId = (string) Str::uuid7();
    DB::table('automation_webhook_destinations')->insert(['id' => $destinationId, 'tenant_id' => $tenant->identifier(), 'name' => 'd', 'url' => 'https://hooks.example.test/a', 'host' => 'hooks.example.test', 'created_by' => (string) Str::uuid7()]);
    $deliveryId = (string) Str::uuid7();
    DB::table('automation_webhook_deliveries')->insert(['id' => $deliveryId, 'tenant_id' => $tenant->identifier(), 'destination_id' => $destinationId, 'event_name' => 'automation.rule.fired', 'payload' => '{}']);
    $suppressionId = (string) Str::uuid7();
    DB::table('customer_marketing_suppressions')->insert(['id' => $suppressionId, 'tenant_id' => $tenant->identifier(), 'customer_id' => $customerId, 'reason' => 'r', 'source' => 'rule']);

    foreach ([
        ['automation_webhook_destinations', $destinationId, ['url' => 'https://10.0.0.1/x']],
        ['automation_webhook_deliveries', $deliveryId, ['payload' => '{"forged":true}']],
        ['customer_marketing_suppressions', $suppressionId, ['reason' => 'rewritten']],
    ] as [$table, $id, $update]) {
        try {
            DB::transaction(static fn () => DB::table($table)->where('id', $id)->update($update)); // a savepoint per refusal
            $this->fail("{$table}: the immutable column moved");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('42501', "{$table} — expected the grant refusal");
        }
    }
    // The lawful writes still land (the grant is a split, not a wall).
    DB::table('automation_webhook_destinations')->where('id', $destinationId)->update(['active' => false]);
    DB::table('automation_webhook_deliveries')->where('id', $deliveryId)->update(['attempts' => 1, 'state' => 'abandoned']);
    DB::table('customer_marketing_suppressions')->where('id', $suppressionId)->update(['cleared_at' => DB::raw('clock_timestamp()')]);
    expect(DB::table('customer_marketing_suppressions')->where('id', $suppressionId)->value('cleared_at'))->not->toBeNull();
});
