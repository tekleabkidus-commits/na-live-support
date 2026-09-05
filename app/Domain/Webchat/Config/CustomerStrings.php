<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Config;

/**
 * The `widget.*` namespace for one language, customer-facing branches ONLY (N-03: keys
 * intact): the staff notes (`staff.*`), the Backoffice label (`feature.*`) and the Designer
 * (`designer.*`) never ride to a visitor. Shared by the config envelope and the Designer's
 * preview document (the SAME strings the visitor sees — B3-S08 J03's preview is the panel).
 */
final class CustomerStrings
{
    /** @return array<string, mixed> */
    public static function for(string $language): array
    {
        $strings = trans('widget', [], $language);
        if (! is_array($strings)) {
            return [];
        }
        foreach (['feature', 'staff', 'designer'] as $staff) {
            unset($strings[$staff]);
        }
        /** @var array<string, mixed> $customer */
        $customer = array_filter($strings, static fn ($k): bool => is_string($k), ARRAY_FILTER_USE_KEY);

        return $customer;
    }
}
