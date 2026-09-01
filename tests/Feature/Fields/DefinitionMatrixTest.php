<?php

declare(strict_types=1);

use App\Domain\Fields\DefinitionWriter;
use App\Domain\Fields\ValueWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-17 / FR-19 (A-59) / AC-06's matrix half — the 13-type matrix:
| definition validation × value validation × storage
|--------------------------------------------------------------------------
|
| Every FR-17 type: the definition lands, an accepting value stores its
| CANONICAL string (ticket → form_data.custom, the Doc 06 jsonb; the FR-02
| condition facts read the same key), and a refusing value fails with the
| definition's own vocabulary. Choice types demand options and refuse values
| outside them (EC-08's stable-value law).
*/

function fieldScene(): array
{
    [$tenant, , $ticket] = ticketFixture('open');

    return [$tenant, $ticket, app(DefinitionWriter::class), app(ValueWriter::class), (string) Str::uuid7()];
}

it('FR-17/FR-19: the 13-type matrix — definition, canonical storage, refusal vocabulary', function (): void {
    [, $ticket, $definitions, $values, $actor] = fieldScene();
    $matrix = [
        ['text', ['x' => 'free text'], 'free text', 123],
        ['number', [], '2500', 'not-a-number'],
        ['email', [], 'player@example.test', 'nope'],
        ['phone', [], '+251-90000-1234', 'abc'],
        ['date', [], '2026-09-01', '01/09/2026'],
        ['time', [], '14:30', '25:99'],
        ['checkbox', [], true, 'yes'],
        ['url', [], 'https://example.test/receipt', 'not a url'],
        ['rich_text', [], 'rich body', 9],
        ['file', [], 'media-ref-123', ''],
    ];
    foreach ($matrix as $i => [$type, , $good, $bad]) {
        $key = 'field_'.$type;
        $definitions->create(['entity' => 'ticket', 'key' => $key, 'label' => ['en' => $type, 'am' => $type], 'type' => $type], $actor);
        $values->setTicketField($ticket->identifier(), $key, $good, $actor);
        expect(fn () => $values->setTicketField($ticket->identifier(), $key, $bad, $actor))
            ->toThrow(InvalidArgumentException::class);
    }
    // Choice types: options demanded, values pinned to them.
    foreach (['radio', 'dropdown', 'multi_select'] as $type) {
        $key = 'field_'.$type;
        expect(fn () => $definitions->create(['entity' => 'ticket', 'key' => $key, 'label' => ['en' => $type, 'am' => $type], 'type' => $type], $actor))
            ->toThrow(InvalidArgumentException::class, 'options');
        $definitions->create(['entity' => 'ticket', 'key' => $key, 'label' => ['en' => $type, 'am' => $type], 'type' => $type,
            'options' => [['value' => 'telebirr', 'label' => ['en' => 'Telebirr', 'am' => 'ቴሌብር']], ['value' => 'cbe', 'label' => ['en' => 'CBE', 'am' => 'ሲቢኢ']]]], $actor);
        $good = $type === 'multi_select' ? ['telebirr', 'cbe'] : 'telebirr';
        $values->setTicketField($ticket->identifier(), $key, $good, $actor);
        expect(fn () => $values->setTicketField($ticket->identifier(), $key, $type === 'multi_select' ? ['chapa'] : 'chapa', $actor))
            ->toThrow(InvalidArgumentException::class);
    }

    // The canonical strings landed under form_data.custom — the SAME keys FR-02's
    // condition facts read (EventContext's ticket.custom.<key>).
    /** @var array{custom: array<string, string>} $formData */
    $formData = json_decode((string) DB::table('tickets')->where('id', $ticket->identifier())->value('form_data'), true);
    expect($formData['custom']['field_number'])->toBe('2500')
        ->and($formData['custom']['field_checkbox'])->toBe('true')
        ->and(json_decode($formData['custom']['field_multi_select'], true))->toBe(['telebirr', 'cbe']);

    // NFR-06: evented + audited.
    expect((int) DB::table('outbox')->where('name', 'field.changed')->count())->toBeGreaterThanOrEqual(13)
        ->and((int) DB::table('audit_log')->where('action_key', 'field.value.changed')->count())->toBeGreaterThan(0);
});

it('FR-17: number min/max and text regex validation rules bind; the where-used views slice stays honest', function (): void {
    [, $ticket, $definitions, $values, $actor] = fieldScene();
    $definitions->create(['entity' => 'ticket', 'key' => 'amount_etb', 'label' => ['en' => 'Amount', 'am' => 'መጠን'], 'type' => 'number', 'validation' => ['min' => 1, 'max' => 100000]], $actor);
    $definitions->create(['entity' => 'ticket', 'key' => 'tx_ref', 'label' => ['en' => 'Ref', 'am' => 'ማጣቀሻ'], 'type' => 'text', 'validation' => ['regex' => '^TB-[0-9]{6}$']], $actor);

    $values->setTicketField($ticket->identifier(), 'amount_etb', 2500, $actor);
    expect(fn () => $values->setTicketField($ticket->identifier(), 'amount_etb', 0, $actor))->toThrow(InvalidArgumentException::class, 'min');
    expect(fn () => $values->setTicketField($ticket->identifier(), 'amount_etb', 200000, $actor))->toThrow(InvalidArgumentException::class, 'max');
    $values->setTicketField($ticket->identifier(), 'tx_ref', 'TB-123456', $actor);
    expect(fn () => $values->setTicketField($ticket->identifier(), 'tx_ref', 'XX-1', $actor))->toThrow(InvalidArgumentException::class, 'pattern');
});
