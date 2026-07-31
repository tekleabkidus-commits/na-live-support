<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

/**
 * SEC-07: admin impersonation is deliberately NOT provided in v1.
 *
 * This is a security stance, documented publicly, not a missing feature — support
 * debugging uses read-only permissions instead. A stance is only worth stating if
 * its absence is asserted: this suite sweeps every place an impersonation feature
 * would have to surface (routes, permission keys, settings, feature flags, class
 * names) and fails if any of them ever grows one.
 *
 * If a future document legitimately needs something adjacent (e.g. Doc 07's
 * read-only "view as" reporting), the RIGHT move is to surface it to the owner and
 * wait — not to weaken this sweep.
 */
it('exposes no impersonation route', function () {
    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        $uri = $route->uri();

        expect(preg_match('/impersonat/i', $name.' '.$uri))->toBe(0, sprintf(
            'Route "%s %s" looks like an impersonation surface (SEC-07 forbids one).',
            $uri,
            $name,
        ));
    }
});

it('registers no impersonation permission, setting, flag, or notification category', function () {
    $registries = [
        'permissions' => array_column(require base_path('registries/permissions.php'), 'key'),
        'settings' => array_column(require base_path('registries/settings.php'), 'key'),
        'features' => array_column(require base_path('registries/features.php'), 'key'),
        'notifications' => array_column(require base_path('registries/notifications.php'), 'category_key'),
    ];

    foreach ($registries as $registry => $keys) {
        foreach ($keys as $key) {
            expect(preg_match('/impersonat/i', (string) $key))->toBe(0, sprintf(
                'Registry "%s" contains key "%s" (SEC-07 forbids an impersonation surface).',
                $registry,
                $key,
            ));
        }
    }
});

it('contains no impersonation class or file in the application tree', function () {
    $finder = Finder::create()
        ->files()
        ->in([base_path('app'), base_path('routes'), base_path('config')])
        ->name('*.php');

    foreach ($finder as $file) {
        expect(preg_match('/impersonat/i', $file->getFilenameWithoutExtension()))->toBe(0, sprintf(
            'File "%s" looks like an impersonation feature (SEC-07).',
            $file->getRelativePathname(),
        ));
    }
});
