<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Honesty\Disclosure;
use App\Domain\Ai\KillSwitch\KillSwitches;
use App\Domain\Ai\Simulation\SimulationEngine;
use App\Domain\Ai\Simulation\ThresholdPublisher;
use App\Http\Controllers\Controller;
use App\Jobs\Ai\PersonaPreviewJob;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Cache\TenantCache;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Doc 09 FR-18 — the Backoffice (S09 step 12, Option A [B8]): Persona (with
 * the 5-conversation preview before publish), Thresholds (FR-11 sliders +
 * simulation → BR-07's token door), Guardrails (blocked topics, disclosure
 * WORDING, RG template, max_ai_messages, profanity policy, PII redaction
 * toggle), Scope (endpoints where the chatbot is on — a thresholds draft
 * with the same values, simulated, published through the SAME BR-07 door),
 * Kill switches (the grid + flip), Decisions (the log view).
 *
 * Route → permission from the CLOSED catalog: ai.chatbot.configure
 * (persona + scope), ai.thresholds.edit, ai.guardrails.edit, ai.killswitch,
 * ai.decisions.view. The provider switch DOOR (ai.providers.manage, the
 * fourth addition of record — S10 step 0.4, R-D10-1 as amended) is JSON
 * only here: the B8-S08 provider screen belongs to the [B8] fidelity pass.
 * Every mutation goes through the domain doors that audit (ConfigVersions
 * publish/rollback, KillSwitches::flip) — no second pen.
 */
final class AiBackofficeController extends Controller
{
    public function __construct(
        private readonly ConfigVersions $configs,
        private readonly KillSwitches $switches,
        private readonly SimulationEngine $simulation,
        private readonly ThresholdPublisher $thresholdPublisher,
        private readonly Disclosure $disclosure,
        private readonly TenantCache $cache,
        private readonly AuditLogger $audit,
    ) {}

    // ── Providers (S10 step 0.4 — the switch door; FR-02 zero-deploy) ──────

    /** The model map: published provider_routing (else the seed) per tier, plus the provider names config knows. */
    public function providers(): JsonResponse
    {
        return new JsonResponse([
            'tiers' => $this->routingMap(),
            'providers' => array_values(array_filter(array_keys((array) config('ai.providers')), 'is_string')),
            'published' => $this->versionOf('provider_routing'),
        ]);
    }

    /**
     * Switch provider/model per tier: validated against config's named providers,
     * drafted + published through ConfigVersions (instant, rollback-able), audited
     * as ai.providers.switched with the OLD and NEW chains (SEC-05 high-visibility).
     */
    public function providersRouting(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $known = array_values(array_filter(array_keys((array) config('ai.providers')), 'is_string'));
        try {
            /** @var array{tiers: array<string, list<array{provider: string, model: string}>>} $input */
            $input = $request->validate([
                'tiers' => ['required', 'array'],
                'tiers.fast' => ['required', 'array', 'min:1', 'max:4'],
                'tiers.strong' => ['required', 'array', 'min:1', 'max:4'],
                'tiers.embeddings' => ['required', 'array', 'min:1', 'max:4'],
                'tiers.*.*.provider' => ['required', 'string', 'in:'.implode(',', $known)],
                'tiers.*.*.model' => ['required', 'string', 'max:120'],
            ]);
        } catch (ValidationException $e) {
            // shouldRenderJsonWhen covers api/* only — the per-door interim (inherited row 5).
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid provider routing', errors: $errors);
        }
        if (count(array_diff(array_keys($input['tiers']), ['fast', 'strong', 'embeddings'])) > 0) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'unknown tier');
        }

        $old = $this->routingMap();
        $new = [];
        foreach (['fast', 'strong', 'embeddings'] as $tier) {
            $new[$tier] = array_map(static fn (array $hop): array => ['provider' => $hop['provider'], 'model' => $hop['model']], $input['tiers'][$tier]);
        }

        $versionId = $this->configs->draft('provider_routing', 'default', $new, $user->identifier());
        try {
            $this->configs->publish($versionId, $user->identifier());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }
        $this->audit->record('ai.providers.switched', 'ai_config_version', $versionId, AuditActor::Staff, [
            'old' => $old,
            'new' => $new,
        ], actorId: $user->identifier());

        return new JsonResponse(['version_id' => $versionId, 'published' => true, 'tiers' => $new]);
    }

    /** @return array<string, list<array{provider: string, model: string}>> */
    private function routingMap(): array
    {
        $orchestrator = app(\App\Domain\Ai\Orchestrator\AiOrchestrator::class);
        $map = [];
        foreach (['fast', 'strong', 'embeddings'] as $tier) {
            $map[$tier] = $orchestrator->chainFor($tier);
        }

        return $map;
    }

    // ── Persona ──────────────────────────────────────────────────────────

    public function persona(): Response
    {
        return Inertia::render('Admin/Ai/Persona', [
            't' => __('ai.backoffice'),
            'published' => $this->versionOf('persona'),
            'history' => $this->history('persona'),
            'disclosure' => ['en' => $this->disclosure->wording('en'), 'am' => $this->disclosure->wording('am')],
            'languages' => Disclosure::LANGUAGES,
        ]);
    }

    /** Stage a persona draft (name, avatar ref, greeting per language, tone sliders). */
    public function personaDraft(Request $request): JsonResponse
    {
        try {
            /** @var array{name: string, avatar_ref?: string, greeting: array<string, string>, tone: array{formality: int, detail: int}} $input */
            $input = $request->validate([
                'name' => ['required', 'string', 'max:80'],
                'avatar_ref' => ['sometimes', 'nullable', 'string', 'max:200'],
                'greeting' => ['required', 'array'],
                'greeting.en' => ['sometimes', 'string', 'max:600'],
                'greeting.am' => ['sometimes', 'string', 'max:600'],
                'tone' => ['required', 'array'],
                'tone.formality' => ['required', 'integer', 'min:0', 'max:100'],
                'tone.detail' => ['required', 'integer', 'min:0', 'max:100'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $user */
        $user = $request->user();
        $id = $this->configs->draft('persona', 'default', $input, $user->identifier());

        return new JsonResponse(['version_id' => $id], 201);
    }

    /** FR-18: the 5-conversation preview of a DRAFT — queued; the page polls. */
    public function personaPreview(Request $request, string $versionId): JsonResponse
    {
        $payload = $this->draftPayload('persona', $versionId);
        if ($payload === null) {
            return $this->notFound();
        }
        $previewId = (string) Str::uuid7();
        $this->cache->put(PersonaPreviewJob::cacheKey($previewId), ['state' => 'pending'], 900, 'TTL');
        PersonaPreviewJob::dispatch($previewId, $payload);

        return new JsonResponse(['preview_id' => $previewId, 'dialogs' => PersonaPreviewJob::DIALOGS], 202);
    }

    public function personaPreviewShow(string $previewId): JsonResponse
    {
        $state = $this->cache->get(PersonaPreviewJob::cacheKey($previewId));

        return new JsonResponse(is_array($state) ? $state : ['state' => 'unknown']);
    }

    // ── Guardrails ───────────────────────────────────────────────────────

    public function guardrails(): Response
    {
        return Inertia::render('Admin/Ai/Guardrails', [
            't' => __('ai.backoffice'),
            'published' => $this->versionOf('guardrails'),
            'history' => $this->history('guardrails'),
            'defaults' => [
                'disclosure' => ['en' => __('ai.disclosure.sentence', [], 'en'), 'am' => __('ai.disclosure.sentence', [], 'am')],
                'rg_template' => ['en' => __('ai.rg.template', [], 'en'), 'am' => __('ai.rg.template', [], 'am')],
                'blocked_topics' => \App\Domain\Ai\Chatbot\BlockedTopics::SEEDED,
            ],
        ]);
    }

    public function guardrailsDraft(Request $request): JsonResponse
    {
        try {
            /** @var array<string, mixed> $input */
            $input = $request->validate([
                'blocked_topics' => ['required', 'array', 'max:100'],
                'blocked_topics.*' => ['string', 'max:80'],
                'disclosure' => ['sometimes', 'array'],
                'disclosure.en' => ['sometimes', 'nullable', 'string', 'max:300'],
                'disclosure.am' => ['sometimes', 'nullable', 'string', 'max:300'],
                'rg_template' => ['sometimes', 'array'],
                'rg_template.en' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'rg_template.am' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'max_ai_messages' => ['required', 'integer', 'min:1', 'max:50'],
                'profanity_policy' => ['required', 'string', 'in:block,soften,allow'],
                'pii_redaction' => ['required', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $user */
        $user = $request->user();
        $id = $this->configs->draft('guardrails', 'default', $input, $user->identifier());

        return new JsonResponse(['version_id' => $id], 201);
    }

    // ── Thresholds + simulation + scope ───────────────────────────────────

    public function thresholds(): Response
    {
        return Inertia::render('Admin/Ai/Thresholds', [
            't' => __('ai.backoffice'),
            'published' => $this->versionOf('thresholds'),
            'endpoints' => $this->endpoints(),
            'history' => $this->history('thresholds'),
        ]);
    }

    /** FR-11/US-06: yesterday recomputed for the slider values — returns the BR-07 token. */
    public function simulate(Request $request): JsonResponse
    {
        try {
            /** @var array{endpoint?: string, day?: string, rg_min: float, spam_min: float, handoff_below: float, auto_resolve_min: float} $input */
            $input = $request->validate([
                'endpoint' => ['sometimes', 'nullable', 'string', 'max:80'],
                'day' => ['sometimes', 'date'],
                'rg_min' => ['required', 'numeric', 'min:0', 'max:1'],
                'spam_min' => ['required', 'numeric', 'min:0', 'max:1'],
                'handoff_below' => ['required', 'numeric', 'min:0', 'max:1'],
                'auto_resolve_min' => ['required', 'numeric', 'min:0', 'max:1'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        $endpoint = isset($input['endpoint']) && $input['endpoint'] !== '' && $input['endpoint'] !== 'default' ? $input['endpoint'] : null;
        /** @var object{d: string} $dayRow */
        $dayRow = DB::selectOne("SELECT (date_trunc('day', clock_timestamp()) - interval '1 day')::text AS d");
        $day = isset($input['day']) ? $input['day'].' 00:00:00+00' : $dayRow->d;
        $after = ['rg_min' => (float) $input['rg_min'], 'spam_min' => (float) $input['spam_min'], 'handoff_below' => (float) $input['handoff_below'], 'auto_resolve_min' => (float) $input['auto_resolve_min']];
        $current = $this->currentThresholds($endpoint);

        $before = $this->simulation->simulateDay($day, $current, $endpoint);
        $result = $this->simulation->simulateDay($day, $after, $endpoint);
        $deltas = [];
        foreach ($result['outcomes'] as $k => $v) {
            $deltas[$k] = $v - ($before['outcomes'][$k] ?? 0);
        }

        return new JsonResponse(['day' => $day, 'endpoint' => $endpoint ?? 'default', 'before' => $before['outcomes'], 'after' => $result['outcomes'], 'deltas' => $deltas, 'total' => $result['total'], 'token' => $result['token'], 'values' => $after]);
    }

    /** BR-07: draft + publish through the token door, in one step. */
    public function thresholdsPublish(Request $request): JsonResponse
    {
        try {
            /** @var array{endpoint?: string, day: string, token: string, chatbot_enabled: bool, languages?: list<string>, rg_min: float, spam_min: float, handoff_below: float, auto_resolve_min: float} $input */
            $input = $request->validate([
                'endpoint' => ['sometimes', 'nullable', 'string', 'max:80'],
                'day' => ['required', 'string', 'max:40'],
                'token' => ['required', 'string', 'size:64'],
                'chatbot_enabled' => ['required', 'boolean'],
                'languages' => ['sometimes', 'array', 'min:1', 'max:10'],
                'languages.*' => ['string', 'size:2'],
                'rg_min' => ['required', 'numeric', 'min:0', 'max:1'],
                'spam_min' => ['required', 'numeric', 'min:0', 'max:1'],
                'handoff_below' => ['required', 'numeric', 'min:0', 'max:1'],
                'auto_resolve_min' => ['required', 'numeric', 'min:0', 'max:1'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $user */
        $user = $request->user();
        $endpointKey = isset($input['endpoint']) && $input['endpoint'] !== '' ? $input['endpoint'] : 'default';
        $languages = $input['languages'] ?? \App\Domain\Ai\Language\LanguageGate::LAUNCH_LANGUAGES;
        $payload = ['chatbot_enabled' => $input['chatbot_enabled'], 'languages' => $languages, 'rg_min' => (float) $input['rg_min'], 'spam_min' => (float) $input['spam_min'], 'handoff_below' => (float) $input['handoff_below'], 'auto_resolve_min' => (float) $input['auto_resolve_min']];
        $versionId = $this->configs->draft('thresholds', $endpointKey, $payload, $user->identifier());
        try {
            $this->thresholdPublisher->publish($versionId, $input['token'], $input['day'], $user->identifier());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['version_id' => $versionId, 'published' => true]);
    }

    // ── Publish / rollback (persona + guardrails) ─────────────────────────

    public function publish(Request $request, string $versionId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $kind = DB::table('ai_config_versions')->where('id', $versionId)->value('kind');
        if (! in_array($kind, ['persona', 'guardrails'], true)) {
            return $this->notFound(); // thresholds publish through BR-07's door only
        }
        if (! $this->allowsKind($user, (string) $kind)) {
            return ProblemDetails::response(ErrorCode::Forbidden, 403, 'forbidden');
        }
        try {
            $this->configs->publish($versionId, $user->identifier());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['version_id' => $versionId, 'published' => true]);
    }

    public function rollback(Request $request, string $versionId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $kind = DB::table('ai_config_versions')->where('id', $versionId)->value('kind');
        if (! is_string($kind) || ! $this->allowsKind($user, $kind)) {
            return $this->notFound();
        }
        try {
            $newId = $this->configs->rollbackTo($versionId, $user->identifier());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['version_id' => $newId, 'published' => true]);
    }

    // ── Kill switches ────────────────────────────────────────────────────

    public function killSwitches(): Response
    {
        return Inertia::render('Admin/Ai/KillSwitches', [
            't' => __('ai.backoffice'),
            'grid' => $this->switches->grid(),
            'surfaces' => KillSwitches::SURFACES,
            'endpoints' => $this->endpoints(),
        ]);
    }

    public function killSwitchFlip(Request $request): JsonResponse
    {
        try {
            /** @var array{surface: string, channel?: ?string, endpoint_ref?: ?string, off: bool, reason?: ?string, confirm: bool} $input */
            $input = $request->validate([
                'surface' => ['required', 'string', 'in:chatbot,copilot,supervisor'],
                'channel' => ['sometimes', 'nullable', 'string', 'max:40'],
                'endpoint_ref' => ['sometimes', 'nullable', 'string', 'max:80'],
                'off' => ['required', 'boolean'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
                'confirm' => ['required', 'accepted'], // FR-18: "big red UI with confirmation"
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $user */
        $user = $request->user();
        try {
            $id = $this->switches->flip($input['surface'], $input['channel'] ?? null, $input['endpoint_ref'] ?? null, $input['off'], $input['reason'] ?? null, $user->identifier());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['switch_id' => $id, 'grid' => $this->switches->grid()]);
    }

    /** AC-08 banner: the OFF rows (read by the workspace strip). */
    public function banner(): JsonResponse
    {
        return new JsonResponse(['off' => $this->switches->bannerState()]);
    }

    // ── Decisions ────────────────────────────────────────────────────────

    public function decisions(): Response
    {
        return Inertia::render('Admin/Ai/Decisions', ['t' => __('ai.backoffice')]);
    }

    // ── internals ────────────────────────────────────────────────────────

    /** @return array{id: string, version: int, payload: array<string, mixed>, published_at: ?string}|null */
    private function versionOf(string $kind): ?array
    {
        $row = $this->configs->published($kind, 'default');
        if ($row === null) {
            return null;
        }
        $payload = json_decode($row->payload, true);
        $keyed = [];
        foreach (is_array($payload) ? $payload : [] as $k => $v) {
            if (is_string($k)) {
                $keyed[$k] = $v;
            }
        }

        return ['id' => $row->id, 'version' => (int) $row->version, 'payload' => $keyed, 'published_at' => $row->published_at];
    }

    /** @return list<array{id: string, key: string, version: int, status: string, created_at: string}> */
    private function history(string $kind): array
    {
        $out = [];
        /** @var list<object{id: string, key: string, version: int|string, status: string, created_at: string}> $rows */
        $rows = DB::table('ai_config_versions')->where('kind', $kind)->orderByDesc('version')->limit(20)->get(['id', 'key', 'version', 'status', DB::raw('created_at::text AS created_at')])->all();
        foreach ($rows as $row) {
            $out[] = ['id' => $row->id, 'key' => $row->key, 'version' => (int) $row->version, 'status' => $row->status, 'created_at' => $row->created_at];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function draftPayload(string $kind, string $versionId): ?array
    {
        $row = DB::table('ai_config_versions')->where('id', $versionId)->where('kind', $kind)->first(['payload']);
        if ($row === null || ! is_string($row->payload)) {
            return null;
        }
        $payload = json_decode($row->payload, true);
        $keyed = [];
        foreach (is_array($payload) ? $payload : [] as $k => $v) {
            if (is_string($k)) {
                $keyed[$k] = $v;
            }
        }

        return $keyed;
    }

    /** @return array<string, float> */
    private function currentThresholds(?string $endpoint): array
    {
        $thresholds = app(\App\Domain\Ai\Pipeline\Thresholds::class);

        return [
            'rg_min' => $thresholds->value('rg_min', $endpoint), 'spam_min' => $thresholds->value('spam_min', $endpoint),
            'handoff_below' => $thresholds->value('handoff_below', $endpoint), 'auto_resolve_min' => $thresholds->value('auto_resolve_min', $endpoint),
        ];
    }

    /**
     * Endpoints = telegram bots (+ the widget seam [D13]); each with its chatbot_enabled answer.
     *
     * @return list<array{ref: string, label: string, channel: string, chatbot_enabled: bool}>
     */
    private function endpoints(): array
    {
        $out = [['ref' => 'default', 'label' => 'default', 'channel' => 'all', 'chatbot_enabled' => $this->enabled('default')]];
        /** @var list<object{id: string, username: string}> $bots */
        $bots = DB::table('telegram_bots')->where('status', 'active')->orderBy('created_at')->get(['id', 'username'])->all();
        foreach ($bots as $bot) {
            $out[] = ['ref' => $bot->id, 'label' => '@'.$bot->username, 'channel' => 'telegram', 'chatbot_enabled' => $this->enabled($bot->id)];
        }

        return $out;
    }

    private function enabled(string $key): bool
    {
        $row = $this->configs->published('thresholds', $key);
        if ($row === null) {
            return false; // §21 rec 2: default OFF
        }
        $payload = json_decode($row->payload, true);

        return is_array($payload) && ($payload['chatbot_enabled'] ?? false) === true;
    }

    private function allowsKind(User $user, string $kind): bool
    {
        $key = $kind === 'persona' ? 'ai.chatbot.configure' : 'ai.guardrails.edit';

        return app(\App\Support\Rbac\CapabilityMapProvider::class)->verdictFor($user, $key)->allowed;
    }

    private function notFound(): JsonResponse
    {
        return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
    }
}
