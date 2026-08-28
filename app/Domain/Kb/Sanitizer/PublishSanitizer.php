<?php

declare(strict_types=1);

namespace App\Domain\Kb\Sanitizer;

/**
 * Doc 10 T-03 / FR-13 / BR-06 — the publish-time sanitizer CONTRACT: scans the block
 * document and returns flags ("this sentence may be misread as an instruction to the
 * AI") for the publisher to keep/edit/override; the override is recorded ON the
 * version (SEC-04). Step 5 binds the shared-package implementation (the same rule set
 * as Doc 09's index-time stripper — one package, one fixture suite).
 *
 * @phpstan-type Flag array{block: int, sentence: string, pattern: string}
 */
interface PublishSanitizer
{
    /**
     * @param  array<string, mixed>  $blocks
     * @return list<array{block: int, sentence: string, pattern: string}>
     */
    public function scan(array $blocks, string $language): array;
}
