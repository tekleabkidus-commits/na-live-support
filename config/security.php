<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Application security posture (Doc 02 T-07)
|------------------------------------------------------------------------------
|
| Platform configuration, not tenant settings: a tenant must not be able to
| weaken the CSP or turn a header off, so none of this goes through the
| settings registry. Values that genuinely vary per deployment (the Reverb
| websocket origin) come from the environment.
|
*/

return [

    'csp' => [

        // Origins the browser may open connections to beyond 'self'. Reverb runs
        // on its own port, so realtime needs its origin here.
        'connect_src' => array_values(array_filter([
            env('SECURITY_CSP_REVERB_ORIGIN'),
        ])),

        // Local development only: the Vite dev server's HTTP and websocket
        // origins. The middleware ignores this list outside APP_ENV=local, so it
        // can never loosen a deployed policy.
        'dev_origins' => array_values(array_filter(
            explode(',', (string) env('SECURITY_CSP_DEV_ORIGINS', '')),
        )),

    ],

];
