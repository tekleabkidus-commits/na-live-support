<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 07 §14 — the localization sweep's executable half (S07 step 15)
|--------------------------------------------------------------------------
|
| §14: "All inbox strings under inbox.* (en + am)". string-lint (the CI
| gate) proves no user-visible literal escapes the key system; THIS test
| proves the two catalogs are STRUCTURALLY IDENTICAL — every key present
| in both languages, no orphans either way — so the Doc 01 fallback chain
| (user → tenant default → en) is a safety net, never the silent steady
| state (R-24's spirit: fallback text renders, but only when content is
| GENUINELY missing, and machine-authored am rides the R-14 review queue
| rather than shipping holes). The remaining §14 clauses live where they
| render: ICU relative→absolute >24 h (Row.vue, layout suite), bidi
| isolation + locale digits (render-level), the 🎉/◇ empty-state token's
| per-tenant replacement (Doc 16's surface — the bucket named in the
| close-out).
*/

/** @return array<string, string> dot-flattened key → value */
function flattenLang(array $tree, string $prefix = ''): array
{
    $out = [];
    foreach ($tree as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $out += flattenLang($value, $path);
        } else {
            $out[$path] = (string) $value;
        }
    }

    return $out;
}

it('§14: the en and am inbox catalogs are structurally identical — every key exists in BOTH languages, no orphans either way (the fallback chain is a net, not a steady state)', function (): void {
    foreach (['inbox', 'tickets', 'tags'] as $file) {
        $en = flattenLang(require base_path("lang/en/{$file}.php"));
        $am = flattenLang(require base_path("lang/am/{$file}.php"));

        $missingInAm = array_diff_key($en, $am);
        $missingInEn = array_diff_key($am, $en);

        expect($missingInAm)->toBe([], "lang/{$file}: keys present in en but MISSING in am: ".implode(', ', array_keys($missingInAm)));
        expect($missingInEn)->toBe([], "lang/{$file}: keys present in am but MISSING in en: ".implode(', ', array_keys($missingInEn)));
    }
});

it('§14: no am value is an untranslated copy of its en sibling beyond the deliberate exceptions (typed phrases keep localized verbs; identical short tokens are enumerated, never silently grown)', function (): void {
    // Deliberate identical pairs: bare placeholders, counts, product nouns
    // that transliterate, and glyph-only strings. Growth of this list is a
    // REVIEWED act — a new identical value fails until enumerated here.
    $sanctioned = [
        'inbox.composer.telegram_name', // channel product noun
        'inbox.list.loaded',            // ':shown / :total' figure-only
    ];

    $en = flattenLang(require base_path('lang/en/inbox.php'));
    $am = flattenLang(require base_path('lang/am/inbox.php'));

    $identical = [];
    foreach ($en as $key => $value) {
        $amValue = $am[$key] ?? null;
        if ($amValue !== null && trim($value) !== '' && $amValue === $value) {
            // Values that are pure placeholders/punctuation/emoji are fine.
            $stripped = preg_replace('/:\w+|[[:punct:]\s\d]|[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $value);
            if ($stripped !== '' && ! in_array('inbox.'.$key, $sanctioned, true)) {
                $identical[] = 'inbox.'.$key;
            }
        }
    }

    expect($identical)->toBe([], 'am values identical to en (untranslated?): '.implode(', ', $identical));
});
