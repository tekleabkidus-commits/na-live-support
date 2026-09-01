<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCHEMA AMENDMENT 6 grant physics, SLA/hours/CSAT half (S12 step 2b; countersign
 * obligations 1 + 4; RIDER 1's flip carriers) — items 5–10 and 15–16. The
 * executing controls: an sla_instances UPDATE dying 42501 on a REAL armed row
 * (BR-03 as physics — sabotage (d)'s standing red), a second survey_responses
 * INSERT dying on the unique (SEC-05 — sabotage (g)'s standing red), the
 * warned/breached fill-only stamps never re-stamping and never un-flipping
 * (R-20 control), and the holidays DELETE grant asserted PRESENT (the one
 * deliberate delete — a narrowing is as visible as a widening).
 */
const DOC12_SLA_TABLES = [
    'business_calendars', 'business_calendar_holidays', 'sla_policies',
    'sla_instances', 'sla_instance_stages', 'surveys', 'survey_responses', 'macros',
];

/** The filed denied set per table AND PER PRIVILEGE (sorted). '*' = every column. */
const DOC12_SLA_DENIED = [
    'business_calendars' => [
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'business_calendar_holidays' => [
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['calendar_id', 'created_at', 'id', 'tenant_id'],
    ],
    'sla_policies' => [
        'INSERT' => ['created_at', 'updated_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'sla_instances' => [
        // armed_at is the SS-02 record's metric instant — R-19b.
        'INSERT' => ['armed_at', 'created_at'],
        // BR-03: NO UPDATE of any kind — an armed instance is a contract.
        'UPDATE' => '*',
    ],
    'sla_instance_stages' => [
        // Born un-warned, un-breached, un-cleared; stamps are the trigger's (RIDER 1).
        'INSERT' => ['breached', 'breached_at', 'cleared_at', 'created_at', 'late_fired', 'pauses', 'retargeted', 'target_at', 'updated_at', 'warned', 'warned_at'],
        'UPDATE' => ['breached_at', 'clock_start_at', 'created_at', 'id', 'instance_id', 'stage', 'target_us', 'tenant_id', 'warned_at'],
    ],
    'surveys' => [
        'INSERT' => ['created_at', 'expired_at', 'sent_at'],
        'UPDATE' => ['channel', 'created_at', 'customer_id', 'expires_at', 'id', 'resolution_event_id', 'resolver_type', 'sent_at', 'tenant_id', 'ticket_id', 'token_hash'],
    ],
    'survey_responses' => [
        'INSERT' => ['created_at', 'edited_at', 'responded_at'],
        'UPDATE' => ['created_at', 'id', 'responded_at', 'survey_id', 'tenant_id'],
    ],
    'macros' => [
        // usage_count is born zero (FR-16), never supplied.
        'INSERT' => ['created_at', 'updated_at', 'usage_count'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
];

it('R-19b/ADR-0003: the app denied set per SLA/hours/CSAT table and privilege is EXACTLY the amendment-6 filing', function (): void {
    foreach (DOC12_SLA_TABLES as $table) {
        $columns = doc12Columns($table);
        expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table}");
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $expected = DOC12_SLA_DENIED[$table][$privilege];
            $denied = doc12DeniedSet($table, $privilege);
            if ($expected === '*') {
                expect($denied)->toBe($columns, "{$table} {$privilege}: app should hold NO column grant");
            } else {
                expect($denied)->toBe($expected, "{$table} {$privilege} denied set drifted");
            }
        }
    }
});

it('N-01 + the delete split: FORCED RLS with tenant_isolation everywhere; app DELETE exists ONLY on business_calendar_holidays', function (): void {
    foreach (DOC12_SLA_TABLES as $table) {
        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($row->relrowsecurity)->toBeTrue("{$table} has RLS disabled")
            ->and($row->relforcerowsecurity)->toBeTrue("{$table} does not FORCE RLS");
        /** @var object{n: int|string} $policy */
        $policy = DB::selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);
        expect((int) $policy->n)->toBe(1, "{$table} lacks the tenant_isolation policy");
        /** @var object{ok: bool} $del */
        $del = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$table]);
        if ($table === 'business_calendar_holidays') {
            expect($del->ok)->toBeTrue('app lost DELETE on holidays — the amendment grants it (prospective-only content)');
        } else {
            expect($del->ok)->toBeFalse("app can DELETE from {$table}");
        }
    }
});

/** Seed policy -> instance -> stage as the app role for the live controls. */
function slaPhysicsScene(): array
{
    [$tenant, , $ticket] = ticketFixture('open');
    $policyId = (string) Str::uuid7();
    DB::table('sla_policies')->insert([
        'id' => $policyId, 'tenant_id' => $tenant->identifier(),
        'name' => 'Fixture policy', 'position' => 1, 'clock_mode' => 'calendar',
        'targets' => json_encode(['normal' => ['first_response_s' => 600]]),
    ]);
    $instanceId = (string) Str::uuid7();
    DB::table('sla_instances')->insert([
        'id' => $instanceId, 'tenant_id' => $tenant->identifier(),
        'ticket_id' => $ticket->identifier(), 'policy_id' => $policyId,
        'policy_snapshot' => json_encode(['warning_pct' => 80]),
    ]);
    $stageId = (string) Str::uuid7();
    DB::table('sla_instance_stages')->insert([
        'id' => $stageId, 'tenant_id' => $tenant->identifier(),
        'instance_id' => $instanceId, 'stage' => 'first_response',
        'target_us' => 600_000_000, 'clock_start_at' => DB::raw('clock_timestamp()'),
    ]);

    return [$tenant, $instanceId, $stageId];
}

it('BR-03 physics (countersign obligation 1): an UPDATE against a REAL armed instance dies 42501; the stage UPDATE grant is the positive control', function (): void {
    [, , $stageId] = slaPhysicsScene();

    // Positive control: the stage's lawful UPDATE works under the same binding.
    DB::table('sla_instance_stages')->where('id', $stageId)->update(['pauses' => json_encode([['from' => 1, 'until' => 2, 'reason' => 'pending_customer']])]);

    foreach ([
        "UPDATE sla_instances SET policy_snapshot = '{}'::jsonb",
        "UPDATE sla_instances SET calendar_snapshot = '{}'::jsonb",
        'UPDATE sla_instance_stages SET warned_at = clock_timestamp()',
    ] as $sql) {
        try {
            DB::transaction(static fn () => DB::statement($sql));
            $this->fail("app executed: {$sql}");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('permission denied', $sql);
        }
    }
});

it('R-20 control (countersign obligation 4, RIDER 1): warned/breached stamp once from the database clock, never re-stamp, never un-flip', function (): void {
    [, , $stageId] = slaPhysicsScene();

    DB::table('sla_instance_stages')->where('id', $stageId)->update(['warned' => true]);
    $warned = DB::table('sla_instance_stages')->where('id', $stageId)->first();
    expect($warned->warned)->toBeTrue()
        ->and($warned->warned_at)->not->toBeNull('the warned flip did not stamp');

    // Un-flip attempt: the trigger restores the boolean AND the stamp.
    DB::table('sla_instance_stages')->where('id', $stageId)->update(['warned' => false]);
    // Re-flip attempt: the stamp must not move.
    DB::table('sla_instance_stages')->where('id', $stageId)->update(['warned' => true]);
    $after = DB::table('sla_instance_stages')->where('id', $stageId)->first();
    expect($after->warned)->toBeTrue('warned un-flipped — the fill-only law is broken')
        ->and($after->warned_at)->toBe($warned->warned_at, 'warned_at MOVED');

    DB::table('sla_instance_stages')->where('id', $stageId)->update(['breached' => true]);
    $breached = DB::table('sla_instance_stages')->where('id', $stageId)->first();
    expect($breached->breached_at)->not->toBeNull('the breached flip did not stamp');
    DB::table('sla_instance_stages')->where('id', $stageId)->update(['late_fired' => true]);
    $final = DB::table('sla_instance_stages')->where('id', $stageId)->first();
    expect($final->breached_at)->toBe($breached->breached_at, 'breached_at moved on an unrelated write');
});

it('SEC-05 physics (countersign obligation 1): a second response for the same survey dies on the unique — the edit window is an UPDATE, never a second row', function (): void {
    [$tenant, , $ticket] = ticketFixture('resolved');
    $surveyId = (string) Str::uuid7();
    DB::table('surveys')->insert([
        'id' => $surveyId, 'tenant_id' => $tenant->identifier(),
        'ticket_id' => $ticket->identifier(),
        'customer_id' => (string) DB::table('tickets')->where('id', $ticket->identifier())->value('customer_id'),
        'resolution_event_id' => (string) Str::uuid7(), 'resolver_type' => 'human',
        'token_hash' => hash('sha256', Str::random(40)), 'channel' => 'telegram',
        'expires_at' => DB::raw("clock_timestamp() + interval '72 hours'"),
    ]);
    DB::table('survey_responses')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
        'survey_id' => $surveyId, 'score' => 4,
    ]);

    try {
        DB::transaction(static fn () => DB::table('survey_responses')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
            'survey_id' => $surveyId, 'score' => 1,
        ]));
        $this->fail('a second response row landed — SEC-05 single-response physics is gone');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('survey_responses_single');
    }

    // The lawful edit path: an UPDATE on the one row.
    DB::table('survey_responses')->where('survey_id', $surveyId)->update(['score' => 5, 'edited_at' => DB::raw('clock_timestamp()')]);
    expect((int) DB::table('survey_responses')->where('survey_id', $surveyId)->value('score'))->toBe(5);
});
