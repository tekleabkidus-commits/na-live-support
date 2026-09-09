<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sms;

use App\Domain\Sms\Failover\CircuitBreaker;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\GatewayRefused;
use App\Domain\Sms\Gateways\GatewayWriter;
use App\Domain\Sms\Routing\RouteWriter;
use App\Domain\Sms\Routing\RoutingTable;
use App\Http\Controllers\Controller;
use App\Models\SmsGateway;
use App\Models\SmsRoute;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 14 FR-07 / FR-08 / FR-09 / SEC-01 / SEC-04 / SEC-06 / §8 — the gateway and
 * routing JSON doors. Reads under `sms.gateways.view`; every mutation under
 * `sms.gateways.manage` (high) with its registered audit key; the rate card
 * (a slice of the mapping) is READ under `reports.view` (SEC-06) — the
 * gateway summary omits it for a viewer without that key. A credential is
 * WRITE-ONLY (SEC-01): it enters on create/replace and no response ever
 * carries it, its ref, or the callback URL. The "test a number" resolver
 * answers the exact chain (§8).
 */
final class SmsGatewaysController extends Controller
{
    public function __construct(
        private readonly GatewayWriter $writer,
        private readonly RouteWriter $routes,
        private readonly RoutingTable $table,
        private readonly CircuitBreaker $breaker,
        private readonly CapabilityMapProvider $verdicts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $withRateCards = $this->can($request, 'reports.view');
        $gateways = [];
        foreach (SmsGateway::query()->whereNull('deleted_at')->orderBy('name')->get() as $gateway) {
            $gateways[] = $this->summary($gateway, $withRateCards);
        }
        $routes = [];
        foreach (SmsRoute::query()->orderBy('position')->get() as $route) {
            $routes[] = $this->routeSummary($route);
        }

        return new JsonResponse(['gateways' => $gateways, 'routes' => $routes, 'overlaps' => $this->table->overlaps()]);
    }

    public function show(Request $request, string $gatewayId): JsonResponse
    {
        return new JsonResponse($this->summary($this->gateway($gatewayId), $this->can($request, 'reports.view')));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->input($request, [
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', 'in:http_generic,smpp_sidecar'],
            'config' => ['required', 'array'],
            'credential' => ['sometimes', 'nullable', 'string', 'max:512'],
            'signing_dlr' => ['sometimes', 'boolean'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{name: string, driver: string, config: array<string, mixed>, credential?: ?string, signing_dlr?: bool} $input */
        try {
            $gateway = $this->writer->create($input['name'], $input['driver'], $input['config'], $input['credential'] ?? null, $this->actor($request), (bool) ($input['signing_dlr'] ?? false));
        } catch (GatewayRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse($this->summary($gateway, true), 201);
    }

    public function update(Request $request, string $gatewayId): JsonResponse
    {
        $gateway = $this->gateway($gatewayId);
        $input = $this->input($request, [
            'name' => ['sometimes', 'string', 'max:120'],
            'config' => ['sometimes', 'array'],
            'sender_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{name?: string, config?: array<string, mixed>, sender_id?: ?string, active?: bool} $input */
        try {
            return new JsonResponse($this->summary($this->writer->update($gateway, $input, $this->actor($request)), true));
        } catch (GatewayRefused $e) {
            return $this->refused($e);
        }
    }

    /** SEC-01: write-only re-entry — never a read-modify-write. */
    public function replaceCredential(Request $request, string $gatewayId): JsonResponse
    {
        $gateway = $this->gateway($gatewayId);
        $input = $this->input($request, ['credential' => ['required', 'string', 'max:512']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{credential: string} $input */
        $this->writer->replaceCredential($gateway, $input['credential'], $this->actor($request));

        return new JsonResponse(['credential_saved' => true]);
    }

    /** §8 "callback URL + secret rotate": new DLR credentials through Doc 02's doors. */
    public function rotateDlr(Request $request, string $gatewayId): JsonResponse
    {
        $gateway = $this->gateway($gatewayId);
        $input = $this->input($request, ['signing_dlr' => ['sometimes', 'boolean']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{signing_dlr?: bool} $input */
        $this->writer->rotateDlrCredentials($gateway, $this->actor($request), (bool) ($input['signing_dlr'] ?? false));

        return new JsonResponse(['dlr_rotated' => true]);
    }

    /** SEC-04: the manual circuit reset — high-visibility, reason required. */
    public function resetCircuit(Request $request, string $gatewayId): JsonResponse
    {
        $gateway = $this->gateway($gatewayId);
        $input = $this->input($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{reason: string} $input */
        $this->breaker->reset($gateway, $this->actor($request), $input['reason']);

        return new JsonResponse($this->summary($gateway->refresh(), true));
    }

    public function destroy(Request $request, string $gatewayId): JsonResponse
    {
        $this->writer->softDelete($this->gateway($gatewayId), $this->actor($request));

        return new JsonResponse(['deleted' => true]);
    }

    /** §8 "test a number": the exact route + fallback chain a given E.164 would take, with the shadowing warnings. */
    public function resolve(Request $request): JsonResponse
    {
        $input = $this->input($request, ['phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{phone: string} $input */
        $explained = $this->table->explain($input['phone']);
        $resolution = $explained['resolution'];
        $names = SmsGateway::query()->whereNull('deleted_at')->pluck('name', 'id');

        return new JsonResponse([
            'matched_prefix' => $resolution->matchedPrefix,
            'route_id' => $resolution->routeId,
            'reason' => $resolution->reason,
            'no_route' => $resolution->noRoute,
            'chain' => array_map(static fn (array $hop): array => $hop + ['gateway_name' => $names[$hop['gateway_id']] ?? null], $resolution->chain),
            'overlaps' => $explained['overlaps'],
        ]);
    }

    // ── routes ─────────────────────────────────────────────────────────────

    public function storeRoute(Request $request): JsonResponse
    {
        $input = $this->input($request, [
            'country_prefix' => ['required', 'string', 'max:16'],
            'primary_gateway_id' => ['required', 'uuid'],
            'fallback_gateway_ids' => ['sometimes', 'array', 'max:5'],
            'fallback_gateway_ids.*' => ['uuid'],
            'sender_id_override' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{country_prefix: string, primary_gateway_id: string, fallback_gateway_ids?: list<string>, sender_id_override?: ?string} $input */
        try {
            $route = $this->routes->create($input['country_prefix'], $input['primary_gateway_id'], $input['fallback_gateway_ids'] ?? [], $input['sender_id_override'] ?? null, $this->actor($request));
        } catch (GatewayRefused $e) {
            return $this->refused($e);
        }

        return new JsonResponse($this->routeSummary($route) + ['overlaps' => $this->table->overlaps()], 201);
    }

    public function updateRoute(Request $request, string $routeId): JsonResponse
    {
        $route = $this->route($routeId);
        $input = $this->input($request, [
            'country_prefix' => ['sometimes', 'string', 'max:16'],
            'primary_gateway_id' => ['sometimes', 'uuid'],
            'fallback_gateway_ids' => ['sometimes', 'array', 'max:5'],
            'fallback_gateway_ids.*' => ['uuid'],
            'sender_id_override' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{country_prefix?: string, primary_gateway_id?: string, fallback_gateway_ids?: list<string>, sender_id_override?: ?string} $input */
        try {
            return new JsonResponse($this->routeSummary($this->routes->update($route, $input, $this->actor($request))) + ['overlaps' => $this->table->overlaps()]);
        } catch (GatewayRefused $e) {
            return $this->refused($e);
        }
    }

    public function reorderRoutes(Request $request): JsonResponse
    {
        $input = $this->input($request, ['ordered_ids' => ['required', 'array', 'min:1'], 'ordered_ids.*' => ['uuid']]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        /** @var array{ordered_ids: list<string>} $input */
        $this->routes->reorder($input['ordered_ids'], $this->actor($request));

        return new JsonResponse(['reordered' => true, 'overlaps' => $this->table->overlaps()]);
    }

    public function deactivateRoute(Request $request, string $routeId): JsonResponse
    {
        return new JsonResponse($this->routeSummary($this->routes->deactivate($this->route($routeId), $this->actor($request))));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function gateway(string $id): SmsGateway
    {
        $gateway = SmsGateway::query()->whereNull('deleted_at')->find($id);
        if ($gateway === null) {
            throw new NotFoundHttpException;
        }

        return $gateway;
    }

    private function route(string $id): SmsRoute
    {
        $route = SmsRoute::query()->find($id);
        if ($route === null) {
            throw new NotFoundHttpException;
        }

        return $route;
    }

    /**
     * The summary: NEVER the credential, its ref, the DLR refs or the callback URL (SEC-01).
     *
     * @return array<string, mixed>
     */
    private function summary(SmsGateway $gateway, bool $withRateCard): array
    {
        $config = $gateway->config;
        $public = $config;
        if (! $withRateCard) {
            unset($public['rate_card']); // SEC-06: rate cards are reports.view-gated
        }

        return [
            'id' => $gateway->identifier(),
            'name' => $gateway->name,
            'driver' => $gateway->driver,
            'config' => $public,
            'has_credential' => $gateway->credential_ref !== null,
            'has_dlr_credentials' => $gateway->dlr_key_ref !== null,
            'sender_id' => $gateway->sender_id,
            'health' => $gateway->health,
            'circuit_state' => $gateway->circuit_state,
            'circuit_opened_at' => $gateway->circuit_opened_at?->toIso8601String(),
            'active' => $gateway->active,
            'is_fixture' => $gateway->is_fixture,
            'throughput_per_second' => GatewayConfig::fromStored($config)->throughputPerSecond(),
        ];
    }

    /** @return array<string, mixed> */
    private function routeSummary(SmsRoute $route): array
    {
        return [
            'id' => $route->identifier(),
            'country_prefix' => $route->country_prefix,
            'position' => $route->position,
            'primary_gateway_id' => $route->primary_gateway_id,
            'fallback_gateway_ids' => $route->fallback_gateway_ids,
            'sender_id_override' => $route->sender_id_override,
            'active' => $route->active,
        ];
    }

    /** The house resolver (DirectoryController's own shape): the viewer's capability map, never a Gate guess. */
    private function can(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof User && $this->verdicts->mapFor($user)->allows($permission);
    }

    /**
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    private function input(Request $request, array $rules): array|JsonResponse
    {
        try {
            /** @var array<string, mixed> $validated */
            $validated = $request->validate($rules);

            return $validated;
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
    }

    private function actor(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        return $user->identifier();
    }

    private function refused(GatewayRefused $e): JsonResponse
    {
        return ProblemDetails::response(
            code: ErrorCode::ValidationFailed,
            status: 422,
            detail: (string) __('sms.refused.'.$e->refusalCode),
            errors: ['refusal' => [$e->refusalCode]] + array_map(
                static fn (mixed $v): array => array_values(array_map(static fn (mixed $x): string => is_scalar($x) ? (string) $x : json_encode($x, JSON_THROW_ON_ERROR), is_array($v) ? $v : [$v])),
                $e->extra,
            ),
        );
    }
}
