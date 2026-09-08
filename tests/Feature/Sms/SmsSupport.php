<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Doc 14 test scene helpers (the WidgetDesignerSupport shape): a tenant with an
 * activated staff session and an actor holding the given `sms.*` keys; the
 * JSON client for `/admin/sms/api/...`; the tenant re-bound after each request
 * cycle (the KB scene's own rule).
 */
function smsScene(array $permissions = ['sms.templates.view', 'sms.templates.create', 'sms.templates.edit', 'sms.templates.delete']): array
{
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);
    $user = ticketActor($permissions, 'Fixture SMS Role');
    bindTenant($tenant);
    $GLOBALS['__sms_tenant'] = $tenant;

    return [$tenant, $user];
}

function smsApi(User $user, string $method, string $path, array $body = []): TestResponse
{
    $url = 'https://localhost/admin/sms/api'.$path;
    $request = test()->actingAs($user, 'staff');

    $response = match ($method) {
        'GET' => $request->getJson($url),
        'POST' => $request->postJson($url, $body),
        'PUT' => $request->putJson($url, $body),
        'DELETE' => $request->deleteJson($url, $body),
        default => $request->patchJson($url, $body),
    };
    if (isset($GLOBALS['__sms_tenant'])) {
        bindTenant($GLOBALS['__sms_tenant']);
    }

    return $response;
}
