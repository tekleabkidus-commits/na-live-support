<?php

declare(strict_types=1);

namespace App\Domain\Sms\Templates;

use App\Domain\Sms\Catalog\TemplateEvents;
use App\Models\SmsTemplate;
use App\Models\SmsTemplateVersion;
use App\Support\Settings\SettingsRepository;

/**
 * Doc 14 FR-04 / §8 / §14 — the read side: the event-grouped list with
 * per-language COMPLETENESS dots (Doc 10's pattern), a template's history,
 * and `resolveForSend` — the send path's lookup: the PUBLISHED version for
 * (event, language) through Doc 01's fallback chain (C-08: the customer's
 * language → the tenant default → English), the fallback LOGGED per send
 * (§14: "falls back per the Doc 01 chain with the fallback logged per send").
 */
final class TemplateReader
{
    /** The launch languages (C-08: en + am at launch); the tenant default joins the set. */
    public const LANGUAGES = ['en', 'am'];

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return list<array{event: string, sendable: bool, languages: array<string, array{template_id: ?string, active: bool, live_version: ?int, complete: bool}>}>
     */
    public function grouped(): array
    {
        $languages = $this->languages();
        /** @var array<string, array<string, SmsTemplate>> $byEvent */
        $byEvent = [];
        foreach (SmsTemplate::query()->whereNull('deleted_at')->get() as $template) {
            $byEvent[$template->event][$template->language] = $template;
        }
        $liveVersions = SmsTemplateVersion::query()->whereIn('id', array_filter(array_map(
            static fn (SmsTemplate $t): ?string => $t->live_version_id,
            array_merge(...array_values(array_map('array_values', $byEvent)) ?: [[]]),
        )))->get()->keyBy('id');

        $out = [];
        foreach (TemplateEvents::all() as $event) {
            $row = ['event' => $event, 'sendable' => $event !== TemplateEvents::OPTOUT_CONFIRMATION, 'languages' => []];
            foreach ($languages as $language) {
                $template = $byEvent[$event][$language] ?? null;
                $live = $template?->live_version_id !== null ? $liveVersions->get($template->live_version_id) : null;
                $row['languages'][$language] = [
                    'template_id' => $template?->identifier(),
                    'active' => $template !== null && $template->active,
                    'live_version' => $live?->version,
                    'complete' => $template !== null && $live !== null && (bool) $template->active,
                ];
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<array{version: int, encoding: string, segments_estimate: int, status_at_birth: string, published_at: string, note: ?string, live: bool, body: string}> */
    public function history(SmsTemplate $template): array
    {
        $rows = [];
        foreach (SmsTemplateVersion::query()->where('template_id', $template->getKey())->orderByDesc('version')->get() as $v) {
            $rows[] = [
                'version' => (int) $v->version,
                'encoding' => (string) $v->encoding,
                'segments_estimate' => (int) $v->segments_estimate,
                'status_at_birth' => (string) $v->status_at_birth,
                'published_at' => (string) $v->published_at,
                'note' => $v->note,
                'live' => $v->getKey() === $template->live_version_id,
                'body' => (string) $v->body,
            ];
        }

        return $rows;
    }

    /**
     * The send path's lookup. Returns null when no ACTIVE published template exists on any
     * rung of the chain (the caller logs `skipped:unknown_template_ref` / no template).
     *
     * @return array{template: SmsTemplate, version: SmsTemplateVersion, language: string, fallback_from: ?string}|null
     */
    public function resolveForSend(string $event, ?string $customerLanguage): ?array
    {
        foreach ($this->chain($customerLanguage) as $language) {
            /** @var SmsTemplate|null $template */
            $template = SmsTemplate::query()->where('event', $event)->where('language', $language)->where('active', true)->whereNull('deleted_at')->whereNotNull('live_version_id')->first();
            if ($template === null || $template->live_version_id === null) {
                continue;
            }
            /** @var SmsTemplateVersion|null $version */
            $version = SmsTemplateVersion::query()->find($template->live_version_id);
            if ($version === null) {
                continue;
            }
            $fallbackFrom = $customerLanguage !== null && $customerLanguage !== $language ? $customerLanguage : null;

            return ['template' => $template, 'version' => $version, 'language' => $language, 'fallback_from' => $fallbackFrom];
        }

        return null;
    }

    /**
     * C-08: customer language → tenant default → English, de-duplicated in order.
     *
     * @return list<string>
     */
    public function chain(?string $customerLanguage): array
    {
        $default = $this->settings->get('tenant.default_language');
        $chain = [];
        foreach ([$customerLanguage, is_string($default) ? $default : null, 'en'] as $language) {
            if (is_string($language) && $language !== '' && ! in_array($language, $chain, true)) {
                $chain[] = $language;
            }
        }

        return $chain;
    }

    /** @return list<string> */
    public function languages(): array
    {
        $default = $this->settings->get('tenant.default_language');
        $languages = self::LANGUAGES;
        if (is_string($default) && $default !== '' && ! in_array($default, $languages, true)) {
            $languages[] = $default;
        }

        return $languages;
    }

    /**
     * §8: the completeness warning — events ENABLED for a language that lacks an active published template.
     *
     * @return list<array{event: string, language: string}>
     */
    public function incompleteEnabledEvents(): array
    {
        $missing = [];
        foreach ($this->grouped() as $row) {
            if (! $row['sendable']) {
                continue;
            }
            $enabled = $this->settings->get('sms.events.'.$row['event'].'.enabled');
            if ($enabled !== true) {
                continue;
            }
            foreach ($row['languages'] as $language => $cell) {
                if (! $cell['complete']) {
                    $missing[] = ['event' => $row['event'], 'language' => $language];
                }
            }
        }

        return $missing;
    }

    public function find(string $templateId): ?SmsTemplate
    {
        return SmsTemplate::query()->whereNull('deleted_at')->find($templateId);
    }
}
