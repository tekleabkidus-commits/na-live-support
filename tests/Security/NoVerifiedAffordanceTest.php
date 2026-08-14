<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 AC-09 / BR-01 / invariant N-06 — the repo-wide no-verified assertion
|--------------------------------------------------------------------------
|
| "Nothing in the product may render 'verified' for customer identity; the
| seeded UI copy and component library contain no verified-badge variant for
| customers" (BR-01, design-system-level). §21: keep this test forever — the
| first well-meaning PR that adds a checkmark will otherwise arrive within a
| quarter. B4-S07 H02 draws the prohibited affordances STRUCK THROUGH as
| documentation; nothing here may make them real.
|
| Landed at S08 step 1a, BEFORE any customer surface exists (the plan's
| ordered minimum), so every later step builds under it. Four sweeps:
|
|   1. SCHEMA: no /verif|confirmed/ column on any customer% table or
|      rg_flag_history (information_schema — picks up new tables by pattern,
|      so 1b's migrations arrive already policed).
|   2. COMPONENT LIBRARY: no verified- or verification-named file anywhere in
|      resources/js — non-vacuous today, repo-wide forever.
|   3. CUSTOMER UI NAMESPACES: zero /verif/i occurrences inside
|      resources/js/{Components,Pages}/Customers and lang/{en,am}/customers.php
|      — strict zero, no allowlist: a legitimate future use must edit THIS
|      test deliberately, which is the point.
|   4. CUSTOMER SERVER NAMESPACES: zero /verif/i in app/Domain/Customers and
|      app/Http/Controllers/Customers — no API field, no serializer key.
|
| PATTERN HISTORY (honest trail): the first run's /verif/i caught this
| guard's own implementation (the drop gate's verify() method); the pattern
| was then narrowed to /verified|verification/i — and the step-1 checkpoint
| ruling 4 REVERTED that: the narrow form is blind to VerifyBadge,
| verifiable, verify_badge — the next variant of exactly the concept BR-01
| forbids. The sweep is BROAD /verif/i again; genuine exceptions live in
| tests/Security/no-verified-allowlist.php, exact-match per (file, token)
| with a reason each — the string-lint convention, so every exception stays
| visible and reviewable instead of dissolving into the pattern.
*/

const VERIFIED_CONCEPT = '/verif/i';

/** @return array<string, list<string>> file => allowed lowercased tokens */
function verifAllowlist(): array
{
    /** @var array<string, list<string>> $list */
    $list = require __DIR__.'/no-verified-allowlist.php';

    return $list;
}

/** @return list<string> repo-relative offenders as "file: token, token" */
function verifSweepFiles(string $absoluteDir, string $pattern): array
{
    if (! is_dir($absoluteDir)) {
        return []; // the namespace does not exist yet; sweeps 1–2 stay non-vacuous
    }

    $allowlist = verifAllowlist();
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/verif\\w*/i', $contents, $matches) === 0 || preg_match($pattern, $contents) !== 1) {
            continue;
        }

        $allowed = array_map('strtolower', $allowlist[$relative] ?? []);
        $tokens = array_values(array_unique(array_map('strtolower', $matches[0])));
        $disallowed = array_values(array_diff($tokens, $allowed));

        if ($disallowed !== []) {
            $offenders[] = $relative.': '.implode(', ', $disallowed);
        }
    }

    return $offenders;
}

it('has no verified-shaped column on any customer table (schema sweep, N-06)', function (): void {
    $rows = DB::select(<<<'SQL'
        SELECT table_name, column_name
          FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (table_name LIKE 'customer%' OR table_name = 'rg_flag_history')
           AND (column_name ~* 'verif' OR column_name ~* 'confirmed')
        SQL);

    expect($rows)->toBe([], 'a verified/confirmed column exists on a customer table: '.json_encode($rows));
});

it('has no verified-named component anywhere in the client tree (BR-01 component library)', function (): void {
    $matches = glob(resource_path('js').'/**/*[Vv]erif*', GLOB_BRACE) ?: [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/verif/i', $file->getFilename()) === 1) {
            $matches[] = $file->getPathname();
        }
    }

    expect($matches)->toBe([], 'a verified-named client file exists: '.json_encode($matches));
});

it('keeps the customer UI namespaces and customers.* lang files free of the verified concept', function (): void {
    $offenders = array_merge(
        verifSweepFiles(resource_path('js/Components/Customers'), VERIFIED_CONCEPT),
        verifSweepFiles(resource_path('js/Pages/Customers'), VERIFIED_CONCEPT),
    );

    foreach (['en', 'am'] as $locale) {
        $path = lang_path($locale.'/customers.php');
        if (! is_file($path)) {
            continue;
        }
        $relative = 'lang/'.$locale.'/customers.php';
        $allowed = array_map('strtolower', verifAllowlist()[$relative] ?? []);
        preg_match_all('/verif\\w*/i', (string) file_get_contents($path), $matches);
        $disallowed = array_values(array_diff(array_map('strtolower', $matches[0]), $allowed));
        if ($disallowed !== []) {
            $offenders[] = $relative.': '.implode(', ', $disallowed);
        }
    }

    expect($offenders)->toBe([], 'the verified concept reached a customer UI namespace: '.json_encode($offenders));
});

it('keeps the Customers server namespaces free of any verified API field', function (): void {
    $offenders = array_merge(
        verifSweepFiles(app_path('Domain/Customers'), VERIFIED_CONCEPT),
        verifSweepFiles(app_path('Http/Controllers/Customers'), VERIFIED_CONCEPT),
    );

    expect($offenders)->toBe([], 'the verified concept reached a Customers server namespace: '.json_encode($offenders));
});
