<?php

declare(strict_types=1);

namespace App\Domain\Sms\Rendering;

/**
 * What a render may draw on (FR-04's variable set): the ticket (→ ticket.number,
 * customer.name, queue.wait_band through Doc 12's VariableResolver), the brand
 * (→ brand.name from the tenant), the platform-generated `code`
 * (verification_code — the platform owns the code, BR-06), and the link TARGET
 * (→ {{link}} through LinkResolver, SEC-05: a platform surface only). Never
 * free text: a context carries ids and platform-generated values.
 */
final readonly class RenderContext
{
    /**
     * @param  array<string, string>  $samples  the editor's sample values (test-send only)
     */
    public function __construct(
        public string $language,
        public ?string $ticketId = null,
        public ?string $agentId = null,
        public ?string $code = null,
        public ?LinkTarget $link = null,
        public array $samples = [],
    ) {}

    public static function samples(string $language): self
    {
        return new self(language: $language, samples: \App\Domain\Sms\Templates\TemplatePublisher::SAMPLE_VALUES);
    }
}
