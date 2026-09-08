<?php

declare(strict_types=1);

namespace App\Domain\Sms\Rendering;

/**
 * FR-06's outcome: `rendered` (every variable resolved), `fallback` (an
 * unresolved variable and the template's on_missing = fallback_text — the
 * fallback text is what leaves), or `skip_send` (unresolved and on_missing =
 * skip_send — nothing leaves; the row is `skipped:render_missing_variable`).
 * Either path is LOGGED (the unresolved names ride the guard_log). A `{{blank}}`
 * never leaves the platform: `text` is null unless the outcome is a send.
 */
final readonly class RenderResult
{
    /**
     * @param  list<string>  $unresolved
     */
    public function __construct(
        public string $outcome,
        public ?string $text,
        public array $unresolved,
    ) {}

    public function sends(): bool
    {
        return $this->text !== null;
    }
}
