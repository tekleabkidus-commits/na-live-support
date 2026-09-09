<?php

declare(strict_types=1);

namespace App\Domain\Sms\Routing;

use App\Domain\Sms\Gateways\GatewayRefused;
use App\Models\SmsGateway;
use App\Models\SmsRoute;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Doc 14 FR-08 / §8 / §9 — the ONE write door for routes: create, update,
 * reorder (drag-ordered rows — positions rewritten as one list), deactivate
 * (never deleted). Every gateway id is validated against the tenant's own
 * gateways (RLS makes a foreign id unreadable); every write bumps the table
 * version (the C-07 invalidation) and is audited.
 */
final class RouteWriter
{
    public function __construct(
        private readonly RoutingTable $table,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $fallbacks
     */
    public function create(string $prefix, string $primaryGatewayId, array $fallbacks, ?string $senderOverride, string $actorId): SmsRoute
    {
        $this->assertPrefix($prefix);
        $this->assertGateways([$primaryGatewayId, ...$fallbacks]);

        return DB::transaction(function () use ($prefix, $primaryGatewayId, $fallbacks, $senderOverride, $actorId): SmsRoute {
            $max = SmsRoute::query()->max('position');
            $route = SmsRoute::query()->create([
                'country_prefix' => $prefix,
                'position' => (is_numeric($max) ? (int) $max : 0) + 1,
                'primary_gateway_id' => $primaryGatewayId,
                'fallback_gateway_ids' => $fallbacks,
                'sender_id_override' => $senderOverride,
                'active' => true,
            ]);
            $this->table->invalidate();
            $this->audit->record('sms.route.created', 'sms_route', $route->identifier(), AuditActor::Staff, ['route_id' => $route->identifier(), 'country_prefix' => $prefix], $actorId);

            return $route->refresh();
        });
    }

    /**
     * @param  array{country_prefix?: string, primary_gateway_id?: string, fallback_gateway_ids?: list<string>, sender_id_override?: ?string}  $attributes
     */
    public function update(SmsRoute $route, array $attributes, string $actorId): SmsRoute
    {
        if (isset($attributes['country_prefix'])) {
            $this->assertPrefix($attributes['country_prefix']);
        }
        $ids = [];
        if (isset($attributes['primary_gateway_id'])) {
            $ids[] = $attributes['primary_gateway_id'];
        }
        if (isset($attributes['fallback_gateway_ids'])) {
            $ids = [...$ids, ...$attributes['fallback_gateway_ids']];
        }
        $this->assertGateways($ids);

        return DB::transaction(function () use ($route, $attributes, $actorId): SmsRoute {
            $route->forceFill(array_intersect_key($attributes, array_flip(['country_prefix', 'primary_gateway_id', 'fallback_gateway_ids', 'sender_id_override'])))->save();
            $this->table->invalidate();
            $this->audit->record('sms.route.updated', 'sms_route', $route->identifier(), AuditActor::Staff, ['route_id' => $route->identifier()], $actorId);

            return $route->refresh();
        });
    }

    /**
     * §9 drag-order: the full ordered list of route ids becomes positions 1..n.
     *
     * @param  list<string>  $orderedRouteIds
     */
    public function reorder(array $orderedRouteIds, string $actorId): void
    {
        DB::transaction(function () use ($orderedRouteIds, $actorId): void {
            foreach ($orderedRouteIds as $index => $routeId) {
                SmsRoute::query()->where('id', $routeId)->update(['position' => $index + 1]);
            }
            $this->table->invalidate();
            $this->audit->record('sms.route.updated', 'sms_route', null, AuditActor::Staff, ['route_id' => 'reorder', 'count' => count($orderedRouteIds)], $actorId);
        });
    }

    public function deactivate(SmsRoute $route, string $actorId): SmsRoute
    {
        return DB::transaction(function () use ($route, $actorId): SmsRoute {
            $route->forceFill(['active' => false])->save();
            $this->table->invalidate();
            $this->audit->record('sms.route.deactivated', 'sms_route', $route->identifier(), AuditActor::Staff, ['route_id' => $route->identifier()], $actorId);

            return $route->refresh();
        });
    }

    private function assertPrefix(string $prefix): void
    {
        if (preg_match('/^\+[1-9][0-9]{0,14}$/', $prefix) !== 1) {
            throw new GatewayRefused('prefix_invalid', 'a country prefix is E.164: + and 1..15 digits', ['prefix' => $prefix]);
        }
    }

    /** @param list<string> $ids */
    private function assertGateways(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            if (! SmsGateway::query()->where('id', $id)->whereNull('deleted_at')->exists()) {
                throw new GatewayRefused('gateway_not_found', 'a route names a gateway that does not exist in this tenant', ['gateway_id' => $id]);
            }
        }
    }
}
