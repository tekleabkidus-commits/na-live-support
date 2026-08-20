<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chatbot;

/**
 * A model output the ActionSchema refused (EC-05): never executed, always
 * logged. `$category` is closed: malformed | out_of_enum | missing_field.
 */
final readonly class DiscardedAction
{
    public function __construct(
        public string $category,
        public string $detail,
    ) {}
}
