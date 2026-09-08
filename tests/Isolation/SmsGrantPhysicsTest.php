<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCHEMA AMENDMENT 8 grant physics (S14 step 2b; countersigned at 2a with three conditions
 * folded) — the WidgetGrantPhysicsTest shape for the seven SMS tables. The denied sets are
 * the AMENDMENT'S OWN FILED SPLITS per privilege, asserted EXACTLY (a widened grant is as
 * red as a narrowed one). Executing controls: the vault-ref CHECK (23514 on a planted
 * token — condition 3), the config no-secret-key CHECK, a version UPDATE (42501, immutable
 * by grant), a message phone/is_test UPDATE (42501 — the honest-state physics), an opt-out
 * DELETE and phone UPDATE (42501 — R-05 append-only), the staff-reason CHECK, the
 * announcement-only trigger (BR-02 as physics — condition 1), the route-prefix CHECK, and
 * the DB-time stamps (sent_at on state → sent; circuit_opened_at on open; launched_at +
 * monthly_bucket on launch) that the app cannot write.
 */
const DOC14_TABLES = ['sms_templates', 'sms_template_versions', 'sms_gateways', 'sms_routes', 'sms_messages', 'sms_optouts', 'sms_broadcasts'];

/** The filed denied set per table AND PER PRIVILEGE (sorted). '*' = every column. */
const DOC14_DENIED = [
    'sms_templates' => [
        'INSERT' => ['created_at', 'deleted_at'],
        'UPDATE' => ['created_at', 'event', 'id', 'language', 'tenant_id'],
    ],
    'sms_template_versions' => [
        'INSERT' => ['created_at', 'published_at'],
        'UPDATE' => '*',
    ],
    'sms_gateways' => [
        'INSERT' => ['circuit_opened_at', 'created_at', 'deleted_at'],
        'UPDATE' => ['circuit_opened_at', 'created_at', 'driver', 'id', 'is_fixture', 'tenant_id'],
    ],
    'sms_routes' => [
        'INSERT' => ['created_at'],
        'UPDATE' => ['created_at', 'id', 'tenant_id'],
    ],
    'sms_messages' => [
        'INSERT' => ['attempts', 'cost_amount', 'cost_corrected_amount', 'cost_correction_note', 'cost_currency', 'created_at', 'delivered_at', 'fail_reason', 'gateway_id', 'provider_id', 'queued_at', 'raw_dlrs', 'sent_at', 'terminal_at'],
        'UPDATE' => ['broadcast_id', 'conversation_id', 'created_at', 'customer_id', 'delivered_at', 'event', 'id', 'is_test', 'lane', 'language', 'phone', 'queued_at', 'sent_at', 'tenant_id', 'terminal_at', 'ticket_id'],
    ],
    'sms_optouts' => [
        'INSERT' => ['created_at', 'restore_reason', 'restored_at', 'restored_by'],
        'UPDATE' => ['added_by', 'created_at', 'id', 'phone', 'reason', 'source', 'stop_word_matched', 'tenant_id'],
    ],
    'sms_broadcasts' => [
        'INSERT' => ['canceled_at', 'completed_at', 'created_at', 'launched_at', 'monthly_bucket'],
        'UPDATE' => ['canceled_at', 'completed_at', 'created_at', 'id', 'launched_at', 'monthly_bucket', 'template_id', 'tenant_id'],
    ],
];

function smsSqlState(QueryException $e): string
{
    return (string) ($e->errorInfo[0] ?? '');
}

/**
 * Run one refusal control under its OWN savepoint (a nested DB::transaction): the test body
 * runs inside the harness's rolled-back transaction, and a refused statement would otherwise
 * abort it — every later statement answering 25P02 instead of the control's own SQLSTATE.
 */
function smsRefusal(callable $attempt): ?string
{
    try {
        DB::transaction($attempt);
    } catch (QueryException $e) {
        return smsSqlState($e);
    }

    return null;
}

/** @return array{template: string, version: string, gateway: string} a lawful scene inside the bound tenant */
function smsPhysicsScene(string $tenantId): array
{
    $templateId = (string) Str::uuid7();
    $versionId = (string) Str::uuid7();
    DB::table('sms_templates')->insert(['id' => $templateId, 'tenant_id' => $tenantId, 'event' => 'ticket_resolved', 'language' => 'en', 'name' => 'Resolved']);
    DB::table('sms_template_versions')->insert(['id' => $versionId, 'tenant_id' => $tenantId, 'template_id' => $templateId, 'version' => 1, 'body' => 'Ticket {{ticket.number}} is resolved.', 'encoding' => 'gsm7', 'segments_estimate' => 1, 'published_by' => (string) Str::uuid7()]);
    DB::table('sms_templates')->where('id', $templateId)->update(['live_version_id' => $versionId]);
    $gatewayId = (string) Str::uuid7();
    DB::table('sms_gateways')->insert(['id' => $gatewayId, 'tenant_id' => $tenantId, 'name' => 'Fixture gateway', 'driver' => 'http_generic', 'config' => json_encode(['base_url' => 'http://mock-sms:8082/reference'], JSON_THROW_ON_ERROR), 'credential_ref' => 'vault://sms/'.$gatewayId.'/token/v1', 'is_fixture' => true]);

    return ['template' => $templateId, 'version' => $versionId, 'gateway' => $gatewayId];
}

it('R-19b/ADR-0003: the app denied set per SMS table and privilege is EXACTLY the amendment-8 filing', function (): void {
    foreach (DOC14_TABLES as $table) {
        $columns = doc12Columns($table);
        expect($columns)->not->toBeEmpty("information_schema knows no columns for {$table}");
        foreach (['INSERT', 'UPDATE'] as $privilege) {
            $expected = DOC14_DENIED[$table][$privilege];
            $denied = doc12DeniedSet($table, $privilege);
            if ($expected === '*') {
                expect($denied)->toBe($columns, "{$table} {$privilege}: app should hold NO column grant");
            } else {
                expect($denied)->toBe($expected, "{$table} {$privilege} denied set drifted");
            }
        }
    }
});

it('N-01 / N-05: every SMS table is FORCED RLS with tenant_isolation and app holds no DELETE on any', function (): void {
    foreach (DOC14_TABLES as $table) {
        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($row->relrowsecurity)->toBeTrue("{$table} has RLS disabled")
            ->and($row->relforcerowsecurity)->toBeTrue("{$table} does not FORCE RLS");
        /** @var object{n: int|string} $policy */
        $policy = DB::selectOne("SELECT count(*) AS n FROM pg_policies WHERE tablename = ? AND policyname = 'tenant_isolation'", [$table]);
        expect((int) $policy->n)->toBe(1, "{$table} lacks the tenant_isolation policy");
        /** @var object{ok: bool} $delete */
        $delete = DB::selectOne("SELECT has_table_privilege('app', ?, 'DELETE') AS ok", [$table]);
        expect($delete->ok)->toBeFalse("app can DELETE from {$table}");
    }
});

it('condition 3 (SEC-01 / C-13): a plaintext or token-shaped credential dies 23514 on the vault-ref CHECK; a secret key inside config dies 23514; a vault://sms/ ref is the positive control', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();

    foreach (['fixture-geezsms-token', 'vault://widget/x/v1', 'eyJpdiI6QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5'] as $planted) {
        $caught = smsRefusal(fn () => DB::table('sms_gateways')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'name' => 'x', 'driver' => 'http_generic', 'credential_ref' => $planted]));
        expect($caught)->toBe('23514', "a planted credential [{$planted}] must die on the CHECK");
    }

    $caught = smsRefusal(fn () => DB::table('sms_gateways')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'name' => 'x', 'driver' => 'http_generic', 'config' => json_encode(['token' => 'fixture-geezsms-token'], JSON_THROW_ON_ERROR)]));
    expect($caught)->toBe('23514', 'a token key inside config must die on the CHECK');

    $scene = smsPhysicsScene($tenantId);
    expect(DB::table('sms_gateways')->where('id', $scene['gateway'])->value('credential_ref'))->toStartWith('vault://sms/');
});

it('immutable by grant: a version body UPDATE, a gateway driver / is_fixture UPDATE, a message phone / is_test UPDATE, an opt-out phone UPDATE and DELETE — each dies 42501', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $scene = smsPhysicsScene($tenantId);
    $messageId = (string) Str::uuid7();
    DB::table('sms_messages')->insert(['id' => $messageId, 'tenant_id' => $tenantId, 'event' => 'ticket_resolved', 'template_id' => $scene['template'], 'template_version_id' => $scene['version'], 'language' => 'en', 'phone' => '+251900001234', 'lane' => 'sms-transactional']);
    $optoutId = (string) Str::uuid7();
    DB::table('sms_optouts')->insert(['id' => $optoutId, 'tenant_id' => $tenantId, 'phone' => '+251900001234', 'source' => 'inbound_stop', 'stop_word_matched' => 'STOP']);

    $attempts = [
        'version body' => fn () => DB::table('sms_template_versions')->where('id', $scene['version'])->update(['body' => 'changed']),
        'gateway driver' => fn () => DB::table('sms_gateways')->where('id', $scene['gateway'])->update(['driver' => 'smpp_sidecar']),
        'gateway is_fixture' => fn () => DB::table('sms_gateways')->where('id', $scene['gateway'])->update(['is_fixture' => false]),
        'gateway circuit_opened_at' => fn () => DB::table('sms_gateways')->where('id', $scene['gateway'])->update(['circuit_opened_at' => '2026-01-01 00:00:00+00']),
        'message phone' => fn () => DB::table('sms_messages')->where('id', $messageId)->update(['phone' => '+251900009999']),
        'message is_test' => fn () => DB::table('sms_messages')->where('id', $messageId)->update(['is_test' => true]),
        'message sent_at' => fn () => DB::table('sms_messages')->where('id', $messageId)->update(['sent_at' => '2026-01-01 00:00:00+00']),
        'optout phone' => fn () => DB::table('sms_optouts')->where('id', $optoutId)->update(['phone' => '+251900009999']),
        'optout delete' => fn () => DB::table('sms_optouts')->where('id', $optoutId)->delete(),
        'template event' => fn () => DB::table('sms_templates')->where('id', $scene['template'])->update(['event' => 'ticket_created']),
    ];
    foreach ($attempts as $label => $attempt) {
        $caught = smsRefusal(fn () => $attempt());
        expect($caught)->toBe('42501', "{$label}: must die 42501");
    }
});

it('the CHECKs bite: a staff opt-out without a reason, a restoration without its reason, a bad route prefix, an unknown state/event/lane — 23514; BR-02 as physics: a broadcast on a non-announcement template is refused by the trigger', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $scene = smsPhysicsScene($tenantId);

    $bad = [
        'staff opt-out without reason' => fn () => DB::table('sms_optouts')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'phone' => '+251900001111', 'source' => 'staff']),
        'route prefix without plus' => fn () => DB::table('sms_routes')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'country_prefix' => '251', 'position' => 1, 'primary_gateway_id' => $scene['gateway']]),
        'unknown message state' => fn () => DB::table('sms_messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'event' => 'ticket_resolved', 'language' => 'en', 'phone' => '+251900001234', 'lane' => 'sms-transactional', 'state' => 'guessed_delivered']),
        'unknown template event' => fn () => DB::table('sms_templates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'event' => 'marketing_blast', 'language' => 'en', 'name' => 'x']),
        'bare sms lane' => fn () => DB::table('sms_messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'event' => 'ticket_resolved', 'language' => 'en', 'phone' => '+251900001234', 'lane' => 'sms']),
        'broadcast on ticket_resolved template (BR-02)' => fn () => DB::table('sms_broadcasts')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'name' => 'x', 'template_id' => $scene['template'], 'template_version_id' => $scene['version']]),
    ];
    foreach ($bad as $label => $attempt) {
        $caught = smsRefusal(fn () => $attempt());
        expect($caught)->toBe('23514', "{$label}: must die 23514");
    }

    // the restoration triple must move together
    $optoutId = (string) Str::uuid7();
    DB::table('sms_optouts')->insert(['id' => $optoutId, 'tenant_id' => $tenantId, 'phone' => '+251900002222', 'source' => 'staff', 'reason' => 'asked in person']);
    $caught = smsRefusal(fn () => DB::table('sms_optouts')->where('id', $optoutId)->update(['restored_at' => DB::raw('clock_timestamp()')]));
    expect($caught)->toBe('23514');

    // the positive control: an announcement template CAN be broadcast
    $annId = (string) Str::uuid7();
    $annVersion = (string) Str::uuid7();
    DB::table('sms_templates')->insert(['id' => $annId, 'tenant_id' => $tenantId, 'event' => 'service_announcement', 'language' => 'en', 'name' => 'Announce']);
    DB::table('sms_template_versions')->insert(['id' => $annVersion, 'tenant_id' => $tenantId, 'template_id' => $annId, 'version' => 1, 'body' => 'Maintenance tonight.', 'encoding' => 'gsm7', 'segments_estimate' => 1, 'published_by' => (string) Str::uuid7()]);
    DB::table('sms_broadcasts')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'name' => 'ok', 'template_id' => $annId, 'template_version_id' => $annVersion]);
    expect(DB::table('sms_broadcasts')->where('tenant_id', $tenantId)->count())->toBe(1);
});

it('the clock facts are the database\'s: sent_at / delivered_at / terminal_at stamp on the state change, circuit_opened_at on open (cleared on close), launched_at + the tenant-month bucket on launch — none written by the app', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $scene = smsPhysicsScene($tenantId);

    $messageId = (string) Str::uuid7();
    DB::table('sms_messages')->insert(['id' => $messageId, 'tenant_id' => $tenantId, 'event' => 'ticket_resolved', 'template_id' => $scene['template'], 'template_version_id' => $scene['version'], 'language' => 'en', 'phone' => '+251900001234', 'lane' => 'sms-transactional']);
    $row = DB::table('sms_messages')->where('id', $messageId)->first(['queued_at', 'sent_at', 'terminal_at']);
    expect($row->queued_at)->not->toBeNull()->and($row->sent_at)->toBeNull()->and($row->terminal_at)->toBeNull();

    DB::table('sms_messages')->where('id', $messageId)->update(['state' => 'sending']);
    DB::table('sms_messages')->where('id', $messageId)->update(['state' => 'sent']);
    $sent = DB::table('sms_messages')->where('id', $messageId)->first(['sent_at', 'terminal_at']);
    expect($sent->sent_at)->not->toBeNull()->and($sent->terminal_at)->toBeNull();

    DB::table('sms_messages')->where('id', $messageId)->update(['state' => 'delivered']);
    $delivered = DB::table('sms_messages')->where('id', $messageId)->first(['sent_at', 'delivered_at', 'terminal_at']);
    expect($delivered->delivered_at)->not->toBeNull()->and($delivered->terminal_at)->not->toBeNull()
        ->and($delivered->sent_at)->toBe($sent->sent_at, 'sent_at must not move once stamped');

    // a row skipped at enqueue is terminal at birth
    $skippedId = (string) Str::uuid7();
    DB::table('sms_messages')->insert(['id' => $skippedId, 'tenant_id' => $tenantId, 'event' => 'ticket_resolved', 'language' => 'en', 'phone' => '+251900001234', 'lane' => 'sms-transactional', 'state' => 'skipped', 'skip_reason' => 'opted_out']);
    expect(DB::table('sms_messages')->where('id', $skippedId)->value('terminal_at'))->not->toBeNull();

    // the circuit stamp
    DB::table('sms_gateways')->where('id', $scene['gateway'])->update(['circuit_state' => 'open', 'health' => 'down']);
    expect(DB::table('sms_gateways')->where('id', $scene['gateway'])->value('circuit_opened_at'))->not->toBeNull();
    DB::table('sms_gateways')->where('id', $scene['gateway'])->update(['circuit_state' => 'half_open']);
    expect(DB::table('sms_gateways')->where('id', $scene['gateway'])->value('circuit_opened_at'))->not->toBeNull('half_open keeps the trip instant');
    DB::table('sms_gateways')->where('id', $scene['gateway'])->update(['circuit_state' => 'closed', 'health' => 'healthy']);
    expect(DB::table('sms_gateways')->where('id', $scene['gateway'])->value('circuit_opened_at'))->toBeNull();

    // the launch stamp + the tenant-month bucket
    $annId = (string) Str::uuid7();
    $annVersion = (string) Str::uuid7();
    DB::table('sms_templates')->insert(['id' => $annId, 'tenant_id' => $tenantId, 'event' => 'service_announcement', 'language' => 'en', 'name' => 'Announce']);
    DB::table('sms_template_versions')->insert(['id' => $annVersion, 'tenant_id' => $tenantId, 'template_id' => $annId, 'version' => 1, 'body' => 'x', 'encoding' => 'gsm7', 'segments_estimate' => 1, 'published_by' => (string) Str::uuid7()]);
    $broadcastId = (string) Str::uuid7();
    DB::table('sms_broadcasts')->insert(['id' => $broadcastId, 'tenant_id' => $tenantId, 'name' => 'b', 'template_id' => $annId, 'template_version_id' => $annVersion]);
    $caught = smsRefusal(fn () => DB::table('sms_broadcasts')->where('id', $broadcastId)->update(['launched_at' => '2026-01-01 00:00:00+00']));
    expect($caught)->toBe('42501');
    DB::table('sms_broadcasts')->where('id', $broadcastId)->update(['state' => 'sending']);
    /** @var object{launched_at: string|null, monthly_bucket: string|null} $launched */
    $launched = DB::table('sms_broadcasts')->where('id', $broadcastId)->first(['launched_at', 'monthly_bucket']);
    /** @var object{m: string} $expected */
    $expected = DB::selectOne("SELECT to_char(clock_timestamp() AT TIME ZONE ?, 'YYYY-MM') AS m", [$tenant->timezone_name]);
    expect($launched->launched_at)->not->toBeNull()->and($launched->monthly_bucket)->toBe($expected->m);
});
