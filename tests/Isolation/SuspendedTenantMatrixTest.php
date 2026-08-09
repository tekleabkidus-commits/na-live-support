<?php

declare(strict_types=1);

use App\Domain\Foundation\Tenancy\TenantLifecycle;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantKeyIssuer;
use Illuminate\Support\Facades\DB;

/**
 * AC-08 — the suspended-tenant matrix (Doc 01 AC-08, FR-05, EC-03, US-02, BR-05).
 *
 * "Suspended-tenant matrix: every entry surface (staff login, API, webhook, widget,
 * DLR) behaves per FR-05."
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * THE ASYMMETRY IS THE REQUIREMENT, AND IT LOOKS LIKE A BUG.
 *
 * FR-05 on suspension: "staff logins blocked, inbound channel traffic accepted and
 * queued (nothing lost) but no outbound sends".
 *
 * So a suspended tenant ACCEPTS a player's Telegram message and REFUSES to send
 * anything back. Read quickly, "suspended but still accepting traffic" reads like a
 * hole someone forgot to close — and closing it would be the most natural-looking
 * "fix" in this entire specification. It would also be a serious defect: US-02 says
 * the owner suspends a tenant (usually non-payment) "knowing inbound player messages
 * are still being captured, so reactivation loses nothing". Drop them and a player who
 * did nothing wrong loses their question, and the operator has no idea it happened.
 *
 * This test exists so that closing that gap fails the build. Anyone who "tightens"
 * suspension to reject inbound traffic gets a red row here with this comment attached.
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * Four states × five surfaces, driven from a data table so the matrix is readable as a
 * matrix. Every cell is asserted, including the ones that pass trivially — a matrix
 * with holes in it is not a matrix.
 */

/**
 * The five entry surfaces, as (route, method, resolution source) triples.
 *
 * @return array<string, array{uri: string, method: string, kind: string|null}>
 */
function ac08Surfaces(): array
{
    return [
        'staff' => ['uri' => '/api/staff/context', 'method' => 'GET', 'kind' => null],
        'api' => ['uri' => '/api/v1/context', 'method' => 'GET', 'kind' => 'api'],
        // Since Doc 02 step 11 the webhook probes demand the SECOND
        // credential (FR-23/FR-24) — static header for telegram, per-request
        // HMAC for the DLR (computed in the loop; a signature cannot be a
        // static table entry). Expectations are UNCHANGED: the second
        // credential sits before tenant.state, so a suspended tenant still
        // answers 202 once the request proves it is really the channel.
        'webhook_telegram' => [
            'uri' => '/api/webhooks/telegram',
            'method' => 'POST',
            'kind' => 'telegram_bot',
            'headers' => ['X-Telegram-Bot-Api-Secret-Token' => 'fixture-telegram-webhook-secret'],
        ],
        // Since Doc 02 step 10 this is the REAL session issuer, not the Doc 01
        // probe — so the cell needs a genuine allowlisted Origin (the fixture
        // widget row is seeded alongside the key below), and "accepted" is
        // 201 issued-a-visitor-session rather than the probe's 202.
        'widget' => [
            'uri' => '/api/widget/v1/session',
            'method' => 'POST',
            'kind' => 'widget',
            'headers' => ['Origin' => 'https://fixture-widget-host.example'],
        ],
        'webhook_sms_dlr' => [
            'uri' => '/api/webhooks/sms/dlr',
            'method' => 'POST',
            'kind' => 'sms_gateway',
            'hmac' => true,
        ],
    ];
}

/**
 * FR-05, as a table. Values are the expected HTTP status.
 *
 *   200/202 — permitted (202 for the inbound surfaces: "accepted and queued")
 *   403     — refused by the tenant-state matrix, with a banner key
 *   404     — did not resolve at all (archived tenants have every key revoked, so
 *             SEC-06's 404 applies before any state check is reached)
 *
 * @return array<string, array<string, int>>
 */
function ac08Expected(): array
{
    return [
        // Nothing is available until provisioning finishes. §8 shows a progress
        // screen rather than a working workspace, and FS-04 forbids a
        // partially-active tenant serving traffic.
        'provisioning' => [
            'staff' => 403,
            'api' => 403,
            'webhook_telegram' => 403,
            'widget' => 403,
            'webhook_sms_dlr' => 403,
        ],
        'active' => [
            'staff' => 200,
            'api' => 200,
            'webhook_telegram' => 202,
            // 201 since Doc 02 step 10: the real FR-21 handler ISSUES a
            // visitor session where the probe merely acknowledged (202).
            'widget' => 201,
            'webhook_sms_dlr' => 202,
        ],
        // THE ROW THAT MATTERS. Staff out, outbound-capable API out, all three
        // inbound surfaces still accepting. See the file docblock. For the
        // widget that now means a suspended operator's VISITORS still get
        // their session identity (201) — their history survives suspension,
        // which is US-02's "reactivation loses nothing" for web chat.
        'suspended' => [
            'staff' => 403,
            'api' => 403,
            'webhook_telegram' => 202,
            'widget' => 201,
            'webhook_sms_dlr' => 202,
        ],
        // Archived revokes every key (FR-05), so the four key-bearing surfaces stop
        // resolving entirely. Staff still has a session, so it reaches the state
        // check and is refused there.
        'archived' => [
            'staff' => 403,
            'api' => 404,
            'webhook_telegram' => 404,
            'widget' => 404,
            'webhook_sms_dlr' => 404,
        ],
    ];
}

it('behaves exactly per FR-05 on every entry surface, in every tenant state', function () {
    $results = [];

    foreach (ac08Expected() as $stateName => $expectations) {
        $state = TenantState::from($stateName);

        // A fresh tenant per state: transitions are one-way and archived is terminal.
        $tenant = createTenant();

        // Issue every key BEFORE the state change, so the archived case genuinely
        // exercises revocation rather than never having had a key.
        $plaintexts = asTenant($tenant, function (): array {
            $issuer = app(TenantKeyIssuer::class);
            $out = [];
            foreach (['api', 'telegram_bot', 'widget', 'sms_gateway'] as $kind) {
                $issued = $issuer->issue(
                    kind: $kind,
                    label: 'ac08 '.$kind,
                    actor: AuditActor::PlatformAdmin,
                );
                $out[$kind] = $issued['plaintext'];

                // Doc 02 step 10: the widget surface's real handler refuses a
                // key with no widget record (fail-closed), so the matrix seeds
                // the minimal FR-21 shape its Origin header matches.
                if ($kind === 'widget') {
                    App\Models\Widget::query()->create([
                        'key_id' => $issued['key']->getKey(),
                        'allowed_origins' => ['https://fixture-widget-host.example'],
                    ]);
                }

                // Doc 02 step 11: the webhook surfaces refuse a key with no
                // second credential (fail-closed, AC-07), so the matrix seeds
                // both mechanisms' minimal shapes.
                if ($kind === 'telegram_bot') {
                    App\Models\WebhookCredential::query()->create([
                        'key_id' => $issued['key']->getKey(),
                        'header_secret_hash' => hash('sha256', 'fixture-telegram-webhook-secret'),
                    ]);

                    // Doc 05 S23 step 4: the real telegram handler refuses a
                    // key with no BOT record (fail-closed, same posture as the
                    // widget branch above) — registration became the write
                    // path, so the matrix seeds the row the wizard writes.
                    App\Models\TelegramBot::query()->create([
                        'telegram_bot_id' => 8_144_038_606,
                        'username' => 'fixture_matrix_bot',
                        'display_name' => 'Fixture Matrix Bot',
                        'mode' => 'direct',
                        'key_id' => $issued['key']->getKey(),
                    ]);
                }

                if ($kind === 'sms_gateway') {
                    App\Models\WebhookCredential::query()->create([
                        'key_id' => $issued['key']->getKey(),
                        'hmac_secret' => 'fixture-dlr-hmac-secret',
                    ]);
                }
            }

            return $out;
        });

        $staffUser = asTenant($tenant, fn (): User => User::factory()->create());

        ac08MoveTo($tenant, $state);

        foreach (ac08Surfaces() as $surface => $spec) {
            // BOTH layers cleared, not just the context. An earlier version cleared only
            // TenantContext, which left `app.tenant_id` populated from the fixture — and
            // the staff cells then passed because the resolver's tenant-state read
            // succeeded on leftover session state rather than through the pre-context
            // lookup a real request uses. A test that depends on ambient database state can
            // be green while production is broken; this one was, briefly.
            app(TenantContext::class)->clear();
            app(App\Support\Tenancy\RlsSession::class)->clear();

            // FR-24's signature covers timestamp + nonce + raw body, so the
            // DLR cell computes fresh headers per request (the body Laravel
            // sends for json($method, $uri, []) is "[]", and that exact
            // string is what gets signed).
            $extraHeaders = $spec['headers'] ?? [];
            if ($spec['hmac'] ?? false) {
                $timestamp = (string) now()->getTimestamp();
                $nonce = (string) Illuminate\Support\Str::uuid7();
                $extraHeaders = [
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Nonce' => $nonce,
                    'X-Webhook-Signature' => hash_hmac(
                        'sha256',
                        sprintf('%s.%s.%s', $timestamp, $nonce, '[]'),
                        'fixture-dlr-hmac-secret',
                    ),
                ];
            }

            $response = $spec['kind'] === null
                ? $this->actingAs($staffUser)->json($spec['method'], $spec['uri'])
                : $this->withHeaders(array_merge(
                    ['Authorization' => 'Bearer '.$plaintexts[$spec['kind']]],
                    $extraHeaders,
                ))->json($spec['method'], $spec['uri']);

            $results[$stateName][$surface] = $response->status();

            expect($response->status())->toBe(
                $expectations[$surface],
                sprintf(
                    'FR-05 violated: a %s tenant on the %s surface returned %d, expected %d. %s',
                    $stateName,
                    $surface,
                    $response->status(),
                    $expectations[$surface],
                    $stateName === 'suspended' && str_contains($surface, 'webhook') || $stateName === 'suspended' && $surface === 'widget'
                        ? 'A SUSPENDED TENANT MUST STILL ACCEPT INBOUND TRAFFIC — US-02: reactivation loses nothing. '
                          .'If you are "fixing" this, read the docblock at the top of this file first.'
                        : '',
                ),
            );
        }
    }

    // The whole matrix, so a failure report shows the shape rather than one cell.
    expect($results)->toBe(ac08Expected());
});

it('shows a non-dismissable banner key when a surface is refused for state', function () {
    $tenant = createTenant();
    $staffUser = asTenant($tenant, fn (): User => User::factory()->create());
    ac08MoveTo($tenant, TenantState::Suspended);

    app(TenantContext::class)->clear();
    $response = $this->actingAs($staffUser)->getJson('/api/staff/context');

    $response->assertStatus(403);

    // FR-05 requires a banner on any permitted surface; §13 requires it announced and
    // not colour-only; N-03/C-08 require it to be an i18n KEY, never literal copy. The
    // API therefore carries the key and the client renders it in the user's language.
    expect($response->json('banner_i18n_key'))->toBe('foundation.tenant.banner.suspended')
        ->and($response->json('tenant_state'))->toBe('suspended')
        // RFC 9457 shape (T-07/C-04), with a stable machine-readable code.
        ->and($response->json('code'))->toBe('tenant_state_forbidden')
        ->and($response->json('trace_id'))->not->toBeEmpty();

    expect(__('foundation.tenant.banner.suspended'))->not->toBe('foundation.tenant.banner.suspended');
});

it('revokes every key when a tenant is archived (FR-05)', function () {
    $tenant = createTenant();

    $issued = asTenant($tenant, fn (): array => app(TenantKeyIssuer::class)->issue(
        kind: 'api',
        label: 'to be revoked',
        actor: AuditActor::PlatformAdmin,
    ));

    ac08MoveTo($tenant, TenantState::Archived);

    asTenant($tenant, function (): void {
        $live = DB::table('tenant_keys')->whereNull('revoked_at')->count();
        expect($live)->toBe(0, 'an archived tenant still has live keys — FR-05 says all keys are revoked');

        // Per-key audit, not one bulk entry: an operator asking "when did my widget
        // stop working" needs a per-key answer.
        $audited = DB::table('audit_log')
            ->where('action_key', 'tenant.key_revoked')
            ->where('actor_type', 'system')
            ->count();
        expect($audited)->toBeGreaterThan(0);
    });

    // And the credential no longer resolves — 404, not 403. A revoked key does not
    // exist as far as an unauthenticated caller can tell (SEC-06).
    app(TenantContext::class)->clear();
    $this->withHeaders(['Authorization' => 'Bearer '.$issued['plaintext']])
        ->getJson('/api/v1/context')
        ->assertNotFound();
});

/**
 * Drive a tenant to a target state through the real lifecycle service, hopping via
 * `active` where FR-05's graph requires it.
 *
 * Not a shortcut: the database trigger rejects any state change that does not carry
 * the lifecycle service's transaction-local flag, so there is no way to teleport a
 * tenant into a state, and a test fixture that could would be proving something
 * production cannot do.
 */
function ac08MoveTo(Tenant $tenant, TenantState $target): void
{
    if ($target === TenantState::Provisioning) {
        return; // where every tenant starts
    }

    asTenant($tenant, function () use ($tenant, $target): void {
        $lifecycle = app(TenantLifecycle::class);

        // provisioning → suspended is legal (EC-04), but provisioning → archived is
        // not; archived is only reachable from active or suspended.
        if ($target === TenantState::Archived) {
            $lifecycle->transition($tenant, TenantState::Active, AuditActor::System, reason: 'ac08 fixture');
        }

        $lifecycle->transition(
            tenant: $tenant,
            target: $target,
            actor: AuditActor::PlatformAdmin,
            actorLabel: 'ac08 fixture',
            reason: 'ac08 fixture',
        );
    });
}
