<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Stage 2 boot assertion — the framework boots on a clean runner.
|------------------------------------------------------------------------------
|
| This replaces Laravel's scaffolded Feature\ExampleTest, which GET '/' and so
| rendered the Inertia root view -> @vite -> public/build/manifest.json. Stage 2
| installs no Node and builds no assets, so it 500'd on every clean runner
| (CI run 30307818550) while passing locally, where the container has built
| assets. A unit stage must not depend on a frontend build.
|
| /up is the framework health route (bootstrap/app.php: health: '/up'). It
| touches no Vite manifest, no database and no Redis, so it proves exactly what
| this stage should prove: the application boots and routes a request.
|
| The full Inertia render stays proven downstream — Stage 3 runs the integration
| suite against the real stack and Stage 4 drives the rendered page in Playwright.
|
*/

test('the application boots and answers the health route', function () {
    $this->get('/up')->assertStatus(200);
});
