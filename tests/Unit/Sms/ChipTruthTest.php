<?php

declare(strict_types=1);

use App\Domain\Sms\State\ChipMapper;
use App\Domain\Sms\State\SmsDeliveryState;

/*
| Doc 14 §9 / NFR-04 / AC-02 — THE CHIP TRUTH (the release gate's unit half).
|
| State chips: queued (clock), held (moon — quiet hours), sent (single check),
| delivered (double check — truthful here per DLR), undelivered/failed (red !),
| expired ("sent · unconfirmed" gray). ZERO inferred-delivered states: the
| double check renders ONLY from state `delivered`, which only a real DLR
| produces. The DOM half (every Doc 14 surface swept) lands at step 12 BEFORE
| the first chip renders. D14-D01: the double check and the moon are class-2
| fills from B1-DS-v2's glyph row.
*/

test('every state maps to exactly one chip and the double check exists for delivered ONLY', function () {
    $mapper = new ChipMapper;
    $glyphs = [];

    foreach (SmsDeliveryState::cases() as $state) {
        $chip = $mapper->for($state);
        $glyphs[$state->value] = $chip->glyph;

        expect($chip->i18nKey)->toBe("sms.chip.{$state->value}")
            ->and($chip->glyph)->toBeIn(['clock', 'moon', 'check', 'double_check', 'bang', 'unconfirmed']);
    }

    expect($glyphs)->toBe([
        'queued' => 'clock',
        'held' => 'moon',
        'sending' => 'clock',
        'sent' => 'check',
        'delivered' => 'double_check',
        'undelivered' => 'bang',
        'expired' => 'unconfirmed',
        'skipped' => 'bang',
        'failed' => 'bang',
    ]);

    expect(array_keys(array_filter($glyphs, static fn (string $g): bool => $g === 'double_check')))->toBe(['delivered']);
});

test('expired reads "sent · unconfirmed" in the gray tone — never a check, never success green', function () {
    $chip = (new ChipMapper)->for(SmsDeliveryState::Expired);

    expect($chip->tone)->toBe('muted')
        ->and($chip->glyph)->toBe('unconfirmed')
        ->and((new ChipMapper)->for(SmsDeliveryState::Delivered)->tone)->toBe('success')
        ->and((new ChipMapper)->for(SmsDeliveryState::Sent)->tone)->toBe('neutral')
        ->and((new ChipMapper)->for(SmsDeliveryState::Undelivered)->tone)->toBe('danger');
});

test('the mapper takes a STATE — there is no door that accepts a carrier word (sabotage (c): success → delivered has no path)', function () {
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(ChipMapper::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toBe(['for']);

    $param = (new ReflectionMethod(ChipMapper::class, 'for'))->getParameters()[0];
    expect((string) $param->getType())->toBe(SmsDeliveryState::class);
});
