<?php

declare(strict_types=1);

namespace App\Domain\Forms\Runtime;

use App\Domain\Forms\VersionReader;

/**
 * Doc 12 FR-21's widget half — the render contract Doc 13 consumes ([D13]).
 * ONE truth: the version's schema verbatim (VersionReader's cached read),
 * wrapped with the resolved render language so both renderers resolve
 * languages identically (§14: customer language → the form's languages →
 * the first declared language).
 */
final class WidgetContract
{
    public function __construct(private readonly VersionReader $versions) {}

    /** @return array{form_id: string, version: int, language: string, schema: array<string, mixed>} */
    public function render(string $versionId, ?string $customerLanguage): array
    {
        $contract = $this->versions->widgetContract($versionId);

        return [...$contract, 'language' => self::resolveLanguage($contract['schema'], $customerLanguage)];
    }

    /** The FR-20/§14 resolution chain, shared with the Telegram engine.
     * @param  array<string, mixed>  $schema */
    public static function resolveLanguage(array $schema, ?string $customerLanguage): string
    {
        $languages = is_array($schema['languages'] ?? null) ? array_values(array_filter($schema['languages'], 'is_string')) : [];
        if ($customerLanguage !== null && in_array($customerLanguage, $languages, true)) {
            return $customerLanguage;
        }

        return $languages[0] ?? 'en';
    }
}
