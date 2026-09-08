<?php

declare(strict_types=1);

namespace App\Domain\Sms\Rendering;

/**
 * SEC-05: "link targets are the platform's own surfaces (ticket status page
 * when it exists per Doc 06 §19, KB articles)". A target is a KIND + an id —
 * never a URL. `kb_article` resolves today (Doc 10's public reader);
 * `ticket_status` is Doc 06 §19's future page and resolves to nothing until it
 * exists (an unresolved {{link}} follows the template's on_missing policy).
 */
final readonly class LinkTarget
{
    public const KINDS = ['kb_article', 'ticket_status'];

    public function __construct(public string $kind, public string $id)
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('a link target is one of the platform surfaces (SEC-05)');
        }
    }
}
