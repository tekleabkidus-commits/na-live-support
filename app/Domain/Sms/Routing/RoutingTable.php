<?php

declare(strict_types=1);

namespace App\Domain\Sms\Routing;

use App\Domain\Sms\Gateways\GatewayConfig;
use App\Models\SmsGateway;
use App\Models\SmsRoute;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Doc 14 FR-08 / FR-09 / EC-01 / EC-12 / §12 — the routing resolver: the
 * tenant's active routes loaded ONCE per table version into a PrefixTrie
 * (C-07: cache key `t:{tenant}:sms:routes:v{version}` on redis-volatile, TTL
 * 3600 s; the version key is bumped by RouteWriter — the documented
 * invalidation; the `sms.routes.changed` event rides it from step 5). The
 * resolver answers the ORDERED chain (primary, fallbacks) with each hop's
 * sender and circuit state; an open circuit is not viable (FR-09 — all its
 * routes fail over); no match → the tenant default gateway or an explicit
 * `no_route` skip per `sms.route.no_match`; a chain with no viable hop is
 * EC-12's ladder (the pipeline queues bounded by the TTL, then fails
 * honestly).
 */
final class RoutingTable
{
    private const CONNECTION = 'volatile';

    private const TTL = 3600;

    /** @var array<string, array{version: string, trie: PrefixTrie, routes: array<string, SmsRoute>, gateways: array<string, SmsGateway>}> per-tenant in-process cache */
    private array $built = [];

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly SettingsRepository $settings,
    ) {}

    public function resolve(string $phoneE164): RouteResolution
    {
        $table = $this->table();
        $match = $table['trie']->longest($phoneE164);
        $gateways = $this->gateways();

        if ($match !== null) {
            $route = $table['routes'][$match['route_id']];
            $chain = [];
            foreach ([$route->primary_gateway_id, ...$route->fallback_gateway_ids] as $gatewayId) {
                $gateway = $gateways[$gatewayId] ?? null;
                if ($gateway === null || ! $gateway->active) {
                    continue;
                }
                $chain[] = $this->hop($gateway, $route->sender_id_override, $match['prefix']);
            }

            return new RouteResolution($chain, $match['prefix'], $route->identifier(), $chain === [], $chain === [] ? 'no_active_gateway_on_route' : 'matched');
        }

        $policy = $this->settings->get('sms.route.no_match');
        if ($policy === 'skip') {
            return new RouteResolution([], null, null, true, 'no_route');
        }
        $defaultId = $this->settings->get('sms.default_gateway_id');
        $default = is_string($defaultId) && $defaultId !== '' ? ($gateways[$defaultId] ?? null) : null;
        if ($default === null || ! $default->active) {
            return new RouteResolution([], null, null, true, 'no_default_gateway');
        }

        return new RouteResolution([$this->hop($default, null, null)], null, null, false, 'tenant_default');
    }

    /**
     * §8 "test a number": the exact chain, every hop named, with the shadowing warnings.
     *
     * @return array{resolution: RouteResolution, overlaps: list<array{shadowing: string, shadowed: string}>}
     */
    public function explain(string $phoneE164): array
    {
        return ['resolution' => $this->resolve($phoneE164), 'overlaps' => $this->overlaps()];
    }

    /** @return list<array{shadowing: string, shadowed: string}> */
    public function overlaps(): array
    {
        $prefixes = [];
        foreach ($this->table()['routes'] as $id => $route) {
            $prefixes[$id] = $route->country_prefix;
        }

        return PrefixTrie::overlaps($prefixes);
    }

    /** The C-07 invalidation: bump the version; every process rebuilds on its next resolve. */
    public function invalidate(): string
    {
        $bumped = $this->redis->connection(self::CONNECTION)->command('incr', [$this->versionKey()]);
        $version = is_scalar($bumped) ? (string) $bumped : '0';
        $this->redis->connection(self::CONNECTION)->command('expire', [$this->versionKey(), self::TTL]);
        unset($this->built[$this->context->tenantId()]);

        return $version;
    }

    public function version(): string
    {
        $raw = $this->redis->connection(self::CONNECTION)->command('get', [$this->versionKey()]);

        return is_scalar($raw) ? (string) $raw : '0';
    }

    /**
     * @return array{version: string, trie: PrefixTrie, routes: array<string, SmsRoute>, gateways: array<string, SmsGateway>}
     */
    private function table(): array
    {
        $tenantId = $this->context->tenantId();
        $version = $this->version();
        $cached = $this->built[$tenantId] ?? null;
        if ($cached !== null && $cached['version'] === $version) {
            return $cached;
        }
        $trie = new PrefixTrie;
        $routes = [];
        foreach (SmsRoute::query()->where('active', true)->orderBy('position')->get() as $route) {
            $routes[$route->identifier()] = $route;
            $trie->insert($route->country_prefix, $route->identifier());
        }
        $gateways = [];
        foreach (SmsGateway::query()->whereNull('deleted_at')->get() as $gateway) {
            $gateways[$gateway->identifier()] = $gateway;
        }
        $this->built[$tenantId] = ['version' => $version, 'trie' => $trie, 'routes' => $routes, 'gateways' => $gateways];

        return $this->built[$tenantId];
    }

    /**
     * The gateway snapshot rides the SAME version key as the trie (§12: ≤ 0.5 ms — one Redis read, no
     * query on the steady path): every writer of a gateway row that changes viability — the circuit
     * breaker's transitions and reset, the gateway writer's create/update/delete — bumps the version,
     * so an open circuit is out of the chain at the NEXT resolve on every worker.
     *
     * @return array<string, SmsGateway>
     */
    private function gateways(): array
    {
        return $this->table()['gateways'];
    }

    /** @return array{gateway_id: string, sender_id: ?string, circuit_open: bool} */
    private function hop(SmsGateway $gateway, ?string $override, ?string $prefix): array
    {
        $sender = $override ?? GatewayConfig::fromStored($gateway->config)->senderFor($prefix) ?? $gateway->sender_id;

        return ['gateway_id' => $gateway->identifier(), 'sender_id' => $sender, 'circuit_open' => $gateway->circuit_state === 'open'];
    }

    private function versionKey(): string
    {
        return sprintf('t:%s:sms:routes:version', $this->context->tenantId());
    }
}
