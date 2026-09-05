<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Webchat\Bundle\WidgetBundleResolver;
use App\Domain\Webchat\Config\CustomerStrings;
use App\Domain\Webchat\Config\WidgetConfigReader;
use App\Domain\Webchat\Designer\InstallSnippet;
use App\Domain\Webchat\Designer\WidgetConfigPublisher;
use App\Domain\Webchat\Designer\WidgetHealthReader;
use App\Domain\Webchat\Identity\WidgetSecretMinter;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Models\Widget;
use App\Support\Rbac\PageCapabilities;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 §4.4 (S13 step 11): the Widget Designer's Backoffice pages — B3-S08 v3 at full
 * fidelity (J01–J10, the 375 admin list). The editor edits ONE config object and PREVIEWS
 * it in the panel bundle itself: the preview document below is the visitor's panel booted
 * in preview mode with the SAME strings the visitor receives (CustomerStrings) and a fixture
 * thread; the editor pushes every draft change into it by postMessage (same origin).
 */
final class WidgetDesignerScreensController extends Controller
{
    public function __construct(
        private readonly PageCapabilities $capabilities,
        private readonly WidgetConfigReader $reader,
        private readonly WidgetConfigPublisher $publisher,
        private readonly WidgetHealthReader $health,
        private readonly InstallSnippet $install,
        private readonly WidgetBundleResolver $bundles,
        private readonly SettingsRepository $settings,
        private readonly WidgetSecretMinter $secrets,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $rows = Widget::query()->whereNull('deleted_at')->orderBy('name')->get();
        $widgets = [];
        foreach ($rows as $widget) {
            $live = $this->reader->live($widget);
            $widgets[] = [
                'id' => VisitorConversation::widgetId($widget),
                'name' => (string) $widget->name,
                'key_prefix' => $widget->key !== null ? $widget->key->prefix : '',
                'hosts' => $widget->allowed_origins ?? [],
                'live_version' => $live['version'],
                'test_mode' => (bool) $widget->test_mode,
                'bundle_channel' => (string) ($widget->bundle_channel ?? 'stable'),
            ];
        }

        return Inertia::render('Admin/Widgets/Index', [
            'copy' => __('widget.designer'),
            'can' => $this->capabilities->for($this->user($request), 'Admin/Widgets/Index'),
            'widgets' => $widgets,
            'bundle_version' => $this->bundles->manifest()['panel'],
        ]);
    }

    public function editor(Request $request, string $widgetId): InertiaResponse
    {
        $widget = $this->widget($widgetId);
        $live = $this->reader->live($widget);

        return Inertia::render('Admin/Widgets/Editor', [
            'copy' => __('widget.designer'),
            'can' => $this->capabilities->for($this->user($request), 'Admin/Widgets/Editor'),
            'widget' => [
                'id' => VisitorConversation::widgetId($widget),
                'name' => (string) $widget->name,
                'key_prefix' => $widget->key !== null ? $widget->key->prefix : '',
                'bundle_channel' => (string) ($widget->bundle_channel ?? 'stable'),
                'test_mode' => (bool) $widget->test_mode,
                'live_version' => $live['version'],
                'live_version_id' => $live['version_id'],
                'allowed_origins' => $widget->allowed_origins ?? [],
                'has_secret' => is_string($widget->identity_secret_ref),
                'secret_rotated_at' => $widget->identity_secret_rotated_at?->toIso8601String(),
                'overlap_hours' => $this->secrets->overlapHours(),
            ],
            'config' => $live['config'],
            'defaults' => WidgetConfigReader::defaults(),
            'fonts' => WidgetConfigPublisher::FONTS,
            'icons' => WidgetConfigPublisher::ICONS,
            'voice_flag' => \Laravel\Pennant\Feature::active('widget.voice_recording'),
            'chatbot' => ['enabled' => true, 'href' => '/admin/ai'],
            'forms' => $this->forms(),
            'departments' => $this->departments(),
            'history' => $this->publisher->history($widget),
            'health' => $this->health->read($widget, CarbonImmutable::now('UTC')),
            'install' => $this->install->forWidget($widget),
            'languages' => ['en', 'am'],
        ]);
    }

    /**
     * The preview document: the panel bundle, booted in preview mode (no session, no socket),
     * framed by the Backoffice page itself — `frame-ancestors 'self'` for THIS document only.
     */
    public function preview(Request $request, string $widgetId): Response
    {
        $widget = $this->widget($widgetId);
        $language = in_array($request->query('language'), ['en', 'am'], true) ? (string) $request->query('language') : 'en';
        $theme = in_array($request->query('theme'), ['light', 'dark'], true) ? (string) $request->query('theme') : 'light';
        $live = $this->reader->live($widget);
        $bundle = $this->bundles->forWidget($widget);
        $nonce = $request->attributes->get(SecurityHeaders::REQUEST_NONCE_ATTRIBUTE);
        $nonce = is_string($nonce) ? $nonce : '';
        $request->attributes->set(SecurityHeaders::REQUEST_ANCESTOR_SOURCES_ATTRIBUTE, ["'self'"]);
        $envelope = [
            'v' => 1,
            'widget_id' => VisitorConversation::widgetId($widget),
            'version' => $live['version'],
            'config' => $live['config'],
            'capabilities' => ['max_text' => 4000, 'media' => ['photo', 'document'], 'typing' => true, 'buttons' => true, 'max_mb' => 25, 'voice' => false, 'chatbot' => true],
            'forms' => ['pre_chat' => ['version_id' => null, 'mode' => 'off'], 'offline' => ['version_id' => null]],
            'visitor' => ['display' => null],
            'transport' => ['channels' => ['thread' => '', 'queue' => '', 'config' => ''], 'polling_interval_seconds' => 0, 'config_poll_seconds' => 0, 'reverb' => ['key' => '', 'host' => '', 'port' => 0, 'scheme' => 'https']],
            'home' => ['band' => 'short'],
            'hours' => ['open' => true, 'opens_at' => null, 'timezone' => 'Africa/Addis_Ababa'],
            'language' => $language,
            'languages' => ['en', 'am'],
            'strings' => CustomerStrings::for($language),
            'settings' => [
                'agent_identity' => is_string($identity = $this->settings->get('widget.agent_identity')) ? $identity : 'first_name',
                'cache_content' => false,
                'proactive_cooldown_hours' => 24,
                'sound_default' => false,
                'transcript_mode' => 'off',
            ],
        ];
        $boot = json_encode(['envelope' => $envelope, 'theme' => $theme, 'language' => $language], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $links = '';
        foreach ($bundle['css'] as $css) {
            $links .= '<link rel="stylesheet" href="'.htmlspecialchars('/w/'.$css, ENT_QUOTES).'">';
        }
        $html = '<!doctype html><html lang="'.$language.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            .$links
            .'<script nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'">window.__NLW_PREVIEW='.$boot.';</script>'
            .'<script type="module" nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'" src="'.htmlspecialchars('/w/'.$bundle['panel'], ENT_QUOTES).'"></script>'
            .'</head><body><div id="nlw-root" data-widget="'.htmlspecialchars(VisitorConversation::widgetId($widget), ENT_QUOTES).'"></div></body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** @return list<array{id: string, name: string}> */
    private function forms(): array
    {
        $out = [];
        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        foreach (\Illuminate\Support\Facades\DB::table('forms')->where('active', true)->orderBy('name')->get(['id', 'name']) as $row) {
            $out[] = ['id' => $str($row->id), 'name' => $str($row->name)];
        }

        return $out;
    }

    /** @return list<array{id: string, name: string}> */
    private function departments(): array
    {
        $out = [];
        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        foreach (\Illuminate\Support\Facades\DB::table('departments')->whereNull('deleted_at')->orderBy('position')->get(['id', 'name']) as $row) {
            $raw = $str($row->name);
            $name = json_decode($raw, true);
            $first = is_array($name) ? ($name['en'] ?? reset($name)) : $raw;
            $out[] = ['id' => $str($row->id), 'name' => $str($first)];
        }

        return $out;
    }

    private function widget(string $widgetId): Widget
    {
        $widget = Widget::query()->whereNull('deleted_at')->find($widgetId);
        if (! $widget instanceof Widget) {
            throw new NotFoundHttpException;
        }

        return $widget;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
