<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Realtime;

use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * T-03's counting contract, server side: the view badge is the SERVER's
 * ZCARD, pushed on delta and debounced to once per 2 s per view — the client
 * NEVER derives a count from its partial window (the em-dash-until-first-
 * value render rule is the client half, step 8; the server half is that
 * every push carries the full ZCARD, so there is nothing to derive).
 *
 * Debounce mechanics (deterministic, no wall-clock asserts — tests clear the
 * throttle key instead of sleeping): SET NX EX 2 on a tenant-prefixed
 * throttle key. Acquired → push the CURRENT ZCARD. Held → mark dirty; the
 * next delta after the window pushes the then-current ZCARD, and a reconnect
 * always refetches through T-07 — a trailing count is never lost, only
 * coalesced.
 *
 * SABOTAGE #13's TARGET: derive the pushed count from anything other than
 * ZCARD (the delta batch, a client echo) and the counting-contract test —
 * which drives MORE deltas than the window admits pushes and asserts the
 * pushed value equals ZCARD *and* the direct query — goes red.
 */
final class CountPusher
{
    private const CONNECTION = 'volatile';

    private const DEBOUNCE_SECONDS = 2;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly ViewZsets $zsets,
        private readonly ViewDeltaPusher $pusher,
    ) {}

    /** Called by the projector after each membership change on $viewKey. */
    public function onDelta(string $viewKey): void
    {
        $throttleKey = sprintf('t:%s:viewcount:throttle:%s', $this->context->tenantId(), $viewKey);
        $dirtyKey = $throttleKey.':dirty';
        $connection = $this->redis->connection(self::CONNECTION);

        // phpredis options form: SET key value ['NX', 'EX' => n].
        /** @var mixed $acquired */
        $acquired = $connection->command('set', [$throttleKey, '1', ['NX', 'EX' => self::DEBOUNCE_SECONDS]]);

        if ($acquired === false || $acquired === null) {
            $connection->command('set', [$dirtyKey, '1', ['EX' => 60]]);

            return;
        }

        $connection->command('del', [$dirtyKey]);

        $this->pusher->toViewKey($viewKey, 'inbox.view_count', [
            'type' => 'view_count',
            'view_key' => $viewKey,
            // THE CONTRACT: the count is the server's ZCARD, nothing else.
            'count' => $this->zsets->count($viewKey),
        ]);
    }
}
