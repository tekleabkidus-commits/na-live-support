<?php

declare(strict_types=1);

use App\Domain\Forms\Runtime\TelegramFlowEngine;
use App\Domain\Forms\Runtime\WidgetContract;
use App\Domain\Forms\SubmissionWriter;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 AC-07 / FR-21 / T-09 — DUAL-RENDER CONFORMANCE: one versioned schema
| → the widget contract AND both Telegram modes → submissions of IDENTICAL
| shape. The renderers may differ in voice; the data may not.
|--------------------------------------------------------------------------
*/

/** @return array<string, mixed> a schema exercising choice + conditional + both languages */
function conformanceSchema(): array
{
    return [
        'languages' => ['en', 'am'],
        'steps' => [
            ['key' => 'issue', 'title' => ['en' => 'The issue', 'am' => 'ችግሩ'], 'fields' => [
                ['key' => 'kind', 'type' => 'radio', 'required' => true, 'label' => ['en' => 'What happened?', 'am' => 'ምን ተከሰተ?'],
                    'options' => [
                        ['value' => 'missing', 'label' => ['en' => 'Money missing', 'am' => 'ገንዘብ ጠፍቷል']],
                        ['value' => 'delayed', 'label' => ['en' => 'Delayed', 'am' => 'ዘግይቷል']],
                    ]],
                ['key' => 'amount', 'type' => 'number', 'required' => true, 'required_when_visible' => true,
                    'label' => ['en' => 'Amount', 'am' => 'መጠን'],
                    'visible_when' => ['field' => 'kind', 'op' => 'equals', 'value' => 'missing']],
            ]],
        ],
        'completion' => ['tags' => ['conformance']],
    ];
}

it('one schema, three renders, ONE submission shape: widget-path, direct-mode, and business-mode answers are identical', function (): void {
    // ticketFixture FIRST — it binds its own tenant; everything shares it.
    [, , $ticket] = ticketFixture('open');
    $agent = grantAllPermissions(ticketActor([]));
    $writer = app(App\Domain\Forms\FormWriter::class);
    $publisher = app(App\Domain\Forms\FormPublisher::class);
    $formId = $writer->create(['name' => 'Fixture Conformance Form'], $agent->identifier());
    $version = $publisher->publish($formId, conformanceSchema(), $agent->identifier());
    $engine = app(TelegramFlowEngine::class);

    // Direct mode: inline buttons carry the choice.
    [, $direct] = conversationFixture();
    $prompt = $engine->start($direct->identifier(), $formId, 'direct', 'en');
    expect(array_column($prompt['buttons'], 'value'))->toBe(['missing', 'delayed'])
        ->and($prompt)->not->toHaveKey('numbered');
    $engine->handle($direct->identifier(), ['choice' => 'missing']);
    $directDone = $engine->handle($direct->identifier(), ['text' => '2500']);

    // Business mode: the SAME choice renders numbered, answered by number.
    [, $business] = conversationFixture();
    $prompt = $engine->start($business->identifier(), $formId, 'business', 'en');
    expect($prompt['numbered'])->toBe(['1. Money missing', '2. Delayed'])
        ->and($prompt['instruction_key'])->toBe('workflow.flow.choose_number')
        ->and($prompt)->not->toHaveKey('buttons');
    $engine->handle($business->identifier(), ['text' => '1']);
    $businessDone = $engine->handle($business->identifier(), ['text' => '2500']);

    expect($directDone['form_data'])->toEqual($businessDone['form_data'])
        ->and($directDone['form_data']['answers'])->toBe(['kind' => 'missing', 'amount' => '2500']);

    // Widget path: same answers through SubmissionWriter — same stored shape.
    app(SubmissionWriter::class)->record($ticket->identifier(), $version['version_id'], $directDone['form_data']['answers'], 'webchat');
    /** @var object{form_data: string} $row */
    $row = DB::selectOne('SELECT form_data::text AS form_data FROM tickets WHERE id = ?', [$ticket->identifier()]);
    $stored = json_decode($row->form_data, true);
    expect($stored['answers'])->toBe($directDone['form_data']['answers'])
        ->and($stored['version_id'])->toBe($version['version_id']);
});

it('§14 language resolution is ONE chain for both renderers: customer language → the form\'s languages → first declared; the conditional hides identically', function (): void {
    $tenant = createTenant();
    $agent = grantAllPermissions(ticketActor([]));
    $formId = app(App\Domain\Forms\FormWriter::class)->create(['name' => 'Fixture Language Form'], $agent->identifier());
    $version = app(App\Domain\Forms\FormPublisher::class)->publish($formId, conformanceSchema(), $agent->identifier());

    // Widget contract resolves Amharic when the customer speaks it, first-declared otherwise.
    $widget = app(WidgetContract::class);
    expect($widget->render($version['version_id'], 'am')['language'])->toBe('am')
        ->and($widget->render($version['version_id'], 'fr')['language'])->toBe('en');

    // The Telegram engine walks the SAME chain — Amharic labels, Amharic buttons.
    $engine = app(TelegramFlowEngine::class);
    [, $conversation] = conversationFixture();
    $prompt = $engine->start($conversation->identifier(), $formId, 'direct', 'am');
    expect($prompt['text'])->toBe('ምን ተከሰተ?')
        ->and(array_column($prompt['buttons'], 'label'))->toBe(['ገንዘብ ጠፍቷል', 'ዘግይቷል']);

    // Conditional logic is server truth in BOTH renders (T-07): choosing
    // 'delayed' hides 'amount' — the flow completes without ever asking it,
    // and the widget-path submission is not asked for it either.
    $done = $engine->handle($conversation->identifier(), ['choice' => 'delayed']);
    expect($done['kind'])->toBe('completed')
        ->and($done['form_data']['answers'])->toBe(['kind' => 'delayed']);
    expect(app(SubmissionWriter::class)->missingRequired(conformanceSchema(), ['kind' => 'delayed']))->toBe([]);
});
