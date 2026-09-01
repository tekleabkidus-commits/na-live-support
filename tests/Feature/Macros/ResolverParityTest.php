<?php

declare(strict_types=1);

use App\Domain\Fields\DefinitionWriter;
use App\Domain\Fields\ValueWriter;
use App\Domain\Macros\PaletteReader;
use App\Domain\Macros\VariableResolver;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-15 / T-06 / §14 / AC-05's parity half — THE ONE RESOLVER
|--------------------------------------------------------------------------
|
| Preview and send resolve through the SAME class (parity by construction) —
| proven by identity: PaletteReader::preview and a direct resolve over the
| same macro+ticket answer byte-identical variables. The vocabulary: customer
| name, ticket number, agent first name, {{queue.wait_band}} through Doc 11's
| own §14-lawful provider, and {{custom.<key>}} from A-59's store. Unresolved
| names come back as the warning states (B12-S03's chips + T-06's guard input).
*/

it('T-06 parity + the vocabulary: preview == direct resolution, live values from rows, unresolved states honest', function (): void {
    [$tenant, $ticket, $agent, $writer] = macroScene();
    app(DefinitionWriter::class)->create(['entity' => 'ticket', 'key' => 'amount_etb', 'label' => ['en' => 'Amount', 'am' => 'መጠን'], 'type' => 'number'], $agent->identifier());
    app(ValueWriter::class)->setTicketField($ticket->identifier(), 'amount_etb', 2500, $agent->identifier());
    $macroId = $writer->create([
        'name' => 'Withdrawal delay — first reply', 'scope' => 'personal', 'owner_id' => $agent->identifier(),
        'body' => 'Hi {{customer.name}}, ticket {{ticket.number}}: your {{custom.amount_etb}} ETB — {{agent.first_name}} will reply within {{queue.wait_band}}. Missing: {{custom.payout_eta}}',
    ], $agent->identifier());

    $preview = app(PaletteReader::class)->preview($macroId, $ticket->identifier(), $agent);
    $direct = app(VariableResolver::class)->resolve((string) DB::table('macros')->where('id', $macroId)->value('body'), $ticket->identifier(), $agent->identifier());

    expect($preview)->toEqual($direct, 'preview and send resolution DIVERGED — T-06 broken');
    expect($preview['variables']['customer.name'])->toBe('Fixture Player')
        ->and($preview['variables']['ticket.number'])->toStartWith('#')
        ->and($preview['variables']['agent.first_name'])->not->toBeNull()
        ->and($preview['variables']['custom.amount_etb'])->toBe('2500')
        ->and($preview['variables']['queue.wait_band'])->not->toBeNull()
        ->and($preview['unresolved'])->toBe(['custom.payout_eta']);
    expect($preview['text'])->toContain('2500')
        // (no message arg — toContain treats it as another needle) the unresolved
        // pill must SURVIVE in the text: the guard reads it.
        ->and($preview['text'])->toContain('{{custom.payout_eta}}');

    // The macro's declared variable set derived from the body (one truth).
    expect(json_decode((string) DB::table('macros')->where('id', $macroId)->value('variables'), true))
        ->toContain('queue.wait_band');
});

it('FR-14 walls: an unknown bundled type refuses; saved replies share the storage with kind', function (): void {
    [, , $agent, $writer] = macroScene();
    expect(fn () => $writer->create(['name' => 'X', 'scope' => 'tenant', 'body' => 'x', 'bundled_actions' => [['type' => 'delete_ticket', 'value' => 1]]], $agent->identifier()))
        ->toThrow(InvalidArgumentException::class, 'set_status/set_priority/add_tags');
    $savedId = $writer->create(['name' => 'Shift handover', 'scope' => 'tenant', 'kind' => 'saved_reply', 'body' => 'plain'], $agent->identifier());
    expect(DB::table('macros')->where('id', $savedId)->value('kind'))->toBe('saved_reply');
    expect((int) DB::table('audit_log')->where('action_key', 'macro.changed')->count())->toBeGreaterThan(0);
});
