<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Routing\Hours\HoursContract;
use App\Domain\Webchat\Config\WidgetConfigReader;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Domain\Webchat\WebchatAdapter;
use App\Http\Middleware\ResolveTenant;
use App\Models\Widget;
use App\Support\Flags\FeatureRegistry;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 T-03 `GET /api/widget/v1/config` — everything the panel needs to
 * paint from one read: the live config (FR-20), the capability envelope the
 * adapter declares (T-02 — caps drive the composer, never a channel name),
 * the transport endpoints (T-04), the hours snapshot (FR-15 from Doc 12's
 * real calendar through HoursContract — an INSTANT, DB time), the platform
 * dials the widget consumes, and the `widget.*` strings for the resolved
 * language chain (§14; C-08 fallback user → tenant → en).
 *
 * Cache-Control 60 s (FR-20's poll is 5 min; the shim is 60 s). A soft-deleted
 * widget is DEAD to the wire: 404 here (C3).
 */
final class WidgetConfigController
{
    public function __construct(
        private readonly WidgetConfigReader $reader,
        private readonly AdapterRegistry $adapters,
        private readonly HoursContract $hours,
        private readonly SettingsRepository $settings,
        private readonly FeatureRegistry $features,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $widget = $this->resolvedWidget($request);
        $live = $this->reader->live($widget);
        $capabilities = $this->adapters->adapter(WebchatAdapter::CHANNEL)->capabilities();
        $now = $this->hours->now();
        $config = $live['config'];
        $behavior = is_array($config['behavior'] ?? null) ? $config['behavior'] : [];
        $department = $this->departmentFrom($config);
        $language = $this->resolveLanguage($request, $config);
        $open = $this->hours->isOpen($department, $now);

        $payload = [
            'v' => 1,
            'widget_id' => VisitorConversation::widgetId($widget),
            'version' => $live['version'],
            'config' => $config,
            'capabilities' => [
                'max_text' => $capabilities->maxText,
                'media' => $capabilities->mediaTypes,
                'typing' => $capabilities->supportsTyping,
                'buttons' => $capabilities->supportsButtons,
                'max_mb' => self::int($this->settings->get('media.max_mb'), 25),
                'voice' => $this->features->active('widget.voice_recording') && ($behavior['file_upload'] ?? true) === true,
            ],
            'transport' => [
                'polling_interval_seconds' => self::int($this->settings->get('widget.polling.interval_seconds'), 5),
                'config_poll_seconds' => self::int($this->settings->get('widget.config.poll_seconds'), 300),
                'reverb' => [
                    'key' => self::str(config('reverb.apps.apps.0.key')),
                    'host' => self::str(config('reverb.apps.apps.0.options.host')),
                    'port' => self::int(config('reverb.apps.apps.0.options.port'), 443),
                    'scheme' => self::str(config('reverb.apps.apps.0.options.scheme'), 'https'),
                ],
            ],
            'hours' => [
                'open' => $open,
                'opens_at' => $open ? null : $this->hours->nextOpen($department, $now)->format(DATE_ATOM),
                'timezone' => $this->hours->timezone(),
            ],
            'language' => $language,
            'languages' => $this->offeredLanguages($config),
            'strings' => $this->strings($language),
            'settings' => [
                'agent_identity' => $this->settings->get('widget.agent_identity'),
                'cache_content' => (bool) $this->settings->get('widget.cache.content'),
                'proactive_cooldown_hours' => self::int($this->settings->get('widget.proactive.cooldown_hours'), 24),
                'sound_default' => (bool) $this->settings->get('widget.sound.default'),
                'transcript_mode' => $this->settings->get('widget.transcript.mode'),
            ],
        ];

        return (new JsonResponse($payload))->setCache(['private' => true, 'max_age' => 60]);
    }

    private function resolvedWidget(Request $request): Widget
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);
        $widget = is_string($keyId) ? Widget::query()->where('key_id', $keyId)->whereNull('deleted_at')->first() : null;
        if ($widget === null) {
            throw new NotFoundHttpException; // C3: dead to the wire; C-10: absence
        }

        return $widget;
    }

    /** @param array<string, mixed> $config */
    private function departmentFrom(array $config): ?string
    {
        $behavior = is_array($config['behavior'] ?? null) ? $config['behavior'] : [];
        $source = $behavior['hours_source'] ?? 'tenant';

        return is_string($source) && str_starts_with($source, 'department:') ? substr($source, 11) : null;
    }

    /**
     * §14: explicit visitor choice (the client sends ?language= from its persisted
     * choice or setLanguage()) → Accept-Language → the widget default → tenant
     * default → en. A language cannot be OFFERED incomplete (the completeness
     * gate is the Designer's; here an unoffered language falls through).
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveLanguage(Request $request, array $config): string
    {
        $offered = $this->offeredLanguages($config);
        $candidates = [];
        $explicit = $request->query('language');
        if (is_string($explicit) && preg_match('/^[a-z]{2,8}$/', $explicit) === 1) {
            $candidates[] = $explicit;
        }
        $browser = $request->getPreferredLanguage($offered);
        if (is_string($browser)) {
            $candidates[] = $browser;
        }
        $content = is_array($config['content'] ?? null) ? $config['content'] : [];
        $default = $content['default_language'] ?? null;
        if (is_string($default)) {
            $candidates[] = $default;
        }
        $candidates[] = 'en';

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $offered, true)) {
                return $candidate;
            }
        }

        return 'en';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function offeredLanguages(array $config): array
    {
        return ['en', 'am']; // the platform's shipped pair (C-08); operator content gates per language at the Designer
    }

    /** @return array<string, mixed> the widget.* namespace for one language, keys intact (N-03) */
    private function strings(string $language): array
    {
        $strings = trans('widget', [], $language);
        if (! is_array($strings)) {
            return [];
        }
        /** @var array<string, mixed> $strings */

        return $strings;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
