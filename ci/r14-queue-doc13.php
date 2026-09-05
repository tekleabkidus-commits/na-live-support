<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| R-14 native-review queue — Doc 13 (S13 step 12). Every `widget.*` key with its
| machine-authored Amharic beside the English, the DESTRUCTIVE tier flagged per
| entry (publish / rollback / secret / discard confirms, the transcript note and
| its refusal, the blocked-visitor copy, the offline submit, the retry card).
| Regenerate: php ci/r14-queue-doc13.php > fixtures/i18n/r14-queue-doc13.json
|------------------------------------------------------------------------------
*/

/** @var array<mixed> $en */
$en = require __DIR__.'/../lang/en/widget.php';
/** @var array<mixed> $am */
$am = require __DIR__.'/../lang/am/widget.php';

$destructive = [
    'designer.publish', 'designer.publishing', 'designer.published', 'designer.rollback', 'designer.rolled_back', 'designer.discard',
    'designer.secret_create', 'designer.secret_rotate', 'designer.secret_shown', 'designer.secret_note', 'designer.created_title', 'designer.created_note',
    'designer.test_mode', 'designer.contrast_refused', 'designer.contrast_why', 'designer.contrast_use',
    'transcript.note', 'transcript.rate_limited', 'overflow.transcript',
    'composer.paused', 'offline.submit', 'offline.receipt', 'retry.body', 'retry.title', 'retry.action',
];

/**
 * @param  array<mixed>  $tree
 * @return array<string, string>
 */
function flatten(array $tree, string $prefix = ''): array
{
    $out = [];
    foreach ($tree as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $out += flatten($value, $path);
        } elseif (is_string($value)) {
            $out[$path] = $value;
        }
    }

    return $out;
}

$flatEn = flatten($en);
$flatAm = flatten($am);
$entries = [];
$missing = [];
foreach ($flatEn as $key => $english) {
    $amharic = $flatAm[$key] ?? null;
    if (! is_string($amharic)) {
        $missing[] = $key;

        continue;
    }
    $entries[] = ['key' => 'widget.'.$key, 'am' => $amharic, 'en' => $english, 'tier' => in_array($key, $destructive, true) ? 'destructive' : 'standard'];
}
// P-NAV-WIDGETS (S13 Batch C, countersign condition 1): Doc 13-owned strings that LIVE in another document's
// lang file join this queue too — the shared Backoffice row's name inside Doc 12's `workflow.php` (non-destructive).
$crossFile = [
    ['file' => 'workflow', 'path' => 'ui.nav.widgets', 'origin' => 'P-NAV-WIDGETS (Doc 12 file, Doc 13 row)'],
];
foreach ($crossFile as $ref) {
    /** @var array<mixed> $otherEn */
    $otherEn = require __DIR__.'/../lang/en/'.$ref['file'].'.php';
    /** @var array<mixed> $otherAm */
    $otherAm = require __DIR__.'/../lang/am/'.$ref['file'].'.php';
    $english = flatten($otherEn)[$ref['path']] ?? null;
    $amharic = flatten($otherAm)[$ref['path']] ?? null;
    if (! is_string($english) || ! is_string($amharic)) {
        $missing[] = $ref['file'].'.'.$ref['path'];

        continue;
    }
    $entries[] = ['key' => $ref['file'].'.'.$ref['path'], 'am' => $amharic, 'en' => $english, 'tier' => 'standard', 'origin' => $ref['origin']];
}
$queue = [
    'doc' => 'Doc13',
    'spec' => 'R-14 native-review queue — Doc 13 machine-authored Amharic (opened S13 step 12): every widget.* key, customer-facing branches and the Designer, the destructive tier flagged per entry for the native reviewer.',
    'count' => count($entries),
    'destructive_count' => count(array_filter($entries, static fn (array $e): bool => $e['tier'] === 'destructive')),
    'missing_am' => $missing,
    'entries' => $entries,
];
echo json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
