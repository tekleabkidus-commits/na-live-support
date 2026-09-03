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

    /**
     * The model map: published provider_routing (else the seed) per tier, plus the provider names config knows.
     * JSON callers (the S10 step-0.4 door contract) get the same body as before; a browser navigation
     * (Inertia / text-html) gets the B8-S08 page — SESSION-F1 content negotiation, no second route.
     */
    public function providers(Request $request): JsonResponse|Response
    {
        $json = [
            'tiers' => $this->routingMap(),
            'providers' => array_values(array_filter(array_keys((array) config('ai.providers')), 'is_string')),
            'published' => $this->versionOf('provider_routing'),
        ];
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return new JsonResponse($json);
        }

        return Inertia::render('Admin/Ai/Providers', $json + [
            't' => __('ai.backoffice'),
            'breakers' => $this->breakerStates($json['providers']),
            'credentials' => $this->credentialRefs($json['providers']),
            'versions' => $this->configVersionsOverview(),
            'honesty_hash' => \App\Domain\Ai\Prompt\ImmutableLayer::hash(),
            'quota' => $this->quotaState(),
            'setup' => $this->setupState(),
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
            // F1 (B8-S04 D02/D05, B8-S11 K04): the canned conversations the preview runs, the §14 gate per language, the setup state.
            'dialogs' => $this->personaDialogs(),
            'gates' => $this->languageGates(),
            'setup' => $this->setupState(),
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
            // F1 (B8-S05 E05/E06, B8-S11 K04): the per-endpoint overrides, the NFR-07 precision facts, the setup state.
            'overrides' => $this->thresholdOverrides(),
            'rg' => ['target' => 0.90, 'corpus' => count(app(\App\Domain\Ai\Rg\RgEvalHarness::class)->corpus())],
            'setup' => $this->setupState(),
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

    /** F1 (B8-S05 E03): the conversations that flip between the published and the pending values — read-only, anonymized. */
    public function flips(Request $request): JsonResponse
    {
        try {
            /** @var array{endpoint?: string, day: string, rg_min: float, spam_min: float, handoff_below: float, auto_resolve_min: float} $input */
            $input = $request->validate([
                'endpoint' => ['sometimes', 'nullable', 'string', 'max:80'],
                'day' => ['required', 'string', 'max:40'],
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
        $after = ['rg_min' => (float) $input['rg_min'], 'spam_min' => (float) $input['spam_min'], 'handoff_below' => (float) $input['handoff_below'], 'auto_resolve_min' => (float) $input['auto_resolve_min']];

        return new JsonResponse($this->simulation->flips($input['day'], $this->currentThresholds($endpoint), $after, $endpoint));
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
            'endpoints' => $this->endpointsWithVolume(),
            // F1 (B8-S07 G01/G04/G06): the scope facts, the blast-radius volumes, the audit strip — all reads.
            'scope' => ['categories' => $this->scopeCategories()],
            'audit' => $this->switchAudit(),
            'setup' => $this->setupState(),
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
        $weekly = app(\App\Domain\Ai\DecisionLog\WeeklyOutputs::class);
        /** @var list<object{id: string, kind: string, version: int|string}> $versions */
        $versions = DB::table('ai_config_versions')->get(['id', 'kind', 'version'])->all();
        $labels = [];
        foreach ($versions as $v) {
            $labels[$v->id] = self::s(__('ai.backoffice.kind_'.$v->kind) ?: $v->kind).' v'.$v->version;
        }
        $categories = [];
        /** @var list<object{id: string, name: string}> $cats */
        $cats = DB::table('kb_categories')->whereNull('deleted_at')->orderBy('depth')->orderBy('position')->get(['id', 'name'])->all();
        foreach ($cats as $cat) {
            $name = json_decode($cat->name, true);
            $categories[] = ['id' => $cat->id, 'name' => is_array($name) ? self::s($name['en'] ?? reset($name)) : (string) $cat->name];
        }

        return Inertia::render('Admin/Ai/Decisions', [
            't' => __('ai.backoffice'),
            // F1 (B8-S09 I01/I04/I05): the today count, the config-version labels, the two quality tabs' reads.
            'counts' => ['today' => (int) DB::table('ai_decisions')->whereRaw("created_at >= date_trunc('day', clock_timestamp())")->count()],
            'config_versions' => $labels,
            'unanswerable' => $weekly->topUnanswerable(),
            'calibration' => $weekly->calibrationCurve(),
            'kb_categories' => $categories,
            'setup' => $this->setupState(),
        ]);
    }

    /**
     * SESSION-F1 (B8-S10, rulings Q2/Q3): the supervisor's ARTEFACTS — the feed (anomaly / sentiment / pause notes),
     * the latest digest, the QA pre-scores, and the RG precision review queue for the RG key only (J06: absent,
     * not locked, for everyone else). Reads only: the supervisor rows of the decision log are FR-17's outputs.
     */
    public function supervisor(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $capabilities = app(\App\Support\Rbac\CapabilityMapProvider::class)->mapFor($user);
        $t = __('ai.backoffice');
        $tr = static function (string $key, array $vars) use ($t): string {
            $out = is_array($t) && is_string($t[$key] ?? null) ? $t[$key] : $key;
            foreach ($vars as $k => $v) {
                $out = str_replace(':'.$k, self::s($v), $out);
            }

            return $out;
        };

        $feed = [];
        $digest = null;
        $qa = [];
        /** @var list<object{id: string, task: string, action: string, response_payload: ?string, conversation_id: ?string, ticket_id: ?string, language: ?string, created_at: string}> $rows */
        $rows = DB::table('ai_decisions')->where('surface', 'supervisor')->orderByDesc('created_at')->limit(60)
            ->get(['id', 'task', 'action', 'response_payload', 'conversation_id', 'ticket_id', 'language', DB::raw('created_at::text AS created_at')])->all();
        foreach ($rows as $row) {
            $payload = json_decode(is_string($row->response_payload) ? $row->response_payload : '', true);
            $payload = is_array($payload) ? $payload : [];
            if ($row->action === 'paused') {
                $feed[] = ['kind' => 'paused', 'at' => $row->created_at, 'title' => $tr('paused_title', []), 'body' => $tr('paused_body', ['reason' => self::s($payload['reason'] ?? '—')]), 'conversation_id' => null, 'holder' => null];
            } elseif ($row->action === 'anomaly_scan') {
                foreach (is_array($payload['alerts'] ?? null) ? $payload['alerts'] : [] as $alert) {
                    if (! is_array($alert)) {
                        continue;
                    }
                    $kind = self::s($alert['kind'] ?? 'volume');
                    $vars = ['intent' => self::s($alert['intent'] ?? ''), 'pct' => self::s($alert['delta_pct'] ?? $alert['pct'] ?? ''), 'since' => substr(self::s($payload['since'] ?? $row->created_at), 11, 5), 'current' => self::s($alert['current'] ?? ''), 'baseline' => self::s($alert['baseline'] ?? ''), 'drop' => self::s($alert['drop'] ?? $alert['delta'] ?? '')];
                    $feed[] = ['kind' => 'anomaly', 'at' => $row->created_at, 'title' => $tr('anomaly_'.$kind.'_title', $vars), 'body' => $tr('anomaly_'.$kind.'_body', $vars), 'conversation_id' => null, 'holder' => null];
                }
            } elseif ($row->action === 'sentiment_watch') {
                $holder = null;
                if (is_string($row->conversation_id)) {
                    $holder = DB::table('tickets as tk')->leftJoin('users as u', 'u.id', '=', 'tk.assignee_id')->where('tk.conversation_id', $row->conversation_id)->orderByDesc('tk.created_at')->value('u.name');
                }
                $feed[] = ['kind' => 'sentiment', 'at' => $row->created_at, 'title' => $tr('sentiment_title', []),
                    'body' => $tr('sentiment_body', ['sentiment' => self::s($payload['sentiment'] ?? ''), 'intent' => self::s($payload['intent'] ?? '—'), 'holder' => is_string($holder) ? $tr('holder_named', ['name' => $holder]) : $tr('holder_none', [])]),
                    'conversation_id' => $row->conversation_id, 'holder' => is_string($holder) ? $holder : null];
            } elseif ($row->action === 'daily_digest' && $digest === null) {
                $bullets = is_array($payload['bullets'] ?? null) ? array_values(array_filter($payload['bullets'], 'is_string')) : [];
                $digest = ['at' => $row->created_at, 'bullets' => $bullets, 'language' => $row->language ?? 'en', 'source' => self::s($payload['source'] ?? 'template')];
            } elseif ($row->action === 'qa_prescore' && count($qa) < 10) {
                $ticket = is_string($row->ticket_id) ? DB::table('tickets as tk')->leftJoin('users as u', 'u.id', '=', 'tk.assignee_id')->where('tk.id', $row->ticket_id)->first(['tk.number', 'u.name']) : null;
                $qa[] = ['ticket_id' => (string) $row->ticket_id, 'ticket_number' => is_object($ticket) && is_numeric($ticket->number) ? (int) $ticket->number : null,
                    'score' => is_numeric($payload['score'] ?? null) ? (int) $payload['score'] : null, 'flags' => is_array($payload['flags'] ?? null) ? array_values(array_filter($payload['flags'], 'is_string')) : [],
                    'rubric_version' => is_scalar($payload['rubric_version'] ?? null) ? (string) $payload['rubric_version'] : null, 'at' => $row->created_at, 'agent' => is_object($ticket) && is_string($ticket->name) ? $ticket->name : null];
            }
        }

        $rg = null;
        if ($capabilities->allows('customers.rg_flag.view')) {
            $queue = app(\App\Domain\Ai\Rg\RgCalibration::class)->reviewQueue(100);
            $month = array_filter($queue, static fn (array $q): bool => strtotime($q['fired_at']) >= strtotime('-30 days'));
            $cleared = count(array_filter($month, static fn (array $q): bool => $q['cleared_at'] !== null));
            $fires = count($month);
            $items = [];
            foreach (array_slice($queue, 0, 20) as $q) {
                $conversation = is_string($q['decision_id']) ? DB::table('ai_decisions')->where('id', $q['decision_id'])->value('conversation_id') : null;
                $items[] = $q + ['conversation_id' => is_string($conversation) ? $conversation : null, 'customer_url' => '/customers/'.$q['customer_id']];
            }
            $rg = ['fires' => $fires, 'cleared' => $cleared, 'kept' => $fires - $cleared, 'precision' => $fires > 0 ? round(($fires - $cleared) / $fires, 2) : null, 'items' => $items];
        }

        return Inertia::render('Admin/Ai/Supervisor', ['t' => $t, 'feed' => $feed, 'digest' => $digest, 'qa' => $qa, 'rg' => $rg, 'setup' => $this->setupState()]);
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
        /** @var list<object{id: string, key: string, version: int|string, status: string, created_at: string, published_at: ?string, published_by_name: ?string}> $rows */
        $rows = DB::table('ai_config_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.published_by')
            ->where('v.kind', $kind)->orderByDesc('v.version')->limit(20)
            ->get(['v.id', 'v.key', 'v.version', 'v.status', DB::raw('v.created_at::text AS created_at'), DB::raw('v.published_at::text AS published_at'), 'u.name AS published_by_name'])->all();
        foreach ($rows as $row) {
            // F1 (B8-S04 D03): the version row names its publish time and actor — read-only, additive.
            $out[] = ['id' => $row->id, 'key' => $row->key, 'version' => (int) $row->version, 'status' => $row->status, 'created_at' => $row->created_at,
                'published_at' => $row->published_at, 'published_by_name' => is_string($row->published_by_name) ? $row->published_by_name : null];
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

    // ── SESSION-F1 [B8] read-only presentation data ─────────────────────

    /**
     * B8-S04 D02: the five canned conversations the preview replays (fixtures/ai/golden), customer turns only.
     *
     * @return list<array{name: string, language: string, turns: list<array{role: string, content: string}>}>
     */
    private function personaDialogs(): array
    {
        $out = [];
        foreach (PersonaPreviewJob::DIALOGS as $name) {
            $decoded = json_decode((string) @file_get_contents(base_path('fixtures/ai/golden/'.$name.'.json')), true);
            if (! is_array($decoded)) {
                continue;
            }
            $turns = [];
            foreach (is_array($decoded['dialog'] ?? null) ? $decoded['dialog'] : [] as $turn) {
                if (is_array($turn) && is_string($turn['role'] ?? null) && is_string($turn['content'] ?? null)) {
                    $turns[] = ['role' => $turn['role'], 'content' => $turn['content']];
                }
            }
            $out[] = ['name' => $name, 'language' => is_string($decoded['language'] ?? null) ? $decoded['language'] : 'en', 'turns' => $turns];
        }

        return $out;
    }

    /**
     * B8-S04 D05 / §14: what each launch language is still missing before it may go live (empty = complete).
     *
     * @return array<string, list<string>>
     */
    private function languageGates(): array
    {
        $gate = app(\App\Domain\Ai\Language\LanguageGate::class);
        $out = [];
        foreach (Disclosure::LANGUAGES as $language) {
            $out[$language] = $gate->missingTemplates($language);
        }

        return $out;
    }

    /**
     * B8-S11 K04: the setup checklist's facts — does published, AI-usable knowledge exist yet?
     *
     * @return array{kb_published: bool, persona_published: bool, categories_ai_usable: bool, kb_url: string, categories_url: string}
     */
    private function setupState(): array
    {
        return [
            'kb_published' => DB::table('kb_article_versions')->exists(),
            'persona_published' => $this->versionOf('persona') !== null,
            'categories_ai_usable' => DB::table('kb_categories')->where('ai_usable', true)->whereNull('deleted_at')->exists(),
            'kb_url' => '/admin/kb',
            'categories_url' => '/admin/kb?pane=categories',
        ];
    }

    /**
     * B8-S05 E05: every endpoint's PUBLISHED thresholds (the default first) — bold-vs-plain is the page's.
     *
     * @return array<string, array{version: int, values: array<string, float>}>
     */
    private function thresholdOverrides(): array
    {
        $out = [];
        foreach ($this->endpoints() as $endpoint) {
            $row = $this->configs->published('thresholds', $endpoint['ref']);
            if ($row === null) {
                continue;
            }
            $payload = json_decode($row->payload, true);
            $values = [];
            foreach (['rg_min', 'spam_min', 'handoff_below', 'auto_resolve_min'] as $key) {
                if (is_array($payload) && is_numeric($payload[$key] ?? null)) {
                    $values[$key] = (float) $payload[$key];
                }
            }
            $out[$endpoint['ref']] = ['version' => (int) $row->version, 'values' => $values];
        }

        return $out;
    }

    /**
     * B8-S07 G01: the KB categories the chatbot may answer from — published article counts, languages, the INTERNAL lock.
     *
     * @return list<array{id: string, name: string, ai_usable: bool, internal: bool, published: int, languages: list<string>}>
     */
    private function scopeCategories(): array
    {
        $out = [];
        /** @var list<object{id: string, name: string, ai_usable: bool, visibility_default: string}> $categories */
        $categories = DB::table('kb_categories')->whereNull('deleted_at')->orderBy('depth')->orderBy('position')->get(['id', 'name', 'ai_usable', 'visibility_default'])->all();
        foreach ($categories as $category) {
            $name = json_decode($category->name, true);
            $label = is_array($name) ? self::s($name[app()->getLocale()] ?? $name['en'] ?? reset($name)) : (string) $category->name;
            /** @var list<object{language: string, n: int|string}> $rows */
            $rows = DB::table('kb_article_variants as v')->join('kb_articles as a', 'a.id', '=', 'v.article_id')
                ->where('a.category_id', $category->id)->where('v.status', 'published')->whereNull('a.deleted_at')
                ->groupBy('v.language')->get([DB::raw('v.language'), DB::raw('count(*) AS n')])->all();
            $published = 0;
            $languages = [];
            foreach ($rows as $row) {
                $published += (int) $row->n;
                $languages[] = $row->language;
            }
            $out[] = ['id' => $category->id, 'name' => $label, 'ai_usable' => (bool) $category->ai_usable, 'internal' => $category->visibility_default === 'internal', 'published' => $published, 'languages' => $languages];
        }

        return $out;
    }

    /**
     * B8-S07 G04: each endpoint with its 7-day conversations/day average — the blast radius in its own numbers.
     *
     * @return list<array{ref: string, label: string, channel: string, chatbot_enabled: bool, conversations_per_day: int}>
     */
    private function endpointsWithVolume(): array
    {
        $out = [];
        foreach ($this->endpoints() as $endpoint) {
            $count = $endpoint['ref'] === 'default'
                ? DB::table('conversations')->whereRaw("created_at >= clock_timestamp() - interval '7 days'")->count()
                : DB::table('conversations')->where('channel_ref_id', $endpoint['ref'])->whereRaw("created_at >= clock_timestamp() - interval '7 days'")->count();
            $out[] = $endpoint + ['conversations_per_day' => (int) round($count / 7)];
        }

        return $out;
    }

    /**
     * B8-S07 G06 / SEC-05: the recent kill-switch flips from the audit log (the high-visibility class), newest first.
     *
     * @return list<array{surface: string, scope: string, off: bool, at: string, actor: string}>
     */
    private function switchAudit(): array
    {
        $out = [];
        /** @var list<object{payload: ?string, created_at: string, name: ?string}> $rows */
        $rows = DB::table('audit_log as l')->leftJoin('users as u', 'u.id', '=', 'l.actor_id')
            ->where('l.action_key', 'ai.killswitch.changed')->orderByDesc('l.created_at')->limit(5)
            ->get(['l.payload', DB::raw('l.created_at::text AS created_at'), 'u.name'])->all();
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            $payload = is_array($payload) ? $payload : [];
            $endpoint = is_string($payload['endpoint_ref'] ?? null) ? $payload['endpoint_ref'] : null;
            $channel = is_string($payload['channel'] ?? null) ? $payload['channel'] : null;
            $out[] = [
                'surface' => is_string($payload['surface'] ?? null) ? $payload['surface'] : '',
                'scope' => $endpoint ?? $channel ?? __('ai.backoffice.col_all'),
                'off' => (bool) (is_array($payload['new'] ?? null) ? ($payload['new']['is_off'] ?? false) : ($payload['is_off'] ?? false)),
                'at' => substr($row->created_at, 0, 16),
                'actor' => is_string($row->name) ? $row->name : '—',
            ];
        }

        return $out;
    }

    /**
     * B8-S08 H01/H05: every configured provider's breaker state (open = tripped) and last observed health, in words.
     *
     * @param  list<string>  $providers
     * @return array<string, array{open: bool, health: ?string}>
     */
    private function breakerStates(array $providers): array
    {
        $breaker = app(\App\Domain\Ai\Providers\CircuitBreaker::class);
        $health = app(\App\Domain\Ai\Providers\ProviderHealth::class);
        $out = [];
        foreach ($providers as $provider) {
            $out[$provider] = ['open' => $breaker->isOpen($provider), 'health' => $health->current($provider)];
        }

        return $out;
    }

    /**
     * B8-S08 H03 / SEC-01: credentials as REFERENCES only — the vault slot a provider's key lives in; never a character of it.
     *
     * @param  list<string>  $providers
     * @return list<array{provider: string, ref: string}>
     */
    private function credentialRefs(array $providers): array
    {
        $out = [];
        foreach ($providers as $provider) {
            $key = config('ai.providers.'.$provider.'.key');
            if ($key === null) {
                continue; // local / replay / fake drivers hold no credential
            }
            $out[] = ['provider' => $provider, 'ref' => 'vault://ai/'.$provider];
        }

        return $out;
    }

    /**
     * B8-S08 H04: what is live per config kind, since when, by whom, and the way back.
     *
     * @return list<array{kind: string, version: ?int, published_at: ?string, published_by_name: ?string, rollback_to: ?int, rollback_id: ?string, own_screen: ?string}>
     */
    private function configVersionsOverview(): array
    {
        $screens = ['persona' => '/admin/ai/persona', 'thresholds' => '/admin/ai/thresholds', 'guardrails' => '/admin/ai/guardrails', 'provider_routing' => null, 'prompt_template' => null, 'rg_classifier' => null];
        $out = [];
        foreach (array_keys($screens) as $kind) {
            /** @var object{version: int|string, published_at: ?string, name: ?string}|null $live */
            $live = DB::table('ai_config_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.published_by')
                ->where('v.kind', $kind)->where('v.key', 'default')->where('v.status', 'published')
                ->first(['v.version', DB::raw('v.published_at::text AS published_at'), 'u.name']);
            /** @var object{id: string, version: int|string}|null $previous */
            $previous = $live === null ? null : DB::table('ai_config_versions')->where('kind', $kind)->where('key', 'default')->where('status', 'superseded')->orderByDesc('version')->first(['id', 'version']);
            $rollbackAllowed = in_array($kind, ['persona', 'guardrails'], true); // the rollback door's own scope (thresholds go through BR-07)
            $out[] = [
                'kind' => $kind,
                'version' => $live === null ? null : (int) $live->version,
                'published_at' => $live?->published_at,
                'published_by_name' => is_string($live?->name) ? $live->name : null,
                'rollback_to' => $previous === null || ! $rollbackAllowed ? null : (int) $previous->version,
                'rollback_id' => $previous === null || ! $rollbackAllowed ? null : $previous->id,
                'own_screen' => $screens[$kind],
            ];
        }

        return $out;
    }

    /**
     * B8-S08 H06 / NFR-06 / EC-12: the daily token quota — used, limit, the reserve band, the reset.
     *
     * @return array{used: int, limit: int, pct: int, reserve_permille: int, resets_at: string}
     */
    private function quotaState(): array
    {
        try {
            $headroom = app(\App\Support\Quota\QuotaMeter::class)->headroom(\App\Support\Quota\Quota::AiTokensPerDay);
        } catch (\Throwable) {
            $headroom = ['used' => 0, 'limit' => 0];
        }
        $reserve = app(\App\Support\Settings\SettingsRepository::class)->get('ai.quota.reserve_permille');
        $limit = max(0, (int) $headroom['limit']);
        $used = max(0, (int) $headroom['used']);

        return [
            'used' => $used,
            'limit' => $limit,
            'pct' => $limit > 0 ? (int) floor($used * 100 / $limit) : 0,
            'reserve_permille' => is_numeric($reserve) ? (int) $reserve : 50,
            'resets_at' => '00:00 UTC',
        ];
    }

    private function notFound(): JsonResponse
    {
        return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
    }

    /** Scalar-to-string for loosely typed JSON payload values (never a cast on mixed). */
    private static function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
