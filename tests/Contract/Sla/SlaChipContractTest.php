<?php

declare(strict_types=1);

use App\Domain\Sla\Chips;
use App\Domain\Sla\Instances\StageLifecycle;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-11 — the SLA chip contract (Doc 07/06 render; fixture-pinned)
|--------------------------------------------------------------------------
|
| Chips::forTicket answers EXACTLY the fixture's shape: stage + label_key
| (en+am both real), state as a STEP (green → amber at warned → red at
| breached), target_at, paused {reason}. Several live stages → the MOST URGENT
| renders. Doc 07 renders this and nothing else.
*/

it('FR-11: shape, step states, pause reason and most-urgent selection — pinned to fixtures/sla/doc07-chip-contract.json', function (): void {
    [$tenant, $ticket, $instance] = timerScene(600);
    $chips = app(Chips::class);

    $chip = $chips->forTicket($ticket->identifier());
    expect($chip)->not->toBeNull();
    /** @var array{shape: array<string, string>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('fixtures/sla/doc07-chip-contract.json')), true, 8, JSON_THROW_ON_ERROR);
    expect(array_keys($chip))->toEqualCanonicalizing(array_keys($fixture['shape']), 'the chip shape drifted from the Doc 07 contract fixture');
    expect($chip['state'])->toBe('green')
        ->and(__($chip['label_key'], [], 'en'))->not->toBe($chip['label_key'])
        ->and(__($chip['label_key'], [], 'am'))->not->toBe($chip['label_key']);

    // amber at warned; red at breached — the most urgent of several live stages wins.
    DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->update(['warned' => true]);
    expect($chips->forTicket($ticket->identifier())['state'])->toBe('amber');
    DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->update(['breached' => true]);
    $chip = $chips->forTicket($ticket->identifier());
    expect($chip['state'])->toBe('red')
        ->and($chip['stage'])->toBe('first_response', 'the most urgent stage did not win');

    // paused carries the glyph's reason.
    app(StageLifecycle::class)->onStatusChanged($ticket->identifier(), 'open', 'pending_customer', '2026-09-01 10:00:00+00');
    $chip = $chips->forTicket($ticket->identifier());
    expect($chip['paused'])->toBe(['reason' => 'pending_customer']);
});
