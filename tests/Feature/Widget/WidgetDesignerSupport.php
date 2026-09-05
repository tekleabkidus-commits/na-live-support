<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;

/** A tenant with an activated staff session and a Designer actor holding the given keys. */
function designerScene(array $permissions = ['webchat.widgets.view', 'webchat.widgets.configure']): array
{
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);
    $user = ticketActor($permissions, 'Fixture Designer Role');
    bindTenant($tenant);
    $GLOBALS['__designer_tenant'] = $tenant;

    return [$tenant, $user];
}

function designer(User $user, string $method, string $path, array $body = []): TestResponse
{
    $url = 'https://localhost/admin/widgets'.$path;
    $request = test()->actingAs($user, 'staff');

    $response = match ($method) {
        'GET' => $request->getJson($url),
        'POST' => $request->postJson($url, $body),
        'PUT' => $request->putJson($url, $body),
        default => $request->patchJson($url, $body),
    };
    // The request cycle clears the test's tenant binding (the KB scene's own rule): re-bind for the assertions that follow.
    if (isset($GLOBALS['__designer_tenant'])) {
        bindTenant($GLOBALS['__designer_tenant']);
    }

    return $response;
}

/** A publishable config: the neutral defaults with one welcome line. */
function designerConfig(array $overrides = []): array
{
    return array_replace_recursive(['content' => ['welcome' => ['en' => '[FIXTURE] Hello 👋']]], $overrides);
}
