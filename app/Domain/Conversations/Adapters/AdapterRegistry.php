<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

use DomainException;

/**
 * The core's only directory of channels (Doc 04 FR-09).
 *
 * Everything the core needs to know about a channel — its adapter and its queue
 * families (T-02: telegram-in/out, webchat-in/out are S0 supervisors) — is REGISTERED
 * DATA looked up by name at the edges (webhook route, outbound dispatch). Domain code
 * downstream of those edges receives the ChannelAdapter instance and its Capabilities,
 * never the name, which is how N-20 stays structural rather than disciplined.
 *
 * Real registrations arrive with Doc 05 (telegram) and Doc 13 (webchat); this session
 * registers only fake adapters, from tests (the S0 mock services are HTTP stand-ins
 * for channel APIs and play no part here).
 */
final class AdapterRegistry
{
    /** @var array<string, array{adapter: ChannelAdapter, in_family: string, out_family: string}> */
    private array $channels = [];

    public function register(string $channel, ChannelAdapter $adapter, string $inFamily, string $outFamily): void
    {
        if (isset($this->channels[$channel])) {
            throw new DomainException("channel '{$channel}' is already registered.");
        }

        $this->channels[$channel] = [
            'adapter' => $adapter,
            'in_family' => $inFamily,
            'out_family' => $outFamily,
        ];
    }

    public function adapter(string $channel): ChannelAdapter
    {
        return $this->entry($channel)['adapter'];
    }

    public function inboundFamily(string $channel): string
    {
        return $this->entry($channel)['in_family'];
    }

    public function outboundFamily(string $channel): string
    {
        return $this->entry($channel)['out_family'];
    }

    /** @return list<string> */
    public function channels(): array
    {
        return array_keys($this->channels);
    }

    /** @return array{adapter: ChannelAdapter, in_family: string, out_family: string} */
    private function entry(string $channel): array
    {
        return $this->channels[$channel]
            ?? throw new DomainException("channel '{$channel}' is not registered.");
    }
}
