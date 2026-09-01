<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 12 BR-02 / §7 / §21 — AUTOMATION GAINS NO BYPASS POWERS (static sweep)
|--------------------------------------------------------------------------
|
| Written BEFORE any action arm exists (S12 step 4, the batch-B ruling's own
| order). The day automation skips the transition service, Doc 06's invariants
| and this module's honesty both die (§21's words) — so the refusal is a
| GRAMMAR over the automation namespace, the PlaceInQueueOneDoorTest shape:
|
|   - no ticket WRITE of any kind (->update/->insert/->upsert on the tickets
|     table, a 'status' => assignment, a 'queue_id' => assignment) exists in
|     app/Domain/Automation or app/Jobs/Automation — actions CALL
|     TicketTransitionService / AssignTicket / PlaceInQueue / TransferTicket;
|   - no reach into the Transitions internals (TransitionMatrix construction)
|     — the service's public door is the only entry;
|   - POSITIVE CONTROL: the grammar itself trips on the ONE lawful writer
|     (TicketRepository), so an empty result is a working scan, not a broken
|     one. Sabotage (b): a status write added under Automation → red here.
*/

/** @return list<string> */
function automationSourceFiles(): array
{
    $out = [];
    foreach (['app/Domain/Automation', 'app/Jobs/Automation'] as $dir) {
        $path = base_path($dir);
        if (! is_dir($path)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
    }
    sort($out);

    return $out;
}

/** The forbidden-write grammar. @return list<string> matched descriptions */
function ticketWriteViolations(string $source): array
{
    $stripped = (string) preg_replace('~/\*.*?\*/|//[^\n]*|\|[^\n|]*\|~s', '', $source);
    $violations = [];
    if (preg_match("~table\\(\\s*'tickets'\\s*\\)\\s*->\\s*(update|insert|upsert|delete)~", $stripped, $m) === 1) {
        $violations[] = "tickets table {$m[1]}";
    }
    foreach (["'status' =>", '"status" =>', "'queue_id' =>", '"queue_id" =>', "'assignee_id' =>"] as $needle) {
        if (str_contains($stripped, $needle)) {
            $violations[] = "column assignment {$needle}";
        }
    }
    if (preg_match('~UPDATE\s+tickets~i', $stripped) === 1) {
        $violations[] = 'raw UPDATE tickets';
    }
    if (str_contains($stripped, 'TransitionMatrix')) {
        $violations[] = 'reach into TransitionMatrix';
    }

    return $violations;
}

it('BR-02: the automation namespace holds NO ticket write and NO transition-internals reach — with the lawful writer as the positive control', function (): void {
    $files = automationSourceFiles();
    expect($files)->not->toBeEmpty('the automation namespace vanished — the sweep is scanning nothing');

    foreach ($files as $file) {
        $violations = ticketWriteViolations((string) file_get_contents($file));
        expect($violations)->toBe([], sprintf('BR-02 violation(s) in %s: %s', $file, implode(', ', $violations)));
    }

    // POSITIVE CONTROL: the grammar catches the ONE lawful status writer, so a
    // green sweep above is a working scan.
    $repository = (string) file_get_contents(base_path('app/Domain/Tickets/Storage/TicketRepository.php'));
    expect(ticketWriteViolations($repository))->not->toBe([], 'the grammar no longer trips on TicketRepository — the sweep is blind');
});
