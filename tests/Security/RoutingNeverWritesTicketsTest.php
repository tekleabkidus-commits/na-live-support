<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 11 T-02 / §21 — routing NEVER writes ticket rows, proven STRUCTURALLY
|--------------------------------------------------------------------------
|
| "assignment commits through Doc 06's TicketTransitionService (actor=system) —
| routing never writes ticket rows directly" (T-02); "the moment routing writes
| tickets directly, two sources of transition truth exist and Doc 06's guarantees
| quietly die" (§21). A-49 (S11) reads the commit path as Doc 06's transition AND
| assignment doors; this test is the physics behind that reading: no PHP file under
| app/Domain/Routing or app/Jobs/Routing may touch the tickets table at all — not
| status, not assignee_id, not department_id, not queue_id, not through the model,
| not through the query builder, not through raw SQL.
|
| Sibling of StatusWriterEncapsulationTest (Doc 06 NFR-03), which stays green
| beside this one: the 13th gate polices STATUS across app/; this polices EVERY
| ticket write inside the routing package. Both are needed because a routing file
| that writes assignee_id directly leaves a ticket that looks normal while FR-15's
| scope check, the FR-16 race guard, the capacity commit and the audit row never
| happened.
|
| SEC-03 rides here too: the router holds NO grant authority — no file under the
| routing package may write user_permission_overrides either; pull-in goes through
| Doc 03's OverrideAdministration.
|
| POSITIVE CONTROL: the detector must recognise a planted write (sabotage #5 of
| the S11 plan) — a fixture string, never a real file — or it is blind.
*/

/** @return list<string> repo-relative PHP paths under the routing package */
function routingPackageFiles(): array
{
    $files = [];
    foreach (['app/Domain/Routing', 'app/Domain/Org', 'app/Jobs/Routing', 'app/Http/Controllers/Routing', 'app/Http/Controllers/Org'] as $dir) {
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

/** PHP with comments stripped — prose describing the rule must not trip it. */
function routingCodeWithoutComments(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * A ticket WRITE is any of: the Ticket model used with a mutating verb
 * (update/create/save/fill/delete/insert/forceFill/increment/decrement), the query
 * builder on the tickets table with a mutating verb, or raw UPDATE/INSERT/DELETE
 * SQL naming tickets. Reads (Ticket::query()->where…->first()) are allowed: the
 * engine reads ticket truth; it never writes it.
 *
 * @return list<string> the offending lines
 */
function ticketWriteLines(string $source): array
{
    $code = routingCodeWithoutComments($source);
    $verbs = '(update|updateOrCreate|updateOrInsert|create|save|saveQuietly|fill|forceFill|delete|forceDelete|insert|insertOrIgnore|upsert|increment|decrement|touch)';
    $patterns = [
        '/Ticket(::query\(\))?(->[a-zA-Z]+\([^)]*\))*->'.$verbs.'\s*\(/',
        '/\$ticket->'.$verbs.'\s*\(/',
        '/table\([\'"]tickets[\'"]\)(->[a-zA-Z]+\([^)]*\))*->'.$verbs.'\s*\(/',
        '/\b(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+tickets\b/i',
        '/table\([\'"]user_permission_overrides[\'"]\)(->[a-zA-Z]+\([^)]*\))*->'.$verbs.'\s*\(/',
        '/UserPermissionOverride(::query\(\))?(->[a-zA-Z]+\([^)]*\))*->'.$verbs.'\s*\(/',
    ];

    $hits = [];
    foreach (explode("\n", $code) as $n => $line) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $hits[] = sprintf('L%d: %s', $n + 1, trim($line));
                break;
            }
        }
    }

    return $hits;
}

it('can see a planted direct ticket write at all — the positive control (sabotage #5 shape)', function () {
    $planted = <<<'PHP'
    <?php
    final class Planted {
        public function run(string $id, string $agent): void {
            DB::table('tickets')->where('id', $id)->update(['assignee_id' => $agent]);
        }
    }
    PHP;
    expect(ticketWriteLines($planted))->toHaveCount(1);

    $plantedModel = "<?php Ticket::query()->whereKey(\$id)->update(['queue_id' => \$q]);";
    expect(ticketWriteLines($plantedModel))->toHaveCount(1);

    $plantedRaw = "<?php DB::statement('UPDATE tickets SET status = ? WHERE id = ?', [\$s, \$id]);";
    expect(ticketWriteLines($plantedRaw))->toHaveCount(1);

    $plantedGrant = "<?php UserPermissionOverride::query()->create(['permission' => 'org.queues.pull_in']);";
    expect(ticketWriteLines($plantedGrant))->toHaveCount(1);

    // A READ is not a write — the engine reads ticket truth constantly.
    $read = "<?php \$t = Ticket::query()->whereKey(\$id)->first(); \$rows = DB::table('tickets')->where('queue_id', \$q)->get();";
    expect(ticketWriteLines($read))->toBe([]);
});

it('T-02/§21: no file in the routing package writes a ticket row or a grant row', function () {
    $offenders = [];
    foreach (routingPackageFiles() as $path) {
        $lines = ticketWriteLines((string) file_get_contents(base_path($path)));
        if ($lines !== []) {
            $offenders[$path] = $lines;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "SEV-1: the routing package writes ticket/grant rows directly in %d file(s):\n%s\n"
        .'Doc 11 T-02 — routing commits ONLY through Doc 06\'s doors (AssignTicket::assignBySystem, '
        .'PlaceInQueue) and creates grants ONLY through Doc 03\'s OverrideAdministration (SEC-03).',
        count($offenders),
        implode("\n", array_map(fn ($p, $l) => $p.' → '.implode(' | ', $l), array_keys($offenders), $offenders)),
    ));
});

it('keeps the Doc 06 status physics green beside this one: the sole status writer is still TicketRepository', function () {
    // The 13th gate's encapsulation half is asserted in Doc 06's own suite; this
    // pin only proves the two checks are not the same check — a routing file that
    // wrote assignee_id would be red HERE and green THERE (status untouched), the
    // two-layer demonstration the S11 plan's sabotage #5 executes.
    expect(file_exists(base_path('tests/Feature/Tickets/StatusWriterEncapsulationTest.php')))->toBeTrue();
    expect(file_exists(base_path('ci/ticket-status-writer.sh')))->toBeTrue();
});
