<?php

declare(strict_types=1);

use App\Domain\Sla\ExampleSentence;

/*
|--------------------------------------------------------------------------
| Doc 12 §8 / §13 / §14 / AC-04 — the example sentence USES the clock library
|--------------------------------------------------------------------------
|
| AC-04's clause: "the SLA example-sentence computation verified against the
| clock library (one implementation, §8 example uses it)". The arithmetic here
| is golden timeline #1's (fixtures/sla/golden-timelines.json): created Tue
| 2026-09-01 17:00 Addis on an 08:00–17:00 Mon–Fri calendar with a 40-minute
| target → Wed 08:40 local. ICU-rendered en + am; the empty-calendar case
| renders the unreachable notice, never a fabricated date.
*/

function exampleCalendar(): array
{
    $weekdays = [];
    foreach ([1, 2, 3, 4, 5] as $d) {
        $weekdays[$d] = [['open' => '08:00', 'close' => '17:00']];
    }

    return ['timezone' => 'Africa/Addis_Ababa', 'weekdays' => $weekdays, 'holidays' => []];
}

it('AC-04: the §8 example reproduces golden timeline #1 through the ONE clock implementation', function (): void {
    $sentence = app(ExampleSentence::class);
    $created = new DateTimeImmutable('2026-09-01T14:00:00Z'); // Tue 17:00 Addis

    $target = $sentence->targetInstant(exampleCalendar(), 2400, $created);
    expect($target)->not->toBeNull()
        ->and($target->format('Y-m-d\TH:i:s\Z'))->toBe('2026-09-02T05:40:00Z'); // Wed 08:40 Addis
});

it('§14: ICU-rendered in both languages with the display timezone explicit; an empty calendar renders the unreachable notice', function (): void {
    $sentence = app(ExampleSentence::class);
    $created = new DateTimeImmutable('2026-09-01T14:00:00Z');
    $tz = new DateTimeZone('Africa/Addis_Ababa');

    $en = $sentence->render(exampleCalendar(), 2400, $created, $tz, 'en');
    expect($en)->toContain('Tue 17:00')->toContain('Wed 08:40')->toContain('Africa/Addis_Ababa');

    $am = $sentence->render(exampleCalendar(), 2400, $created, $tz, 'am');
    expect($am)->toContain('Wed 08:40')
        ->and($am)->not->toBe($en); // a real translation, not a fallback echo

    $empty = ['timezone' => 'UTC', 'weekdays' => [], 'holidays' => []];
    expect($sentence->render($empty, 2400, $created, $tz, 'en'))
        ->toBe(__('workflow.sla.example_unreachable', [], 'en'));
});
