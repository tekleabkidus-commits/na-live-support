<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Webchat\Config\WidgetConfigReader;
use App\Domain\Webchat\Designer\ConfigRefused;
use App\Domain\Webchat\Designer\InstallSnippet;
use App\Domain\Webchat\Designer\WidgetConfigPublisher;
use App\Domain\Webchat\Designer\WidgetDesigner;
use App\Domain\Webchat\Designer\WidgetHealthReader;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Widget;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 §4.4 / FR-19 / FR-20 (S13 step 10): the Designer's JSON doors under /admin/widgets.
 * Reads under `webchat.widgets.view`, mutations under `webchat.widgets.configure` with the
 * `audit:` declaration each mutation must honour (DeclareAuditAction verifies the row was
 * written). Refusals are RFC 9457 problem details with the rule's code and the inline
 * figures (the contrast refusal carries ratio + suggestion — B3-S08 J03). The Inertia
 * pages that consume these doors are step 11's.
 */
final class WidgetDesignerController extends Controller
{
    public function __construct(
        private readonly WidgetDesigner $designer,
        private readonly WidgetConfigPublisher $publisher,
        private readonly WidgetConfigReader $reader,
        private readonly WidgetHealthReader $health,
        private readonly InstallSnippet $install,
    ) {}

    public function index(): JsonResponse
    {
        $rows = Widget::query()->whereNull('deleted_at')->orderBy('name')->get();
        $out = [];
        foreach ($rows as $widget) {
            $out[] = $this->summary($widget);
        }

        return new JsonResponse(['widgets' => $out]);
    }

    public function show(string $widgetId): JsonResponse
    {
        $widget = $this->widget($widgetId);
        $live = $this->reader->live($widget);

        return new JsonResponse($this->summary($widget) + [
            'config' => $live['config'],
            'defaults' => WidgetConfigReader::defaults(),
            'allowed_origins' => $widget->allowed_origins ?? [],
            'has_secret' => is_string($widget->identity_secret_ref),
            'secret_rotated_at' => $widget->identity_secret_rotated_at?->toIso8601String(),
            'history' => $this->publisher->history($widget),
            'install' => $this->install->forWidget($widget),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{name: string, allowed_origins?: list<string>}|JsonResponse $input */
        $input = $this->input($request, ['name' => ['required', 'string', 'max:80'], 'allowed_origins' => ['sometimes', 'array', 'max:50'], 'allowed_origins.*' => ['string', 'max:255']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        try {
            $created = $this->designer->create($input['name'], $input['allowed_origins'] ?? [], $this->actor($request));
        } catch (ConfigRefused $e) {
            return $this->refused($e);
        }

        // The key plaintext is shown ONCE (Doc 02 FR-19 pattern); it is never readable again.
        return new JsonResponse($this->summary($created['widget']) + [
            'key_plaintext' => $created['key_plaintext'],
            'install' => $this->install->forWidget($created['widget'], $created['key_plaintext']), // the complete snippet, this once
        ], 201);
    }

    public function update(Request $request, string $widgetId): JsonResponse
    {
        $widget = $this->widget($widgetId);
        /** @var array{name?: string, bundle_channel?: string, test_mode?: bool}|JsonResponse $input */
        $input = $this->input($request, ['name' => ['sometimes', 'string', 'max:80'], 'bundle_channel' => ['sometimes', 'in:stable,early'], 'test_mode' => ['sometimes', 'boolean']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        if ($input === []) {
            $request->attributes->set('audit.noop', true); // A-37: nothing asked, nothing changed, nothing audited

            return new JsonResponse($this->summary($widget));
        }
        try {
            $result = $this->designer->update($widget, $input, $this->actor($request));
        } catch (ConfigRefused $e) {
            return $this->refused($e);
        }
        if ($result['changed'] === []) {
            $request->attributes->set('audit.noop', true); // same-state: the writer recorded nothing (A-37)
        }

        return new JsonResponse($this->summary($result['widget']));
    }

    public function retire(Request $request, string $widgetId): JsonResponse
    {
        $widget = $this->widget($widgetId);
        $widget = $this->designer->retire($widget, $this->actor($request));

        return new JsonResponse($this->summary($widget));
    }

    public function allowlist(Request $request, string $widgetId): JsonResponse
    {
        $widget = $this->widget($widgetId);
        /** @var array{allowed_origins: list<string>}|JsonResponse $input */
        $input = $this->input($request, ['allowed_origins' => ['present', 'array', 'max:50'], 'allowed_origins.*' => ['string', 'max:255']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        try {
            $result = $this->designer->setAllowedOrigins($widget, $input['allowed_origins'], $this->actor($request));
        } catch (ConfigRefused $e) {
            return $this->refused($e);
        }
        if ($result['added'] === [] && $result['removed'] === []) {
            $request->attributes->set('audit.noop', true);
        }

        return new JsonResponse($result);
    }

    public function createSecret(Request $request, string $widgetId): JsonResponse
    {
        return $this->secretDoor($request, $widgetId, fn (Widget $w): string => $this->designer->createSecret($w, $this->actor($request)));
    }

    public function rotateSecret(Request $request, string $widgetId): JsonResponse
    {
        return $this->secretDoor($request, $widgetId, fn (Widget $w): string => $this->designer->rotateSecret($w, $this->actor($request)));
    }

    /** @param  \Closure(Widget): string  $mint */
    private function secretDoor(Request $request, string $widgetId, \Closure $mint): JsonResponse
    {
        $widget = $this->widget($widgetId);
        try {
            $plaintext = $mint($widget);
        } catch (ConfigRefused $e) {
            return $this->refused($e);
        }
        $widget->refresh();

        return new JsonResponse([
            'secret_plaintext' => $plaintext, // shown once
            'rotated_at' => $widget->identity_secret_rotated_at?->toIso8601String(),
            'overlap_hours' => app(\App\Domain\Webchat\Identity\WidgetSecretMinter::class)->overlapHours(),
        ], 201);
    }

    /** The Designer's inline refusal at edit time — the SAME validation publish runs; nothing is written. */
    public function validateDraft(Request $request, string $widgetId): JsonResponse
    {
        $this->widget($widgetId);
        /** @var array{config: array<string, mixed>}|JsonResponse $input */
        $input = $this->input($request, ['config' => ['required', 'array']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        try {
            $result = $this->publisher->validate($input['config']);
        } catch (ConfigRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse(['ok' => true, 'warnings' => $result['warnings'], 'config' => $result['config']]);
    }

    public function publish(Request $request, string $widgetId): JsonResponse
    {
        $widget = $this->widget($widgetId);
        /** @var array{config: array<string, mixed>, note?: string}|JsonResponse $input */
        $input = $this->input($request, ['config' => ['required', 'array'], 'note' => ['sometimes', 'nullable', 'string', 'max:200']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        try {
            $result = $this->publisher->publish($widget, $input['config'], $this->actor($request), $input['note'] ?? null);
        } catch (ConfigRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse($result, 201);
    }

    public function rollback(Request $request, string $widgetId): JsonResponse
    {
        $widget = $this->widget($widgetId);
        /** @var array{version_id: string}|JsonResponse $input */
        $input = $this->input($request, ['version_id' => ['required', 'uuid']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        try {
            $result = $this->publisher->rollback($widget, $input['version_id'], $this->actor($request));
        } catch (ConfigRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse($result, 201);
    }

    public function health(string $widgetId): JsonResponse
    {
        $widget = $this->widget($widgetId);

        return new JsonResponse($this->health->read($widget, CarbonImmutable::now('UTC')));
    }

    private function widget(string $widgetId): Widget
    {
        $widget = Widget::query()->whereNull('deleted_at')->find($widgetId);
        if (! $widget instanceof Widget) {
            throw new NotFoundHttpException; // C-10: a foreign or retired widget is indistinguishable
        }

        return $widget;
    }

    /** @return array<string, mixed> */
    private function summary(Widget $widget): array
    {
        $live = $this->reader->live($widget);

        return [
            'id' => VisitorConversation::widgetId($widget),
            'name' => (string) $widget->name,
            'key_prefix' => $widget->key !== null ? $widget->key->prefix : '',
            'bundle_channel' => (string) ($widget->bundle_channel ?? 'stable'),
            'test_mode' => (bool) $widget->test_mode,
            'live_version' => $live['version'],
            'live_version_id' => $live['version_id'],
            'allowed_origins' => $widget->allowed_origins ?? [],
            'retired' => $widget->deleted_at !== null,
        ];
    }

    private function actor(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        return $user->identifier();
    }

    /**
     * Input refusals as RFC 9457 problem details (C-04) — not the framework's ValidationException, whose session
     * error bag the Backoffice group's web middleware would render for a JSON door.
     *
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    private function input(Request $request, array $rules): array|JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = array_map(static fn (array $messages): array => array_values(array_map('strval', $messages)), $validator->errors()->toArray());

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'the request does not validate', $errors, ['refusal_code' => 'invalid_input']);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    private function refused(ConfigRefused $e): JsonResponse
    {
        return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage(), extra: ['refusal_code' => $e->refusalCode] + $e->extra);
    }
}
