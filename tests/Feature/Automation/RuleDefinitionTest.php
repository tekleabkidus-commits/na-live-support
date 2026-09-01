<?php

declare(strict_types=1);

use App\Domain\Automation\Rules\KeywordSets;
use App\Domain\Automation\Rules\RuleWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-01 / FR-07 / FR-02's keyword sets — definitions, revisions, doors
|--------------------------------------------------------------------------
|
| FR-07's split as behavior: create → a DRAFT revision, inactive; activate →
| the atomic pointer swap with the SEC-01 capture; an edit to an ACTIVE rule
| drafts a NEW revision and the active one is BYTE-UNTOUCHED (its history is
| the diff's source). FR-01's validation walls: unknown trigger, unknown
| action type (the CLOSED registry), a schedule under the 5-minute floor.
*/

it('FR-07: create drafts, activate swaps atomically, an edit to an active rule never touches the active revision', function (): void {
    $t = createTenant();
    bindTenant($t);
    $author = grantAllPermissions(ticketActor([]));
    $writer = app(RuleWriter::class);

    $made = $writer->create(['name' => 'VIP fast lane', 'trigger' => 'ticket.created', 'conditions' => ['all' => [['fact' => 'customer.tags', 'op' => 'contains', 'value' => 'vip']]], 'actions' => [['type' => 'set_priority', 'priority' => 'high']]], $author);
    /** @var object{active: bool, active_revision_id: ?string} $rule */
    $rule = DB::table('automation_rules')->where('id', $made['rule_id'])->first();
    expect($rule->active)->toBeFalse('a created rule may never be born active (FR-07)')
        ->and($rule->active_revision_id)->toBeNull()
        ->and(DB::table('automation_rule_revisions')->where('id', $made['revision_id'])->value('activated_at'))->toBeNull();

    $writer->activate($made['rule_id'], $made['revision_id'], $author);
    /** @var object{active: bool, active_revision_id: ?string} $rule */
    $rule = DB::table('automation_rules')->where('id', $made['rule_id'])->first();
    expect($rule->active)->toBeTrue()
        ->and($rule->active_revision_id)->toBe($made['revision_id'])
        ->and(DB::table('automation_rule_revisions')->where('id', $made['revision_id'])->value('activated_at'))->not->toBeNull();

    $activeDefinition = (string) DB::table('automation_rule_revisions')->where('id', $made['revision_id'])->value('definition');
    $draftId = $writer->edit($made['rule_id'], ['actions' => [['type' => 'set_priority', 'priority' => 'urgent']]], $author);
    expect($draftId)->not->toBe($made['revision_id'])
        ->and((string) DB::table('automation_rule_revisions')->where('id', $made['revision_id'])->value('definition'))->toBe($activeDefinition, 'the ACTIVE revision mutated on edit (FR-07)')
        ->and((string) DB::table('automation_rules')->where('id', $made['rule_id'])->value('active_revision_id'))->toBe($made['revision_id'], 'the pointer moved without an activation');
    expect((int) DB::table('automation_rule_revisions')->where('rule_id', $made['rule_id'])->count())->toBe(2);

    $writer->deactivate($made['rule_id'], $author);
    expect((bool) DB::table('automation_rules')->where('id', $made['rule_id'])->value('active'))->toBeFalse();

    // NFR-06: every change evented + the SEC-06 pair audited.
    expect((int) DB::table('outbox')->where('name', 'automation.rule.changed')->count())->toBeGreaterThanOrEqual(4)
        ->and((int) DB::table('audit_log')->where('action_key', 'automation.rule.activated')->count())->toBe(1)
        ->and((int) DB::table('audit_log')->where('action_key', 'automation.rule.deactivated')->count())->toBe(1);
});

it('FR-01/FR-03 walls: unknown trigger, unknown action type and a sub-5-minute schedule are refused; a lawful schedule lands', function (): void {
    $t = createTenant();
    bindTenant($t);
    $author = grantAllPermissions(ticketActor([]));
    $writer = app(RuleWriter::class);

    expect(fn () => $writer->create(['name' => 'X', 'trigger' => 'ticket.sneezed'], $author))
        ->toThrow(InvalidArgumentException::class, 'unknown trigger');
    expect(fn () => $writer->create(['name' => 'X', 'trigger' => 'ticket.created', 'actions' => [['type' => 'force_status']]], $author))
        ->toThrow(InvalidArgumentException::class, 'the registry is closed');
    expect(fn () => $writer->create(['name' => 'X', 'trigger' => 'schedule', 'schedule_cron' => '*/2 * * * *'], $author))
        ->toThrow(InvalidArgumentException::class, '5-minute floor');
    expect(fn () => $writer->create(['name' => 'X', 'trigger' => 'schedule'], $author))
        ->toThrow(InvalidArgumentException::class, 'schedule_cron');

    $made = $writer->create(['name' => 'Nightly sweep', 'trigger' => 'schedule', 'schedule_cron' => '0 2 * * *'], $author);
    expect(DB::table('automation_rules')->where('id', $made['rule_id'])->value('schedule_cron'))->toBe('0 2 * * *');
});

it('FR-02: keyword sets are managed lists — created, updated, retired by flag; empties refused; the audit carries the id never the list (SEC-03)', function (): void {
    $t = createTenant();
    bindTenant($t);
    $sets = app(KeywordSets::class);
    $actor = (string) Str::uuid7();

    $id = $sets->create('Deposit words', ['ገንዘብ', 'deposit', 'telebirr'], $actor);
    expect(json_decode((string) DB::table('automation_keyword_sets')->where('id', $id)->value('keywords'), true))->toBe(['ገንዘብ', 'deposit', 'telebirr']);

    $sets->update($id, ['keywords' => ['deposit'], 'active' => false], $actor);
    expect((bool) DB::table('automation_keyword_sets')->where('id', $id)->value('active'))->toBeFalse();

    expect(fn () => $sets->create('Empty', [], $actor))->toThrow(InvalidArgumentException::class);
    expect(fn () => $sets->update($id, ['keywords' => ['ok', ' ']], $actor))->toThrow(InvalidArgumentException::class);

    /** @var object{payload: string} $audit */
    $audit = DB::table('audit_log')->where('action_key', 'automation.keyword_set.changed')->orderByDesc('id')->first();
    expect($audit->payload)->not->toContain('deposit', 'the keyword list leaked into the audit (SEC-03)');
});
