<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Fields\DefinitionWriter;
use App\Domain\Forms\Seeding\FormSeeder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-20 / FR-22 / FR-23 / BR-06 / EC-07 / R-03 — publish mints an
| immutable version; every FR-22 block refuses; seeded forms are content
|--------------------------------------------------------------------------
*/

it('FR-22: publish mints sequential immutable versions, moves the pointer, audits high-risk, and events kind=published', function (): void {
    [, , $agent, $writer, $publisher] = formScene();
    $formId = $writer->create(['name' => 'Fixture Form'], $agent->identifier());
    expect((int) DB::table('outbox')->where('name', 'form.changed')->count())->toBe(1);

    $first = $publisher->publish($formId, minimalFormSchema(), $agent->identifier());
    $second = $publisher->publish($formId, minimalFormSchema(), $agent->identifier());
    expect($first['version'])->toBe(1)->and($second['version'])->toBe(2);
    expect(DB::table('forms')->where('id', $formId)->value('current_version_id'))->toBe($second['version_id']);
    expect(AuditLogger::recordedCount('form.published'))->toBe(2);
    expect((int) DB::table('outbox')->where('name', 'form.changed')->whereRaw("payload->>'kind' = 'published'")->count())->toBe(2);
});

it('FR-22 blocks: retired ref (EC-14), unknown field, EC-07 required-hidden WITH the auto-suggest, language completeness, R-03 forward wall — each refuses the publish', function (): void {
    [, , $agent, $writer, $publisher] = formScene();
    $fields = app(DefinitionWriter::class);
    $retiredId = $fields->create(['entity' => 'ticket', 'key' => 'legacy_ref', 'label' => ['en' => 'Legacy', 'am' => 'የቆየ'], 'type' => 'text'], $agent->identifier());
    $fields->deactivate($retiredId, $agent->identifier());
    $formId = $writer->create(['name' => 'Fixture Blocks Form'], $agent->identifier());

    $codes = static fn (array $schema): array => array_column($publisher->preview($formId, $schema)['blocks'], 'code');

    $withField = static function (array $field): array {
        $schema = minimalFormSchema();
        $schema['steps'][0]['fields'][] = $field;

        return $schema;
    };

    expect($codes($withField(['ref' => 'legacy_ref'])))->toContain('retired_field')
        ->and($codes($withField(['ref' => 'never_defined'])))->toContain('unknown_field')
        ->and($codes($withField(['key' => 'no_am', 'type' => 'text', 'label' => ['en' => 'English only']])))->toContain('incomplete_language');

    // EC-07: required + conditional visibility → block CARRIES the fix.
    $hidden = $withField(['key' => 'maybe', 'type' => 'text', 'required' => true,
        'label' => ['en' => 'Maybe', 'am' => 'ምናልባት'],
        'visible_when' => ['field' => 'summary', 'op' => 'not_empty']]);
    $blocks = $publisher->preview($formId, $hidden)['blocks'];
    $ec07 = collect($blocks)->firstWhere('code', 'required_hidden');
    expect($ec07)->not->toBeNull()
        ->and($ec07['suggest'])->toBe(['set' => ['required_when_visible' => true]]);
    // ...and applying the suggested fix publishes.
    $hidden['steps'][0]['fields'][1]['required_when_visible'] = true;
    expect($publisher->preview($formId, $hidden)['blocks'])->toBe([]);

    // R-03: a condition may reference the same or an EARLIER step, never a later one.
    $forward = minimalFormSchema();
    $forward['steps'][0]['fields'][0]['visible_when'] = ['field' => 'later_field', 'op' => 'equals', 'value' => 'x'];
    $forward['steps'][] = ['key' => 'second', 'title' => ['en' => 'Second', 'am' => 'ሁለተኛ'], 'fields' => [
        ['key' => 'later_field', 'type' => 'text', 'label' => ['en' => 'Later', 'am' => 'ኋለኛ']],
    ]];
    expect($codes($forward))->toContain('forward_reference');
    $backward = minimalFormSchema();
    $backward['steps'][] = ['key' => 'second', 'title' => ['en' => 'Second', 'am' => 'ሁለተኛ'], 'fields' => [
        ['key' => 'echo', 'type' => 'text', 'label' => ['en' => 'Echo', 'am' => 'አስተጋባ'],
            'visible_when' => ['field' => 'summary', 'op' => 'not_empty']],
    ]];
    expect($codes($backward))->toBe([]);

    expect(fn () => $publisher->publish($formId, $forward, $agent->identifier()))
        ->toThrow(DomainException::class, 'publish_blocked');
    // Every block label speaks both launch languages (N-03).
    foreach (['invalid_shape', 'forward_reference', 'unknown_field', 'retired_field', 'required_hidden', 'incomplete_language', 'upload_unsupported'] as $code) {
        expect(__('workflow.forms.block.'.$code, [], 'en'))->not->toBe('workflow.forms.block.'.$code)
            ->and(__('workflow.forms.block.'.$code, [], 'am'))->not->toBe('workflow.forms.block.'.$code);
    }
});

it('FR-22: a REQUIRED file field blocks publish when a registered channel accepts no uploads', function (): void {
    [, , $agent, $writer, $publisher] = formScene();
    $registry = app(AdapterRegistry::class);
    $registry->register('fixturesms', new FakeChannelAdapter(new Capabilities(
        maxText: 160, mediaTypes: [], supportsTyping: false, supportsEdit: false,
        supportsDelete: false, supportsButtons: false, replyWindow: ReplyWindow::none(),
    )), 'sms', 'sms');
    $formId = $writer->create(['name' => 'Fixture File Form'], $agent->identifier());
    $schema = minimalFormSchema();
    $schema['steps'][0]['fields'][] = ['key' => 'proof', 'type' => 'file', 'required' => true, 'label' => ['en' => 'Proof', 'am' => 'ማስረጃ']];

    $blocks = $publisher->preview($formId, $schema)['blocks'];
    $block = collect($blocks)->firstWhere('code', 'upload_unsupported');
    expect($block)->not->toBeNull()->and($block['channel'])->toBe('fixturesms');
    // Optional-but-requested (the FR-23 stance) publishes fine.
    $schema['steps'][0]['fields'][1]['required'] = false;
    expect($publisher->preview($formId, $schema)['blocks'])->toBe([]);
});

it('FR-23/§21: the seeder mints the three forms PUBLISHED + three example rules born INACTIVE; presence wins on re-seed (editable content)', function (): void {
    [, , $agent] = formScene();
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $seeder = app(FormSeeder::class);

    $first = $seeder->seed($agent);
    expect($first)->toBe(['forms' => 3, 'rules' => 3]);
    /** @var list<object{name: string, current_version_id: ?string, entity_bindings: string}> $forms */
    $forms = DB::select('SELECT name, current_version_id, entity_bindings::text AS entity_bindings FROM forms ORDER BY name');
    expect(array_column($forms, 'name'))->toBe(['Deposit Issue', 'Verification Request', 'Withdrawal Issue']);
    foreach ($forms as $form) {
        expect($form->current_version_id)->not->toBeNull()
            ->and($form->entity_bindings)->toContain('department_id');
    }
    expect((int) DB::table('automation_rules')->where('active', true)->count())->toBe(0)
        ->and((int) DB::table('automation_rules')->count())->toBe(3);

    // Idempotent + never-overwrite: an operator republish survives a re-seed untouched.
    $publisher = app(App\Domain\Forms\FormPublisher::class);
    $depositId = (string) DB::table('forms')->where('name', 'Deposit Issue')->value('id');
    $edited = $publisher->publish($depositId, minimalFormSchema(), $agent->identifier());
    expect($seeder->seed($agent))->toBe(['forms' => 0, 'rules' => 0]);
    expect(DB::table('forms')->where('id', $depositId)->value('current_version_id'))->toBe($edited['version_id']);
});
