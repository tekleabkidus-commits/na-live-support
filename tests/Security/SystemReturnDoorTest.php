<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| P-C6 (S11 step 9b) — `AssignTicket::unassignBySystem` is SERVER-INTERNAL: the AssignBySystemDoorTest
| static-assertion class applied to the new door. No route action names it; no controller under
| app/Http references it; Doc 11 reaches it ONLY through the SystemReturn port's one adapter.
|--------------------------------------------------------------------------
*/

it('unassignBySystem is UNREACHABLE from HTTP: no route action names it, no controller under app/Http references it, and the ONLY caller under app/ is the SystemReturn adapter', function () {
    foreach (Route::getRoutes()->getRoutes() as $route) {
        expect($route->getActionName())->not->toContain('unassignBySystem');
    }
    $callers = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $rel = 'app'.substr($file->getPathname(), strlen(app_path()));
        if (str_starts_with($rel, 'app/Http/')) {
            expect(str_contains($source, 'unassignBySystem'))->toBeFalse($rel.' references the system door');
        }
        if (preg_match('/->unassignBySystem\(/', $source) === 1) {
            $callers[] = $rel;
        }
    }
    sort($callers);
    expect($callers)->toBe(['app/Domain/Routing/Rescue/SystemReturnViaDoc06.php'], 'a second caller of the system door exists: '.implode(', ', $callers));
});
