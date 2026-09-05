<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Routing\Estimator\Estimator;
use App\Domain\Routing\Hours\HoursContract;
use App\Domain\Routing\Intake\TenantDefaultDepartment;
use App\Domain\Webchat\Config\WidgetConfigReader;
use App\Domain\Webchat\Realtime\WebchatPusher;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Domain\Webchat\WebchatAdapter;
use App\Http\Middleware\ResolveTenant;
use App\Models\Widget;
use App\Support\Flags\FeatureRegistry;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private readonly Estimator $estimator,
        private readonly TenantDefaultDepartment $defaultDepartment,
        private readonly \App\Domain\Ai\Pipeline\ChatbotEnablement $enablement,
        private readonly TenantContext $context,
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
        $visitor = $request->user('widget');
        if (! $visitor instanceof WidgetVisitor) {
            throw new NotFoundHttpException;
        }

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
                // F08/F09: whether Doc 09's chatbot answers this widget out of hours (the offline form shows only when it does not).
                'chatbot' => $this->enablement->enabledFor('chatbot', WebchatAdapter::CHANNEL, VisitorConversation::widgetId($widget)),
            ],
            'transport' => [
                // The visitor's OWN two channels, named by the server (the tenant id never travels to the client
                // as a fact of its own — the names are opaque strings the broadcasting-auth door checks).
                'channels' => [
                    'thread' => sprintf('tenant.%s.visitor.%s.%s', $this->context->tenantId(), $visitor->visitorId, WebchatPusher::SUFFIX_THREAD),
                    'queue' => sprintf('tenant.%s.visitor.%s.%s', $this->context->tenantId(), $visitor->visitorId, WebchatPusher::SUFFIX_QUEUE),
                    // FR-20 / NFR-04: the PUBLIC per-widget config channel — a version-only wake-up; the door is the record.
                    'config' => \App\Events\Webchat\WidgetConfigPush::channelFor(VisitorConversation::widgetId($widget)),
                ],
                'polling_interval_seconds' => self::int($this->settings->get('widget.polling.interval_seconds'), 5),
                'config_poll_seconds' => self::int($this->settings->get('widget.config.poll_seconds'), 300),
                'reverb' => [
                    'key' => self::str(config('reverb.apps.apps.0.key')),
                    'host' => self::str(config('reverb.apps.apps.0.options.host')),
                    'port' => self::int(config('reverb.apps.apps.0.options.port'), 443),
                    'scheme' => self::str(config('reverb.apps.apps.0.options.scheme'), 'https'),
                ],
            ],
            // Home's wait band (FR-09; BR-02 honest presence): Doc 11's estimate for the widget's department
            // queue — band only, never a count (SEC-05); off-hours carries opens_at below.
            'home' => ['band' => $this->homeBand($open, $department, $now)],
            // FR-06 / FR-15: the pre-chat and offline forms as VERSION ids (Doc 12's immutable contract; the widget
            // fetches the schema through `GET forms/{versionId}`), and the pre-chat mode (off | optional | required).
            'forms' => $this->forms($config),
            // FR-07 / F06: the site-asserted display for THIS visitor's customer — continuity only, never "verified" (BR-05).
            'visitor' => ['display' => $this->assertedDisplay($visitor)],
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

    /**
     * FR-09's wait band for Home: the widget's department queue when `hours_source`
     * names one, else the tenant default department's default queue (A-55). Off hours
     * is its own band; with no queue at all the band is null and Home shows none.
     */
    private function homeBand(bool $open, ?string $department, \DateTimeImmutable $now): ?string
    {
        if (! $open) {
            return 'off_hours';
        }
        $queue = $department !== null
            ? DB::table('departments')->where('id', $department)->value('default_queue_id')
            : ($this->defaultDepartment->resolve()['queue_id'] ?? null);

        return is_string($queue) ? $this->estimator->forQueue($queue, $now)['band'] : null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{pre_chat: array{version_id: ?string, mode: string}, offline: array{version_id: ?string}}
     */
    private function forms(array $config): array
    {
        $content = is_array($config['content'] ?? null) ? $config['content'] : [];
        $version = static function (mixed $formId): ?string {
            if (! is_string($formId) || $formId === '') {
                return null;
            }
            $v = DB::table('forms')->where('id', $formId)->value('current_version_id');

            return is_string($v) ? $v : null;
        };
        $mode = $content['pre_chat_mode'] ?? 'off';

        return [
            'pre_chat' => ['version_id' => $version($content['pre_chat_form_id'] ?? null), 'mode' => in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'off'],
            'offline' => ['version_id' => $version($content['offline_form_id'] ?? null)],
        ];
    }

    /** The site-asserted `web` identity's display on the visitor's own customer (A-61's shape), or null. */
    private function assertedDisplay(WidgetVisitor $visitor): ?string
    {
        /** @var object{customer_id: string}|null $own */
        $own = DB::table('customer_identities')->where('type', 'web')->where('value', $visitor->visitorId)->first(['customer_id']);
        if ($own === null) {
            return null;
        }
        /** @var object{metadata: ?string}|null $site */
        $site = DB::table('customer_identities')->where('type', 'web')->where('customer_id', $own->customer_id)->where('value', 'like', 'site:%')->orderByDesc('id')->first(['metadata']);
        $meta = $site !== null && is_string($site->metadata) ? json_decode($site->metadata, true) : null;
        $display = is_array($meta) ? ($meta['display'] ?? null) : null;

        return is_string($display) && $display !== '' ? $display : null;
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

    /** @return array<string, mixed> the widget.* namespace for one language, keys intact (N-03) — shared with the Designer's preview */
    private function strings(string $language): array
    {
        return \App\Domain\Webchat\Config\CustomerStrings::for($language);
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
