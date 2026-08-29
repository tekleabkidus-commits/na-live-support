<?php

declare(strict_types=1);

/**
 * Doc 10 §13/C-08 (S10 step 13): the kb.* namespace ships en AND am for EVERY key — parity
 * both directions (a key present in one language and missing in the other is a silent
 * English leak or a dead Amharic string), and every replacement placeholder (:name, :summary,
 * :link) survives translation (a dropped placeholder renders the raw colon-token to a
 * customer). Machine-authored Amharic rides the R-14 native-review queue; THIS test pins
 * structure, never prose quality.
 */
function kbLangFlatten(array $tree, string $prefix = ''): array
{
    $flat = [];
    foreach ($tree as $key => $value) {
        $qualified = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += kbLangFlatten($value, $qualified);
        } else {
            $flat[$qualified] = (string) $value;
        }
    }

    return $flat;
}

it('C-08: every kb.* key exists in BOTH en and am, no empty values, and every :placeholder set matches across the pair', function (): void {
    /** @var array<string, mixed> $en */
    $en = require base_path('lang/en/kb.php');
    /** @var array<string, mixed> $am */
    $am = require base_path('lang/am/kb.php');
    $enFlat = kbLangFlatten($en);
    $amFlat = kbLangFlatten($am);

    expect(array_diff_key($enFlat, $amFlat))->toBe([], 'keys with NO Amharic: '.implode(', ', array_keys(array_diff_key($enFlat, $amFlat))))
        ->and(array_diff_key($amFlat, $enFlat))->toBe([], 'Amharic keys with NO English: '.implode(', ', array_keys(array_diff_key($amFlat, $enFlat))));

    foreach ($enFlat as $key => $english) {
        expect(trim($english))->not->toBe('');
        expect(trim($amFlat[$key]))->not->toBe('');
        preg_match_all('/:([a-z_]+)/', $english, $enPlaceholders);
        preg_match_all('/:([a-z_]+)/', $amFlat[$key], $amPlaceholders);
        sort($enPlaceholders[1]);
        sort($amPlaceholders[1]);
        expect($amPlaceholders[1])->toBe($enPlaceholders[1], $key.' placeholder mismatch');
    }
});
