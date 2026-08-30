<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 11 T-02 / A-49 — ONE WRITER of tickets.queue_id (S11 STOP-A packet obligation 3)
|--------------------------------------------------------------------------
|
| The KbOneIndexerDoorTest pattern: static half — the ONLY file under app/ that writes
| `queue_id` onto the tickets table is PlaceInQueue.php; a positive control proves the
| detector sees a planted writer. (Reads of queue_id are everywhere and are fine; the
| detector looks for an ASSIGNMENT of queue_id in a file that touches the tickets table.)
*/

const QUEUE_ID_DOOR = 'app/Domain/Tickets/Placement/PlaceInQueue.php';

function writesTicketQueueId(string $source): bool
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    $assigns = preg_match('/[\'"]queue_id[\'"]\s*=>/', $code) === 1
        || preg_match('/->queue_id\s*=[^=>]/', $code) === 1
        || preg_match('/SET\s+queue_id\s*=/i', $code) === 1;
    $ticketContext = preg_match('/(Ticket::|table\([\'"]tickets[\'"]\)|UPDATE\s+tickets\s)/i', $code) === 1;

    return $assigns && $ticketContext;
}

/** @return list<string> */
function ticketQueueIdWriters(): array
{
    $writers = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = 'app'.substr($file->getPathname(), strlen(base_path('app')));
        if (writesTicketQueueId((string) file_get_contents($file->getPathname()))) {
            $writers[] = $path;
        }
    }
    sort($writers);

    return $writers;
}

it('can see a planted queue_id writer — the positive control', function () {
    expect(writesTicketQueueId("<?php DB::table('tickets')->where('id', \$id)->update(['queue_id' => \$q]);"))->toBeTrue()
        ->and(writesTicketQueueId("<?php Ticket::query()->whereKey(\$id)->update(['queue_id' => \$q]);"))->toBeTrue()
        ->and(writesTicketQueueId("<?php \$rows = DB::table('tickets')->where('queue_id', \$q)->get();"))->toBeFalse('a READ was flagged')
        ->and(writesTicketQueueId("<?php DB::table('queues')->update(['name' => 'x']); \$t = Ticket::find(\$id);"))->toBeFalse();
});

it('T-02/A-49: exactly one file under app/ writes tickets.queue_id — PlaceInQueue', function () {
    expect(ticketQueueIdWriters())->toBe([QUEUE_ID_DOOR], 'a second queue_id writer exists: '.implode(', ', ticketQueueIdWriters()));
});
