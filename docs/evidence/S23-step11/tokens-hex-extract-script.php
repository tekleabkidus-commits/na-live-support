<?php

declare(strict_types=1);

/*
 * Tokens-first hex extraction (advisor ruling 2, S23 step 11): every --ds-*
 * value in resources/css/design-tokens.css is checked hex-for-hex against
 * the FROZEN design sources — B1-DS-design-system-v2 (both colourways) plus
 * the three addenda. A token value that appears in NO frozen file is a
 * fidelity violation (the S19 label-token deviation is the one filed
 * exception). Comparison is case-insensitive on the hex digits.
 */

$root = '/app';
$css = file_get_contents($root.'/resources/css/design-tokens.css');
if ($css === false) {
    fwrite(STDERR, "cannot read design-tokens.css\n");
    exit(1);
}

$frozen = '';
foreach ([
    'B1-DS-design-system-v2.dc.html',
    'B1-DS-addendum-B5-v1.dc.html',
    'B1-DS-v3-addendum-B6-v1.dc.html',
    'B1-DS-v4-addendum-B7-v1.dc.html',
] as $file) {
    $content = file_get_contents($root.'/design/frozen/'.$file);
    if ($content === false) {
        fwrite(STDERR, "cannot read frozen file {$file}\n");
        exit(1);
    }
    $frozen .= strtolower($content);
}

preg_match_all('/(--ds-[a-z-]+):\s*(#[0-9a-fA-F]{3,8})/', $css, $matches, PREG_SET_ORDER);

// The S19 filed deviation: the label token deliberately maps to the
// compliant gray (the drawn #9A9588 fails AA) — checked against the FILING,
// not the frames.
$filedDeviations = ['--ds-text-label'];

$checked = 0;
$missing = [];
foreach ($matches as $m) {
    [, $token, $hex] = $m;
    if (in_array($token, $filedDeviations, true)) {
        printf("FILED-DEVIATION  %-28s %s (S19 DESIGN-DEVIATIONS: label token)\n", $token, $hex);

        continue;
    }
    $checked++;
    if (! str_contains($frozen, strtolower($hex))) {
        $missing[] = [$token, $hex];
    }
}

printf("\nchecked %d token declarations against 4 frozen design files\n", $checked);
if ($missing === []) {
    echo "RESULT: PASS — every non-deviation token hex appears verbatim in the frozen design sources\n";
    exit(0);
}
echo "design-system+addenda misses (checking the frozen SCREEN files next — the\n";
echo "token header names the B6 frames as the source for surface values):\n";

$screens = glob($root.'/design/frozen/B*-S*.dc.html') ?: [];
$stillMissing = [];
foreach ($missing as [$token, $hex]) {
    $foundIn = null;
    foreach ($screens as $screen) {
        $content = file_get_contents($screen);
        if ($content !== false && str_contains(strtolower($content), strtolower($hex))) {
            $foundIn = basename($screen);
            break;
        }
    }
    if ($foundIn !== null) {
        printf("  %-28s %s  → drawn in %s\n", $token, $hex, $foundIn);
    } else {
        $stillMissing[] = [$token, $hex];
    }
}

if ($stillMissing === []) {
    echo "RESULT: PASS — every non-deviation token hex appears verbatim in a frozen design source\n";
    exit(0);
}
echo "RESULT: FAIL — tokens with NO frozen source anywhere:\n";
foreach ($stillMissing as [$token, $hex]) {
    printf("  %-28s %s\n", $token, $hex);
}
exit(1);
