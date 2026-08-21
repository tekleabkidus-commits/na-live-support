<?php

declare(strict_types=1);

/**
 * Doc 09 §14 / C-08 (S09 step 13): every ai.* string exists in BOTH launch
 * languages with an IDENTICAL key structure (a missing Amharic key would
 * render the English fallback — honest but a gap; a missing English key is
 * a defect); every :param a template names in en is named in am too; and
 * the R-14 native-review queue file enumerates every machine-authored
 * Amharic key with its review tier (destructive-tier keys flagged).
 */
function flattenKeys(array $arr, string $prefix = ''): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
        if (is_array($v)) {
            $out += flattenKeys($v, $key);
        } else {
            $out[$key] = is_string($v) ? $v : '';
        }
    }

    return $out;
}

it('en and am ai.php carry the same key set; every :param in en appears in am', function (): void {
    $en = flattenKeys(require base_path('lang/en/ai.php'));
    $am = flattenKeys(require base_path('lang/am/ai.php'));
    expect(array_diff_key($en, $am))->toBe([], 'keys missing in am: '.implode(', ', array_keys(array_diff_key($en, $am))))
        ->and(array_diff_key($am, $en))->toBe([], 'keys missing in en: '.implode(', ', array_keys(array_diff_key($am, $en))));
    foreach ($en as $key => $text) {
        preg_match_all('/:([a-z_]+)/', $text, $m);
        foreach ($m[1] as $param) {
            expect(str_contains($am[$key], ':'.$param))->toBeTrue("am {$key} lacks :{$param}");
        }
        expect(trim($am[$key]))->not->toBe('', "am {$key} is blank");
    }
    expect(count($en))->toBeGreaterThan(80);
});

it('the R-14 queue file lists every machine-authored am ai.* key exactly once, with the destructive tier flagged on the named keys', function (): void {
    $queue = json_decode((string) file_get_contents(base_path('fixtures/i18n/r14-queue-doc09.json')), true);
    $am = flattenKeys(require base_path('lang/am/ai.php'));
    $listed = array_column($queue['entries'], 'key');
    sort($listed);
    $expected = array_map(fn (string $k): string => 'ai.'.$k, array_keys($am));
    sort($expected);
    expect($listed)->toBe($expected);
    $destructive = array_column(array_filter($queue['entries'], fn (array $e): bool => $e['tier'] === 'destructive'), 'key');
    foreach (['ai.rg.template', 'ai.backoffice.confirm_off', 'ai.backoffice.rg_template', 'ai.backoffice.rollback', 'ai.backoffice.remove'] as $mustFlag) {
        expect($destructive)->toContain($mustFlag);
    }
});
