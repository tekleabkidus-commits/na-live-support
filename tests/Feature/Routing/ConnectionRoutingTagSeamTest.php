<?php

declare(strict_types=1);

use App\Domain\Routing\Seams\ConnectionRoutingTagSource;

/*
| Doc 11 FR-08 step 1 — the Doc 05 connection-tag SEAM (STOP-C.3 ruled): the precedence slot is
| preserved and tested against the fixture; production returns null (falls through to the tenant
| default department). No Doc 05 column exists — reopener on Doc 05's FR-08 row.
*/
it('reads the fixture in local/testing and returns null for unknown or untagged connections and for a null connection', function () {
    $source = new ConnectionRoutingTagSource;
    expect($source->tagFor('fixture-conn-payments'))->toBe('payments')
        ->and($source->tagFor('fixture-conn-vip'))->toBe('vip')
        ->and($source->tagFor('fixture-conn-untagged'))->toBeNull()
        ->and($source->tagFor('nope'))->toBeNull()
        ->and($source->tagFor(null))->toBeNull();
});
