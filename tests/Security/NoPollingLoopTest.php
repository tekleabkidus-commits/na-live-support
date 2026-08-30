<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-08 step 5 / NFR-02 / T-03 — "no polling loops anywhere", as CODE
|--------------------------------------------------------------------------
|
| "No eligible agent → ticket waits in queue; re-evaluated on every availability
| event (agent online, capacity freed, membership change) — event-driven, no
| polling loop" (FR-08 step 5). "no polling loops anywhere (event-driven per FR-08
| step 5)" (NFR-02). This test makes the absence structural in two directions:
|
|  (1) STATIC: no file in the routing package sleeps, spins, or schedules itself
|      (`sleep(`, `usleep(`, `while (true)`, `for (;;)`, `Schedule::`), and
|  (2) THE SCHEDULE PIN: the ONLY scheduled routing commands are the two the
|      document itself names — the 1-minute temporary-expiry sweep (T-07) and the
|      FS-01 consistency/rebuild probe. A scheduled "scan the queues" command is
|      exactly the polling loop the document forbids, and it would look like a
|      helpful addition. The pin moves ONLY with a filed amendment.
|
| The dispatcher is a pure event reaction (T-03): availability events → pop → assign.
| Sabotage #7 of the S11 plan plants a `routing:scan` every-minute command → red here.
*/

const ROUTING_SCHEDULED_ALLOWLIST = [
    'routing:expiry-sweep',   // T-07: the 1-min temporary-membership expiry sweep
    'routing:consistency',    // FS-01: ZSET/DB mirror probe (rebuild on drift)
];

/** @return list<string> */
function routingPackagePaths(): array
{
    $files = [];
    foreach (['app/Domain/Routing', 'app/Jobs/Routing', 'app/Console/Commands/Routing'] as $dir) {
        $root = base_path($dir);
        if (! is_dir($root)) {
            continue;
        }
        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $dir.substr($file->getPathname(), strlen($root));
            }
        }
    }
    sort($files);

    return $files;
}

/** @return list<string> */
function pollingShapes(string $source): array
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    $hits = [];
    foreach (explode("\n", $code) as $n => $line) {
        if (preg_match('/\b(sleep|usleep|time_nanosleep)\s*\(|while\s*\(\s*true\s*\)|for\s*\(\s*;\s*;\s*\)|Schedule::/', $line) === 1) {
            $hits[] = sprintf('L%d: %s', $n + 1, trim($line));
        }
    }

    return $hits;
}

it('can see a planted polling loop — the positive control (sabotage #7 shape)', function () {
    expect(pollingShapes('<?php while (true) { $this->scan(); sleep(60); }'))->toHaveCount(1)
        ->and(pollingShapes("<?php Schedule::command('routing:scan')->everyMinute();"))->toHaveCount(1)
        ->and(pollingShapes("<?php // sleep(60) in prose is fine\n\$x = 1;"))->toBe([]);
});

it('FR-08 step 5 / NFR-02: no sleep, spin or self-scheduling exists in the routing package', function () {
    $offenders = [];
    foreach (routingPackagePaths() as $path) {
        $hits = pollingShapes((string) file_get_contents(base_path($path)));
        if ($hits !== []) {
            $offenders[$path] = $hits;
        }
    }
    expect($offenders)->toBe([], 'a polling shape exists in the routing package: '.json_encode($offenders));
});

it('pins the scheduled routing commands to exactly the two the document names (T-07 sweep, FS-01 probe)', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $routing = [];
    foreach ($schedule->events() as $event) {
        if (preg_match('/artisan[\'"]?\s+(routing:[a-z-]+)/', $event->command ?? '', $m) === 1) {
            $routing[] = $m[1];
        }
    }
    sort($routing);
    $unexpected = array_values(array_diff($routing, ROUTING_SCHEDULED_ALLOWLIST));

    expect($unexpected)->toBe([], 'a scheduled routing command outside the allowlist is a polling loop by another name: '.implode(', ', $unexpected));
});
