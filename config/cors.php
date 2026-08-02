<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| CORS (Doc 02 T-06) — the framework's blanket handler is OFF, deliberately.
|------------------------------------------------------------------------------
|
| Laravel ships Illuminate\Http\Middleware\HandleCors in the global stack with
| a DEFAULT config of `paths: ['api/*'], allowed_origins: ['*']` when this file
| is absent — which step 10 discovered the hard way: every api/* response,
| staff surface included, carried `Access-Control-Allow-Origin: *`, and the
| wildcard OVERRODE the widget surface's per-widget stamp. A blanket grant is
| the exact opposite of T-06:
|
|   staff app   same-origin — needs NO cors headers, and offering `*` invites
|               any page on the web to read staff API responses through a
|               logged-in browser (the session cookie is SameSite=Lax, but
|               defense should not lean on one attribute);
|   widget      CORS-allowlisted PER WIDGET CONFIG — App\Http\Middleware\
|               WidgetCors stamps exactly the requesting origin, exactly when
|               the resolved widget's allowlist admits it (AC-06's cors layer);
|   bearer API  consumed by servers, not browsers — CSRF-exempt by
|               statelessness, and no cors grant belongs on it.
|
| So: no paths, no origins, nothing for HandleCors to do. Widget cors lives in
| WidgetCors, where the allowlist is. Do not "fix" an integration's cross-
| origin problem by widening this file — that is a T-06 change and needs its
| owning document reopened.
|
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
