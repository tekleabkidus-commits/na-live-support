<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| widget-vocabulary gate (Doc 13 §8 / R-D13-5.1 / DESIGN-FIDELITY-MANDATE §4)
|------------------------------------------------------------------------------
|
| "No failure vocabulary on the customer surface, anywhere, in either
| language." Every CUSTOMER-FACING string of the widget — the `widget.*`
| namespace in lang/en and lang/am, minus the `feature.*` (Backoffice label)
| and `designer.*` (staff) branches — is swept against a denylist the plan
| enumerated: words that blame or alarm (error/failed/failure/broken/crash/
| exception/fatal/invalid/oops/sorry-something-went-wrong, and "unavailable" as
| BLAME — "articles unavailable — chat still works" is the drawn degrade and is
| allowed by its exact form), plus the Amharic equivalents. Degrade is labeled
| and calm (B3-S09); a dead widget is invisible, not a broken site (NFR-06).
|
| POSITIVE CONTROL: `--control` plants a denied word into an in-memory copy and
| must FAIL — the gate proves it can see what it is for (rule 11's own shape).
| A key may be exempted ONLY with a reason in the EXEMPT list below (none at
| landing; growing it is a filed amendment).
|
*/

$root = dirname(__DIR__);
$control = in_array('--control', $argv, true);

$deny = [
    // English (word-boundary, case-insensitive)
    '/\b(error|errors|failed|failure|failures|broken|crash|crashed|exception|fatal|invalid|oops|bug|corrupt|corrupted|rejected|denied|forbidden)\b/iu',
    '/\bsorry,? something went wrong\b/iu',
    '/\bunavailable\b(?! — chat still works)/iu', // "unavailable" as blame; the drawn degrade line is the one allowed form
    // Amharic equivalents: ስህተት (error), ተሳስቷል/ተሳሳተ (wrong/failed), ተበላሽቷል (broken), ወድቋል (crashed/fell), አልተሳካም (failed), ልክ ያልሆነ (invalid), ተከልክሏል (denied)
    '/(ስህተት|ተሳስቷል|ተሳሳተ|ተበላሽቷል|ወድቋል|አልተሳካም|ልክ ያልሆነ|ተከልክሏል|ውድቀት)/u',
];
$exemptPrefixes = ['feature.', 'designer.']; // staff-facing branches (Backoffice), not the customer surface
/** @var array<string, string> $exemptKeys key => the filed reason; none at landing — grows only by filed amendment */
$exemptKeys = [];

$violations = [];
$scanned = 0;
foreach (['en', 'am'] as $lang) {
    $path = "{$root}/lang/{$lang}/widget.php";
    if (! is_file($path)) {
        fwrite(STDERR, "widget-vocabulary: FAIL — {$path} is missing (the widget namespace must exist in both languages, C-08)\n");
        exit(1);
    }
    /** @var array<string, mixed> $strings */
    $strings = require $path;
    $flat = [];
    $flatten = function (array $node, string $prefix) use (&$flatten, &$flat): void {
        foreach ($node as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flatten($value, $full);
            } elseif (is_string($value)) {
                $flat[$full] = $value;
            }
        }
    };
    $flatten($strings, '');
    if ($control) {
        $flat['__control.planted'] = 'Sorry, something went wrong — an error occurred.';
    }
    foreach ($flat as $key => $text) {
        foreach ($exemptPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                continue 2;
            }
        }
        if (isset($exemptKeys[$key])) {
            continue;
        }
        $scanned++;
        foreach ($deny as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $violations[] = sprintf('%s/widget.%s: "%s" — «%s»', $lang, $key, $m[0], mb_substr($text, 0, 80));
                break;
            }
        }
    }
}

if ($control) {
    if ($violations === []) {
        fwrite(STDERR, "widget-vocabulary: CONTROL FAILED — the planted failure word was NOT caught; the gate is blind\n");
        exit(1);
    }
    echo 'widget-vocabulary: control OK — the planted word was caught ('.count($violations)." finding(s))\n";
    exit(0);
}

if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, "  widget-vocabulary: {$v}\n");
    }
    fwrite(STDERR, sprintf("widget-vocabulary: FAIL (%d customer-facing string(s) carry failure vocabulary — Doc 13 §8 / R-D13-5.1)\n", count($violations)));
    exit(1);
}
echo "widget-vocabulary: PASS ({$scanned} customer-facing widget strings swept in en+am; no failure vocabulary)\n";
