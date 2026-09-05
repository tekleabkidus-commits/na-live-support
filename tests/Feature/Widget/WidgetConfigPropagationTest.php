<?php

declare(strict_types=1);

use App\Events\Webchat\WidgetConfigPush;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/WidgetDesignerSupport.php';

/*
|--------------------------------------------------------------------------
| Doc 13 FR-20 / NFR-04 (≤ 60 s): a publish pushes a version-only frame on the
| PUBLIC per-widget config channel in the same request (open panels re-read the
| door); a fresh load reads the shim whose public cache is ≤ 60 s; the poll floor
| (config_poll_seconds) rides the envelope. The bound is asserted with the clock
| figures the code declares, not a sleep.
|--------------------------------------------------------------------------
*/

it('publish and rollback each dispatch the version-only frame on widget.{id}.config, a PUBLIC channel, carrying no config and no tenant id', function (): void {
    Event::fake([WidgetConfigPush::class]);
    [, $user] = designerScene();
    $id = designer($user, 'POST', '', ['name' => '[FIXTURE] W'])->json('id');
    $v1 = designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig()])->assertStatus(201);
    designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig(['appearance' => ['theme' => 'dark']])])->assertStatus(201);
    designer($user, 'POST', '/'.$id.'/config/rollback', ['version_id' => $v1->json('version_id')])->assertStatus(201);
    Event::assertDispatchedTimes(WidgetConfigPush::class, 3);
    Event::assertDispatched(WidgetConfigPush::class, function (WidgetConfigPush $e) use ($id): bool {
        $channel = $e->broadcastOn();
        $payload = json_encode($e->broadcastWith(), JSON_THROW_ON_ERROR);

        return $channel->name === 'widget.'.$id.'.config'
            && ! str_starts_with($channel->name, 'private-')
            && $e->broadcastAs() === 'widget.config.published'
            && ! str_contains($payload, 'welcome') && ! str_contains($payload, 'tenant')
            && $e->version >= 1;
    });
});

it('NFR-04 with the clock figures: the shim cache is ≤ 60 s and the envelope carries the poll floor — push (0 s) + shim (≤ 60 s) bounds propagation at 60 s', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    activateTenant($tenant);
    bindTenant($tenant);
    $shim = test()->get('https://localhost/w/'.$key.'.js'); // the snippet carries the FULL widget key (the shim resolves by it)
    $shim->assertOk();
    preg_match('/max-age=(\d+)/', (string) $shim->headers->get('Cache-Control'), $m);
    expect((int) ($m[1] ?? PHP_INT_MAX))->toBeLessThanOrEqual(60);
    $session = widgetSession($key)->assertCreated();
    $cookie = visitorCookieFrom($session);
    resetWidgetClient();
    $envelope = test()->withHeaders(['Authorization' => 'Bearer '.$key, 'Origin' => WIDGET_ORIGIN, 'X-Visitor-Token' => $session->json('token')])
        ->withUnencryptedCookie('nlw_visitor', $cookie)->withCredentials()->getJson('https://localhost/api/widget/v1/config');
    $envelope->assertOk();
    expect($envelope->json('transport.config_poll_seconds'))->toBeInt()->toBeLessThanOrEqual(300)
        ->and($envelope->json('transport.channels.config'))->toBe('widget.'.$widget->getKey().'.config');
});
