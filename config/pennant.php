<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Pennant — deliberately on the in-memory store for now (Doc 01 FR-11)
|------------------------------------------------------------------------------
|
| The 'array' store resolves every flag to its registered default, per process.
| That is all Doc 01 needs: FR-11 fixes the REGISTRATION and RESOLUTION
| contract (registries/features.php + FeatureRegistry, tenant-scoped), while
| the toggle surface belongs to Doc 16.
|
| WHY NOT THE DATABASE STORE TODAY: Pennant's stock `features` table keys rows
| by a free-form `scope` string and carries no tenant_id — which violates C-02
| (every tenant-scoped table has non-null tenant_id) and would sit outside RLS.
| Doc 16 must ship a tenant-scoped store (custom driver or amended schema +
| policy) before any toggle persists. Recorded in docs/BUILD-STATUS.md as
| Doc 16's inherited task; flipping this to 'database' without that work would
| put per-tenant state in an unpoliced table.
|
*/

return [
    'default' => env('PENNANT_STORE', 'array'),

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],
    ],
];
