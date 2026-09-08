<?php

declare(strict_types=1);

namespace App\Domain\Sms\Templates;

use App\Domain\Sms\Catalog\TemplateEvents;
use App\Domain\Sms\Encoding\Segmentation;
use App\Domain\Sms\Encoding\Segmenter;
use App\Support\Settings\SettingsRepository;

/**
 * Doc 14 FR-05 / SEC-05 / AC-10 / NFR-06 — THE publish-time validation and the
 * editor's estimate, one class (the editor's "validate" door and the writer's
 * publish call the SAME code — parity is shared code, the Doc 12 T-06 law).
 *
 *  - missing-variable validation: every `{{name}}` must be in the EVENT's
 *    allowed set (FR-04) — a template referencing a variable its event does
 *    not provide CANNOT publish (FR-05);
 *  - SEC-05: a literal URL in the body fails publish (smishing-shape
 *    prevention); `{{link}}` is the ONLY way a link enters a message and it
 *    resolves at send to a platform short URL on `sms.link.domain` — using
 *    `{{link}}` while that setting is empty is refused too;
 *  - the segment ESTIMATE (A-66): the body with SAMPLE values for its
 *    variables (the editor's figure; NFR-06 binds it within one segment of the
 *    accrual on the rendered text — EstimateVsAccrualParityTest);
 *  - a body over 1000 characters is refused (the editor's own ceiling; the
 *    vendor's 335-character cap is a mapping-level guard at send).
 */
final class TemplatePublisher
{
    public const MAX_BODY_CHARS = 1000;

    /** Sample values the editor estimates with — deliberately LONG for their kind (a conservative estimate, R-02). */
    public const SAMPLE_VALUES = [
        'ticket.number' => '#4812',
        'customer.name' => 'Abebe Tadesse',
        'brand.name' => 'NA BET Support',
        'queue.wait_band' => 'about 20 minutes',
        'code' => '482913',
        'link' => 'https://s.example.et/s/AbCdEf12',
    ];

    public function __construct(
        private readonly Segmenter $segmenter,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Validate a body for an event the way publish will; returns the analysis or throws TemplateRefused.
     *
     * @return array{variables_used: list<string>, encoding: string, segments_estimate: int, units: int, per_segment: int, boundaries: list<int>}
     */
    public function validate(string $event, string $body): array
    {
        if (! TemplateEvents::isTemplateEvent($event)) {
            throw new TemplateRefused('unknown_event', 'not a template event', ['event' => $event]);
        }
        if ($body === '' || mb_strlen($body, 'UTF-8') > self::MAX_BODY_CHARS) {
            throw new TemplateRefused('body_too_long', 'template body is empty or over the ceiling', ['max' => self::MAX_BODY_CHARS]);
        }

        // SEC-05 / AC-10: no arbitrary URL, ever — the only link is {{link}}.
        if (preg_match('~(?:https?://|www\.)[^\s]+~i', $body) === 1) {
            throw new TemplateRefused('arbitrary_url', 'a template may not carry a URL; use {{link}} (SEC-05)');
        }

        $used = self::variablesIn($body);
        $allowed = TemplateEvents::allowedVariables($event);
        $notAllowed = array_values(array_diff($used, $allowed));
        if ($notAllowed !== []) {
            throw new TemplateRefused('variable_not_allowed', 'a variable the event does not provide', ['variables' => $notAllowed, 'allowed' => $allowed]);
        }

        if (in_array('link', $used, true)) {
            $domain = $this->settings->get('sms.link.domain');
            if (! is_string($domain) || trim($domain) === '') {
                throw new TemplateRefused('link_domain_unset', '{{link}} needs sms.link.domain (SEC-05)');
            }
        }

        $estimate = $this->estimate($body);

        return [
            'variables_used' => $used,
            'encoding' => $estimate->encoding->value,
            'segments_estimate' => $estimate->segments,
            'units' => $estimate->units,
            'per_segment' => $estimate->perSegment,
            'boundaries' => $estimate->boundaries,
        ];
    }

    /** The editor's estimate: the body with SAMPLE values substituted, through the ONE segmenter. */
    public function estimate(string $body): Segmentation
    {
        return $this->segmenter->segment(self::withSamples($body));
    }

    /** @return list<string> the `{{name}}` variables a body references, in order of first appearance */
    public static function variablesIn(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z_.]+[a-z0-9_.]*)\s*\}\}/i', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    public static function withSamples(string $body): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_.]+[a-z0-9_.]*)\s*\}\}/i',
            static fn (array $m): string => self::SAMPLE_VALUES[$m[1]] ?? $m[0],
            $body,
        );
    }
}
