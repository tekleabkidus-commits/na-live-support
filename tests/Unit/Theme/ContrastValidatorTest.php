<?php

declare(strict_types=1);

use App\Support\Theme\ContrastValidator;

/*
|--------------------------------------------------------------------------
| A-62: the platform's ONE contrast validator — pure, WCAG 2.x, property-tested.
| Symmetry, monotonicity in lightness, the AA thresholds, both B1-DS-v2
| colourways as fixtures, and B3-S08's drawn refusal (a yellow on white cannot
| publish; the suggestion is the same hue, darker, ≥ 4.5:1).
|--------------------------------------------------------------------------
*/

function hslHue(string $hex): float
{
    $h = ltrim($hex, '#');
    [$r, $g, $b] = [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    if ($max === $min) {
        return 0.0;
    }
    $d = $max - $min;
    $hue = match (true) {
        $max === $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
        $max === $g => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };

    return $hue * 60;
}

it('is symmetric and bounded: ratio(a,b) === ratio(b,a) within [1, 21] for random pairs; black on white is 21', function (): void {
    $v = new ContrastValidator;
    mt_srand(62);
    for ($i = 0; $i < 200; $i++) {
        $a = sprintf('#%06x', mt_rand(0, 0xFFFFFF));
        $b = sprintf('#%06x', mt_rand(0, 0xFFFFFF));
        $ab = $v->check($a, $b)->ratio;
        $ba = $v->check($b, $a)->ratio;
        expect(abs($ab - $ba))->toBeLessThan(1e-9)->and($ab)->toBeGreaterThanOrEqual(1.0)->and($ab)->toBeLessThanOrEqual(21.0);
    }
    expect(round($v->check('#000000', '#ffffff')->ratio, 2))->toBe(21.0)
        ->and(round($v->check('#ffffff', '#ffffff')->ratio, 2))->toBe(1.0);
});

it('is monotone in lightness: a lighter grey on white always contrasts LESS; the AA thresholds sit where WCAG puts them', function (): void {
    $v = new ContrastValidator;
    $previous = PHP_FLOAT_MAX;
    for ($g = 0; $g <= 255; $g += 5) {
        $hex = sprintf('#%02x%02x%02x', $g, $g, $g);
        $ratio = $v->check($hex, '#ffffff')->ratio;
        expect($ratio)->toBeLessThanOrEqual($previous);
        $previous = $ratio;
    }
    expect($v->check('#767676', '#ffffff')->passesAa)->toBeTrue('#767676 on white is 4.54:1 — the canonical AA pass')
        ->and($v->check('#777777', '#ffffff')->passesAa)->toBeFalse('#777777 on white is 4.48:1 — below AA text')
        ->and($v->check('#777777', '#ffffff')->passesAaLarge)->toBeTrue('but above the 3:1 large/UI threshold')
        ->and($v->check('#aaaaaa', '#ffffff')->passesAaLarge)->toBeFalse();
});

it('passes both B1-DS-v2 colourways as fixtures (the tokens the widget ships with)', function (): void {
    $v = new ContrastValidator;
    // light: accent #22211d on panel #ffffff, its contrast #ffffff on the accent; text #1d1c18 on #ffffff
    expect($v->check('#22211d', '#ffffff')->passesAa)->toBeTrue()
        ->and($v->check('#ffffff', '#22211d')->passesAa)->toBeTrue()
        ->and($v->check('#1d1c18', '#ffffff')->passesAa)->toBeTrue();
    // dark: accent #eceae4 on panel #1c1c19, its contrast #171714 on the accent; text #eceae4 on #1c1c19
    expect($v->check('#eceae4', '#1c1c19')->passesAa)->toBeTrue()
        ->and($v->check('#171714', '#eceae4')->passesAa)->toBeTrue();
});

it('B3-S08 J03: the yellow on white cannot publish; the suggestion is the SAME hue, darker, at ≥ 4.5:1; a passing colour suggests itself', function (): void {
    $v = new ContrastValidator;
    $verdict = $v->check('#F2C94C', '#ffffff');
    expect($verdict->passesAa)->toBeFalse()->and($verdict->ratio)->toBeLessThan(2.0);
    $suggested = $v->suggest('#F2C94C', '#ffffff');
    expect($v->check($suggested, '#ffffff')->ratio)->toBeGreaterThanOrEqual(4.5)
        ->and(abs(hslHue($suggested) - hslHue('#F2C94C')))->toBeLessThan(6.0, 'same hue family (the drawn #8A6A05 is this walk)')
        ->and($v->suggest('#22211d', '#ffffff'))->toBe('#22211d')
        ->and($v->suggest('#F2C94C', '#1c1c19', ContrastValidator::AA_LARGE))->toBe('#f2c94c'); // already reads on the dark panel
    // the walk goes the other way on a dark background
    $onDark = $v->suggest('#2a2a2a', '#1c1c19');
    expect($v->check($onDark, '#1c1c19')->ratio)->toBeGreaterThanOrEqual(4.5);
});

it('normalizes 3- and 6-digit hex and refuses anything else', function (): void {
    expect(ContrastValidator::normalize('#ABC'))->toBe('#aabbcc')->and(ContrastValidator::normalize(' 112233 '))->toBe('#112233');
    expect(fn () => ContrastValidator::normalize('red'))->toThrow(InvalidArgumentException::class);
});
