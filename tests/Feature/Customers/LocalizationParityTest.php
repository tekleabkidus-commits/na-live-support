<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 08 §14 — the customers.* localization sweep (S08 step 12)
|--------------------------------------------------------------------------
|
| string-lint (the CI gate) proves no user-visible literal escapes the key
| system; THIS test proves the en and am customers catalogs are
| STRUCTURALLY IDENTICAL, so the Doc 01 fallback chain is a net, never the
| silent steady state. Machine-authored am rides the standing R-14 queue —
| the "staff-collected" label is flagged there with §14's own note (it
| must not read as "untrusted" to agents; that judgment belongs to the
| native reviewer, not this test).
*/

/** @return array<string, string> dot-flattened key → value */
function customersFlattenLang(array $tree, string $prefix = ''): array
{
    $out = [];
    foreach ($tree as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $out += customersFlattenLang($value, $path);
        } else {
            $out[$path] = (string) $value;
        }
    }

    return $out;
}

it('§14: the en and am customers catalogs are structurally identical — every key in BOTH languages, no orphans either way, and every :placeholder present in en survives into am', function (): void {
    $en = customersFlattenLang(require base_path('lang/en/customers.php'));
    $am = customersFlattenLang(require base_path('lang/am/customers.php'));

    $missingInAm = array_diff_key($en, $am);
    $missingInEn = array_diff_key($am, $en);
    expect($missingInAm)->toBe([], 'customers keys present in en but MISSING in am: '.implode(', ', array_keys($missingInAm)));
    expect($missingInEn)->toBe([], 'customers keys present in am but MISSING in en: '.implode(', ', array_keys($missingInEn)));

    // Placeholder parity: a translation that drops :count or :name renders
    // a hole where a fact belongs.
    foreach ($en as $key => $value) {
        preg_match_all('/:(\w+)/', $value, $matches);
        foreach ($matches[1] as $placeholder) {
            expect(str_contains($am[$key], ':'.$placeholder))->toBeTrue(
                "customers.{$key}: the am translation lost the :{$placeholder} placeholder",
            );
        }
    }
});
