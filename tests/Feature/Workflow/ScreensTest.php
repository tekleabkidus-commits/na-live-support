<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 §8/§9 (S12 step 14) — Backoffice → Workflow: the seven pages render
| under their catalog keys with server-localized copy (every string a key,
| en + am), every mutation door carries the operator's question to the
| step-1…13 writer and hands refusals back as RFC 9457 (C-04), and the
| pages are UNREACHABLE without the key (404-shaped denials are Doc 03's).
|--------------------------------------------------------------------------
*/

/**
 * A bound + ACTIVE tenant (the staff chain's tenant.state gate), a ticket, and the actor.
 * After every HTTP round-trip the request middleware CLEARS the tenant context (Doc 01
 * EC-01's clear-on-release), so a test re-binds before any direct DB read — `$db()`.
 *
 * @return array{0: User, 1: object, 2: object, 3: Closure}
 */
function workflowScene(array $keys = []): array
{
    [$tenant, , $ticket] = ticketFixture('open');
    activateTenant($tenant);
    $user = $keys === [] ? grantAllPermissions(ticketActor([], 'Workflow Admin')) : ticketActor($keys, 'Workflow Scoped '.implode('+', $keys));
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);
    $db = function () use ($tenant): void {
        app(App\Support\Tenancy\TenantContext::class)->clear();
        bindTenant($tenant);
    };

    return [$user, $tenant, $ticket, $db];
}

function workflowAdmin(array $keys = []): User
{
    return workflowScene($keys)[0];
}

it('renders all seven surfaces + the hub for a full-map admin, each with the workflow.ui bundle and its capability map', function (): void {
    $admin = workflowAdmin();
    foreach ([
        '/admin/workflow' => 'Workflow/Hub', '/admin/workflow/rules' => 'Workflow/Rules', '/admin/workflow/sla' => 'Workflow/Sla',
        '/admin/workflow/hours' => 'Workflow/Hours', '/admin/workflow/macros' => 'Workflow/Macros', '/admin/workflow/fields' => 'Workflow/Fields',
        '/admin/workflow/forms' => 'Workflow/Forms', '/admin/workflow/csat' => 'Workflow/Csat',
    ] as $url => $component) {
        $response = $this->actingAs($admin, 'staff')->get('https://localhost'.$url);
        $response->assertOk();
        $page = $response->viewData('page');
        expect($page['component'])->toBe($component, $url)
            ->and($page['props']['copy']['nav']['workflow'])->toBe('Workflow')
            ->and($page['props']['can'])->toBeArray();
    }
});

it('every page is gated by its catalog key — a viewer without it is refused (no invented key, the CLOSED catalog)', function (): void {
    $stranger = workflowAdmin(['tickets.view']);
    foreach (['/admin/workflow', '/admin/workflow/rules', '/admin/workflow/sla', '/admin/workflow/hours', '/admin/workflow/macros', '/admin/workflow/fields', '/admin/workflow/forms', '/admin/workflow/csat'] as $url) {
        $status = $this->actingAs($stranger, 'staff')->get('https://localhost'.$url)->status();
        expect(in_array($status, [403, 404], true))->toBeTrue("{$url} answered {$status} to a viewer without the key");
    }
});

it('rules: create → edit (draft revision) → dry-run against a recent event (truth badges) → activate → reorder → deactivate, all through RuleWriter/DryRunner; a bad card refuses 422 with the writer\'s own words', function (): void {
    [$admin, $tenant, $ticket, $db] = workflowScene();
    $as = fn () => $this->actingAs($admin, 'staff');
    $created = $as()->postJson('https://localhost/admin/workflow/rules', ['name' => 'Screen rule', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'add_tags', 'tags' => ['screen']]]]);
    $created->assertOk();
    $ruleId = $created->json('rule_id');
    $editor = $as()->get('https://localhost/admin/workflow/rules/'.$ruleId);
    $editor->assertOk();
    expect($editor->viewData('page')['props']['rule']['name'])->toBe('Screen rule');

    $refused = $as()->postJson('https://localhost/admin/workflow/rules/'.$ruleId, ['name' => 'Screen rule', 'trigger' => 'ticket.created', 'actions' => [['type' => 'send_sms_template', 'template_ref' => 't', 'body' => 'free text']]]);
    $refused->assertStatus(422);
    expect($refused->json('detail'))->toContain('no free-text SMS');

    $edited = $as()->postJson('https://localhost/admin/workflow/rules/'.$ruleId, ['name' => 'Screen rule v2', 'trigger' => 'ticket.created', 'conditions' => ['all' => [['fact' => 'ticket.status', 'op' => 'equals', 'value' => 'open']]], 'actions' => [['type' => 'add_note', 'body' => 'from the screen']]]);
    $edited->assertOk();
    $revisionId = $edited->json('revision_id');

    $db();
    $eventId = publishTicketCreated($tenant, $ticket);
    $row = DB::table('outbox')->where('event_id', $eventId)->first();
    DB::table('events')->insertOrIgnore(['id' => $eventId, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload]);
    $dry = $as()->postJson('https://localhost/admin/workflow/rules/dry-run', ['event_id' => $eventId, 'definition' => ['conditions' => ['all' => [['fact' => 'ticket.status', 'op' => 'equals', 'value' => 'open']]], 'actions' => [['type' => 'add_note', 'body' => 'x']]]]);
    $dry->assertOk();
    expect($dry->json('matched'))->toBeTrue()->and($dry->json('trace.0.matched'))->toBeTrue()->and($dry->json('trace.0.actual'))->toBe('open')->and($dry->json('actions.0.would_run'))->toBeTrue();

    $as()->postJson('https://localhost/admin/workflow/rules/'.$ruleId.'/activate', ['revision_id' => $revisionId])->assertOk();
    $db();
    expect((bool) DB::table('automation_rules')->where('id', $ruleId)->value('active'))->toBeTrue();
    $second = $as()->postJson('https://localhost/admin/workflow/rules', ['name' => 'Second', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => []])->json('rule_id');
    $as()->postJson('https://localhost/admin/workflow/rules/'.$second.'/reorder', ['position' => 1])->assertOk();
    $db();
    expect((int) DB::table('automation_rules')->where('id', $second)->value('position'))->toBe(1)
        ->and((int) DB::table('automation_rules')->where('id', $ruleId)->value('position'))->toBe(2);
    $as()->postJson('https://localhost/admin/workflow/rules/'.$ruleId.'/deactivate', [])->assertOk();
    $db();
    expect((bool) DB::table('automation_rules')->where('id', $ruleId)->value('active'))->toBeFalse();
    expect((int) DB::table('audit_log')->whereIn('action_key', ['automation.rule.created', 'automation.rule.updated', 'automation.rule.activated', 'automation.rule.deactivated'])->count())->toBeGreaterThanOrEqual(5);
});

it('webhook destinations + keyword sets from the rules surface: an internal target refuses with the ssrf_refused code in errors; a set is created and retired', function (): void {
    [$admin, , , $db] = workflowScene();
    $as = fn () => $this->actingAs($admin, 'staff');
    $bad = $as()->postJson('https://localhost/admin/workflow/webhook-destinations', ['name' => 'x', 'url' => 'https://10.0.0.1/hook']);
    $bad->assertStatus(422);
    expect($bad->json('errors.url.0'))->toBe('ssrf_refused');
    $good = $as()->postJson('https://localhost/admin/workflow/webhook-destinations', ['name' => 'CRM', 'url' => 'https://hooks.example.test/x']);
    $good->assertOk();
    $as()->postJson('https://localhost/admin/workflow/webhook-destinations/'.$good->json('id'), ['active' => false])->assertOk();
    $set = $as()->postJson('https://localhost/admin/workflow/keyword-sets', ['name' => 'Payout words', 'keywords' => "payout\nwithdrawal"]);
    $set->assertOk();
    $as()->postJson('https://localhost/admin/workflow/keyword-sets/'.$set->json('id'), ['active' => false])->assertOk();
    $db();
    expect((bool) DB::table('automation_keyword_sets')->where('id', $set->json('id'))->value('active'))->toBeFalse();
});

it('SLA + hours: a policy is created through PolicyWriter, the live example sentence answers from the clock library in the tenant zone, a calendar with holidays lands through CalendarWriter', function (): void {
    [$admin, , , $db] = workflowScene();
    $as = fn () => $this->actingAs($admin, 'staff');
    $calendar = $as()->postJson('https://localhost/admin/workflow/hours/calendars', ['name' => 'Addis office', 'timezone' => 'Africa/Addis_Ababa', 'weekdays' => ['1' => [['open' => '08:00', 'close' => '17:00']], '2' => [['open' => '08:00', 'close' => '17:00']], '3' => [['open' => '08:00', 'close' => '17:00']]], 'is_tenant_default' => true]);
    $calendar->assertOk();
    $calendarId = $calendar->json('id');
    $as()->postJson('https://localhost/admin/workflow/hours/calendars/'.$calendarId.'/holidays', ['date' => '2026-09-11', 'name' => 'Enkutatash', 'recurring_yearly' => true])->assertOk();
    $db();
    expect((int) DB::table('business_calendar_holidays')->where('calendar_id', $calendarId)->count())->toBe(1);

    $example = $as()->postJson('https://localhost/admin/workflow/sla/example', ['calendar_id' => $calendarId, 'target_seconds' => 2400, 'created_at' => '2026-09-01T17:00:00+03:00']);
    $example->assertOk();
    expect($example->json('sentence'))->toContain('08:40');

    $policy = $as()->postJson('https://localhost/admin/workflow/sla/policies', ['name' => 'Standard', 'match_conditions' => [], 'targets' => ['normal' => ['first_response_s' => 2400, 'resolution_s' => 86400]], 'clock_mode' => 'calendar', 'calendar_id' => $calendarId]);
    $policy->assertOk();
    $page = $as()->get('https://localhost/admin/workflow/sla')->viewData('page');
    expect(collect($page['props']['policies'])->pluck('name')->all())->toContain('Standard');
});

it('macros + fields + forms + CSAT: each surface\'s doors land through their writers; preview answers T-06 parity; a form publishes v1; the CSAT settings write through the registry', function (): void {
    [$admin, , $ticket] = workflowScene();
    $as = fn () => $this->actingAs($admin, 'staff');
    $macro = $as()->postJson('https://localhost/admin/workflow/macros', ['name' => 'Payout ETA', 'scope' => 'personal', 'body' => 'Hi {{customer.name}}, ticket {{ticket.number}}']);
    $macro->assertOk();
    $ticketId = $ticket->identifier();
    $preview = $as()->postJson('https://localhost/admin/workflow/macros/'.$macro->json('id').'/preview', ['ticket_id' => $ticketId]);
    $preview->assertOk();
    expect($preview->json('text'))->toContain('#');

    $field = $as()->postJson('https://localhost/admin/workflow/fields', ['entity' => 'ticket', 'key' => 'payout_method', 'label' => ['en' => 'Payout method', 'am' => 'የክፍያ ዘዴ'], 'type' => 'dropdown', 'options' => [['value' => 'telebirr', 'label' => ['en' => 'Telebirr', 'am' => 'ቴሌብር']]]]);
    $field->assertOk();
    $used = $as()->getJson('https://localhost/admin/workflow/fields/ticket/payout_method/where-used');
    $used->assertOk();
    expect($used->json('forms'))->toBe([]);

    $form = $as()->postJson('https://localhost/admin/workflow/forms', ['name' => 'Withdrawal issue']);
    $form->assertOk();
    $schema = minimalFormSchema();
    $blocks = $as()->postJson('https://localhost/admin/workflow/forms/'.$form->json('id').'/preview', ['schema' => $schema]);
    $blocks->assertOk();
    expect($blocks->json('blocks'))->toBe([]);
    $published = $as()->postJson('https://localhost/admin/workflow/forms/'.$form->json('id').'/publish', ['schema' => $schema]);
    $published->assertOk();
    expect($published->json('version'))->toBe(1);
    $builder = $as()->get('https://localhost/admin/workflow/forms/'.$form->json('id'));
    $builder->assertOk();
    expect($builder->viewData('page')['props']['versions'][0]['version'])->toBe(1);

    $as()->postJson('https://localhost/admin/workflow/csat', ['cooldown_hours' => 48, 'comment_prompt_enabled' => false])->assertOk();
    $csat = $as()->get('https://localhost/admin/workflow/csat')->viewData('page');
    expect($csat['props']['settings']['cooldown_hours'])->toBe(48)->and($csat['props']['settings']['comment_prompt_enabled'])->toBeFalse();
});

it('§14: every workflow.ui string resolves in en AND am (the bundle ships both; machine am → R-14)', function (): void {
    $flatten = function (array $tree, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($tree as $key => $value) {
            $keys = array_merge($keys, is_array($value) ? $flatten($value, $prefix.$key.'.') : [$prefix.$key]);
        }

        return $keys;
    };
    /** @var array<string, mixed> $en */
    $en = __('workflow.ui', [], 'en');
    foreach ($flatten($en) as $key) {
        expect(__('workflow.ui.'.$key, [], 'am'))->not->toBe('workflow.ui.'.$key, "workflow.ui.{$key} missing in am")
            ->and(__('workflow.ui.'.$key, [], 'en'))->toBeString();
    }
});
