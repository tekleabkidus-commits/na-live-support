<?php

declare(strict_types=1);

use App\Domain\Fields\DefinitionWriter;
use App\Domain\Fields\ValueWriter;
use App\Domain\Forms\SubmissionWriter;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-22 / BR-06 / SS-03 / T-07 — submissions snapshot {version_id +
| answers} into the ticket; the server is truth for conditional logic
|--------------------------------------------------------------------------
*/

it('BR-06: a submission names its exact version forever — a later publish never re-interprets it; the custom key survives', function (): void {
    [, $ticket, $agent, $writer, $publisher] = formScene();
    app(DefinitionWriter::class)->create(['entity' => 'ticket', 'key' => 'branch_code', 'label' => ['en' => 'Branch', 'am' => 'ቅርንጫፍ'], 'type' => 'text'], $agent->identifier());
    app(ValueWriter::class)->setTicketField($ticket->identifier(), 'branch_code', 'AA-04', $agent->identifier());
    $formId = $writer->create(['name' => 'Fixture Snapshot Form'], $agent->identifier());
    $v1 = $publisher->publish($formId, minimalFormSchema(), $agent->identifier());

    app(SubmissionWriter::class)->record($ticket->identifier(), $v1['version_id'], ['summary' => 'my 2,500 birr did not arrive'], 'webchat');
    $publisher->publish($formId, minimalFormSchema(), $agent->identifier()); // v2 — must not touch the snapshot

    /** @var object{form_data: string} $row */
    $row = DB::selectOne('SELECT form_data::text AS form_data FROM tickets WHERE id = ?', [$ticket->identifier()]);
    $data = json_decode($row->form_data, true);
    expect($data['version_id'])->toBe($v1['version_id'])
        ->and($data['answers'])->toBe(['summary' => 'my 2,500 birr did not arrive'])
        ->and($data['complete'])->toBeTrue()
        ->and($data['custom'])->toBe(['branch_code' => 'AA-04']);

    /** @var object{payload: string} $event */
    $event = DB::table('outbox')->where('name', 'form.submitted')->first(['payload']);
    expect($event)->not->toBeNull();
    $payload = json_decode($event->payload, true);
    expect($payload['version_id'])->toBe($v1['version_id'])
        ->and($payload['channel'])->toBe('webchat')
        ->and($payload['complete'])->toBeTrue();
});

it('T-07 server truth: a COMPLETE submission missing a required-and-VISIBLE answer refuses; hidden-required is not demanded; SS-03 partial lands flagged incomplete', function (): void {
    [, $ticket, $agent, $writer, $publisher] = formScene();
    $formId = $writer->create(['name' => 'Fixture Conditional Form'], $agent->identifier());
    $schema = minimalFormSchema();
    $schema['steps'][0]['fields'][] = [
        'key' => 'reference', 'type' => 'text', 'required' => true, 'required_when_visible' => true,
        'label' => ['en' => 'Reference', 'am' => 'ማጣቀሻ'],
        'visible_when' => ['field' => 'summary', 'op' => 'contains', 'value' => 'withdrawal'],
    ];
    $version = $publisher->publish($formId, $schema, $agent->identifier());
    $submissions = app(SubmissionWriter::class);

    // Visible (condition holds) and absent → refused.
    expect(fn () => $submissions->record($ticket->identifier(), $version['version_id'], ['summary' => 'withdrawal stuck'], 'webchat'))
        ->toThrow(DomainException::class, 'missing_required: reference');
    // Hidden (condition fails) → not demanded.
    $submissions->record($ticket->identifier(), $version['version_id'], ['summary' => 'login problem'], 'webchat');

    // SS-03: the mid-form handoff lands PARTIAL data, honestly flagged.
    $submissions->record($ticket->identifier(), $version['version_id'], ['summary' => 'withdrawal stuck'], 'telegram', complete: false);
    /** @var object{form_data: string} $row */
    $row = DB::selectOne('SELECT form_data::text AS form_data FROM tickets WHERE id = ?', [$ticket->identifier()]);
    expect(json_decode($row->form_data, true)['complete'])->toBeFalse();
    expect((int) DB::table('outbox')->where('name', 'form.submitted')->whereRaw("payload->>'complete' = 'false'")->count())->toBe(1);
});
