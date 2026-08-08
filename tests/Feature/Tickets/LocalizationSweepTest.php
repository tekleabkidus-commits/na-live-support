<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 06 §14 (+§13's copy half) — the localization sweep (step 8)
|--------------------------------------------------------------------------
|
| "Status/priority names, pending reasons, dialogs, consequence copy, and failure
| reasons under tickets.* i18n keys (en + am)." The steps registered their copy as
| they shipped; this sweep is what proves NOTHING WAS LEFT BEHIND, mechanically:
|
|  - every refusal CODE the Tickets domain can raise (scanned from the source, not
|    from a hand-kept list) has a `tickets.fail.*` key in BOTH launch languages;
|  - the fixed vocabularies (8 statuses, 4 priorities, 4 pending reasons, §13's 8
|    consequence descriptions) are complete in both;
|  - the two files are structurally identical (key parity), so a string added to one
|    language cannot silently ship untranslated.
|
| N-03's string-lint gate polices literals OUT of the code; this sweep polices keys
| INTO the catalog. What it deliberately does not cover: countdown ICU phrasing and
| dialog step announcements are Doc 07's rendering (§13's interactive obligations
| land with its browser suite); tag content is operator free-text with no
| translation layer, by design (§14's own sentence).
*/

/** @return list<string> every quoted refusal code constructed in the Tickets domain */
function ticketRefusalCodesInSource(): array
{
    $roots = [
        base_path('app/Domain/Tickets'),
        base_path('app/Jobs/Tickets'),
    ];

    $codes = [];

    foreach ($roots as $root) {
        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // The refusal constructors: new TransitionRefused('code', …) and the
            // bulk loop's failItem($item, 'code') literals.
            preg_match_all("/new TransitionRefused\\(\\s*'([a-z_]+)'/", $source, $refused);
            preg_match_all("/failItem\\(\\\$item, '([a-z_]+)'\\)/", $source, $failed);

            $codes = [...$codes, ...$refused[1], ...$failed[1]];
        }
    }

    // The two exception-class codes that carry their own constants.
    $codes[] = 'illegal_transition';
    $codes[] = 'transition_not_permitted';

    return array_values(array_unique($codes));
}

test('§14: every refusal code the Tickets domain can raise has a fail.* key in BOTH launch languages', function () {
    $codes = ticketRefusalCodesInSource();
    expect(count($codes))->toBeGreaterThanOrEqual(20, 'the source scan collapsed — it no longer sees the refusal constructors');

    foreach (['en', 'am'] as $locale) {
        /** @var array<string, mixed> $strings */
        $strings = require base_path("lang/{$locale}/tickets.php");
        /** @var array<string, string> $fail */
        $fail = $strings['fail'];

        $missing = array_values(array_diff($codes, array_keys($fail)));

        expect($missing)->toBe([], sprintf(
            '§14: refusal code(s) with no %s copy: %s — an agent would see a raw code where an explanation belongs',
            $locale,
            implode(', ', $missing),
        ));
    }
});

test('§14/§13: the fixed vocabularies are complete in both languages, and the two files are structurally identical', function () {
    $flatten = function (array $strings, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($strings as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $keys = [...$keys, ...$flatten($value, $path)];
            } else {
                $keys[] = $path;
                expect(trim((string) $value))->not->toBe('', "{$path} is registered but empty");
            }
        }

        return $keys;
    };

    /** @var array<string, mixed> $en */
    $en = require base_path('lang/en/tickets.php');
    /** @var array<string, mixed> $am */
    $am = require base_path('lang/am/tickets.php');

    $enKeys = $flatten($en);
    $amKeys = $flatten($am);

    expect(array_values(array_diff($enKeys, $amKeys)))->toBe([], 'keys present in en but missing in am — a string would ship untranslated')
        ->and(array_values(array_diff($amKeys, $enKeys)))->toBe([], 'keys present in am but missing in en');

    $statuses = ['new', 'open', 'pending_customer', 'pending_internal', 'escalated', 'resolved', 'closed', 'spam'];

    foreach ($statuses as $status) {
        // (toContain takes needles only — the ledger's matcher lesson.)
        expect($enKeys)->toContain("status.{$status}")
            ->toContain("consequence.{$status}");
    }

    foreach (['low', 'normal', 'high', 'urgent'] as $priority) {
        expect($enKeys)->toContain("priority.{$priority}");
    }

    expect(count(array_filter($enKeys, static fn (string $k): bool => str_starts_with($k, 'pending_reason.'))))
        ->toBe(4, 'FR-01 fixes four pending reasons');
});
