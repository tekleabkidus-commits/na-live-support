<?php

declare(strict_types=1);

namespace App\Domain\Kb\Editor;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Doc 10 FR-05 / T-07 / FS-05 — per-variant SOFT locks: a TTL'd key in redis-volatile
 * (C-07 prefix), refreshed by the editor's heartbeat (kb.lock.heartbeat_seconds, 90 s);
 * first SET NX wins; a takeover overwrites and AUDITS BOTH PARTIES (the taker's act and
 * the holder's loss — two rows, one action key); Redis down → LockUnavailable, and the
 * caller degrades to optimistic concurrency (WorkingCopy's expected stamp) — noisier,
 * never unsafe. Locks are presence, never truth: nothing durable depends on them.
 */
final class Locks
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RedisFactory $redis,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
    ) {}

    private function key(string $variantId): string
    {
        return sprintf('t:%s:kb_lock:%s', $this->context->tenantId(), $variantId);
    }

    private function ttl(): int
    {
        $ttl = $this->settings->get('kb.lock.heartbeat_seconds');

        return is_numeric($ttl) ? (int) $ttl : 90;
    }

    /** @return array{holder_id: string, acquired: bool} acquired=false means someone else holds it (LockHeld is thrown instead) */
    public function acquire(string $variantId, string $userId): array
    {
        try {
            $set = $this->redis->connection('volatile')->command('set', [$this->key($variantId), $userId, ['NX', 'EX' => $this->ttl()]]); // the CallbackNonce SET NX EX idiom
            if ($set) {
                return ['holder_id' => $userId, 'acquired' => true];
            }
            $raw = $this->redis->connection('volatile')->command('get', [$this->key($variantId)]);
            $holder = is_string($raw) ? $raw : '';
        } catch (Throwable $e) {
            throw new LockUnavailable('lock service unavailable (FS-05)', previous: $e);
        }
        if ($holder === $userId) {
            $this->heartbeat($variantId, $userId);

            return ['holder_id' => $userId, 'acquired' => true];
        }
        throw new LockHeld($holder);
    }

    /** Refresh the TTL — only the holder may. */
    public function heartbeat(string $variantId, string $userId): bool
    {
        try {
            $holder = $this->redis->connection('volatile')->command('get', [$this->key($variantId)]);
            if ($holder !== $userId) {
                return false;
            }
            $this->redis->connection('volatile')->command('expire', [$this->key($variantId), $this->ttl()]);

            return true;
        } catch (Throwable $e) {
            throw new LockUnavailable('lock service unavailable (FS-05)', previous: $e);
        }
    }

    public function release(string $variantId, string $userId): void
    {
        try {
            $holder = $this->redis->connection('volatile')->command('get', [$this->key($variantId)]);
            if ($holder === $userId) {
                $this->redis->connection('volatile')->command('del', [$this->key($variantId)]);
            }
        } catch (Throwable $e) {
            throw new LockUnavailable('lock service unavailable (FS-05)', previous: $e);
        }
    }

    public function holder(string $variantId): ?string
    {
        try {
            $holder = $this->redis->connection('volatile')->command('get', [$this->key($variantId)]);
        } catch (Throwable $e) {
            throw new LockUnavailable('lock service unavailable (FS-05)', previous: $e);
        }

        return is_string($holder) && $holder !== '' ? $holder : null;
    }

    /** FR-05: the lock steal — overwrites, audits BOTH parties, returns the displaced holder. */
    public function takeover(string $variantId, string $userId): ?string
    {
        $previous = $this->holder($variantId);
        try {
            $this->redis->connection('volatile')->command('set', [$this->key($variantId), $userId, ['EX' => $this->ttl()]]);
        } catch (Throwable $e) {
            throw new LockUnavailable('lock service unavailable (FS-05)', previous: $e);
        }
        $payload = ['variant_id' => $variantId, 'previous_holder_id' => $previous, 'new_holder_id' => $userId];
        \Illuminate\Support\Facades\DB::transaction(function () use ($payload, $variantId, $userId, $previous): void {
            $this->audit->record('kb.lock.taken_over', 'kb_article_variant', $variantId, AuditActor::Staff, $payload + ['party' => 'taker'], actorId: $userId);
            if ($previous !== null && $previous !== $userId) {
                $this->audit->record('kb.lock.taken_over', 'kb_article_variant', $variantId, AuditActor::Staff, $payload + ['party' => 'holder'], actorId: $previous);
                $this->events->publish('kb.lock.taken_over', ['tenant_id' => $this->context->tenantId(), 'variant_id' => $variantId, 'previous_holder_id' => $previous, 'new_holder_id' => $userId]); // P4 (A-44 amendment)
            }
        });

        return $previous;
    }
}
