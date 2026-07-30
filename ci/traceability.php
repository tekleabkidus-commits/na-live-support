<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| traceability gate (Doc 19 FR-02; CLAUDE.md §2 traceability registry rule)
|------------------------------------------------------------------------------
|
| Every Acceptance Criterion (§18 AC-XX) in every spec document must map to
| ≥ 1 entry in registries/traceability.yaml. Entry well-formedness (fields,
| enums, files existing) is registry:check's job; THIS gate answers one
| question: is any AC unmapped?
|
| PER-DOCUMENT FLIP (ADR 0004, owner ruling D-1). Supersedes Session 0's
| all-or-nothing bootstrap flip.
|
| A document's ACs become BLOCKING as soon as that document's row in
| docs/BUILD-STATUS.md leaves `NOT STARTED`. Until then they are reported as
| PENDING and do not fail the build.
|
| WHY IT CHANGED. Session 0 flipped the whole gate on the first registry entry:
| one honest entry for Doc 01 AC-01 would have made all 180 ACs across 19
| unbuilt documents hard-fail. The only ways out were to map 172 ACs of
| unwritten modules to placeholder "manual" entries — asserting coverage that
| does not exist, which is the false-PASS this repo exists to prevent — or to
| sign a skip file and turn the gate off. Both are worse than the gate being
| wrong.
|
| This is a STRENGTHENING, not a relaxation. Under the old rule the gate was
| either off (registry empty) or impossible (registry non-empty), so in practice
| it would have been off forever. Now every document that is being worked on is
| enforced, and enforcement arrives module by module as the build order
| progresses. A Doc 01 AC left unmapped fails the build today — falsified in the
| commit that introduced this.
|
| Exit codes: 0 = pass, 1 = violations.
|
*/

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

// --- collect every AC id from every spec document ----------------------------
$docFiles = glob($root.'/docs/Doc*.md') ?: [];
sort($docFiles);
if (count($docFiles) !== 19) {
    fwrite(STDERR, sprintf("traceability: FAIL — expected 19 spec documents in /docs, found %d\n", count($docFiles)));
    exit(1);
}

/** @var array<string, true> $allAcs "Doc01 AC-01" => true */
$allAcs = [];
foreach ($docFiles as $file) {
    $doc = substr(basename($file), 0, 5); // "Doc01"
    $contents = (string) file_get_contents($file);
    if (preg_match_all('/(?<![A-Za-z-])AC-(\d{2})\b/', $contents, $m) > 0) {
        foreach (array_unique($m[1]) as $num) {
            $allAcs[sprintf('%s AC-%s', $doc, $num)] = true;
        }
    }
}

// --- load the registry -------------------------------------------------------
/** @var mixed $registry */
$registry = Yaml::parseFile($root.'/registries/traceability.yaml');
$entries = (is_array($registry) && is_array($registry['entries'] ?? null)) ? $registry['entries'] : [];

$mapped = [];
foreach ($entries as $entry) {
    if (is_array($entry) && isset($entry['ac_ref']) && is_string($entry['ac_ref'])) {
        $mapped[$entry['ac_ref']] = true;
    }
}

// --- which documents are in scope for enforcement? --------------------------
//
// Read from docs/BUILD-STATUS.md, which is the owner-facing record of what is
// being built. A document is enforced once its row leaves `NOT STARTED`.
//
// BUILD-STATUS is deliberately the source rather than a separate config: it is the
// file the owner reads and updates, so enforcement follows the project's own
// account of itself and there is no second list to drift.
$buildStatusPath = $root.'/docs/BUILD-STATUS.md';
if (! is_file($buildStatusPath)) {
    fwrite(STDERR, "traceability: FAIL — docs/BUILD-STATUS.md is missing; cannot determine which documents are in scope\n");
    exit(1);
}
$buildStatus = (string) file_get_contents($buildStatusPath);

/** @var array<string, true> $enforcedDocs */
$enforcedDocs = [];
foreach (array_keys($allAcs) as $acRef) {
    [$doc] = explode(' ', $acRef);
    if (isset($enforcedDocs[$doc])) {
        continue;
    }

    // Table rows look like: | 01 | Platform Foundation & Multitenancy | NOT STARTED | S1 | …
    $number = substr($doc, 3); // "Doc01" -> "01"
    if (preg_match('/^\|\s*'.preg_quote($number, '/').'\s*\|[^|]*\|\s*([^|]+?)\s*\|/m', $buildStatus, $m) !== 1) {
        // No row for this document: treat as not started rather than guessing.
        continue;
    }

    $status = strtoupper($m[1]);

    // A document is enforced when it is being built...
    if (! str_contains($status, 'NOT STARTED') && ! str_contains($status, 'CONTINUOUS')) {
        $enforcedDocs[$doc] = true;
    }
}

// ...and, separately, by RATCHET: once any AC of a document is mapped, all of that
// document's ACs must be.
//
// The ratchet is what stops `CONTINUOUS` becoming a permanent exemption. Docs 18 and
// 19 are continuous by nature — Doc 18 accrues a gate per session, Doc 19 accrues
// traceability per session — so there is no single session at which all their ACs are
// in scope, and enforcing them wholesale today would demand rows for infrastructure
// that later documents have not introduced yet. But the moment someone maps ONE of
// their ACs, the whole document is enforced and cannot be left half-accounted-for.
//
// Net effect: a document is exempt only while nobody has claimed any coverage in it.
// Claiming any is a commitment to account for all of it.
foreach (array_keys($mapped) as $mappedRef) {
    [$mappedDoc] = explode(' ', $mappedRef);
    $enforcedDocs[$mappedDoc] = true;
}

$unmapped = [];
$pending = [];
foreach (array_keys($allAcs) as $acRef) {
    if (isset($mapped[$acRef])) {
        continue;
    }
    [$doc] = explode(' ', $acRef);
    if (isset($enforcedDocs[$doc])) {
        $unmapped[] = $acRef;
    } else {
        $pending[] = $acRef;
    }
}
sort($unmapped);

if ($pending !== []) {
    printf(
        'traceability: %d AC(s) PENDING across %d not-started document(s) — not enforced until their '
        ."BUILD-STATUS row leaves NOT STARTED (ADR 0004).\n",
        count($pending),
        count($allAcs) === 0 ? 0 : count(array_unique(array_map(
            static fn (string $ref): string => explode(' ', $ref)[0],
            $pending,
        ))),
    );
}

if ($unmapped !== []) {
    foreach ($unmapped as $ac) {
        fwrite(STDERR, "traceability: FAIL unmapped AC: $ac\n");
    }
    fwrite(STDERR, sprintf(
        'traceability: FAIL (%d unmapped AC(s) in %d in-progress document(s)). Every AC of a document '
        ."being built maps to a test or a justified manual/drill entry (Doc 19 FR-02).\n",
        count($unmapped),
        count($enforcedDocs),
    ));
    exit(1);
}

printf(
    "traceability: PASS (%d AC(s) mapped across %d enforced document(s); %d pending)\n",
    count($mapped),
    count($enforcedDocs),
    count($pending),
);
exit(0);
