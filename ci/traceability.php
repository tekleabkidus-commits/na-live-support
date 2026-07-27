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
| BOOTSTRAP FLIP (explicit, not a TODO):
|   - registries/traceability.yaml `entries: []` → print the full unmapped-AC
|     count as WARN, exit 0 (no tests exist before Session 1).
|   - entries NON-EMPTY → every AC in every doc must be mapped; any unmapped
|     AC exits 1. No grace period, no partial credit.
|
| Exit codes: 0 = pass (or bootstrap warn), 1 = violations.
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

$unmapped = array_keys(array_diff_key($allAcs, $mapped));
sort($unmapped);
$bootstrap = $entries === [];

if ($unmapped !== []) {
    if ($bootstrap) {
        printf(
            "traceability: WARN — %d of %d ACs across 19 documents are unmapped, tolerated ONLY while "
            ."registries/traceability.yaml is empty. The first entry flips this gate to blocking.\n",
            count($unmapped),
            count($allAcs),
        );
        exit(0);
    }
    foreach ($unmapped as $ac) {
        fwrite(STDERR, "traceability: FAIL unmapped AC: $ac\n");
    }
    fwrite(STDERR, sprintf(
        "traceability: FAIL (%d unmapped AC(s) of %d). Every AC maps to a test or a justified manual/drill entry (Doc 19 FR-02).\n",
        count($unmapped),
        count($allAcs),
    ));
    exit(1);
}

printf("traceability: PASS (all %d ACs mapped)\n", count($allAcs));
exit(0);
