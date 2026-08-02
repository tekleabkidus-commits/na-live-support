<?php

declare(strict_types=1);

/**
 * The constant-time PIN for webhook credential comparisons (Doc 02
 * FR-23/FR-24; step-11 ruling, decision 2).
 *
 * HONESTLY LABELED: this is an ARCHITECTURE PIN, not a timing proof. A
 * nanosecond-scale early-exit memcmp signal on a 64-char digest is below
 * measurement noise on shared hardware, and a flaky timing test teaches
 * people to re-run red (the S4 lesson, recorded in BUILD-STATUS). So the
 * property is enforced in two falsifiable halves:
 *
 *   1. the comparison is LOAD-BEARING — sabotage (a), an always-true stub,
 *      turns the functional refusal tests red (WebhookSecretTest /
 *      WebhookHmacTest);
 *   2. the comparison is hash_equals — THIS file reads the middleware source
 *      and goes red when either comparison site stops being hash_equals;
 *      sabotage (b), swapping in `==`, is expected to redden ONLY this pin,
 *      and that expectation is itself part of the approved falsification
 *      bar ("only the pin goes red is the honest assertion of what the pin
 *      does").
 *
 * Same spirit as NoImpersonationTest's file sweep: when the property cannot
 * be observed at runtime, pin the source and say so.
 */
it('compares the header secret and the HMAC through hash_equals, pinned at the source', function (): void {
    $source = file_get_contents(app_path('Http/Middleware/VerifyWebhookSecret.php'));

    expect($source)->toBeString();

    // Both comparison sites, literally. Rewriting either to ==/===/strcmp
    // changes these lines and fails here by construction.
    expect($source)
        ->toContain("hash_equals(\$storedHash, hash('sha256', \$presented))")
        ->toContain('hash_equals($expected, strtolower($signature))');

    // And no loose comparison touches the presented credentials anywhere in
    // the file — belt for a refactor that keeps the pinned lines but adds a
    // second, weaker path.
    expect($source)
        ->not->toContain('== $presented')
        ->not->toContain('== $signature')
        ->not->toContain('=== $presented')
        ->not->toContain('=== $signature');
});

it('spends replay nonces through an atomic SET NX, pinned at the source', function (): void {
    // The same pin for the replay guard's atomicity: a read-then-write pair
    // would admit two concurrent replays; 'NX' in the single SET command is
    // what forbids it (sabotage (c) removes it and the replay test goes red
    // functionally — this pin names the mechanism for the next reader).
    $source = file_get_contents(app_path('Domain/Auth/Webhooks/ReplayGuard.php'));

    expect($source)->toBeString();
    expect($source)->toContain("'nx'");
});
