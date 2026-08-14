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
| Word-boundary caution is deliberate in sweeps 3–4: /verif/i also catches
| "verification", "unverified", "verify" — any of them in a CUSTOMER
| namespace is the concept arriving, which N-06 forbids wholesale.
*/

/** @return list<string> repo-relative offenders */
function verifSweepFiles(string $absoluteDir, string $pattern): array
{
    if (! is_dir($absoluteDir)) {
        return []; // the namespace does not exist yet; sweeps 1–2 stay non-vacuous
    }

    $offenders = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        if (preg_match($pattern, $contents) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
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
        verifSweepFiles(resource_path('js/Components/Customers'), '/verif/i'),
        verifSweepFiles(resource_path('js/Pages/Customers'), '/verif/i'),
    );

    foreach (['en', 'am'] as $locale) {
        $path = lang_path($locale.'/customers.php');
        if (is_file($path) && preg_match('/verif/i', (string) file_get_contents($path)) === 1) {
            $offenders[] = 'lang/'.$locale.'/customers.php';
        }
    }

    expect($offenders)->toBe([], 'the verified concept reached a customer UI namespace: '.json_encode($offenders));
});

it('keeps the Customers server namespaces free of any verified API field', function (): void {
    $offenders = array_merge(
        verifSweepFiles(app_path('Domain/Customers'), '/verif/i'),
        verifSweepFiles(app_path('Http/Controllers/Customers'), '/verif/i'),
    );

    expect($offenders)->toBe([], 'the verified concept reached a Customers server namespace: '.json_encode($offenders));
});
