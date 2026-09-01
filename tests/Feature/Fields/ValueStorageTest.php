<?php

declare(strict_types=1);

use App\Domain\Fields\DefinitionWriter;
use App\Domain\Fields\ValueWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-19 (A-59, approved) — the two storage doors, no rival table
|--------------------------------------------------------------------------
|
| customer → Doc 08's customer_field_values door (its audit-history reading
| stands; the seeded trio accepts through BOTH validation layers; a NEW
| customer definition validates HERE and then hits Doc 08's fixture wall —
| packet P-FIELDS' documented pre-state, ASSERTED so the swap has its
| fail-first). ticket → the Doc 06 jsonb (proven across the matrix). The FR-02
| condition facts read both stores (EventContext — proven live here).
*/

it('A-59 customer half: the seeded trio stores through Doc 08\'s door; a NEW definition validates here and hits the fixture wall (P-FIELDS\' pre-state, asserted)', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    $definitions = app(DefinitionWriter::class);
    $values = app(ValueWriter::class);
    $actor = (string) Str::uuid7();
    $customerId = (string) DB::table('tickets')->where('id', $ticket->identifier())->value('customer_id');

    // The seeded key exists as a REAL Doc 12 definition too (my validation layer).
    $definitions->create(['entity' => 'customer', 'key' => 'player_id', 'label' => ['en' => 'Player ID', 'am' => 'የተጫዋች መለያ'], 'type' => 'text'], $actor);
    $values->setCustomerField($customerId, 'player_id', 'PL-90001', $actor);
    expect(DB::table('customer_field_values')->where('customer_id', $customerId)->where('field_key', 'player_id')->value('value'))
        ->toBe('PL-90001');

    // A NEW customer definition: MY validation passes, Doc 08's fixture door
    // refuses — the P-FIELDS packet's fail-first, pinned until the countersigned swap.
    $definitions->create(['entity' => 'customer', 'key' => 'referral_channel', 'label' => ['en' => 'Tier', 'am' => 'ደረጃ'], 'type' => 'text'], $actor);
    expect(fn () => $values->setCustomerField($customerId, 'referral_channel', 'gold', $actor))
        ->toThrow(InvalidArgumentException::class, 'unknown customer field');
});

it('FR-02 live: a stored ticket custom value drives a rule condition end-to-end (US-05\'s "usable in rule conditions")', function (): void {
    [$tenant, $ticket, $author, $writer] = engineScene();
    $definitions = app(DefinitionWriter::class);
    $values = app(ValueWriter::class);
    $definitions->create(['entity' => 'ticket', 'key' => 'promo_code', 'label' => ['en' => 'Promo', 'am' => 'ማስተዋወቂያ'], 'type' => 'text'], (string) Str::uuid7());
    $values->setTicketField($ticket->identifier(), 'promo_code', 'MEGA10', (string) Str::uuid7());
    $rule = activeRule($writer, $author, 'promo-rule', conditions: ['all' => [['fact' => 'ticket.custom.promo_code', 'op' => 'equals', 'value' => 'MEGA10']]]);

    $eventId = publishTicketCreated($tenant, $ticket);
    app(App\Domain\Automation\Engine\Evaluator::class)->evaluate($eventId, 'ticket.created');

    expect((bool) DB::table('automation_rule_executions')->where('rule_id', $rule['rule_id'])->where('event_id', $eventId)->value('matched'))
        ->toBeTrue('the stored custom value never reached the condition facts (US-05)');
});
