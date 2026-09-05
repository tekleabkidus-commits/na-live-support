<?php

declare(strict_types=1);

namespace App\Support\Theme;

use InvalidArgumentException;

/**
 * A-62 (ruled at the S13 plan approval): the platform's ONE contrast validator —
 * SHARED KERNEL, pure (no framework), the WCAG 2.x relative-luminance formula.
 * Doc 13's Widget Designer consumes it as a HARD GATE (no override path exists,
 * by construction: no method here or on the publisher takes a bypass); Doc 16's
 * branding session binds the SAME class and adds nothing but its surfaces.
 *
 *  - check(fg, bg): the ratio (1..21) and the AA verdicts (4.5:1 text, 3:1 large/UI).
 *  - suggest(fg, bg, min): the nearest passing shade of the SAME hue, found by a
 *    lightness walk (HSL, hue and saturation held) away from the background —
 *    B3-S08's drawn "#F2C94C on white → #8A6A05 at 4.6:1".
 */
final class ContrastValidator
{
    public const AA_TEXT = 4.5;

    public const AA_LARGE = 3.0;

    public function check(string $fg, string $bg): ContrastVerdict
    {
        $ratio = self::ratio($fg, $bg);

        return new ContrastVerdict($ratio, $ratio >= self::AA_TEXT, $ratio >= self::AA_LARGE);
    }

    /** The nearest same-hue shade of $fg that reaches $minRatio against $bg (walking lightness in 1 % steps, away from the background). */
    public function suggest(string $fg, string $bg, float $minRatio = self::AA_TEXT): string
    {
        if (self::ratio($fg, $bg) >= $minRatio) {
            return self::normalize($fg);
        }
        [$h, $s, $l] = self::toHsl($fg);
        $bgL = self::luminance(self::rgb($bg));
        // Walk toward the end of the lightness scale that contrasts with the background;
        // if that end cannot reach the ratio, walk the other way (a mid-grey background).
        foreach ([$bgL >= 0.5 ? -1 : 1, $bgL >= 0.5 ? 1 : -1] as $direction) {
            for ($step = 1; $step <= 100; $step++) {
                $candidateL = $l + $direction * $step / 100;
                if ($candidateL < 0.0 || $candidateL > 1.0) {
                    break;
                }
                $hex = self::fromHsl($h, $s, $candidateL);
                if (self::ratio($hex, $bg) >= $minRatio) {
                    return $hex;
                }
            }
        }

        return $bgL >= 0.5 ? '#000000' : '#ffffff';
    }

    public static function ratio(string $fg, string $bg): float
    {
        $a = self::luminance(self::rgb($fg));
        $b = self::luminance(self::rgb($bg));
        [$light, $dark] = $a >= $b ? [$a, $b] : [$b, $a];

        return ($light + 0.05) / ($dark + 0.05);
    }

    public static function normalize(string $hex): string
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $h) !== 1) {
            throw new InvalidArgumentException('not a hex colour: '.$hex);
        }

        return '#'.strtolower($h);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $h = substr(self::normalize($hex), 1);

        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private static function luminance(array $rgb): float
    {
        $lin = array_map(static function (int $c): float {
            $v = $c / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /** @return array{0: float, 1: float, 2: float} hue 0..360, saturation 0..1, lightness 0..1 */
    private static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(static fn (int $c): float => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match (true) {
            $max === $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $max === $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $hp = $h / 60;
        $x = $c * (1 - abs(fmod($hp, 2) - 1));
        [$r, $g, $b] = match (true) {
            $hp < 1 => [$c, $x, 0.0],
            $hp < 2 => [$x, $c, 0.0],
            $hp < 3 => [0.0, $c, $x],
            $hp < 4 => [0.0, $x, $c],
            $hp < 5 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };
        $m = $l - $c / 2;
        $to = static fn (float $v): string => str_pad(dechex((int) round(max(0.0, min(1.0, $v + $m)) * 255)), 2, '0', STR_PAD_LEFT);

        return '#'.$to($r).$to($g).$to($b);
    }
}
