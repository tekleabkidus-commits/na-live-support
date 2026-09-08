<?php

declare(strict_types=1);

use App\Domain\Sms\Rendering\LinkTarget;
use App\Domain\Sms\Rendering\RenderContext;
use App\Domain\Sms\Rendering\TemplateRenderer;
use App\Domain\Sms\Templates\TemplateReader;
use App\Domain\Sms\Templates\TemplateWriter;
use App\Models\SmsTemplateVersion;
use App\Support\Settings\SettingsRepository;

/*
| Doc 14 FR-06 / §14 / SEC-05 — the server-side resolver: Doc 12's ONE
| VariableResolver consumed for the ticket-bound vocabulary; brand.name, code
| and {{link}} resolved by Doc 14; unresolved → on_missing (skip_send default |
| fallback_text), either path logged; a {{blank}} NEVER leaves; the language
| fallback chain (customer → tenant default → English) with the fallback named.
*/

function smsRenderScene(): array
{
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    activateTenant($tenant);
    bindTenant($tenant);
    $actor = '00000000-0000-7000-8000-0000000000aa';
    $writer = app(TemplateWriter::class);

    return [$tenant, $ticket, $actor, $writer];
}

it('renders the ticket-bound vocabulary through Doc 12\'s resolver, brand.name from the tenant, code from the context — no {{ leaves', function (): void {
    [$tenant, $ticket, $actor, $writer] = smsRenderScene();
    $template = $writer->create('ticket_resolved', 'en', 'Resolved', '{{brand.name}}: ticket {{ticket.number}} for {{customer.name}} is resolved.', $actor);
    /** @var SmsTemplateVersion $version */
    $version = SmsTemplateVersion::query()->findOrFail($template->live_version_id);

    $result = app(TemplateRenderer::class)->render($version, $template, new RenderContext(language: 'en', ticketId: $ticket->identifier()));

    expect($result->outcome)->toBe('rendered')
        ->and($result->unresolved)->toBe([])
        ->and($result->text)->toContain($tenant->name)->toContain('#'.$ticket->number)->toContain('Fixture Player')
        ->and($result->text)->not->toContain('{{');

    $code = $writer->create('verification_code', 'en', 'Code', '{{brand.name}}: your code is {{code}}.', $actor);
    /** @var SmsTemplateVersion $codeVersion */
    $codeVersion = SmsTemplateVersion::query()->findOrFail($code->live_version_id);
    $rendered = app(TemplateRenderer::class)->render($codeVersion, $code, new RenderContext(language: 'en', code: '482913'));
    expect($rendered->text)->toBe($tenant->name.': your code is 482913.');
});

it('FR-06: an unresolved variable → skip_send by default (nothing leaves, the name logged); with on_missing = fallback_text the fallback leaves; a fallback with its own hole → skip', function (): void {
    [, , $actor, $writer] = smsRenderScene();
    $template = $writer->create('ticket_resolved', 'en', 'Resolved', 'Ticket {{ticket.number}} is resolved.', $actor);
    /** @var SmsTemplateVersion $version */
    $version = SmsTemplateVersion::query()->findOrFail($template->live_version_id);
    $renderer = app(TemplateRenderer::class);

    // no ticket in the context → ticket.number unresolved
    $skip = $renderer->render($version, $template, new RenderContext(language: 'en'));
    expect($skip->outcome)->toBe('skip_send')->and($skip->text)->toBeNull()->and($skip->unresolved)->toBe(['ticket.number'])->and($skip->sends())->toBeFalse();

    $template = $writer->update($template, ['on_missing' => 'fallback_text', 'fallback_text' => 'Your ticket is resolved.'], $actor);
    $fallback = $renderer->render($version, $template, new RenderContext(language: 'en'));
    expect($fallback->outcome)->toBe('fallback')->and($fallback->text)->toBe('Your ticket is resolved.')->and($fallback->unresolved)->toBe(['ticket.number']);

    $template = $writer->update($template, ['fallback_text' => 'Ticket {{ticket.number}} — see {{brand.name}}'], $actor);
    $hole = $renderer->render($version, $template, new RenderContext(language: 'en'));
    expect($hole->outcome)->toBe('skip_send')->and($hole->text)->toBeNull();
});

it('SEC-05: {{link}} resolves ONLY to a signed platform short URL on sms.link.domain; the not-yet-built ticket status page resolves to nothing (never a fabricated URL)', function (): void {
    [, , $actor, $writer] = smsRenderScene();
    app(SettingsRepository::class)->set('sms.link.domain', 's.nabet.example');
    $template = $writer->create('security_notification', 'en', 'Security', 'Review: {{link}}', $actor);
    /** @var SmsTemplateVersion $version */
    $version = SmsTemplateVersion::query()->findOrFail($template->live_version_id);
    $renderer = app(TemplateRenderer::class);

    $kb = $renderer->render($version, $template, new RenderContext(language: 'en', link: new LinkTarget('kb_article', '0192a3b4-0000-7000-8000-00000000abcd')));
    expect($kb->outcome)->toBe('rendered')
        ->and($kb->text)->toMatch('~^Review: https://s\.nabet\.example/s/kb_article\.0192a3b4-0000-7000-8000-00000000abcd\.[0-9a-f]{12}$~');

    $status = $renderer->render($version, $template, new RenderContext(language: 'en', link: new LinkTarget('ticket_status', 'x')));
    expect($status->outcome)->toBe('skip_send')->and($status->unresolved)->toBe(['link']);

    expect(fn () => new LinkTarget('external', 'https://evil.example'))->toThrow(InvalidArgumentException::class);
});

it('§14 / C-08: resolveForSend walks customer language → tenant default → English and names the fallback', function (): void {
    [, , $actor, $writer] = smsRenderScene();
    $writer->create('ticket_resolved', 'en', 'Resolved', 'Resolved {{ticket.number}}', $actor);
    $reader = app(TemplateReader::class);

    $am = $reader->resolveForSend('ticket_resolved', 'am');
    expect($am)->not->toBeNull()->and($am['language'])->toBe('en')->and($am['fallback_from'])->toBe('am');

    $writer->create('ticket_resolved', 'am', 'ተፈትቷል', 'ተፈትቷል {{ticket.number}}', $actor);
    $amNow = $reader->resolveForSend('ticket_resolved', 'am');
    expect($amNow['language'])->toBe('am')->and($amNow['fallback_from'])->toBeNull();

    expect($reader->resolveForSend('ticket_closed', 'am'))->toBeNull('no template on any rung → null, the caller logs it')
        ->and($reader->chain('am'))->toBe(['am', 'en']);
});
