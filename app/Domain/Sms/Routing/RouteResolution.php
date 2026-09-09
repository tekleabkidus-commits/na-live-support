<?php

declare(strict_types=1);

namespace App\Domain\Sms\Routing;

/**
 * FR-08's answer for one phone: the ORDERED gateway chain (primary, then the
 * fallbacks — FR-09), each with the sender id that applies (the route's
 * override, else the gateway's per-country sender), the matched prefix (null
 * when the tenant default gateway answered), and `noRoute` when nothing can
 * carry the message (FR-08's explicit `no_route` skip).
 *
 * @phpstan-type Hop array{gateway_id: string, sender_id: ?string, circuit_open: bool}
 */
final readonly class RouteResolution
{
    /**
     * @param  list<Hop>  $chain
     */
    public function __construct(
        public array $chain,
        public ?string $matchedPrefix,
        public ?string $routeId,
        public bool $noRoute,
        public string $reason,
    ) {}

    /** @return list<Hop> the hops whose circuit is not open (FR-09: an open circuit fails its routes over) */
    public function viable(): array
    {
        return array_values(array_filter($this->chain, static fn (array $hop): bool => ! $hop['circuit_open']));
    }
}
