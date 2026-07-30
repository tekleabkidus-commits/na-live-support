<?php

declare(strict_types=1);

use App\Http\Controllers\Foundation\IngressProbeController;
use App\Http\Controllers\Foundation\TenantSettingsController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Foundation routes (Doc 01)
|------------------------------------------------------------------------------
|
| WHAT THESE ARE, STATED PLAINLY SO THEY ARE NOT MISTAKEN FOR PRODUCT SURFACE.
|
| Doc 01 owns no user-facing features. It owns the CONTRACT that Docs 04–17 consume:
| resolution, state enforcement, settings, quotas. But AC-01, AC-02 and AC-08 are all
| stated in terms of HTTP — "via every public endpoint touching that table", "500
| requests with forged tenant_id", "every entry surface (staff login, API, webhook,
| widget, DLR)". Without endpoints those criteria cannot be executed at all, and an
| untestable acceptance criterion is an unmet one.
|
| So each of the five key-bearing surfaces gets a minimal ingress probe: it resolves a
| tenant, enforces FR-05, and reports what it resolved. The real Telegram webhook is
| Doc 05's, the real widget endpoint Doc 13's, the real DLR Doc 14's — each replaces
| its probe with a genuine handler on the SAME middleware stack, which is exactly the
| contract Doc 01 is supposed to hand them.
|
| The settings endpoints are real (FR-07's settings.read/write API) and stay.
|
*/

// NOTE: no `->prefix('api')` here. bootstrap/app.php's withRouting(api: …) already
// applies the `api` prefix to this file, and adding another produced routes at
// `api/api/v1/context` — which 404'd, and the 404 was indistinguishable from
// SEC-06's deliberate 404-on-failed-resolution. That is worth remembering: when
// every resolution failure is a 404 by design, a routing mistake wears the same
// mask, so check `route:list` before assuming the resolver is at fault.
Route::group([], function (): void {

    // ── Staff surface (FR-03a). Suspended blocks it entirely (FR-05, EC-03).
    Route::middleware(['tenant:staff_session', 'tenant.state:staff'])
        ->prefix('staff')
        ->group(function (): void {
            Route::get('context', [IngressProbeController::class, 'show'])->name('foundation.staff.context');

            // FR-07's settings.read/write API. DELETE is "reset to default" (§8),
            // which is a deletion of the override row rather than a write of the
            // default — see SettingsRepository::reset().
            Route::get('settings', [TenantSettingsController::class, 'index'])->name('foundation.settings.index');
            Route::get('settings/{key}', [TenantSettingsController::class, 'show'])->name('foundation.settings.show');
            Route::put('settings/{key}', [TenantSettingsController::class, 'update'])
                ->middleware('audit:tenant.setting_changed')
                ->name('foundation.settings.update');
            Route::delete('settings/{key}', [TenantSettingsController::class, 'destroy'])
                ->middleware('audit:tenant.setting_changed')
                ->name('foundation.settings.reset');
        });

    // ── Public API surface (FR-03b). Outbound-capable, so suspension blocks it.
    Route::middleware(['tenant:api_key', 'tenant.state:outbound'])
        ->prefix('v1')
        ->group(function (): void {
            Route::get('context', [IngressProbeController::class, 'show'])->name('foundation.api.context');
        });

    /*
     | ── The three INBOUND surfaces (FR-03c/d/e).
     |
     | All three use `tenant.state:inbound`, which permits a SUSPENDED tenant. That is
     | not an oversight — it is the subtlest requirement in FR-05 and the one most
     | likely to be "fixed" by someone who reads it as a bug. A suspended operator is
     | usually a payment problem, not a closed business, and US-02 says reactivation
     | must lose nothing. So a player's message is still accepted and queued while the
     | operator is suspended; what stops is anything going back out.
     */
    Route::middleware(['tenant:telegram_bot_key', 'tenant.state:inbound'])
        ->post('webhooks/telegram', [IngressProbeController::class, 'ingest'])
        ->name('foundation.webhook.telegram');

    Route::middleware(['tenant:widget_key', 'tenant.state:inbound'])
        ->post('widget/session', [IngressProbeController::class, 'ingest'])
        ->name('foundation.widget.session');

    Route::middleware(['tenant:sms_gateway_key', 'tenant.state:inbound'])
        ->post('webhooks/sms/dlr', [IngressProbeController::class, 'ingest'])
        ->name('foundation.webhook.sms_dlr');
});
