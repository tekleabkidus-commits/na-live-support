<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Doc 13 T-08 / FR-19 Health — beacons increment the widget's per-day
 * counters (C2: the day bucket is computed in SQL from the DB clock at the
 * tenant's display timezone — asserted at a fixture wall-clock boundary), a
 * blocked-origin beacon lists the origin (display-only, bounded), a sampled
 * panel stack is SCRUBBED, an unknown kind is a silent 204 (NFR-06), and no
 * visitor session is needed (the loader beacons before any session exists).
 */
it('T-08: load/open/blocked-origin/mount-fail beacons increment the day\'s counters in SQL; the blocked origin is listed; an unknown kind is a silent 204; no session is needed', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    resetWidgetClient();
    $post = fn (array $body) => test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => WIDGET_ORIGIN])->postJson('/api/widget/v1/beacon', $body);

    $post(['kind' => 'load'])->assertStatus(204);
    $post(['kind' => 'load'])->assertStatus(204);
    $post(['kind' => 'open'])->assertStatus(204);
    $post(['kind' => 'mount_fail', 'reason' => 'csp'])->assertStatus(204);
    $post(['kind' => 'double_install'])->assertStatus(204);
    $post(['kind' => 'not-a-kind'])->assertStatus(204);
    test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => 'https://evil-brand.example'])->postJson('/api/widget/v1/beacon', ['kind' => 'blocked_origin'])->assertStatus(204);

    bindTenant($tenant);
    $row = DB::table('widget_health_counters')->where('widget_id', $widget->getKey())->sole();
    expect((int) $row->loads)->toBe(2)->and((int) $row->opens)->toBe(1)->and((int) $row->mount_fail_csp)->toBe(1)
        ->and((int) $row->double_install)->toBe(1)->and((int) $row->blocked_origin_attempts)->toBe(1);
    $blocked = json_decode((string) $row->blocked_origins, true);
    expect($blocked[0]['origin'])->toBe('https://evil-brand.example')->and($blocked[0]['count'])->toBe(1);

    // C2: the day bucket is the DB clock's date at the tenant's display timezone (fixture: Africa/Addis_Ababa, UTC+3).
    $expected = DB::selectOne("SELECT (clock_timestamp() AT TIME ZONE 'Africa/Addis_Ababa')::date AS d")->d;
    expect($row->day)->toBe($expected);
})->group('widget');

it('T-08: a panel error beacon with a stack is SCRUBBED before sampling; the sample never carries a phone or an email', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    resetWidgetClient();
    bindTenant($tenant);
    app(App\Support\Settings\SettingsRepository::class)->set('widget.beacon.sample_rate', '1', App\Support\Audit\AuditActor::PlatformAdmin, null, null, false);
    resetWidgetClient();

    test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => WIDGET_ORIGIN])->postJson('/api/widget/v1/beacon', [
        'kind' => 'panel_error', 'reason' => 'render', 'bundle_hash' => 'abc123',
        'sample' => 'TypeError at Thread.tsx:41 — visitor said call me on +251900001234 or mail fixture@fixture.example',
    ])->assertStatus(204);

    bindTenant($tenant);
    expect((int) DB::table('widget_health_counters')->where('widget_id', $widget->getKey())->value('errors'))->toBe(1);
    $sample = DB::table('widget_beacon_samples')->where('widget_id', $widget->getKey())->value('sample');
    expect($sample)->not->toBeNull()->not->toContain('+251900001234')->not->toContain('fixture@fixture.example')->toContain('TypeError');
})->group('widget');
