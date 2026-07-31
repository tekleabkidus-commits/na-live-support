<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Authentication surface (Doc 02; required from routes/web.php)
|------------------------------------------------------------------------------
|
| EVERY auth route is registered here BY HAND — Fortify auto-registration is
| permanently off (FortifyServiceProvider). The rule, inherited from Doc 01 and
| made policy by Doc 02: a route ships in the same step as its proven flow,
| carrying its full middleware chain in T-02's order
|
|     tenant-resolve → guard → tenant-state → 2FA-enforcement → RBAC
|
| plus its `audit:<action>` declaration (mutating routes) and its
| `permission:<key>` mapping or ci/permission-exempt-routes.php entry. The
| RouteRegistrationTest mirrors and the CI gates both enforce this shape.
|
| This file is EMPTY at the skeleton step by design: no auth flow is proven yet,
| so no auth route exists yet. Each Doc 02 step appends its own surface:
|
|   login step      POST /login, POST /logout
|   session step    devices list/revoke
|   2FA step        two-factor challenge / enrolment / recovery / admin reset
|   password step   forgot / reset / invited set-password
|   token step      personal + machine token management
|
| A visitor hitting an unregistered path gets a 404 — which is honest. The
| alternative (a live login form whose backing flow is unbuilt) is the Doc 01
| defect this file exists to prevent.
|
*/
