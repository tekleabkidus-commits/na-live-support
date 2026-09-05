<?php

declare(strict_types=1);

use App\Domain\Webchat\Health\WidgetHealth;
use App\Models\Widget;

require_once __DIR__.'/WidgetDesignerSupport.php';

/*
|--------------------------------------------------------------------------
| Doc 13 FR-19 Health / T-08 / BR-07 — the Health tab's reads: 7-day counters,
| the blocked-origins list, invalid signatures, the active bundle + rollout,
| the two drawn diagnoses (CSP-blocked mounts, the double install), test mode.
|--------------------------------------------------------------------------
*/

it('sums the week, lists blocked origins, counts the two diagnoses, names the bundle and the config version', function (): void {
    [, $user] = designerScene();
    $id = designer($user, 'POST', '', ['name' => '[FIXTURE] W'])->json('id');
    $widget = Widget::query()->findOrFail($id);
    $health = app(WidgetHealth::class);
    foreach (range(1, 5) as $i) {
        $health->beacon($widget, 'load', null, null, null, null);
    }
    $health->beacon($widget, 'open', null, null, null, null);
    $health->beacon($widget, 'mount_fail', 'csp', null, null, null);
    $health->beacon($widget, 'mount_fail', 'other', null, null, null);
    $health->beacon($widget, 'double_install', null, null, null, null);
    $health->beacon($widget, 'blocked_origin', null, null, null, 'https://promo-partner.example');
    $health->beacon($widget, 'blocked_origin', null, null, null, 'https://promo-partner.example');
    $health->beacon($widget, 'blocked_origin', null, null, null, 'https://bet-mirror.example');
    designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig()])->assertStatus(201);
    designer($user, 'PATCH', '/'.$id, ['test_mode' => true])->assertOk();

    $read = designer($user, 'GET', '/'.$id.'/health')->assertOk();
    expect($read->json('days'))->toBe(7)
        ->and($read->json('counters.loads'))->toBe(5)->and($read->json('counters.opens'))->toBe(1)
        ->and($read->json('counters.errors'))->toBe(1, 'a non-CSP mount failure is an error')
        ->and($read->json('counters.blocked_origin_attempts'))->toBe(3)
        ->and($read->json('diagnoses.csp_blocked.count'))->toBe(1)->and($read->json('diagnoses.double_install.count'))->toBe(1)
        ->and(collect($read->json('blocked_origins'))->pluck('origin')->all())->toContain('https://promo-partner.example')
        ->and($read->json('bundle.version'))->toBeString()->and($read->json('bundle.rollout_pct'))->toBe(100)->and($read->json('bundle.channel'))->toBe('stable')
        ->and($read->json('config_version'))->toBe(1)->and($read->json('test_mode'))->toBeTrue();
});
