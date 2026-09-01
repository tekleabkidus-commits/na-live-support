<?php

declare(strict_types=1);

use App\Domain\Fields\DefinitionWriter;
use App\Domain\Fields\ValueWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-18 / EC-08 / EC-14 / AC-06's lifecycle half — retire, reactivate,
| never-reuse, option snapshots, where-used completeness
|--------------------------------------------------------------------------
|
| The physics were proven at step 2b (key/type/entity 42501; the unscoped
| unique); this file proves the BEHAVIOR: a retired field refuses writes and
| keeps its stored values (Doc 08 EC-09's rule, OWNED here — closing row 7);
| reactivation restores; a retired key can never re-mint; option removal
| snapshots its label while the stored value stands; EC-14's where-used answer
| is COMPLETE (forms + rules + views) and travels in the deactivation audit;
| the flagged rule keeps evaluating as is-empty.
*/

it('FR-18: deactivate → writes refuse + values stand; reactivate restores; the key never re-mints (closing row 7\'s ownership)', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    $definitions = app(DefinitionWriter::class);
    $values = app(ValueWriter::class);
    $actor = (string) Str::uuid7();
    $fieldId = $definitions->create(['entity' => 'ticket', 'key' => 'payment_method', 'label' => ['en' => 'Payment method', 'am' => 'የክፍያ መንገድ'], 'type' => 'text'], $actor);
    $values->setTicketField($ticket->identifier(), 'payment_method', 'telebirr', $actor);

    $definitions->deactivate($fieldId, $actor);
    expect(fn () => $values->setTicketField($ticket->identifier(), 'payment_method', 'cbe', $actor))
        ->toThrow(InvalidArgumentException::class, 'retired');
    /** @var array{custom: array<string, string>} $formData */
    $formData = json_decode((string) DB::table('tickets')->where('id', $ticket->identifier())->value('form_data'), true);
    expect($formData['custom']['payment_method'])->toBe('telebirr', 'the definition went, the fact should stay (EC-09)');
    // SEC-06: the deactivation audit is its own high-risk action with the where-used counts.
    /** @var array{where_used: array{forms: int, rules: int, views: int}} $audit */
    $audit = json_decode((string) DB::table('audit_log')->where('action_key', 'field.deactivated')->orderByDesc('id')->value('payload'), true);
    expect($audit['where_used'])->toHaveKeys(['forms', 'rules', 'views']);

    // Never-reuse: a fresh definition on the retired key dies on the unscoped unique.
    try {
        DB::transaction(fn () => $definitions->create(['entity' => 'ticket', 'key' => 'payment_method', 'label' => ['en' => 'x', 'am' => 'x'], 'type' => 'text'], $actor));
        $this->fail('a retired key re-minted — FR-18 broken');
    } catch (Illuminate\Database\QueryException $e) {
        expect($e->getMessage())->toContain('field_definitions_key_never_reused');
    }

    $definitions->reactivate($fieldId, $actor);
    $values->setTicketField($ticket->identifier(), 'payment_method', 'cbe', $actor);
    expect(json_decode((string) DB::table('tickets')->where('id', $ticket->identifier())->value('form_data'), true)['custom']['payment_method'])->toBe('cbe');
});

it('EC-08: option removal snapshots the label; the stored value stands and renders from the snapshot', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    $definitions = app(DefinitionWriter::class);
    $values = app(ValueWriter::class);
    $actor = (string) Str::uuid7();
    $fieldId = $definitions->create(['entity' => 'ticket', 'key' => 'channel_pref', 'label' => ['en' => 'Channel', 'am' => 'መስመር'], 'type' => 'dropdown',
        'options' => [['value' => 'telebirr', 'label' => ['en' => 'Telebirr', 'am' => 'ቴሌብር']], ['value' => 'cbe', 'label' => ['en' => 'CBE Birr', 'am' => 'ሲቢኢ ብር']]]], $actor);
    $values->setTicketField($ticket->identifier(), 'channel_pref', 'cbe', $actor);

    $optionId = (string) DB::table('field_definition_options')->where('field_id', $fieldId)->where('value', 'cbe')->value('id');
    $definitions->removeOption($optionId, $actor);

    /** @var object{active: bool, label_snapshot: ?string} $option */
    $option = DB::table('field_definition_options')->where('id', $optionId)->first(['active', 'label_snapshot']);
    expect($option->active)->toBeFalse()
        ->and(json_decode((string) $option->label_snapshot, true))->toEqual(['en' => 'CBE Birr', 'am' => 'ሲቢኢ ብር']); // jsonb reorders keys
    // The stored value stands; a NEW write of the removed value refuses.
    expect(json_decode((string) DB::table('tickets')->where('id', $ticket->identifier())->value('form_data'), true)['custom']['channel_pref'])->toBe('cbe');
    expect(fn () => $values->setTicketField($ticket->identifier(), 'channel_pref', 'cbe', $actor))
        ->toThrow(InvalidArgumentException::class);
});

it('EC-14: the where-used answer is COMPLETE — the referencing rule and saved view are both named; the flagged rule evaluates is-empty', function (): void {
    [$tenant, $ticket, $author, $writer] = engineScene();
    $definitions = app(DefinitionWriter::class);
    $actor = (string) Str::uuid7();
    $fieldId = $definitions->create(['entity' => 'ticket', 'key' => 'vip_tier', 'label' => ['en' => 'VIP tier', 'am' => 'ደረጃ'], 'type' => 'text'], $actor);
    $rule = activeRule($writer, $author, 'vip-tier-rule', conditions: ['all' => [['fact' => 'ticket.custom.vip_tier', 'op' => 'equals', 'value' => 'gold']]]);
    DB::table('inbox_saved_views')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
        'owner_user_id' => $author->identifier(), 'name' => 'VIP tier view',
        'filters' => json_encode(['custom' => ['vip_tier' => 'gold']]),
    ]);

    $used = $definitions->deactivate($fieldId, $actor);
    expect(array_column($used['rules'], 'id'))->toContain($rule['rule_id'])
        ->and($used['views'])->toHaveCount(1);

    // The flagged rule keeps evaluating — the retired field's fact is EMPTY, honestly.
    $eventId = publishTicketCreated($tenant, $ticket);
    app(App\Domain\Automation\Engine\Evaluator::class)->evaluate($eventId, 'ticket.created');
    /** @var object{matched: bool, condition_results: string} $execution */
    $execution = DB::table('automation_rule_executions')->where('rule_id', $rule['rule_id'])->where('event_id', $eventId)->first(['matched', 'condition_results']);
    expect($execution->matched)->toBeFalse()
        ->and($execution->condition_results)->toContain('vip_tier');
});
