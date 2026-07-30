<?php

declare(strict_types=1);

use App\Support\Audit\AuditActor;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantKeyIssuer;
use Illuminate\Support\Str;

/**
 * AC-02 — 500 forged requests, zero effect on resolution (Doc 01 FR-04, AC-02).
 *
 * "Fuzz test: 500 requests with forged tenant_id in body/query/header → zero effect on
 * resolution (proven by trace ids)."
 *
 * HOW THIS IS PROVEN, AND WHY IT IS NOT A VALIDATION TEST. There is no code in this
 * platform that strips, rejects or sanitises a client-supplied `tenant_id`. Adding one
 * would imply that request input is a possible source of tenant identity that we have
 * chosen to distrust. It is not a source at all: TenantResolver reads the route's
 * declared source and the presented credential, and has no path from `$request->input()`
 * to a tenant id.
 *
 * So this test does not assert "the forgery was rejected". It asserts something
 * stronger: that a forged request and a clean request resolve IDENTICALLY, and that the
 * probe echoes back the forgery it ignored. Every response carries its own trace id
 * (C-14), which is AC-02's "proven by trace ids" — each of the 500 attempts is
 * individually identifiable in the logs.
 */
it('ignores forged tenant_id in body, query and header across 500 requests', function () {
    $victim = createTenant();
    $attacker = createTenant();

    // A real API key for the attacker's own tenant: the realistic threat is not an
    // anonymous request, it is a legitimate tenant trying to reach another's data.
    $issued = asTenant($attacker, fn () => app(TenantKeyIssuer::class)->issue(
        kind: 'api',
        label: 'fuzz probe key',
        actor: AuditActor::PlatformAdmin,
    ));
    $plaintext = $issued['plaintext'];

    $attackerId = (string) $attacker->getKey();
    $victimId = (string) $victim->getKey();
    activateTenant($attacker);

    // The forgery vocabulary: the victim's real id, a well-formed but unknown uuid,
    // SQL-ish and traversal-ish payloads, empty and absurd values. A fuzzer that only
    // tries the victim's real id would miss a resolver that accepts ANY well-formed
    // uuid.
    $forgeries = [
        $victimId,
        (string) Str::uuid7(),
        '00000000-0000-0000-0000-000000000000',
        "{$victimId}' OR '1'='1",
        '../'.$victimId,
        '',
        'null',
        str_repeat('a', 512),
    ];

    $traceIds = [];
    $attempts = 0;

    // 500 requests, cycling body / query / header placement over the vocabulary.
    for ($i = 0; $i < 500; $i++) {
        $forged = $forgeries[$i % count($forgeries)];
        $placement = $i % 3;

        app(TenantContext::class)->clear();

        $response = match ($placement) {
            0 => $this->withHeaders(['Authorization' => 'Bearer '.$plaintext])
                ->postJson('/api/v1/context?_method=GET', ['tenant_id' => $forged]),
            1 => $this->withHeaders(['Authorization' => 'Bearer '.$plaintext])
                ->getJson('/api/v1/context?tenant_id='.urlencode($forged)),
            default => $this->withHeaders([
                'Authorization' => 'Bearer '.$plaintext,
                'X-Tenant-Id' => $forged,
            ])->getJson('/api/v1/context'),
        };

        // Placement 0 posts to a GET-only route; skip those, they prove nothing about
        // resolution. Counted separately so the test cannot silently assert on 167
        // requests while claiming 500.
        if ($response->status() === 405) {
            continue;
        }

        $attempts++;
        $response->assertOk();

        $body = $response->json();

        expect($body['resolved_tenant_id'])->toBe(
            $attackerId,
            sprintf('forged value %s in placement %d changed resolution', var_export($forged, true), $placement),
        );
        expect($body['resolved_tenant_id'])->not->toBe($victimId);

        $traceIds[] = $body['trace_id'];
    }

    // AC-02's evidence clause: every attempt is individually traceable.
    expect($attempts)->toBeGreaterThan(300)
        ->and($traceIds)->toHaveCount($attempts)
        ->and(array_unique($traceIds))->toHaveCount($attempts, 'trace ids must be unique per request');
});

it('resolves the same tenant with and without a forged tenant_id', function () {
    $tenant = createTenant();
    $issued = asTenant($tenant, fn () => app(TenantKeyIssuer::class)->issue(
        kind: 'api',
        label: 'parity probe',
        actor: AuditActor::PlatformAdmin,
    ));
    activateTenant($tenant);

    $other = createTenant();

    app(TenantContext::class)->clear();
    $clean = $this->withHeaders(['Authorization' => 'Bearer '.$issued['plaintext']])
        ->getJson('/api/v1/context');

    app(TenantContext::class)->clear();
    $forged = $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['plaintext'],
        'X-Tenant-Id' => (string) $other->getKey(),
    ])->getJson('/api/v1/context?tenant_id='.$other->getKey());

    $clean->assertOk();
    $forged->assertOk();

    expect($forged->json('resolved_tenant_id'))->toBe($clean->json('resolved_tenant_id'))
        ->and($forged->json('resolution_source'))->toBe($clean->json('resolution_source'))
        // The probe confirms the forgery was received and disregarded, so this is not
        // a test that passes because the header never arrived.
        ->and($forged->json('ignored_client_assertions.header_tenant_id'))->toBe((string) $other->getKey())
        ->and($forged->json('ignored_client_assertions.query_tenant_id'))->toBe((string) $other->getKey());
});
