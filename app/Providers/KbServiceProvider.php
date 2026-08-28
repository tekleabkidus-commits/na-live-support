<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Kb\Tombstones\ChunkStateCitationResolver;
use App\Domain\Kb\Tombstones\CitationResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Doc 10 — the Knowledge Base module's bindings (S10 step 1). Kept in its own
 * provider so Doc 01's AppServiceProvider is never edited for Doc 10 wiring.
 */
final class KbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // FR-16: the citation resolver contract; step 7 rebinds the
        // version+slug-history implementation behind the same interface.
        $this->app->bind(CitationResolver::class, ChunkStateCitationResolver::class);
        // T-03/BR-06: the publish-time sanitizer contract — step 4 binds the loud
        // placeholder; step 5 rebinds the shared package (sabotage #7 reverts this line).
        $this->app->bind(\App\Domain\Kb\Sanitizer\PublishSanitizer::class, \App\Domain\Kb\Sanitizer\UnboundSanitizer::class);
    }
}
