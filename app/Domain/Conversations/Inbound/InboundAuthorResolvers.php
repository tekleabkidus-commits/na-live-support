<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Inbound;

use App\Domain\Conversations\Adapters\NormalizedInbound;
use Closure;

/**
 * The inbound-author seam (ruling A-27). Core asks per channel "is this
 * inbound authored by someone other than the customer?"; the DEFAULT answer
 * is no (null → customer, the universal case). A channel that can carry a
 * staff-authored inbound registers a resolver here.
 *
 * N-20: keyed on the CHANNEL name through a registry, never a conditional in
 * core — the SourceUrlResolvers pattern. The resolver does its OWN
 * server-side authority check (A-27 condition 2: agent authorship is
 * reachable only when the update's from.id matches the connection's
 * registered account_user_id); core never trusts adapter-supplied content to
 * claim authorship. A resolver returning null means "customer", so a spoofed
 * owner-phone flag that fails the server-side check attributes as customer,
 * proven by the negative spoof control.
 */
final class InboundAuthorResolvers
{
    /** @var array<string, Closure(NormalizedInbound): ?InboundAuthor> */
    private array $resolvers = [];

    /** @param Closure(NormalizedInbound): ?InboundAuthor $resolver */
    public function register(string $channel, Closure $resolver): void
    {
        $this->resolvers[$channel] = $resolver;
    }

    public function resolve(string $channel, NormalizedInbound $inbound): ?InboundAuthor
    {
        $resolver = $this->resolvers[$channel] ?? null;

        return $resolver === null ? null : $resolver($inbound);
    }
}
