<?php

declare(strict_types=1);

namespace App\Domain\Kb\Categories;

use DomainException;

/** Doc 10 FR-01/EC-03: a category with articles is never deleted — reassign first. */
final class CategoryHasArticles extends DomainException
{
    public function __construct(public readonly int $articles, public readonly int $retrievalImpact)
    {
        parent::__construct(sprintf('category holds %d article(s) — reassign them first (FR-01); %d would leave the chatbot knowledge', $articles, $retrievalImpact));
    }
}
