<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appBooted' => true,
    ]);
});

// The explicit auth surface (Doc 02). See that file's header for the rule it
// enforces; Fortify never auto-registers routes in this application.
require __DIR__.'/auth.php';

// ── Doc 03 §8/§9 — the four B6 RBAC screens (S19 step 9). Pages are gated
// by the same catalog keys as their APIs; mutations go through
// /api/staff/admin/* which carry the full staff middleware chain.
Route::middleware([
    'auth:staff',
    // The same resolution chain as the staff API surface: these pages QUERY
    // tenant-scoped models, so the tenant binds before the controller, the
    // tenant state gates, and the user's locale drives the server-localized
    // copy (I-5/C-08).
    'tenant:staff_session',
    'tenant.state:staff',
    App\Http\Middleware\ApplyUserLocale::class,
])->prefix('admin')->group(function (): void {
    Route::get('roles', [App\Http\Controllers\Admin\RbacScreensController::class, 'roles'])
        ->middleware('permission:admin.roles.view')
        ->name('rbac.screens.roles');
    Route::get('roles/{roleId}/edit', [App\Http\Controllers\Admin\RbacScreensController::class, 'editor'])
        ->middleware('permission:admin.roles.view')
        ->name('rbac.screens.editor');
    Route::get('users/{userId}/overrides', [App\Http\Controllers\Admin\RbacScreensController::class, 'overrides'])
        ->middleware('permission:admin.users.view')
        ->name('rbac.screens.overrides');
    Route::get('pending-grants', [App\Http\Controllers\Admin\RbacScreensController::class, 'pending'])
        ->middleware('permission:admin.roles.view')
        ->name('rbac.screens.pending');
});
