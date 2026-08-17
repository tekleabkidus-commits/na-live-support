<?php

declare(strict_types=1);

namespace App\Domain\Customers\Rollups;

use App\Jobs\Customers\RollupConsumer;
use App\Support\Events\ConsumerRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;

/**
 * FS-03's staleness surface (S08 step 12; B4-S07 H07) — the standard
 * staleness pattern, taken from Doc 07's ProjectorStatus lesson whole:
 * beat-age ALONE lies on a quiet stack (no ticket events ≠ a dead rollup
 * handler), so staleness is only claimed when the age is past threshold AND
 * there is pending work the handler should have applied — an unrelayed
 * outbox row in one of the families that feed the RollupConsumer, or an
 * applied event newer than the last beat. "◷ stale" means "the handler is
 * behind", never "nothing happened".
 *
 * The event families come FROM ConsumerRegistry (one brain): a registry
 * amendment moves this check with it, so the pending probe cannot drift
 * from what the consumer actually eats.
 *
 * The heartbeat is OPS METADATA on redis-volatile (the ProjectorStatus
 * precedent — nothing orders by it, no metric reads it, and tests drive the
 * STORED VALUE, never a sleep).
 */
final class CustomerRollupStatus
{
    private const CONNECTION = 'volatile';

    public const STALE_AFTER_SECONDS = 60;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    public function key(): string
    {
        return sprintf('t:%s:customers:rollup_heartbeat', $this->context->tenantId());
    }

    /** Called by RollupConsumer after every applied event. */
    public function beat(): void
    {
        $this->redis->connection(self::CONNECTION)->command('set', [$this->key(), (string) time()]);
    }

    /** Seconds since the last applied rollup event; null = never beat (cold tenant). */
    public function staleSeconds(): ?int
    {
        /** @var string|false|null $raw */
        $raw = $this->redis->connection(self::CONNECTION)->command('get', [$this->key()]);
        if ($raw === false || $raw === null) {
            return null;
        }

        return max(0, time() - (int) $raw);
    }

    /**
     * The whole H07 answer in one read: {stale_seconds, pending, stale}.
     * `stale` is true only past threshold WITH pending rollup-family work.
     *
     * @return array{stale_seconds: ?int, pending: bool, stale: bool}
     */
    public function status(): array
    {
        $seconds = $this->staleSeconds();
        $pending = false;

        if ($seconds !== null && $seconds > self::STALE_AFTER_SECONDS) {
            $families = $this->rollupEventNames();
            $pending = DB::table('outbox')
                ->whereIn('name', $families)
                ->whereNull('relayed_at')
                ->whereRaw('occurred_at < clock_timestamp() - make_interval(secs => ?)', [self::STALE_AFTER_SECONDS])
                ->exists()
                || DB::table('events')
                    ->whereIn('name', $families)
                    ->whereRaw('occurred_at > clock_timestamp() - make_interval(secs => ?)', [max(1, $seconds)])
                    ->exists();
        }

        return [
            'stale_seconds' => $seconds,
            'pending' => $pending,
            'stale' => $seconds !== null && $seconds > self::STALE_AFTER_SECONDS && $pending,
        ];
    }

    /** @return list<string> */
    private function rollupEventNames(): array
    {
        $names = [];
        foreach (ConsumerRegistry::map() as $eventName => $consumers) {
            if (in_array(RollupConsumer::class, $consumers, true)) {
                $names[] = $eventName;
            }
        }

        return $names;
    }
}
