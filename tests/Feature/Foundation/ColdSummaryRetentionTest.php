<?php

declare(strict_types=1);

use App\Domain\Foundation\Retention\ColdSummaryStore;
use App\Domain\Foundation\Retention\InboundAccrual;
use App\Domain\Foundation\Retention\InboundAccrualPolicy;
use App\Domain\Foundation\Tenancy\TenantLifecycle;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\Tenant;
use App\Support\Audit\AuditActor;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * BR-05 — the retention path: TenantLifecycle stamps the facts, InboundAccrualPolicy
 * decides from them with a CALLER-SUPPLIED clock (no test asserts on now(); every
 * instant below is derived from the suspended_at the lifecycle wrote), and
 * ColdSummaryStore keeps exactly "count + last message pointer".
 *
 * The ACK-to-channel clause is deliberately absent: no channel exists until Doc 04,
 * which must prove ACK-but-summarize against this frozen contract (inherited task in
 * BUILD-STATUS). BR-05 therefore stays PARTIAL even with this file green.
 */
function coldFrom(Tenant $tenant, int $days): CarbonImmutable
{
    $suspendedAt = $tenant->refresh()->getAttribute('suspended_at');

    expect($suspendedAt)->not->toBeNull();

    return CarbonImmutable::instance($suspendedAt)->addDays($days);
}

it('stamps every lifecycle timestamp from its single writer, in DB time', function () {
    $tenant = createTenant();
    expect($tenant->getAttribute('provisioned_at'))->toBeNull();

    activateTenant($tenant);
    $tenant->refresh();
    expect($tenant->getAttribute('provisioned_at'))->not->toBeNull()
        ->and($tenant->getAttribute('suspended_at'))->toBeNull();

    suspendTenant($tenant);
    $tenant->refresh();
    expect($tenant->getAttribute('suspended_at'))->not->toBeNull();

    // Reactivation CLEARS suspended_at: the next suspension starts a fresh window.
    activateTenant($tenant);
    $tenant->refresh();
    expect($tenant->getAttribute('suspended_at'))->toBeNull();

    asTenant($tenant, fn (): Tenant => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Archived,
        actor: AuditActor::PlatformAdmin,
        actorLabel: 'br05 fixture',
        reason: 'test fixture',
    ));
    expect($tenant->refresh()->getAttribute('archived_at'))->not->toBeNull();
});

it('accrues in full inside the retention window and cold beyond it (BR-05)', function () {
    $tenant = suspendTenant(activateTenant(createTenant()));

    asTenant($tenant, function () use ($tenant): void {
        $policy = app(InboundAccrualPolicy::class);
        $edge = coldFrom($tenant, 30); // the registered default

        // One second before the boundary, and the boundary instant itself: full.
        expect($policy->modeFor($tenant, $edge->subSecond()))->toBe(InboundAccrual::Full)
            ->and($policy->modeFor($tenant, $edge))->toBe(InboundAccrual::Full)
            // Past it: cold.
            ->and($policy->modeFor($tenant, $edge->addSecond()))->toBe(InboundAccrual::ColdSummary);
    });
});

it('moves the boundary when the platform changes the retention setting (BR-05: "configurable at platform level")', function () {
    $tenant = suspendTenant(activateTenant(createTenant()));

    asTenant($tenant, function () use ($tenant): void {
        app(SettingsRepository::class)->set(
            key: 'tenant.suspended_inbound_retention_days',
            value: 5,
            actor: AuditActor::System,
            tenantSurface: false,
        );

        $policy = app(InboundAccrualPolicy::class);
        $edge = coldFrom($tenant, 5);

        expect($policy->modeFor($tenant, $edge->subSecond()))->toBe(InboundAccrual::Full)
            ->and($policy->modeFor($tenant, $edge->addSecond()))->toBe(InboundAccrual::ColdSummary);
    });
});

it('never cold-summarises a tenant that is not suspended, however old the timestamp', function () {
    $tenant = activateTenant(createTenant());

    asTenant($tenant, function () use ($tenant): void {
        // An active tenant, asked about a moment years away: always full.
        $farFuture = CarbonImmutable::instance($tenant->refresh()->getAttribute('created_at'))->addYears(5);

        expect(app(InboundAccrualPolicy::class)->modeFor($tenant, $farFuture))->toBe(InboundAccrual::Full);
    });
});

it('resolves a suspended tenant with NO suspended_at to full accrual, loudly — discarding on missing evidence inverts US-02', function () {
    $tenant = suspendTenant(activateTenant(createTenant()));

    // The should-be-impossible state: suspended, timestamp gone.
    $tenant->forceFill(['suspended_at' => null])->save();

    asTenant($tenant, function () use ($tenant): void {
        $anyTime = CarbonImmutable::instance($tenant->refresh()->getAttribute('created_at'))->addYears(5);

        expect(app(InboundAccrualPolicy::class)->modeFor($tenant, $anyTime))->toBe(InboundAccrual::Full);
    });
});

it('keeps exactly count + last pointer, per channel, atomically (BR-05)', function () {
    $tenant = suspendTenant(activateTenant(createTenant()));

    asTenant($tenant, function (): void {
        $store = app(ColdSummaryStore::class);

        $store->record('telegram', 'tg-msg-100');
        $store->record('telegram', 'tg-msg-101');
        $store->record('webchat', 'wc-msg-1');

        $telegram = $store->summaryFor('telegram');
        expect($telegram)->not->toBeNull()
            ->and((int) $telegram->message_count)->toBe(2)
            // The POINTER is replaced, not accumulated — a summary, not a log.
            ->and($telegram->last_message_pointer)->toBe('tg-msg-101');

        $webchat = $store->summaryFor('webchat');
        expect((int) $webchat->message_count)->toBe(1)
            ->and($store->summaryFor('sms'))->toBeNull();
    });
});

it('refuses to record with no tenant bound', function () {
    app(TenantContext::class)->clear();

    expect(fn () => app(ColdSummaryStore::class)->record('telegram', 'tg-msg-1'))
        ->toThrow(MissingTenantContext::class);
});
