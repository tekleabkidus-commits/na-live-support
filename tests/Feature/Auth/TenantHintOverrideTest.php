<?php

declare(strict_types=1);

use App\Support\Auth\Sessions\TenantHint;
use Illuminate\Http\Request;

/**
 * The override must belong to ONE request object, not to a reusable id
 * (Doc 01 R-01; Doc 02 T-04/Q7).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS CATCHES, AND WHY IT IS NOT THEORETICAL.
 *
 * TenantHint::useForThisRequest() pins the tenant for the remainder of the
 * CURRENT request, because at login the session must be written under a tenant
 * whose cookie has not reached the browser yet. Until S11 the pin was keyed by
 * spl_object_id() — and PHP hands out the id of a collected object again. Under
 * Octane a later, unrelated request could therefore inherit a previous
 * request's tenant, read its session from the wrong keyspace, silently start a
 * fresh session, and fail CSRF with a bare 419 that no log explained.
 *
 * The test forces the collision rather than hoping for it: it takes the id of a
 * request that has been freed and constructs a new one, asserting the new
 * request is NOT served the old override. With spl_object_id restored this
 * fails; with the weak reference it passes.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function hintFor(Request $request): TenantHint
{
    app()->instance('request', $request);

    return app(TenantHint::class);
}

it('serves the override to the request that set it (positive control)', function (): void {
    $request = Request::create('/login', 'POST');
    $hint = hintFor($request);

    $hint->useForThisRequest('019fc000-0000-7000-8000-000000000001');

    expect($hint->current())->toBe('019fc000-0000-7000-8000-000000000001');
});

it('does NOT serve a previous request\'s override to a new request that REUSES its object id', function (): void {
    $hint = app(TenantHint::class);

    $first = Request::create('/login', 'POST');
    app()->instance('request', $first);
    $hint->useForThisRequest('019fc000-0000-7000-8000-000000000001');
    expect($hint->current())->toBe('019fc000-0000-7000-8000-000000000001', 'positive control: the override must apply to the request that set it');

    $firstId = spl_object_id($first);

    // The container's `request` binding is itself a reference, so the object
    // cannot be collected — and its id cannot be reused — until that binding
    // points elsewhere. Missing this made the collision unreproducible and the
    // test vacuous.
    app()->instance('request', Request::create('/placeholder', 'GET'));
    unset($first); // now genuinely freed

    // Allocate until the id is genuinely reused, so the assertion below is
    // testing the collision rather than merely a different request.
    $reused = null;
    for ($i = 0; $i < 200; $i++) {
        $candidate = Request::create('/anything', 'GET');
        if (spl_object_id($candidate) === $firstId) {
            $reused = $candidate;
            break;
        }
        unset($candidate);
    }

    expect($reused)->not->toBeNull('the harness could not reproduce an id collision; this test proves nothing without one');

    app()->instance('request', $reused);

    expect($hint->current())->toBeNull(
        'SEV-1: a request that merely REUSED a freed object id inherited the previous request\'s '
        .'tenant override — its session would be read from another tenant\'s keyspace, silently '
        .'starting a fresh session and failing CSRF with an unexplained 419'
    );
});
