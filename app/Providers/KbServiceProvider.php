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
        // Step 7 rebinds the truth-fed resolver (versions + slug history); ChunkStateCitationResolver stays for pre-step-7 fixture refs.
        $this->app->bind(CitationResolver::class, \App\Domain\Kb\Tombstones\VersionedCitationResolver::class);
        // T-03/BR-06 (step 5): the publish-time sanitizer is Doc 09's InstructionStripper
        // run sentence by sentence — ONE shared package, one rule set, one fixture suite.
        // Sabotage #7 rebinds UnboundSanitizer here and AC-05 goes red.
        $this->app->bind(\App\Domain\Kb\Sanitizer\PublishSanitizer::class, \App\Domain\Kb\Sanitizer\SharedPublishSanitizer::class);
        // STOP-B (S10 step 7b, packet P1 countersigned): the chatbot's retriever is bound to the
        // PUBLIC PARTITION by table name — a constructor fact, no widening parameter on that path.
        $this->app->when(\App\Domain\Ai\Chatbot\ChatbotTurn::class)
            ->needs(\App\Domain\Ai\Retrieval\HybridRetriever::class)
            ->give(static fn (\Illuminate\Contracts\Foundation\Application $app): \App\Domain\Ai\Retrieval\HybridRetriever => new \App\Domain\Ai\Retrieval\HybridRetriever($app->make(\App\Domain\Ai\Orchestrator\AiOrchestrator::class), 'kb_chunks_public'));
    }
}
