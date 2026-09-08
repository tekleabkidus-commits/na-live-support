<?php

declare(strict_types=1);

namespace App\Domain\Sms\Catalog;

/**
 * Doc 14 FR-04 / FR-15 — the TEMPLATE event vocabulary: the ten sendable
 * events plus `optout_confirmation`, which has a template (FR-15: "a
 * confirmation SMS where telco rules require it (template optout_confirmation,
 * exempt from its own rule once)") but is NEVER a sendable, toggleable or
 * broadcast-capable event — only the STOP parser's single door sends it, to
 * the phone that just opted out, once (STOP-Q 3).
 */
final class TemplateEvents
{
    public const OPTOUT_CONFIRMATION = 'optout_confirmation';

    /** @return list<string> */
    public static function all(): array
    {
        return [...SendableEvent::values(), self::OPTOUT_CONFIRMATION];
    }

    public static function isTemplateEvent(string $event): bool
    {
        return in_array($event, self::all(), true);
    }

    /** @return list<string> */
    public static function allowedVariables(string $event): array
    {
        if ($event === self::OPTOUT_CONFIRMATION) {
            return ['brand.name'];
        }

        return SendableEvent::from($event)->allowedVariables();
    }
}
