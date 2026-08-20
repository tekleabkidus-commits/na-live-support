<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

/**
 * FR-02: named provider configs from config/ai.php → driver instances.
 * Instances are memoized; tests may inject a scripted driver via swap().
 */
final class ProviderRegistry
{
    /** @var array<string, ProviderDriver> */
    private array $instances = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly FixturesOnlyEgress $guard,
    ) {}

    public function driver(string $provider, int $timeoutSeconds = 30): ProviderDriver
    {
        if (isset($this->instances[$provider])) {
            return $this->instances[$provider];
        }

        /** @var array<string, mixed>|null $config */
        $config = config("ai.providers.{$provider}");
        if (! is_array($config)) {
            throw new InvalidArgumentException(sprintf('unknown AI provider "%s" (config/ai.php)', $provider));
        }

        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'unknown';

        return $this->instances[$provider] = match ($driver) {
            'openai-compatible' => new OpenAiCompatibleClient(
                http: $this->http,
                guard: $this->guard,
                baseUrl: is_string($config['base_url'] ?? null) ? $config['base_url'] : '',
                key: is_string($config['key'] ?? null) ? $config['key'] : null,
                proxy: is_string($config['proxy'] ?? null) ? $config['proxy'] : null,
                timeoutSeconds: $timeoutSeconds,
            ),
            'fake' => new FakeDriver($this->guard),
            'replay' => new ReplayDriver($this->guard),
            default => throw new InvalidArgumentException(sprintf('unknown AI driver "%s"', $driver)),
        };
    }

    /** Test seam: swap a named provider for a scripted driver. */
    public function swap(string $provider, ProviderDriver $driver): void
    {
        $this->instances[$provider] = $driver;
    }
}
