<?php

declare(strict_types=1);

use App\Domain\Tickets\Storage\TicketRepository;
use App\Domain\Tickets\Transitions\TicketTransitionService;

/*
|--------------------------------------------------------------------------
| Doc 06 NFR-03 — one status writer, proven STRUCTURALLY
|--------------------------------------------------------------------------
|
| "The state machine has one server-side writer (transition service); no status writes
| elsewhere (CI grep + repository encapsulation)." NFR-03 names two proofs and this is
| the encapsulation half; ci/ticket-status-writer.sh is the grep half.
|
| WHY TWO. A grep recognises the shapes it was taught. This test recognises a FILE it
| was never told about: it walks every PHP file under app/ and asks which ones write a
| ticket status at all, then asserts the answer is one name. A novel spelling that
| slipped past the regex still lands in this set — and conversely, a file that stops
| existing shows up here rather than as a silently-passing gate.
|
| Both are needed because the failure they prevent is invisible after the fact: a status
| set outside the service leaves a ticket that looks perfectly normal, while its FR-24
| timestamps, its outbox event and its FR-10 invariant simply never happened.
*/

/** @return list<string> repo-relative paths of every PHP file under app/ */
function applicationPhpFiles(): array
{
    $root = base_path('app');
    $files = [];

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = 'app'.substr($file->getPathname(), strlen($root));
        }
    }

    sort($files);

    return $files;
}

/** A file's PHP with comments removed — prose explaining a pattern must not trip it. */
function applicationCodeWithoutComments(string $path): string
{
    $code = '';
    foreach (token_get_all((string) file_get_contents(base_path($path))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * Detection is PER FILE, not per line — and that is the correction this test's first
 * run forced. The original pattern demanded the ticket context and the status
 * assignment inside one statement (`Ticket::…'status' =>`). The repository builds its
 * payload first and calls ->update($update) two statements later, so the check found
 * ZERO writers — including the one file it exists to sanction — and would have reported
 * a clean green over any offender written the same ordinary way. S19's lesson #7: a
 * check that cannot see the thing it guards condemns the check, so the check was
 * strengthened rather than the finding explained away. `assertsTheKnownWriter` below is
 * the positive control that keeps it honest from here on.
 *
 * REFINED with the gate (S07 step 14, advisor-ruled 2026-08-13 stop item 4;
 * this half's failure surfaced in the condition-4 battery and is filed as a
 * finding): a RESPONSE PAYLOAD that projects the current status into JSON
 * (`'status' => $ticket->getAttribute('status')`) is assignment-shaped but
 * reads — it cannot change the column. Assignment lines whose VALUE side is
 * itself a status read (getAttribute('status') / ->status property or method /
 * ->value('status')) no longer count; a literal, variable, or computed value
 * still does. The positive control keeps the refinement honest: the sanctioned
 * writer's `'status' => $to->value` is NOT read-shaped and must stay detected —
 * an over-exclusion turns the control red, exactly as an under-detection would.
 */
function writesTicketStatus(string $path): bool
{
    $code = applicationCodeWithoutComments($path);

    $assignLines = preg_grep(
        '/([\'"]status[\'"]\s*=>|->status\s*=[^=>])/',
        explode("\n", $code),
    ) ?: [];
    $writeLines = preg_grep(
        '/[\'"]status[\'"]\s*=>\s*[^,]*(->getAttribute\([\'"]status[\'"]\)|->status\b|->value\([\'"]status[\'"]\))/',
        $assignLines,
        PREG_GREP_INVERT,
    ) ?: [];

    $assigns = $writeLines !== [];
    $ticketContext = preg_match('/(Ticket::|table\([\'"]tickets[\'"]\)|UPDATE\s+tickets\s)/i', $code) === 1;

    return $assigns && $ticketContext;
}

it('can see the sanctioned writer at all — the positive control for the two checks below', function () {
    // Without this, "exactly one writer" and the CI gate both pass vacuously the moment
    // the pattern stops matching reality. It is the assertion that failed first, and it
    // is the reason the pattern is what it is.
    expect(writesTicketStatus('app/Domain/Tickets/Storage/TicketRepository.php'))->toBeTrue(
        'the detector no longer recognises TicketRepository as a status writer — it is blind, and so is the gate',
    );
});

it('has exactly one file in app/ that writes a ticket status (NFR-03 encapsulation)', function () {
    $writers = array_values(array_filter(applicationPhpFiles(), writesTicketStatus(...)));

    expect($writers)->toBe(['app/Domain/Tickets/Storage/TicketRepository.php'], sprintf(
        "SEV-1: ticket status is written in %d place(s): %s\n".
        'Doc 06 T-01/NFR-03 allow exactly one — TicketTransitionService through TicketRepository. '
        .'A status set anywhere else skips the FR-24 timestamps, the outbox event and the FR-10 invariant, '
        .'and leaves a ticket that looks entirely normal afterwards.',
        count($writers),
        implode(', ', $writers),
    ));
});

it('routes every status write through the transition service, which is the only caller', function () {
    $repositorySource = (string) file_get_contents(base_path('app/Domain/Tickets/Storage/TicketRepository.php'));
    expect($repositorySource)->toContain('function writeStatus');

    // The gate cannot express "only one CALLER", so this asserts it directly: no file
    // but the service may name writeStatus().
    $callers = [];
    foreach (applicationPhpFiles() as $path) {
        if ($path === 'app/Domain/Tickets/Storage/TicketRepository.php') {
            continue;
        }
        // Comments stripped: the Ticket model's docblock NAMES writeStatus() to say
        // that only the service may call it, and prose describing the rule must not
        // register as breaking it (the first run flagged exactly that).
        if (str_contains(applicationCodeWithoutComments($path), 'writeStatus(')) {
            $callers[] = $path;
        }
    }

    expect($callers)->toBe(['app/Domain/Tickets/Transitions/TicketTransitionService.php'],
        'writeStatus() has a caller outside TicketTransitionService — T-01 says one path, and every '
        .'operation Doc 06 adds (resolve, escalate, spam, merge, split, bulk) must compose on the service.');
});

it('offers no mass-assignment path to status on the Ticket model', function () {
    // A `status` in the fillable list would let any ->fill()/->create() set it, which
    // is a status write the patterns above cannot see. Absence is the guarantee.
    $ticket = new App\Models\Ticket;

    expect($ticket->getFillable())->not->toContain('status')
        ->and($ticket->getFillable())->not->toContain('resolved_at')
        ->and($ticket->getFillable())->not->toContain('waiting_staff_seconds');
});

it('binds the service and repository as ordinary singleton-resolvable classes', function () {
    // Cheap, but it is what makes the two assertions above meaningful: if the service
    // could not be resolved, every caller would reach for the repository directly.
    expect(app(TicketTransitionService::class))->toBeInstanceOf(TicketTransitionService::class)
        ->and(app(TicketRepository::class))->toBeInstanceOf(TicketRepository::class);
});
