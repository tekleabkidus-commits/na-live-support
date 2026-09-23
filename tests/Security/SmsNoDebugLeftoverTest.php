<?php

declare(strict_types=1);

/*
| The 2ce16d7-look ruling (b), 2026-09-23 — the debug-leftover sweep: step 5's
| session instrumentation survived a cleanup script whose regex silently
| failed (never verified by grep), shipping a debug_backtrace + an extra
| COUNT query + a Log::debug on the guard's hot path from aaf19f7 to this
| commit. This sweep makes that class structural: no `debug_backtrace` and no
| `'DEBUG ` log call exists anywhere under app/Domain/Sms — and the scanner
| is proven on a positive control before it proves anything else.
*/

/** @return list<string> repo-relative offenders */
function smsDebugLeftoverScan(callable $contentsFor): array
{
    $offenders = [];
    foreach ($contentsFor() as $path => $contents) {
        if (preg_match('/debug_backtrace\s*\(/', $contents) === 1 || preg_match('/Log::debug\(\s*\'DEBUG /', $contents) === 1) {
            $offenders[] = $path;
        }
    }
    sort($offenders);

    return $offenders;
}

it('no debug_backtrace and no "DEBUG " log call exists under app/Domain/Sms — the scanner proven on a positive control first', function (): void {
    // POSITIVE control: the scanner sees both shapes when they exist
    $planted = smsDebugLeftoverScan(static fn (): iterable => [
        'planted-a.php' => '<?php $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);',
        'planted-b.php' => "<?php \\Illuminate\\Support\\Facades\\Log::debug('DEBUG optout read', []);",
        'innocent.php' => '<?php Log::debug("a legitimate debug line");',
    ]);
    expect($planted)->toBe(['planted-a.php', 'planted-b.php']);

    // the sweep itself
    $root = base_path('app/Domain/Sms');
    $offenders = smsDebugLeftoverScan(static function () use ($root): iterable {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                yield str_replace(base_path().'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname());
            }
        }
    });
    expect($offenders)->toBe([]);
});
