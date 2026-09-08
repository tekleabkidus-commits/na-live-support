<?php

declare(strict_types=1);

namespace App\Domain\Sms\Rendering;

use App\Domain\Macros\VariableResolver;
use App\Domain\Sms\Templates\TemplatePublisher;
use App\Models\SmsTemplate;
use App\Models\SmsTemplateVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Doc 14 FR-06 — the server-side resolver. It shares Doc 12's macro engine's
 * DISCIPLINE (T-06 there: one resolver, locale-formatted values, unresolved
 * names reported — never silently blanked) and CONSUMES its shared kernel for
 * the ticket-bound vocabulary (`VariableResolver`: customer.name, ticket.number,
 * queue.wait_band — Doc 11's provider is already §14-lawful); Doc 14 resolves
 * its own three: brand.name (the tenant), code (platform-generated, BR-06) and
 * link (LinkResolver, SEC-05).
 *
 * The law (Doc 12 BR-04 extended): a `{{blank}}` never leaves the platform.
 * Unresolved at send → the template's on_missing: `skip_send` (default) → no
 * text; `fallback_text` → the fallback (itself resolved the same way; if IT
 * has an unresolved variable, nothing leaves). Both paths are logged by the
 * caller from `RenderResult::unresolved`.
 */
final class TemplateRenderer
{
    public function __construct(
        private readonly VariableResolver $variables,
        private readonly LinkResolver $links,
        private readonly TenantContext $context,
    ) {}

    public function render(SmsTemplateVersion $version, SmsTemplate $template, RenderContext $context): RenderResult
    {
        [$text, $unresolved] = $this->resolve($version->body, $context);

        if ($unresolved === []) {
            return new RenderResult('rendered', $text, []);
        }

        if ($template->on_missing === 'fallback_text' && is_string($template->fallback_text) && $template->fallback_text !== '') {
            [$fallback, $fallbackUnresolved] = $this->resolve($template->fallback_text, $context);
            if ($fallbackUnresolved === []) {
                return new RenderResult('fallback', $fallback, $unresolved);
            }

            return new RenderResult('skip_send', null, array_values(array_unique([...$unresolved, ...$fallbackUnresolved])));
        }

        return new RenderResult('skip_send', null, $unresolved);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function resolve(string $body, RenderContext $context): array
    {
        $names = TemplatePublisher::variablesIn($body);
        if ($names === []) {
            return [$body, []];
        }

        $values = [];
        // the ticket-bound vocabulary through Doc 12's ONE resolver (parity is shared code)
        $ticketBound = array_values(array_intersect($names, VariableResolver::VOCABULARY));
        if ($ticketBound !== [] && $context->ticketId !== null && $context->samples === []) {
            $resolved = $this->variables->resolve(
                implode(' ', array_map(static fn (string $n): string => '{{'.$n.'}}', $ticketBound)),
                $context->ticketId,
                $context->agentId,
            );
            foreach ($resolved['variables'] as $name => $value) {
                $values[(string) $name] = $value;
            }
        }

        foreach ($names as $name) {
            if (array_key_exists($name, $values)) {
                continue;
            }
            $values[$name] = match ($name) {
                'brand.name' => $this->brandName($context),
                'code' => $context->samples['code'] ?? $context->code,
                'link' => $context->samples['link'] ?? $this->links->resolve($context->link),
                default => $context->samples[$name] ?? null,
            };
        }

        $unresolved = [];
        $text = $body;
        foreach ($names as $name) {
            $value = $values[$name] ?? null;
            if ($value === null || $value === '') {
                $unresolved[] = $name;

                continue;
            }
            $text = (string) preg_replace('/\{\{\s*'.preg_quote($name, '/').'\s*\}\}/i', $value, $text);
        }

        return [$text, $unresolved];
    }

    private function brandName(RenderContext $context): ?string
    {
        if (isset($context->samples['brand.name'])) {
            return $context->samples['brand.name'];
        }
        $name = DB::table('tenants')->where('id', $this->context->tenantId())->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
